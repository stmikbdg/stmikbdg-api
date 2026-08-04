<?php

namespace App\Services\ArsipDigital;

use App\Jobs\ArsipDigital\FinalizePdfSignSessionJob;
use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\PdfSignSession;
use Com\Tecnick\Pdf\Tcpdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PdfSelfSignService
{
    public function __construct(private readonly ArsipDigitalSettingsService $settings) {}

    public function create(object $user, string $role, ?ArchiveFile $source, ?UploadedFile $upload, ?int $limitMb = null): PdfSignSession
    {
        $this->cleanup();
        if ($role === 'admin' && ! $upload) {
            throw new HttpException(422, 'Admin wajib mengupload PDF sumber.');
        }
        if ($role !== 'admin' && ! $source) {
            throw new HttpException(422, 'File arsip sumber wajib dipilih.');
        }
        $settings = $this->settings->getDefaults();
        $limitMb ??= (int) $settings['default_max_file_size_mb'];
        $limit = $limitMb * 1024 * 1024;
        $knownSize = $upload?->getSize() ?? $source?->file_size_bytes;
        if ($knownSize !== null && $knownSize > $limit) {
            throw new HttpException(422, "PDF sumber melebihi batas {$limitMb}MB.");
        }
        if ($source && $source->storage_availability === 'unknown') {
            $this->resolveAvailability($source);
        }
        $bytes = $upload ? $this->readUpload($upload, $limit) : $this->readStorage($source->storage_disk, $source->storage_path, $limit);
        $prefix = preg_replace('/^(?:\xEF\xBB\xBF)?\s*/', '', substr($bytes, 0, 1024));
        if (! str_starts_with($prefix, '%PDF-')) {
            throw new HttpException(422, 'Header PDF sumber tidak valid.');
        }

        $id = (string) Str::uuid();
        $disk = (string) $settings['storage_disk'];
        $path = "arsip-digital/tmp/pdf-sign/{$id}/source.pdf";
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw new HttpException(500, 'Gagal menyimpan PDF sementara.');
        }
        try {
            return PdfSignSession::create([
                'sign_session_id' => $id, 'owner_user_id' => $user->id, 'owner_role' => $role,
                'source_file_id' => $source?->file_id, 'storage_disk' => $disk, 'source_path' => $path,
                'source_sha256' => hash('sha256', $bytes), 'original_filename' => $upload?->getClientOriginalName() ?? $source->display_filename,
                'expires_at' => now()->addHour(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->deleteDirectory(dirname($path));
            throw $e;
        }
    }

    private function resolveAvailability(ArchiveFile $source): void
    {
        try {
            if (! Storage::disk($source->storage_disk)->exists($source->storage_path)) {
                $source->update(['storage_availability' => 'missing']);
                throw new HttpException(410, 'PDF sumber tidak tersedia.');
            }
            $source->update(['storage_availability' => 'available']);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new HttpException(503, 'Storage sementara tidak tersedia.');
        }
    }

    private function readUpload(UploadedFile $upload, int $limit): string
    {
        $stream = @fopen($upload->getRealPath(), 'rb');

        return $this->readCapped($stream, $limit);
    }

    private function readStorage(string $disk, string $path, int $limit): string
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (\League\Flysystem\UnableToReadFile $e) {
            if ($e->reason() === 'File does not exist at path: '.$path) {
                throw new HttpException(410, 'PDF sumber tidak tersedia.');
            }

            throw new HttpException(503, 'Storage sementara tidak tersedia.');
        } catch (\Throwable) {
            throw new HttpException(503, 'Storage sementara tidak tersedia.');
        }

        if (! is_resource($stream)) {
            throw new HttpException(410, 'PDF sumber tidak tersedia.');
        }

        return $this->readCapped($stream, $limit);
    }

    private function readCapped($stream, int $limit): string
    {
        if (! is_resource($stream)) {
            throw new HttpException(500, 'Gagal membaca PDF sumber.');
        }
        try {
            $bytes = stream_get_contents($stream, $limit + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes)) {
            throw new HttpException(500, 'Gagal membaca PDF sumber.');
        }
        if (strlen($bytes) > $limit) {
            throw new HttpException(422, 'PDF sumber melebihi batas '.($limit / 1024 / 1024).'MB.');
        }

        return $bytes;
    }

    public function owned(string $id, object $user, string $role): PdfSignSession
    {
        $session = PdfSignSession::findOrFail($id);
        if ($session->owner_user_id !== $user->id || $session->owner_role !== $role) {
            throw new HttpException(403, 'Tidak memiliki akses sesi tanda tangan.');
        }
        if ($session->expires_at->isPast() && ! in_array($session->status, ['queued', 'processing'], true)) {
            $this->delete($session);
            throw new HttpException(410, 'Sesi tanda tangan sudah kedaluwarsa.');
        }

        return $session;
    }

    public function queueFinalize(PdfSignSession $session, array $placements, array $images): PdfSignSession
    {
        $disk = Storage::disk($session->storage_disk);
        $directory = dirname($session->source_path).'/payload';
        $disk->deleteDirectory($directory);
        try {
            foreach ($images as $index => $image) {
                $stream = fopen($image->getRealPath(), 'rb');
                try {
                    if (! $disk->writeStream("{$directory}/signature-{$index}.png", $stream, ['visibility' => 'private'])) {
                        throw new \RuntimeException;
                    }
                } finally {
                    fclose($stream);
                }
            }
            if (! $disk->put("{$directory}/placements.json", json_encode($placements, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            $disk->deleteDirectory($directory);
            throw new HttpException(500, 'Gagal menyimpan payload sementara.');
        }
        $updated = PdfSignSession::whereKey($session->getKey())->whereIn('status', ['created', 'failed'])->update(['status' => 'queued', 'error_message' => null, 'started_at' => null, 'finished_at' => null]);
        if (! $updated) {
            $disk->deleteDirectory($directory);
            throw new HttpException(409, 'Sesi sudah diproses.');
        }
        try {
            FinalizePdfSignSessionJob::dispatch($session->getKey())->onConnection(config('queue.default'));
        } catch (\Throwable $e) {
            PdfSignSession::whereKey($session->getKey())->where('status', 'queued')->update(['status' => 'failed', 'error_message' => 'Pemrosesan PDF gagal.', 'finished_at' => now()]);
            $disk->deleteDirectory($directory);
            throw $e;
        }

        return $session->refresh();
    }

    public function processFinalize(string $sessionId): void
    {
        if (! PdfSignSession::whereKey($sessionId)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now()])) {
            return;
        }
        $session = PdfSignSession::findOrFail($sessionId);
        $disk = Storage::disk($session->storage_disk);
        $payload = dirname($session->source_path).'/payload';
        $work = sys_get_temp_dir().'/pdf-sign-'.$sessionId.'-'.Str::random(8);
        mkdir($work, 0700, true);
        try {
            $placements = json_decode($disk->get("{$payload}/placements.json"), true, flags: JSON_THROW_ON_ERROR);
            file_put_contents("{$work}/source.pdf", $disk->get($session->source_path));
            $images = [];
            foreach ($placements as $index => $placement) {
                if ($placement['method'] !== 'text') {
                    $path = "{$work}/signature-{$index}.png";
                    file_put_contents($path, $disk->get("{$payload}/signature-{$index}.png"));
                    $images[$index] = new UploadedFile($path, basename($path), 'image/png', null, true);
                }
            }
            $result = $this->stamp("{$work}/source.pdf", $placements, $images, $work);
            $resultPath = dirname($session->source_path).'/result.pdf';
            if (! $disk->put($resultPath, $result, ['visibility' => 'private'])) {
                throw new \RuntimeException;
            }
            $session->update(['result_path' => $resultPath, 'result_sha256' => hash('sha256', $result)]);
            if ($session->signature_request_file_id) {
                app(SignatureRequestService::class)->syncFinalized($session->refresh(), (object) ['id' => $session->owner_user_id]);
            }
            $session->update(['status' => 'finalized', 'finished_at' => now()]);
            app(AuditLogService::class)->record('pdf_self_sign.finalized', 'pdf_sign_session', $sessionId, 'PDF self-sign difinalisasi.', ['result_sha256' => $session->result_sha256], null, $session->owner_user_id, $session->owner_role);
        } catch (\Throwable $e) {
            Log::error('Pemrosesan PDF self-sign gagal.', [
                'session_id' => $sessionId,
                'phase' => 'finalize',
                'exception' => $e,
            ]);
            $disk->delete(dirname($session->source_path).'/result.pdf');
            $this->failFinalize($sessionId, $this->finalizeErrorMessage($e, $sessionId), $e);

            throw $e;
        } finally {
            $disk->deleteDirectory($payload);
            foreach (glob($work.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($work);
        }
    }

    private function stamp(string $sourcePath, array $placements, array $images, string $work): string
    {
        $fontPath = resource_path('pdf-fonts');
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontPath);
        }
        $pdf = new Tcpdf(fileOptions: ['allowedPaths' => [$work, $fontPath]]);
        $font = $pdf->font->insert($pdf->pon, 'dejavusans', '', 12);
        $sourceId = $pdf->setImportSourceData(file_get_contents($sourcePath));
        $count = $pdf->getSourcePageCount($sourceId);
        foreach ($placements as $placement) {
            if ($placement['page'] > $count) {
                throw new HttpException(422, 'Halaman placement tidak tersedia.');
            }
        }
        for ($number = 1; $number <= $count; $number++) {
            $pdf->addPageFromImport($sourceId, $number);
            $pdf->page->addContent("\n".$font['out']);
            $page = $pdf->page->getPage();
            foreach ($placements as $index => $p) {
                if ($number === $p['page']) {
                    $x = $p['x'] * $page['width'];
                    $y = $p['y'] * $page['height'];
                    $w = $p['width'] * $page['width'];
                    $h = $p['height'] * $page['height'];
                    if ($p['method'] === 'text') {
                        $pdf->addHTMLCell(html: e($p['text']), posx: $x, posy: $y, width: $w, height: $h);
                    } else {
                        $id = $pdf->image->add($images[$index]->getRealPath());
                        $pdf->page->addContent($pdf->image->getSetImage($id, $x, $y, $w, $h, $page['height']));
                    }
                }
            }
        }

        return $pdf->getOutPDFString();
    }

    public function failFinalize(string $id, ?string $message = null, ?\Throwable $cause = null): void
    {
        $message ??= 'Pemrosesan PDF gagal.';
        PdfSignSession::whereKey($id)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'error_message' => $message, 'finished_at' => now()]);
        if ($session = PdfSignSession::find($id)) {
            Storage::disk($session->storage_disk)->deleteDirectory(dirname($session->source_path).'/payload');
            app(AuditLogService::class)->record('pdf_self_sign.failed', 'pdf_sign_session', $id, 'Pemrosesan PDF self-sign gagal.', array_filter([
                'exception_class' => $cause ? $cause::class : null,
            ]), null, $session->owner_user_id, $session->owner_role);
        }
    }

    private function finalizeErrorMessage(\Throwable $e, string $sessionId): string
    {
        if (! app()->environment('production')) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'Pemrosesan PDF gagal.';
        }

        return 'Pemrosesan PDF gagal. ID referensi: '.($this->safeSessionReference($e->getMessage()) ?? $sessionId);
    }

    private function safeSessionReference(string $message): ?string
    {
        if (preg_match('/(?:correlation|request|session)[ _-]?id[=: ]+([A-Za-z0-9-]{6,64})/i', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function delete(PdfSignSession $session): void
    {
        Storage::disk($session->storage_disk)->deleteDirectory(dirname($session->source_path));
        $session->delete();
    }

    public function cleanup(): int
    {
        $count = 0;
        $lease = now()->subMinutes(30);
        PdfSignSession::whereIn('status', ['queued', 'processing'])->where('updated_at', '<=', $lease)->eachById(function ($s) use (&$count) {
            $this->failFinalize($s->getKey());
            $count++;
        }, column: 'sign_session_id');
        PdfSignSession::whereNotIn('status', ['queued', 'processing'])->where('expires_at', '<=', now())->eachById(function ($s) use (&$count) {
            $this->delete($s);
            $count++;
        }, column: 'sign_session_id');

        return $count;
    }
}
