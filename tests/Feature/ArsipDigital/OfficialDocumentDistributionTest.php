<?php

namespace Tests\Feature\ArsipDigital;

use App\Services\ArsipDigital\AcademicDocumentDataService;
use Illuminate\Support\Facades\DB;
use Mockery;

class OfficialDocumentDistributionTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_distributes_existing_official_file_to_its_student_owner(): void
    {
        $documentId = $this->issueDocument('TRX/DIST/001');
        $fileId = DB::table('arsip_digital.official_documents')->where('official_document_id', $documentId)->value('file_id');
        $fileCount = DB::table('arsip_digital.files')->count();

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents/'.$documentId.'/distribute')
            ->assertCreated()
            ->assertJsonPath('data.distribution.status', 'published')
            ->assertJsonPath('data.distribution.official_document_id', $documentId)
            ->assertJsonPath('data.distribution.recipients.0.file_id', $fileId)
            ->json('data.distribution.distribution_id');

        $this->assertSame($fileCount, DB::table('arsip_digital.files')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'official_document_available',
        ]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'action' => 'official_document.distributed',
            'entity_id' => (string) $documentId,
        ]);

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/distributions')
            ->assertOk()
            ->assertJsonPath('data.distributions.0.distribution_id', $distributionId)
            ->assertJsonPath('data.distributions.0.files.0.file_id', $fileId);

        $this->actingAsDosen()
            ->getJson('/api/arsip-digital/distributions')
            ->assertOk()
            ->assertJsonCount(0, 'data.distributions');

        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/distribution-files/'.$fileId.'/download')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents/'.$documentId.'/distribute')
            ->assertStatus(409);
    }

    public function test_withdrawing_delivery_does_not_revoke_official_document(): void
    {
        $documentId = $this->issueDocument('TRX/DIST/002');
        $fileId = DB::table('arsip_digital.official_documents')->where('official_document_id', $documentId)->value('file_id');
        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents/'.$documentId.'/distribute')
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/withdraw', [
                'reason' => 'Delivery dikirim ulang melalui kanal lain.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('arsip_digital.official_documents', [
            'official_document_id' => $documentId,
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('arsip_digital.files', [
            'file_id' => $fileId,
            'source_type' => 'official',
            'status' => 'active',
            'is_current' => 1,
        ]);
        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/distribution-files/'.$fileId.'/download')
            ->assertStatus(410);
    }

    public function test_revoking_official_document_closes_its_delivery(): void
    {
        $documentId = $this->issueDocument('TRX/DIST/003');
        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents/'.$documentId.'/distribute')
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents/'.$documentId.'/revoke', [
                'reason' => 'Dokumen mengandung data yang salah.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('arsip_digital.distributions', [
            'distribution_id' => $distributionId,
            'status' => 'closed',
        ]);
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'distribution_id' => $distributionId,
            'delivery_status' => 'revoked',
        ]);
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'type' => 'official_document_revoked',
            'entity_id' => $documentId,
        ]);
    }

    private function issueDocument(string $number): int
    {
        $academic = Mockery::mock(AcademicDocumentDataService::class);
        $academic->shouldReceive('documentForStudent')->once()->with(99, 'transcript', null)->andReturn([
            'student' => ['mhs_id' => 99, 'nim' => '22010001', 'nama' => 'Mahasiswa Test', 'angkatan' => '2022', 'prodi' => 'TI', 'status' => 'A'],
            'records' => [['mk_id' => 10, 'kd_mk' => 'TI101', 'nm_mk' => 'Algoritma', 'semester' => 1, 'sks' => 3, 'nilai' => 'A', 'mutu' => 4]],
            'source' => ['system' => 'simak', 'dataset' => 'vnilaiakhir'],
        ]);
        $this->app->instance(AcademicDocumentDataService::class, $academic);

        return $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/academic-documents', [
                'document_type' => 'transcript',
                'document_number' => $number,
                'mhs_id' => 99,
                'signer_user_id' => 3,
                'signer_title' => 'Ketua Program Studi',
            ])
            ->assertCreated()
            ->json('data.document.official_document_id');
    }
}
