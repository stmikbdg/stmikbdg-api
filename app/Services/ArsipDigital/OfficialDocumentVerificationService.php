<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\OfficialDocument;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfficialDocumentVerificationService
{
    public function verify(string $token): OfficialDocument
    {
        if ($token === '' || strlen($token) !== 64 || ! ctype_xdigit($token)) {
            throw new HttpException(404, 'Dokumen tidak ditemukan.');
        }

        $document = OfficialDocument::with(['file', 'replacedBy'])
            ->where('verification_token_hash', hash('sha256', $token))
            ->first();

        if (! $document) {
            throw new HttpException(404, 'Dokumen tidak ditemukan.');
        }

        return $document;
    }

    public function publicPayload(OfficialDocument $document): array
    {
        $snapshot = $document->academic_snapshot ?? [];
        $student = $snapshot['student'] ?? [];
        $summary = $snapshot['summary'] ?? [];

        return [
            'valid' => $document->isVerifiable(),
            'status' => $document->status,
            'status_label' => $this->statusLabel($document->status),
            'document_type' => $document->document_type,
            'document_type_label' => $this->documentTypeLabel($document->document_type),
            'document_number' => $document->document_number,
            'semester' => $document->semester,
            'semester_summary' => $this->semesterSummary($document),
            'student' => [
                'nim' => $this->maskIdentifier($document->subject_identifier),
                'nama' => $document->subject_name_snapshot,
                'program_studi' => $student['prodi'] ?? null,
                'angkatan' => $student['angkatan'] ?? null,
            ],
            'academic_summary' => [
                'jumlah_mata_kuliah' => $summary['jumlah_mata_kuliah'] ?? count($snapshot['records'] ?? []),
                'total_sks' => $summary['total_sks'] ?? null,
            ],
            'signer' => [
                'nama' => $document->signer_name_snapshot,
                'jabatan' => $document->signer_title_snapshot,
            ],
            'issued_at' => $document->issued_at?->toIso8601String(),
            'issued_at_label' => $document->issued_at ? $this->indonesianDateTime($document->issued_at) : null,
            'revoked_at' => $document->revoked_at?->toIso8601String(),
            'revocation_reason' => $document->status === 'revoked' ? $document->revocation_reason : null,
            'replacement_reason' => $document->status === 'replaced' ? $document->replacement_reason : null,
            'replaced_by_document_number' => $document->status === 'replaced' ? $document->replacedBy?->document_number : null,
            'file_checksum_sha256' => $document->file_checksum_sha256,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'issued' => 'Aktif — sudah diterbitkan',
            'revoked' => 'Dicabut',
            'replaced' => 'Diganti',
            default => ucfirst($status),
        };
    }

    private function documentTypeLabel(string $documentType): string
    {
        return match ($documentType) {
            'transcript' => 'Transkrip Nilai',
            'khs' => 'Kartu Hasil Studi',
            default => ucfirst($documentType),
        };
    }

    private function semesterSummary(OfficialDocument $document): string
    {
        if ($document->document_type === 'khs') {
            return $document->semester ? 'Semester '.$document->semester : '-';
        }

        $semesters = collect($document->academic_snapshot['records'] ?? [])
            ->pluck('semester')
            ->filter(fn ($semester) => $semester !== null && $semester !== '')
            ->map(fn ($semester) => (int) $semester)
            ->unique()
            ->sort()
            ->values();

        if ($semesters->isEmpty()) {
            return '-';
        }

        $first = $semesters->first();
        $last = $semesters->last();
        $isContinuous = $semesters->count() === ($last - $first + 1);

        return $isContinuous && $semesters->count() > 1
            ? 'Semester '.$first.'–'.$last
            : 'Semester '.$semesters->implode(', ');
    }

    private function indonesianDateTime(object $date): string
    {
        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        return $date->format('d').' '.$months[(int) $date->format('n')].' '.$date->format('Y, H:i').' WIB';
    }

    private function maskIdentifier(string $identifier): string
    {
        $visible = substr($identifier, -4);

        return str_repeat('*', max(0, strlen($identifier) - 4)).$visible;
    }
}
