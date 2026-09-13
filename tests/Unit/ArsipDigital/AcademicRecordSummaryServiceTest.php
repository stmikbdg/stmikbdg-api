<?php

namespace Tests\Unit\ArsipDigital;

use App\Services\KRS\AcademicRecordSummaryService;
use Tests\TestCase;

class AcademicRecordSummaryServiceTest extends TestCase
{
    public function test_summary_matches_existing_simak_ip_contract(): void
    {
        $records = collect([
            ['nilai' => 'A', 'mutu' => 4, 'matakuliah' => ['sks' => 3]],
            ['nilai' => 'B', 'mutu' => 3, 'matakuliah' => ['sks' => 2]],
        ]);

        $this->assertSame([
            'jumlah_mata_kuliah' => 2,
            'total_sks' => 5,
            'total_semua_ip' => 3.5,
            'total_nilai_a' => 1,
            'total_nilai_b' => 1,
            'total_nilai_c' => 0,
            'total_nilai_d' => 0,
            'total_nilai_e' => 0,
        ], app(AcademicRecordSummaryService::class)->summarize($records));
    }
}
