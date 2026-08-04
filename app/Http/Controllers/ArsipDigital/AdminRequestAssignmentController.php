<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\ArchiveRequest;
use App\Models\ArsipDigital\RequestAssignment;
use App\Models\ArsipDigital\RequestFile;
use App\Services\ArsipDigital\AdminRequestMonitoringService;
use App\Services\ArsipDigital\ArchiveRequestService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminRequestAssignmentController extends Controller
{
    public function assignments(
        Request $request,
        int $request_id,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            ArchiveRequest::findOrFail($request_id);

            $filters = $request->validate([
                'status' => ['sometimes', 'in:not_submitted,waiting_verification,approved,rejected,closed'],
                'is_late' => ['sometimes', 'boolean'],
                'target_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'identifier' => ['sometimes', 'string', 'max:100'],
                'angkatan' => ['sometimes', 'string', 'max:20'],
                'scholarship' => ['sometimes', 'string', 'max:255'],
                'scholarship_type_id' => ['sometimes', 'integer'],
                'scholarship_status' => ['sometimes', 'string', 'max:50'],
                'segment_id' => ['sometimes', 'integer'],
                'search' => ['sometimes', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);

            $assignments = $monitoringService->assignmentQuery($request_id, $filters)
                ->paginate($filters['per_page'] ?? 50);

            return $this->successfulResponseJSON([
                'assignments' => $assignments->items(),
                'meta' => [
                    'current_page' => $assignments->currentPage(),
                    'last_page' => $assignments->lastPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function progress(
        Request $request,
        int $request_id,
        RoleResolverService $roleResolver,
        ArchiveRequestService $requestService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);
            $archiveRequest = ArchiveRequest::findOrFail($request_id);

            return $this->successfulResponseJSON(['progress' => $requestService->progress($archiveRequest)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function approve(
        Request $request,
        int $assignment_id,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $assignment = RequestAssignment::with('requestFiles.file')->findOrFail($assignment_id);
            $assignment = $monitoringService->approve($assignment, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['assignment' => $assignment->toArray()], 'Assignment berhasil disetujui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function reject(
        Request $request,
        int $assignment_id,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'reason' => ['required', 'string', 'min:1'],
            ]);
            $assignment = RequestAssignment::with('requestFiles.file')->findOrFail($assignment_id);
            $assignment = $monitoringService->reject($assignment, $payload['reason'], auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['assignment' => $assignment->toArray()], 'Assignment berhasil ditolak.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function bulkApprove(
        Request $request,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'assignment_ids' => ['required', 'array', 'min:1'],
                'assignment_ids.*' => ['integer'],
            ]);
            $result = $monitoringService->bulkApprove(array_values(array_unique($payload['assignment_ids'])), auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['result' => $result], 'Bulk approve selesai.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function bulkReject(
        Request $request,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'assignment_ids' => ['required', 'array', 'min:1'],
                'assignment_ids.*' => ['integer'],
                'reason' => ['required', 'string', 'min:1'],
            ]);
            $result = $monitoringService->bulkReject(array_values(array_unique($payload['assignment_ids'])), $payload['reason'], auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['result' => $result], 'Bulk reject selesai.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function downloadRequestFile(
        Request $request,
        int $request_file_id,
        RoleResolverService $roleResolver,
        ArsipDigitalStorageService $storageService,
        AuditLogService $auditLog
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $requestFile = RequestFile::with('file')->findOrFail($request_file_id);
            $file = $requestFile->file;

            if (! $file) {
                return $this->failedResponseJSON('File tidak ditemukan.', 404);
            }

            $auditLog->record(
                'request_file.admin_downloaded',
                'request_file',
                $requestFile->request_file_id,
                'Admin download file request arsip digital.',
                ['file_id' => $file->file_id, 'assignment_id' => $requestFile->assignment_id, 'request_id' => $requestFile->request_id],
                $request,
                auth()->user()?->id,
                $role
            );

            return $storageService->downloadPrivate($file->storage_disk, $file->storage_path, $file->display_filename);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function uploadForUser(
        Request $request,
        RoleResolverService $roleResolver,
        AdminRequestMonitoringService $monitoringService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'file' => ['required', 'file'],
                'owner_role' => ['required', 'in:mahasiswa,dosen'],
                'owner_identifier' => ['required', 'string', 'max:100'],
                'category_id' => ['nullable', 'integer'],
                'request_assignment_id' => ['nullable', 'integer'],
                'requires_review' => ['sometimes', 'boolean'],
                'display_filename' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string'],
            ]);

            $result = $monitoringService->uploadForUser($request->file('file'), $payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON([
                'file' => $result['file']?->toArray(),
                'request_file' => $result['request_file']?->toArray(),
            ], 'File user berhasil diupload admin.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
