<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection(config('myconfig.database.first_connection'))->getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection(config('myconfig.database.first_connection'))->unprepared(<<<'SQL'
CREATE INDEX IF NOT EXISTS distribution_recipients_distribution_identifier_idx
    ON arsip_digital.distribution_recipients (distribution_id, identifier);
CREATE INDEX IF NOT EXISTS distribution_recipients_distribution_status_idx
    ON arsip_digital.distribution_recipients (distribution_id, delivery_status);
CREATE INDEX IF NOT EXISTS distributions_created_order_idx
    ON arsip_digital.distributions (created_at DESC, distribution_id DESC);
SQL);
    }

    public function down(): void
    {
        if (DB::connection(config('myconfig.database.first_connection'))->getDriverName() !== 'pgsql') {
            return;
        }

        DB::connection(config('myconfig.database.first_connection'))->unprepared(<<<'SQL'
DROP INDEX IF EXISTS arsip_digital.distribution_recipients_distribution_identifier_idx;
DROP INDEX IF EXISTS arsip_digital.distribution_recipients_distribution_status_idx;
DROP INDEX IF EXISTS arsip_digital.distributions_created_order_idx;
SQL);
    }
};
