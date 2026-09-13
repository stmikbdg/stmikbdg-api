<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function connection()
    {
        return DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
    }

    public function up(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional storage reconciliation migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
CREATE TABLE arsip_digital.institutional_storage_reconciliation_reports (
    reconciliation_report_id bigserial PRIMARY KEY,
    mode varchar NOT NULL DEFAULT 'existence',
    status varchar NOT NULL DEFAULT 'queued',
    requested_by_user_id bigint NOT NULL,
    total_files bigint NOT NULL DEFAULT 0,
    checked_files bigint NOT NULL DEFAULT 0,
    available_files bigint NOT NULL DEFAULT 0,
    missing_files bigint NOT NULL DEFAULT 0,
    failed_files bigint NOT NULL DEFAULT 0,
    error_message text NULL,
    started_at timestamp NULL,
    finished_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    CONSTRAINT institutional_storage_reconciliation_reports_mode_check CHECK (mode IN ('existence')),
    CONSTRAINT institutional_storage_reconciliation_reports_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))
);
DO $$ BEGIN
    IF to_regclass('public.users') IS NOT NULL THEN
        ALTER TABLE arsip_digital.institutional_storage_reconciliation_reports
            ADD CONSTRAINT institutional_storage_reconciliation_reports_requester_fk
            FOREIGN KEY (requested_by_user_id) REFERENCES public.users(id);
    END IF;
END $$;
CREATE INDEX institutional_storage_reconciliation_reports_created_at_idx ON arsip_digital.institutional_storage_reconciliation_reports (created_at);
CREATE INDEX institutional_storage_reconciliation_reports_status_idx ON arsip_digital.institutional_storage_reconciliation_reports (status);
CREATE UNIQUE INDEX institutional_storage_reconciliation_reports_active_scope_mode_unique
    ON arsip_digital.institutional_storage_reconciliation_reports (mode)
    WHERE status IN ('queued', 'running');
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional storage reconciliation migration requires PostgreSQL.');
        }
        if ($connection->table('arsip_digital.institutional_storage_reconciliation_reports')->exists()) {
            throw new RuntimeException('Cannot roll back institutional storage reconciliation schema while report rows exist.');
        }
        $connection->unprepared('DROP TABLE arsip_digital.institutional_storage_reconciliation_reports');
    }
};
