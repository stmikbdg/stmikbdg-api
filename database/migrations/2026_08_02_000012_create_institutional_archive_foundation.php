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
            throw new RuntimeException('Institutional archive foundation migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
CREATE TABLE arsip_digital.institutional_units (
    unit_id bigserial PRIMARY KEY,
    code varchar NULL,
    name varchar NOT NULL,
    description text NULL,
    is_active boolean NOT NULL DEFAULT true,
    created_by_user_id bigint NOT NULL,
    updated_by_user_id bigint NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE UNIQUE INDEX institutional_units_name_active_unique
    ON arsip_digital.institutional_units (lower(name))
    WHERE deleted_at IS NULL AND is_active = true;
CREATE UNIQUE INDEX institutional_units_code_active_unique
    ON arsip_digital.institutional_units (lower(code))
    WHERE deleted_at IS NULL AND is_active = true AND code IS NOT NULL;

ALTER TABLE arsip_digital.categories DROP CONSTRAINT categories_category_type_check;
ALTER TABLE arsip_digital.categories
    ADD CONSTRAINT categories_category_type_check
    CHECK (category_type IN ('personal', 'official', 'distribution', 'institutional'));
CREATE UNIQUE INDEX categories_institutional_name_active_unique
    ON arsip_digital.categories (
        category_type,
        COALESCE(parent_category_id, 0),
        lower(name)
    )
    WHERE category_type = 'institutional' AND deleted_at IS NULL;

CREATE TABLE arsip_digital.institutional_archives (
    institutional_archive_id bigserial PRIMARY KEY,
    archive_uuid uuid NOT NULL UNIQUE,
    title varchar NOT NULL,
    document_number varchar NULL,
    document_number_normalized varchar GENERATED ALWAYS AS (
        NULLIF(regexp_replace(lower(document_number), '[[:space:]]+', '', 'g'), '')
    ) STORED,
    document_year smallint NULL,
    document_date date NULL,
    received_date date NULL,
    unit_id bigint NOT NULL,
    category_id bigint NULL,
    description text NULL,
    access_level varchar NOT NULL DEFAULT 'internal',
    retention_note text NULL,
    tags jsonb NULL,
    current_file_id bigint NULL,
    status varchar NOT NULL DEFAULT 'active',
    created_by_user_id bigint NOT NULL,
    updated_by_user_id bigint NULL,
    deleted_by_user_id bigint NULL,
    delete_reason text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL,
    CONSTRAINT institutional_archives_unit_fk FOREIGN KEY (unit_id)
        REFERENCES arsip_digital.institutional_units(unit_id),
    CONSTRAINT institutional_archives_category_fk FOREIGN KEY (category_id)
        REFERENCES arsip_digital.categories(category_id),
    CONSTRAINT institutional_archives_access_level_check
        CHECK (access_level IN ('internal', 'restricted')),
    CONSTRAINT institutional_archives_status_check
        CHECK (status IN ('active', 'deleted')),
    CONSTRAINT institutional_archives_lifecycle_check CHECK (
        (status = 'active'
            AND deleted_at IS NULL
            AND deleted_by_user_id IS NULL
            AND delete_reason IS NULL)
        OR
        (status = 'deleted'
            AND deleted_at IS NOT NULL
            AND deleted_by_user_id IS NOT NULL
            AND NULLIF(trim(delete_reason), '') IS NOT NULL)
    ),
    CONSTRAINT institutional_archives_document_number_date_check
        CHECK (document_number_normalized IS NULL OR document_date IS NOT NULL OR document_year IS NOT NULL),
    CONSTRAINT institutional_archives_document_year_consistency_check
        CHECK (document_date IS NULL OR document_year IS NULL OR document_year = EXTRACT(YEAR FROM document_date))
);

CREATE UNIQUE INDEX institutional_archives_document_number_unique
    ON arsip_digital.institutional_archives (
        unit_id,
        COALESCE(document_year, EXTRACT(YEAR FROM document_date)::smallint),
        document_number_normalized
    )
    WHERE document_number_normalized IS NOT NULL;
CREATE INDEX institutional_archives_title_idx ON arsip_digital.institutional_archives (title);
CREATE INDEX institutional_archives_document_number_idx ON arsip_digital.institutional_archives (document_number_normalized);
CREATE INDEX institutional_archives_unit_idx ON arsip_digital.institutional_archives (unit_id);
CREATE INDEX institutional_archives_category_idx ON arsip_digital.institutional_archives (category_id);
CREATE INDEX institutional_archives_document_date_idx ON arsip_digital.institutional_archives (document_date);
CREATE INDEX institutional_archives_document_year_idx ON arsip_digital.institutional_archives (document_year);
CREATE INDEX institutional_archives_status_idx ON arsip_digital.institutional_archives (status);
CREATE INDEX institutional_archives_creator_idx ON arsip_digital.institutional_archives (created_by_user_id);
CREATE INDEX institutional_archives_created_at_idx ON arsip_digital.institutional_archives (created_at);

ALTER TABLE arsip_digital.files DROP CONSTRAINT files_source_type_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_source_type_check
    CHECK (source_type IN ('personal', 'request', 'distribution', 'admin_upload', 'official', 'institutional'));
ALTER TABLE arsip_digital.files ADD COLUMN institutional_archive_id bigint NULL;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_institutional_archive_fk FOREIGN KEY (institutional_archive_id)
    REFERENCES arsip_digital.institutional_archives(institutional_archive_id),
    ADD CONSTRAINT files_institutional_source_check CHECK (
        (source_type = 'institutional') = (institutional_archive_id IS NOT NULL)
    );
CREATE INDEX files_institutional_archive_idx
    ON arsip_digital.files (institutional_archive_id);
CREATE UNIQUE INDEX files_institutional_archive_current_unique
    ON arsip_digital.files (institutional_archive_id)
    WHERE institutional_archive_id IS NOT NULL
      AND is_current = true
      AND status = 'active'
      AND deleted_at IS NULL;

ALTER TABLE arsip_digital.institutional_archives
    ADD CONSTRAINT institutional_archives_current_file_fk FOREIGN KEY (current_file_id)
    REFERENCES arsip_digital.files(file_id);

CREATE FUNCTION arsip_digital.validate_institutional_archive_current_file()
RETURNS trigger AS $$
BEGIN
    IF NEW.current_file_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM arsip_digital.files f
        WHERE f.file_id = NEW.current_file_id
          AND f.institutional_archive_id = NEW.institutional_archive_id
          AND f.source_type = 'institutional'
          AND f.is_current = true
          AND f.status = 'active'
          AND f.deleted_at IS NULL
    ) THEN
        RAISE EXCEPTION 'Current file must be active current institutional file owned by archive';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER institutional_archives_current_file_validate
AFTER INSERT OR UPDATE OF current_file_id, institutional_archive_id
ON arsip_digital.institutional_archives
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION arsip_digital.validate_institutional_archive_current_file();

CREATE FUNCTION arsip_digital.validate_institutional_file_current_reference()
RETURNS trigger AS $$
BEGIN
    IF OLD.institutional_archive_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM arsip_digital.institutional_archives a
        WHERE a.current_file_id = OLD.file_id
          AND (NEW.institutional_archive_id IS DISTINCT FROM OLD.institutional_archive_id
            OR NEW.source_type <> 'institutional'
            OR NEW.is_current <> true
            OR NEW.status <> 'active'
            OR NEW.deleted_at IS NOT NULL)
    ) THEN
        RAISE EXCEPTION 'Referenced current institutional file must remain active and current';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER files_institutional_current_reference_validate
BEFORE UPDATE ON arsip_digital.files
FOR EACH ROW EXECUTE FUNCTION arsip_digital.validate_institutional_file_current_reference();

ALTER TABLE arsip_digital.distributions
    ADD COLUMN institutional_archive_id bigint NULL,
    ADD COLUMN source_file_id bigint NULL,
    ADD COLUMN expires_at timestamp NULL,
    ADD CONSTRAINT distributions_institutional_archive_fk FOREIGN KEY (institutional_archive_id)
        REFERENCES arsip_digital.institutional_archives(institutional_archive_id),
    ADD CONSTRAINT distributions_source_file_fk FOREIGN KEY (source_file_id)
        REFERENCES arsip_digital.files(file_id),
    ADD CONSTRAINT distributions_source_exclusivity_check CHECK (
        num_nonnulls(official_document_id, institutional_archive_id) <= 1
        AND ((institutional_archive_id IS NULL) = (source_file_id IS NULL))
    );
CREATE INDEX distributions_institutional_archive_idx
    ON arsip_digital.distributions (institutional_archive_id);
CREATE INDEX distributions_source_file_idx
    ON arsip_digital.distributions (source_file_id);

CREATE FUNCTION arsip_digital.validate_institutional_distribution_source()
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
CREATE TRIGGER distributions_institutional_source_validate
BEFORE INSERT OR UPDATE OF institutional_archive_id, source_file_id
ON arsip_digital.distributions
FOR EACH ROW EXECUTE FUNCTION arsip_digital.validate_institutional_distribution_source();
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Institutional archive foundation migration requires PostgreSQL.');
        }

        if ($connection->table('arsip_digital.institutional_archives')->exists()
            || $connection->table('arsip_digital.files')->where('source_type', 'institutional')->exists()
            || $connection->table('arsip_digital.categories')->where('category_type', 'institutional')->exists()
            || $connection->table('arsip_digital.distributions')->whereNotNull('institutional_archive_id')->exists()
            || $connection->table('arsip_digital.distributions')->whereNotNull('source_file_id')->exists()) {
            throw new RuntimeException('Cannot roll back institutional archive schema while institutional data exists.');
        }

        $connection->unprepared(<<<'SQL'
DROP TRIGGER distributions_institutional_source_validate ON arsip_digital.distributions;
DROP FUNCTION arsip_digital.validate_institutional_distribution_source();
DROP TRIGGER files_institutional_current_reference_validate ON arsip_digital.files;
DROP FUNCTION arsip_digital.validate_institutional_file_current_reference();
DROP TRIGGER institutional_archives_current_file_validate ON arsip_digital.institutional_archives;
DROP FUNCTION arsip_digital.validate_institutional_archive_current_file();
ALTER TABLE arsip_digital.institutional_archives DROP CONSTRAINT institutional_archives_current_file_fk;
ALTER TABLE arsip_digital.distributions DROP CONSTRAINT distributions_source_exclusivity_check;
ALTER TABLE arsip_digital.distributions DROP CONSTRAINT distributions_source_file_fk;
ALTER TABLE arsip_digital.distributions DROP CONSTRAINT distributions_institutional_archive_fk;
DROP INDEX arsip_digital.distributions_source_file_idx;
DROP INDEX arsip_digital.distributions_institutional_archive_idx;
ALTER TABLE arsip_digital.distributions DROP COLUMN expires_at;
ALTER TABLE arsip_digital.distributions DROP COLUMN source_file_id;
ALTER TABLE arsip_digital.distributions DROP COLUMN institutional_archive_id;
DROP INDEX arsip_digital.files_institutional_archive_current_unique;
DROP INDEX arsip_digital.files_institutional_archive_idx;
ALTER TABLE arsip_digital.files DROP CONSTRAINT files_institutional_source_check;
ALTER TABLE arsip_digital.files DROP CONSTRAINT files_institutional_archive_fk;
ALTER TABLE arsip_digital.files DROP COLUMN institutional_archive_id;
ALTER TABLE arsip_digital.files DROP CONSTRAINT files_source_type_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_source_type_check
    CHECK (source_type IN ('personal', 'request', 'distribution', 'admin_upload', 'official'));
DROP TABLE arsip_digital.institutional_archives;
DROP INDEX arsip_digital.categories_institutional_name_active_unique;
ALTER TABLE arsip_digital.categories DROP CONSTRAINT categories_category_type_check;
ALTER TABLE arsip_digital.categories
    ADD CONSTRAINT categories_category_type_check
    CHECK (category_type IN ('personal', 'official', 'distribution'));
DROP TABLE arsip_digital.institutional_units;
SQL);
    }
};
