<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection(config('myconfig.database.first_connection'))->unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS arsip_digital.pdf_sign_sessions (
    sign_session_id uuid PRIMARY KEY,
    owner_user_id bigint NOT NULL,
    owner_role varchar NOT NULL CHECK (owner_role IN ('admin', 'mahasiswa', 'dosen')),
    source_file_id bigint NULL REFERENCES arsip_digital.files(file_id),
    source_path text NOT NULL,
    result_path text NULL,
    source_sha256 varchar NOT NULL,
    result_sha256 varchar NULL,
    original_filename varchar NOT NULL,
    status varchar NOT NULL DEFAULT 'created' CHECK (status IN ('created', 'finalized', 'saved')),
    expires_at timestamp NOT NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);
CREATE INDEX IF NOT EXISTS arsip_digital_pdf_sign_sessions_expiry_idx ON arsip_digital.pdf_sign_sessions (expires_at);
SQL);
    }

    public function down(): void
    {
        DB::connection(config('myconfig.database.first_connection'))->statement('DROP TABLE IF EXISTS arsip_digital.pdf_sign_sessions');
    }
};
