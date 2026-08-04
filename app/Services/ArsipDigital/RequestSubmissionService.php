<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\RequestAssignment;
use App\Models\ArsipDigital\RequestFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RequestSubmissionService
{
    public function __construct(
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArsipDigitalStorageService $storage,
        private readonly RequestStatusWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
        private readonly ArchiveUploadValidationService $uploadValidation,
        private readonly NotificationService $notifications,
        private readonly ArchiveCategoryService $categories,
    ) {}

    public function upload(RequestAssignment $assignment, UploadedFile $uploadedFile, array $payload, object $user, string $role, $httpRequest = null): RequestFile
    {
        $request = $assignment->request;
        $this->assertAssignmentOwner($assignment, $user, $role);
        $this->workflow->assertUserSubmissionAllowed($request);
        $this->validateUploadFile($uploadedFile, $request);

        $displayFilename = $this->storage->safeFilename($payload['display_filename'] ?? $uploadedFile->getClientOriginalName());
        $replacedRequestFile = $this->resolveReplacement($assignment, $payload['replace_request_file_id'] ?? null);
        $this->assertMaxFiles($assignment, (int) $request->max_files, $displayFilename, $replacedRequestFile);

        $storageMetadata = $this->storage->uploadPrivate($uploadedFile, 'request', [
            'request_id' => $request->request_id,
            'assignment_id' => $assignment->assignment_id,
        ]);
        $isLate = $this->workflow->isLate($request);
        $status = $this->workflow->submissionStatus($request);

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
                $assignment,
                $request,
                $user,
                $role,
                $storageMetadata,
                $displayFilename,
                $isLate,
                $status,
                $payload,
                $replacedRequestFile,
                $httpRequest
            ): RequestFile {
                $version = $this->nextRequestVersion($assignment, $displayFilename, $replacedRequestFile);
                if ($replacedRequestFile) {
                    $this->replaceRequestFile($replacedRequestFile);
                } else {
                    $this->replaceCurrentRequestFileByName($assignment, $displayFilename, true);
                }

                $category = $this->categories->systemPersonalCategory(
                    $assignment->target_user_id,
                    $assignment->target_role,
                    $request->title,
                    $user,
                    $role
                );

                $file = ArchiveFile::create([
                    'category_id' => $category->category_id,
                    'owner_user_id' => $assignment->target_user_id,
                    'owner_role' => $assignment->target_role,
                    'owner_identifier' => $assignment->identifier,
                    'owner_name_snapshot' => $assignment->name_snapshot,
                    'owner_status_snapshot' => $assignment->status_snapshot,
                    'uploaded_by_user_id' => $user->id,
                    'uploaded_by_role' => $role,
                    'source_type' => 'request',
                    'original_filename' => $storageMetadata['original_filename'],
                    'display_filename' => $displayFilename,
                    'storage_disk' => $storageMetadata['storage_disk'],
                    'storage_path' => $storageMetadata['storage_path'],
                    'mime_type' => $storageMetadata['mime_type'],
                    'extension' => $storageMetadata['extension'],
                    'file_size_bytes' => $storageMetadata['file_size_bytes'],
                    'checksum_sha256' => $storageMetadata['checksum_sha256'],
                    'version_group_uuid' => $version['version_group_uuid'],
                    'version_number' => $version['version_number'],
                    'is_current' => true,
                    'status' => 'active',
                    'metadata' => [
                        'assignment_id' => $assignment->assignment_id,
                        'request_id' => $request->request_id,
                        'note' => $payload['note'] ?? null,
                    ],
                ]);

                $requestFile = RequestFile::create([
                    'request_id' => $request->request_id,
                    'assignment_id' => $assignment->assignment_id,
                    'file_id' => $file->file_id,
                    'submission_type' => 'uploaded',
                    'status' => $status,
                    'is_late' => $isLate,
                    'is_current' => true,
                    'note' => $payload['note'] ?? null,
                    'created_by_user_id' => $user->id,
                    'created_by_role' => $role,
                ]);

                $this->updateAssignmentAfterSubmission($assignment, $status, $isLate);

                $this->auditLog->record(
                    'request_file.uploaded',
                    'request_file',
                    $requestFile->request_file_id,
                    'File request arsip digital diupload oleh target.',
                    ['request_id' => $request->request_id, 'assignment_id' => $assignment->assignment_id, 'file_id' => $file->file_id],
                    $httpRequest,
                    $user->id,
                    $role
                );

                $this->notifyAdmins($requestFile, $assignment, 'request_file_submitted', 'diupload', $isLate);

                return $requestFile->fresh('file');
            });
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($storageMetadata['storage_disk'], $storageMetadata['storage_path']);
            } catch (\Throwable) {
                // Preserve the original database exception for the caller.
            }

            throw $e;
        }
    }

    public function reuse(RequestAssignment $assignment, ArchiveFile $file, array $payload, object $user, string $role, $httpRequest = null): RequestFile
    {
        $request = $assignment->request;
        $this->assertAssignmentOwner($assignment, $user, $role);
        $this->workflow->assertUserSubmissionAllowed($request);

        if (! $request->allow_file_reuse) {
            throw new HttpException(422, 'Request ini tidak mengizinkan reuse file.');
        }

        $this->assertReusableFile($assignment, $file, $request);

        $displayFilename = $file->display_filename;
        $replacedRequestFile = $this->resolveReplacement($assignment, $payload['replace_request_file_id'] ?? null);
        $isLate = $this->workflow->isLate($request);
        $status = $this->workflow->submissionStatus($request);

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
            $assignment,
            $request,
            $file,
            $user,
            $role,
            $displayFilename,
            $replacedRequestFile,
            $isLate,
            $status,
            $httpRequest
        ): RequestFile {
            DB::connection(config('myconfig.database.first_connection'))
                ->table('users')
                ->where('id', $user->id)
                ->lockForUpdate()
                ->first();
            $lockedFile = ArchiveFile::where('file_id', $file->file_id)->lockForUpdate()->firstOrFail();
            $this->assertReusableFile($assignment, $lockedFile, $request);

            $this->assertMaxFiles($assignment, (int) $request->max_files, $displayFilename, $replacedRequestFile);
            if ($replacedRequestFile) {
                $this->replaceRequestFile($replacedRequestFile);
            } else {
                $this->replaceCurrentRequestFileByName($assignment, $displayFilename, false);
            }

            $requestFile = RequestFile::create([
                'request_id' => $request->request_id,
                'assignment_id' => $assignment->assignment_id,
                'file_id' => $lockedFile->file_id,
                'submission_type' => 'reused',
                'status' => $status,
                'is_late' => $isLate,
                'is_current' => true,
                'created_by_user_id' => $user->id,
                'created_by_role' => $role,
            ]);

            $this->updateAssignmentAfterSubmission($assignment, $status, $isLate);

            $this->auditLog->record(
                'request_file.reused',
                'request_file',
                $requestFile->request_file_id,
                'File lama dipakai ulang untuk memenuhi request arsip digital.',
                ['request_id' => $request->request_id, 'assignment_id' => $assignment->assignment_id, 'file_id' => $file->file_id],
                $httpRequest,
                $user->id,
                $role
            );

            $this->notifyAdmins($requestFile, $assignment, 'request_file_reused', 'dipakai ulang', $isLate);

            return $requestFile->fresh('file');
        }, 3);
    }

    private function assertAssignmentOwner(RequestAssignment $assignment, object $user, string $role): void
    {
        if ($assignment->target_user_id !== $user->id || $assignment->target_role !== $role) {
            throw new HttpException(403, 'Tidak memiliki akses ke assignment request ini.');
        }

        if (! in_array($assignment->status, ['not_submitted', 'waiting_verification', 'rejected'], true)) {
            throw new HttpException(422, 'File assignment tidak dapat diubah pada status saat ini.');
        }
    }

    private function validateUploadFile(UploadedFile $file, $request): void
    {
        $settings = $this->settings->getDefaults();
        $maxFileSizeMb = $request->max_file_size_mb ?: $settings['default_max_file_size_mb'];
        $allowedExtensions = $request->allowed_extensions ?: $settings['default_allowed_extensions'];
        $this->uploadValidation->validateUploadedFile(
            $file,
            (int) $maxFileSizeMb,
            $allowedExtensions
        );
    }

    private function assertReusableFile(RequestAssignment $assignment, ArchiveFile $file, $request): void
    {
        if ($file->owner_user_id !== $assignment->target_user_id || $file->owner_role !== $assignment->target_role) {
            throw new HttpException(422, 'File tidak dimiliki oleh target assignment.');
        }

        if ($file->status !== 'active' || $file->deleted_at !== null || ! $file->is_current) {
            throw new HttpException(422, 'File tidak memenuhi syarat reuse.');
        }

        $settings = $this->settings->getDefaults();
        $this->uploadValidation->validateMetadataFile(
            $file->extension,
            (int) $file->file_size_bytes,
            (int) ($request->max_file_size_mb ?: $settings['default_max_file_size_mb']),
            $request->allowed_extensions ?: $settings['default_allowed_extensions'],
            $file->mime_type
        );
    }

    private function resolveReplacement(RequestAssignment $assignment, mixed $requestFileId): ?RequestFile
    {
        if ($requestFileId === null) {
            return null;
        }

        $requestFile = RequestFile::where('request_file_id', $requestFileId)
            ->where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->with('file')
            ->first();

        if (! $requestFile) {
            throw new HttpException(422, 'File yang akan diganti tidak valid.');
        }

        return $requestFile;
    }

    private function assertMaxFiles(RequestAssignment $assignment, int $maxFiles, string $displayFilename, ?RequestFile $replacedRequestFile = null): void
    {
        if ($replacedRequestFile) {
            return;
        }

        $currentFiles = RequestFile::where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->with('file')
            ->get();

        $sameNameExists = $currentFiles->contains(fn (RequestFile $requestFile): bool => $requestFile->file?->display_filename === $displayFilename);

        if (! $sameNameExists && $currentFiles->count() >= $maxFiles) {
            throw new HttpException(422, 'Jumlah file request sudah mencapai batas maksimal.');
        }
    }

    private function replaceRequestFile(RequestFile $requestFile): void
    {
        $requestFile->fill([
            'is_current' => false,
            'status' => 'replaced',
        ]);
        $requestFile->save();

        if ($requestFile->file?->source_type === 'request') {
            $requestFile->file->fill([
                'is_current' => false,
                'status' => 'replaced',
            ]);
            $requestFile->file->save();
        }
    }

    private function replaceCurrentRequestFileByName(RequestAssignment $assignment, string $displayFilename, bool $replaceArchiveFile): void
    {
        $currentFiles = RequestFile::where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->with('file')
            ->get()
            ->filter(fn (RequestFile $requestFile): bool => $requestFile->file?->display_filename === $displayFilename);

        foreach ($currentFiles as $requestFile) {
            $requestFile->fill([
                'is_current' => false,
                'status' => 'replaced',
            ]);
            $requestFile->save();

            if ($replaceArchiveFile && $requestFile->file) {
                $requestFile->file->fill([
                    'is_current' => false,
                    'status' => 'replaced',
                ]);
                $requestFile->file->save();
            }
        }
    }

    private function nextRequestVersion(RequestAssignment $assignment, string $displayFilename, ?RequestFile $replacedRequestFile = null): array
    {
        $latest = $replacedRequestFile?->file?->source_type === 'request' ? $replacedRequestFile->file : null;

        if (! $latest && ! $replacedRequestFile) {
            $latest = RequestFile::where('assignment_id', $assignment->assignment_id)
                ->whereHas('file', fn ($query) => $query->where('display_filename', $displayFilename))
                ->with('file')
                ->get()
                ->pluck('file')
                ->filter()
                ->sortByDesc('version_number')
                ->first();
        }

        if (! $latest) {
            return [
                'version_group_uuid' => (string) Str::uuid(),
                'version_number' => 1,
            ];
        }

        return [
            'version_group_uuid' => $latest->version_group_uuid,
            'version_number' => $latest->version_number + 1,
        ];
    }

    private function notifyAdmins(RequestFile $requestFile, RequestAssignment $assignment, string $type, string $verb, bool $isLate): void
    {
        $request = $assignment->request;
        $this->notifications->sendToAdmins([
            'type' => $type,
            'title' => $request->requires_verification ? 'File request menunggu verifikasi' : 'File request sudah '.$verb,
            'message' => trim($assignment->name_snapshot.' '.$verb.' file untuk request '.$request->title.'.'),
            'entity_type' => 'request_file',
            'entity_id' => $requestFile->request_file_id,
            'data' => [
                'request_id' => $request->request_id,
                'assignment_id' => $assignment->assignment_id,
                'request_file_id' => $requestFile->request_file_id,
                'identifier' => $assignment->identifier,
                'is_late' => $isLate,
            ],
        ]);
    }

    private function updateAssignmentAfterSubmission(RequestAssignment $assignment, string $status, bool $isLate): void
    {
        $assignment->fill([
            'status' => $status,
            'is_late' => $assignment->is_late || $isLate,
            'submitted_at' => now(),
            'verified_at' => $status === 'approved' ? now() : null,
            'verified_by_user_id' => null,
            'reject_reason' => null,
        ]);
        $assignment->save();
    }
}
