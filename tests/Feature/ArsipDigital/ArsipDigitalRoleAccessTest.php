<?php

namespace Tests\Feature\ArsipDigital;

class ArsipDigitalRoleAccessTest extends ArsipDigitalFeatureTestCase
{
    public function test_active_role_header_controls_access_for_admin_mahasiswa_and_dosen(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/settings')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/requests')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->actingAsDosen()
            ->getJson('/api/arsip-digital/requests')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_missing_or_unowned_active_role_is_forbidden(): void
    {
        $this->actingAs($this->mahasiswa, 'api')
            ->getJson('/api/arsip-digital/me/archive-summary')
            ->assertForbidden();

        $this->actingAs($this->mahasiswa, 'api')
            ->withHeader('X-Active-Role', 'admin')
            ->getJson('/api/arsip-digital/admin/settings')
            ->assertForbidden();
    }
}
