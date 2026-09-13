<?php

namespace App\Services\ArsipDigital;

use App\Models\KRS\NilaiAkhirView;
use App\Models\Users\MahasiswaView;
use App\Services\KRS\AcademicRecordSummaryService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AcademicDocumentDataService
{
    public function __construct(private readonly AcademicRecordSummaryService $summaryService) {}

    public function documentForStudent(int $mhsId, string $documentType, ?int $semester = null): array
    {
        $snapshot = $this->transcriptForStudent($mhsId);

        if ($documentType === 'khs') {
            $records = collect($snapshot['records'])
                ->where('semester', $semester)
                ->values();
            $snapshot['records'] = $records->all();
            $snapshot['summary'] = $this->summaryService->summarizeNormalized($records);
        }

        if (empty($snapshot['records'])) {
            throw new HttpException(422, 'Data akademik untuk dokumen tidak tersedia.');
        }

        return $snapshot;
    }

    public function transcriptForStudent(int $mhsId): array
    {
        $student = MahasiswaView::query()
            ->where('mhs_id', $mhsId)
            ->first();

        if (! $student) {
            throw new HttpException(404, 'Mahasiswa tidak ditemukan.');
        }

        $sourceRecords = NilaiAkhirView::getNilaiAkhirByMhsId($mhsId);
        $records = $sourceRecords
            ->map(function ($record): array {
                $course = $record->matakuliah;

                return [
                    'mk_id' => $record->mk_id,
                    'kd_mk' => trim((string) ($course->kd_mk ?? '')),
                    'nm_mk' => trim((string) ($course->nm_mk ?? '')),
                    'semester' => $course->semester ?? null,
                    'sks' => $course->sks ?? null,
                    'nilai' => $record->nilai,
                    'mutu' => $record->mutu,
                ];
            })
            ->values()
            ->all();

        return [
            'student' => [
                'mhs_id' => $student->mhs_id,
                'nim' => $student->nim,
                'nama' => trim((string) $student->nm_mhs),
                'angkatan' => $student->masuk_tahun,
                'prodi' => $this->programStudyLabel($student),
                'status' => $student->sts_mhs,
            ],
            'records' => $records,
            'summary' => $this->summaryService->summarize($sourceRecords),
            'source' => [
                'system' => 'simak',
                'dataset' => 'vnilaiakhir',
            ],
        ];
    }

    private function programStudyLabel(object $student): ?string
    {
        $label = $student->nama_jurusan ?? $student->prodi ?? null;
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        $department = $student->jurusan;
        if (is_object($department)) {
            return trim((string) ($department->nama_jurusan ?? $department->nm_jurusan ?? $department->prodi ?? '')) ?: null;
        }

        if (is_array($department)) {
            return trim((string) ($department['nama_jurusan'] ?? $department['nm_jurusan'] ?? $department['prodi'] ?? '')) ?: null;
        }

        return null;
    }
}
