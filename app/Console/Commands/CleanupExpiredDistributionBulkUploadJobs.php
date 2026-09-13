<?php

namespace App\Console\Commands;

use App\Services\ArsipDigital\DistributionBulkUploadService;
use Illuminate\Console\Command;

class CleanupExpiredDistributionBulkUploadJobs extends Command
{
    protected $signature = 'arsip-digital:cleanup-expired-distribution-bulk-upload-jobs';

    protected $description = 'Cleanup expired distribution bulk upload ZIP jobs and temporary files.';

    public function handle(DistributionBulkUploadService $bulkUploadService): int
    {
        $count = $bulkUploadService->cleanupExpired();

        $this->info("Cleaned up {$count} expired distribution bulk upload job(s).");

        return self::SUCCESS;
    }
}
