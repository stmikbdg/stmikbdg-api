<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\AuditLogNote;
use App\Models\ArsipDigital\AuditLogNoteRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuditLogNoteService
{
    public function __construct(private readonly AuditLogNoteActorNameResolver $actorNames) {}

    public function get(int $auditLogId): array
    {
        AuditLog::findOrFail($auditLogId);
        $note = AuditLogNote::with(['revisions' => fn ($query) => $query->orderByDesc('version')])
            ->where('audit_log_id', $auditLogId)
            ->first();

        $revisions = $note?->revisions ?? [];
        $note?->unsetRelation('revisions');

        return [
            'note' => $note,
            'revisions' => $revisions,
        ];
    }

    public function create(int $auditLogId, string $text, Request $request): array
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($auditLogId, $text, $request): array {
            AuditLog::whereKey($auditLogId)->lockForUpdate()->firstOrFail();
            if (AuditLogNote::where('audit_log_id', $auditLogId)->exists()) {
                throw new HttpException(409, 'Note audit log sudah ada.');
            }

            $actor = auth()->user();
            $actorName = $this->actorNames->resolve($actor);
            $now = now();
            $note = AuditLogNote::create([
                'audit_log_id' => $auditLogId,
                'note' => trim($text),
                'creator_user_id' => $actor->id,
                'creator_name_snapshot' => $actorName,
                'last_editor_user_id' => $actor->id,
                'last_editor_name_snapshot' => $actorName,
                'current_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->revision($note, $actor, $actorName, $now);
            $this->audit('audit_log_note.created', $note, $actor, $request);

            return $this->get($auditLogId);
        });
    }

    public function update(int $auditLogId, string $text, string $expectedUpdatedAt, Request $request): array
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($auditLogId, $text, $expectedUpdatedAt, $request): array {
            $note = AuditLogNote::where('audit_log_id', $auditLogId)->lockForUpdate()->firstOrFail();
            if ($this->timestamp($note) !== now()->parse($expectedUpdatedAt)->utc()->format('Y-m-d\TH:i:s.u\Z')) {
                throw new HttpException(409, 'Note berubah. Muat ulang sebelum menyimpan.');
            }

            $actor = auth()->user();
            $actorName = $this->actorNames->resolve($actor);
            $now = now();
            $note->fill([
                'note' => trim($text),
                'last_editor_user_id' => $actor->id,
                'last_editor_name_snapshot' => $actorName,
                'current_version' => $note->current_version + 1,
                'updated_at' => $now,
            ])->save();
            $this->revision($note, $actor, $actorName, $now);
            $this->audit('audit_log_note.updated', $note, $actor, $request);

            return $this->get($auditLogId);
        });
    }

    private function revision(AuditLogNote $note, object $actor, string $actorName, object $now): void
    {
        AuditLogNoteRevision::create([
            'audit_log_note_id' => $note->audit_log_note_id,
            'version' => $note->current_version,
            'note' => $note->note,
            'actor_user_id' => $actor->id,
            'actor_name_snapshot' => $actorName,
            'created_at' => $now,
        ]);
    }

    private function audit(string $action, AuditLogNote $note, object $actor, Request $request): void
    {
        AuditLog::create([
            'actor_user_id' => $actor->id,
            'actor_role' => 'admin',
            'action' => $action,
            'entity_type' => 'audit_log_note',
            'entity_id' => (string) $note->audit_log_note_id,
            'description' => 'Perubahan note audit log.',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'parent_audit_log_id' => $note->audit_log_id,
                'version' => $note->current_version,
            ],
        ]);
    }

    private function timestamp(AuditLogNote $note): string
    {
        return $note->updated_at->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
