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
            throw new RuntimeException('Institutional archive verification migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
CREATE TABLE arsip_digital.institutional_archive_verifications (
    institutional_archive_verification_id bigserial PRIMARY KEY,
    institutional_archive_id bigint NOT NULL,
    source_file_id bigint NOT NULL UNIQUE,
    token_hash char(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    status varchar(20) NOT NULL CHECK (status IN ('pending','processing','ready','failed','replaced','revoked','unsupported')),
    failure_message varchar(500),
    public_metadata jsonb NOT NULL,
    source_checksum_sha256 char(64) NOT NULL CHECK (source_checksum_sha256 ~ '^[0-9a-f]{64}$'),
    verified_checksum_sha256 char(64) CHECK (verified_checksum_sha256 IS NULL OR verified_checksum_sha256 ~ '^[0-9a-f]{64}$'),
    version_number integer NOT NULL CHECK (version_number >= 1),
    issuer_user_id bigint NOT NULL,
    issuer_name_snapshot varchar NOT NULL,
    storage_disk varchar,
    storage_path text,
    original_filename varchar,
    display_filename varchar,
    mime_type varchar,
    file_size_bytes bigint CHECK (file_size_bytes IS NULL OR file_size_bytes >= 0),
    issued_at timestamp NOT NULL,
    processed_at timestamp,
    replaced_at timestamp,
    revoked_at timestamp,
    replaced_by_verification_id bigint,
    created_at timestamp NOT NULL,
    updated_at timestamp NOT NULL,
    CONSTRAINT institutional_archive_verifications_archive_fk FOREIGN KEY (institutional_archive_id) REFERENCES arsip_digital.institutional_archives(institutional_archive_id),
    CONSTRAINT institutional_archive_verifications_source_fk FOREIGN KEY (source_file_id) REFERENCES arsip_digital.files(file_id),
    CONSTRAINT institutional_archive_verifications_replacement_fk FOREIGN KEY (replaced_by_verification_id) REFERENCES arsip_digital.institutional_archive_verifications(institutional_archive_verification_id),
    CONSTRAINT institutional_archive_verifications_ready_storage CHECK ((status = 'ready' AND storage_disk IS NOT NULL AND storage_path IS NOT NULL AND verified_checksum_sha256 IS NOT NULL AND processed_at IS NOT NULL) OR status <> 'ready'),
    CONSTRAINT institutional_archive_verifications_replaced_link CHECK ((status = 'replaced' AND replaced_at IS NOT NULL AND replaced_by_verification_id IS NOT NULL) OR status <> 'replaced'),
    CONSTRAINT institutional_archive_verifications_revoked_time CHECK ((status = 'revoked' AND revoked_at IS NOT NULL) OR status <> 'revoked')
);
CREATE UNIQUE INDEX institutional_archive_verifications_storage_unique ON arsip_digital.institutional_archive_verifications(storage_disk, storage_path) WHERE storage_path IS NOT NULL;
CREATE INDEX institutional_archive_verifications_archive_status_idx ON arsip_digital.institutional_archive_verifications(institutional_archive_id, status);
CREATE INDEX institutional_archive_verifications_replaced_by_idx ON arsip_digital.institutional_archive_verifications(replaced_by_verification_id) WHERE replaced_by_verification_id IS NOT NULL;

CREATE FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.institutional_archive_id IS DISTINCT FROM OLD.institutional_archive_id
       OR NEW.source_file_id IS DISTINCT FROM OLD.source_file_id
       OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
       OR NEW.public_metadata IS DISTINCT FROM OLD.public_metadata
       OR NEW.source_checksum_sha256 IS DISTINCT FROM OLD.source_checksum_sha256
       OR NEW.version_number IS DISTINCT FROM OLD.version_number
       OR NEW.issuer_user_id IS DISTINCT FROM OLD.issuer_user_id
       OR NEW.issuer_name_snapshot IS DISTINCT FROM OLD.issuer_name_snapshot
       OR NEW.issued_at IS DISTINCT FROM OLD.issued_at THEN
        RAISE EXCEPTION 'institutional archive verification snapshot is immutable';
    END IF;
    IF OLD.status IN ('replaced','revoked') AND NEW.status IS DISTINCT FROM OLD.status THEN
        RAISE EXCEPTION 'terminal verification status is immutable';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER institutional_archive_verifications_snapshot_immutable BEFORE UPDATE ON arsip_digital.institutional_archive_verifications FOR EACH ROW EXECUTE FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot();
CREATE FUNCTION arsip_digital.prevent_institutional_archive_verification_delete() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'institutional archive verifications cannot be deleted'; END; $$;
CREATE TRIGGER institutional_archive_verifications_no_delete BEFORE DELETE ON arsip_digital.institutional_archive_verifications FOR EACH ROW EXECUTE FUNCTION arsip_digital.prevent_institutional_archive_verification_delete();
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional archive verification migration requires PostgreSQL.');
        }
        $connection->unprepared(<<<'SQL'
DROP TABLE arsip_digital.institutional_archive_verifications;
DROP FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot();
DROP FUNCTION arsip_digital.prevent_institutional_archive_verification_delete();
SQL);
    }
};
