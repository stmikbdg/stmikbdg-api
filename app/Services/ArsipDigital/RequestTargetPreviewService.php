<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\Segment;
use App\Models\ArsipDigital\SegmentMember;
use App\Models\ArsipDigital\StudentScholarship;
use App\Models\Users\Dosen;
use App\Models\Users\DosenView;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RequestTargetPreviewService
{
    public function preview(array $payload, ?int $maxTargets = null, ?int $detailLimit = null): array
    {
        $targetRole = $payload['target_role'];
        $scopeType = $payload['scope_type'];
        $filters = $payload['target_filters'] ?? [];
        $identifiers = $payload['target_identifiers'] ?? [];
        $segmentIds = $payload['target_segment_ids'] ?? [];

        $candidates = match ($scopeType) {
            'all' => $this->allIdentifiers($targetRole),
            'filter' => $this->filterIdentifiers($targetRole, $filters),
            'specific' => collect($this->normalizeIdentifiers($identifiers)),
            'segment' => $this->segmentIdentifiers($targetRole, $segmentIds),
            default => throw new HttpException(422, 'Scope target tidak valid.'),
        };

        $candidates = $candidates
            ->map(fn ($candidate): string => trim((string) (is_array($candidate) ? ($candidate['identifier'] ?? '') : $candidate)))
            ->filter()
            ->unique()
            ->values();

        if ($maxTargets !== null && $candidates->count() > $maxTargets) {
            throw new HttpException(422, "Jumlah target distribution maksimal {$maxTargets}.");
        }

        [$valid, $invalid] = $this->resolveCandidates($targetRole, $scopeType, $candidates);
        $validDetails = $detailLimit === null ? $valid : array_slice($valid, 0, $detailLimit);
        $invalidDetails = $detailLimit === null ? $invalid : array_slice($invalid, 0, $detailLimit);

        return [
            'target_role' => $targetRole,
            'scope_type' => $scopeType,
            'total_targets' => count($valid) + count($invalid),
            'total_valid' => count($valid),
            'total_invalid' => count($invalid),
            'valid_targets' => $validDetails,
            'invalid_targets' => $invalidDetails,
            'details_limit' => $detailLimit,
            'valid_targets_truncated' => count($validDetails) < count($valid),
            'invalid_targets_truncated' => count($invalidDetails) < count($invalid),
        ];
    }

    private function resolveCandidates(string $targetRole, string $scopeType, Collection $candidates): array
    {
        $identifiers = $candidates
            ->map(fn ($candidate): string => trim((string) (is_array($candidate) ? ($candidate['identifier'] ?? '') : $candidate)))
            ->filter()
            ->unique()
            ->values();

        if ($identifiers->isEmpty()) {
            return [[], []];
        }

        if ($targetRole === 'mahasiswa') {
            $accounts = User::whereIn('kd_user', $identifiers->map(fn ($identifier): string => 'MHS-'.$identifier))->get()->keyBy('kd_user');
            $profiles = MahasiswaView::whereIn('nim', $identifiers)->get()->keyBy(fn ($profile): string => trim((string) $profile->nim));
            $scholarships = $this->scholarshipSnapshots($identifiers);

            return $this->buildResolvedTargets($identifiers, $targetRole, $scopeType, $accounts, $profiles, $scholarships, 'MHS-', 'Akun user mahasiswa tidak ditemukan.');
        }

        $accounts = User::whereIn('kd_user', $identifiers->map(fn ($identifier): string => 'DSN-'.$identifier))->get()->keyBy('kd_user');
        $profiles = Dosen::whereIn('kd_dosen', $identifiers)->get()->keyBy(fn ($profile): string => trim((string) $profile->kd_dosen));

        return $this->buildResolvedTargets($identifiers, $targetRole, $scopeType, $accounts, $profiles, collect(), 'DSN-', 'Akun user dosen tidak ditemukan.');
    }

    private function buildResolvedTargets(Collection $identifiers, string $targetRole, string $scopeType, Collection $accounts, Collection $profiles, Collection $scholarships, string $accountPrefix, string $missingAccountMessage): array
    {
        $valid = [];
        $invalid = [];

        foreach ($identifiers as $identifier) {
            $account = $accounts->get($accountPrefix.$identifier);

            if (! $account) {
                $invalid[] = [
                    'target_role' => $targetRole,
                    'identifier' => $identifier,
                    'reason' => $missingAccountMessage,
                ];

                continue;
            }

            $profile = $profiles->get($identifier);
            $valid[] = [
                'target_user_id' => $account->id,
                'target_role' => $targetRole,
                'identifier' => $identifier,
                'name_snapshot' => $targetRole === 'mahasiswa'
                    ? $this->firstFilled($profile, ['nm_mhs', 'nama'], $account->name ?? null)
                    : $this->firstFilled($profile, ['nm_dosen', 'nama'], $account->name ?? null),
                'angkatan_snapshot' => $targetRole === 'mahasiswa' ? $this->firstFilled($profile, ['angkatan', 'masuk_tahun', 'tahun_masuk']) : null,
                'prodi_snapshot' => $targetRole === 'mahasiswa'
                    ? $this->firstFilled($profile, ['prodi', 'nm_jur', 'jurusan', 'jurusan.nm_jur'])
                    : $this->firstFilled($profile, ['prodi', 'homebase', 'kd_jur']),
                'status_snapshot' => $targetRole === 'mahasiswa'
                    ? $this->firstFilled($profile, ['sts_mhs', 'status', 'status_mhs'])
                    : $this->firstFilled($profile, ['status', 'sts_dosen']),
                'scholarship_snapshot' => $scholarships->get($identifier),
                'metadata' => [
                    'scope_type' => $scopeType,
                ],
            ];
        }

        return [$valid, $invalid];
    }

    private function allIdentifiers(string $targetRole): Collection
    {
        $prefix = match ($targetRole) {
            'mahasiswa' => 'MHS-',
            'dosen' => 'DSN-',
            default => throw new HttpException(422, 'Target role harus mahasiswa atau dosen.'),
        };

        return User::where('kd_user', 'like', $prefix.'%')
            ->pluck('kd_user')
            ->map(fn (string $kdUser): string => substr($kdUser, strlen($prefix)));
    }

    private function filterIdentifiers(string $targetRole, array $filters): Collection
    {
        if ($targetRole === 'dosen') {
            $query = DosenView::query()->whereNotNull('kd_dosen');

            if (! empty($filters['student_status'])) {
                $query->whereIn('sts_dosen', (array) $filters['student_status']);
            }

            $identifiers = $query->pluck('kd_dosen')->map(fn ($value): string => trim((string) $value))->unique()->values();

            if (array_key_exists('has_account', $filters) && $filters['has_account'] === true) {
                $accountIdentifiers = User::where('kd_user', 'like', 'DSN-%')
                    ->pluck('kd_user')
                    ->map(fn (string $kdUser): string => substr($kdUser, 4));

                return $identifiers->intersect($accountIdentifiers)->values();
            }

            return $identifiers;
        }

        $hasScholarshipFilter = ! empty($filters['scholarship_type_ids']) || ! empty($filters['scholarship_status']);

        if ($hasScholarshipFilter) {
            $query = StudentScholarship::query();

            if (! empty($filters['scholarship_type_ids'])) {
                $query->whereIn('scholarship_type_id', (array) $filters['scholarship_type_ids']);
            }

            if (! empty($filters['scholarship_status'])) {
                $query->whereIn('status', (array) $filters['scholarship_status']);
            }

            if (! empty($filters['angkatan'])) {
                $query->whereIn('angkatan_snapshot', (array) $filters['angkatan']);
            }

            return $query->pluck('nim')->unique()->values();
        }

        $query = MahasiswaView::query();

        if (! empty($filters['angkatan'])) {
            $query->whereIn('masuk_tahun', (array) $filters['angkatan']);
        }

        if (! empty($filters['student_status'])) {
            $query->whereIn('sts_mhs', (array) $filters['student_status']);
        }

        $identifiers = $query->pluck('nim')->map(fn ($value): string => trim((string) $value))->unique()->values();

        if (array_key_exists('has_account', $filters) && $filters['has_account'] === true) {
            $accountIdentifiers = User::where('kd_user', 'like', 'MHS-%')
                ->pluck('kd_user')
                ->map(fn (string $kdUser): string => substr($kdUser, 4));

            return $identifiers->intersect($accountIdentifiers)->values();
        }

        return $identifiers;
    }

    private function segmentIdentifiers(string $targetRole, array $segmentIds): Collection
    {
        if (empty($segmentIds)) {
            return collect();
        }

        $segments = Segment::whereIn('segment_id', $segmentIds)
            ->where('target_role', $targetRole)
            ->where('is_active', true)
            ->pluck('segment_id');

        return SegmentMember::whereIn('segment_id', $segments)
            ->where('target_role', $targetRole)
            ->pluck('identifier')
            ->unique()
            ->values();
    }

    private function normalizeIdentifiers(mixed $identifiers): array
    {
        if (is_string($identifiers)) {
            $identifiers = preg_split('/[\s,]+/', $identifiers, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($identifiers)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $item): string {
            if (is_array($item)) {
                return trim((string) ($item['identifier'] ?? ''));
            }

            return trim((string) $item);
        }, $identifiers)));
    }

    private function scholarshipSnapshots(Collection $identifiers): Collection
    {
        return StudentScholarship::with('scholarshipType')
            ->whereIn('nim', $identifiers)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('nim')
            ->map(fn (Collection $items): array => $items->map(fn (StudentScholarship $scholarship): array => [
                'scholarship_type_id' => $scholarship->scholarship_type_id,
                'scholarship_name' => $scholarship->scholarshipType?->name,
                'status' => $scholarship->status,
                'period_label' => $scholarship->period_label,
            ])->values()->toArray());
    }

    private function firstFilled(?object $source, array $keys, mixed $fallback = null): mixed
    {
        if (! $source) {
            return $fallback;
        }

        foreach ($keys as $key) {
            $value = data_get($source, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}
