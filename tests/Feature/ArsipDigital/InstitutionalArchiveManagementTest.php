<?php

namespace Tests\Feature\ArsipDigital;

use App\Models\Users\UserView;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;

class InstitutionalArchiveManagementTest extends ArsipDigitalFeatureTestCase
{
    private int $unitId;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unitId = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'code' => 'BAAK', 'is_active' => true, 'created_by_user_id' => 1]);
        $this->categoryId = DB::table('arsip_digital.categories')->insertGetId(['name' => 'Surat', 'category_type' => 'institutional', 'visibility' => 'private']);
    }

    public function test_all_management_endpoints_require_admin_active_role(): void
    {
        $id = $this->upload()->json('data.archive.institutional_archive_id');
        $calls = [['getJson', '/api/arsip-digital/admin/institutional-archives'], ['post', '/api/arsip-digital/admin/institutional-archives'], ['getJson', "/api/arsip-digital/admin/institutional-archives/$id"], ['putJson', "/api/arsip-digital/admin/institutional-archives/$id"], ['postJson', "/api/arsip-digital/admin/institutional-archives/$id/move"], ['getJson', "/api/arsip-digital/admin/institutional-archives/$id/preview"], ['getJson', "/api/arsip-digital/admin/institutional-archives/$id/download"]];
        foreach ([$this->mahasiswa, $this->dosen] as $user) {
            foreach ($calls as [$method, $uri]) {
                $this->actingAs($user, 'api')->withHeader('X-Active-Role', $user->id === 2 ? 'mahasiswa' : 'dosen')->{$method}($uri)->assertForbidden()->assertDontSee('storage_path');
            }
        }
        foreach ($calls as [$method, $uri]) {
            $this->actingAs($this->admin, 'api')->withHeader('X-Active-Role', 'mahasiswa')->{$method}($uri)->assertForbidden();
        }
    }

    public function test_upload_creates_private_current_file_checksum_refs_and_safe_payload(): void
    {
        $response = $this->upload(['title' => '  Surat Keputusan  ', 'tags' => [' penting ', 'penting'], 'received_date' => '2026-08-01', 'retention_note' => 'Tetap'])->assertCreated()->assertJsonMissing(['storage_path'])->assertJsonMissing(['storage_disk']);
        $archive = $response->json('data.archive');
        $this->assertSame($archive['current_file_id'], $archive['current_file']['file_id']);
        $this->assertSame('institutional', $archive['current_file']['source_type']);
        $this->assertSame(1, $archive['current_file']['version_number']);
        $this->assertTrue($archive['current_file']['is_current']);
        $this->assertSame(hash('sha256', '%PDF-1.4 institutional'), $archive['current_file']['checksum_sha256']);
        $row = DB::table('arsip_digital.files')->where('file_id', $archive['current_file_id'])->first();
        Storage::disk('s3')->assertExists($row->storage_path);
        $this->assertStringContainsString('/institutional/', $row->storage_path);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_archive.created', 'actor_user_id' => 1]);
    }

    public function test_upload_validation_storage_failure_and_invalid_references_leave_no_rows(): void
    {
        $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', [])->assertSessionHasNoErrors()->assertStatus(422);
        $this->upload(['unit_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $official = DB::table('arsip_digital.categories')->insertGetId(['name' => 'Official', 'category_type' => 'official']);
        $this->upload(['category_id' => $official])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->upload(['file' => UploadedFile::fake()->createWithContent('bad.exe', 'bad')])->assertUnprocessable();
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('put')->andReturnFalse();
        $this->upload()->assertStatus(500);
        $this->assertDatabaseCount('arsip_digital.institutional_archives', 0);
        $this->assertDatabaseCount('arsip_digital.files', 0);
    }

    public function test_audit_failure_rolls_back_upload_and_cleans_object(): void
    {
        DB::statement("CREATE TRIGGER arsip_digital.fail_archive_audit BEFORE INSERT ON audit_logs WHEN NEW.entity_type = 'institutional_archive' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->upload()->assertStatus(500);
        $this->assertDatabaseCount('arsip_digital.institutional_archives', 0);
        $this->assertDatabaseCount('arsip_digital.files', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_cleanup_failure_logs_no_path_or_identifier(): void
    {
        Log::spy();
        $storage = Mockery::mock(ArsipDigitalStorageService::class);
        $storage->shouldReceive('uploadPrivate')->andReturn(['storage_disk' => 's3', 'storage_path' => 'secret/object/key.pdf', 'original_filename' => 'x.pdf', 'display_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'file_size_bytes' => 4, 'checksum_sha256' => 'x']);
        $storage->shouldReceive('deletePrivate')->andThrow(new \RuntimeException('cleanup fail secret/object/key.pdf'));
        $this->app->instance(ArsipDigitalStorageService::class, $storage);
        DB::statement("CREATE TRIGGER arsip_digital.fail_archive_create BEFORE INSERT ON institutional_archives BEGIN SELECT RAISE(FAIL, 'db failed'); END");
        $this->upload()->assertStatus(500);
        Log::shouldHaveReceived('error')->with('Institutional archive upload cleanup failed.', Mockery::on(fn ($context) => $context === ['disk' => 's3', 'exception' => \RuntimeException::class]));
    }

    public function test_delete_false_logs_safe_cleanup_failure_and_preserves_original_failure(): void
    {
        Log::spy();
        Storage::shouldReceive('disk')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturnTrue();
        Storage::shouldReceive('delete')->once()->andReturnFalse();
        DB::statement("CREATE TRIGGER arsip_digital.fail_archive_create_false_delete BEFORE INSERT ON institutional_archives BEGIN SELECT RAISE(FAIL, 'original database failure'); END");

        $response = $this->upload()->assertStatus(500)->assertDontSee('storage_path')->assertDontSee('storage_disk')->assertDontSee('original database failure');
        $this->assertSame('Terjadi masalah pada database. Pastikan migrasi sudah dijalankan atau hubungi administrator.', $response->json('message'));
        Log::shouldHaveReceived('error')->with('Institutional archive upload cleanup failed.', Mockery::on(fn ($context) => $context === ['disk' => 's3', 'exception' => \RuntimeException::class]));
        $this->assertDatabaseCount('arsip_digital.institutional_archives', 0);
        $this->assertDatabaseCount('arsip_digital.files', 0);
    }

    public function test_document_number_rules_and_self_update(): void
    {
        $this->upload(['document_number' => null])->assertCreated();
        $this->upload(['document_number' => null, 'title' => 'Tanpa Nomor 2'])->assertCreated();
        $first = $this->upload(['document_number' => ' 001 / BAAK ', 'document_year' => 2026])->assertCreated()->json('data.archive');
        $this->upload(['document_number' => '001/baak', 'document_year' => 2026])->assertUnprocessable()->assertJsonValidationErrors('document_number');
        $other = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Keuangan', 'is_active' => true, 'created_by_user_id' => 1]);
        $this->upload(['document_number' => '001/BAAK', 'document_year' => 2026, 'unit_id' => $other])->assertCreated();
        $this->upload(['document_number' => '001/BAAK', 'document_year' => 2027])->assertCreated();
        $this->upload(['document_number' => 'X'])->assertUnprocessable()->assertJsonValidationErrors('document_year');
        $this->upload(['document_number' => 'Y', 'document_year' => 2025, 'document_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('document_year');
        $this->actingAsAdmin()->putJson('/api/arsip-digital/admin/institutional-archives/'.$first['institutional_archive_id'], ['document_number' => ' 001 / BAAK ', 'document_year' => 2026])->assertOk();
    }

    public function test_list_is_bounded_searchable_filterable_sorted_and_rejects_query_injection(): void
    {
        $this->upload(['title' => 'Zeta Rahasia', 'document_number' => 'Z-1', 'document_year' => 2026, 'access_level' => 'restricted'])->assertCreated();
        $this->upload(['title' => 'Alpha', 'description' => 'needle'])->assertCreated();
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?search=needle&per_page=1')->assertOk()->assertJsonCount(1, 'data.archives')->assertJsonPath('data.archives.0.title', 'Alpha')->assertJsonMissing(['storage_path']);
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives?unit_id=$this->unitId&category_id=$this->categoryId&document_year=2026&access_level=restricted&storage_availability=available&uploader_id=1&sort=title&direction=asc")->assertOk()->assertJsonPath('data.archives.0.title', 'Zeta Rahasia');
        foreach (['per_page=101', 'sort=title%3Bdrop%20table', 'direction=desc%20nulls%20first', 'unit_id=1%20or%201=1'] as $query) {
            $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?'.$query)->assertUnprocessable();
        }
    }

    public function test_cross_admin_detail_update_move_preview_download_and_generic_isolation(): void
    {
        $archive = $this->upload()->json('data.archive');
        $second = $this->adminUser(9);
        $category = DB::table('arsip_digital.categories')->insertGetId(['name' => 'Pindah', 'category_type' => 'institutional']);
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->getJson('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'])->assertOk()->assertJsonMissing(['storage_path']);
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->putJson('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'], ['title' => 'Diubah Admin B'])->assertOk()->assertJsonPath('data.archive.updated_by_user_id', 9);
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->postJson('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'].'/move', ['category_id' => $category])->assertOk()->assertJsonPath('data.archive.category_id', $category);
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->get('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'].'/preview')->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('x-content-type-options', 'nosniff');
        $this->actingAs($second, 'api')->withHeader('X-Active-Role', 'admin')->get('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'].'/download')->assertOk()->assertHeader('content-disposition', 'attachment; filename=dokumen.pdf');
        $this->actingAsAdmin()->getJson('/api/arsip-digital/files/'.$archive['current_file_id'])->assertForbidden()->assertDontSee('storage_path');
        $this->actingAsAdmin()->getJson('/api/arsip-digital/files')->assertOk()->assertJsonCount(0, 'data.files');
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/files')->assertOk()->assertJsonCount(0, 'data.files');
    }

    public function test_preview_download_headers_audit_and_secrecy_are_exact(): void
    {
        $archive = $this->upload()->json('data.archive');
        $base = '/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'];
        $this->actingAsAdmin()->get($base.'/preview')->assertOk()
            ->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition', 'inline; filename="dokumen.pdf"')
            ->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff');
        $this->assertDatabaseMissing('arsip_digital.audit_logs', ['action' => 'institutional_archive.downloaded_by_admin']);
        $this->actingAsAdmin()->get($base.'/download')->assertOk()
            ->assertHeader('content-type', 'application/pdf')->assertHeader('content-disposition', 'attachment; filename=dokumen.pdf')
            ->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff');
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_archive.downloaded_by_admin', 'actor_user_id' => 1, 'entity_id' => (string) $archive['institutional_archive_id']]);
        foreach ([$this->actingAsAdmin()->getJson($base), $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives')] as $response) {
            $response->assertDontSee('storage_path')->assertDontSee('storage_disk')->assertDontSee('arsip-digital/testing');
        }
    }

    public function test_each_list_filter_sort_direction_and_stable_pagination_hits_endpoint(): void
    {
        $root = $this->upload(['title' => 'Root', 'category_id' => null, 'document_date' => '2024-01-02'])->assertCreated()->json('data.archive');
        $large = $this->upload(['title' => 'Beta', 'document_date' => '2026-02-03', 'access_level' => 'restricted', 'file' => $this->pdfUpload('large.pdf', '%PDF-1.4 much larger institutional file')])->assertCreated()->json('data.archive');
        DB::table('arsip_digital.files')->where('file_id', $root['current_file_id'])->update(['storage_availability' => 'missing', 'uploaded_by_user_id' => 9]);
        DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $root['institutional_archive_id'])->update(['created_at' => '2024-01-01 00:00:00']);
        DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $large['institutional_archive_id'])->update(['created_at' => '2026-01-01 00:00:00']);
        $cases = [
            ['unit_id='.$this->unitId, 2], ['category_id='.$this->categoryId, 1], ['category_id=root', 1], ['document_year=2024', 1],
            ['access_level=restricted', 1], ['storage_availability=missing', 1], ['uploader_id=9', 1],
        ];
        foreach ($cases as [$query, $count]) {
            $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?'.$query)->assertOk()->assertJsonCount($count, 'data.archives');
        }
        $expected = [
            'document_date' => [$root['institutional_archive_id'], $large['institutional_archive_id']],
            'created_at' => [$root['institutional_archive_id'], $large['institutional_archive_id']],
            'title' => [$large['institutional_archive_id'], $root['institutional_archive_id']],
            'file_size' => [$root['institutional_archive_id'], $large['institutional_archive_id']],
        ];
        foreach ($expected as $sort => [$ascendingId, $descendingId]) {
            $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives?sort=$sort&direction=asc&per_page=1")->assertOk()->assertJsonPath('data.archives.0.institutional_archive_id', $ascendingId);
            $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives?sort=$sort&direction=desc&per_page=1")->assertOk()->assertJsonPath('data.archives.0.institutional_archive_id', $descendingId);
        }
        $pageOne = $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?sort=title&direction=asc&per_page=1&page=1')->assertOk();
        $pageTwo = $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?sort=title&direction=asc&per_page=1&page=2')->assertOk();
        $this->assertNotSame($pageOne->json('data.archives.0.institutional_archive_id'), $pageTwo->json('data.archives.0.institutional_archive_id'));
        foreach (['sort=created_at%3Bdrop', 'direction=asc%20nulls%20last', 'category_id=1%20or%201=1'] as $query) {
            $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-archives?'.$query)->assertUnprocessable()->assertDontSee('storage_path')->assertDontSee('storage_disk');
        }
    }

    public function test_missing_object_has_no_download_audit_and_update_move_audit_failures_rollback(): void
    {
        $archive = $this->upload()->json('data.archive');
        $row = DB::table('arsip_digital.files')->where('file_id', $archive['current_file_id'])->first();
        Storage::disk('s3')->delete(DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $row->file_id)->value('storage_path'));
        $this->actingAsAdmin()->get('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'].'/download')->assertNotFound()->assertDontSee('storage_path')->assertDontSee('storage_disk')->assertDontSee($row->storage_path);
        $this->assertDatabaseMissing('arsip_digital.audit_logs', ['action' => 'institutional_archive.downloaded_by_admin']);
        DB::statement("CREATE TRIGGER arsip_digital.fail_archive_change BEFORE INSERT ON audit_logs WHEN NEW.action IN ('institutional_archive.metadata_updated','institutional_archive.moved') BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->actingAsAdmin()->putJson('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'], ['title' => 'Rollback'])->assertStatus(500);
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'].'/move', ['category_id' => null])->assertStatus(500);
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $archive['institutional_archive_id'], 'title' => 'Dokumen', 'category_id' => $this->categoryId]);
    }

    private function upload(array $overrides = [])
    {
        $response = $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', array_merge(['file' => $this->pdfUpload('dokumen.pdf', '%PDF-1.4 institutional'), 'title' => 'Dokumen', 'unit_id' => $this->unitId, 'category_id' => $this->categoryId], $overrides), ['Accept' => 'application/json']);
        if ($response->getStatusCode() === 201) {
            $this->markInstitutionalVerificationReady($response->json('data.archive.current_file_id'));
        }

        return $response;
    }

    private function adminUser(int $id): UserView
    {
        DB::table('users')->insert(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => 1]);
        $user = new UserView;
        $user->setRawAttributes(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => true, 'is_mhs' => false, 'is_dosen' => false, 'is_staff' => false], true);

        return $user;
    }
}
