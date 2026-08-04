<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\ArchiveFileService;
use App\Services\ArsipDigital\ArchivePermissionService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class ArchiveFileController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, ArchiveFileService $fileService)
    {
        try {
            $role = $roleResolver->resolve($request);

            $filters = $request->validate([
                'owner_role' => ['sometimes', 'in:mahasiswa,dosen,admin'],
                'owner_user_id' => ['sometimes', 'integer'],
                'owner_identifier' => ['sometimes', 'string', 'max:255'],
                'category_id' => ['sometimes', 'integer'],
                'extension' => ['sometimes', 'string', 'max:20'],
                'search' => ['nullable', 'string', 'max:255'],
                'is_current' => ['sometimes', 'boolean'],
                'with_deleted' => ['sometimes', 'boolean'],
            ]);

            $files = $fileService->queryFor(auth()->user(), $role, $filters)->get();

            return $this->successfulResponseJSON([
                'files' => $files->map(fn ($file): array => $this->filePayload($file))->toArray(),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(
        Request $request,
        RoleResolverService $roleResolver,
        ArchiveFileService $fileService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);

            $payload = $request->validate([
                'file' => ['required', 'file'],
                'category_id' => ['nullable', 'integer'],
                'display_filename' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string'],
            ]);

            $file = $fileService->uploadPersonal($request->file('file'), $payload, auth()->user(), $role);

            $auditLogService->record(
                'file.uploaded',
                'file',
                $file->file_id,
                'File personal arsip digital diupload.',
                [
                    'category_id' => $file->category_id,
                    'version_group_uuid' => $file->version_group_uuid,
                    'version_number' => $file->version_number,
                ],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['file' => $file->toArray()], 'File berhasil diupload.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function move(
        Request $request,
        RoleResolverService $roleResolver,
        ArchiveFileService $fileService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);
            $payload = $request->validate([
                'file_ids' => ['required', 'array', 'min:1', 'max:100'],
                'file_ids.*' => ['required', 'integer', 'distinct'],
                'category_id' => ['nullable', 'integer'],
            ]);

            $result = $fileService->move($payload['file_ids'], $payload['category_id'] ?? null, auth()->user(), $role);

            $auditLogService->record(
                'file.moved',
                'file_batch',
                null,
                'File arsip pribadi dipindahkan.',
                $result,
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['move' => $result], 'File berhasil dipindahkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $file_id, RoleResolverService $roleResolver, ArchiveFileService $fileService)
    {
        try {
            $role = $roleResolver->resolve($request);
            $file = $fileService->findVisible($file_id, auth()->user(), $role, $request->boolean('with_deleted'));

            return $this->successfulResponseJSON(['file' => $file->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function versions(Request $request, int $file_id, RoleResolverService $roleResolver, ArchiveFileService $fileService)
    {
        try {
            $role = $roleResolver->resolve($request);
            $versions = $fileService->versionsFor($file_id, auth()->user(), $role);

            return $this->successfulResponseJSON(['versions' => $versions->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function download(
        Request $request,
        int $file_id,
        RoleResolverService $roleResolver,
        ArchiveFileService $fileService,
        ArchivePermissionService $permissionService,
        ArsipDigitalStorageService $storageService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request);
            $file = $fileService->findVisible($file_id, auth()->user(), $role);

            if (! $permissionService->canDownloadFile($file, auth()->user(), $role)) {
                $message = $file->source_type === 'distribution'
                    ? 'File distribution harus didownload melalui endpoint distribution.'
                    : 'Tidak memiliki akses download file.';

                return $this->failedResponseJSON($message, 403);
            }

            $auditLogService->record(
                'file.downloaded',
                'file',
                $file->file_id,
                'File arsip digital didownload.',
                ['storage_disk' => $file->storage_disk],
                $request,
                auth()->user()?->id,
                $role
            );

            return $storageService->downloadPrivate($file->storage_disk, $file->storage_path, $file->display_filename);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(
        Request $request,
        int $file_id,
        RoleResolverService $roleResolver,
        ArchiveFileService $fileService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request);
            $payload = $request->validate([
                'reason' => ['nullable', 'string'],
            ]);

            $file = $fileService->findVisible($file_id, auth()->user(), $role);
            $fileService->delete($file, auth()->user(), $role, $payload['reason'] ?? null);

            $auditLogService->record(
                'file.deleted',
                'file',
                $file->file_id,
                $role === 'admin' ? 'File arsip digital dihapus secara soft delete.' : 'File arsip pribadi dihapus permanen.',
                ['reason' => $payload['reason'] ?? null],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSONV2('File berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function restore(
        Request $request,
        int $file_id,
        RoleResolverService $roleResolver,
        ArchiveFileService $fileService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $file = $fileService->restore($file_id);

            $auditLogService->record(
                'file.restored',
                'file',
                $file->file_id,
                'File arsip digital direstore oleh admin.',
                [],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['file' => $file->toArray()], 'File berhasil direstore.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function filePayload($file): array
    {
        $payload = $file->toArray();
        $requestFile = $file->requestFile;
        $archiveRequest = $requestFile?->request;

        if ($requestFile && $archiveRequest) {
            $payload['archive_folder'] = [
                'key' => 'request:'.$archiveRequest->request_id,
                'type' => 'request',
                'label' => $archiveRequest->title,
                'group_label' => 'Permintaan Berkas',
                'request_id' => $archiveRequest->request_id,
            ];
        } elseif ($file->category_id) {
            $payload['archive_folder'] = [
                'key' => 'category:'.$file->category_id,
                'type' => 'category',
                'label' => 'Kategori #'.$file->category_id,
                'group_label' => 'Kategori',
                'category_id' => $file->category_id,
            ];
        } else {
            $payload['archive_folder'] = [
                'key' => 'root',
                'type' => 'root',
                'label' => 'Root',
                'group_label' => 'Arsip Pribadi',
            ];
        }

        return $payload;
    }
}
