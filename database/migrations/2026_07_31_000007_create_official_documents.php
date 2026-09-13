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
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.files DROP CONSTRAINT IF EXISTS files_source_type_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_source_type_check CHECK (source_type IN ('personal', 'request', 'distribution', 'admin_upload', 'official'));

CREATE TABLE IF NOT EXISTS arsip_digital.official_documents (
    official_document_id bigserial PRIMARY KEY,
    document_type varchar NOT NULL CHECK (document_type IN ('khs', 'transcript')),
    document_number varchar NOT NULL UNIQUE,
    semester integer NULL,
    subject_user_id bigint NOT NULL,
    subject_mhs_id bigint NOT NULL,
    subject_identifier varchar NOT NULL,
    subject_name_snapshot varchar NOT NULL,
    academic_snapshot jsonb NOT NULL,
    snapshot_captured_at timestamp NOT NULL,
    template_version varchar NOT NULL,
    file_id bigint NOT NULL UNIQUE REFERENCES arsip_digital.files(file_id),
    file_checksum_sha256 varchar NOT NULL,
    status varchar NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'revoked', 'replaced')),
    issued_by_user_id bigint NOT NULL,
    issued_at timestamp NOT NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);

CREATE INDEX IF NOT EXISTS official_documents_subject_idx
    ON arsip_digital.official_documents (subject_user_id, document_type, issued_at DESC);
CREATE INDEX IF NOT EXISTS official_documents_identifier_idx
    ON arsip_digital.official_documents (subject_identifier, document_type);

CREATE OR REPLACE FUNCTION arsip_digital.protect_official_document_snapshot()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Official document registry cannot be deleted';
    END IF;

    IF OLD.document_type IS DISTINCT FROM NEW.document_type
        OR OLD.document_number IS DISTINCT FROM NEW.document_number
        OR OLD.semester IS DISTINCT FROM NEW.semester
        OR OLD.subject_user_id IS DISTINCT FROM NEW.subject_user_id
        OR OLD.subject_mhs_id IS DISTINCT FROM NEW.subject_mhs_id
        OR OLD.subject_identifier IS DISTINCT FROM NEW.subject_identifier
        OR OLD.subject_name_snapshot IS DISTINCT FROM NEW.subject_name_snapshot
        OR OLD.academic_snapshot IS DISTINCT FROM NEW.academic_snapshot
        OR OLD.snapshot_captured_at IS DISTINCT FROM NEW.snapshot_captured_at
        OR OLD.template_version IS DISTINCT FROM NEW.template_version
        OR OLD.file_id IS DISTINCT FROM NEW.file_id
        OR OLD.file_checksum_sha256 IS DISTINCT FROM NEW.file_checksum_sha256
        OR OLD.issued_by_user_id IS DISTINCT FROM NEW.issued_by_user_id
        OR OLD.issued_at IS DISTINCT FROM NEW.issued_at THEN
        RAISE EXCEPTION 'Official document snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS protect_official_document_snapshot ON arsip_digital.official_documents;
CREATE TRIGGER protect_official_document_snapshot
BEFORE UPDATE OR DELETE ON arsip_digital.official_documents
FOR EACH ROW EXECUTE FUNCTION arsip_digital.protect_official_document_snapshot();
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS protect_official_document_snapshot ON arsip_digital.official_documents;
DROP FUNCTION IF EXISTS arsip_digital.protect_official_document_snapshot();
DROP TABLE IF EXISTS arsip_digital.official_documents;
UPDATE arsip_digital.files SET source_type = 'admin_upload' WHERE source_type = 'official';
ALTER TABLE arsip_digital.files DROP CONSTRAINT IF EXISTS files_source_type_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_source_type_check CHECK (source_type IN ('personal', 'request', 'distribution', 'admin_upload'));
SQL);
    }
};
