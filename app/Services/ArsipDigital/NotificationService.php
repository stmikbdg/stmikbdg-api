<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\Notification;
use App\Models\Users\UserView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function sendToUser(
        int $userId,
        string $role,
        string $type,
        string $title,
        ?string $message,
        ?string $entityType,
        ?int $entityId,
        array $data = []
    ): Notification {
        return Notification::create([
            'recipient_user_id' => $userId,
            'recipient_role' => $role,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'data' => $data ?: null,
        ]);
    }

    public function sendToManyUsers(array $recipients, array $payload): int
    {
        $now = now();
        $rows = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            $userId = data_get($recipient, 'target_user_id')
                ?? data_get($recipient, 'user_id')
                ?? data_get($recipient, 'id');
            $role = data_get($recipient, 'target_role')
                ?? data_get($recipient, 'role')
                ?? ($payload['recipient_role'] ?? $payload['role'] ?? null);

            if (! $userId || ! $role) {
                continue;
            }

            $key = implode('|', [
                $userId,
                $role,
                $payload['type'],
                $payload['entity_type'] ?? '',
                $payload['entity_id'] ?? '',
            ]);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'recipient_user_id' => (int) $userId,
                'recipient_role' => $role,
                'type' => $payload['type'],
                'title' => $payload['title'],
                'message' => $payload['message'] ?? null,
                'entity_type' => $payload['entity_type'] ?? null,
                'entity_id' => $payload['entity_id'] ?? null,
                'data' => empty($payload['data']) ? null : json_encode($payload['data']),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::connection(config('myconfig.database.first_connection'))
                ->table('arsip_digital.notifications')
                ->insert($chunk);
        }

        return count($rows);
    }

    public function sendToAdmins(array $payload): int
    {
        return $this->sendToManyUsers(
            UserView::where('is_admin', true)->get(['id'])->all(),
            ['role' => 'admin'] + $payload
        );
    }

    public function queryForUser(object $user, string $role, array $filters): Builder
    {
        $query = Notification::where('recipient_user_id', $user->id)
            ->where('recipient_role', $role);

        foreach (['type', 'entity_type', 'entity_id'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (array_key_exists('read', $filters) && $filters['read'] !== null) {
            filter_var($filters['read'], FILTER_VALIDATE_BOOL) ? $query->whereNotNull('read_at') : $query->whereNull('read_at');
        }

        if (array_key_exists('unread', $filters) && $filters['unread'] !== null && filter_var($filters['unread'], FILTER_VALIDATE_BOOL)) {
            $query->whereNull('read_at');
        }

        return $query->orderByDesc('created_at')->orderByDesc('notification_id');
    }

    public function unreadCount(object $user, string $role): int
    {
        return $this->queryForUser($user, $role, ['unread' => true])->count();
    }

    public function markRead(object $user, string $role, int $notificationId): ?Notification
    {
        $notification = Notification::where('notification_id', $notificationId)
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', $role)
            ->first();

        if (! $notification) {
            return null;
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification;
    }

    public function markAllRead(object $user, string $role): int
    {
        return Notification::where('recipient_user_id', $user->id)
            ->where('recipient_role', $role)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
