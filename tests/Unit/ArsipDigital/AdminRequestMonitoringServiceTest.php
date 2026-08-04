<?php

namespace Tests\Unit\ArsipDigital;

use App\Models\ArsipDigital\RequestAssignment;
use App\Services\ArsipDigital\AdminRequestMonitoringService;
use App\Services\ArsipDigital\ArchiveUploadValidationService;
use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\NotificationService;
use App\Services\ArsipDigital\RequestStatusWorkflowService;
use App\Services\ArsipDigital\TargetResolverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminRequestMonitoringServiceTest extends TestCase
{
    public function test_reject_requires_reason(): void
    {
        $this->expectException(HttpException::class);

        $settings = new ArsipDigitalSettingsService();
        $service = new AdminRequestMonitoringService(
            $settings,
            new ArsipDigitalStorageService($settings),
            new ArchiveUploadValidationService(),
            new TargetResolverService(),
            new RequestStatusWorkflowService(),
            new AuditLogService(),
            new NotificationService(),
        );

        $assignment = new RequestAssignment();
        $assignment->setRawAttributes(['assignment_id' => 10, 'request_id' => 20], true);

        $service->reject($assignment, '   ', (object) ['id' => 1], 'admin');
    }
}
