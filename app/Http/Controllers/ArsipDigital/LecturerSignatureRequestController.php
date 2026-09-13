<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\RoleResolverService;
use App\Services\ArsipDigital\SignatureRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LecturerSignatureRequestController extends Controller
{
    public function availability(Request $request, RoleResolverService $roles)
    {
        try {
            $roles->resolve($request, ['dosen']);
            $row = DB::table('arsip_digital.dosen_signature_availability')->where('user_id', auth()->id())->first();

            return $this->successfulResponseJSON(['is_available' => $row === null ? true : (bool) $row->is_available]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function setAvailability(Request $request, RoleResolverService $roles)
    {
        try {
            $roles->resolve($request, ['dosen']);
            $data = $request->validate(['is_available' => ['required', 'boolean']]);
            DB::table('arsip_digital.dosen_signature_availability')->updateOrInsert(['user_id' => auth()->id()], ['is_available' => $data['is_available'], 'created_at' => now(), 'updated_at' => now()]);

            return $this->successfulResponseJSON($data);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function accept(Request $request, int $signature_request_id, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['dosen']);

            return $this->successfulResponseJSON($service->transition($signature_request_id, auth()->user(), 'draft')->toArray());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function reject(Request $request, int $signature_request_id, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['dosen']);
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return $this->successfulResponseJSON($service->transition($signature_request_id, auth()->user(), 'rejected', $data['reason'])->toArray());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function bulk(Request $request, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['dosen']);
            $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct'], 'action' => ['required', 'in:accept,reject'], 'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:2000']]);

            return $this->successfulResponseJSON($service->bulk($data['ids'], auth()->user(), $data['action'] === 'accept' ? 'draft' : 'rejected', $data['reason'] ?? null));
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function createSession(Request $request, int $signature_request_file_id, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['dosen']);

            return $this->successfulResponseJSON($service->createSession($signature_request_file_id, auth()->user())->toArray());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function send(Request $request, int $signature_request_id, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['dosen']);

            return $this->successfulResponseJSON($service->send($signature_request_id, auth()->user())->toArray(), 'Semua hasil tanda tangan dikirim.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
