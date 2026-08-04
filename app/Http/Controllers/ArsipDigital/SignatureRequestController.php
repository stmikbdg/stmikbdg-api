<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\SignatureRequest;
use App\Models\ArsipDigital\SignatureRequestFile;
use App\Models\Users\Dosen;
use App\Models\Users\User;
use App\Services\ArsipDigital\RoleResolverService;
use App\Services\ArsipDigital\SignatureRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SignatureRequestController extends Controller
{
    public function config(Request $request, RoleResolverService $roles, \App\Services\ArsipDigital\ArsipDigitalSettingsService $settings)
    {
        try {
            $roles->resolve($request, ['mahasiswa', 'dosen']);
            $values = $settings->getDefaults();

            return $this->successfulResponseJSON(array_intersect_key($values, array_flip(['signature_request_max_files', 'signature_request_max_file_size_mb', 'signature_request_max_total_size_mb', 'signature_request_expiry_days', 'signature_request_cooldown_hours'])));
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function directory(Request $request, RoleResolverService $roles)
    {
        try {
            $roles->resolve($request, ['mahasiswa']);

            $unavailableIds = DB::table('arsip_digital.dosen_signature_availability')->where('is_available', false)->pluck('user_id');
            $accounts = User::whereNotIn('id', $unavailableIds)->where('is_dosen', true)->where('kd_user', 'like', 'DSN-%')->get(['id', 'kd_user'])->keyBy(fn (User $user): string => substr($user->kd_user, 4));
            $lecturers = Dosen::query()->whereIn('kd_dosen', $accounts->keys())->orderBy('nm_dosen')->get(['kd_dosen', 'nm_dosen'])->map(fn (Dosen $lecturer): array => ['id' => $accounts->get(trim((string) $lecturer->kd_dosen))?->id, 'name' => trim((string) $lecturer->nm_dosen)])->filter(fn (array $lecturer): bool => $lecturer['id'] !== null)->values()->all();

            return $this->successfulResponseJSON($lecturers);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function index(Request $request, RoleResolverService $roles)
    {
        try {
            $role = $roles->resolve($request, ['mahasiswa', 'dosen']);
            $query = SignatureRequest::with('files')->where($role === 'mahasiswa' ? 'student_user_id' : 'lecturer_user_id', auth()->id());
            if ($request->filled('status')) {
                $query->where('status', $request->string('status'));
            }

            return $this->successfulResponseJSON($query->latest('signature_request_id')->paginate(min((int) $request->input('per_page', 15), 100))->toArray());
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['mahasiswa']);
            $data = $request->validate(['lecturer_user_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'file_ids' => ['required', 'array', 'min:1'], 'file_ids.*' => ['integer', 'distinct']]);

            return $this->successfulResponseJSON($service->create(auth()->user(), $data)->toArray(), 'Request tanda tangan dibuat.');
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $signature_request_id, RoleResolverService $roles)
    {
        try {
            $role = $roles->resolve($request, ['mahasiswa', 'dosen']);

            return $this->successfulResponseJSON(SignatureRequest::with('files')->where($role === 'mahasiswa' ? 'student_user_id' : 'lecturer_user_id', auth()->id())->findOrFail($signature_request_id)->toArray());
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $signature_request_id, RoleResolverService $roles)
    {
        try {
            $roles->resolve($request, ['mahasiswa']);
            $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string'], 'file_ids' => ['sometimes', 'array', 'min:1'], 'file_ids.*' => ['integer', 'distinct']]);

            return $this->successfulResponseJSON(app(SignatureRequestService::class)->updateRequested($signature_request_id, auth()->user(), $data)->toArray());
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function download(Request $request, int $signature_request_file_id, string $kind, RoleResolverService $roles)
    {
        try {
            $role = $roles->resolve($request, ['mahasiswa', 'dosen']);
            $file = SignatureRequestFile::with(['request', 'source'])->findOrFail($signature_request_file_id);
            if (($role === 'mahasiswa' && $file->request->student_user_id !== auth()->id()) || ($role === 'dosen' && $file->request->lecturer_user_id !== auth()->id())) {
                throw new HttpException(403, 'Tidak memiliki akses file request.');
            }
            if ($kind === 'source') {
                return Storage::disk($file->source->storage_disk)->download($file->source->storage_path, $file->source_filename);
            }
            if ($kind !== 'result' || ! $file->signed_result_path || ! in_array($file->request->status, ['draft', 'completed'], true)) {
                throw new HttpException(404, 'Hasil tanda tangan tidak tersedia.');
            }

            return Storage::disk($file->signed_result_disk)->download($file->signed_result_path, 'signed-'.$file->source_filename);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $signature_request_id, RoleResolverService $roles, SignatureRequestService $service)
    {
        try {
            $roles->resolve($request, ['mahasiswa']);

            return $this->successfulResponseJSON($service->cancel($signature_request_id, auth()->user())->toArray());
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }
}
