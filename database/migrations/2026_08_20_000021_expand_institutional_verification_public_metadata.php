<?php

use App\Services\ArsipDigital\AuditLogNoteActorNameResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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

        $resolver = app(AuditLogNoteActorNameResolver::class);
        $connection->transaction(function () use ($connection, $resolver): void {
            $connection->unprepared('DROP TRIGGER institutional_archive_verifications_snapshot_immutable ON arsip_digital.institutional_archive_verifications');
            $names = [];
            $identity = function (int $userId) use ($resolver, &$names): string {
                if (isset($names[$userId])) {
                    return $names[$userId];
                }
                try {
                    $name = $resolver->resolveUserId($userId) ?? 'Admin';
                } catch (QueryException) {
                    $name = 'Admin';
                }

                return $names[$userId] = "Admin #{$userId} - {$name}";
            };

            $connection->table('arsip_digital.institutional_archive_verifications as verification')
                ->join('arsip_digital.institutional_archives as archive', 'archive.institutional_archive_id', '=', 'verification.institutional_archive_id')
                ->join('arsip_digital.files as file', 'file.file_id', '=', 'verification.source_file_id')
                ->leftJoin('arsip_digital.institutional_units as unit', 'unit.unit_id', '=', 'archive.unit_id')
                ->leftJoin('arsip_digital.categories as category', 'category.category_id', '=', 'archive.category_id')
                ->select([
                    'verification.institutional_archive_verification_id', 'verification.issuer_user_id', 'verification.issuer_name_snapshot',
                    'archive.title', 'archive.document_number', 'archive.document_year', 'archive.document_date', 'archive.received_date', 'archive.access_level', 'archive.created_at as archived_at', 'archive.created_by_user_id',
                    'unit.name as unit_name', 'category.name as category_name', 'file.display_filename', 'file.mime_type', 'file.extension', 'file.file_size_bytes', 'file.created_at as uploaded_at', 'file.uploaded_by_user_id',
                ])
                ->orderBy('verification.institutional_archive_verification_id')
                ->each(function (object $row) use ($connection, $identity): void {
                    $metadata = [
                        'title' => $row->title,
                        'document_number' => $row->document_number,
                        'document_year' => $row->document_year === null ? null : (int) $row->document_year,
                        'document_date' => $row->document_date,
                        'received_date' => $row->received_date,
                        'unit_name' => $row->unit_name,
                        'category_name' => $row->category_name ?: 'Tanpa Folder',
                        'access_level' => $row->access_level,
                        'display_filename' => basename((string) $row->display_filename),
                        'mime_type' => $row->mime_type,
                        'extension' => strtoupper((string) $row->extension),
                        'file_size_bytes' => (int) $row->file_size_bytes,
                        'uploaded_at' => $row->uploaded_at,
                        'uploader' => $identity((int) $row->uploaded_by_user_id),
                        'archived_at' => $row->archived_at,
                        'archive_creator' => $identity((int) $row->created_by_user_id),
                        'issuer' => $identity((int) $row->issuer_user_id),
                    ];
                    $connection->table('arsip_digital.institutional_archive_verifications')
                        ->where('institutional_archive_verification_id', $row->institutional_archive_verification_id)
                        ->update(['public_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                });

            $this->restoreTrigger($connection);
        });
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional archive verification migration requires PostgreSQL.');
        }
        $this->restoreTrigger($connection);
    }

    private function restoreTrigger($connection): void
    {
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
DROP TRIGGER IF EXISTS institutional_archive_verifications_snapshot_immutable ON arsip_digital.institutional_archive_verifications;
CREATE TRIGGER institutional_archive_verifications_snapshot_immutable BEFORE UPDATE ON arsip_digital.institutional_archive_verifications FOR EACH ROW EXECUTE FUNCTION arsip_digital.protect_institutional_archive_verification_snapshot();
SQL);
    }
};
