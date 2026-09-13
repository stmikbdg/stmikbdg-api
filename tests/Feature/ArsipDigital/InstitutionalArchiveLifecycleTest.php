<?php

namespace Tests\Feature\ArsipDigital;

use App\Models\Users\UserView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InstitutionalArchiveLifecycleTest extends ArsipDigitalFeatureTestCase
{
    private int $unitId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unitId = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'is_active' => true, 'created_by_user_id' => 1]);
        $this->categoryId = DB::table('arsip_digital.categories')->insertGetId(['name' => 'Surat', 'category_type' => 'institutional']);
    }

    public function test_delete_requires_admin_reason_is_atomic_and_preserves_files(): void
    {
        $archive = $this->upload();
        $id = $archive['institutional_archive_id'];
        $fileId = $archive['current_file_id'];
        $file = DB::table('arsip_digital.files')->where('file_id', $fileId)->first();
        $object = Storage::disk('s3')->get($file->storage_path);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsMahasiswa()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'x'])->assertForbidden();
        DB::statement("CREATE TRIGGER arsip_digital.fail_delete_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'institutional_archive.deleted' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'Kadaluarsa'])->assertStatus(500);
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $id, 'status' => 'active', 'deleted_at' => null]);
        DB::statement('DROP TRIGGER arsip_digital.fail_delete_audit');
        $second = $this->adminUser(9);
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => ' Kadaluarsa '])->assertOk()->assertJsonPath('data.archive.status', 'deleted');
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $id, 'deleted_by_user_id' => 9, 'delete_reason' => 'Kadaluarsa', 'current_file_id' => $fileId]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_archive.deleted', 'actor_user_id' => 9]);
        $this->assertSame($object, Storage::disk('s3')->get($file->storage_path));
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $fileId, 'is_current' => 1, 'status' => 'active']);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'lagi'])->assertNotFound();
        $this->assertSame(1, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_archive.deleted')->count());
    }

    public function test_deleted_guards_trash_dto_filters_privacy_and_no_hard_delete(): void
    {
        $archive = $this->upload(['title' => 'Rahasia Needle', 'document_number' => 'N-1', 'document_year' => 2026]);
        $id = $archive['institutional_archive_id'];
        $file = $archive['current_file_id'];
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'Selesai'])->assertOk();
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives')->assertJsonCount(0, 'data.archives');
        foreach ([['putJson', "admin/institutional-archives/$id", ['title' => 'x']], ['postJson', "admin/institutional-archives/$id/move", ['category_id' => null]], ['postJson', "admin/institutional-archives/$id/versions", []]] as [$method, $uri, $payload]) {
            $this->actingAsAdmin()->{$method}("/api/arsip-digital/$uri", $payload)->assertNotFound();
        }
        foreach (["$id", "$id/preview", "$id/download", "$id/versions", "$id/versions/$file/download"] as $suffix) {
            $this->actingAsAdmin()->get("/api/arsip-digital/admin/institutional-archives/$suffix")->assertNotFound();
        }
        $response = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/trash?search=needle&unit_id=$this->unitId&document_year=2026&sort=title&direction=asc&per_page=1&page=1")->assertOk()->assertJsonCount(1, 'data.archives');
        $expectedKeys = ['institutional_archive_id', 'title', 'document_number', 'document_year', 'document_date', 'unit', 'category', 'current_file', 'deleted_by_user_id', 'deleted_at', 'delete_reason'];
        $actualKeys = array_keys($response->json('data.archives.0'));
        sort($expectedKeys);
        sort($actualKeys);
        $this->assertSame($expectedKeys, $actualKeys);
        $response->assertJsonPath('data.archives.0.current_file.display_filename', 'dokumen.pdf')->assertJsonMissing(['storage_path'])->assertJsonMissing(['storage_disk'])->assertJsonMissing(['checksum_sha256'])->assertJsonMissing(['archive_uuid']);
        foreach (['per_page=101', 'sort=title%3Bdrop', 'direction=asc%20nulls%20last'] as $query) {
            $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives/trash?'.$query)->assertUnprocessable();
        }
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id/hard", ['reason' => 'x'])->assertNotFound();
    }

    public function test_restore_atomic_repeated_safe_and_number_remains_reserved(): void
    {
        $archive = $this->upload(['document_number' => 'UNIK', 'document_year' => 2026]);
        $id = $archive['institutional_archive_id'];
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'x'])->assertOk();
        $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', ['file' => $this->pdfUpload(), 'title' => 'Bentrok', 'unit_id' => $this->unitId, 'document_number' => ' unik ', 'document_year' => 2026], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('document_number');
        DB::statement("CREATE TRIGGER arsip_digital.fail_restore_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'institutional_archive.restored' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/$id/restore")->assertStatus(500);
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $id, 'status' => 'deleted', 'delete_reason' => 'x']);
        DB::statement('DROP TRIGGER arsip_digital.fail_restore_audit');
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/$id/restore")->assertOk()->assertJsonPath('data.archive.status', 'active');
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/$id/restore")->assertNotFound();
        $this->assertSame(1, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_archive.restored')->count());
    }

    public function test_timeline_active_deleted_scoped_order_paginated_and_malformed_safe(): void
    {
        $first = $this->upload();
        $other = $this->upload(['title' => 'Lain']);
        $id = $first['institutional_archive_id'];
        DB::table('arsip_digital.audit_logs')->insert(['actor_user_id' => 1, 'actor_role' => 'admin', 'action' => 'institutional_archive.metadata_updated', 'entity_type' => 'institutional_archive', 'entity_id' => (string) $id, 'metadata' => json_encode(['changed_fields' => ['title', 9, 'storage_path'], 'before' => ['title' => 'A', 'tags' => null, 'storage_path' => 'secret', 'nested' => ['token' => 'secret']], 'after' => null, 'reason' => ['bad'], 'token' => 'secret']), 'created_at' => now()->addSecond()]);
        DB::table('arsip_digital.audit_logs')->insert(['actor_user_id' => 1, 'actor_role' => 'admin', 'action' => 'evil.action', 'entity_type' => 'institutional_archive', 'entity_id' => (string) $id, 'metadata' => '{bad', 'created_at' => now()->addSeconds(2)]);
        $page = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/$id/timeline?per_page=1&page=1")->assertOk()->assertJsonCount(1, 'data.activities');
        $page->assertJsonPath('data.activities.0.label', 'Metadata diperbarui')->assertJsonPath('data.activities.0.actor_display_name', 'Admin Test')->assertJsonPath('data.activities.0.changed_fields', ['title'])->assertJsonPath('data.activities.0.before', ['title' => 'A', 'tags' => []])->assertJsonPath('data.activities.0.after', [])->assertJsonPath('data.activities.0.reason', null)->assertJsonMissing(['email'])->assertJsonMissing(['storage_path'])->assertJsonMissing(['token'])->assertJsonMissing(['metadata']);
        $page->assertJsonMissing(['actor_user_id'])->assertJsonMissing(['email'])->assertJsonMissing(['actor' => []]);
        DB::table('arsip_digital.audit_logs')->where('action', 'institutional_archive.created')->update(['actor_user_id' => 999]);
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/$id/timeline?per_page=1&page=2")->assertOk()->assertJsonPath('data.activities.0.label', 'Arsip dibuat')->assertJsonPath('data.activities.0.actor_display_name', null)->assertJsonMissing(['actor_user_id']);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$id", ['reason' => 'hapus'])->assertOk();
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/$id/timeline")->assertOk()->assertJsonFragment(['label' => 'Arsip dihapus'])->assertJsonMissing(['action' => 'evil.action']);
    }

    private function upload(array $overrides = []): array
    {
        return $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', array_merge(['file' => $this->pdfUpload(), 'title' => 'Dokumen', 'unit_id' => $this->unitId, 'category_id' => $this->categoryId], $overrides), ['Accept' => 'application/json'])->assertCreated()->json('data.archive');
    }

    private function adminUser(int $id): UserView
    {
        DB::table('users')->insert(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => 1]);
        $user = new UserView;
        $user->setRawAttributes(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => true, 'is_mhs' => false, 'is_dosen' => false, 'is_staff' => false], true);

        return $user;
    }
}
