<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Models\ArsipDigital\RequestAssignment;

class ArchiveSummaryService
{
    public function __construct(private readonly ArsipDigitalSettingsService $settings)
    {
    }

    public function summaryFor(object $user, string $role): array
    {
        if ($role === 'admin') {
            return [
                'role' => $role,
                'total_files' => ArchiveFile::where('status', 'active')->count(),
                'pending_requests' => RequestAssignment::whereIn('status', ['not_submitted', 'waiting_verification'])->count(),
                'rejected_requests' => RequestAssignment::where('status', 'rejected')->count(),
                'distribution_files' => DistributionRecipient::whereIn('delivery_status', ['available', 'downloaded'])->count(),
            ];
        }

        $personalUsedBytes = (int) ArchiveFile::where('owner_user_id', $user->id)
            ->where('owner_role', $role)
            ->whereIn('source_type', ['personal', 'admin_upload'])
            ->whereNull('deleted_at')
            ->sum('file_size_bytes');
        $settings = $this->settings->getDefaults();
        $personalQuotaMb = $this->settings->personalQuotaMbForRole($role);
        $personalQuotaBytes = $personalQuotaMb === null ? null : $personalQuotaMb * 1024 * 1024;
        $personalRemainingBytes = $personalQuotaBytes === null ? null : max(0, $personalQuotaBytes - $personalUsedBytes);

        return [
            'role' => $role,
            'total_files' => ArchiveFile::where('owner_user_id', $user->id)
                ->where('owner_role', $role)
                ->where('status', 'active')
                ->count(),
            'pending_requests' => RequestAssignment::where('target_user_id', $user->id)
                ->where('target_role', $role)
                ->whereIn('status', ['not_submitted', 'waiting_verification'])
                ->count(),
            'rejected_requests' => RequestAssignment::where('target_user_id', $user->id)
                ->where('target_role', $role)
                ->where('status', 'rejected')
                ->count(),
            'distribution_files' => DistributionRecipient::where('target_user_id', $user->id)
                ->where('target_role', $role)
                ->whereIn('delivery_status', ['available', 'downloaded'])
                ->count(),
            'personal_quota_mb' => $personalQuotaMb,
            'personal_quota_bytes' => $personalQuotaBytes,
            'personal_used_bytes' => $personalUsedBytes,
            'personal_remaining_bytes' => $personalRemainingBytes,
            'personal_usage_percent' => $personalQuotaBytes ? round(($personalUsedBytes / $personalQuotaBytes) * 100, 2) : null,
            'default_max_file_size_mb' => $settings['default_max_file_size_mb'],
            'default_allowed_extensions' => $settings['default_allowed_extensions'],
        ];
    }
}
