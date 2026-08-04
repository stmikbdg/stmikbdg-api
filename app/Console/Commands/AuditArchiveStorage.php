<?php

namespace App\Console\Commands;

use App\Models\ArsipDigital\ArchiveFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditArchiveStorage extends Command
{
    protected $signature = 'arsip-digital:audit-storage {--dry-run : Report changes without updating records} {--batch=100 : Records checked per batch}';

    protected $description = 'Audit archive objects and persist availability.';

    public function handle(): int
    {
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $dryRun = (bool) $this->option('dry-run');
        $checked = $missing = $changed = $errors = 0;

        ArchiveFile::query()->select(['file_id', 'storage_disk', 'storage_path', 'storage_availability'])
            ->orderBy('file_id')->chunkById($batch, function ($files) use ($dryRun, &$checked, &$missing, &$changed, &$errors): void {
                foreach ($files as $file) {
                    $checked++;
                    try {
                        $availability = Storage::disk($file->storage_disk)->exists($file->storage_path) ? 'available' : 'missing';
                    } catch (\Throwable) {
                        $errors++;
                        $this->error("file_id={$file->file_id} storage check failed");

                        continue;
                    }
                    $missing += (int) ($availability === 'missing');
                    if ($availability !== $file->storage_availability) {
                        $changed++;
                        if (! $dryRun) {
                            $file->update(['storage_availability' => $availability]);
                        }
                    }
                }
            }, 'file_id', 'file_id');

        $this->info("checked={$checked} missing={$missing} changed={$changed} errors={$errors} dry_run=".($dryRun ? 'yes' : 'no'));

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
