<?php

namespace App\Jobs\ArsipDigital;

use App\Services\ArsipDigital\InstitutionalStorageMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileInstitutionalStorageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public readonly int $reportId) {}

    public function handle(InstitutionalStorageMonitoringService $service): void
    {
        $service->process($this->reportId);
    }

    public function failed(\Throwable $exception): void
    {
        app(InstitutionalStorageMonitoringService::class)->fail($this->reportId, $exception);
    }
}
