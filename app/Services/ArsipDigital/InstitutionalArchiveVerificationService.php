<?php

namespace App\Services\ArsipDigital;

use App\Jobs\ArsipDigital\GenerateInstitutionalVerifiedPdfJob;
use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\InstitutionalArchive;
use App\Models\ArsipDigital\InstitutionalArchiveVerification;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstitutionalArchiveVerificationService
{
    public function __construct(
        private readonly ArsipDigitalStorageService $storage,
        private readonly AuditLogNoteActorNameResolver $actorNames,
    ) {}

    public function create(InstitutionalArchive $archive, ArchiveFile $file, object $issuer, bool $dispatch = true): InstitutionalArchiveVerification
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($archive, $file, $issuer, $dispatch): InstitutionalArchiveVerification {
            ArchiveFile::whereKey($file->file_id)->lockForUpdate()->firstOrFail();
            $archive->loadMissing(['unit', 'category']);
            $existing = InstitutionalArchiveVerification::where('source_file_id', $file->file_id)->first();
            if ($existing) {
                return $existing;
            }
            $token = bin2hex(random_bytes(32));
            $status = strtolower((string) $file->extension) === 'pdf' ? 'pending' : 'unsupported';
            $identities = [];
            $identity = function (int $userId, ?string $fallbackName = null) use (&$identities): string {
                return $identities[$userId] ??= $this->resolveIdentity($userId, $fallbackName);
            };
            $issuerName = $this->issuerName($issuer);
            $verification = InstitutionalArchiveVerification::create([
                'institutional_archive_id' => $archive->institutional_archive_id,
                'source_file_id' => $file->file_id,
                'token_hash' => hash('sha256', $token),
                'status' => $status,
                'public_metadata' => [
                    'title' => $archive->title,
                    'document_number' => $archive->document_number,
                    'document_year' => $archive->document_year,
                    'document_date' => $archive->document_date?->toDateString(),
                    'received_date' => $archive->received_date?->toDateString(),
                    'unit_name' => $archive->unit?->name,
                    'category_name' => $archive->category?->name ?: 'Tanpa Folder',
                    'access_level' => $archive->access_level ?: 'internal',
                    'display_filename' => basename((string) $file->display_filename),
                    'mime_type' => $file->mime_type,
                    'extension' => strtoupper((string) $file->extension),
                    'file_size_bytes' => $file->file_size_bytes,
                    'uploaded_at' => $file->created_at?->toISOString(),
                    'uploader' => $identity((int) $file->uploaded_by_user_id),
                    'archived_at' => $archive->created_at?->toISOString(),
                    'archive_creator' => $identity((int) $archive->created_by_user_id),
                    'issuer' => $identity((int) $issuer->id, $issuerName),
                ],
                'source_checksum_sha256' => $file->checksum_sha256,
                'version_number' => $file->version_number,
                'issuer_user_id' => $issuer->id,
                'issuer_name_snapshot' => $issuerName,
                'issued_at' => now(),
            ]);
            if ($status === 'pending' && $dispatch) {
                DB::afterCommit(function () use ($verification, $token): void {
                    try {
                        GenerateInstitutionalVerifiedPdfJob::dispatch($verification->getKey(), $token);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            return $verification;
        });
    }

    public function process(int $id, string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $verification = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $tokenHash): ?InstitutionalArchiveVerification {
            $row = InstitutionalArchiveVerification::with(['archive', 'sourceFile'])->whereKey($id)->lockForUpdate()->first();
            if (! $row || ! hash_equals($row->token_hash, $tokenHash)) {
                return null;
            }
            if (! in_array($row->status, ['pending', 'failed'], true)) {
                return null;
            }
            $row->update(['status' => 'processing', 'failure_message' => null]);

            return $row;
        });
        if (! $verification) {
            return;
        }

        $stored = null;
        try {
            $source = Storage::disk($verification->sourceFile->storage_disk)->get($verification->sourceFile->storage_path);
            if (! hash_equals($verification->source_checksum_sha256, hash('sha256', $source))) {
                throw new HttpException(409, 'Checksum master tidak cocok dengan registry.');
            }
            if (! str_starts_with($source, '%PDF')) {
                throw new HttpException(422, 'Source bukan PDF valid.');
            }
            $bytes = $this->render($verification, $source, $token);
            if (! str_starts_with($bytes, '%PDF')) {
                throw new \RuntimeException('Verified output bukan PDF valid.');
            }
            $stored = $this->storage->uploadPrivateBytes($bytes, pathinfo($verification->sourceFile->display_filename, PATHINFO_FILENAME).'-verified.pdf', 'institutional-verified', [
                'archive_id' => $verification->institutional_archive_id,
                'source_file_id' => $verification->source_file_id,
            ], $verification->sourceFile->storage_disk);
            $finalized = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id, $stored, $tokenHash): bool {
                $row = InstitutionalArchiveVerification::whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'processing' || ! hash_equals($row->token_hash, $tokenHash)) {
                    return false;
                }
                $row->update([
                    'status' => 'ready', 'failure_message' => null, 'verified_checksum_sha256' => $stored['checksum_sha256'],
                    'storage_disk' => $stored['storage_disk'], 'storage_path' => $stored['storage_path'], 'original_filename' => $stored['original_filename'],
                    'display_filename' => $stored['display_filename'], 'mime_type' => 'application/pdf', 'file_size_bytes' => $stored['file_size_bytes'], 'processed_at' => now(),
                ]);

                return true;
            });
            if (! $finalized) {
                $this->storage->deletePrivate($stored['storage_disk'], $stored['storage_path']);
            }
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk($stored['storage_disk'])->delete($stored['storage_path']);
            }
            InstitutionalArchiveVerification::whereKey($id)->where('status', 'processing')->where('token_hash', $tokenHash)->update([
                'status' => 'failed',
                'failure_message' => app()->environment('production') ? 'Pemrosesan PDF terverifikasi gagal.' : Str::limit($e->getMessage(), 500, ''),
            ]);
            throw $e;
        }
    }

    public function retry(int $id): InstitutionalArchiveVerification
    {
        [$row, $token] = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($id): array {
            $row = InstitutionalArchiveVerification::with(['archive', 'sourceFile'])->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'failed'
                || $row->archive?->current_file_id !== $row->source_file_id
                || $row->sourceFile?->status !== 'active'
                || ! $row->sourceFile?->is_current) {
                throw new HttpException(409, 'Hanya verifikasi gagal untuk file aktif saat ini yang dapat diproses ulang.');
            }

            $token = bin2hex(random_bytes(32));
            $row->update([
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'failure_message' => null,
                'verified_checksum_sha256' => null,
                'storage_disk' => null,
                'storage_path' => null,
                'original_filename' => null,
                'display_filename' => null,
                'mime_type' => null,
                'file_size_bytes' => null,
                'processed_at' => null,
            ]);

            return [$row, $token];
        });

        DB::afterCommit(fn () => GenerateInstitutionalVerifiedPdfJob::dispatch($row->getKey(), $token));

        return $row->refresh();
    }

    public function readyForSource(int $fileId): InstitutionalArchiveVerification
    {
        $row = InstitutionalArchiveVerification::where('source_file_id', $fileId)->first();
        if (! $row || $row->status !== 'ready') {
            throw new HttpException($row && in_array($row->status, ['pending', 'processing'], true) ? 425 : 409, 'PDF terverifikasi belum tersedia.');
        }

        return $row;
    }

    public function publicByToken(string $token): InstitutionalArchiveVerification
    {
        return InstitutionalArchiveVerification::with('replacement')->where('token_hash', hash('sha256', strtolower($token)))->firstOrFail();
    }

    public function summaryForSource(int $fileId): ?array
    {
        $row = InstitutionalArchiveVerification::where('source_file_id', $fileId)->first();

        return $row?->verification_summary;
    }

    private function issuerName(object $actor): string
    {
        try {
            return DB::connection(config('myconfig.database.first_connection'))
                ->transaction(fn (): string => $this->actorNames->resolve($actor));
        } catch (QueryException) {
            $name = trim((string) ($actor->name ?? ''));

            return $name !== '' ? $name : 'Admin';
        }
    }

    private function resolveIdentity(int $userId, ?string $fallbackName = null): string
    {
        try {
            $name = DB::connection(config('myconfig.database.first_connection'))
                ->transaction(fn (): ?string => $this->actorNames->resolveUserId($userId));
        } catch (QueryException) {
            $name = null;
        }
        $name = trim((string) ($name ?: $fallbackName ?: 'Admin'));

        return "Admin #{$userId} - ".($name !== '' ? $name : 'Admin');
    }

    private function render(InstitutionalArchiveVerification $verification, string $source, string $token): string
    {
        $fontPath = resource_path('pdf-fonts');
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontPath);
        }
        $pdf = new Tcpdf(fileOptions: ['allowedPaths' => [$fontPath]]);
        $pdf->setTitle('Sertifikat Verifikasi Arsip Institusional');
        $pdf->addPage(['format' => 'A4', 'orientation' => 'P']);
        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 11);
        $pdf->page->addContent($font['out']);
        $metadata = $verification->public_metadata;
        $code = implode('-', str_split(strtoupper(substr($token, 0, 12)), 4));
        $url = rtrim(config('app.url'), '/').'/api/arsip-digital/institutional-verify/'.$token;
        $html = '<div style="font-family:dejavusans;text-align:center"><div style="font-size:20pt;font-weight:bold">STMIK BANDUNG</div><div style="font-size:15pt;margin-top:20px">SERTIFIKAT VERIFIKASI ARSIP</div></div><div style="font-family:dejavusans;font-size:10pt;margin-top:30px"><b>Judul:</b> '.e($metadata['title'] ?? '-').'<br><b>Nomor Dokumen:</b> '.e($metadata['document_number'] ?? '-').'<br><b>Unit:</b> '.e($metadata['unit_name'] ?? '-').'<br><b>Tanggal Dokumen:</b> '.e($metadata['document_date'] ?? '-').'<br><b>Versi:</b> '.$verification->version_number.'<br><b>Penerbit:</b> '.e($verification->issuer_name_snapshot).'<br><b>Checksum Master SHA-256:</b><br><span style="font-size:7pt">'.$verification->source_checksum_sha256.'</span></div><div style="font-family:dejavusans;font-size:10pt;text-align:center;margin-top:28px">Tercatat dan diterbitkan melalui Arsip Digital STMIK Bandung.<br><b>Kode: '.$code.'</b></div>';
        $pdf->addHTMLCell(html: $html, posx: 20, posy: 20, width: 170);
        $pdf->page->addContent($pdf->getBarcode(type: 'QRCODE,H', code: $url, posx: 80, posy: 205, width: 50, height: 50, padding: [0, 0, 0, 0]));
        $sourceId = $pdf->setImportSourceData($source);
        for ($page = 1; $page <= $pdf->getSourcePageCount($sourceId); $page++) {
            $pdf->addPageFromImport($sourceId, $page);
        }

        return $pdf->getOutPDFString();
    }
}
