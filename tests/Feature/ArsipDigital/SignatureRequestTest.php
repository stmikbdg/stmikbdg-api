<?php

namespace Tests\Feature\ArsipDigital;

use App\Models\ArsipDigital\PdfSignSession;
use App\Models\Users\User;
use App\Services\ArsipDigital\ArchiveFileService;
use App\Services\ArsipDigital\SignatureRequestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SignatureRequestTest extends ArsipDigitalFeatureTestCase
{
    public function test_availability_create_one_active_edit_accept_and_expiry(): void
    {
        $file = $this->createActiveArchiveFileForMahasiswa();
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/signature-requests')->assertOk()->assertJsonPath('data.current_page', 1)->assertJsonCount(0, 'data.data');
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/signature-request-config')->assertOk()->assertJsonPath('data.signature_request_max_files', 10)->assertJsonMissing(['storage_disk' => 's3']);
        $this->actingAsDosen()->putJson('/api/arsip-digital/lecturer/signature-request-availability', ['is_available' => true])->assertOk();
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/signature-request-lecturers')->assertOk()->assertJsonFragment(['id' => 3]);
        $response = $this->actingAsMahasiswa()->postJson('/api/arsip-digital/signature-requests', ['lecturer_user_id' => 3, 'title' => 'Tanda tangan', 'file_ids' => [$file]])->assertSuccessful();
        $id = $response->json('data.signature_request_id');
        $this->actingAsMahasiswa()->postJson('/api/arsip-digital/signature-requests', ['lecturer_user_id' => 3, 'title' => 'Duplikat', 'file_ids' => [$file]])->assertStatus(409);
        $this->actingAsMahasiswa()->putJson("/api/arsip-digital/signature-requests/{$id}", ['title' => 'Baru', 'file_ids' => [$file]])->assertOk()->assertJsonPath('data.title', 'Baru');
        $this->actingAsDosen()->postJson("/api/arsip-digital/lecturer/signature-requests/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAsMahasiswa()->putJson("/api/arsip-digital/signature-requests/{$id}", ['title' => 'Terkunci'])->assertStatus(409);

        DB::table('arsip_digital.signature_requests')->where('signature_request_id', $id)->update(['status' => 'completed']);
        $expired = DB::table('arsip_digital.signature_requests')->insertGetId(['student_user_id' => 2, 'lecturer_user_id' => 3, 'title' => 'Expired', 'status' => 'requested', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('arsip-digital:expire-signature-requests')->assertSuccessful();
        $this->assertDatabaseHas('arsip_digital.signature_requests', ['signature_request_id' => $expired, 'status' => 'expired']);
        $this->assertDatabaseHas('arsip_digital.notifications', ['entity_id' => $expired, 'type' => 'signature_request.expired']);
    }

    public function test_signature_result_uses_request_source_student_folder_and_metadata_without_personal_quota(): void
    {
        DB::table('arsip_digital.settings')->where('key', 'archive_defaults')->update(['value' => json_encode([
            'default_max_file_size_mb' => 10,
            'default_allowed_extensions' => ['pdf'],
            'storage_disk' => 's3',
            'personal_quota_mb_mahasiswa' => 0,
        ])]);
        $student = User::findOrFail(2);
        $lecturer = User::findOrFail(3);
        $archive = app(ArchiveFileService::class)->uploadSignatureRequestResult(
            UploadedFile::fake()->createWithContent('signed-akta.pdf', '%PDF-1.4 signed'),
            $student,
            $lecturer,
            41,
            'Dr. Dosen Test, M.Kom.',
            ['archive_type' => 'Permintaan berkas tanda tangan', 'display_type' => 'Permintaan berkas tanda tangan']
        );

        $this->assertSame('request', $archive->source_type);
        $this->assertSame(2, $archive->owner_user_id);
        $this->assertSame('request_ttd_dr_dosen_test_mkom', $archive->category->name);
        $this->assertSame('Permintaan berkas tanda tangan', $archive->metadata['archive_type']);
        $this->assertSame('Permintaan berkas tanda tangan', $archive->metadata['display_type']);
        $this->assertStringContainsString('/requests/signature-41/2/', $archive->storage_path);
        Storage::disk('s3')->assertExists($archive->storage_path);
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/files')->assertOk()->assertJsonFragment(['file_id' => $archive->file_id, 'source_type' => 'request']);
    }

    public function test_sync_finalized_reads_s3_and_saves_validated_result_locally(): void
    {
        [$session, $path, $bytes] = $this->createFinalizedSignatureRequestSession('%PDF-1.4 signed');

        $file = app(SignatureRequestService::class)->syncFinalized($session, $this->dosen);

        Storage::disk('s3')->assertExists($path);
        Storage::disk('local')->assertExists($file->signed_result_path);
        $this->assertSame($bytes, Storage::disk('local')->get($file->signed_result_path));
        $this->assertSame(hash('sha256', $bytes), $file->result_sha256);
    }

    public function test_sync_finalized_missing_or_invalid_source_fails_generically_without_type_error(): void
    {
        foreach ([null, 'not a pdf'] as $bytes) {
            [$session, $path] = $this->createFinalizedSignatureRequestSession($bytes ?? '%PDF-1.4 missing');
            if ($bytes === null) {
                Storage::disk('s3')->delete($path);
            }

            try {
                app(SignatureRequestService::class)->syncFinalized($session, $this->dosen);
                $this->fail('Expected sync to fail.');
            } catch (HttpException $exception) {
                $this->assertSame(500, $exception->getStatusCode());
                $this->assertSame('Gagal menyimpan hasil tanda tangan request.', $exception->getMessage());
            }
            DB::table('arsip_digital.signature_requests')->where('status', 'draft')->update(['status' => 'completed']);
        }
    }

    public function test_bulk_reject_only_owned_selected_ids(): void
    {
        $first = DB::table('arsip_digital.signature_requests')->insertGetId(['student_user_id' => 2, 'lecturer_user_id' => 3, 'title' => 'One', 'status' => 'requested', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('arsip_digital.signature_requests')->insertGetId(['student_user_id' => 2, 'lecturer_user_id' => 99, 'title' => 'Foreign', 'status' => 'requested', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsDosen()->postJson('/api/arsip-digital/lecturer/signature-requests/bulk', ['ids' => [$first, $foreign], 'action' => 'reject', 'reason' => 'Tidak sesuai'])->assertStatus(422);
        $this->assertDatabaseHas('arsip_digital.signature_requests', ['signature_request_id' => $first, 'status' => 'requested']);
    }

    private function createFinalizedSignatureRequestSession(string $bytes): array
    {
        $sourceFileId = $this->createActiveArchiveFileForMahasiswa();
        $requestId = DB::table('arsip_digital.signature_requests')->insertGetId(['student_user_id' => 2, 'lecturer_user_id' => 3, 'title' => 'Sign', 'status' => 'draft', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $requestFileId = DB::table('arsip_digital.signature_request_files')->insertGetId(['signature_request_id' => $requestId, 'source_file_id' => $sourceFileId, 'source_sha256' => hash('sha256', 'file lama'), 'source_filename' => 'akta.pdf', 'source_size_bytes' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $sessionId = fake()->uuid();
        $path = "arsip-digital/tmp/pdf-sign/{$sessionId}/result.pdf";
        Storage::disk('s3')->put($path, $bytes);
        $session = PdfSignSession::create(['sign_session_id' => $sessionId, 'owner_user_id' => 3, 'owner_role' => 'dosen', 'signature_request_file_id' => $requestFileId, 'source_path' => 'source.pdf', 'result_path' => $path, 'source_sha256' => hash('sha256', 'file lama'), 'result_sha256' => hash('sha256', $bytes), 'original_filename' => 'akta.pdf', 'status' => 'finalized', 'storage_disk' => 's3', 'expires_at' => now()->addHour()]);

        return [$session, $path, $bytes];
    }
}
