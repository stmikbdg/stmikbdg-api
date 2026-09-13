<?php

namespace App\Jobs\ArsipDigital;

use App\Services\ArsipDigital\ExportJobService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateArchiveExportZipJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly int $exportJobId)
    {
    }

    public function handle(ExportJobService $exportJobService): void
    {
        $exportJobService->process($this->exportJobId);
    }

    public function failed(\Throwable $exception): void
    {
        app(ExportJobService::class)->fail($this->exportJobId, $exception);
    }
}
