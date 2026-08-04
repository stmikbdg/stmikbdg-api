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
        DB::connection($this->connectionName())->unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS arsip_digital.notifications (
    notification_id bigserial PRIMARY KEY,
    recipient_user_id bigint NOT NULL,
    recipient_role varchar NOT NULL CHECK (recipient_role IN ('admin', 'mahasiswa', 'dosen')),
    type varchar NOT NULL,
    title varchar NOT NULL,
    message text NULL,
    entity_type varchar NULL,
    entity_id bigint NULL,
    data jsonb NULL,
    read_at timestamp NULL,
    created_at timestamp NULL,
    updated_at timestamp NULL
);

CREATE INDEX IF NOT EXISTS arsip_digital_notifications_recipient_read_created_idx
    ON arsip_digital.notifications (recipient_user_id, recipient_role, read_at, created_at DESC);
CREATE INDEX IF NOT EXISTS arsip_digital_notifications_entity_idx
    ON arsip_digital.notifications (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS arsip_digital_notifications_type_created_idx
    ON arsip_digital.notifications (type, created_at DESC);
SQL);
    }

    public function down(): void
    {
        DB::connection($this->connectionName())->unprepared(<<<'SQL'
DROP TABLE IF EXISTS arsip_digital.notifications;
SQL);
    }
};
