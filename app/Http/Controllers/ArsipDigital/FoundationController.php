<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\ArchiveSummaryService;
use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FoundationController extends Controller
{
    public function archiveSummary(
        Request $request,
        RoleResolverService $roleResolver,
        ArchiveSummaryService $summaryService
    ) {
        try {
            $role = $roleResolver->resolve($request);

            return $this->successfulResponseJSON(
                $summaryService->summaryFor(auth()->user(), $role)
            );
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function settings(
        Request $request,
        RoleResolverService $roleResolver,
        ArsipDigitalSettingsService $settingsService
    ) {
        try {
            $roleResolver->resolve($request, ['admin']);

            return $this->successfulResponseJSON($settingsService->getDefaults());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function updateSettings(
        Request $request,
        RoleResolverService $roleResolver,
        ArsipDigitalSettingsService $settingsService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);

            $payload = $request->validate([
                'default_max_file_size_mb' => ['sometimes', 'integer', 'min:1', 'max:200'],
                'default_allowed_extensions' => ['sometimes', 'array', 'min:1'],
                'default_allowed_extensions.*' => ['string', 'regex:/^[A-Za-z0-9]+$/'],
                'storage_disk' => ['sometimes', 'string', 'max:50'],
                'personal_quota_mb_by_role' => ['sometimes', 'array'],
                'personal_quota_mb_by_role.mahasiswa' => ['sometimes', 'integer', 'min:1', 'max:102400'],
                'personal_quota_mb_by_role.dosen' => ['sometimes', 'integer', 'min:1', 'max:102400'],
                'signature_request_max_files' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'signature_request_max_file_size_mb' => ['sometimes', 'integer', 'min:1', 'max:200'],
                'signature_request_max_total_size_mb' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                'signature_request_expiry_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
                'signature_request_cooldown_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            ]);

            if (isset($payload['default_allowed_extensions'])) {
                $payload['default_allowed_extensions'] = array_values(array_unique(array_map(
                    fn (string $extension): string => strtolower($extension),
                    $payload['default_allowed_extensions']
                )));
            }

            $current = $settingsService->getDefaults();
            $archiveLimit = $payload['default_max_file_size_mb'] ?? $current['default_max_file_size_mb'];
            $requestLimit = $payload['signature_request_max_file_size_mb'] ?? $current['signature_request_max_file_size_mb'];
            if ($requestLimit > $archiveLimit) {
                throw new HttpException(422, 'Batas file request tanda tangan tidak boleh melebihi batas upload arsip.');
            }

            $settings = $settingsService->updateDefaults($payload);

            $auditLogService->record(
                'settings.updated',
                'settings',
                'archive_defaults',
                'Pengaturan default arsip digital diperbarui.',
                ['updated_keys' => array_keys($payload)],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON($settings, 'Pengaturan arsip digital berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
