<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\AuditLogNoteService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminAuditLogNoteController extends Controller
{
    public function show(Request $request, int $auditLogId, RoleResolverService $roles, AuditLogNoteService $notes)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON($notes->get($auditLogId));
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, int $auditLogId, RoleResolverService $roles, AuditLogNoteService $notes)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $this->payload($request);

            return $this->successfulResponseJSON($notes->create($auditLogId, $payload['note'], $request), 'Note audit log berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $auditLogId, RoleResolverService $roles, AuditLogNoteService $notes)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $this->payload($request, true);

            return $this->successfulResponseJSON($notes->update($auditLogId, $payload['note'], $payload['expected_updated_at'], $request), 'Note audit log berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function payload(Request $request, bool $update = false): array
    {
        if (is_string($request->input('note'))) {
            $request->merge(['note' => trim($request->input('note'))]);
        }

        return $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:5000', 'not_regex:/^\s*$/u'],
            'expected_updated_at' => [$update ? 'required' : 'sometimes', 'date'],
        ]);
    }
}
