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
        $connection = DB::connection($this->connectionName());

        $connection->unprepared(<<<'SQL'
CREATE SCHEMA IF NOT EXISTS arsip_digital;

CREATE TABLE IF NOT EXISTS arsip_digital.settings (
    setting_id bigserial PRIMARY KEY,
    key varchar UNIQUE NOT NULL,
    value jsonb NOT NULL,
    description text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.categories (
    category_id bigserial PRIMARY KEY,
    owner_user_id bigint NULL,
    owner_role varchar NULL CHECK (owner_role IN ('admin', 'mahasiswa', 'dosen')),
    category_type varchar NOT NULL CHECK (category_type IN ('personal', 'official', 'distribution')),
    name varchar NOT NULL,
    description text NULL,
    visibility varchar NOT NULL DEFAULT 'admin_visible' CHECK (visibility IN ('private', 'admin_visible', 'official')),
    parent_category_id bigint NULL REFERENCES arsip_digital.categories(category_id),
    created_by_user_id bigint NOT NULL,
    created_by_role varchar NOT NULL,
    is_system boolean DEFAULT false,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.files (
    file_id bigserial PRIMARY KEY,
    category_id bigint NULL REFERENCES arsip_digital.categories(category_id),
    owner_user_id bigint NOT NULL,
    owner_role varchar NOT NULL CHECK (owner_role IN ('mahasiswa', 'dosen', 'admin')),
    owner_identifier varchar NOT NULL,
    owner_name_snapshot varchar NULL,
    owner_status_snapshot varchar NULL,
    uploaded_by_user_id bigint NOT NULL,
    uploaded_by_role varchar NOT NULL CHECK (uploaded_by_role IN ('mahasiswa', 'dosen', 'admin')),
    source_type varchar NOT NULL CHECK (source_type IN ('personal', 'request', 'distribution', 'admin_upload')),
    original_filename varchar NOT NULL,
    display_filename varchar NOT NULL,
    storage_disk varchar NOT NULL,
    storage_path text NOT NULL,
    mime_type varchar NULL,
    extension varchar NOT NULL,
    file_size_bytes bigint NOT NULL,
    checksum_sha256 varchar NULL,
    version_group_uuid uuid NOT NULL,
    version_number integer NOT NULL DEFAULT 1,
    is_current boolean NOT NULL DEFAULT true,
    status varchar NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'deleted', 'replaced')),
    metadata jsonb NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL,
    deleted_by_user_id bigint NULL,
    deleted_by_role varchar NULL,
    delete_source varchar NULL CHECK (delete_source IN ('owner', 'admin', 'system')),
    delete_reason text NULL
);

CREATE INDEX IF NOT EXISTS arsip_digital_files_owner_role_user_idx ON arsip_digital.files (owner_role, owner_user_id);
CREATE INDEX IF NOT EXISTS arsip_digital_files_owner_role_identifier_idx ON arsip_digital.files (owner_role, owner_identifier);
CREATE INDEX IF NOT EXISTS arsip_digital_files_category_id_idx ON arsip_digital.files (category_id);
CREATE INDEX IF NOT EXISTS arsip_digital_files_version_group_idx ON arsip_digital.files (version_group_uuid, version_number);
CREATE INDEX IF NOT EXISTS arsip_digital_files_source_type_idx ON arsip_digital.files (source_type);
CREATE INDEX IF NOT EXISTS arsip_digital_files_deleted_at_idx ON arsip_digital.files (deleted_at);

CREATE TABLE IF NOT EXISTS arsip_digital.scholarship_types (
    scholarship_type_id bigserial PRIMARY KEY,
    code varchar UNIQUE NULL,
    name varchar NOT NULL,
    description text NULL,
    is_active boolean DEFAULT true,
    source varchar DEFAULT 'manual' CHECK (source IN ('manual', 'campus_api', 'import')),
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.student_scholarships (
    student_scholarship_id bigserial PRIMARY KEY,
    student_user_id bigint NULL,
    nim varchar NOT NULL,
    student_name_snapshot varchar NULL,
    angkatan_snapshot varchar NULL,
    scholarship_type_id bigint REFERENCES arsip_digital.scholarship_types(scholarship_type_id),
    status varchar DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'expired', 'unknown')),
    period_label varchar NULL,
    start_date date NULL,
    end_date date NULL,
    source varchar DEFAULT 'manual' CHECK (source IN ('manual', 'campus_api', 'import')),
    metadata jsonb NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE INDEX IF NOT EXISTS arsip_digital_student_scholarships_nim_idx ON arsip_digital.student_scholarships (nim);
CREATE INDEX IF NOT EXISTS arsip_digital_student_scholarships_user_idx ON arsip_digital.student_scholarships (student_user_id);
CREATE INDEX IF NOT EXISTS arsip_digital_student_scholarships_type_idx ON arsip_digital.student_scholarships (scholarship_type_id);
CREATE INDEX IF NOT EXISTS arsip_digital_student_scholarships_status_idx ON arsip_digital.student_scholarships (status);
CREATE INDEX IF NOT EXISTS arsip_digital_student_scholarships_angkatan_idx ON arsip_digital.student_scholarships (angkatan_snapshot);

CREATE TABLE IF NOT EXISTS arsip_digital.segments (
    segment_id bigserial PRIMARY KEY,
    name varchar NOT NULL,
    description text NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    source varchar DEFAULT 'manual' CHECK (source IN ('manual', 'import', 'campus_api')),
    is_active boolean DEFAULT true,
    created_by_user_id bigint NOT NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.segment_members (
    segment_member_id bigserial PRIMARY KEY,
    segment_id bigint REFERENCES arsip_digital.segments(segment_id),
    target_user_id bigint NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    identifier varchar NOT NULL,
    name_snapshot varchar NULL,
    angkatan_snapshot varchar NULL,
    prodi_snapshot varchar NULL,
    status_snapshot varchar NULL,
    metadata jsonb NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS arsip_digital_segment_members_unique_active_idx
    ON arsip_digital.segment_members (segment_id, target_role, identifier)
    WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS arsip_digital.requests (
    request_id bigserial PRIMARY KEY,
    title varchar NOT NULL,
    description text NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    scope_type varchar NOT NULL CHECK (scope_type IN ('all', 'filter', 'specific', 'segment')),
    target_filters jsonb NULL,
    target_identifiers jsonb NULL,
    target_segment_ids jsonb NULL,
    max_files integer NOT NULL DEFAULT 1,
    max_file_size_mb integer NULL,
    allowed_extensions jsonb NULL,
    requires_verification boolean DEFAULT true,
    allow_file_reuse boolean DEFAULT true,
    allow_inactive_upload boolean DEFAULT false,
    deadline_at timestamp NULL,
    close_after_deadline boolean DEFAULT false,
    status varchar NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'closed', 'archived')),
    created_by_user_id bigint NOT NULL,
    published_at timestamp NULL,
    closed_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.request_assignments (
    assignment_id bigserial PRIMARY KEY,
    request_id bigint REFERENCES arsip_digital.requests(request_id),
    target_user_id bigint NOT NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    identifier varchar NOT NULL,
    name_snapshot varchar NULL,
    angkatan_snapshot varchar NULL,
    prodi_snapshot varchar NULL,
    status_snapshot varchar NULL,
    scholarship_snapshot jsonb NULL,
    metadata jsonb NULL,
    status varchar NOT NULL DEFAULT 'not_submitted' CHECK (status IN ('not_submitted', 'waiting_verification', 'approved', 'rejected', 'closed')),
    is_late boolean DEFAULT false,
    submitted_at timestamp NULL,
    verified_at timestamp NULL,
    verified_by_user_id bigint NULL,
    reject_reason text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS arsip_digital_request_assignments_unique_active_idx
    ON arsip_digital.request_assignments (request_id, target_role, identifier)
    WHERE deleted_at IS NULL;

CREATE TABLE IF NOT EXISTS arsip_digital.request_files (
    request_file_id bigserial PRIMARY KEY,
    request_id bigint REFERENCES arsip_digital.requests(request_id),
    assignment_id bigint REFERENCES arsip_digital.request_assignments(assignment_id),
    file_id bigint REFERENCES arsip_digital.files(file_id),
    submission_type varchar NOT NULL CHECK (submission_type IN ('uploaded', 'reused', 'admin_uploaded')),
    status varchar NOT NULL DEFAULT 'waiting_verification' CHECK (status IN ('waiting_verification', 'approved', 'rejected', 'replaced')),
    is_late boolean DEFAULT false,
    is_current boolean DEFAULT true,
    note text NULL,
    created_by_user_id bigint NOT NULL,
    created_by_role varchar NOT NULL,
    reviewed_by_user_id bigint NULL,
    reviewed_at timestamp NULL,
    reject_reason text NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.distributions (
    distribution_id bigserial PRIMARY KEY,
    title varchar NOT NULL,
    description text NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    scope_type varchar NOT NULL CHECK (scope_type IN ('specific', 'segment', 'filter')),
    target_filters jsonb NULL,
    target_identifiers jsonb NULL,
    target_segment_ids jsonb NULL,
    status varchar NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'closed', 'archived')),
    created_by_user_id bigint NOT NULL,
    published_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.distribution_recipients (
    recipient_id bigserial PRIMARY KEY,
    distribution_id bigint REFERENCES arsip_digital.distributions(distribution_id),
    target_user_id bigint NOT NULL,
    target_role varchar NOT NULL CHECK (target_role IN ('mahasiswa', 'dosen')),
    identifier varchar NOT NULL,
    name_snapshot varchar NULL,
    angkatan_snapshot varchar NULL,
    prodi_snapshot varchar NULL,
    status_snapshot varchar NULL,
    metadata jsonb NULL,
    file_id bigint NULL REFERENCES arsip_digital.files(file_id),
    delivery_status varchar DEFAULT 'pending' CHECK (delivery_status IN ('pending', 'file_uploaded', 'available', 'downloaded')),
    created_at timestamp NULL,
    updated_at timestamp NULL,
    deleted_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.export_jobs (
    export_job_id bigserial PRIMARY KEY,
    requested_by_user_id bigint NOT NULL,
    export_type varchar NOT NULL CHECK (export_type IN ('request', 'archive_browser', 'distribution')),
    filters jsonb NULL,
    status varchar NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'expired')),
    storage_disk varchar NULL,
    storage_path text NULL,
    file_size_bytes bigint NULL,
    error_message text NULL,
    expires_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL,
    completed_at timestamp NULL
);

CREATE TABLE IF NOT EXISTS arsip_digital.audit_logs (
    audit_log_id bigserial PRIMARY KEY,
    actor_user_id bigint NULL,
    actor_role varchar NULL,
    action varchar NOT NULL,
    entity_type varchar NOT NULL,
    entity_id varchar NULL,
    description text NULL,
    ip_address varchar NULL,
    user_agent text NULL,
    metadata jsonb NULL,
    created_at timestamp NULL
);
SQL);

        $now = now()->toDateTimeString();
        $connection->statement(
            <<<'SQL'
INSERT INTO arsip_digital.settings (key, value, description, created_at, updated_at)
VALUES (
    'archive_defaults',
    '{"default_max_file_size_mb":10,"default_allowed_extensions":["pdf","jpg","jpeg","png","doc","docx","xls","xlsx"],"storage_disk":"s3","personal_quota_mb_by_role":{"mahasiswa":50,"dosen":50}}'::jsonb,
    'Default Arsip Digital V1 settings',
    ?,
    ?
)
ON CONFLICT (key) DO UPDATE SET
    value = EXCLUDED.value,
    description = EXCLUDED.description,
    updated_at = EXCLUDED.updated_at
SQL,
            [$now, $now]
        );
    }

    public function down(): void
    {
        DB::connection($this->connectionName())->unprepared(<<<'SQL'
DROP TABLE IF EXISTS arsip_digital.audit_logs;
DROP TABLE IF EXISTS arsip_digital.export_jobs;
DROP TABLE IF EXISTS arsip_digital.distribution_recipients;
DROP TABLE IF EXISTS arsip_digital.distributions;
DROP TABLE IF EXISTS arsip_digital.request_files;
DROP TABLE IF EXISTS arsip_digital.request_assignments;
DROP TABLE IF EXISTS arsip_digital.requests;
DROP TABLE IF EXISTS arsip_digital.segment_members;
DROP TABLE IF EXISTS arsip_digital.segments;
DROP TABLE IF EXISTS arsip_digital.student_scholarships;
DROP TABLE IF EXISTS arsip_digital.scholarship_types;
DROP TABLE IF EXISTS arsip_digital.files;
DROP TABLE IF EXISTS arsip_digital.categories;
DROP TABLE IF EXISTS arsip_digital.settings;
DROP SCHEMA IF EXISTS arsip_digital;
SQL);
    }
};
