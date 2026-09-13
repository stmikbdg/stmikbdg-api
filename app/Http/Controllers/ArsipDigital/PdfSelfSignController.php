<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\ArchiveFile;
use App\Services\ArsipDigital\ArchiveFileService;
use App\Services\ArsipDigital\ArchivePermissionService;
use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\PdfSelfSignService;
use App\Services\ArsipDigital\RoleResolverService;
use App\Services\ArsipDigital\SignatureRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PdfSelfSignController extends Controller
{
    public function store(Request $request, RoleResolverService $roles, ArchivePermissionService $permissions, PdfSelfSignService $service, AuditLogService $audit, ArsipDigitalSettingsService $settings)
    {
        try {
            $role = $roles->resolve($request);
            $maxKb = (int) $settings->getDefaults()['default_max_file_size_mb'] * 1024;
            $payload = $request->validate([
                'file_id' => ['nullable', 'integer'],
                'file' => ['nullable', 'file', "max:{$maxKb}"],
            ], ['file.max' => 'PDF sumber melebihi batas '.($maxKb / 1024).'MB.']);
            $source = isset($payload['file_id']) ? ArchiveFile::findOrFail($payload['file_id']) : null;
            if ($source && (! $permissions->canViewFile($source, auth()->user(), $role) || $source->extension !== 'pdf')) {
                throw new HttpException(403, 'PDF arsip bukan milik pengguna.');
            }
            if ($source?->storage_availability === 'missing') {
                throw new HttpException(410, 'PDF sumber tidak tersedia.');
            }
            if ($role === 'admin' && $source || $role !== 'admin' && $request->hasFile('file')) {
                throw new HttpException(422, 'Sumber PDF tidak sesuai role.');
            }
            $session = $service->create(auth()->user(), $role, $source, $request->file('file'), (int) ($maxKb / 1024));
            if (! $audit->record('pdf_self_sign.created', 'pdf_sign_session', $session->sign_session_id, 'Sesi PDF self-sign dibuat.', ['source_sha256' => $session->source_sha256, 'source_file_id' => $session->source_file_id], $request, auth()->id(), $role)) {
                $service->delete($session);
                throw new HttpException(500, 'Audit create gagal disimpan.');
            }

            return $this->successfulResponseJSON(['session' => $session->toArray()], 'Sesi tanda tangan dibuat.', 201);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function finalize(Request $request, string $session_id, RoleResolverService $roles, PdfSelfSignService $service, AuditLogService $audit, SignatureRequestService $signatureRequests)
    {
        try {
            $role = $roles->resolve($request);
            if ($request->filled('placements') && is_string($request->input('placements'))) {
                $placements = json_decode($request->input('placements'), true);
                if (! is_array($placements)) {
                    throw new HttpException(422, 'Placements wajib berupa JSON array valid.');
                }
                $request->merge(['placements' => $placements]);
            }
            if (! $request->has('placements')) {
                $request->merge(['placements' => [$request->only(['method', 'text', 'page', 'x', 'y', 'width', 'height'])]]);
            }
            $payload = $request->validate([
                'placements' => ['required', 'array', 'min:1', 'max:20'],
                'placements.*.method' => ['required', 'in:draw,upload,text'],
                'placements.*.text' => ['nullable', 'string', 'max:200'],
                'placements.*.page' => ['required', 'integer', 'min:1'],
                'placements.*.x' => ['required', 'numeric', 'between:0,1'],
                'placements.*.y' => ['required', 'numeric', 'between:0,1'],
                'placements.*.width' => ['required', 'numeric', 'gt:0', 'lte:1'],
                'placements.*.height' => ['required', 'numeric', 'gt:0', 'lte:1'],
                'signatures' => ['nullable', 'array'],
                'signatures.*' => ['file', 'mimes:png', 'max:2048'],
                'signature' => ['nullable', 'file', 'mimes:png', 'max:2048'],
            ]);
            $images = [];
            foreach ($payload['placements'] as $index => $placement) {
                if ($placement['x'] + $placement['width'] > 1 || $placement['y'] + $placement['height'] > 1) {
                    throw new HttpException(422, "Placement {$index} melewati batas halaman.");
                }
                if ($placement['method'] === 'text' && ! isset($placement['text'])) {
                    throw new HttpException(422, "Text placement {$index} wajib diisi.");
                }
                if ($placement['method'] !== 'text') {
                    $images[$index] = $request->file("signatures.{$index}") ?? ($index === 0 ? $request->file('signature') : null);
                    if (! $images[$index]) {
                        throw new HttpException(422, "Signature placement {$index} wajib diisi.");
                    }
                }
            }
            $session = $service->owned($session_id, auth()->user(), $role);
            if (in_array($session->status, ['created', 'failed'], true)) {
                $session = $service->queueFinalize($session, $payload['placements'], $images);
            } elseif (! in_array($session->status, ['queued', 'processing', 'finalized'], true)) {
                throw new HttpException(409, 'Sesi sudah diproses.');
            }

            return $this->successfulResponseJSON(['session' => $session->toArray()], 'PDF sedang diproses.', 202);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, string $session_id, RoleResolverService $roles, PdfSelfSignService $service)
    {
        try {
            $session = $service->owned($session_id, auth()->user(), $roles->resolve($request));
            $requestFile = $session->signature_request_file_id
                ? \App\Models\ArsipDigital\SignatureRequestFile::find($session->signature_request_file_id)
                : null;

            return $this->successfulResponseJSON([
                'session' => array_merge(
                    $session->only(['sign_session_id', 'status', 'error_message', 'result_sha256', 'expires_at']),
                    $requestFile ? ['request_file' => $requestFile->only(['signature_request_file_id', 'signed_at', 'result_sha256'])] : []
                ),
            ]);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function download(Request $request, string $session_id, RoleResolverService $roles, PdfSelfSignService $service, AuditLogService $audit)
    {
        try {
            $role = $roles->resolve($request);
            $session = $service->owned($session_id, auth()->user(), $role);
            if (! $session->result_path) {
                throw new HttpException(409, 'PDF belum difinalisasi.');
            }
            if (! $audit->record('pdf_self_sign.downloaded', 'pdf_sign_session', $session->sign_session_id, 'Hasil PDF self-sign diunduh.', ['result_sha256' => $session->result_sha256], $request, auth()->id(), $role)) {
                throw new HttpException(500, 'Audit download gagal disimpan.');
            }

            return Storage::disk($session->storage_disk)->download($session->result_path, 'signed-'.$session->original_filename);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function save(Request $request, string $session_id, RoleResolverService $roles, PdfSelfSignService $service, ArchiveFileService $files, AuditLogService $audit)
    {
        try {
            $role = $roles->resolve($request, ['mahasiswa', 'dosen']);
            $payload = $request->validate(['category_id' => ['nullable', 'integer'], 'display_filename' => ['nullable', 'string', 'max:255']]);
            $session = $service->owned($session_id, auth()->user(), $role);
            if ($session->status !== 'finalized' || ! $session->result_path) {
                throw new HttpException(409, 'PDF belum dapat disimpan.');
            }
            $path = tempnam(sys_get_temp_dir(), 'pdf-sign-save-');
            $stream = Storage::disk($session->storage_disk)->readStream($session->result_path);
            $target = fopen($path, 'wb');
            if (! is_resource($stream) || ! is_resource($target) || stream_copy_to_stream($stream, $target) === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_resource($target)) {
                    fclose($target);
                }
                @unlink($path);
                throw new HttpException(500, 'Gagal membaca PDF hasil tanda tangan.');
            }
            fclose($stream);
            fclose($target);
            try {
                $upload = new UploadedFile($path, 'signed-'.$session->original_filename, 'application/pdf', null, true);
                $file = $files->uploadPersonal($upload, $payload, auth()->user(), $role);
            } finally {
                @unlink($path);
            }
            if (! $audit->record('pdf_self_sign.saved', 'file', $file->file_id, 'Hasil self-sign disimpan ke Arsip Saya.', ['sign_session_id' => $session->sign_session_id, 'source_sha256' => $session->source_sha256, 'result_sha256' => $session->result_sha256], $request, auth()->id(), $role)) {
                $files->delete($file, auth()->user(), $role, 'Audit save gagal');
                throw new HttpException(500, 'Audit save gagal disimpan.');
            }
            $service->delete($session);

            return $this->successfulResponseJSON(['file' => $file->toArray()], 'PDF tersimpan di Arsip Saya.', 201);
        } catch (\Throwable $e) {
            return ErrorHandler::handle($e);
        }
    }
}
