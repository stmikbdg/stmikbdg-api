<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\ArchiveRequest;
use App\Services\ArsipDigital\ArchiveRequestService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminRequestController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $filters = $request->validate([
                'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'scope_type' => ['sometimes', 'in:all,filter,specific,segment'],
                'status' => ['sometimes', 'in:draft,published,closed,archived'],
                'search' => ['nullable', 'string', 'max:255'],
                'with_deleted' => ['sometimes', 'boolean'],
            ]);

            return $this->successfulResponseJSON([
                'requests' => $requestService->adminQuery($filters)->selectRaw('*')->withCount([
                    'assignments',
                    'assignments as submitted_assignments_count' => fn ($query) => $query->whereIn('status', ['waiting_verification', 'approved', 'rejected']),
                    'assignments as approved_assignments_count' => fn ($query) => $query->where('status', 'approved'),
                ])->get()->toArray(),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roleResolver, ArchiveRequestService $requestService, AuditLogService $auditLog)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $this->validatedPayload($request);
            $archiveRequest = $requestService->create($payload, auth()->user());

            $auditLog->record('request.created', 'request', $archiveRequest->request_id, 'Request arsip digital dibuat.', [], $request, auth()->user()?->id, $role);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::withCount([
                'assignments',
                'assignments as submitted_assignments_count' => fn ($query) => $query->whereIn('status', ['waiting_verification', 'approved', 'rejected']),
                'assignments as approved_assignments_count' => fn ($query) => $query->where('status', 'approved'),
                'assignments as waiting_verification_assignments_count' => fn ($query) => $query->where('status', 'waiting_verification'),
            ])
                ->with(['assignments.requestFiles.file'])
                ->findOrFail($request_id);

            return $this->successfulResponseJSON([
                'request' => $archiveRequest->toArray(),
                'progress' => $requestService->progress($archiveRequest),
                'file_summary' => $requestService->fileSummary($archiveRequest),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService, AuditLogService $auditLog)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $payload = $this->validatedPayload($request, true);
            $archiveRequest = $requestService->update($archiveRequest, $payload);

            $auditLog->record('request.updated', 'request', $archiveRequest->request_id, 'Request arsip digital diperbarui.', ['updated_keys' => array_keys($payload)], $request, auth()->user()?->id, $role);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService, AuditLogService $auditLog)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $requestService->delete($archiveRequest);

            $auditLog->record('request.deleted', 'request', $request_id, 'Request arsip digital dihapus secara soft delete.', [], $request, auth()->user()?->id, $role);

            return $this->successfulResponseJSONV2('Request berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function previewTargets(Request $request, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'target_role' => ['required', 'in:mahasiswa,dosen'],
                'scope_type' => ['required', 'in:all,filter,specific,segment'],
                'target_filters' => ['nullable', 'array'],
                'target_identifiers' => ['nullable'],
                'target_segment_ids' => ['nullable', 'array'],
                'target_segment_ids.*' => ['integer'],
            ]);

            return $this->successfulResponseJSON(['preview' => $requestService->previewForPayload($payload)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function publish(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $archiveRequest = $requestService->publish($archiveRequest, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil dipublish.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function appendTargets(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $payload = $this->validatedTargetPayload($request);
            $summary = $requestService->appendTargets($archiveRequest, $payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['summary' => $summary], 'Target berhasil diproses.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function close(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $archiveRequest = $requestService->close($archiveRequest, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil ditutup.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function reopen(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $archiveRequest = $requestService->reopen($archiveRequest, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil dibuka lagi.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function archive(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);
            $archiveRequest = $requestService->archive($archiveRequest, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['request' => $archiveRequest->toArray()], 'Request berhasil diarsipkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function progress(Request $request, int $request_id, RoleResolverService $roleResolver, ArchiveRequestService $requestService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);

            return $this->successfulResponseJSON(['progress' => $requestService->progress($archiveRequest)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'target_role' => [$required, 'in:mahasiswa,dosen'],
            'scope_type' => [$required, 'in:all,filter,specific,segment'],
            'target_filters' => ['nullable', 'array'],
            'target_identifiers' => ['nullable'],
            'target_segment_ids' => ['nullable', 'array'],
            'target_segment_ids.*' => ['integer'],
            'max_files' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:200'],
            'allowed_extensions' => ['nullable', 'array'],
            'allowed_extensions.*' => ['string', 'regex:/^[A-Za-z0-9]+$/'],
            'requires_verification' => ['sometimes', 'boolean'],
            'allow_file_reuse' => ['sometimes', 'boolean'],
            'allow_inactive_upload' => ['sometimes', 'boolean'],
            'deadline_at' => ['nullable', 'date'],
            'close_after_deadline' => ['sometimes', 'boolean'],
        ]);
    }

    private function validatedTargetPayload(Request $request): array
    {
        return $request->validate([
            'target_role' => ['required', 'in:mahasiswa,dosen'],
            'scope_type' => ['required', 'in:all,filter,specific,segment'],
            'target_filters' => ['nullable', 'array'],
            'target_identifiers' => ['nullable'],
            'target_segment_ids' => ['nullable', 'array'],
            'target_segment_ids.*' => ['integer'],
        ]);
    }
}
