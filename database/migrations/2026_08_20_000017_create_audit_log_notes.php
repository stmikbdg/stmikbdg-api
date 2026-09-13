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
            throw new RuntimeException('Audit log notes migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
CREATE TABLE arsip_digital.audit_log_notes (
    audit_log_note_id bigserial PRIMARY KEY,
    audit_log_id bigint NOT NULL UNIQUE,
    note text NOT NULL CHECK (char_length(note) BETWEEN 1 AND 5000 AND char_length(trim(note)) > 0),
    creator_user_id bigint NOT NULL,
    creator_name_snapshot varchar NOT NULL,
    last_editor_user_id bigint NOT NULL,
    last_editor_name_snapshot varchar NOT NULL,
    current_version integer NOT NULL DEFAULT 1 CHECK (current_version >= 1),
    created_at timestamp NOT NULL,
    updated_at timestamp NOT NULL,
    CONSTRAINT audit_log_notes_audit_log_fk FOREIGN KEY (audit_log_id)
        REFERENCES arsip_digital.audit_logs(audit_log_id)
);

CREATE TABLE arsip_digital.audit_log_note_revisions (
    audit_log_note_revision_id bigserial PRIMARY KEY,
    audit_log_note_id bigint NOT NULL,
    version integer NOT NULL CHECK (version >= 1),
    note text NOT NULL CHECK (char_length(note) BETWEEN 1 AND 5000 AND char_length(trim(note)) > 0),
    actor_user_id bigint NOT NULL,
    actor_name_snapshot varchar NOT NULL,
    created_at timestamp NOT NULL,
    CONSTRAINT audit_log_note_revisions_note_fk FOREIGN KEY (audit_log_note_id)
        REFERENCES arsip_digital.audit_log_notes(audit_log_note_id),
    CONSTRAINT audit_log_note_revisions_note_version_unique UNIQUE (audit_log_note_id, version)
);
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Audit log notes migration requires PostgreSQL.');
        }

        $connection->unprepared(<<<'SQL'
DROP TABLE arsip_digital.audit_log_note_revisions;
DROP TABLE arsip_digital.audit_log_notes;
SQL);
    }
};
