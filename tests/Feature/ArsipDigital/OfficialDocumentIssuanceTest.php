<?php

namespace Tests\Feature\ArsipDigital;

use App\Services\ArsipDigital\AcademicDocumentDataService;
use App\Services\ArsipDigital\OfficialDocumentPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;

class OfficialDocumentIssuanceTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_can_issue_official_transcript_from_simak_snapshot(): void
    {
        $this->mockAcademicSnapshot(1);

        $documentId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents', [
                'document_type' => 'transcript',
                'document_number' => 'TRX/2026/001',
                'mhs_id' => 99,
                'signer_user_id' => 3,
                'signer_title' => 'Ketua Program Studi',
            ])
            ->assertCreated()
            ->assertJsonPath('data.document.document_type', 'transcript')
            ->assertJsonPath('data.document.document_number', 'TRX/2026/001')
            ->assertJsonPath('data.document.subject_identifier', '22010001')
            ->assertJsonPath('data.document.semester_summary', 'Semester 1, 2')
            ->assertJsonPath('data.document.course_count', 2)
            ->assertJsonPath('data.document.signer_name_snapshot', 'Dosen Test, M.T.')
            ->assertJsonPath('data.document.signer_title_snapshot', 'Ketua Program Studi')
            ->json('data.document.official_document_id');

        $document = DB::table('arsip_digital.official_documents')->where('official_document_id', $documentId)->first();
        $file = DB::table('arsip_digital.files')->where('file_id', $document->file_id)->first();

        $this->assertSame('issued', $document->status);
        $this->assertSame('official', $file->source_type);
        $this->assertSame($document->file_checksum_sha256, $file->checksum_sha256);
        Storage::disk($file->storage_disk)->assertExists($file->storage_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk($file->storage_disk)->get($file->storage_path));

        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'action' => 'official_document.issued',
            'entity_type' => 'official_document',
            'entity_id' => (string) $documentId,
        ]);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents/'.$documentId)
            ->assertOk()
            ->assertJsonPath('data.document.file.checksum_sha256', $file->checksum_sha256);
    }

    public function test_official_issuance_is_limited_to_transcript(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents', [
                'document_type' => 'khs',
                'document_number' => 'KHS/2026/001',
                'mhs_id' => 99,
                'semester' => 2,
                'signer_user_id' => 3,
                'signer_title' => 'Ketua Program Studi',
            ])
            ->assertUnprocessable();
    }

    public function test_document_number_is_unique_and_non_admin_is_forbidden(): void
    {
        $this->mockAcademicSnapshot(2);
        $payload = [
            'document_type' => 'transcript',
            'document_number' => 'TRX/2026/002',
            'mhs_id' => 99,
            'signer_user_id' => 3,
            'signer_title' => 'Ketua Program Studi',
        ];

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/academic-documents', $payload)->assertCreated();
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/academic-documents', $payload)->assertStatus(409);

        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/admin/academic-documents')->assertForbidden();
        $this->actingAsDosen()->postJson('/api/arsip-digital/admin/academic-documents', $payload)->assertForbidden();
    }

    public function test_admin_gets_editable_incremented_number_suggestion_per_document_type(): void
    {
        $this->mockAcademicSnapshot(1);
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/academic-documents', [
            'document_type' => 'transcript',
            'document_number' => 'TRX/2026/009',
            'mhs_id' => 99,
            'signer_user_id' => 3,
            'signer_title' => 'Ketua Program Studi',
        ])->assertCreated();

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents/next-number?document_type=transcript')
            ->assertOk()
            ->assertJsonPath('data.document_number', 'TRX/2026/010');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents/next-number?document_type=khs')
            ->assertUnprocessable();

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents?document_type=transcript&status=issued')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.documents.0.document_number', 'TRX/2026/009');
        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents?document_type=khs&status=issued')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents/signers')
            ->assertOk()
            ->assertJsonPath('data.signers.0.user_id', 3)
            ->assertJsonPath('data.signers.0.name', 'Dosen Test, M.T.');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/admin/academic-documents/next-number?document_type=transcript')
            ->assertForbidden();
    }

    public function test_admin_can_preview_before_issuance_and_preview_or_download_issued_pdf(): void
    {
        $snapshot = $this->academicSnapshot();
        $service = Mockery::mock(AcademicDocumentDataService::class);
        $service->shouldReceive('documentForStudent')->twice()->with(99, 'transcript', null)->andReturn($snapshot);
        $this->app->instance(AcademicDocumentDataService::class, $service);

        $draftPreview = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/academic-documents/preview', [
            'document_type' => 'transcript',
            'document_number' => 'TRX/PREVIEW/001',
            'mhs_id' => 99,
            'signer_user_id' => 3,
            'signer_title' => 'Ketua Program Studi',
        ])->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $draftPreview->getContent());

        $documentId = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/academic-documents', [
            'document_type' => 'transcript',
            'document_number' => 'TRX/PREVIEW/001',
            'mhs_id' => 99,
            'signer_user_id' => 3,
            'signer_title' => 'Ketua Program Studi',
        ])->assertCreated()->json('data.document.official_document_id');

        $preview = $this->actingAsAdmin()
            ->get('/api/arsip-digital/admin/academic-documents/'.$documentId.'/preview')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $preview->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $preview->streamedContent());

        $download = $this->actingAsAdmin()
            ->get('/api/arsip-digital/admin/academic-documents/'.$documentId.'/download')
            ->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('content-disposition'));

        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/admin/academic-documents/'.$documentId.'/preview')
            ->assertForbidden();
        $this->actingAsDosen()
            ->get('/api/arsip-digital/admin/academic-documents/'.$documentId.'/download')
            ->assertForbidden();
    }

    public function test_transcript_renderer_uses_a4_two_column_multipage_layout(): void
    {
        $records = collect(range(1, 90))->map(fn (int $index) => [
            'mk_id' => $index,
            'kd_mk' => 'IF'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'nm_mk' => 'Mata Kuliah Pengujian '.$index,
            'semester' => (int) ceil($index / 8),
            'sks' => 2,
            'nilai' => 'A',
            'mutu' => 4,
        ])->all();
        $snapshot = $this->academicSnapshot();
        $snapshot['records'] = $records;
        $snapshot['summary'] = ['jumlah_mata_kuliah' => 90, 'total_sks' => 180, 'total_semua_ip' => 4.0];

        $pdf = app(OfficialDocumentPdfService::class)->render(
            'transcript',
            'TRX/LAYOUT/001',
            $snapshot,
            null,
            'https://example.test/verify/'.str_repeat('a', 64),
            false,
            ['user_id' => 3, 'name' => 'Dosen Test, M.T.', 'title' => 'Ketua Program Studi'],
        );

        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertStringContainsString('/MediaBox [0.000000 0.000000 595.276000 841.890000]', $pdf);
    }

    private function mockAcademicSnapshot(int $calls): void
    {
        $service = Mockery::mock(AcademicDocumentDataService::class);
        $service->shouldReceive('documentForStudent')->times($calls)->with(99, Mockery::type('string'), Mockery::any())->andReturnUsing(function (int $mhsId, string $documentType, ?int $semester): array {
            $snapshot = $this->academicSnapshot();
            if ($documentType === 'khs') {
                $snapshot['records'] = collect($snapshot['records'])->where('semester', $semester)->values()->all();
            }

            return $snapshot;
        });
        $this->app->instance(AcademicDocumentDataService::class, $service);
    }

    private function academicSnapshot(): array
    {
        return [
            'student' => [
                'mhs_id' => 99,
                'nim' => '22010001',
                'nama' => 'Mahasiswa Test',
                'angkatan' => '2022',
                'prodi' => 'TI',
                'status' => 'A',
            ],
            'records' => [
                ['mk_id' => 10, 'kd_mk' => 'TI101', 'nm_mk' => 'Algoritma', 'semester' => 1, 'sks' => 3, 'nilai' => 'A', 'mutu' => 4],
                ['mk_id' => 20, 'kd_mk' => 'TI201', 'nm_mk' => 'Basis Data', 'semester' => 2, 'sks' => 3, 'nilai' => 'B', 'mutu' => 3],
            ],
            'summary' => ['jumlah_mata_kuliah' => 2, 'total_sks' => 6, 'total_semua_ip' => 3.5],
            'source' => ['system' => 'simak', 'dataset' => 'vnilaiakhir'],
        ];
    }
}
