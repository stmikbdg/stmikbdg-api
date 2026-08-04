<?php

namespace App\Console\Commands;

use App\Services\ArsipDigital\PdfSelfSignService;
use Illuminate\Console\Command;

class CleanupExpiredPdfSignSessions extends Command
{
    protected $signature = 'arsip-digital:cleanup-expired-pdf-sign-sessions';

    protected $description = 'Cleanup expired PDF sign sessions and temporary files.';

    public function handle(PdfSelfSignService $service): int
    {
        $count = $service->cleanup();

        $this->info("Cleaned up {$count} expired PDF sign session(s).");

        return self::SUCCESS;
    }
}
