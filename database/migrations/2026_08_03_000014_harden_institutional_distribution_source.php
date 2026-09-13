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
            throw new RuntimeException('Institutional distribution source immutability migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
DROP TRIGGER distributions_institutional_source_validate ON arsip_digital.distributions;
CREATE OR REPLACE FUNCTION arsip_digital.validate_institutional_distribution_source()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND NEW.source_file_id IS DISTINCT FROM OLD.source_file_id THEN
        RAISE EXCEPTION 'Published distribution source file cannot be changed';
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.status IN ('published', 'closed', 'archived') AND NEW.status = 'draft' THEN
        RAISE EXCEPTION 'Distribution status cannot return to draft';
    END IF;

    IF NEW.institutional_archive_id IS NULL OR NEW.source_file_id IS NULL THEN
        RETURN NEW;
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
CREATE TRIGGER distributions_institutional_source_validate
BEFORE INSERT OR UPDATE OF institutional_archive_id, source_file_id, status
ON arsip_digital.distributions
FOR EACH ROW EXECUTE FUNCTION arsip_digital.validate_institutional_distribution_source();
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional distribution source immutability migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
DROP TRIGGER distributions_institutional_source_validate ON arsip_digital.distributions;
CREATE OR REPLACE FUNCTION arsip_digital.validate_institutional_distribution_source()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND NEW.source_file_id IS DISTINCT FROM OLD.source_file_id THEN
        RAISE EXCEPTION 'Published distribution source file cannot be changed';
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.status IN ('published', 'closed', 'archived') AND NEW.status = 'draft' THEN
        RAISE EXCEPTION 'Distribution status cannot return to draft';
    END IF;

    IF NEW.institutional_archive_id IS NULL OR NEW.source_file_id IS NULL THEN
        RETURN NEW;
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
CREATE TRIGGER distributions_institutional_source_validate
BEFORE INSERT OR UPDATE OF institutional_archive_id, source_file_id, status
ON arsip_digital.distributions
FOR EACH ROW EXECUTE FUNCTION arsip_digital.validate_institutional_distribution_source();
SQL);
    }
};
