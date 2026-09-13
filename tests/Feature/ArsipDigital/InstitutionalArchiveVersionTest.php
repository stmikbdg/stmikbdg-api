<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InstitutionalArchiveVersionTest extends ArsipDigitalFeatureTestCase
{
    private int $archiveId;

    private array $originalS3Config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalS3Config = config('filesystems.disks.s3');
        Storage::forgetDisk('s3');
        Storage::fake('s3');
        $unit = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'is_active' => true, 'created_by_user_id' => 1]);
        $this->archiveId = $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', [
            'file' => $this->pdfUpload('awal.pdf', '%PDF-1.4 awal'), 'title' => 'Dokumen', 'unit_id' => $unit,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.archive.institutional_archive_id');
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('s3');
        config(['filesystems.disks.s3' => $this->originalS3Config]);
        parent::tearDown();
    }

    public function test_version_endpoints_require_admin_and_reason_and_existing_validation(): void
    {
        $base = "/api/arsip-digital/admin/institutional-archives/{$this->archiveId}/versions";
        foreach ([$this->actingAsMahasiswa(), $this->actingAsDosen()] as $client) {
            $client->getJson($base)->assertForbidden();
            $client->post($base, [])->assertForbidden();
        }
        $this->actingAsAdmin()->post($base, ['file' => $this->pdfUpload('baru.pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsAdmin()->post($base, ['file' => UploadedFile::fake()->createWithContent('bad.exe', 'bad'), 'reason' => 'Revisi'], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAsAdmin()->getJson($base.'?per_page=101')->assertUnprocessable();
    }

    public function test_upload_preserves_group_retires_old_updates_pointer_audits_and_keeps_objects(): void
    {
        $old = DB::table('arsip_digital.files')->where('institutional_archive_id', $this->archiveId)->first();
        $response = $this->uploadVersion('baru.pdf', '%PDF-1.4 baru', ' Koreksi isi ')->assertCreated()->assertJsonMissing(['storage_path', 'storage_disk']);
        $new = $response->json('data.archive.current_file');
        $this->assertSame(2, $new['version_number']);
        $this->assertSame($old->version_group_uuid, $new['version_group_uuid']);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $old->file_id, 'is_current' => 0, 'status' => 'replaced']);
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $this->archiveId, 'current_file_id' => $new['file_id']]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_archive.file_version_uploaded', 'entity_id' => (string) $this->archiveId]);
        $this->assertCount(2, Storage::disk('s3')->allFiles());
    }

    public function test_history_is_bounded_ordered_paginated_and_explicitly_safe(): void
    {
        $this->uploadVersion('dua.pdf', '%PDF-1.4 dua', 'Dua')->assertCreated();
        $this->uploadVersion('tiga.pdf', '%PDF-1.4 tiga', 'Tiga')->assertCreated();
        $response = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/{$this->archiveId}/versions?per_page=2&page=1")->assertOk()
            ->assertJsonPath('data.versions.0.version_number', 3)->assertJsonPath('data.versions.0.version_reason', 'Tiga')
            ->assertJsonPath('data.versions.1.version_number', 2)->assertJsonPath('data.pagination.total', 3)
            ->assertJsonMissing(['metadata', 'storage_path', 'storage_disk', 'deleted_at', 'delete_reason', 'owner_identifier']);
        $allowed = ['file_id', 'display_filename', 'mime_type', 'extension', 'file_size_bytes', 'checksum_sha256', 'version_number', 'is_current', 'status', 'storage_availability', 'uploaded_by_user_id', 'created_at', 'version_reason', 'verification'];
        $this->assertSame($allowed, array_keys($response->json('data.versions.0')));
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-archives/{$this->archiveId}/versions?per_page=2&page=2")->assertJsonPath('data.versions.0.version_number', 1);
    }

    public function test_exact_historical_download_checks_ownership_missing_object_and_audits_without_fallback(): void
    {
        $old = DB::table('arsip_digital.files')->where('institutional_archive_id', $this->archiveId)->first();
        $new = $this->uploadVersion('baru.pdf', '%PDF-1.4 berbeda', 'Revisi')->assertCreated()->json('data.archive.current_file');
        $base = "/api/arsip-digital/admin/institutional-archives/{$this->archiveId}/versions";
        $this->actingAsAdmin()->get($base.'/'.$old->file_id.'/download')->assertOk()->assertHeader('content-disposition', 'attachment; filename=awal.pdf');
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_archive.downloaded_by_admin', 'entity_id' => (string) $this->archiveId]);
        $other = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => 1, 'title' => 'Lain', 'created_by_user_id' => 1]);
        $this->actingAsAdmin()->get("/api/arsip-digital/admin/institutional-archives/$other/versions/{$old->file_id}/download")->assertNotFound();
        $row = DB::table('arsip_digital.files')->where('file_id', $new['file_id'])->first();
        $auditCount = DB::table('arsip_digital.audit_logs')->where('action', 'institutional_archive.downloaded_by_admin')->count();
        $this->assertSame('s3', $row->storage_disk);
        $this->assertTrue(Storage::disk($row->storage_disk)->exists($row->storage_path));
        $this->assertTrue(Storage::disk($row->storage_disk)->delete($row->storage_path));
        $this->assertFalse(Storage::disk($row->storage_disk)->exists($row->storage_path));
        $this->actingAsAdmin()->get($base.'/'.$new['file_id'].'/download')->assertNotFound()->assertDontSee($old->storage_path);
        $this->assertSame($auditCount, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_archive.downloaded_by_admin')->count());
    }

    public function test_database_or_audit_failure_rolls_back_state_and_cleans_only_new_object(): void
    {
        $old = DB::table('arsip_digital.files')->where('institutional_archive_id', $this->archiveId)->first();
        DB::statement("CREATE TRIGGER arsip_digital.fail_version_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'institutional_archive.file_version_uploaded' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->uploadVersion('gagal.pdf', '%PDF-1.4 gagal', 'Gagal')->assertStatus(500);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $old->file_id, 'is_current' => 1, 'status' => 'active']);
        $this->assertDatabaseCount('arsip_digital.files', 1);
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_generic_file_show_download_delete_and_personal_upload_cannot_bypass_institutional_contract(): void
    {
        $file = DB::table('arsip_digital.files')->where('institutional_archive_id', $this->archiveId)->value('file_id');
        $this->actingAsAdmin()->getJson("/api/arsip-digital/files/$file")->assertForbidden();
        $this->actingAsAdmin()->get("/api/arsip-digital/files/$file/download")->assertForbidden();
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/files/$file", ['reason' => 'bypass'])->assertForbidden();
        $this->actingAsMahasiswa()->post('/api/arsip-digital/files', ['file' => $this->pdfUpload(), 'institutional_archive_id' => $this->archiveId], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseCount('arsip_digital.files', 2);
        $this->assertSame(1, DB::table('arsip_digital.files')->whereNotNull('institutional_archive_id')->count());
    }

    private function uploadVersion(string $name, string $content, string $reason)
    {
        return $this->actingAsAdmin()->post("/api/arsip-digital/admin/institutional-archives/{$this->archiveId}/versions", ['file' => $this->pdfUpload($name, $content), 'reason' => $reason], ['Accept' => 'application/json']);
    }
}
