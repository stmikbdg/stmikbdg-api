<?php

namespace Tests\Unit\ArsipDigital;

use App\Models\ArsipDigital\DistributionRecipient;
use App\Services\ArsipDigital\ArchiveCategoryService;
use App\Services\ArsipDigital\ArchivePermissionService;
use App\Services\ArsipDigital\ArchiveUploadValidationService;
use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\DistributionService;
use App\Services\ArsipDigital\NotificationService;
use App\Services\ArsipDigital\RequestTargetPreviewService;
use App\Services\ArsipDigital\TargetResolverService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DistributionServiceTest extends TestCase
{
    public function test_recipient_visibility_requires_owner_and_available_file(): void
    {
        $service = $this->service();
        $recipient = new DistributionRecipient;
        $recipient->setRawAttributes([
            'recipient_id' => 10,
            'target_user_id' => 5,
            'target_role' => 'mahasiswa',
            'file_id' => 99,
            'delivery_status' => 'available',
        ], true);

        $service->assertRecipientVisibleToUser($recipient, (object) ['id' => 5], 'mahasiswa');

        $this->assertTrue(true);
    }

    public function test_recipient_visibility_rejects_wrong_owner(): void
    {
        $this->expectException(HttpException::class);

        $recipient = new DistributionRecipient;
        $recipient->setRawAttributes([
            'recipient_id' => 10,
            'target_user_id' => 5,
            'target_role' => 'mahasiswa',
            'file_id' => 99,
            'delivery_status' => 'available',
        ], true);

        $this->service()->assertRecipientVisibleToUser($recipient, (object) ['id' => 6], 'mahasiswa');
    }

    public function test_recipient_visibility_rejects_pending_file(): void
    {
        $this->expectException(HttpException::class);

        $recipient = new DistributionRecipient;
        $recipient->setRawAttributes([
            'recipient_id' => 10,
            'target_user_id' => 5,
            'target_role' => 'mahasiswa',
            'file_id' => null,
            'delivery_status' => 'pending',
        ], true);

        $this->service()->assertRecipientVisibleToUser($recipient, (object) ['id' => 5], 'mahasiswa');
    }

    private function service(): DistributionService
    {
        $settings = new ArsipDigitalSettingsService;

        return new DistributionService(
            new RequestTargetPreviewService(new TargetResolverService),
            $settings,
            new ArsipDigitalStorageService($settings),
            new ArchiveUploadValidationService,
            new AuditLogService,
            new NotificationService,
            new ArchiveCategoryService(new ArchivePermissionService, new TargetResolverService),
        );
    }
}
