<?php

namespace App\Services\ArsipDigital;

use Com\Tecnick\Pdf\Tcpdf;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfficialDocumentPdfService
{
    public const TEMPLATE_VERSION = 'official-academic-v2';

    private const TRANSCRIPT_ROWS_PER_COLUMN = 38;

    private const TRANSCRIPT_TABLE_Y = 42.0;

    public function render(
        string $documentType,
        string $documentNumber,
        array $snapshot,
        ?int $semester = null,
        ?string $verificationUrl = null,
        bool $preview = false,
        ?array $signer = null
    ): string {
        $records = collect($snapshot['records'] ?? []);
        if ($documentType === 'khs') {
            $records = $records->where('semester', $semester)->values();
        }

        if ($records->isEmpty()) {
            throw new HttpException(422, 'Data akademik untuk dokumen tidak tersedia.');
        }

        $student = $snapshot['student'] ?? [];
        $pdf = $this->newPdf(
            $documentType === 'transcript' ? 'TRANSKRIP NILAI' : 'KARTU HASIL STUDI SEMESTER '.(int) $semester,
            $this->filename($documentType, (string) ($student['nim'] ?? ''), $semester)
        );

        if ($documentType === 'transcript') {
            $this->renderTranscript($pdf, $documentNumber, $snapshot, $records->all(), $verificationUrl, $preview, $signer);
        } else {
            $this->renderKhs($pdf, $documentNumber, $snapshot, $records->all(), $semester, $verificationUrl, $preview, $signer);
        }

        $bytes = $pdf->getOutPDFString();
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new HttpException(500, 'Renderer gagal menghasilkan PDF valid.');
        }

        return $bytes;
    }

    public function filename(string $documentType, string $nim, ?int $semester = null): string
    {
        $suffix = $documentType === 'khs' ? '-semester-'.(int) $semester : '';

        return strtoupper($documentType).'-'.$nim.$suffix.'.pdf';
    }

    private function newPdf(string $title, string $filename): Tcpdf
    {
        $fontPath = resource_path('pdf-fonts');
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontPath);
        }

        $pdf = new Tcpdf(fileOptions: ['allowedPaths' => [$fontPath]]);
        $pdf->setTitle($title);
        $pdf->setSubject('Dokumen Akademik Resmi');
        $pdf->setPDFFilename($filename);

        return $pdf;
    }

    private function renderTranscript(
        Tcpdf $pdf,
        string $documentNumber,
        array $snapshot,
        array $records,
        ?string $verificationUrl,
        bool $preview,
        ?array $signer
    ): void {
        $pages = array_chunk($records, self::TRANSCRIPT_ROWS_PER_COLUMN * 2);
        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 8);

        foreach ($pages as $pageIndex => $pageRecords) {
            $pdf->addPage(['format' => 'A4', 'orientation' => 'P']);
            $pdf->page->addContent($font['out']);
            $isLastPage = $pageIndex === array_key_last($pages);
            $splitAt = count($pageRecords) < self::TRANSCRIPT_ROWS_PER_COLUMN * 2
                ? (int) ceil(count($pageRecords) / 2)
                : self::TRANSCRIPT_ROWS_PER_COLUMN;
            $left = array_slice($pageRecords, 0, $splitAt);
            $right = array_slice($pageRecords, $splitAt);

            $pdf->addHTMLCell(
                html: $this->transcriptHeader($documentNumber, $snapshot['student'] ?? [], $preview),
                posx: 10,
                posy: 9,
                width: 190,
            );
            $pdf->addHTMLCell(
                html: $this->transcriptTable($left),
                posx: 10,
                posy: self::TRANSCRIPT_TABLE_Y,
                width: 93,
            );
            $pdf->addHTMLCell(
                html: $this->transcriptTable($right),
                posx: 107,
                posy: self::TRANSCRIPT_TABLE_Y,
                width: 93,
            );

            if ($isLastPage) {
                $this->renderTranscriptFooter(
                    $pdf,
                    $snapshot['summary'] ?? [],
                    $verificationUrl,
                    $preview,
                    $signer,
                    $this->transcriptFooterY($left, $right),
                );
            }
        }
    }

    private function transcriptHeader(string $documentNumber, array $student, bool $preview): string
    {
        $banner = $preview
            ? '<div style="border:1.5px solid #b91c1c;color:#b91c1c;font-size:7pt;font-weight:bold;text-align:center;padding:3px">PREVIEW — BELUM DITERBITKAN</div>'
            : '';

        return sprintf(
            '<div style="font-family:dejavusans">%s<div style="font-size:15pt;font-weight:bold;text-align:center;margin-top:4px">TRANSKRIP NILAI</div><div style="font-size:7pt;text-align:center;margin-bottom:5px">Nomor: %s</div><table style="font-size:7.5pt;width:100%%"><tr><td width="12%%">Nama</td><td width="38%%">: <b>%s</b></td><td width="16%%">Program Studi</td><td width="34%%">: <b>%s</b></td></tr><tr><td>NIM</td><td>: <b>%s</b></td><td>Angkatan</td><td>: %s</td></tr></table></div>',
            $banner,
            e($documentNumber),
            e((string) ($student['nama'] ?? '-')),
            e((string) ($student['prodi'] ?? '-')),
            e((string) ($student['nim'] ?? '-')),
            e((string) ($student['angkatan'] ?? '-')),
        );
    }

    private function transcriptTable(array $records): string
    {
        $rows = collect($records)->map(function (array $record): string {
            return sprintf(
                '<tr><td width="18%%">%s</td><td width="60%%">%s</td><td width="11%%" style="text-align:center">%s</td><td width="11%%" style="text-align:center"><b>%s</b></td></tr>',
                e((string) ($record['kd_mk'] ?? '-')),
                e((string) ($record['nm_mk'] ?? '-')),
                e((string) ($record['sks'] ?? '-')),
                e((string) ($record['nilai'] ?? '-')),
            );
        })->implode('');

        return '<table border="1" cellpadding="1.4" style="font-family:dejavusans;font-size:6.4pt;width:100%;border-collapse:collapse"><thead><tr style="font-weight:bold;background-color:#eeeeee;text-align:center"><th width="18%">Kode</th><th width="60%">Mata Kuliah</th><th width="11%">SKS</th><th width="11%">Nilai</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function transcriptFooterY(array $left, array $right): float
    {
        $leftHeight = $this->transcriptColumnHeight($left);
        $rightHeight = $this->transcriptColumnHeight($right);
        $tableBottom = self::TRANSCRIPT_TABLE_Y + max($leftHeight, $rightHeight);

        return min(190.0, max(92.0, $tableBottom + 8));
    }

    private function transcriptColumnHeight(array $records): float
    {
        $rowUnits = collect($records)->sum(function (array $record): int {
            $nameLength = mb_strlen((string) ($record['nm_mk'] ?? ''));

            return max(1, (int) ceil($nameLength / 42));
        });

        return 4 + ($rowUnits * 2.75);
    }

    private function renderTranscriptFooter(
        Tcpdf $pdf,
        array $summary,
        ?string $verificationUrl,
        bool $preview,
        ?array $signer,
        float $footerY = 225.0
    ): void {
        $statistics = sprintf(
            '<table style="font-family:dejavusans;font-size:8pt"><tr><td width="52%%">Jumlah Mata Kuliah</td><td>: <b>%s</b></td></tr><tr><td>Jumlah Kredit Kumulatif</td><td>: <b>%s</b></td></tr><tr><td>Indeks Prestasi Kumulatif</td><td>: <b>%s</b></td></tr></table>',
            e((string) ($summary['jumlah_mata_kuliah'] ?? '-')),
            e((string) ($summary['total_sks'] ?? '-')),
            isset($summary['total_semua_ip']) ? number_format((float) $summary['total_semua_ip'], 2) : '-',
        );
        $pdf->addHTMLCell(html: $statistics, posx: 12, posy: $footerY + 3, width: 85);

        $signerName = e((string) ($signer['name'] ?? '-'));
        $signerTitle = e((string) ($signer['title'] ?? 'Ketua Program Studi'));
        $pdf->addHTMLCell(
            html: '<div style="font-family:dejavusans;font-size:7.5pt;text-align:center">Bandung, '.e($this->indonesianDate()).'</div>',
            posx: 127,
            posy: $footerY,
            width: 70,
        );

        if ($verificationUrl !== null) {
            $pdf->page->addContent($pdf->getBarcode(
                type: 'QRCODE,H',
                code: $verificationUrl,
                posx: 150,
                posy: $footerY + 7,
                width: 25,
                height: 25,
                padding: [0, 0, 0, 0],
            ));
        } else {
            $pdf->addHTMLCell(
                html: '<div style="border:1px solid #777;color:#777;font-family:dejavusans;font-size:6pt;text-align:center;padding:10px">QR VERIFIKASI<br>SETELAH DITERBITKAN</div>',
                posx: 147,
                posy: $footerY + 7,
                width: 31,
            );
        }

        $notice = $preview ? 'PREVIEW — BELUM DITERBITKAN' : 'Dokumen terverifikasi QR';
        $pdf->addHTMLCell(
            html: '<div style="font-family:dejavusans;font-size:7pt;text-align:center"><b>'.$signerName.'</b><br>'.$signerTitle.'<br><span style="font-size:6pt;color:#666">'.e($notice).'</span></div>',
            posx: 121,
            posy: $footerY + 34,
            width: 82,
        );
    }

    private function indonesianDate(): string
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
        $date = now();

        return $date->format('d').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }

    private function renderKhs(
        Tcpdf $pdf,
        string $documentNumber,
        array $snapshot,
        array $records,
        ?int $semester,
        ?string $verificationUrl,
        bool $preview,
        ?array $signer
    ): void {
        $student = $snapshot['student'] ?? [];
        $rows = collect($records)->map(function (array $record, int $index): string {
            return sprintf(
                '<tr><td>%d</td><td>%s</td><td>%s</td><td style="text-align:center">%s</td><td style="text-align:center"><b>%s</b></td></tr>',
                $index + 1,
                e((string) ($record['kd_mk'] ?? '-')),
                e((string) ($record['nm_mk'] ?? '-')),
                e((string) ($record['sks'] ?? '-')),
                e((string) ($record['nilai'] ?? '-')),
            );
        })->implode('');
        $banner = $preview
            ? '<div style="border:2px solid #b91c1c;color:#b91c1c;font-weight:bold;text-align:center;padding:5px">PREVIEW — BELUM DITERBITKAN</div>'
            : '';
        $html = sprintf(
            '<div style="font-family:dejavusans">%s<h1 style="font-size:15pt;text-align:center">KARTU HASIL STUDI SEMESTER %d</h1><p style="font-size:8pt;text-align:center">Nomor: %s</p><table style="font-size:8pt"><tr><td width="20%%">Nama</td><td>: <b>%s</b></td></tr><tr><td>NIM</td><td>: %s</td></tr><tr><td>Program Studi</td><td>: %s</td></tr></table><br><table border="1" cellpadding="3" style="font-size:8pt;width:100%%"><thead><tr style="font-weight:bold;background-color:#eee;text-align:center"><th width="7%%">No</th><th width="16%%">Kode</th><th width="55%%">Mata Kuliah</th><th width="11%%">SKS</th><th width="11%%">Nilai</th></tr></thead><tbody>%s</tbody></table></div>',
            $banner,
            (int) $semester,
            e($documentNumber),
            e((string) ($student['nama'] ?? '-')),
            e((string) ($student['nim'] ?? '-')),
            e((string) ($student['prodi'] ?? '-')),
            $rows,
        );

        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 9);
        $pdf->addPage(['format' => 'A4', 'orientation' => 'P']);
        $pdf->page->addContent($font['out']);
        $pdf->addHTMLCell(html: $html, posx: 15, posy: 12, width: 180);
        $this->renderTranscriptFooter($pdf, $snapshot['summary'] ?? [], $verificationUrl, $preview, $signer);
    }
}
