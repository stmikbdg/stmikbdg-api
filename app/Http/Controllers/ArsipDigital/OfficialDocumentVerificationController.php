<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\OfficialDocumentVerificationService;
use Illuminate\Http\Request;

class OfficialDocumentVerificationController extends Controller
{
    public function show(Request $request, string $token, OfficialDocumentVerificationService $verificationService)
    {
        try {
            $payload = $verificationService->publicPayload($verificationService->verify($token));

            if ($request->expectsJson()) {
                return $this->successfulResponseJSON($payload);
            }

            return response($this->html($payload), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function html(array $payload): string
    {
        $valid = $payload['valid'];
        $title = $valid ? 'Dokumen Terverifikasi' : 'Dokumen Tidak Berlaku';
        $color = $valid ? '#166534' : '#991b1b';
        $surface = $valid ? '#f0fdf4' : '#fef2f2';
        $border = $valid ? '#bbf7d0' : '#fecaca';
        $reasonText = $payload['revocation_reason'] ?? $payload['replacement_reason'] ?? null;
        $reason = $reasonText
            ? '<div class="notice"><strong>Alasan:</strong> '.e((string) $reasonText).'</div>'
            : '';
        $replacement = $payload['replaced_by_document_number']
            ? $this->row('Diganti oleh', $payload['replaced_by_document_number'])
            : '';
        $summary = $payload['academic_summary'];
        $signer = $payload['signer'];

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title><style>
            *{box-sizing:border-box}body{font-family:Inter,Arial,sans-serif;background:#f4f4f5;color:#18181b;margin:0;padding:24px}.card{max-width:760px;margin:40px auto;background:#fff;border:1px solid #e4e4e7;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(24,24,27,.08)}.hero{background:'.$surface.';border-bottom:1px solid '.$border.';padding:28px}.eyebrow{color:#52525b;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.hero h1{color:'.$color.';font-size:27px;margin:8px 0}.hero p{color:#52525b;margin:0}.body{padding:28px}.badge{display:inline-flex;background:'.$surface.';border:1px solid '.$border.';border-radius:999px;color:'.$color.';font-size:13px;font-weight:700;padding:7px 12px}.section{border-top:1px solid #e4e4e7;margin-top:24px;padding-top:20px}.section h2{font-size:14px;margin:0 0 14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 28px}.row{border-bottom:1px solid #f4f4f5;padding:9px 0}.label{color:#71717a;display:block;font-size:12px;margin-bottom:3px}.value{font-size:14px;font-weight:600}.notice{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#9a3412;margin-top:18px;padding:12px}.privacy{background:#fafafa;border-radius:8px;color:#52525b;font-size:12px;line-height:1.5;margin-top:20px;padding:12px}details{color:#52525b;font-size:12px;margin-top:18px}code{display:block;margin-top:8px;overflow-wrap:anywhere}.footer{color:#71717a;font-size:12px;padding:0 28px 28px;text-align:center}@media(max-width:600px){body{padding:12px}.card{margin:12px auto}.hero,.body{padding:20px}.grid{grid-template-columns:1fr}}
        </style></head><body><main class="card"><header class="hero"><div class="eyebrow">Verifikasi Dokumen Akademik</div><h1>'.e($title).'</h1><p>Metadata berikut cocok dengan dokumen yang terdaftar pada sistem Arsip Digital STMIK Bandung.</p></header><section class="body"><span class="badge">'.e((string) $payload['status_label']).'</span>'.$reason.'<div class="section"><h2>Identitas Dokumen</h2><div class="grid">'.$this->row('Jenis dokumen', $payload['document_type_label']).$this->row('Nomor dokumen', $payload['document_number']).$this->row('Diterbitkan', $payload['issued_at_label']).$this->row('Cakupan semester', $payload['semester_summary']).$replacement.'</div></div><div class="section"><h2>Mahasiswa</h2><div class="grid">'.$this->row('Nama', $payload['student']['nama']).$this->row('NIM', $payload['student']['nim']).$this->row('Program studi', $payload['student']['program_studi'] ?: '-').$this->row('Angkatan', $payload['student']['angkatan'] ?: '-').'</div></div><div class="section"><h2>Ringkasan Dokumen</h2><div class="grid">'.$this->row('Jumlah mata kuliah', $summary['jumlah_mata_kuliah'] ?? '-').$this->row('Jumlah kredit', $summary['total_sks'] ?? '-').$this->row('Pejabat', $signer['nama'] ?: '-').$this->row('Jabatan', $signer['jabatan'] ?: '-').'</div></div><div class="privacy">Nilai mata kuliah, IPK, dan data pribadi lengkap tidak ditampilkan pada halaman publik untuk melindungi privasi mahasiswa. Keaslian file dapat dicocokkan melalui checksum teknis di bawah.</div><details><summary>Detail teknis dokumen</summary><code>SHA-256: '.e((string) $payload['file_checksum_sha256']).'</code></details></section><footer class="footer">Verifikasi ini menunjukkan status dokumen pada saat halaman dibuka.</footer></main></body></html>';
    }

    private function row(string $label, mixed $value): string
    {
        return '<div class="row"><span class="label">'.e($label).'</span><span class="value">'.e((string) $value).'</span></div>';
    }
}
