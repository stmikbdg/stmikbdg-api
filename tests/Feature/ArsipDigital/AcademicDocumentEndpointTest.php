<?php

namespace Tests\Feature\ArsipDigital;

use App\Services\ArsipDigital\AcademicDocumentDataService;
use Mockery;

class AcademicDocumentEndpointTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_can_read_student_academic_snapshot(): void
    {
        $payload = [
            'student' => [
                'mhs_id' => 2,
                'nim' => '22010001',
                'nama' => 'Mahasiswa Test',
            ],
            'records' => [
                [
                    'mk_id' => 10,
                    'kd_mk' => 'TI101',
                    'nm_mk' => 'Pemrograman',
                    'semester' => 1,
                    'sks' => 3,
                    'nilai' => 'A',
                    'mutu' => 4,
                ],
            ],
            'source' => [
                'system' => 'simak',
                'dataset' => 'vnilaiakhir',
            ],
        ];

        $service = Mockery::mock(AcademicDocumentDataService::class);
        $service->shouldReceive('transcriptForStudent')->once()->with(2)->andReturn($payload);
        $this->app->instance(AcademicDocumentDataService::class, $service);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/academic-documents/students/2/transcript')
            ->assertOk()
            ->assertJsonPath('data.student.nim', '22010001')
            ->assertJsonPath('data.records.0.nilai', 'A')
            ->assertJsonPath('data.source.dataset', 'vnilaiakhir');
    }

    public function test_only_admin_can_read_student_academic_snapshot(): void
    {
        $path = '/api/arsip-digital/admin/academic-documents/students/2/transcript';

        $this->actingAsMahasiswa()->getJson($path)->assertForbidden();
        $this->actingAsDosen()->getJson($path)->assertForbidden();
    }
}
