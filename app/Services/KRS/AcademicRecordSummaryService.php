<?php

namespace App\Services\KRS;

use Illuminate\Support\Collection;

class AcademicRecordSummaryService
{
    public function summarize(Collection $records): ?array
    {
        return $this->summarizeRecords($records, fn ($record) => $record['matakuliah']['sks']);
    }

    public function summarizeNormalized(Collection $records): ?array
    {
        return $this->summarizeRecords($records, fn (array $record) => $record['sks']);
    }

    private function summarizeRecords(Collection $records, callable $sks): ?array
    {
        if ($records->isEmpty()) {
            return null;
        }

        $gradeCounts = $records->countBy('nilai');

        return [
            'jumlah_mata_kuliah' => $records->count(),
            'total_sks' => $records->sum($sks),
            'total_semua_ip' => (float) $records->sum('mutu') / $records->count(),
            'total_nilai_a' => $gradeCounts['A'] ?? 0,
            'total_nilai_b' => $gradeCounts['B'] ?? 0,
            'total_nilai_c' => $gradeCounts['C'] ?? 0,
            'total_nilai_d' => $gradeCounts['D'] ?? 0,
            'total_nilai_e' => $gradeCounts['E'] ?? 0,
        ];
    }
}
