<?php

namespace Tests\Feature\ArsipDigital;

use App\Console\Commands\BackfillInstitutionalArchiveVerifications;
use App\Jobs\ArsipDigital\GenerateInstitutionalVerifiedPdfJob;
use App\Models\ArsipDigital\InstitutionalArchiveVerification;
use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class InstitutionalArchiveVerificationTest extends ArsipDigitalFeatureTestCase
{
    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://127.0.0.1:8001']);
        $this->unitId = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'is_active' => true, 'created_by_user_id' => 1]);
    }

    public function test_upload_preserves_master_and_queues_hash_only_registry(): void
    {
        Queue::fake();
        $upload = $this->importablePdfUpload('master.pdf', 'Master Byte Identical');
        $bytes = file_get_contents($upload->getRealPath());
        $archive = $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', ['file' => $upload, 'title' => 'SK Akademik', 'unit_id' => $this->unitId], ['Accept' => 'application/json'])->assertCreated()->json('data.archive');
        $file = DB::table('arsip_digital.files')->where('file_id', $archive['current_file_id'])->first();
        $registry = DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->first();

        $this->assertSame($bytes, Storage::disk('s3')->get($file->storage_path));
        $this->assertSame(hash('sha256', $bytes), $file->checksum_sha256);
        $this->assertSame('pending', $registry->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $registry->token_hash);
        $this->assertStringNotContainsString('http://127.0.0.1:8001', json_encode($registry));
        Queue::assertPushed(GenerateInstitutionalVerifiedPdfJob::class, fn ($job) => ! str_contains(serialize($job), $registry->token_hash));
    }

    public function test_process_creates_different_verified_pdf_with_configured_url_and_is_idempotent(): void
    {
        Queue::fake();
        [$archive, $file] = $this->uploadImportable();
        $token = str_repeat('ab', 32);
        DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->update(['token_hash' => hash('sha256', $token)]);
        $service = app(InstitutionalArchiveVerificationService::class);
        $service->process(DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->value('institutional_archive_verification_id'), $token);
        $registry = DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->first();
        $verified = Storage::disk($registry->storage_disk)->get($registry->storage_path);

        $this->assertSame('ready', $registry->status);
        $this->assertStringStartsWith('%PDF', $verified);
        $this->assertNotSame($file->checksum_sha256, $registry->verified_checksum_sha256);
        $this->assertSame(hash('sha256', $verified), $registry->verified_checksum_sha256);
        $this->assertSame('http://127.0.0.1:8001', config('app.url'));
        $objects = Storage::disk('s3')->allFiles();
        $service->process($registry->institutional_archive_verification_id, $token);
        $after = DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->first();
        $this->assertSame($registry->storage_path, $after->storage_path);
        $this->assertSame($registry->verified_checksum_sha256, $after->verified_checksum_sha256);
        $this->assertSame($objects, Storage::disk('s3')->allFiles());
        $this->assertSame($archive['current_file_id'], $file->file_id);
    }

    public function test_process_does_not_duplicate_work_already_claimed_by_same_token(): void
    {
        Queue::fake();
        [, $file] = $this->uploadImportable();
        $token = str_repeat('ac', 32);
        $registry = InstitutionalArchiveVerification::where('source_file_id', $file->file_id)->firstOrFail();
        $registry->update(['status' => 'processing', 'token_hash' => hash('sha256', $token)]);
        $objects = Storage::disk('s3')->allFiles();

        app(InstitutionalArchiveVerificationService::class)->process($registry->getKey(), $token);

        $registry->refresh();
        $this->assertSame('processing', $registry->status);
        $this->assertNull($registry->storage_path);
        $this->assertNull($registry->verified_checksum_sha256);
        $this->assertSame($objects, Storage::disk('s3')->allFiles());
    }

    public function test_public_json_html_whitelist_headers_and_unknown_tokens(): void
    {
        Queue::fake();
        [, $file] = $this->uploadImportable();
        $token = str_repeat('cd', 32);
        DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->update(['token_hash' => hash('sha256', $token)]);
        $url = "/api/arsip-digital/institutional-verify/$token";
        $json = $this->getJson($url)->assertOk()->assertHeader('cache-control', 'no-store, private')->assertHeader('referrer-policy', 'no-referrer')->assertHeader('x-robots-tag', 'noindex, nofollow')->assertHeader('x-content-type-options', 'nosniff')->assertJsonMissing(['token_hash', 'storage_path', 'issuer_user_id', 'institutional_archive_id', 'description', 'tags', 'owner_user_id', 'uploaded_by_user_id']);
        $this->assertSame(['valid', 'status', 'status_label', 'title', 'document_number', 'document_year', 'document_date', 'received_date', 'unit_name', 'category_name', 'access_level', 'access_level_label', 'version_number', 'display_filename', 'mime_type', 'extension', 'file_size_bytes', 'uploaded_at', 'uploader', 'archived_at', 'archive_creator', 'issued_at', 'issuer', 'processed_at', 'source_checksum_sha256', 'verified_checksum_sha256', 'replacement_version_number'], array_keys($json->json('data')));
        $json->assertJsonPath('data.title', 'SK Akademik')->assertJsonPath('data.unit_name', 'Akademik')->assertJsonPath('data.category_name', 'Tanpa Folder')->assertJsonPath('data.access_level', 'internal')->assertJsonPath('data.access_level_label', 'Internal')->assertJsonPath('data.display_filename', 'master.pdf')->assertJsonPath('data.mime_type', 'application/pdf')->assertJsonPath('data.extension', 'PDF')->assertJsonPath('data.uploader', 'Admin #1 - Admin Test')->assertJsonPath('data.archive_creator', 'Admin #1 - Admin Test')->assertJsonPath('data.issuer', 'Admin #1 - Admin Test');
        $this->assertIsInt($json->json('data.file_size_bytes'));
        $this->get($url, ['Accept' => 'text/html'])->assertOk()->assertHeader('content-security-policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'")->assertSee('Verifikasi Arsip Institusional')->assertSee('Identitas Dokumen')->assertSee('Informasi File')->assertSee('Penerbitan &amp; Verifikasi', false)->assertSee('Diupload oleh')->assertSee('Detail teknis dokumen')->assertSee('@media(max-width:600px)', false);
        $this->getJson('/api/arsip-digital/institutional-verify/'.str_repeat('ef', 32))->assertNotFound();
        $this->getJson('/api/arsip-digital/institutional-verify/not-hex')->assertNotFound();
    }

    public function test_admin_download_uses_derivative_and_master_endpoint_is_exact_and_private(): void
    {
        Queue::fake();
        [$archive, $file, $master] = $this->uploadImportable(true);
        $verified = '%PDF-1.4 verified-only';
        $this->markInstitutionalVerificationReady($file->file_id, $verified);
        $base = '/api/arsip-digital/admin/institutional-archives/'.$archive['institutional_archive_id'];

        $this->actingAsAdmin()->get($base.'/download')->assertOk()->assertStreamedContent($verified);
        $this->actingAsAdmin()->get($base.'/preview-verified')->assertOk()->assertStreamedContent($verified);
        $this->actingAsAdmin()->get($base.'/download-master')->assertOk()->assertStreamedContent($master);
        foreach ([$base.'/download', $base.'/preview-verified', $base.'/download-master', $base.'/verification/retry'] as $url) {
            ($url === $base.'/verification/retry' ? $this->actingAsMahasiswa()->postJson($url) : $this->actingAsMahasiswa()->get($url))->assertForbidden();
        }
    }

    public function test_pending_publish_rejected_ready_recipient_receives_derivative(): void
    {
        Queue::fake();
        [$archive, $file, $master] = $this->uploadImportable(true);
        $draft = $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/{$archive['institutional_archive_id']}/distributions", ['title' => 'Distribusi', 'target_role' => 'mahasiswa', 'scope_type' => 'specific', 'target_identifiers' => ['22010001']])->assertCreated()->json('data.distribution.distribution_id');
        $targetsUrl = "/api/arsip-digital/admin/institutional-distributions/$draft/targets";
        $targets = $this->actingAsAdmin()->getJson($targetsUrl)->json('data');
        $payload = ['source_file_id' => $file->file_id, 'expected_updated_at' => $targets['updated_at'], 'target_fingerprint' => $targets['target_fingerprint']];
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $payload)->assertStatus(425);
        $verified = '%PDF-1.4 recipient-verified';
        $this->markInstitutionalVerificationReady($file->file_id, $verified);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $payload)->assertOk();
        $recipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->value('recipient_id');
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient/download")->assertOk()->assertStreamedContent($verified);
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient/preview")->assertOk()->assertHeader('content-type', 'application/pdf')->assertStreamedContent($verified);
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-files/$file->file_id/preview")->assertOk()->assertHeader('content-type', 'application/pdf')->assertStreamedContent($verified);
        $this->assertStringNotContainsString($master, $verified);
    }

    public function test_version_replacement_delete_revoke_and_restore_does_not_reactivate(): void
    {
        Queue::fake();
        [$archive, $old] = $this->uploadImportable();
        $oldToken = str_repeat('12', 32);
        DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $old->file_id)->update(['token_hash' => hash('sha256', $oldToken)]);
        $new = $this->actingAsAdmin()->post("/api/arsip-digital/admin/institutional-archives/{$archive['institutional_archive_id']}/versions", ['file' => $this->importablePdfUpload('v2.pdf', 'V2'), 'reason' => 'Koreksi'], ['Accept' => 'application/json'])->assertCreated()->json('data.archive.current_file_id');
        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $old->file_id, 'status' => 'replaced']);
        $this->getJson("/api/arsip-digital/institutional-verify/$oldToken")->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.status', 'replaced');
        $this->get("/api/arsip-digital/institutional-verify/$oldToken", ['Accept' => 'text/html'])->assertOk()->assertSee('Arsip Tidak Berlaku')->assertSee('Diganti versi baru')->assertSee('Versi 2 tersedia pada registry.');
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/{$archive['institutional_archive_id']}", ['reason' => 'Dicabut'])->assertOk();
        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $new, 'status' => 'revoked']);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/{$archive['institutional_archive_id']}/restore")->assertOk();
        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $new, 'status' => 'revoked']);
    }

    public function test_checksum_mismatch_fails_without_derivative_and_terminal_race_stays_terminal(): void
    {
        Queue::fake();
        [, $file] = $this->uploadImportable();
        $registry = InstitutionalArchiveVerification::where('source_file_id', $file->file_id)->firstOrFail();
        $token = str_repeat('34', 32);
        DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $file->file_id)->update(['token_hash' => hash('sha256', $token)]);
        $master = Storage::disk('s3')->get($file->storage_path);
        Storage::disk('s3')->put($file->storage_path, 'tampered');
        try {
            app(InstitutionalArchiveVerificationService::class)->process($registry->getKey(), $token);
            $this->fail('Checksum mismatch must fail.');
        } catch (\Throwable) {
            $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $file->file_id, 'status' => 'failed']);
        }
        Storage::disk('s3')->put($file->storage_path, $master);
        Queue::fake();
        $oldToken = $token;
        $response = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-archives/'.$file->institutional_archive_id.'/verification/retry')
            ->assertAccepted()
            ->assertJsonPath('data.verification.status', 'pending')
            ->assertJsonStructure(['data' => ['verification' => ['verification_id', 'status', 'failure_message', 'source_checksum_sha256', 'verified_checksum_sha256', 'version_number', 'issuer_name', 'issued_at', 'processed_at']]]);
        $this->getJson("/api/arsip-digital/institutional-verify/$oldToken")->assertNotFound();
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-archives/'.$file->institutional_archive_id.'/verification/retry')->assertStatus(409);
        app(InstitutionalArchiveVerificationService::class)->process($response->json('data.verification.verification_id'), $oldToken);
        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $file->file_id, 'status' => 'pending', 'failure_message' => null]);
        Queue::assertPushed(GenerateInstitutionalVerifiedPdfJob::class, function ($job) use ($response): bool {
            app(InstitutionalArchiveVerificationService::class)->process($response->json('data.verification.verification_id'), \Illuminate\Support\Facades\Crypt::decryptString($job->encryptedToken));

            return true;
        });
        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', ['source_file_id' => $file->file_id, 'status' => 'ready']);
    }

    public function test_creator_uses_canonical_dosen_name(): void
    {
        Queue::fake();
        DB::table('users')->where('id', 1)->update(['kd_user' => 'DSN-IF054']);
        DB::table('dosen')->insert(['kd_dosen' => 'IF054', 'nm_dosen' => 'Mina Ismu Rahayu, M.T']);
        $this->admin->setRawAttributes(array_merge($this->admin->getAttributes(), ['kd_user' => 'DSN-IF054']), true);
        [, $file] = $this->uploadImportable();

        $this->assertDatabaseHas('arsip_digital.institutional_archive_verifications', [
            'source_file_id' => $file->file_id,
            'issuer_name_snapshot' => 'Mina Ismu Rahayu, M.T',
        ]);
    }

    public function test_backfill_marks_pdf_pending_non_pdf_unsupported_and_is_idempotent(): void
    {
        Queue::fake();
        $archive = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => $this->unitId, 'title' => 'Backfill', 'created_by_user_id' => 1]);
        foreach ([['pdf', 'application/pdf'], ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']] as $index => [$extension, $mime]) {
            DB::table('arsip_digital.files')->insert(['institutional_archive_id' => $archive, 'owner_user_id' => 1, 'owner_role' => 'admin', 'owner_identifier' => '1', 'uploaded_by_user_id' => 1, 'uploaded_by_role' => 'admin', 'source_type' => 'institutional', 'original_filename' => "x.$extension", 'display_filename' => "x.$extension", 'storage_disk' => 's3', 'storage_path' => "backfill/$index", 'mime_type' => $mime, 'extension' => $extension, 'file_size_bytes' => 4, 'checksum_sha256' => hash('sha256', 'test'), 'version_group_uuid' => fake()->uuid(), 'version_number' => $index + 1, 'is_current' => $index === 0, 'status' => $index === 0 ? 'active' : 'replaced', 'storage_availability' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->artisan(BackfillInstitutionalArchiveVerifications::class, ['--chunk' => 1])->assertSuccessful();
        $this->assertSame(['pending', 'unsupported'], DB::table('arsip_digital.institutional_archive_verifications')->orderBy('source_file_id')->pluck('status')->all());
        $this->artisan(BackfillInstitutionalArchiveVerifications::class)->assertSuccessful();
        $this->assertDatabaseCount('arsip_digital.institutional_archive_verifications', 2);
    }

    private function uploadImportable(bool $withMaster = false): array
    {
        $upload = $this->importablePdfUpload('master.pdf', 'Master Archive');
        $master = file_get_contents($upload->getRealPath());
        $archive = $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', ['file' => $upload, 'title' => 'SK Akademik', 'unit_id' => $this->unitId], ['Accept' => 'application/json'])->assertCreated()->json('data.archive');
        $file = \App\Models\ArsipDigital\ArchiveFile::findOrFail($archive['current_file_id']);

        return $withMaster ? [$archive, $file, $master] : [$archive, $file];
    }
}
