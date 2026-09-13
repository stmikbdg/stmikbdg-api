<?php

namespace App\Console\Commands;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\InstitutionalArchiveVerification;
use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use Illuminate\Console\Command;

class BackfillInstitutionalArchiveVerifications extends Command
{
    protected $signature = 'arsip-digital:backfill-institutional-verifications {--chunk=100}';

    protected $description = 'Queue missing institutional archive PDF verifications';

    public function handle(InstitutionalArchiveVerificationService $service): int
    {
        $count = 0;
        ArchiveFile::with(['institutionalArchive.unit'])->where('source_type', 'institutional')->whereNotNull('institutional_archive_id')->whereNotIn('file_id', InstitutionalArchiveVerification::select('source_file_id'))->orderBy('file_id')->chunkById(max(1, (int) $this->option('chunk')), function ($files) use ($service, &$count): void {
            foreach ($files as $file) {
                if ($file->institutionalArchive) {
                    $issuer = (object) ['id' => $file->uploaded_by_user_id, 'name' => $file->owner_name_snapshot ?: 'Administrator STMIK Bandung'];
                    $service->create($file->institutionalArchive, $file, $issuer);
                    $count++;
                }
            }
        }, 'file_id');
        $this->info("{$count} verification registry rows created.");

        return self::SUCCESS;
    }
}
