<?php

namespace Tests\Unit\ArsipDigital;

use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleResolverServiceTest extends TestCase
{
    public function test_it_rejects_missing_active_role_header(): void
    {
        $request = Request::create('/api/arsip-digital/me/archive-summary');
        $user = (object) ['is_admin' => true, 'is_mhs' => false, 'is_dosen' => false];

        try {
            (new RoleResolverService())->resolveForUser($request, $user);
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_it_rejects_active_role_not_owned_by_user(): void
    {
        $request = Request::create('/api/arsip-digital/me/archive-summary', 'GET', [], [], [], [
            'HTTP_X_ACTIVE_ROLE' => 'admin',
        ]);
        $user = (object) ['is_admin' => false, 'is_mhs' => true, 'is_dosen' => false];

        try {
            (new RoleResolverService())->resolveForUser($request, $user);
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_it_resolves_valid_active_role(): void
    {
        $request = Request::create('/api/arsip-digital/me/archive-summary', 'GET', [], [], [], [
            'HTTP_X_ACTIVE_ROLE' => 'mahasiswa',
        ]);
        $user = (object) ['is_admin' => false, 'is_mhs' => true, 'is_dosen' => false];

        $this->assertSame('mahasiswa', (new RoleResolverService())->resolveForUser($request, $user));
    }
}
