<?php

use App\Services\ArsipDigital\AuditLogNoteActorNameResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection(config('myconfig.database.first_connection') ?: 'pgsql');
        if (! $this->hasTable($connection, 'audit_log_notes') || ! $this->hasTable($connection, 'audit_log_note_revisions')) {
            return;
        }

        $notes = $connection->table('arsip_digital.audit_log_notes');
        $revisions = $connection->table('arsip_digital.audit_log_note_revisions');
        $userIds = collect()
            ->merge((clone $notes)->where('creator_name_snapshot', 'Admin')->distinct()->pluck('creator_user_id'))
            ->merge((clone $notes)->where('last_editor_name_snapshot', 'Admin')->distinct()->pluck('last_editor_user_id'))
            ->merge((clone $revisions)->where('actor_name_snapshot', 'Admin')->distinct()->pluck('actor_user_id'))
            ->merge((clone $notes)->whereRaw("creator_name_snapshot = 'Admin #' || CAST(creator_user_id AS TEXT)")->distinct()->pluck('creator_user_id'))
            ->merge((clone $notes)->whereRaw("last_editor_name_snapshot = 'Admin #' || CAST(last_editor_user_id AS TEXT)")->distinct()->pluck('last_editor_user_id'))
            ->merge((clone $revisions)->whereRaw("actor_name_snapshot = 'Admin #' || CAST(actor_user_id AS TEXT)")->distinct()->pluck('actor_user_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $resolver = app(AuditLogNoteActorNameResolver::class);
        $names = $userIds->mapWithKeys(function (int $userId) use ($resolver): array {
            $name = $resolver->resolveUserId($userId);

            return $name === null ? [] : [$userId => $name];
        });

        $connection->transaction(function () use ($connection, $names): void {
            foreach ($names as $userId => $name) {
                $placeholders = ['Admin', "Admin #$userId"];
                $connection->table('arsip_digital.audit_log_notes')
                    ->where('creator_user_id', $userId)
                    ->whereIn('creator_name_snapshot', $placeholders)
                    ->update(['creator_name_snapshot' => $name]);
                $connection->table('arsip_digital.audit_log_notes')
                    ->where('last_editor_user_id', $userId)
                    ->whereIn('last_editor_name_snapshot', $placeholders)
                    ->update(['last_editor_name_snapshot' => $name]);
                $connection->table('arsip_digital.audit_log_note_revisions')
                    ->where('actor_user_id', $userId)
                    ->whereIn('actor_name_snapshot', $placeholders)
                    ->update(['actor_name_snapshot' => $name]);
            }
        });
    }

    public function down(): void {}

    private function hasTable(object $connection, string $table): bool
    {
        if ($connection->getDriverName() === 'sqlite') {
            return $connection->selectOne("SELECT 1 FROM arsip_digital.sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
        }

        return $connection->getSchemaBuilder()->hasTable('arsip_digital.'.$table);
    }
};
