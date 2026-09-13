<?php

namespace App\Services\ArsipDigital;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ArchiveUploadValidationService
{
    private const MIME_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];

    public function validateUploadedFile(UploadedFile $file, int $maxFileSizeMb, array $allowedExtensions): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = array_map('strtolower', $allowedExtensions);

        if ($file->getSize() > ($maxFileSizeMb * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran file melebihi batas ' . $maxFileSizeMb . ' MB.',
            ]);
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'Ekstensi file tidak diizinkan.',
            ]);
        }

        $this->validateMimeType($extension, (string) $file->getMimeType());
    }

    public function validateMetadataFile(string $extension, int $fileSizeBytes, int $maxFileSizeMb, array $allowedExtensions, ?string $mimeType = null): void
    {
        $extension = strtolower($extension);
        $allowedExtensions = array_map('strtolower', $allowedExtensions);

        if ($fileSizeBytes > ($maxFileSizeMb * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'file' => 'Ukuran file melebihi batas ' . $maxFileSizeMb . ' MB.',
            ]);
        }

        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'Ekstensi file tidak diizinkan.',
            ]);
        }

        if ($mimeType !== null) {
            $this->validateMimeType($extension, $mimeType);
        }
    }

    private function validateMimeType(string $extension, string $mimeType): void
    {
        if (! isset(self::MIME_BY_EXTENSION[$extension])) {
            return;
        }

        if (! in_array($mimeType, self::MIME_BY_EXTENSION[$extension], true)) {
            throw ValidationException::withMessages([
                'file' => 'MIME type file tidak sesuai dengan ekstensi.',
            ]);
        }
    }
}
