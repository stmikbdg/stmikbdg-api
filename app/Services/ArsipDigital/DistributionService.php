<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DistributionService
{
    public function __construct(
        private readonly RequestTargetPreviewService $targetPreview,
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArsipDigitalStorageService $storage,
        private readonly ArchiveUploadValidationService $uploadValidation,
        private readonly AuditLogService $auditLog,
        private readonly NotificationService $notifications,
        private readonly ArchiveCategoryService $categories,
    ) {}

    public function adminQuery(array $filters = []): Builder
    {
        $query = Distribution::query();

        if (! empty($filters['with_deleted'])) {
            $query->withTrashed();
        }

        foreach (['target_role', 'scope_type', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower($filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$search]);
            });
        }

        return $query->orderByDesc('created_at')->orderByDesc('distribution_id');
    }

    public function userQuery(object $user, string $role): Builder
    {
        return Distribution::where('status', 'published')
            ->whereHas('recipients', function (Builder $query) use ($user, $role): void {
                $query->where('target_user_id', $user->id)
                    ->where('target_role', $role)
                    ->whereIn('delivery_status', ['available', 'downloaded'])
                    ->whereNotNull('file_id');
            })
            ->with(['recipients' => function ($query) use ($user, $role): void {
                $query->where('target_user_id', $user->id)
                    ->where('target_role', $role)
                    ->whereIn('delivery_status', ['available', 'downloaded'])
                    ->whereNotNull('file_id')
                    ->with('file');
            }])
            ->orderByDesc('published_at')
            ->orderByDesc('distribution_id');
    }

    public function create(array $payload, object $actor, string $actorRole, $httpRequest = null): Distribution
    {
        $distribution = Distribution::create([
            'title' => $payload['title'],
            'description' => $payload['description'] ?? null,
            'target_role' => $payload['target_role'],
            'scope_type' => $payload['scope_type'],
            'target_filters' => $payload['target_filters'] ?? null,
            'target_identifiers' => $payload['target_identifiers'] ?? null,
            'target_segment_ids' => $payload['target_segment_ids'] ?? null,
            'status' => 'draft',
            'created_by_user_id' => $actor->id,
        ]);

        $this->auditLog->record(
            'distribution.created',
            'distribution',
            $distribution->distribution_id,
            'Distribution arsip digital dibuat.',
            [],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $distribution;
    }

    public function update(Distribution $distribution, array $payload): Distribution
    {
        if ($distribution->status !== 'draft') {
            throw new HttpException(422, 'Distribution hanya dapat diubah saat status draft.');
        }

        $distribution->fill([
            'title' => $payload['title'] ?? $distribution->title,
            'description' => array_key_exists('description', $payload) ? $payload['description'] : $distribution->description,
            'target_role' => $payload['target_role'] ?? $distribution->target_role,
            'scope_type' => $payload['scope_type'] ?? $distribution->scope_type,
            'target_filters' => array_key_exists('target_filters', $payload) ? $payload['target_filters'] : $distribution->target_filters,
            'target_identifiers' => array_key_exists('target_identifiers', $payload) ? $payload['target_identifiers'] : $distribution->target_identifiers,
            'target_segment_ids' => array_key_exists('target_segment_ids', $payload) ? $payload['target_segment_ids'] : $distribution->target_segment_ids,
        ]);
        $distribution->save();

        return $distribution;
    }

    public function delete(Distribution $distribution): void
    {
        if ($distribution->status !== 'draft') {
            throw new HttpException(422, 'Distribution hanya dapat dihapus saat status draft.');
        }

        $distribution->delete();
    }

    public function previewForPayload(array $payload): array
    {
        return $this->targetPreview->preview($payload, 1000, 100);
    }

    public function previewForDistribution(Distribution $distribution): array
    {
        return $this->targetPreview->preview([
            'target_role' => $distribution->target_role,
            'scope_type' => $distribution->scope_type,
            'target_filters' => $distribution->target_filters ?? [],
            'target_identifiers' => $distribution->target_identifiers ?? [],
            'target_segment_ids' => $distribution->target_segment_ids ?? [],
        ], 1000);
    }

    public function publish(Distribution $distribution, object $actor, string $actorRole, $httpRequest = null): Distribution
    {
        $preview = $distribution->original_distribution_id ? null : $this->previewForDistribution($distribution);

        if ($preview && $preview['total_invalid'] > 0) {
            throw new HttpException(422, 'Distribution tidak dapat dipublish karena masih memiliki target invalid.');
        }

        if ($preview && $preview['total_valid'] < 1) {
            throw new HttpException(422, 'Distribution tidak dapat dipublish tanpa target valid.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $preview, $actor, $actorRole, $httpRequest): Distribution {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();

            if ($distribution->status !== 'draft') {
                throw new HttpException(422, 'Hanya distribution draft yang dapat dipublish.');
            }

            $duplicateRecipients = DistributionRecipient::where('distribution_id', $distribution->distribution_id)
                ->orderBy('recipient_id')
                ->get()
                ->groupBy(fn ($recipient) => $recipient->target_role.'|'.$recipient->identifier)
                ->flatMap(fn ($recipients) => $recipients->slice(1));
            foreach ($duplicateRecipients as $duplicateRecipient) {
                $duplicateRecipient->delete();
            }

            $distribution->fill([
                'status' => 'published',
                'published_at' => now(),
            ]);
            $distribution->save();

            $now = now();
            if ($preview) {
                collect($preview['valid_targets'])->unique(fn (array $target) => $target['target_role'].'|'.$target['identifier'])->map(fn (array $target): array => [
                    'distribution_id' => $distribution->distribution_id,
                    'target_user_id' => $target['target_user_id'],
                    'target_role' => $target['target_role'],
                    'identifier' => $target['identifier'],
                    'name_snapshot' => $target['name_snapshot'],
                    'angkatan_snapshot' => $target['angkatan_snapshot'],
                    'prodi_snapshot' => $target['prodi_snapshot'],
                    'status_snapshot' => $target['status_snapshot'],
                    'metadata' => json_encode([
                        'scope_type' => $distribution->scope_type,
                        'scholarship_snapshot' => $target['scholarship_snapshot'] ?? null,
                        'target_metadata' => $target['metadata'] ?? null,
                    ]),
                    'delivery_status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->chunk(50)->each(fn ($recipients) => DistributionRecipient::insertOrIgnore($recipients->all()));
            }

            $recipientCount = DistributionRecipient::where('distribution_id', $distribution->distribution_id)->count();
            if ($recipientCount < 1) {
                throw new HttpException(422, 'Distribution tidak dapat dipublish tanpa target valid.');
            }

            $this->auditLog->record(
                'distribution.published',
                'distribution',
                $distribution->distribution_id,
                'Distribution arsip digital dipublish.',
                ['total_recipients' => $recipientCount],
                $httpRequest,
                $actor->id,
                $actorRole
            );

            return $distribution->fresh()->loadCount('recipients');
        });
    }

    public function withdraw(Distribution $distribution, string $reason, object $actor, string $actorRole, $httpRequest = null): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $reason, $actor, $actorRole, $httpRequest): Distribution {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();
            if ($distribution->status !== 'published') {
                throw new HttpException(422, 'Hanya distribution published yang dapat ditarik.');
            }

            $distribution->update([
                'status' => 'closed',
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $actor->id,
                'withdrawal_reason' => $reason,
            ]);
            $recipients = DistributionRecipient::where('distribution_id', $distribution->distribution_id)
                ->orderBy('recipient_id')
                ->lockForUpdate()
                ->get();
            DistributionRecipient::whereIn('recipient_id', $recipients->pluck('recipient_id'))->update([
                'delivery_status' => 'revoked',
                'updated_at' => now(),
            ]);
            ArchiveFile::whereIn('file_id', $recipients->pluck('file_id')->filter()->unique())->update([
                'status' => 'revoked',
                'is_current' => false,
                'updated_at' => now(),
            ]);
            $this->notifications->sendToManyUsers($recipients->map(fn ($recipient) => [
                'target_user_id' => $recipient->target_user_id,
                'target_role' => $recipient->target_role,
            ])->all(), [
                'type' => 'distribution_withdrawn',
                'title' => 'Distribution ditarik',
                'message' => $distribution->title.' telah ditarik: '.$reason,
                'entity_type' => 'distribution',
                'entity_id' => $distribution->distribution_id,
                'data' => ['distribution_id' => $distribution->distribution_id, 'reason' => $reason],
            ]);
            $this->auditLog->record('distribution.withdrawn', 'distribution', $distribution->distribution_id, 'Distribution arsip digital ditarik.', ['reason' => $reason], $httpRequest, $actor->id, $actorRole);

            return $distribution->fresh();
        });
    }

    public function createCorrection(Distribution $original, object $actor, string $actorRole, $httpRequest = null): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($original, $actor, $actorRole, $httpRequest): Distribution {
            $original = Distribution::whereKey($original->distribution_id)->lockForUpdate()->firstOrFail();
            if (! in_array($original->status, ['published', 'closed'], true)) {
                throw new HttpException(422, 'Correction hanya dapat dibuat dari distribution published atau closed.');
            }

            $correction = Distribution::create([
                'original_distribution_id' => $original->distribution_id,
                'title' => $original->title,
                'description' => $original->description,
                'target_role' => $original->target_role,
                'scope_type' => $original->scope_type,
                'target_filters' => $original->target_filters,
                'target_identifiers' => $original->target_identifiers,
                'target_segment_ids' => $original->target_segment_ids,
                'status' => 'draft',
                'created_by_user_id' => $actor->id,
            ]);
            $now = now();
            DistributionRecipient::where('distribution_id', $original->distribution_id)->orderBy('recipient_id')->get()->unique(fn ($recipient) => $recipient->target_role.'|'.$recipient->identifier)->each(function ($recipient) use ($correction, $now): void {
                DistributionRecipient::create([
                    'distribution_id' => $correction->distribution_id,
                    'target_user_id' => $recipient->target_user_id,
                    'target_role' => $recipient->target_role,
                    'identifier' => $recipient->identifier,
                    'name_snapshot' => $recipient->name_snapshot,
                    'angkatan_snapshot' => $recipient->angkatan_snapshot,
                    'prodi_snapshot' => $recipient->prodi_snapshot,
                    'status_snapshot' => $recipient->status_snapshot,
                    'metadata' => $recipient->metadata,
                    'delivery_status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
            $this->auditLog->record('distribution.correction_created', 'distribution', $correction->distribution_id, 'Correction distribution arsip digital dibuat.', ['original_distribution_id' => $original->distribution_id], $httpRequest, $actor->id, $actorRole);

            return $correction->loadCount('recipients');
        });
    }

    public function recipientQuery(int $distributionId, array $filters = []): Builder
    {
        $query = DistributionRecipient::where('distribution_id', $distributionId)
            ->with('file');

        if (! empty($filters['delivery_status'])) {
            $query->where('delivery_status', $filters['delivery_status']);
        }

        if (! empty($filters['target_role'])) {
            $query->where('target_role', $filters['target_role']);
        }

        if (! empty($filters['identifier'])) {
            $query->where('identifier', $filters['identifier']);
        }

        if (! empty($filters['angkatan'])) {
            $query->where('angkatan_snapshot', $filters['angkatan']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower($filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(identifier) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(name_snapshot) LIKE ?', [$search]);
            });
        }

        return $query->orderBy('identifier');
    }

    public function uploadRecipientFile(DistributionRecipient $recipient, UploadedFile $uploadedFile, array $payload, object $actor, string $actorRole, $httpRequest = null): DistributionRecipient
    {
        $recipient->loadMissing('distribution', 'file');

        if (! $recipient->distribution || ! in_array($recipient->distribution->status, ['draft', 'published'], true)) {
            throw new HttpException(422, 'File distribution hanya dapat diupload saat distribution draft atau published.');
        }

        $settings = $this->settings->getDefaults();
        $this->uploadValidation->validateUploadedFile(
            $uploadedFile,
            (int) $settings['default_max_file_size_mb'],
            $settings['default_allowed_extensions']
        );

        $displayFilename = $this->storage->safeFilename($payload['display_filename'] ?? $uploadedFile->getClientOriginalName());
        $storageMetadata = $this->storage->uploadPrivate($uploadedFile, 'distribution', [
            'distribution_id' => $recipient->distribution_id,
            'recipient_id' => $recipient->recipient_id,
            'owner_user_id' => $recipient->target_user_id,
        ]);

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
                $recipient,
                $actor,
                $actorRole,
                $storageMetadata,
                $displayFilename,
                $payload,
                $httpRequest
            ): DistributionRecipient {
                $distribution = Distribution::whereKey($recipient->distribution_id)->lockForUpdate()->firstOrFail();
                $recipient = DistributionRecipient::with('file')->whereKey($recipient->recipient_id)->lockForUpdate()->firstOrFail();
                if (! in_array($distribution->status, ['draft', 'published'], true)) {
                    throw new HttpException(422, 'File distribution hanya dapat diupload saat distribution draft atau published.');
                }
                if ($distribution->status === 'published' && $recipient->file_id && $recipient->download_count > 0) {
                    throw new HttpException(422, 'File distribution tidak dapat diganti setelah ada download.');
                }
                $recipient->setRelation('distribution', $distribution);
                $version = $this->nextDistributionVersion($recipient, $displayFilename);
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
                        'distribution_id' => $recipient->distribution_id,
                        'recipient_id' => $recipient->recipient_id,
                        'note' => $payload['note'] ?? null,
                    ],
                ]);

                $recipient->fill([
                    'file_id' => $file->file_id,
                    'delivery_status' => 'available',
                ]);
                $recipient->save();

                if ($recipient->distribution->status === 'published') {
                    $this->notifications->sendToUser(
                        (int) $recipient->target_user_id,
                        $recipient->target_role,
                        'distribution_file_available',
                        'File distribution tersedia',
                        'File untuk distribution '.$recipient->distribution->title.' sudah tersedia.',
                        'distribution_recipient',
                        $recipient->recipient_id,
                        [
                            'distribution_id' => $recipient->distribution_id,
                            'recipient_id' => $recipient->recipient_id,
                            'file_id' => $file->file_id,
                        ]
                    );
                }

                $this->auditLog->record(
                    'distribution_file.uploaded',
                    'distribution_recipient',
                    $recipient->recipient_id,
                    'File distribution arsip digital diupload untuk recipient.',
                    ['distribution_id' => $recipient->distribution_id, 'file_id' => $file->file_id],
                    $httpRequest,
                    $actor->id,
                    $actorRole
                );

                return $recipient->fresh(['distribution', 'file']);
            });
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($storageMetadata['storage_disk'], $storageMetadata['storage_path']);
            } catch (\Throwable) {
                // Preserve the original database exception.
            }

            throw $e;
        }
    }

    public function findDownloadableRecipientByFile(int $fileId, object $user, string $role): DistributionRecipient
    {
        $recipient = DistributionRecipient::where('file_id', $fileId)
            ->where('target_user_id', $user->id)
            ->where('target_role', $role)
            ->with(['distribution', 'file'])
            ->first();

        if ($recipient?->distribution?->status === 'closed') {
            throw new HttpException(410, 'Distribution sudah ditarik.');
        }
        if (! $recipient) {
            throw new HttpException(404, 'File distribution belum tersedia.');
        }

        $this->assertRecipientVisibleToUser($recipient, $user, $role);

        return $recipient;
    }

    public function assertRecipientVisibleToUser(DistributionRecipient $recipient, object $user, string $role): void
    {
        if ($recipient->target_user_id !== $user->id || $recipient->target_role !== $role) {
            throw new HttpException(403, 'Tidak memiliki akses ke file distribution ini.');
        }

        if (! in_array($recipient->delivery_status, ['available', 'downloaded'], true) || ! $recipient->file_id) {
            throw new HttpException(404, 'File distribution belum tersedia.');
        }
    }

    public function markDownloaded(DistributionRecipient $recipient, object $actor, string $actorRole, $httpRequest = null): DistributionRecipient
    {
        $recipient = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($recipient): DistributionRecipient {
            $distribution = Distribution::whereKey($recipient->distribution_id)->lockForUpdate()->firstOrFail();
            $recipient = DistributionRecipient::with('file')->whereKey($recipient->recipient_id)->lockForUpdate()->firstOrFail();
            $recipient->setRelation('distribution', $distribution);
            if ($distribution->status === 'closed') {
                throw new HttpException(410, 'Distribution sudah ditarik.');
            }
            if ($distribution->status !== 'published') {
                throw new HttpException(404, 'File distribution belum tersedia.');
            }
            $now = now();
            $recipient->increment('download_count');
            $recipient->update([
                'delivery_status' => 'downloaded',
                'first_downloaded_at' => $recipient->first_downloaded_at ?: $now,
                'last_downloaded_at' => $now,
            ]);

            return $recipient;
        });

        $this->auditLog->record(
            'distribution_file.downloaded',
            'distribution_recipient',
            $recipient->recipient_id,
            'User download file distribution arsip digital.',
            ['distribution_id' => $recipient->distribution_id, 'file_id' => $recipient->file_id],
            $httpRequest,
            $actor->id,
            $actorRole
        );

        return $recipient->fresh(['distribution', 'file']);
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
}
