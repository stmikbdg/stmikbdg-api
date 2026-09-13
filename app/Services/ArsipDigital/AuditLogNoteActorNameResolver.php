<?php

namespace App\Services\ArsipDigital;

use App\Models\Users\Admin;
use App\Models\Users\Dosen;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;

class AuditLogNoteActorNameResolver
{
    public function resolve(object $actor): string
    {
        return $this->resolveIdentity((int) $actor->id, (string) ($actor->kd_user ?? '')) ?? 'Admin';
    }

    public function resolveUserId(int $userId): ?string
    {
        return $this->resolveIdentity($userId, (string) User::whereKey($userId)->value('kd_user'));
    }

    private function resolveIdentity(int $userId, string $kdUser): ?string
    {
        [$prefix, $identifier] = array_pad(explode('-', trim($kdUser), 2), 2, '');
        $identifier = trim($identifier);
        $name = match (strtoupper(trim($prefix))) {
            'ADM' => $this->uniqueName(Admin::where('kd_admin', $identifier), 'nm_admin'),
            'DSN' => $this->uniqueName(Dosen::where('kd_dosen', $identifier), 'nm_dosen'),
            'MHS' => $this->uniqueName(MahasiswaView::where('nim', $identifier), 'nm_mhs'),
            default => null,
        };

        return $name ?? $this->uniqueName(Admin::where('user_id', $userId), 'nm_admin');
    }

    private function uniqueName(object $query, string $column): ?string
    {
        $names = $query->limit(2)->pluck($column);
        if ($names->count() !== 1) {
            return null;
        }

        $name = trim((string) $names->first());

        return $name !== '' ? $name : null;
    }
}
