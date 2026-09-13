<?php

namespace App\Services\ArsipDigital;

use App\Models\Users\Admin;
use App\Models\Users\Dosen;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;

class TargetResolverService
{
    public function resolve(string $role, string $identifier): array
    {
        $identifier = trim($identifier);

        return match ($role) {
            'mahasiswa' => $this->resolveMahasiswa($identifier),
            'dosen' => $this->resolveDosen($identifier),
            'admin' => $this->resolveAdmin($identifier),
            default => $this->invalid($role, $identifier, 'Role target tidak didukung.'),
        };
    }

    public function resolveCurrentUser(object $user, string $activeRole): array
    {
        $identifier = $this->identifierFromKdUser((string) ($user->kd_user ?? ''));

        if ($identifier === null) {
            return $this->invalid($activeRole, '', 'Identifier user tidak valid.');
        }

        return $this->resolve($activeRole, $identifier);
    }

    public function resolveByUserId(string $role, int $userId): array
    {
        $account = User::find($userId);
        $prefix = match ($role) {
            'mahasiswa' => 'MHS-',
            'dosen' => 'DSN-',
            'admin' => 'ADM-',
            default => null,
        };

        if (! $account || $prefix === null || ! str_starts_with((string) $account->kd_user, $prefix)) {
            return $this->invalid($role, (string) $userId, 'Akun user target tidak ditemukan.');
        }

        return $this->resolve($role, substr((string) $account->kd_user, strlen($prefix)));
    }

    private function resolveMahasiswa(string $nim): array
    {
        $account = User::where('kd_user', 'MHS-' . $nim)->first();
        $profile = MahasiswaView::where('nim', $nim)->first();

        if (! $account) {
            return $this->invalid('mahasiswa', $nim, 'Akun user mahasiswa tidak ditemukan.');
        }

        return [
            'valid' => true,
            'role' => 'mahasiswa',
            'target_user_id' => $account->id,
            'identifier' => $nim,
            'name_snapshot' => $this->firstFilled($profile, ['nm_mhs', 'nama'], $account->name ?? null),
            'angkatan_snapshot' => $this->firstFilled($profile, ['angkatan', 'masuk_tahun', 'tahun_masuk']),
            'prodi_snapshot' => $this->firstFilled($profile, ['prodi', 'nm_jur', 'jurusan', 'jurusan.nm_jur']),
            'status_snapshot' => $this->firstFilled($profile, ['sts_mhs', 'status', 'status_mhs']),
            'account' => $account,
            'profile' => $profile,
            'error' => null,
        ];
    }

    private function resolveDosen(string $kodeDosen): array
    {
        $account = User::where('kd_user', 'DSN-' . $kodeDosen)->first();
        $profile = Dosen::where('kd_dosen', $kodeDosen)->first();

        if (! $account) {
            return $this->invalid('dosen', $kodeDosen, 'Akun user dosen tidak ditemukan.');
        }

        return [
            'valid' => true,
            'role' => 'dosen',
            'target_user_id' => $account->id,
            'identifier' => $kodeDosen,
            'name_snapshot' => $this->firstFilled($profile, ['nm_dosen', 'nama'], $account->name ?? null),
            'angkatan_snapshot' => null,
            'prodi_snapshot' => $this->firstFilled($profile, ['prodi', 'homebase', 'kd_jur']),
            'status_snapshot' => $this->firstFilled($profile, ['status', 'sts_dosen']),
            'account' => $account,
            'profile' => $profile,
            'error' => null,
        ];
    }

    private function resolveAdmin(string $kodeAdmin): array
    {
        $account = User::where('kd_user', 'ADM-' . $kodeAdmin)->first();
        $profile = Admin::where('kd_admin', $kodeAdmin)->first();

        if (! $account) {
            return $this->invalid('admin', $kodeAdmin, 'Akun user admin tidak ditemukan.');
        }

        return [
            'valid' => true,
            'role' => 'admin',
            'target_user_id' => $account->id,
            'identifier' => $kodeAdmin,
            'name_snapshot' => $this->firstFilled($profile, ['nm_admin', 'nama'], $account->name ?? null),
            'angkatan_snapshot' => null,
            'prodi_snapshot' => null,
            'status_snapshot' => null,
            'account' => $account,
            'profile' => $profile,
            'error' => null,
        ];
    }

    private function invalid(string $role, string $identifier, string $message): array
    {
        return [
            'valid' => false,
            'role' => $role,
            'target_user_id' => null,
            'identifier' => $identifier,
            'name_snapshot' => null,
            'angkatan_snapshot' => null,
            'prodi_snapshot' => null,
            'status_snapshot' => null,
            'account' => null,
            'profile' => null,
            'error' => $message,
        ];
    }

    private function identifierFromKdUser(string $kdUser): ?string
    {
        $parts = explode('-', $kdUser, 2);

        return count($parts) === 2 && $parts[1] !== '' ? $parts[1] : null;
    }

    private function firstFilled(?object $source, array $keys, mixed $fallback = null): mixed
    {
        if (! $source) {
            return $fallback;
        }

        foreach ($keys as $key) {
            $value = data_get($source, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}
