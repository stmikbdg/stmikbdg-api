<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\InstitutionalArchiveVerification;
use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InstitutionalArchiveVerificationController extends Controller
{
    public function show(Request $request, string $token, InstitutionalArchiveVerificationService $service)
    {
        $verification = $service->publicByToken($token);
        $payload = $this->payload($verification);
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'"];
        if ($request->expectsJson()) {
            return response()->json(['data' => $payload], 200, $headers);
        }

        return response($this->html($payload), 200, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function payload(InstitutionalArchiveVerification $verification): array
    {
        $metadata = $verification->public_metadata;

        return [
            'valid' => $verification->status === 'ready',
            'status' => $verification->status,
            'status_label' => $this->statusLabel($verification->status),
            'title' => $metadata['title'] ?? null,
            'document_number' => $metadata['document_number'] ?? null,
            'document_year' => $metadata['document_year'] ?? null,
            'document_date' => $metadata['document_date'] ?? null,
            'received_date' => $metadata['received_date'] ?? null,
            'unit_name' => $metadata['unit_name'] ?? null,
            'category_name' => $metadata['category_name'] ?? 'Tanpa Folder',
            'access_level' => $metadata['access_level'] ?? null,
            'access_level_label' => match ($metadata['access_level'] ?? null) {
                'internal' => 'Internal', 'restricted' => 'Terbatas', default => '-',
            },
            'version_number' => $verification->version_number,
            'display_filename' => $metadata['display_filename'] ?? null,
            'mime_type' => $metadata['mime_type'] ?? null,
            'extension' => $metadata['extension'] ?? null,
            'file_size_bytes' => $metadata['file_size_bytes'] ?? null,
            'uploaded_at' => $metadata['uploaded_at'] ?? null,
            'uploader' => $metadata['uploader'] ?? null,
            'archived_at' => $metadata['archived_at'] ?? null,
            'archive_creator' => $metadata['archive_creator'] ?? null,
            'issued_at' => $verification->issued_at?->toISOString(),
            'issuer' => $metadata['issuer'] ?? $verification->issuer_name_snapshot,
            'processed_at' => $verification->processed_at?->toISOString(),
            'source_checksum_sha256' => $verification->source_checksum_sha256,
            'verified_checksum_sha256' => $verification->verified_checksum_sha256,
            'replacement_version_number' => $verification->replacement?->version_number,
        ];
    }

    private function html(array $payload): string
    {
        $ready = $payload['status'] === 'ready';
        $invalid = in_array($payload['status'], ['replaced', 'revoked'], true);
        $title = $ready ? 'Arsip Terverifikasi' : ($invalid ? 'Arsip Tidak Berlaku' : $payload['status_label']);
        [$color, $surface, $border] = $ready ? ['#166534', '#f0fdf4', '#bbf7d0'] : ($invalid || $payload['status'] === 'failed' ? ['#991b1b', '#fef2f2', '#fecaca'] : ['#854d0e', '#fefce8', '#fde68a']);
        $notice = match ($payload['status']) {
            'replaced' => '<div class="notice">Versi arsip ini tidak lagi aktif.'.($payload['replacement_version_number'] ? ' Versi '.$payload['replacement_version_number'].' tersedia pada registry.' : ' Versi baru tersedia pada registry.').'</div>',
            'revoked' => '<div class="notice">Verifikasi arsip ini telah dicabut dan tidak lagi berlaku.</div>',
            default => '',
        };

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.e($title).'</title><style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif;background:#f4f4f5;color:#18181b;margin:0;padding:24px}.card{max-width:760px;margin:40px auto;background:#fff;border:1px solid #e4e4e7;border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(24,24,27,.08)}.hero{background:'.$surface.';border-bottom:1px solid '.$border.';padding:28px}.eyebrow{color:#52525b;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.hero h1{color:'.$color.';font-size:27px;margin:8px 0}.hero p{color:#52525b;line-height:1.5;margin:0}.body{padding:28px}.badge{display:inline-flex;background:'.$surface.';border:1px solid '.$border.';border-radius:999px;color:'.$color.';font-size:13px;font-weight:700;padding:7px 12px}.section{border-top:1px solid #e4e4e7;margin-top:24px;padding-top:20px}.section h2{font-size:14px;margin:0 0 14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 28px}.row{border-bottom:1px solid #f4f4f5;padding:9px 0}.label{color:#71717a;display:block;font-size:12px;margin-bottom:3px}.value{font-size:14px;font-weight:600;overflow-wrap:anywhere}.notice{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;color:#9a3412;margin-top:18px;padding:12px}.privacy{background:#fafafa;border-radius:8px;color:#52525b;font-size:12px;line-height:1.5;margin-top:20px;padding:12px}details{color:#52525b;font-size:12px;margin-top:18px}code{display:block;margin-top:8px;overflow-wrap:anywhere}.footer{color:#71717a;font-size:12px;padding:0 28px 28px;text-align:center}@media(max-width:600px){body{padding:12px}.card{margin:12px auto}.hero,.body{padding:20px}.grid{grid-template-columns:1fr}}
</style></head><body><main class="card"><header class="hero"><div class="eyebrow">Verifikasi Arsip Institusional</div><h1>'.e($title).'</h1><p>Status dan metadata aman berikut berasal dari snapshot registry Arsip Digital STMIK Bandung.</p></header><section class="body"><span class="badge">'.e($payload['status_label']).'</span>'.$notice.'<section class="section"><h2>Identitas Dokumen</h2><div class="grid">'.$this->row('Judul', $payload['title']).$this->row('Nomor dokumen', $payload['document_number']).$this->row('Tahun', $payload['document_year']).$this->row('Tanggal dokumen', $this->date($payload['document_date'], false)).$this->row('Tanggal diterima', $this->date($payload['received_date'], false)).$this->row('Unit', $payload['unit_name']).$this->row('Folder', $payload['category_name']).$this->row('Akses', $payload['access_level_label'].' ('.($payload['access_level'] ?: '-').')').$this->row('Versi', $payload['version_number']).'</div></section><section class="section"><h2>Informasi File</h2><div class="grid">'.$this->row('Nama file', $payload['display_filename']).$this->row('Format / MIME', trim(($payload['extension'] ?: '-').' / '.($payload['mime_type'] ?: '-'))).$this->row('Ukuran', $this->size($payload['file_size_bytes'])).$this->row('Diupload', $this->date($payload['uploaded_at'])).$this->row('Diupload oleh', $payload['uploader']).'</div></section><section class="section"><h2>Penerbitan &amp; Verifikasi</h2><div class="grid">'.$this->row('Arsip dibuat', $this->date($payload['archived_at'])).$this->row('Dibuat oleh', $payload['archive_creator']).$this->row('Verifikasi diterbitkan', $this->date($payload['issued_at'])).$this->row('Penerbit', $payload['issuer']).$this->row('Diproses', $this->date($payload['processed_at'])).'</div></section><div class="privacy">Halaman publik ini hanya menampilkan metadata verifikasi yang aman. Lokasi penyimpanan, identitas internal, catatan audit, distribusi, dan data sensitif lain tidak dipublikasikan.</div><details><summary>Detail teknis dokumen</summary><code>SHA-256 master: '.e($payload['source_checksum_sha256']).'</code><code>SHA-256 terverifikasi: '.e($payload['verified_checksum_sha256'] ?: '-').'</code></details></section><footer class="footer">Status ditampilkan sesuai registry saat halaman dibuka.</footer></main></body></html>';
    }

    private function statusLabel(string $status): string
    {
        return ['ready' => 'Terverifikasi', 'replaced' => 'Diganti versi baru', 'revoked' => 'Dicabut', 'pending' => 'Menunggu proses', 'processing' => 'Sedang diproses', 'failed' => 'Pemrosesan gagal', 'unsupported' => 'Format tidak didukung'][$status] ?? 'Status tidak dikenal';
    }

    private function row(string $label, mixed $value): string
    {
        return '<div class="row"><span class="label">'.e($label).'</span><span class="value">'.e((string) ($value ?? '-')).'</span></div>';
    }

    private function date(mixed $value, bool $withTime = true): string
    {
        if (! $value) {
            return '-';
        }
        $date = Carbon::parse($value)->locale('id')->timezone('Asia/Jakarta');

        return $withTime ? $date->translatedFormat('j F Y, H:i').' WIB' : $date->translatedFormat('j F Y');
    }

    private function size(mixed $bytes): string
    {
        if (! is_numeric($bytes)) {
            return '-';
        }
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1, ',', '.').' KB';
        }

        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }
}
