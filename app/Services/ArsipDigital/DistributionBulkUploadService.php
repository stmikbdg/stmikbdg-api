<?php

namespace App\Services\ArsipDigital;

use App\Jobs\ArsipDigital\ProcessDistributionBulkUploadZipJob;
use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionBulkUploadEntry;
use App\Models\ArsipDigital\DistributionBulkUploadJob;
use App\Models\ArsipDigital\DistributionRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use ZipArchive;

class DistributionBulkUploadService
{
    private const MAX_ZIP_SIZE_MB = 100;

    private const MAX_ZIP_ENTRIES = 1000;

    public function __construct(
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArsipDigitalStorageService $storage,
        private readonly ArchiveUploadValidationService $uploadValidation,
        private readonly AuditLogService $auditLog,
        private readonly NotificationService $notifications,
        private readonly ArchiveCategoryService $categories,
    ) {}

    public function adminQuery(array $filters = []): Builder
    {
        $query = DistributionBulkUploadJob::query()->with('distribution');

        if (! empty($filters['distribution_id'])) {
            $query->where('distribution_id', $filters['distribution_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('bulk_upload_job_id');
    }

    public function createPreviewJob(Distribution $distribution, UploadedFile $zipFile, object $actor, string $actorRole, $httpRequest = null): DistributionBulkUploadJob
    {
        if (! in_array($distribution->status, ['draft', 'published'], true)) {
            throw new HttpException(422, 'Bulk upload ZIP hanya dapat dibuat untuk distribution draft atau published.');
        }

        if (! DistributionRecipient::where('distribution_id', $distribution->distribution_id)->exists()) {
            throw new HttpException(422, 'Distribution belum memiliki recipient untuk dicocokkan.');
        }

        $this->validateZipUpload($zipFile);

        $defaults = $this->settings->getDefaults();
        $disk = $defaults['storage_disk'];
        $safeFilename = $this->storage->safeFilename($zipFile->getClientOriginalName());

        $job = DistributionBulkUploadJob::create([
            'distribution_id' => $distribution->distribution_id,
            'uploaded_by_user_id' => $actor->id,
            'status' => 'uploaded',
            'original_filename' => $zipFile->getClientOriginalName(),
            'file_size_bytes' => $zipFile->getSize(),
            'expires_at' => now()->addDays(7),
        ]);

        $storagePath = sprintf(
            'arsip-digital/%s/distribution-bulk-upload-jobs/%s/source/%s_%s',
            app()->environment(),
            $job->bulk_upload_job_id,
            Str::uuid(),
            $safeFilename
        );

        $stream = fopen($zipFile->getRealPath(), 'r');
        $stored = false;

        try {
            $stored = Storage::disk($disk)->put($storagePath, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            $job->fill([
                'status' => 'failed',
                'error_message' => 'Gagal menyimpan file ZIP bulk upload.',
            ]);
            $job->save();

            throw new HttpException(500, 'Gagal menyimpan file ZIP bulk upload.');
        }

        $job->fill([
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
        ]);
        $job->save();

        $this->auditLog->record(
            'distribution_bulk_upload.created',
            'distribution_bulk_upload_job',
            $job->bulk_upload_job_id,
            'Bulk upload ZIP distribution dibuat.',
            [
                'distribution_id' => $distribution->distribution_id,
                'original_filename' => $job->original_filename,
                'file_size_bytes' => $job->file_size_bytes,
                'expires_at' => optional($job->expires_at)->toISOString(),
            ],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        ProcessDistributionBulkUploadZipJob::dispatch($job->bulk_upload_job_id)
            ->onQueue('default');

        return $job->fresh();
    }

    public function processPreview(int $jobId): DistributionBulkUploadJob
    {
        $updated = DistributionBulkUploadJob::where('bulk_upload_job_id', $jobId)
            ->whereIn('status', ['uploaded', 'failed'])
            ->update([
                'status' => 'processing',
                'error_message' => null,
                'summary' => null,
                'processed_at' => null,
                'updated_at' => now(),
            ]);

        $job = DistributionBulkUploadJob::with('distribution')->findOrFail($jobId);

        if ($updated !== 1) {
            return $job->fresh(['distribution', 'entries.recipient']);
        }

        if (! $job->storage_disk || ! $job->storage_path) {
            throw new HttpException(422, 'File ZIP bulk upload belum tersimpan.');
        }

        $storedEntryPaths = [];
        $tempDirectory = storage_path('app/arsip-digital/tmp/distribution-bulk-upload-'.$job->bulk_upload_job_id.'-'.Str::uuid());
        $zipPath = $tempDirectory.'/source.zip';

        try {
            $this->cleanupEntryFiles($job);
            DistributionBulkUploadEntry::where('bulk_upload_job_id', $job->bulk_upload_job_id)->delete();

            File::ensureDirectoryExists($tempDirectory);
            $this->copyStorageObjectToLocal($job->storage_disk, $job->storage_path, $zipPath);

            $recipients = DistributionRecipient::where('distribution_id', $job->distribution_id)
                ->with('file')
                ->orderBy('identifier')
                ->get();

            if ($recipients->isEmpty()) {
                throw new HttpException(422, 'Distribution belum memiliki recipient untuk dicocokkan.');
            }

            $recipientIndex = $this->buildRecipientIndex($recipients);
            $settings = $this->settings->getDefaults();
            $maxFileSizeMb = (int) $settings['default_max_file_size_mb'];
            $allowedExtensions = $settings['default_allowed_extensions'];
            $disk = $settings['storage_disk'];

            $zip = new ZipArchive;
            $zipOpen = false;

            if ($zip->open($zipPath) !== true) {
                throw new HttpException(422, 'File ZIP bulk upload tidak valid atau tidak dapat dibuka.');
            }

            $zipOpen = true;

            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                $zip->close();
                $zipOpen = false;

                throw new HttpException(422, 'Jumlah entry ZIP melebihi batas '.self::MAX_ZIP_ENTRIES.' file.');
            }

            $entryPayloads = [];
            $totalExtractedBytes = 0;
            $maxExtractedBytes = self::MAX_ZIP_SIZE_MB * 1024 * 1024;

            try {
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);

                    if ($stat === false) {
                        continue;
                    }

                    $entryPath = (string) ($stat['name'] ?? '');

                    if ($entryPath === '' || $this->isDirectoryEntry($entryPath) || $this->isSystemArtifactEntry($entryPath)) {
                        continue;
                    }

                    $fileSizeBytes = (int) ($stat['size'] ?? 0);
                    $totalExtractedBytes += $fileSizeBytes;

                    if ($totalExtractedBytes > $maxExtractedBytes) {
                        $zip->close();
                        $zipOpen = false;

                        throw new HttpException(422, 'Total ukuran file hasil ekstraksi ZIP melebihi batas '.self::MAX_ZIP_SIZE_MB.' MB.');
                    }

                    $entryPayloads[] = $this->previewZipEntry(
                        $job,
                        $zip,
                        $entryPath,
                        $fileSizeBytes,
                        $index,
                        $tempDirectory,
                        $disk,
                        $maxFileSizeMb,
                        $allowedExtensions,
                        $recipientIndex,
                        $storedEntryPaths
                    );
                }
            } finally {
                if ($zipOpen) {
                    $zip->close();
                }
            }

            $this->markDuplicateMatches($entryPayloads);
            $summary = $this->buildSummary($entryPayloads, $recipients->count());

            foreach ($entryPayloads as $payload) {
                DistributionBulkUploadEntry::create($payload);
            }

            $job->fill([
                'status' => 'preview_ready',
                'summary' => $summary,
                'error_message' => null,
                'processed_at' => now(),
            ]);
            $job->save();

            $this->auditLog->record(
                'distribution_bulk_upload.preview_ready',
                'distribution_bulk_upload_job',
                $job->bulk_upload_job_id,
                'Preview bulk upload ZIP distribution siap ditinjau.',
                [
                    'distribution_id' => $job->distribution_id,
                    'summary' => $summary,
                ],
                null,
                $job->uploaded_by_user_id,
                'admin'
            );

            return $job->fresh(['distribution', 'entries.recipient']);
        } catch (Throwable $e) {
            foreach ($storedEntryPaths as $storedEntry) {
                try {
                    Storage::disk($storedEntry['disk'])->delete($storedEntry['path']);
                } catch (Throwable) {
                    // Cleanup failure must not hide the original preview error.
                }
            }

            DistributionBulkUploadEntry::where('bulk_upload_job_id', $job->bulk_upload_job_id)->delete();

            $job->fill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
            $job->save();

            throw $e;
        } finally {
            File::deleteDirectory($tempDirectory);
        }
    }

    public function findForAdmin(int $jobId): DistributionBulkUploadJob
    {
        return DistributionBulkUploadJob::with(['distribution', 'entries.recipient'])->findOrFail($jobId);
    }

    public function confirm(int $jobId, object $actor, string $actorRole, $httpRequest = null): DistributionBulkUploadJob
    {
        $uploadedFiles = [];
        $tempDirectory = storage_path('app/arsip-digital/tmp/distribution-bulk-confirm-'.$jobId.'-'.Str::uuid());

        try {
            File::ensureDirectoryExists($tempDirectory);

            $job = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
                $jobId,
                $actor,
                $actorRole,
                $httpRequest,
                $tempDirectory,
                &$uploadedFiles
            ): DistributionBulkUploadJob {
                $job = DistributionBulkUploadJob::with('distribution')
                    ->where('bulk_upload_job_id', $jobId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($job->status !== 'preview_ready') {
                    throw new HttpException(422, 'Bulk upload ZIP hanya dapat dikonfirmasi saat status preview_ready.');
                }

                $distribution = Distribution::whereKey($job->distribution_id)->lockForUpdate()->firstOrFail();
                $job->setRelation('distribution', $distribution);
                if (! in_array($distribution->status, ['draft', 'published'], true)) {
                    throw new HttpException(422, 'File distribution hanya dapat dikonfirmasi saat distribution draft atau published.');
                }
                $matchedEntries = DistributionBulkUploadEntry::where('bulk_upload_job_id', $job->bulk_upload_job_id)
                    ->where('match_status', 'matched')
                    ->orderBy('bulk_upload_entry_id')
                    ->lockForUpdate()
                    ->get();

                if ($matchedEntries->isEmpty()) {
                    throw new HttpException(422, 'Tidak ada entry matched yang dapat dikonfirmasi.');
                }

                $job->fill(['status' => 'confirming']);
                $job->save();

                $confirmed = 0;
                $notificationRecipients = [];

                foreach ($matchedEntries as $entry) {
                    $recipient = DistributionRecipient::with(['distribution', 'file'])
                        ->where('recipient_id', $entry->recipient_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $recipient || $recipient->distribution_id !== $job->distribution_id) {
                        throw new HttpException(422, 'Recipient matched tidak ditemukan atau bukan bagian dari distribution job.');
                    }

                    if (! $recipient->distribution || ! in_array($recipient->distribution->status, ['draft', 'published'], true)) {
                        throw new HttpException(422, 'File distribution hanya dapat dikonfirmasi saat distribution draft atau published.');
                    }

                    if ($recipient->distribution->status === 'published' && $recipient->file_id && $recipient->download_count > 0) {
                        throw new HttpException(422, 'File distribution tidak dapat diganti setelah ada download.');
                    }

                    if (! $entry->temporary_disk || ! $entry->temporary_path) {
                        throw new HttpException(422, 'File sementara entry tidak tersedia.');
                    }

                    $storageMetadata = $this->uploadEntryToFinalStorage($entry, $recipient, $tempDirectory);
                    $uploadedFiles[] = [
                        'disk' => $storageMetadata['storage_disk'],
                        'path' => $storageMetadata['storage_path'],
                    ];

                    $version = $this->nextDistributionVersion($recipient, $entry->display_filename);
                    $previousFile = $recipient->file;

                    if ($previousFile) {
                        $previousFile->fill([
                            'is_current' => false,
                            'status' => 'replaced',
                        ]);
                        $previousFile->save();
                    }

                    $category = $this->categories->systemPersonalCategory(
                        $recipient->target_user_id,
                        $recipient->target_role,
                        $distribution->title,
                        $actor,
                        $actorRole
                    );

                    $file = ArchiveFile::create([
                        'category_id' => $category->category_id,
                        'owner_user_id' => $recipient->target_user_id,
                        'owner_role' => $recipient->target_role,
                        'owner_identifier' => $recipient->identifier,
                        'owner_name_snapshot' => $recipient->name_snapshot,
                        'owner_status_snapshot' => $recipient->status_snapshot,
                        'uploaded_by_user_id' => $actor->id,
                        'uploaded_by_role' => $actorRole,
                        'source_type' => 'distribution',
                        'original_filename' => $storageMetadata['original_filename'],
                        'display_filename' => $entry->display_filename,
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
                            'distribution_id' => $recipient->distribution_id,
                            'recipient_id' => $recipient->recipient_id,
                            'bulk_upload_job_id' => $job->bulk_upload_job_id,
                            'bulk_upload_entry_id' => $entry->bulk_upload_entry_id,
                        ],
                    ]);

                    $recipient->fill([
                        'file_id' => $file->file_id,
                        'delivery_status' => 'available',
                    ]);
                    $recipient->save();
                    $notificationRecipients[] = [
                        'target_user_id' => $recipient->target_user_id,
                        'target_role' => $recipient->target_role,
                    ];

                    $entry->fill([
                        'match_status' => 'confirmed',
                        'match_reason' => 'File matched berhasil dikonfirmasi menjadi file distribution.',
                    ]);
                    $entry->save();

                    $this->auditLog->record(
                        'distribution_file.bulk_uploaded',
                        'distribution_recipient',
                        $recipient->recipient_id,
                        'File distribution arsip digital dikonfirmasi dari bulk upload ZIP.',
                        [
                            'distribution_id' => $recipient->distribution_id,
                            'file_id' => $file->file_id,
                            'bulk_upload_job_id' => $job->bulk_upload_job_id,
                            'bulk_upload_entry_id' => $entry->bulk_upload_entry_id,
                        ],
                        $httpRequest,
                        $actor->id,
                        $actorRole
                    );

                    $confirmed++;
                }

                if ($job->distribution->status === 'published') {
                    $this->notifications->sendToManyUsers($notificationRecipients, [
                        'type' => 'distribution_file_available',
                        'title' => 'File distribution tersedia',
                        'message' => 'File untuk distribution '.$job->distribution->title.' sudah tersedia.',
                        'entity_type' => 'distribution',
                        'entity_id' => $job->distribution_id,
                        'data' => [
                            'distribution_id' => $job->distribution_id,
                            'bulk_upload_job_id' => $job->bulk_upload_job_id,
                        ],
                    ]);
                }

                $summary = $this->confirmedSummary($job->summary ?? [], $confirmed);

                $job->fill([
                    'status' => 'confirmed',
                    'summary' => $summary,
                    'error_message' => null,
                    'confirmed_at' => now(),
                ]);
                $job->save();

                $this->auditLog->record(
                    'distribution_bulk_upload.confirmed',
                    'distribution_bulk_upload_job',
                    $job->bulk_upload_job_id,
                    'Bulk upload ZIP distribution berhasil dikonfirmasi.',
                    [
                        'distribution_id' => $job->distribution_id,
                        'confirmed' => $confirmed,
                        'summary' => $summary,
                    ],
                    $httpRequest,
                    $actor->id,
                    $actorRole
                );

                return $job;
            });
        } catch (Throwable $e) {
            foreach ($uploadedFiles as $uploadedFile) {
                try {
                    Storage::disk($uploadedFile['disk'])->delete($uploadedFile['path']);
                } catch (Throwable) {
                    // Preserve the original confirm error.
                }
            }

            throw $e;
        } finally {
            File::deleteDirectory($tempDirectory);
        }

        $this->cleanupConfirmedJobTemporaryStorage($job);

        return $job->fresh(['distribution', 'entries.recipient']);
    }

    public function cancel(int $jobId, object $actor, string $actorRole, $httpRequest = null): DistributionBulkUploadJob
    {
        $job = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($jobId): DistributionBulkUploadJob {
            $job = DistributionBulkUploadJob::with('distribution')
                ->where('bulk_upload_job_id', $jobId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($job->status, ['confirmed', 'expired', 'cancelled'], true)) {
                throw new HttpException(422, 'Bulk upload ZIP dengan status '.$job->status.' tidak dapat dibatalkan.');
            }

            if (in_array($job->status, ['processing', 'confirming'], true)) {
                throw new HttpException(422, 'Bulk upload ZIP sedang diproses dan belum dapat dibatalkan. Tunggu proses selesai atau gagal terlebih dahulu.');
            }

            $job->fill([
                'status' => 'cancelled',
                'error_message' => null,
            ]);
            $job->save();

            return $job;
        });

        $this->cleanupJobTemporaryStorage($job);

        $this->auditLog->record(
            'distribution_bulk_upload.cancelled',
            'distribution_bulk_upload_job',
            $job->bulk_upload_job_id,
            'Bulk upload ZIP distribution dibatalkan.',
            ['distribution_id' => $job->distribution_id],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $job->fresh(['distribution', 'entries.recipient']);
    }

    public function fail(int $jobId, Throwable $e): void
    {
        $job = DistributionBulkUploadJob::find($jobId);

        if (! $job) {
            return;
        }

        if (in_array($job->status, ['confirmed', 'expired', 'cancelled'], true)) {
            return;
        }

        $job->fill([
            'status' => 'failed',
            'error_message' => $e->getMessage() ?: 'Proses bulk upload ZIP gagal.',
            'processed_at' => now(),
        ]);
        $job->save();
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        $expiresAt = now();

        DistributionBulkUploadJob::whereNotNull('expires_at')
            ->where('expires_at', '<=', $expiresAt)
            ->whereIn('status', ['uploaded', 'preview_ready', 'failed', 'cancelled'])
            ->orderBy('bulk_upload_job_id')
            ->chunkById(100, function ($jobs) use (&$count): void {
                foreach ($jobs as $job) {
                    $claimedJob = $this->claimExpiredJobForCleanup((int) $job->bulk_upload_job_id);

                    if (! $claimedJob) {
                        continue;
                    }

                    $this->cleanupJobTemporaryStorage($claimedJob);
                    $count++;
                }
            }, 'bulk_upload_job_id');

        return $count;
    }

    private function claimExpiredJobForCleanup(int $jobId): ?DistributionBulkUploadJob
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($jobId): ?DistributionBulkUploadJob {
            $job = DistributionBulkUploadJob::where('bulk_upload_job_id', $jobId)
                ->lockForUpdate()
                ->first();

            if (! $job || ! $job->expires_at || $job->expires_at->isFuture()) {
                return null;
            }

            if (! in_array($job->status, ['uploaded', 'preview_ready', 'failed', 'cancelled'], true)) {
                return null;
            }

            if ($job->status === 'cancelled') {
                $job->fill(['expires_at' => null]);
            } else {
                $job->fill([
                    'status' => 'expired',
                    'error_message' => $job->error_message,
                ]);
            }

            $job->save();

            return $job;
        });
    }

    private function validateZipUpload(UploadedFile $zipFile): void
    {
        if (strtolower($zipFile->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages([
                'zip_file' => 'File bulk upload harus berupa ZIP dengan ekstensi .zip.',
            ]);
        }

        if ($zipFile->getSize() > (self::MAX_ZIP_SIZE_MB * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'zip_file' => 'Ukuran ZIP melebihi batas '.self::MAX_ZIP_SIZE_MB.' MB.',
            ]);
        }

        if (! class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'PHP extension ZipArchive belum tersedia.');
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipFile->getRealPath());

        if ($opened !== true) {
            throw ValidationException::withMessages([
                'zip_file' => 'File ZIP tidak valid, rusak, atau tidak dapat dibuka. Pastikan file adalah arsip .zip yang valid.',
            ]);
        }

        $zip->close();
    }

    private function previewZipEntry(
        DistributionBulkUploadJob $job,
        ZipArchive $zip,
        string $entryPath,
        int $fileSizeBytes,
        int $index,
        string $tempDirectory,
        string $disk,
        int $maxFileSizeMb,
        array $allowedExtensions,
        array $recipientIndex,
        array &$storedEntryPaths
    ): array {
        $basename = basename(str_replace('\\', '/', $entryPath));
        $displayFilename = $this->storage->safeFilename($basename !== '' ? $basename : 'file');
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $filenameMetadata = $this->normalizeFilename($basename);

        $basePayload = [
            'bulk_upload_job_id' => $job->bulk_upload_job_id,
            'recipient_id' => null,
            'identifier' => null,
            'entry_path' => $entryPath,
            'original_filename' => $basename !== '' ? $basename : $entryPath,
            'display_filename' => $displayFilename,
            'temporary_disk' => null,
            'temporary_path' => null,
            'mime_type' => null,
            'extension' => $extension ?: null,
            'file_size_bytes' => $fileSizeBytes,
            'checksum_sha256' => null,
            'match_status' => 'invalid',
            'match_reason' => null,
            'metadata' => $filenameMetadata,
        ];

        if ($this->isUnsafeZipEntryPath($entryPath)) {
            return array_merge($basePayload, [
                'match_reason' => 'Path entry ZIP tidak aman.',
            ]);
        }

        try {
            $this->uploadValidation->validateMetadataFile($extension, $fileSizeBytes, $maxFileSizeMb, $allowedExtensions);
        } catch (ValidationException $e) {
            return array_merge($basePayload, [
                'match_reason' => $this->firstValidationMessage($e),
            ]);
        }

        $localEntryPath = $tempDirectory.'/entry-'.$index.'-'.Str::uuid();
        $entryStream = $zip->getStream($entryPath);

        if ($entryStream === false) {
            return array_merge($basePayload, [
                'match_reason' => 'Gagal membaca entry dari ZIP.',
            ]);
        }

        $target = fopen($localEntryPath, 'w');

        try {
            stream_copy_to_stream($entryStream, $target);
        } finally {
            if (is_resource($entryStream)) {
                fclose($entryStream);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }

        $mimeType = mime_content_type($localEntryPath) ?: null;

        try {
            $this->uploadValidation->validateMetadataFile($extension, $fileSizeBytes, $maxFileSizeMb, $allowedExtensions, $mimeType);
        } catch (ValidationException $e) {
            @unlink($localEntryPath);

            return array_merge($basePayload, [
                'mime_type' => $mimeType,
                'match_reason' => $this->firstValidationMessage($e),
            ]);
        }

        $checksum = hash_file('sha256', $localEntryPath);
        $temporaryPath = sprintf(
            'arsip-digital/%s/distribution-bulk-upload-jobs/%s/entries/%s/%s',
            app()->environment(),
            $job->bulk_upload_job_id,
            Str::uuid(),
            $displayFilename
        );

        $stream = fopen($localEntryPath, 'r');
        $stored = false;

        try {
            $stored = Storage::disk($disk)->put($temporaryPath, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($localEntryPath);
        }

        if ($stored === false) {
            return array_merge($basePayload, [
                'mime_type' => $mimeType,
                'checksum_sha256' => $checksum,
                'match_reason' => 'Gagal menyimpan file entry sementara.',
            ]);
        }

        $storedEntryPaths[] = ['disk' => $disk, 'path' => $temporaryPath];

        $match = $this->matchFilenameToRecipients($filenameMetadata, $recipientIndex);
        $metadata = array_merge($filenameMetadata, [
            'matched_recipient_ids' => $match['recipient_ids'],
            'matched_identifiers' => $match['identifiers'],
        ]);

        $payload = array_merge($basePayload, [
            'temporary_disk' => $disk,
            'temporary_path' => $temporaryPath,
            'mime_type' => $mimeType,
            'checksum_sha256' => $checksum,
            'metadata' => $metadata,
        ]);

        if (count($match['recipient_ids']) === 1) {
            $recipient = $recipientIndex['by_id'][$match['recipient_ids'][0]];

            if ($recipient->file_id) {
                $metadata['will_replace_existing_file_id'] = $recipient->file_id;
            }

            return array_merge($payload, [
                'recipient_id' => $recipient->recipient_id,
                'identifier' => $recipient->identifier,
                'match_status' => 'matched',
                'match_reason' => $match['reason'],
                'metadata' => $metadata,
            ]);
        }

        if (count($match['recipient_ids']) > 1) {
            return array_merge($payload, [
                'match_status' => 'ambiguous',
                'match_reason' => 'Nama file cocok dengan lebih dari satu recipient.',
                'metadata' => $metadata,
            ]);
        }

        return array_merge($payload, [
            'match_status' => 'unmatched',
            'match_reason' => 'Identifier recipient tidak ditemukan pada nama file.',
        ]);
    }

    private function buildRecipientIndex($recipients): array
    {
        $index = [
            'by_id' => [],
            'token_map' => [],
            'digit_map' => [],
            'compact_map' => [],
        ];

        foreach ($recipients as $recipient) {
            $identifier = (string) $recipient->identifier;
            $normalized = $this->normalizeIdentifier($identifier);
            $recipient->bulk_upload_normalized_identifier = $normalized;
            $index['by_id'][$recipient->recipient_id] = $recipient;

            $tokenKeys = array_values(array_unique(array_filter(array_merge(
                [$normalized['compact']],
                array_filter($normalized['digit_sequences'], fn (string $token): bool => strlen($token) >= 5)
            ))));

            foreach ($tokenKeys as $token) {
                $index['token_map'][$token][] = $recipient->recipient_id;
            }

            if ($normalized['compact'] !== '' && ctype_digit($normalized['compact'])) {
                $index['digit_map'][$normalized['compact']][] = $recipient->recipient_id;
            }

            if (strlen($normalized['compact']) >= 5) {
                $index['compact_map'][$normalized['compact']][] = $recipient->recipient_id;
            }
        }

        return $index;
    }

    private function matchFilenameToRecipients(array $filenameMetadata, array $recipientIndex): array
    {
        $tokenMatches = [];

        foreach ($filenameMetadata['normalized_tokens'] as $token) {
            if (isset($recipientIndex['token_map'][$token])) {
                foreach ($recipientIndex['token_map'][$token] as $recipientId) {
                    $tokenMatches[$recipientId] = true;
                }
            }
        }

        if (! empty($tokenMatches)) {
            return $this->matchResult(array_keys($tokenMatches), $recipientIndex, 'Identifier ditemukan sebagai token nama file.');
        }

        $digitMatches = [];

        foreach ($filenameMetadata['digit_sequences'] as $sequence) {
            if (isset($recipientIndex['digit_map'][$sequence])) {
                foreach ($recipientIndex['digit_map'][$sequence] as $recipientId) {
                    $digitMatches[$recipientId] = true;
                }
            }
        }

        if (! empty($digitMatches)) {
            return $this->matchResult(array_keys($digitMatches), $recipientIndex, 'Identifier numerik ditemukan sebagai digit sequence nama file.');
        }

        $compactMatches = [];
        $filenameCompact = $filenameMetadata['compact_filename'];

        if ($filenameCompact !== '') {
            foreach ($recipientIndex['compact_map'] as $identifierCompact => $recipientIds) {
                if (str_contains($filenameCompact, $identifierCompact)) {
                    foreach ($recipientIds as $recipientId) {
                        $compactMatches[$recipientId] = true;
                    }
                }
            }
        }

        if (! empty($compactMatches)) {
            return $this->matchResult(array_keys($compactMatches), $recipientIndex, 'Identifier ditemukan pada compact nama file.');
        }

        return [
            'recipient_ids' => [],
            'identifiers' => [],
            'reason' => null,
        ];
    }

    private function matchResult(array $recipientIds, array $recipientIndex, string $reason): array
    {
        sort($recipientIds);

        return [
            'recipient_ids' => array_values($recipientIds),
            'identifiers' => array_values(array_map(
                fn (int $recipientId): ?string => $recipientIndex['by_id'][$recipientId]->identifier,
                $recipientIds
            )),
            'reason' => $reason,
        ];
    }

    private function markDuplicateMatches(array &$entryPayloads): void
    {
        $matchedByRecipient = [];

        foreach ($entryPayloads as $index => $payload) {
            if ($payload['match_status'] === 'matched' && ! empty($payload['recipient_id'])) {
                $matchedByRecipient[$payload['recipient_id']][] = $index;
            }
        }

        foreach ($matchedByRecipient as $recipientId => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            foreach ($indexes as $index) {
                $metadata = $entryPayloads[$index]['metadata'] ?? [];
                $metadata['duplicate_recipient_id'] = (int) $recipientId;
                $entryPayloads[$index]['match_status'] = 'duplicate';
                $entryPayloads[$index]['match_reason'] = 'Lebih dari satu file cocok untuk recipient yang sama.';
                $entryPayloads[$index]['metadata'] = $metadata;
            }
        }
    }

    private function buildSummary(array $entryPayloads, int $totalRecipients): array
    {
        $summary = [
            'total_entries' => count($entryPayloads),
            'matched_entries' => 0,
            'matched_recipients' => 0,
            'missing_recipients' => 0,
            'unmatched_entries' => 0,
            'duplicate_entries' => 0,
            'duplicate_recipients' => 0,
            'ambiguous_entries' => 0,
            'invalid_entries' => 0,
            'will_replace_entries' => 0,
        ];

        $matchedRecipients = [];
        $duplicateRecipients = [];

        foreach ($entryPayloads as $payload) {
            match ($payload['match_status']) {
                'matched' => $summary['matched_entries']++,
                'unmatched' => $summary['unmatched_entries']++,
                'duplicate' => $summary['duplicate_entries']++,
                'ambiguous' => $summary['ambiguous_entries']++,
                'invalid' => $summary['invalid_entries']++,
                default => null,
            };

            if ($payload['match_status'] === 'matched' && ! empty($payload['recipient_id'])) {
                $matchedRecipients[$payload['recipient_id']] = true;

                if (! empty(($payload['metadata'] ?? [])['will_replace_existing_file_id'])) {
                    $summary['will_replace_entries']++;
                }
            }

            if ($payload['match_status'] === 'duplicate' && ! empty($payload['recipient_id'])) {
                $duplicateRecipients[$payload['recipient_id']] = true;
            }
        }

        $summary['matched_recipients'] = count($matchedRecipients);
        $summary['duplicate_recipients'] = count($duplicateRecipients);
        $summary['missing_recipients'] = max(0, $totalRecipients - $summary['matched_recipients'] - $summary['duplicate_recipients']);

        return $summary;
    }

    private function normalizeIdentifier(string $identifier): array
    {
        $normalized = Str::of($identifier)->ascii()->lower()->trim()->toString();
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compact = preg_replace('/[^a-z0-9]+/', '', $normalized) ?: '';
        preg_match_all('/\d+/', $normalized, $digitMatches);

        return [
            'normalized_identifier' => $normalized,
            'compact' => $compact,
            'tokens' => array_values(array_unique($tokens)),
            'digit_sequences' => array_values(array_unique($digitMatches[0] ?? [])),
        ];
    }

    private function normalizeFilename(string $filename): array
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $nameWithoutExtension = pathinfo($basename, PATHINFO_FILENAME);
        $normalized = Str::of($nameWithoutExtension)->ascii()->lower()->toString();
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compact = preg_replace('/[^a-z0-9]+/', '', $normalized) ?: '';
        preg_match_all('/\d+/', $normalized, $digitMatches);

        return [
            'normalized_tokens' => array_values(array_unique($tokens)),
            'compact_filename' => $compact,
            'digit_sequences' => array_values(array_unique($digitMatches[0] ?? [])),
        ];
    }

    private function isDirectoryEntry(string $entryPath): bool
    {
        return str_ends_with($entryPath, '/') || str_ends_with($entryPath, '\\');
    }

    private function isSystemArtifactEntry(string $entryPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $entryPath);
        $basename = basename($normalizedPath);

        return str_starts_with($normalizedPath, '__MACOSX/')
            || in_array($basename, ['.DS_Store', 'Thumbs.db'], true);
    }

    private function isUnsafeZipEntryPath(string $entryPath): bool
    {
        $normalizedPath = str_replace('\\', '/', $entryPath);

        if (str_starts_with($normalizedPath, '/') || preg_match('/^[A-Za-z]:/', $normalizedPath)) {
            return true;
        }

        $segments = explode('/', $normalizedPath);

        return in_array('..', $segments, true);
    }

    private function copyStorageObjectToLocal(string $disk, string $path, string $localPath): void
    {
        $source = Storage::disk($disk)->readStream($path);

        if ($source === false) {
            throw new HttpException(404, 'File ZIP bulk upload tidak ditemukan di storage.');
        }

        $target = fopen($localPath, 'w');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }
    }

    private function cleanupEntryFiles(DistributionBulkUploadJob $job): void
    {
        DistributionBulkUploadEntry::where('bulk_upload_job_id', $job->bulk_upload_job_id)
            ->whereNotNull('temporary_disk')
            ->whereNotNull('temporary_path')
            ->get()
            ->each(function (DistributionBulkUploadEntry $entry): void {
                try {
                    Storage::disk($entry->temporary_disk)->delete($entry->temporary_path);
                } catch (Throwable) {
                    // Best effort cleanup before reprocessing preview.
                }
            });
    }

    private function cleanupConfirmedJobTemporaryStorage(DistributionBulkUploadJob $job): void
    {
        $this->cleanupJobTemporaryStorage($job);
    }

    private function cleanupJobTemporaryStorage(DistributionBulkUploadJob $job): void
    {
        $files = [];

        if ($job->storage_disk && $job->storage_path) {
            $files[] = [
                'disk' => $job->storage_disk,
                'path' => $job->storage_path,
            ];
        }

        DistributionBulkUploadEntry::where('bulk_upload_job_id', $job->bulk_upload_job_id)
            ->whereNotNull('temporary_disk')
            ->whereNotNull('temporary_path')
            ->get()
            ->each(function (DistributionBulkUploadEntry $entry) use (&$files): void {
                $files[] = [
                    'disk' => $entry->temporary_disk,
                    'path' => $entry->temporary_path,
                ];
            });

        foreach ($files as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable) {
                // Best effort cleanup for temporary bulk upload files.
            }
        }
    }

    private function uploadEntryToFinalStorage(DistributionBulkUploadEntry $entry, DistributionRecipient $recipient, string $tempDirectory): array
    {
        $localPath = $tempDirectory.'/entry-'.$entry->bulk_upload_entry_id.'-'.Str::uuid();
        $this->copyStorageObjectToLocal($entry->temporary_disk, $entry->temporary_path, $localPath);

        $disk = $this->settings->getDefaults()['storage_disk'];
        $uuid = (string) Str::uuid();
        $safeFilename = $this->storage->safeFilename($entry->display_filename);
        $storagePath = trim(sprintf(
            'arsip-digital/%s/distributions/%s/%s/%s/%s_%s',
            app()->environment(),
            $recipient->distribution_id,
            $recipient->recipient_id,
            $uuid,
            $uuid,
            $safeFilename
        ), '/');

        $stream = fopen($localPath, 'r');
        $stored = false;

        try {
            $stored = Storage::disk($disk)->put($storagePath, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($localPath);
        }

        if ($stored === false) {
            throw new HttpException(500, 'Gagal menyimpan file distribution hasil bulk upload.');
        }

        return [
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'original_filename' => $entry->original_filename,
            'display_filename' => $safeFilename,
            'mime_type' => $entry->mime_type,
            'extension' => strtolower((string) $entry->extension),
            'file_size_bytes' => $entry->file_size_bytes,
            'checksum_sha256' => $entry->checksum_sha256,
        ];
    }

    private function nextDistributionVersion(DistributionRecipient $recipient, string $displayFilename): array
    {
        $latest = ArchiveFile::withTrashed()
            ->where('source_type', 'distribution')
            ->where('display_filename', $displayFilename)
            ->where('metadata->recipient_id', $recipient->recipient_id)
            ->orderByDesc('version_number')
            ->first();

        if (! $latest) {
            return ['version_group_uuid' => (string) Str::uuid(), 'version_number' => 1];
        }

        return [
            'version_group_uuid' => $latest->version_group_uuid,
            'version_number' => $latest->version_number + 1,
        ];
    }

    private function confirmedSummary(array $previewSummary, int $confirmed): array
    {
        $totalEntries = (int) ($previewSummary['total_entries'] ?? $confirmed);

        return array_merge($previewSummary, [
            'confirmed' => $confirmed,
            'skipped' => max(0, $totalEntries - $confirmed),
            'failed' => 0,
        ]);
    }

    private function firstValidationMessage(ValidationException $e): string
    {
        $messages = $e->validator->errors()->all();

        return $messages[0] ?? $e->getMessage();
    }
}
