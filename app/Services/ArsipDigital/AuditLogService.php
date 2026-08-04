<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    public function record(
        string $action,
        string $entityType,
        string|int|null $entityId = null,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null,
        ?int $actorUserId = null,
        ?string $actorRole = null,
    ): ?AuditLog {
        try {
            $user = auth()->user();

            return AuditLog::create([
                'actor_user_id' => $actorUserId ?? $user?->id,
                'actor_role' => $actorRole,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId === null ? null : (string) $entityId,
                'description' => $description,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'metadata' => $metadata,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gagal menulis audit log arsip digital.', [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
