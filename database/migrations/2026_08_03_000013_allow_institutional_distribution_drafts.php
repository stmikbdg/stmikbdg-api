<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function connection()
    {
        return DB::connection(config('myconfig.database.first_connection'));
    }

    public function up(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional distribution draft source migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.distributions DROP CONSTRAINT distributions_source_exclusivity_check;
ALTER TABLE arsip_digital.distributions ADD CONSTRAINT distributions_source_exclusivity_check CHECK (
    num_nonnulls(official_document_id, institutional_archive_id) <= 1
    AND (source_file_id IS NULL OR institutional_archive_id IS NOT NULL)
);
CREATE OR REPLACE FUNCTION arsip_digital.validate_institutional_distribution_source()
RETURNS trigger AS $$
BEGIN
    IF NEW.institutional_archive_id IS NULL OR NEW.source_file_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' AND NEW.source_file_id IS DISTINCT FROM OLD.source_file_id AND OLD.status <> 'draft' THEN
        RAISE EXCEPTION 'Published distribution source file cannot be changed';
    END IF;

    IF TG_OP = 'INSERT' OR NEW.source_file_id IS DISTINCT FROM OLD.source_file_id OR NEW.institutional_archive_id IS DISTINCT FROM OLD.institutional_archive_id THEN
        IF NOT EXISTS (
            SELECT 1 FROM arsip_digital.files f
            WHERE f.file_id = NEW.source_file_id
              AND f.institutional_archive_id = NEW.institutional_archive_id
              AND f.source_type = 'institutional'
              AND f.is_current = true
              AND f.status = 'active'
              AND f.deleted_at IS NULL
        ) THEN
            RAISE EXCEPTION 'Distribution source file must belong to institutional archive';
        END IF;
    ELSIF NOT EXISTS (
        SELECT 1 FROM arsip_digital.files f
        WHERE f.file_id = NEW.source_file_id
          AND f.institutional_archive_id = NEW.institutional_archive_id
          AND f.source_type = 'institutional'
          AND f.deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Distribution source file must belong to institutional archive';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional distribution draft source migration requires PostgreSQL.');
        }
        if ($connection->table('arsip_digital.distributions')->whereNotNull('institutional_archive_id')->whereNull('source_file_id')->exists()) {
            throw new RuntimeException('Cannot restore strict institutional distribution source validation while drafts with null source exist.');
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.distributions DROP CONSTRAINT distributions_source_exclusivity_check;
ALTER TABLE arsip_digital.distributions ADD CONSTRAINT distributions_source_exclusivity_check CHECK (
    num_nonnulls(official_document_id, institutional_archive_id) <= 1
    AND ((institutional_archive_id IS NULL) = (source_file_id IS NULL))
);
CREATE OR REPLACE FUNCTION arsip_digital.validate_institutional_distribution_source()
RETURNS trigger AS $$
BEGIN
    IF NEW.institutional_archive_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM arsip_digital.files f
        WHERE f.file_id = NEW.source_file_id
          AND f.institutional_archive_id = NEW.institutional_archive_id
          AND f.source_type = 'institutional'
          AND f.is_current = true
          AND f.status = 'active'
          AND f.deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Distribution source file must belong to institutional archive';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};
