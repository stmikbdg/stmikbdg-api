<?php

namespace Tests\Unit\ArsipDigital;

use App\Models\ArsipDigital\ArchiveRequest;
use App\Models\ArsipDigital\RequestAssignment;
use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\ExportJobService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExportJobServiceTest extends TestCase
{
    public function test_normalize_request_filters_requires_request_id(): void
    {
        $this->expectException(HttpException::class);

        $this->service()->normalizeRequestFilters([]);
    }

    public function test_normalize_request_filters_accepts_valid_statuses(): void
    {
        $filters = $this->service()->normalizeRequestFilters([
            'request_id' => '12',
            'statuses' => ['approved', 'waiting_verification', 'approved'],
            'assignment_statuses' => ['approved'],
        ]);

        $this->assertSame(12, $filters['request_id']);
        $this->assertSame(['approved', 'waiting_verification'], $filters['statuses']);
        $this->assertSame(['approved'], $filters['assignment_statuses']);
    }

    public function test_normalize_distribution_filters_accepts_valid_filters(): void
    {
        $filters = $this->service()->normalizeDistributionFilters([
            'distribution_id' => '9',
            'recipient_ids' => ['3', '3', '4'],
            'delivery_statuses' => ['available', 'downloaded', 'available'],
            'download_filename' => 'Distribusi/Sertifikat',
        ]);

        $this->assertSame(9, $filters['distribution_id']);
        $this->assertSame([3, 4], $filters['recipient_ids']);
        $this->assertSame(['available', 'downloaded'], $filters['delivery_statuses']);
        $this->assertSame('Distribusi-Sertifikat.zip', $filters['download_filename']);
    }

    public function test_zip_folder_names_are_sanitized(): void
    {
        $request = new ArchiveRequest();
        $request->setRawAttributes(['request_id' => 7, 'title' => 'Akta/Kelahiran:2026'], true);

        $assignment = new RequestAssignment();
        $assignment->setRawAttributes([
            'identifier' => '22010001',
            'name_snapshot' => 'Budi / Santoso',
        ], true);

        $service = $this->service();

        $this->assertSame('Akta-Kelahiran-2026-7', $service->zipRootName($request));
        $this->assertSame('22010001 - Budi - Santoso', $service->recipientFolderName($assignment));
    }

    private function service(): ExportJobService
    {
        return new ExportJobService(
            new ArsipDigitalSettingsService(),
            new AuditLogService(),
        );
    }
}
