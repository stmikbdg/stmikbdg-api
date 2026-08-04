<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection(config('myconfig.database.first_connection'))->unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS arsip_digital.dosen_signature_availability (
    user_id bigint PRIMARY KEY,
    is_available boolean NOT NULL DEFAULT false,
    created_at timestamp NULL,
    updated_at timestamp NULL
);
CREATE TABLE IF NOT EXISTS arsip_digital.signature_requests (
    signature_request_id bigserial PRIMARY KEY,
    student_user_id bigint NOT NULL,
    lecturer_user_id bigint NOT NULL,
    student_name varchar NOT NULL,
    lecturer_name varchar NOT NULL,
    title varchar NOT NULL,
    description text NULL,
    status varchar NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','draft','completed','rejected','expired','cancelled')),
    rejection_reason text NULL,
    expires_at timestamp NOT NULL,
    finished_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS signature_requests_active_pair_idx ON arsip_digital.signature_requests (student_user_id, lecturer_user_id) WHERE status IN ('requested','draft');
CREATE INDEX IF NOT EXISTS signature_requests_lecturer_status_idx ON arsip_digital.signature_requests (lecturer_user_id, status, created_at);
CREATE TABLE IF NOT EXISTS arsip_digital.signature_request_files (
    signature_request_file_id bigserial PRIMARY KEY,
    signature_request_id bigint NOT NULL REFERENCES arsip_digital.signature_requests(signature_request_id) ON DELETE CASCADE,
    source_file_id bigint NOT NULL REFERENCES arsip_digital.files(file_id),
    source_sha256 varchar NOT NULL,
    source_filename varchar NOT NULL,
    source_size_bytes bigint NOT NULL,
    sign_session_id uuid NULL REFERENCES arsip_digital.pdf_sign_sessions(sign_session_id) ON DELETE SET NULL,
    signed_result_disk varchar NULL,
    signed_result_path text NULL,
    result_sha256 varchar NULL,
    result_file_id bigint NULL REFERENCES arsip_digital.files(file_id),
    signed_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    UNIQUE (signature_request_id, source_file_id)
);
ALTER TABLE arsip_digital.pdf_sign_sessions ADD COLUMN IF NOT EXISTS signature_request_file_id bigint NULL REFERENCES arsip_digital.signature_request_files(signature_request_file_id) ON DELETE SET NULL;
SQL);
    }

    public function down(): void
    {
        DB::connection(config('myconfig.database.first_connection'))->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.pdf_sign_sessions DROP COLUMN IF EXISTS signature_request_file_id;
DROP TABLE IF EXISTS arsip_digital.signature_request_files;
DROP TABLE IF EXISTS arsip_digital.signature_requests;
DROP TABLE IF EXISTS arsip_digital.dosen_signature_availability;
SQL);
    }
};
