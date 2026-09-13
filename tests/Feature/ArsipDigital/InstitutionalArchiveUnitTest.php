<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class InstitutionalArchiveUnitTest extends ArsipDigitalFeatureTestCase
{
    public function test_only_admin_can_manage_shared_units_and_folders(): void
    {
        $unit = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', [
            'name' => 'Akademik',
            'code' => 'BAAK',
        ])->assertCreated()->json('data.unit');

        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-units')
            ->assertOk()->assertJsonPath('data.units.0.unit_id', $unit['unit_id']);
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/admin/institutional-units')->assertForbidden();
        $this->actingAsDosen()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Internal'])->assertForbidden();
    }

    public function test_every_institutional_classification_endpoint_rejects_non_admin_and_active_role_mismatch_without_leaking_data(): void
    {
        $unitId = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Unit Rahasia'])->assertCreated()->json('data.unit.unit_id');
        $categoryId = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Folder Rahasia'])->assertCreated()->json('data.category.category_id');
        $endpoints = [
            ['getJson', '/api/arsip-digital/admin/institutional-units', []],
            ['postJson', '/api/arsip-digital/admin/institutional-units', ['name' => 'Baru']],
            ['putJson', "/api/arsip-digital/admin/institutional-units/{$unitId}", ['name' => 'Ubah']],
            ['deleteJson', "/api/arsip-digital/admin/institutional-units/{$unitId}", []],
            ['postJson', "/api/arsip-digital/admin/institutional-units/{$unitId}/restore", []],
            ['getJson', '/api/arsip-digital/admin/institutional-categories', []],
            ['postJson', '/api/arsip-digital/admin/institutional-categories', ['name' => 'Baru']],
            ['putJson', "/api/arsip-digital/admin/institutional-categories/{$categoryId}", ['name' => 'Ubah']],
            ['deleteJson', "/api/arsip-digital/admin/institutional-categories/{$categoryId}", []],
            ['postJson', "/api/arsip-digital/admin/institutional-categories/{$categoryId}/restore", []],
        ];

        foreach ([$this->mahasiswa, $this->dosen] as $user) {
            foreach ($endpoints as [$method, $uri, $payload]) {
                $role = $user->id === $this->mahasiswa->id ? 'mahasiswa' : 'dosen';
                $response = $this->actingAs($user, 'api')->withHeader('X-Active-Role', $role)->{$method}($uri, $payload)->assertForbidden();
                $response->assertDontSee('Unit Rahasia')->assertDontSee('Folder Rahasia');
            }
        }

        foreach ($endpoints as [$method, $uri, $payload]) {
            $this->actingAs($this->admin, 'api')->withHeader('X-Active-Role', 'mahasiswa')->{$method}($uri, $payload)
                ->assertForbidden()->assertDontSee('Unit Rahasia')->assertDontSee('Folder Rahasia');
        }
    }

    public function test_legacy_category_restore_passes_actor_and_resolved_role(): void
    {
        $id = DB::table('arsip_digital.categories')->insertGetId([
            'category_type' => 'official', 'name' => 'Legacy', 'visibility' => 'official',
            'created_by_user_id' => 1, 'created_by_role' => 'admin', 'deleted_at' => now(),
        ]);

        $this->actingAsAdmin()->postJson("/api/arsip-digital/categories/{$id}/restore")
            ->assertOk()->assertJsonPath('data.category.category_id', $id);
        $this->assertDatabaseHas('arsip_digital.categories', ['category_id' => $id, 'deleted_at' => null]);
    }

    public function test_unit_duplicate_dependency_delete_restore_and_audit(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', [
            'name' => 'Akademik', 'code' => 'BAAK',
        ])->assertCreated()->json('data.unit.unit_id');

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', [
            'name' => ' akademik ', 'code' => 'LAIN',
        ])->assertUnprocessable();
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', [
            'name' => 'Keuangan', 'code' => 'baak',
        ])->assertUnprocessable();

        DB::table('arsip_digital.institutional_archives')->insert(['unit_id' => $id, 'title' => 'Referensi']);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-units/{$id}")->assertUnprocessable();
        DB::table('arsip_digital.institutional_archives')->delete();
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-units/{$id}")->assertOk();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-units/{$id}/restore")->assertOk();

        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_unit.created', 'actor_user_id' => 1]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_unit.deactivated', 'actor_user_id' => 1]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_unit.restored', 'actor_user_id' => 1]);
    }

    public function test_unit_update_distinguishes_omitted_description_from_null(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', [
            'name' => 'Akademik', 'description' => 'Keterangan',
        ])->assertCreated()->json('data.unit.unit_id');

        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-units/{$id}", ['name' => 'Akademik Baru'])
            ->assertOk()->assertJsonPath('data.unit.description', 'Keterangan');
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-units/{$id}", ['description' => null])
            ->assertOk()->assertJsonPath('data.unit.description', null);
        $this->assertDatabaseHas('arsip_digital.institutional_units', ['unit_id' => $id, 'description' => null]);
    }

    public function test_institutional_category_no_op_update_does_not_write_empty_audit(): void
    {
        $category = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Tetap'])
            ->assertCreated()->json('data.category');

        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-categories/{$category['category_id']}", ['name' => 'Tetap'])
            ->assertOk()->assertJsonPath('data.category.category_id', $category['category_id']);
        $this->assertDatabaseMissing('arsip_digital.audit_logs', [
            'action' => 'institutional_category.updated', 'entity_id' => (string) $category['category_id'],
        ]);
    }

    public function test_institutional_folder_hierarchy_duplicate_cycle_dependency_and_restore(): void
    {
        $root = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Surat'])
            ->assertCreated()->json('data.category.category_id');
        $child = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', [
            'name' => 'Masuk', 'parent_category_id' => $root,
        ])->assertCreated()->json('data.category.category_id');

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', [
            'name' => ' masuk ', 'parent_category_id' => $root,
        ])->assertUnprocessable();
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-categories/{$root}", [
            'parent_category_id' => $child,
        ])->assertUnprocessable();
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$root}")->assertUnprocessable();
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$child}")->assertOk();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-categories/{$child}/restore")->assertOk();
    }

    public function test_restore_conflicts_wrong_type_parent_archive_dependency_and_category_update_audit(): void
    {
        $deletedUnit = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Duplikat', 'code' => 'DUP'])->assertCreated()->json('data.unit.unit_id');
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-units/{$deletedUnit}")->assertOk();
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Duplikat', 'code' => 'DUP'])->assertCreated();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-units/{$deletedUnit}/restore")->assertUnprocessable();

        $deletedCategory = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Konflik'])->assertCreated()->json('data.category.category_id');
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$deletedCategory}")->assertOk();
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Konflik'])->assertCreated();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-categories/{$deletedCategory}/restore")->assertUnprocessable();

        $officialParent = DB::table('arsip_digital.categories')->insertGetId(['category_type' => 'official', 'name' => 'Official', 'visibility' => 'official']);
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Salah Parent', 'parent_category_id' => $officialParent])->assertUnprocessable();

        $categoryId = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Direferensikan'])->assertCreated()->json('data.category.category_id');
        $unitId = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Referensi'])->assertCreated()->json('data.unit.unit_id');
        DB::table('arsip_digital.institutional_archives')->insert(['unit_id' => $unitId, 'category_id' => $categoryId, 'title' => 'Arsip']);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$categoryId}")->assertUnprocessable();
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-categories/{$categoryId}", ['name' => 'Diperbarui'])->assertOk();
        $audit = DB::table('arsip_digital.audit_logs')->where('action', 'institutional_category.updated')->where('entity_id', $categoryId)->first();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['name'], $metadata['changed_fields']);
        $this->assertSame(['name' => 'Direferensikan'], $metadata['before']);
        $this->assertSame(['name' => 'Diperbarui'], $metadata['after']);
        $this->assertArrayNotHasKey('visibility', $metadata['before']);
    }

    public function test_audit_failure_rolls_back_unit_create(): void
    {
        $this->failAuditFor('institutional_unit');

        try {
            $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Rollback'])->assertUnprocessable();
            $this->assertDatabaseMissing('arsip_digital.institutional_units', ['name' => 'Rollback']);
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_unit_update(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Before', 'code' => 'BEF'])->assertCreated()->json('data.unit.unit_id');
        $before = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
        $this->failAuditFor('institutional_unit');

        try {
            $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-units/{$id}", ['name' => 'After', 'code' => 'AFT'])->assertUnprocessable();
            $after = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
            $this->assertSame((array) $before, (array) $after);
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_unit_deactivate(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Active'])->assertCreated()->json('data.unit.unit_id');
        $before = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
        $this->failAuditFor('institutional_unit');

        try {
            $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-units/{$id}")->assertUnprocessable();
            $after = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
            $this->assertSame((array) $before, (array) $after);
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_unit_restore(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-units', ['name' => 'Deleted'])->assertCreated()->json('data.unit.unit_id');
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-units/{$id}")->assertOk();
        $before = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
        $this->failAuditFor('institutional_unit');

        try {
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-units/{$id}/restore")->assertUnprocessable();
            $after = DB::table('arsip_digital.institutional_units')->where('unit_id', $id)->first();
            $this->assertSame((array) $before, (array) $after);
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_category_child_can_move_to_root_and_pagination_bounds_are_validated(): void
    {
        $root = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Root'])->assertCreated()->json('data.category.category_id');
        $child = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Child', 'parent_category_id' => $root])->assertCreated()->json('data.category.category_id');
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-categories/{$child}", ['parent_category_id' => null])->assertOk()->assertJsonPath('data.category.parent_category_id', null);
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-categories?per_page=101')->assertUnprocessable();
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-units?page=0')->assertUnprocessable();
    }

    public function test_audit_failure_rolls_back_category_create(): void
    {
        $this->failAuditFor('institutional_category');
        try {
            $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'created-category'])->assertStatus(500);
            $this->assertDatabaseMissing('arsip_digital.categories', ['name' => 'created-category', 'category_type' => 'institutional']);
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_category_update(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Before'])->assertCreated()->json('data.category.category_id');
        $before = DB::table('arsip_digital.categories')->where('category_id', $id)->first();
        $this->failAuditFor('institutional_category');
        try {
            $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-categories/{$id}", ['name' => 'After', 'parent_category_id' => null])->assertStatus(500);
            $this->assertSame((array) $before, (array) DB::table('arsip_digital.categories')->where('category_id', $id)->first());
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_category_deactivate(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Active Folder'])->assertCreated()->json('data.category.category_id');
        $before = DB::table('arsip_digital.categories')->where('category_id', $id)->first();
        $this->failAuditFor('institutional_category');
        try {
            $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$id}")->assertStatus(500);
            $this->assertSame((array) $before, (array) DB::table('arsip_digital.categories')->where('category_id', $id)->first());
        } finally {
            $this->dropAuditFailure();
        }
    }

    public function test_audit_failure_rolls_back_category_restore(): void
    {
        $id = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-categories', ['name' => 'Deleted Folder'])->assertCreated()->json('data.category.category_id');
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-categories/{$id}")->assertOk();
        $before = DB::table('arsip_digital.categories')->where('category_id', $id)->first();
        $this->failAuditFor('institutional_category');
        try {
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-categories/{$id}/restore")->assertStatus(500);
            $this->assertSame((array) $before, (array) DB::table('arsip_digital.categories')->where('category_id', $id)->first());
        } finally {
            $this->dropAuditFailure();
        }
    }

    private function failAuditFor(string $entityType): void
    {
        DB::statement("CREATE TRIGGER arsip_digital.fail_audit BEFORE INSERT ON audit_logs WHEN NEW.entity_type = '{$entityType}' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
    }

    private function dropAuditFailure(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS arsip_digital.fail_audit');
    }
}
