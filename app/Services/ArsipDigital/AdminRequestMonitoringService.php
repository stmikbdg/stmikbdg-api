<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Category;
use App\Models\ArsipDigital\RequestAssignment;
use App\Models\ArsipDigital\RequestFile;
use App\Models\ArsipDigital\SegmentMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminRequestMonitoringService
{
    public function __construct(
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArsipDigitalStorageService $storage,
        private readonly ArchiveUploadValidationService $uploadValidation,
        private readonly TargetResolverService $targetResolver,
        private readonly RequestStatusWorkflowService $workflow,
        private readonly AuditLogService $auditLog,
        private readonly NotificationService $notifications,
    ) {}

    public function assignmentQuery(int $requestId, array $filters = []): Builder
    {
        $query = RequestAssignment::where('request_id', $requestId)
            ->with('requestFiles.file');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('is_late', $filters) && $filters['is_late'] !== null) {
            $query->where('is_late', filter_var($filters['is_late'], FILTER_VALIDATE_BOOL));
        }

        if (! empty($filters['angkatan'])) {
            $query->where('angkatan_snapshot', $filters['angkatan']);
        }

        if (! empty($filters['target_role'])) {
            $query->where('target_role', $filters['target_role']);
        }

        if (! empty($filters['identifier'])) {
            $query->where('identifier', $filters['identifier']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower($filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(identifier) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(name_snapshot) LIKE ?', [$search]);
            });
        }

        if (! empty($filters['scholarship_type_id'])) {
            $query->whereJsonContains('scholarship_snapshot', [
                ['scholarship_type_id' => (int) $filters['scholarship_type_id']],
            ]);
        }

        if (! empty($filters['scholarship_status'])) {
            $query->whereJsonContains('scholarship_snapshot', [
                ['status' => $filters['scholarship_status']],
            ]);
        }

        if (! empty($filters['scholarship'])) {
            $search = '%'.strtolower($filters['scholarship']).'%';
            $query->whereRaw('LOWER(COALESCE(scholarship_snapshot::text, \'\')) LIKE ?', [$search]);
        }

        if (! empty($filters['segment_id'])) {
            $identifiers = SegmentMember::where('segment_id', $filters['segment_id'])
                ->pluck('identifier')
                ->toArray();
            $query->whereIn('identifier', $identifiers);
        }

        return $query->orderBy('identifier');
    }

    public function approve(RequestAssignment $assignment, object $actor, string $actorRole, $httpRequest = null): RequestAssignment
    {
        $this->ensureWaitingVerification($assignment);

        if (! $this->hasCurrentFile($assignment)) {
            throw new HttpException(422, 'Assignment belum memiliki file aktif untuk diverifikasi.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($assignment, $actor, $actorRole, $httpRequest): RequestAssignment {
            RequestFile::where('assignment_id', $assignment->assignment_id)
                ->where('is_current', true)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'approved',
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'reject_reason' => null,
                    'updated_at' => now(),
                ]);

            $assignment->fill([
                'status' => 'approved',
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'reject_reason' => null,
            ]);
            $assignment->save();

            $this->auditLog->record(
                'request_assignment.approved',
                'request_assignment',
                $assignment->assignment_id,
                'Assignment request arsip digital disetujui admin.',
                ['request_id' => $assignment->request_id],
                $httpRequest,
                $actor->id,
                $actorRole
            );

            $this->notifyAssignmentResult($assignment, 'request_file_approved');

            return $assignment->fresh('requestFiles.file');
        });
    }

    public function reject(RequestAssignment $assignment, string $reason, object $actor, string $actorRole, $httpRequest = null): RequestAssignment
    {
        if (trim($reason) === '') {
            throw new HttpException(422, 'Alasan reject wajib diisi.');
        }

        $this->ensureWaitingVerification($assignment);

        if (! $this->hasCurrentFile($assignment)) {
            throw new HttpException(422, 'Assignment belum memiliki file aktif untuk diverifikasi.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($assignment, $reason, $actor, $actorRole, $httpRequest): RequestAssignment {
            RequestFile::where('assignment_id', $assignment->assignment_id)
                ->where('is_current', true)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'reject_reason' => $reason,
                    'updated_at' => now(),
                ]);

            $assignment->fill([
                'status' => 'rejected',
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'reject_reason' => $reason,
            ]);
            $assignment->save();

            $this->auditLog->record(
                'request_assignment.rejected',
                'request_assignment',
                $assignment->assignment_id,
                'Assignment request arsip digital ditolak admin.',
                ['request_id' => $assignment->request_id, 'reason' => $reason],
                $httpRequest,
                $actor->id,
                $actorRole
            );

            $this->notifyAssignmentResult($assignment, 'request_file_rejected', $reason);

            return $assignment->fresh('requestFiles.file');
        });
    }

    public function bulkApprove(array $assignmentIds, object $actor, string $actorRole, $httpRequest = null): array
    {
        $assignments = RequestAssignment::with('requestFiles.file')
            ->whereIn('assignment_id', $assignmentIds)
            ->get();

        $updated = [];
        $skipped = [];

        foreach ($assignments as $assignment) {
            if ($assignment->status !== 'waiting_verification') {
                $skipped[] = [
                    'assignment_id' => $assignment->assignment_id,
                    'identifier' => $assignment->identifier,
                    'reason' => 'Status bukan menunggu verifikasi.',
                ];

                continue;
            }

            if (! $this->hasCurrentFile($assignment)) {
                $skipped[] = [
                    'assignment_id' => $assignment->assignment_id,
                    'identifier' => $assignment->identifier,
                    'reason' => 'Assignment belum memiliki file aktif untuk diverifikasi.',
                ];

                continue;
            }

            $updated[] = $this->approve($assignment, $actor, $actorRole, $httpRequest)->toArray();
        }

        return [
            'updated' => count($updated),
            'skipped' => count($skipped),
            'assignments' => $updated,
            'skipped_assignments' => $skipped,
        ];
    }

    public function bulkReject(array $assignmentIds, string $reason, object $actor, string $actorRole, $httpRequest = null): array
    {
        $assignments = RequestAssignment::with('requestFiles.file')
            ->whereIn('assignment_id', $assignmentIds)
            ->get();

        $updated = [];
        $skipped = [];

        foreach ($assignments as $assignment) {
            if ($assignment->status !== 'waiting_verification') {
                $skipped[] = [
                    'assignment_id' => $assignment->assignment_id,
                    'identifier' => $assignment->identifier,
                    'reason' => 'Status bukan menunggu verifikasi.',
                ];

                continue;
            }

            if (! $this->hasCurrentFile($assignment)) {
                $skipped[] = [
                    'assignment_id' => $assignment->assignment_id,
                    'identifier' => $assignment->identifier,
                    'reason' => 'Assignment belum memiliki file aktif untuk diverifikasi.',
                ];

                continue;
            }

            $updated[] = $this->reject($assignment, $reason, $actor, $actorRole, $httpRequest)->toArray();
        }

        return [
            'updated' => count($updated),
            'skipped' => count($skipped),
            'assignments' => $updated,
            'skipped_assignments' => $skipped,
        ];
    }

    private function ensureWaitingVerification(RequestAssignment $assignment): void
    {
        if ($assignment->status !== 'waiting_verification') {
            throw new HttpException(422, 'Status bukan menunggu verifikasi.');
        }
    }

    private function hasCurrentFile(RequestAssignment $assignment): bool
    {
        return RequestFile::where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function notifyAssignmentResult(RequestAssignment $assignment, string $type, ?string $reason = null): void
    {
        $request = $assignment->request ?: $assignment->request()->first();
        $requestTitle = $request?->title ?? 'arsip digital';
        $requestFileId = RequestFile::where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->orderByDesc('request_file_id')
            ->value('request_file_id');
        $shortReason = $reason === null ? null : Str::limit(trim($reason), 120);
        $approved = $type === 'request_file_approved';

        $this->notifications->sendToUser(
            (int) $assignment->target_user_id,
            $assignment->target_role,
            $type,
            $approved ? 'File request disetujui' : 'File request ditolak',
            $approved ? 'File untuk request '.$requestTitle.' disetujui.' : 'File untuk request '.$requestTitle.' ditolak: '.$shortReason,
            'request_assignment',
            $assignment->assignment_id,
            [
                'request_id' => $assignment->request_id,
                'assignment_id' => $assignment->assignment_id,
                'request_file_id' => $requestFileId,
                'reason' => $shortReason,
            ]
        );
    }

    public function uploadForUser(UploadedFile $uploadedFile, array $payload, object $actor, string $actorRole, $httpRequest = null): array
    {
        $ownerRole = $payload['owner_role'];
        $resolved = $this->targetResolver->resolve($ownerRole, $payload['owner_identifier']);

        if (! $resolved['valid']) {
            throw new HttpException(422, $resolved['error']);
        }

        $assignment = null;
        if (! empty($payload['request_assignment_id'])) {
            $assignment = RequestAssignment::with('request')->findOrFail($payload['request_assignment_id']);
            if ($assignment->target_user_id !== $resolved['target_user_id'] || $assignment->target_role !== $ownerRole) {
                throw new HttpException(422, 'Owner tidak sesuai dengan assignment request.');
            }
        }

        $category = null;
        if (! empty($payload['category_id'])) {
            $category = Category::findOrFail($payload['category_id']);

            if (
                $category->category_type !== 'personal'
                || $category->owner_user_id !== $resolved['target_user_id']
                || $category->owner_role !== $ownerRole
            ) {
                throw new HttpException(422, 'Kategori tidak sesuai dengan owner file.');
            }
        }

        $settings = $this->settings->getDefaults();
        $request = $assignment?->request;
        $maxFileSizeMb = $request?->max_file_size_mb ?: $settings['default_max_file_size_mb'];
        $allowedExtensions = $request?->allowed_extensions ?: $settings['default_allowed_extensions'];
        $this->uploadValidation->validateUploadedFile($uploadedFile, (int) $maxFileSizeMb, $allowedExtensions);
        $this->assertPersonalQuotaAvailable((int) $resolved['target_user_id'], $ownerRole, $uploadedFile);

        $displayFilename = $this->storage->safeFilename($payload['display_filename'] ?? $uploadedFile->getClientOriginalName());
        $isLate = $request ? $this->workflow->isLate($request) : false;
        $requiresReview = (bool) ($payload['requires_review'] ?? false);
        $status = $requiresReview ? 'waiting_verification' : 'approved';

        if ($assignment) {
            $this->assertMaxFilesForAdminUpload($assignment, (int) $request->max_files, $displayFilename);
        }

        $storageMetadata = $this->storage->uploadPrivate($uploadedFile, $assignment ? 'request' : 'admin_upload', [
            'request_id' => $request?->request_id,
            'assignment_id' => $assignment?->assignment_id,
            'owner_user_id' => $resolved['target_user_id'],
        ]);

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
                $assignment,
                $request,
                $category,
                $resolved,
                $ownerRole,
                $actor,
                $actorRole,
                $storageMetadata,
                $displayFilename,
                $status,
                $isLate,
                $payload,
                $uploadedFile,
                $httpRequest
            ): array {
                DB::connection(config('myconfig.database.first_connection'))
                    ->table('users')
                    ->where('id', $resolved['target_user_id'])
                    ->lockForUpdate()
                    ->first();
                $this->assertPersonalQuotaAvailable((int) $resolved['target_user_id'], $ownerRole, $uploadedFile);

                $lockedCategory = null;
                if ($category) {
                    $lockedCategory = Category::where('category_id', $category->category_id)->lockForUpdate()->first();
                    if (
                        ! $lockedCategory
                        || $lockedCategory->category_type !== 'personal'
                        || $lockedCategory->owner_user_id !== $resolved['target_user_id']
                        || $lockedCategory->owner_role !== $ownerRole
                    ) {
                        throw new HttpException(422, 'Kategori tidak sesuai dengan owner file.');
                    }
                }

                if ($assignment) {
                    $version = $this->nextRequestVersion($assignment, $displayFilename);
                    $this->replaceCurrentRequestFileByName($assignment, $displayFilename, true);
                } else {
                    $latest = ArchiveFile::withTrashed()
                        ->where('owner_user_id', $resolved['target_user_id'])
                        ->where('owner_role', $ownerRole)
                        ->whereIn('source_type', ['personal', 'admin_upload'])
                        ->where('display_filename', $displayFilename)
                        ->when($lockedCategory, fn ($query) => $query->where('category_id', $lockedCategory->category_id), fn ($query) => $query->whereNull('category_id'))
                        ->orderByDesc('version_number')
                        ->lockForUpdate()
                        ->first();
                    $version = $latest
                        ? ['version_group_uuid' => $latest->version_group_uuid, 'version_number' => $latest->version_number + 1]
                        : ['version_group_uuid' => (string) Str::uuid(), 'version_number' => 1];

                    $currentQuery = ArchiveFile::where('owner_user_id', $resolved['target_user_id'])
                        ->where('owner_role', $ownerRole)
                        ->whereIn('source_type', ['personal', 'admin_upload'])
                        ->where('display_filename', $displayFilename)
                        ->where('is_current', true)
                        ->whereNull('deleted_at');
                    $lockedCategory
                        ? $currentQuery->where('category_id', $lockedCategory->category_id)
                        : $currentQuery->whereNull('category_id');
                    $currentQuery->update(['is_current' => false, 'status' => 'replaced', 'updated_at' => now()]);
                }

                $file = ArchiveFile::create([
                    'category_id' => $lockedCategory?->category_id,
                    'owner_user_id' => $resolved['target_user_id'],
                    'owner_role' => $ownerRole,
                    'owner_identifier' => $resolved['identifier'],
                    'owner_name_snapshot' => $resolved['name_snapshot'],
                    'owner_status_snapshot' => $resolved['status_snapshot'],
                    'uploaded_by_user_id' => $actor->id,
                    'uploaded_by_role' => $actorRole,
                    'source_type' => $assignment ? 'request' : 'admin_upload',
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
                        'assignment_id' => $assignment?->assignment_id,
                        'request_id' => $request?->request_id,
                        'note' => $payload['note'] ?? null,
                    ],
                ]);

                $requestFile = null;
                if ($assignment) {
                    $requestFile = RequestFile::create([
                        'request_id' => $request->request_id,
                        'assignment_id' => $assignment->assignment_id,
                        'file_id' => $file->file_id,
                        'submission_type' => 'admin_uploaded',
                        'status' => $status,
                        'is_late' => $isLate,
                        'is_current' => true,
                        'note' => $payload['note'] ?? null,
                        'created_by_user_id' => $actor->id,
                        'created_by_role' => $actorRole,
                    ]);

                    $assignment->fill([
                        'status' => $status,
                        'is_late' => $assignment->is_late || $isLate,
                        'submitted_at' => now(),
                        'verified_at' => $status === 'approved' ? now() : null,
                        'verified_by_user_id' => $status === 'approved' ? $actor->id : null,
                        'reject_reason' => null,
                    ]);
                    $assignment->save();
                }

                $this->auditLog->record(
                    'request_file.admin_uploaded',
                    $requestFile ? 'request_file' : 'file',
                    $requestFile?->request_file_id ?? $file->file_id,
                    'Admin upload file untuk user arsip digital.',
                    ['request_id' => $request?->request_id, 'assignment_id' => $assignment?->assignment_id, 'file_id' => $file->file_id, 'status' => $status],
                    $httpRequest,
                    $actor->id,
                    $actorRole
                );

                return [
                    'file' => $file->fresh(),
                    'request_file' => $requestFile?->fresh('file'),
                ];
            }, 3);
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($storageMetadata['storage_disk'], $storageMetadata['storage_path']);
            } catch (\Throwable) {
                // Preserve the original database exception.
            }

            throw $e;
        }
    }

    private function assertPersonalQuotaAvailable(int $ownerUserId, string $role, UploadedFile $uploadedFile): void
    {
        $quotaMb = $this->settings->personalQuotaMbForRole($role);
        if ($quotaMb === null) {
            return;
        }

        $usedBytes = (int) ArchiveFile::where('owner_user_id', $ownerUserId)
            ->where('owner_role', $role)
            ->whereIn('source_type', ['personal', 'admin_upload'])
            ->whereNull('deleted_at')
            ->sum('file_size_bytes');
        $quotaBytes = $quotaMb * 1024 * 1024;
        $uploadBytes = (int) ($uploadedFile->getSize() ?: 0);

        if ($usedBytes + $uploadBytes > $quotaBytes) {
            $remainingMb = max(0, round(($quotaBytes - $usedBytes) / 1024 / 1024, 2));
            throw ValidationException::withMessages([
                'file' => "Kuota penyimpanan arsip pribadi sudah tidak cukup. Sisa kuota: {$remainingMb} MB.",
            ]);
        }
    }

    private function assertMaxFilesForAdminUpload(RequestAssignment $assignment, int $maxFiles, string $displayFilename): void
    {
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

    private function replaceCurrentRequestFileByName(RequestAssignment $assignment, string $displayFilename, bool $replaceArchiveFile): void
    {
        RequestFile::where('assignment_id', $assignment->assignment_id)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->with('file')
            ->get()
            ->filter(fn (RequestFile $requestFile): bool => $requestFile->file?->display_filename === $displayFilename)
            ->each(function (RequestFile $requestFile) use ($replaceArchiveFile): void {
                $requestFile->fill(['is_current' => false, 'status' => 'replaced']);
                $requestFile->save();

                if ($replaceArchiveFile && $requestFile->file) {
                    $requestFile->file->fill(['is_current' => false, 'status' => 'replaced']);
                    $requestFile->file->save();
                }
            });
    }

    private function nextRequestVersion(RequestAssignment $assignment, string $displayFilename): array
    {
        $latest = RequestFile::where('assignment_id', $assignment->assignment_id)
            ->whereHas('file', fn ($query) => $query->where('display_filename', $displayFilename))
            ->with('file')
            ->get()
            ->pluck('file')
            ->filter()
            ->sortByDesc('version_number')
            ->first();

        if (! $latest) {
            return ['version_group_uuid' => (string) Str::uuid(), 'version_number' => 1];
        }

        return [
            'version_group_uuid' => $latest->version_group_uuid,
            'version_number' => $latest->version_number + 1,
        ];
    }
}
