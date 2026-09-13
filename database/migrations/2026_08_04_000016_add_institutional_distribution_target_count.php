<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'distributions_target_count_check';

    private function connection()
    {
        return DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
    }

    public function up(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional distribution target count migration requires PostgreSQL.');
        }

        $connection->unprepared('ALTER TABLE arsip_digital.distributions ADD COLUMN target_count integer NULL, ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (target_count IS NULL OR target_count >= 0)');
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional distribution target count migration requires PostgreSQL.');
        }

        if ($connection->table('arsip_digital.distributions')->whereNotNull('target_count')->exists()) {
            throw new RuntimeException('Cannot roll back institutional distribution target count while populated values exist.');
        }

        $connection->unprepared('ALTER TABLE arsip_digital.distributions DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT.', DROP COLUMN IF EXISTS target_count');
    }
};
