<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Models\ArsipDigital\InstitutionalArchive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstitutionalDistributionService
{
    public function __construct(
        private readonly RequestTargetPreviewService $targets,
        private readonly InstitutionalArchiveVerificationService $verifications,
    ) {}

    public function preview(array $payload): array
    {
        return $this->targets->preview($payload, 1000, 100);
    }

    private function resolveAll(Distribution $distribution): array
    {
        return $this->targets->preview([
            'target_role' => $distribution->target_role,
            'scope_type' => $distribution->scope_type,
            'target_filters' => $distribution->target_filters ?? [],
            'target_identifiers' => $distribution->target_identifiers ?? [],
            'target_segment_ids' => $distribution->target_segment_ids ?? [],
        ], 1000, null);
    }

    public function draftTargets(Distribution $distribution): array
    {
        $this->assertDraft($distribution);
        $preview = $this->resolveAll($distribution);

        return [
            'criteria' => $this->criteria($distribution),
            'targets' => collect($preview['valid_targets'])->map(fn (array $target): array => collect($target)->only(['identifier', 'name_snapshot', 'angkatan_snapshot', 'prodi_snapshot', 'status_snapshot', 'has_account', 'reason'])->all())->values()->all(),
            'total_valid' => $preview['total_valid'],
            'total_invalid' => $preview['total_invalid'],
            'target_fingerprint' => $this->targetFingerprint($preview),
            'updated_at' => $this->timestamp($distribution),
        ];
    }

    public function update(Distribution $distribution, array $payload, object $actor, string $role, Request $request): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $payload, $actor, $role, $request): Distribution {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($distribution);
            $archive = InstitutionalArchive::withTrashed()->whereKey($distribution->institutional_archive_id)->lockForUpdate()->firstOrFail();
            $this->assertActiveArchive($archive);
            $this->assertCurrent($distribution, $payload['expected_updated_at']);
            $preview = $this->targets->preview($payload, 1000, null);
            $this->assertValidTargets($preview);
            $distribution->update([
                'title' => $payload['title'], 'description' => $payload['description'] ?? null, 'expires_at' => $payload['expires_at'] ?? null,
                'target_role' => $payload['target_role'], 'scope_type' => $payload['scope_type'], 'target_filters' => $payload['target_filters'] ?? null,
                'target_identifiers' => $payload['target_identifiers'] ?? null, 'target_segment_ids' => $payload['target_segment_ids'] ?? null,
                'target_count' => $preview['total_valid'],
            ]);
            $this->audit($request, $actor, $role, 'institutional_distribution.updated', $distribution, []);

            return $distribution->fresh()->load(['institutionalArchive.currentFile'])->loadCount('recipients');
        });
    }

    public function cancel(Distribution $distribution, string $expectedUpdatedAt, ?string $reason, object $actor, string $role, Request $request): void
    {
        DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $expectedUpdatedAt, $reason, $actor, $role, $request): void {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($distribution);
            $archive = InstitutionalArchive::withTrashed()->whereKey($distribution->institutional_archive_id)->lockForUpdate()->firstOrFail();
            $this->assertActiveArchive($archive);
            $this->assertCurrent($distribution, $expectedUpdatedAt);
            if ($distribution->recipients()->exists()) {
                throw new HttpException(409, 'Draft memiliki penerima dan tidak dapat dibatalkan.');
            }
            $this->audit($request, $actor, $role, 'institutional_distribution.cancelled', $distribution, ['reason' => $reason]);
            $distribution->delete();
        });
    }

    public function create(InstitutionalArchive $archive, array $payload, object $actor, string $role, Request $request): Distribution
    {
        $this->assertActiveArchive($archive);

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($archive, $payload, $actor, $role, $request): Distribution {
            $archive = InstitutionalArchive::whereKey($archive->institutional_archive_id)->lockForUpdate()->firstOrFail();
            $this->assertActiveArchive($archive);
            $preview = $this->targets->preview($payload, 1000, null);
            $this->assertValidTargets($preview);
            $distribution = Distribution::create([
                'institutional_archive_id' => $archive->institutional_archive_id,
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'target_role' => $payload['target_role'],
                'scope_type' => $payload['scope_type'],
                'target_filters' => $payload['target_filters'] ?? null,
                'target_identifiers' => $payload['target_identifiers'] ?? null,
                'target_segment_ids' => $payload['target_segment_ids'] ?? null,
                'target_count' => $preview['total_valid'],
                'expires_at' => $payload['expires_at'] ?? null,
                'status' => 'draft',
                'created_by_user_id' => $actor->id,
            ]);
            $this->audit($request, $actor, $role, 'institutional_distribution.created', $distribution, []);

            return $distribution;
        });
    }

    public function publish(Distribution $distribution, int $expectedSourceFileId, object $actor, string $role, Request $request, ?string $expectedUpdatedAt = null, ?string $expectedTargetFingerprint = null): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $expectedSourceFileId, $actor, $role, $request, $expectedUpdatedAt, $expectedTargetFingerprint): Distribution {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();
            if ($distribution->status === 'published') {
                if ($distribution->source_file_id !== $expectedSourceFileId) {
                    throw new HttpException(409, 'Versi sumber tidak cocok dengan distribusi yang sudah dipublish.');
                }

                return $distribution->loadCount('recipients');
            }
            if ($distribution->status !== 'draft' || ! $distribution->institutional_archive_id) {
                throw new HttpException(409, 'Hanya distribusi arsip lembaga draft yang dapat dipublish.');
            }
            if ($expectedUpdatedAt === null || $expectedTargetFingerprint === null) {
                throw new HttpException(422, 'Token draft dan fingerprint target wajib diisi.');
            }
            $this->assertCurrent($distribution, $expectedUpdatedAt);
            $archive = InstitutionalArchive::whereKey($distribution->institutional_archive_id)->lockForUpdate()->first();
            $this->assertActiveArchive($archive);
            if ($archive->current_file_id !== $expectedSourceFileId) {
                throw new HttpException(409, 'Versi sumber berubah. Muat ulang distribusi sebelum publish.');
            }
            if ($distribution->expires_at && now()->greaterThanOrEqualTo($distribution->expires_at)) {
                throw new HttpException(422, 'Batas waktu harus setelah waktu sekarang.');
            }
            $preview = $this->resolveAll($distribution);
            $this->assertValidTargets($preview);
            if ($expectedTargetFingerprint !== null && ! hash_equals($this->targetFingerprint($preview), $expectedTargetFingerprint)) {
                throw new HttpException(409, 'Populasi target berubah. Muat ulang target dan konfirmasi publish kembali.');
            }
            $expectedTargets = collect($preview['valid_targets'])->unique(fn ($target) => $target['target_role'].'|'.$target['identifier'])->map(fn ($target): string => implode('|', [$target['target_user_id'], $target['target_role'], $target['identifier']]))->sort()->values()->all();
            $fileId = $archive->current_file_id;
            $this->verifications->readyForSource($fileId);
            $now = now();
            foreach (collect($preview['valid_targets'])->unique(fn ($target) => $target['target_role'].'|'.$target['identifier']) as $target) {
                DistributionRecipient::firstOrCreate([
                    'distribution_id' => $distribution->distribution_id, 'target_role' => $target['target_role'], 'identifier' => $target['identifier'],
                ], [
                    'target_user_id' => $target['target_user_id'], 'name_snapshot' => $target['name_snapshot'],
                    'angkatan_snapshot' => $target['angkatan_snapshot'], 'prodi_snapshot' => $target['prodi_snapshot'],
                    'status_snapshot' => $target['status_snapshot'], 'file_id' => $fileId, 'delivery_status' => 'available',
                ]);
            }
            $recipients = DistributionRecipient::where('distribution_id', $distribution->distribution_id)->orderBy('recipient_id')->get();
            $actualTargets = $recipients->map(fn ($recipient): string => implode('|', [$recipient->target_user_id, $recipient->target_role, $recipient->identifier]))->sort()->values()->all();
            if ($actualTargets !== $expectedTargets) {
                throw new HttpException(409, 'Target berubah saat publish. Preview ulang sebelum publish.');
            }
            $distribution->update(['source_file_id' => $fileId, 'status' => 'published', 'published_at' => $now]);
            foreach ($recipients->chunk(1000) as $chunk) {
                $now = now();
                DB::connection(config('myconfig.database.first_connection'))->table('arsip_digital.notifications')->insert($chunk->map(fn ($recipient): array => [
                    'recipient_user_id' => $recipient->target_user_id,
                    'recipient_role' => $recipient->target_role,
                    'type' => 'institutional_distribution_available',
                    'title' => 'Arsip lembaga tersedia',
                    'message' => $distribution->title.' sudah tersedia.',
                    'entity_type' => 'distribution_recipient',
                    'entity_id' => $recipient->recipient_id,
                    'data' => json_encode(['distribution_id' => $distribution->distribution_id, 'recipient_id' => $recipient->recipient_id]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }
            $this->audit($request, $actor, $role, 'institutional_distribution.published', $distribution, ['source_file_id' => $fileId, 'total_recipients' => $recipients->count()]);

            return $distribution->fresh()->loadCount('recipients');
        }, 3);
    }

    public function withdraw(Distribution $distribution, string $reason, object $actor, string $role, Request $request): Distribution
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($distribution, $reason, $actor, $role, $request): Distribution {
            $distribution = Distribution::whereKey($distribution->distribution_id)->lockForUpdate()->firstOrFail();
            if (! $distribution->institutional_archive_id) {
                throw new HttpException(409, 'Hanya distribusi arsip lembaga published yang dapat ditarik.');
            }
            $archive = InstitutionalArchive::withTrashed()->whereKey($distribution->institutional_archive_id)->lockForUpdate()->firstOrFail();
            $this->assertActiveArchive($archive);
            if ($distribution->status === 'closed') {
                return $distribution;
            }
            if ($distribution->status !== 'published') {
                throw new HttpException(409, 'Hanya distribusi arsip lembaga published yang dapat ditarik.');
            }
            $distribution->update(['status' => 'closed', 'withdrawn_at' => now(), 'withdrawn_by_user_id' => $actor->id, 'withdrawal_reason' => $reason]);
            DistributionRecipient::where('distribution_id', $distribution->distribution_id)->update(['delivery_status' => 'revoked', 'updated_at' => now()]);
            $this->audit($request, $actor, $role, 'institutional_distribution.withdrawn', $distribution, ['reason' => $reason]);

            return $distribution->fresh();
        });
    }

    public function distributionDto(Distribution $distribution): array
    {
        $file = $distribution->status === 'draft'
            ? $distribution->institutionalArchive?->currentFile
            : $distribution->sourceFile;

        return collect($distribution->only(['distribution_id', 'institutional_archive_id', 'source_file_id', 'title', 'description', 'target_role', 'scope_type', 'expires_at', 'status', 'published_at', 'withdrawn_at', 'withdrawal_reason', 'created_at', 'updated_at']))
            ->merge([
                'effective_status' => $distribution->status === 'published' && $distribution->expires_at?->isPast() ? 'expired' : $distribution->status,
                'editable' => $distribution->status === 'draft',
                'source_file_id' => $file?->file_id,
                'source_file' => $file ? $file->only(['file_id', 'version_number', 'display_filename']) : null,
                'target_count' => $distribution->target_count,
                'recipients_count' => $distribution->recipients_count ?? $distribution->recipients()->count(),
            ])->all();
    }

    public function recipientDto(DistributionRecipient $recipient): array
    {
        $identifier = (string) $recipient->identifier;

        return $recipient->only(['recipient_id', 'target_role', 'name_snapshot', 'delivery_status', 'download_count', 'first_downloaded_at', 'last_downloaded_at', 'created_at']) + [
            'identifier' => strlen($identifier) <= 4 ? str_repeat('*', strlen($identifier)) : substr($identifier, 0, 2).str_repeat('*', strlen($identifier) - 4).substr($identifier, -2),
        ];
    }

    private function criteria(Distribution $distribution): array
    {
        return collect($distribution->only(['target_role', 'scope_type', 'target_filters', 'target_identifiers', 'target_segment_ids']))->all();
    }

    private function targetFingerprint(array $preview): string
    {
        $targets = collect($preview['valid_targets'])->map(fn (array $target): string => implode('|', [$target['target_user_id'], $target['target_role'], $target['identifier']]))->sort()->values()->all();

        return hash('sha256', json_encode($targets, JSON_UNESCAPED_SLASHES));
    }

    private function timestamp(Distribution $distribution): string
    {
        return $distribution->updated_at->utc()->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function assertCurrent(Distribution $distribution, string $expected): void
    {
        if ($this->timestamp($distribution) !== now()->parse($expected)->utc()->format('Y-m-d\\TH:i:s.u\\Z')) {
            throw new HttpException(409, 'Draft berubah. Muat ulang sebelum melanjutkan.');
        }
    }

    private function assertDraft(Distribution $distribution): void
    {
        if ($distribution->status !== 'draft' || ! $distribution->institutional_archive_id) {
            throw new HttpException(409, 'Target hanya dapat diubah atau dilihat pada distribusi arsip lembaga draft.');
        }
    }

    private function assertActiveArchive(?InstitutionalArchive $archive): void
    {
        if (! $archive || $archive->trashed() || $archive->status !== 'active' || ! $archive->current_file_id) {
            throw new HttpException(409, 'Arsip lembaga tidak aktif.');
        }
    }

    private function assertValidTargets(array $preview): void
    {
        if ($preview['total_invalid'] > 0 || $preview['total_valid'] < 1) {
            throw new HttpException(422, 'Distribusi membutuhkan target valid tanpa target invalid.');
        }
    }

    private function audit(Request $request, object $actor, string $role, string $action, Distribution $distribution, array $metadata): void
    {
        AuditLog::create(['actor_user_id' => $actor->id, 'actor_role' => $role, 'action' => $action, 'entity_type' => 'institutional_distribution', 'entity_id' => (string) $distribution->distribution_id, 'description' => $action, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'metadata' => $metadata, 'created_at' => now()]);
    }
}
