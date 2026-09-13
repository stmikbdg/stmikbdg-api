<?php

use App\Services\ArsipDigital\AuditLogNoteActorNameResolver;
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

        $connection->unprepared('DROP TRIGGER institutional_archive_verifications_snapshot_immutable ON arsip_digital.institutional_archive_verifications');

        $resolver = app(AuditLogNoteActorNameResolver::class);
        $connection->table('arsip_digital.institutional_archive_verifications')
            ->where(function ($query): void {
                $query->whereIn('issuer_name_snapshot', ['Admin', 'Administrator STMIK Bandung'])
                    ->orWhere('issuer_name_snapshot', 'like', 'Admin #%');
            })
            ->orderBy('institutional_archive_verification_id')
            ->each(function (object $row) use ($connection, $resolver): void {
                $name = $resolver->resolveUserId((int) $row->issuer_user_id) ?? 'Admin';
                $connection->table('arsip_digital.institutional_archive_verifications')
                    ->where('institutional_archive_verification_id', $row->institutional_archive_verification_id)
                    ->update(['issuer_name_snapshot' => $name]);
            });

        $connection->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.institutional_archive_id IS DISTINCT FROM OLD.institutional_archive_id
       OR NEW.source_file_id IS DISTINCT FROM OLD.source_file_id
       OR (NEW.token_hash IS DISTINCT FROM OLD.token_hash AND NOT (OLD.status = 'failed' AND NEW.status = 'pending'))
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
SQL);
    }

    public function down(): void
    {
        $this->connection()->unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
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
SQL);
    }
};
