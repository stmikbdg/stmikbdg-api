<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function connectionName(): string
    {
        return config('myconfig.database.first_connection') ?: 'pgsql';
    }

    public function up(): void
    {
        DB::connection($this->connectionName())->unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS arsip_digital.distribution_bulk_upload_jobs (
    bulk_upload_job_id bigserial PRIMARY KEY,
    distribution_id bigint NOT NULL REFERENCES arsip_digital.distributions(distribution_id),
    uploaded_by_user_id bigint NOT NULL,
    status varchar NOT NULL DEFAULT 'uploaded' CHECK (status IN ('uploaded', 'processing', 'preview_ready', 'confirming', 'confirmed', 'failed', 'expired', 'cancelled')),
    original_filename varchar NOT NULL,
    storage_disk varchar NULL,
    storage_path text NULL,
    file_size_bytes bigint NULL,
    summary jsonb NULL,
    error_message text NULL,
    expires_at timestamp NULL,
    processed_at timestamp NULL,
    confirmed_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.distribution_bulk_upload_entries (
    bulk_upload_entry_id bigserial PRIMARY KEY,
    bulk_upload_job_id bigint NOT NULL REFERENCES arsip_digital.distribution_bulk_upload_jobs(bulk_upload_job_id),
    recipient_id bigint NULL REFERENCES arsip_digital.distribution_recipients(recipient_id),
    identifier varchar NULL,
    entry_path text NOT NULL,
    original_filename varchar NOT NULL,
    display_filename varchar NOT NULL,
    temporary_disk varchar NULL,
    temporary_path text NULL,
    mime_type varchar NULL,
    extension varchar NULL,
    file_size_bytes bigint NULL,
    checksum_sha256 varchar NULL,
    match_status varchar NOT NULL CHECK (match_status IN ('matched', 'unmatched', 'ambiguous', 'duplicate', 'invalid', 'confirmed', 'skipped', 'failed')),
    match_reason text NULL,
    metadata jsonb NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);

CREATE INDEX IF NOT EXISTS distribution_bulk_upload_jobs_distribution_id_idx
    ON arsip_digital.distribution_bulk_upload_jobs (distribution_id);
CREATE INDEX IF NOT EXISTS distribution_bulk_upload_jobs_status_idx
    ON arsip_digital.distribution_bulk_upload_jobs (status);
CREATE INDEX IF NOT EXISTS distribution_bulk_upload_entries_job_id_idx
    ON arsip_digital.distribution_bulk_upload_entries (bulk_upload_job_id);
CREATE INDEX IF NOT EXISTS distribution_bulk_upload_entries_recipient_id_idx
    ON arsip_digital.distribution_bulk_upload_entries (recipient_id);
CREATE INDEX IF NOT EXISTS distribution_bulk_upload_entries_match_status_idx
    ON arsip_digital.distribution_bulk_upload_entries (match_status);
SQL);
    }

    public function down(): void
    {
        DB::connection($this->connectionName())->unprepared(<<<'SQL'
DROP TABLE IF EXISTS arsip_digital.distribution_bulk_upload_entries;
DROP TABLE IF EXISTS arsip_digital.distribution_bulk_upload_jobs;
SQL);
    }
};
