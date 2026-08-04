<?php

namespace App\Services\ArsipDigital;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleResolverService
{
    public const ROLES = ['admin', 'mahasiswa', 'dosen'];

    public function resolve(Request $request, ?array $allowedRoles = null): string
    {
        return $this->resolveForUser($request, auth()->user(), $allowedRoles);
    }

    public function resolveForUser(Request $request, object $user, ?array $allowedRoles = null): string
    {
        $activeRole = $request->header('X-Active-Role');

        if (! in_array($activeRole, self::ROLES, true)) {
            throw new HttpException(403, 'X-Active-Role tidak valid.');
        }

        if ($allowedRoles !== null && ! in_array($activeRole, $allowedRoles, true)) {
            throw new HttpException(403, 'Role tidak memiliki akses arsip digital.');
        }

        if (! $this->userHasRole($user, $activeRole)) {
            throw new HttpException(403, 'X-Active-Role tidak sesuai dengan role user.');
        }

        return $activeRole;
    }

    private function userHasRole(object $user, string $role): bool
    {
        return match ($role) {
            'admin' => $this->isTruthy($user->is_admin ?? false),
            'mahasiswa' => $this->isTruthy($user->is_mhs ?? false),
            'dosen' => $this->isTruthy($user->is_dosen ?? false),
        };
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
