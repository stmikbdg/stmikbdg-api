<?php

namespace App\Jobs\ArsipDigital;

use App\Services\ArsipDigital\PdfSelfSignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizePdfSignSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public readonly string $sessionId) {}

    public function handle(PdfSelfSignService $service): void
    {
        $service->processFinalize($this->sessionId);
    }

    public function failed(\Throwable $exception): void
    {
        app(PdfSelfSignService::class)->failFinalize($this->sessionId, cause: $exception);
    }
}
