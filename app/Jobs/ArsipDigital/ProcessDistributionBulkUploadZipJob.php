<?php

namespace App\Jobs\ArsipDigital;

use App\Services\ArsipDigital\DistributionBulkUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDistributionBulkUploadZipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly int $bulkUploadJobId)
    {
    }

    public function handle(DistributionBulkUploadService $bulkUploadService): void
    {
        $bulkUploadService->processPreview($this->bulkUploadJobId);
    }

    public function failed(\Throwable $exception): void
    {
        app(DistributionBulkUploadService::class)->fail($this->bulkUploadJobId, $exception);
    }
}
