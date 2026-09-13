<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class ArsipDigitalCategoryTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_can_create_personal_category_for_target_and_only_target_sees_it(): void
    {
        $categoryId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'owner_role' => 'mahasiswa',
                'owner_user_id' => 2,
                'name' => 'Kategori Target',
            ])
            ->assertCreated()
            ->assertJsonPath('data.category.owner_user_id', 2)
            ->assertJsonPath('data.category.owner_role', 'mahasiswa')
            ->assertJsonPath('data.category.created_by_user_id', 1)
            ->assertJsonPath('data.category.created_by_role', 'admin')
            ->json('data.category.category_id');

        $this->assertDatabaseHas('arsip_digital.categories', [
            'category_id' => $categoryId,
            'owner_user_id' => 2,
            'owner_role' => 'mahasiswa',
            'created_by_user_id' => 1,
            'created_by_role' => 'admin',
        ]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'actor_user_id' => 1,
            'actor_role' => 'admin',
            'action' => 'category.created',
            'entity_type' => 'category',
            'entity_id' => (string) $categoryId,
        ]);
        $this->assertSame('personal', json_decode(DB::table('arsip_digital.audit_logs')->where('entity_id', (string) $categoryId)->value('metadata'), true)['category_type']);

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/categories')
            ->assertOk()
            ->assertJsonPath('data.categories.0.category_id', $categoryId);

        $this->actingAsDosen()
            ->getJson('/api/arsip-digital/categories')
            ->assertOk()
            ->assertJsonMissing(['category_id' => $categoryId]);
    }

    public function test_admin_cannot_create_personal_category_without_owner_target(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'owner_role' => 'mahasiswa',
                'name' => 'Kategori Tanpa Target',
            ])
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_rename_or_delete_system_category(): void
    {
        $categoryId = DB::table('arsip_digital.categories')->insertGetId([
            'owner_user_id' => 2,
            'owner_role' => 'mahasiswa',
            'category_type' => 'personal',
            'name' => 'Permintaan Berkas',
            'visibility' => 'admin_visible',
            'created_by_user_id' => 2,
            'created_by_role' => 'mahasiswa',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsMahasiswa()
            ->putJson('/api/arsip-digital/categories/'.$categoryId, ['name' => 'Nama Baru'])
            ->assertForbidden();

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/categories/'.$categoryId)
            ->assertForbidden();

        $this->assertDatabaseHas('arsip_digital.categories', [
            'category_id' => $categoryId,
            'name' => 'Permintaan Berkas',
            'deleted_at' => null,
        ], 'sqlite');
    }

    public function test_update_category_rejects_descendant_parent_cycle(): void
    {
        $rootId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Root',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $childId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Child',
                'parent_category_id' => $rootId,
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $grandchildId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Grandchild',
                'parent_category_id' => $childId,
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $this->actingAsMahasiswa()
            ->putJson('/api/arsip-digital/categories/'.$rootId, [
                'parent_category_id' => $grandchildId,
            ])
            ->assertUnprocessable();
    }

    public function test_update_category_rejects_self_parent(): void
    {
        $categoryId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Root',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $this->actingAsMahasiswa()
            ->putJson('/api/arsip-digital/categories/'.$categoryId, [
                'parent_category_id' => $categoryId,
            ])
            ->assertUnprocessable();
    }
}
