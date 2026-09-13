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
            return;
        }

        $connection->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.files DROP CONSTRAINT IF EXISTS files_status_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_status_check CHECK (status IN ('active', 'deleted', 'replaced', 'revoked'));
ALTER TABLE arsip_digital.distribution_recipients DROP CONSTRAINT IF EXISTS distribution_recipients_delivery_status_check;
ALTER TABLE arsip_digital.distribution_recipients
    ADD CONSTRAINT distribution_recipients_delivery_status_check CHECK (delivery_status IN ('pending', 'file_uploaded', 'available', 'downloaded', 'revoked'));
UPDATE arsip_digital.distribution_recipients AS recipients
SET delivery_status = 'revoked', updated_at = CURRENT_TIMESTAMP
FROM arsip_digital.distributions AS distributions
WHERE recipients.distribution_id = distributions.distribution_id
  AND distributions.status = 'closed'
  AND recipients.deleted_at IS NULL;
UPDATE arsip_digital.files AS files
SET status = 'revoked', is_current = false, updated_at = CURRENT_TIMESTAMP
FROM arsip_digital.distribution_recipients AS recipients
JOIN arsip_digital.distributions AS distributions
  ON distributions.distribution_id = recipients.distribution_id
WHERE files.file_id = recipients.file_id
  AND distributions.status = 'closed'
  AND files.deleted_at IS NULL;
SQL);
    }

    public function down(): void
    {
        $connection = $this->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement("UPDATE arsip_digital.distribution_recipients SET delivery_status = 'available', updated_at = CURRENT_TIMESTAMP WHERE delivery_status = 'revoked'");
        $connection->statement("UPDATE arsip_digital.files SET status = 'active', is_current = true, updated_at = CURRENT_TIMESTAMP WHERE status = 'revoked'");
        $connection->unprepared(<<<'SQL'
ALTER TABLE arsip_digital.files DROP CONSTRAINT IF EXISTS files_status_check;
ALTER TABLE arsip_digital.files
    ADD CONSTRAINT files_status_check CHECK (status IN ('active', 'deleted', 'replaced'));
ALTER TABLE arsip_digital.distribution_recipients DROP CONSTRAINT IF EXISTS distribution_recipients_delivery_status_check;
ALTER TABLE arsip_digital.distribution_recipients
    ADD CONSTRAINT distribution_recipients_delivery_status_check CHECK (delivery_status IN ('pending', 'file_uploaded', 'available', 'downloaded'));
SQL);
    }
};
