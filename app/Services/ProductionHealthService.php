<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductionHealthService
{
    public function check(): array
    {
        $checks = ['database' => false, 'migrations' => false, 'storage' => false];

        try {
            DB::connection()->getPdo();
            $checks['database'] = true;
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $checks['migrations'] = count(array_diff(array_keys($files), $migrator->getRepository()->getRan())) === 0;
        } catch (\Throwable) {
        }

        $disk = Storage::disk(config('filesystems.default'));
        $probe = 'health/'.Str::uuid().'.tmp';
        try {
            $checks['storage'] = $disk->put($probe, 'ok', ['visibility' => 'private'])
                && $disk->get($probe) === 'ok'
                && $disk->delete($probe);
        } catch (\Throwable) {
            try {
                $disk->delete($probe);
            } catch (\Throwable) {
            }
        }

        return ['ok' => ! in_array(false, $checks, true), 'checks' => $checks];
    }
}
