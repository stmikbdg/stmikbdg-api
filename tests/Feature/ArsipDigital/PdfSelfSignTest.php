<?php

namespace Tests\Feature\ArsipDigital;

use App\Jobs\ArsipDigital\FinalizePdfSignSessionJob;
use App\Models\ArsipDigital\PdfSignSession;
use App\Services\ArsipDigital\PdfSelfSignService;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class PdfSelfSignTest extends ArsipDigitalFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_admin_can_create_from_small_pdf_and_bom_whitespace_prefix(): void
    {
        foreach (["%PDF-1.4\n%%EOF", "\xEF\xBB\xBF \n\t%PDF-1.4\n%%EOF"] as $content) {
            $this->actingAsAdmin()->post('/api/arsip-digital/pdf-sign-sessions', [
                'file' => UploadedFile::fake()->createWithContent('source.pdf', $content),
            ])->assertCreated();
        }
    }

    public function test_dynamic_limit_and_invalid_header_return_precise_errors(): void
    {
        $settings = json_decode(DB::table('arsip_digital.settings')->where('key', 'archive_defaults')->value('value'), true);
        $settings['default_max_file_size_mb'] = 1;
        DB::table('arsip_digital.settings')->where('key', 'archive_defaults')->update(['value' => json_encode($settings)]);

        $this->actingAsAdmin()->post('/api/arsip-digital/pdf-sign-sessions', [
            'file' => UploadedFile::fake()->createWithContent('large.pdf', '%PDF-'.str_repeat('x', 1024 * 1024)),
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', 'PDF sumber melebihi batas 1MB.');

        $this->actingAsAdmin()->post('/api/arsip-digital/pdf-sign-sessions', [
            'file' => UploadedFile::fake()->createWithContent('invalid.pdf', 'not a pdf'),
        ])->assertUnprocessable()->assertJsonPath('message', 'Header PDF sumber tidak valid.');
    }

    public function test_storage_read_failure_is_generic_and_does_not_expose_path(): void
    {
        $fileId = $this->createActiveArchiveFileForMahasiswa('secret-source.pdf');
        Storage::disk('s3')->delete('arsip-digital/testing/source/secret-source.pdf');

        $this->actingAsMahasiswa()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId])
            ->assertStatus(410)
            ->assertJsonPath('message', 'PDF sumber tidak tersedia.')
            ->assertJsonMissingExact(['message' => 'arsip-digital/testing/source/secret-source.pdf']);
    }

    public function test_unknown_source_is_checked_and_marked_available(): void
    {
        $fileId = $this->ownedPdf();
        DB::table('arsip_digital.files')->where('file_id', $fileId)->update(['storage_availability' => 'unknown']);

        $this->actingAsMahasiswa()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId])->assertCreated();
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $fileId, 'storage_availability' => 'available']);
    }

    public function test_unknown_missing_source_is_marked_missing(): void
    {
        $fileId = $this->createActiveArchiveFileForMahasiswa('unknown-missing.pdf');
        DB::table('arsip_digital.files')->where('file_id', $fileId)->update(['storage_availability' => 'unknown']);
        Storage::disk('s3')->delete('arsip-digital/testing/source/unknown-missing.pdf');

        $this->actingAsMahasiswa()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId])->assertStatus(410);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $fileId, 'storage_availability' => 'missing']);
    }

    public function test_mahasiswa_can_only_create_session_from_owned_pdf(): void
    {
        $fileId = $this->ownedPdf();

        $this->actingAsMahasiswa()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId])
            ->assertCreated()
            ->assertJsonPath('data.session.source_file_id', $fileId);

        $this->actingAsDosen()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId])
            ->assertForbidden();
    }

    public function test_admin_must_upload_valid_pdf_and_cannot_save_to_archive(): void
    {
        $response = $this->actingAsAdmin()->post('/api/arsip-digital/pdf-sign-sessions', [
            'file' => UploadedFile::fake()->createWithContent('source.pdf', "%PDF-1.4\n%%EOF"),
        ]);
        $response->assertCreated();
        $sessionId = $response->json('data.session.sign_session_id');

        $this->actingAsAdmin()->postJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/save")
            ->assertForbidden();
    }

    public function test_finalize_dispatches_job_and_sets_processing_status(): void
    {
        Queue::fake();
        $sessionId = $this->createAdminSession($this->validPdf());

        $this->actingAsAdmin()->postJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/finalize", $this->textPlacement())
            ->assertStatus(202)
            ->assertJsonPath('data.session.status', 'queued');

        Queue::assertPushed(FinalizePdfSignSessionJob::class, fn ($job) => $job->sessionId === $sessionId);
        Storage::disk('s3')->assertExists("arsip-digital/tmp/pdf-sign/{$sessionId}/payload/placements.json");
    }

    public function test_finalize_job_marks_session_finalized_and_cleans_payload(): void
    {
        Queue::fake();
        $sessionId = $this->createAdminSession($this->validPdf());
        $this->actingAsAdmin()->postJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/finalize", $this->textPlacement())->assertStatus(202);

        app(PdfSelfSignService::class)->processFinalize($sessionId);

        $session = PdfSignSession::findOrFail($sessionId);
        $this->assertSame('finalized', $session->status);
        $this->assertNotNull($session->result_sha256);
        Storage::disk('s3')->assertExists("arsip-digital/tmp/pdf-sign/{$sessionId}/result.pdf");
        Storage::disk('s3')->assertMissing("arsip-digital/tmp/pdf-sign/{$sessionId}/payload");
        $this->actingAsAdmin()->getJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}")
            ->assertOk()->assertJsonPath('data.session.status', 'finalized');
    }

    public function test_finalize_exception_marks_failed_logs_and_propagates(): void
    {
        Queue::fake();
        Log::spy();
        $sessionId = $this->createAdminSession($this->validPdf());
        $this->actingAsAdmin()->postJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/finalize", $this->textPlacement())->assertStatus(202);
        Storage::disk('s3')->delete("arsip-digital/tmp/pdf-sign/{$sessionId}/source.pdf");

        try {
            app(PdfSelfSignService::class)->processFinalize($sessionId);
            $this->fail('Finalize exception was not propagated.');
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertDatabaseHas('arsip_digital.pdf_sign_sessions', [
            'sign_session_id' => $sessionId,
            'status' => 'failed',
        ]);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Pemrosesan PDF self-sign gagal.'
            && $context['session_id'] === $sessionId
            && $context['phase'] === 'finalize'
            && $context['exception'] instanceof \Throwable
            && ! isset($context['payload'])
            && ! isset($context['signature']));
    }

    private function ownedPdf(): int
    {
        $id = $this->createActiveArchiveFileForMahasiswa();
        $bytes = "%PDF-1.4\n%%EOF";
        Storage::disk('s3')->put('arsip-digital/testing/source/akta.pdf', $bytes);
        DB::table('arsip_digital.files')->where('file_id', $id)->update([
            'file_size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ]);

        return $id;
    }

    private function createAdminSession(string $content): string
    {
        return $this->actingAsAdmin()->post('/api/arsip-digital/pdf-sign-sessions', [
            'file' => UploadedFile::fake()->createWithContent('source.pdf', $content),
        ])->assertCreated()->json('data.session.sign_session_id');
    }

    private function textPlacement(): array
    {
        return ['placements' => [[
            'method' => 'text',
            'text' => 'Signed',
            'page' => 1,
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.2,
            'height' => 0.1,
        ]]];
    }

    private function validPdf(): string
    {
        $pdf = new Tcpdf;
        $pdf->addPage();

        return $pdf->getOutPDFString();
    }

    public function test_object_authorization_and_expiry_cleanup_are_enforced(): void
    {
        $fileId = $this->ownedPdf();
        $create = $this->actingAsMahasiswa()->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => $fileId]);
        $create->assertCreated();
        $sessionId = $create->json('data.session.sign_session_id');

        $this->actingAsDosen()->getJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/download")
            ->assertForbidden();

        DB::table('arsip_digital.pdf_sign_sessions')->where('sign_session_id', $sessionId)->update(['expires_at' => now()->subMinute()]);
        $this->actingAsMahasiswa()->getJson("/api/arsip-digital/pdf-sign-sessions/{$sessionId}/download")
            ->assertStatus(410);
        $this->assertDatabaseMissing('arsip_digital.pdf_sign_sessions', ['sign_session_id' => $sessionId]);
        Storage::disk('local')->assertMissing("arsip-digital/tmp/pdf-sign/{$sessionId}");
    }
}
