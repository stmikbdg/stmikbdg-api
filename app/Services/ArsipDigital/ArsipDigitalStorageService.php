<?php

namespace App\Services\ArsipDigital;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArsipDigitalStorageService
{
    public function __construct(private readonly ArsipDigitalSettingsService $settings) {}

    public function uploadPrivate(UploadedFile $file, string $sourceType, array $context = [], ?string $disk = null): array
    {
        $disk = $disk ?: $this->settings->getDefaults()['storage_disk'];
        $uuid = (string) Str::uuid();
        $safeFilename = $this->safeFilename($file->getClientOriginalName());
        $directory = $this->directory($sourceType, $context, $uuid);
        $storageFilename = $uuid.'_'.$safeFilename;
        $storagePath = trim($directory.'/'.$storageFilename, '/');

        $stream = fopen($file->getRealPath(), 'r');

        try {
            $stored = Storage::disk($disk)->put($storagePath, $stream, ['visibility' => 'private']);

            if ($stored === false) {
                throw new HttpException(500, 'Gagal menyimpan file arsip digital ke storage.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'original_filename' => $file->getClientOriginalName(),
            'display_filename' => $safeFilename,
            'mime_type' => $file->getMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'file_size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    public function uploadPrivateBytes(string $bytes, string $filename, string $sourceType, array $context = [], ?string $disk = null): array
    {
        $disk = $disk ?: $this->settings->getDefaults()['storage_disk'];
        $uuid = (string) Str::uuid();
        $safeFilename = $this->safeFilename($filename);
        $directory = $this->directory($sourceType, $context, $uuid);
        $storagePath = trim($directory.'/'.$uuid.'_'.$safeFilename, '/');

        if (Storage::disk($disk)->put($storagePath, $bytes, ['visibility' => 'private']) === false) {
            throw new HttpException(500, 'Gagal menyimpan file arsip digital ke storage.');
        }

        return [
            'storage_disk' => $disk,
            'storage_path' => $storagePath,
            'original_filename' => $filename,
            'display_filename' => $safeFilename,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    public function openPrivateStream(string $disk, string $path)
    {
        if (! Storage::disk($disk)->exists($path) || ($stream = Storage::disk($disk)->readStream($path)) === false) {
            abort(404, 'File tidak ditemukan di storage.');
        }

        return $stream;
    }

    public function downloadOpenedStream($stream, string $downloadName): StreamedResponse
    {
        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $downloadName);
    }

    public function downloadPrivate(string $disk, string $path, ?string $downloadName = null, ?string $mimeType = null): StreamedResponse
    {
        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'File tidak ditemukan di storage.');
        }

        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === false) {
            abort(404, 'File tidak ditemukan di storage.');
        }

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $downloadName ?: basename($path), [
            'Content-Type' => $mimeType ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function streamPdfPrivate(string $disk, string $path, ?string $filename = null, bool $download = false): StreamedResponse
    {
        $stream = Storage::disk($disk)->readStream($path);

        if ($stream === false) {
            abort(404, 'File tidak ditemukan di storage.');
        }

        $filename = $filename ?: basename($path);
        $disposition = $download ? 'attachment' : 'inline';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }

    public function deletePrivate(string $disk, string $path): void
    {
        if (Storage::disk($disk)->delete($path) === false) {
            throw new \RuntimeException('Private storage object cleanup failed.');
        }
    }

    public function safeFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9._-]+/', '-')->trim('-._')->toString();
        $name = $name !== '' ? $name : 'file';

        return $extension !== ''
            ? $name.'.'.strtolower(Str::of($extension)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '')->toString())
            : $name;
    }

    private function directory(string $sourceType, array $context, string $uuid): string
    {
        $environment = app()->environment();

        return match ($sourceType) {
            'request' => sprintf(
                'arsip-digital/%s/requests/%s/%s/%s',
                $environment,
                $context['request_id'] ?? 'unassigned',
                $context['assignment_id'] ?? 'unassigned',
                $uuid
            ),
            'distribution' => sprintf(
                'arsip-digital/%s/distributions/%s/%s/%s',
                $environment,
                $context['distribution_id'] ?? 'unassigned',
                $context['recipient_id'] ?? 'unassigned',
                $uuid
            ),
            'institutional' => sprintf(
                'arsip-digital/%s/institutional/%s/%s',
                $environment,
                $context['owner_user_id'] ?? 'unassigned',
                $uuid
            ),
            'institutional-verified' => sprintf(
                'arsip-digital/%s/institutional-verified/%s/%s/%s',
                $environment,
                $context['archive_id'] ?? 'unassigned',
                $context['source_file_id'] ?? 'unassigned',
                $uuid
            ),
            'official' => sprintf(
                'arsip-digital/%s/official/%s/%s/%s',
                $environment,
                $context['document_type'] ?? 'document',
                $context['owner_user_id'] ?? 'unassigned',
                $uuid
            ),
            default => sprintf(
                'arsip-digital/%s/personal/%s/%s',
                $environment,
                $context['owner_user_id'] ?? 'unassigned',
                $uuid
            ),
        };
    }
}
