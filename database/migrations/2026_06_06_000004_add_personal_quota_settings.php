<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function connectionName(): string
    {
        return config('myconfig.database.first_connection', config('database.default'));
    }

    public function up(): void
    {
        DB::connection($this->connectionName())->statement(<<<'SQL'
UPDATE arsip_digital.settings
SET value = value || '{"personal_quota_mb_by_role":{"mahasiswa":50,"dosen":50}}'::jsonb,
    updated_at = NOW()
WHERE key = 'archive_defaults'
SQL);
    }

    public function down(): void
    {
        DB::connection($this->connectionName())->statement(<<<'SQL'
UPDATE arsip_digital.settings
SET value = value - 'personal_quota_mb_by_role',
    updated_at = NOW()
WHERE key = 'archive_defaults'
SQL);
    }
};
