<?php

namespace App\Console\Commands;

use App\Services\ProductionHealthService;
use Illuminate\Console\Command;

class ProductionHealthCheck extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Check database, migrations, and configured storage.';

    public function handle(ProductionHealthService $health): int
    {
        $result = $health->check();
        foreach ($result['checks'] as $check => $ok) {
            $this->line($check.': '.($ok ? 'ok' : 'degraded'));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
