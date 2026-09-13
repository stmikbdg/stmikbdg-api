<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\InstitutionalArchiveService;
use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstitutionalArchiveController extends Controller
{
    public function index(Request $request, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate([
                'search' => ['nullable', 'string', 'max:255'], 'unit_id' => ['sometimes', 'integer'], 'category_id' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'root' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $fail('Folder tidak valid.');
                    }
                }],
                'document_year' => ['sometimes', 'integer', 'min:1900', 'max:2100'], 'access_level' => ['sometimes', 'in:internal,restricted'],
                'storage_availability' => ['sometimes', 'in:available,missing,unknown'], 'uploader_id' => ['sometimes', 'integer'],
                'sort' => ['sometimes', 'in:document_date,created_at,title,file_size'], 'direction' => ['sometimes', 'in:asc,desc'],
                'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);
            $result = $archives->paginate($payload);

            return $this->successfulResponseJSON(['archives' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function trash(Request $request, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'unit_id' => ['sometimes', 'integer'], 'document_year' => ['sometimes', 'integer', 'min:1900', 'max:2100'], 'sort' => ['sometimes', 'in:deleted_at,title,document_date,created_at'], 'direction' => ['sometimes', 'in:asc,desc'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
            $result = $archives->trash($payload);

            return $this->successfulResponseJSON(['archives' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['reason' => ['required', 'string', 'max:1000', 'not_regex:/^\\s*$/']]);

            return $this->successfulResponseJSON(['archive' => $archives->delete($id, $payload['reason'], auth()->user())], 'Arsip dipindahkan ke sampah.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function restore(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON(['archive' => $archives->restore($id, auth()->user())], 'Arsip berhasil dipulihkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function timeline(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
            $result = $archives->timeline($id, $payload['per_page'] ?? 20);

            return $this->successfulResponseJSON(['activities' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $this->payload($request);
            unset($payload['file']);
            $archive = $archives->create($request->file('file'), $payload, auth()->user());

            return $this->successfulResponseJSON(['archive' => $archive], 'Arsip lembaga berhasil diupload.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON(['archive' => $archives->find($id)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON(['archive' => $archives->update($id, $this->payload($request, true), auth()->user())], 'Metadata arsip berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function move(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['category_id' => ['nullable', 'integer']]);

            return $this->successfulResponseJSON(['archive' => $archives->move($id, $payload['category_id'] ?? null, auth()->user())], 'Arsip berhasil dipindahkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function uploadVersion(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archives->find($id);
            $payload = $request->validate(['file' => ['required', 'file'], 'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\\s*$/']]);

            return $this->successfulResponseJSON(['archive' => $archives->uploadVersion($id, $request->file('file'), $payload['reason'], auth()->user())], 'Versi baru berhasil diupload.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function versions(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
            $result = $archives->versions($id, $payload['per_page'] ?? 20);

            return $this->successfulResponseJSON(['versions' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function downloadVersion(Request $request, int $id, int $fileId, RoleResolverService $roles, InstitutionalArchiveService $archives, ArsipDigitalStorageService $storage)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $file = $archives->version($id, $fileId);
            $response = $storage->downloadPrivate($file->storage_disk, $file->storage_path, $file->display_filename);
            $archives->auditDownload($archive, auth()->user(), $file);
            $response->headers->set('Content-Type', $file->mime_type ?: 'application/octet-stream');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function preview(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $file = $archive->currentFile;
            if (! in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                throw new HttpException(415, 'Format file tidak mendukung pratinjau.');
            }
            if (! \Storage::disk($file->storage_disk)->exists($file->storage_path)) {
                abort(404, 'File tidak ditemukan di storage.');
            }
            $stream = \Storage::disk($file->storage_disk)->readStream($file->storage_path);
            if ($stream === false) {
                abort(404, 'File tidak ditemukan di storage.');
            }

            return response()->stream(function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, ['Content-Type' => $file->mime_type, 'Content-Disposition' => 'inline; filename="'.$file->display_filename.'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function download(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives, InstitutionalArchiveVerificationService $verifications)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $master = $archive->currentFile;
            $file = $verifications->readyForSource($master->file_id);
            if (! \Storage::disk($file->storage_disk)->exists($file->storage_path)) {
                abort(404, 'File tidak ditemukan di storage.');
            }
            $stream = \Storage::disk($file->storage_disk)->readStream($file->storage_path);
            if ($stream === false) {
                abort(404, 'File tidak ditemukan di storage.');
            }
            try {
                $archives->auditDownload($archive, auth()->user());
            } catch (\Throwable $e) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw $e;
            }

            // Audit means authorization and object open succeeded. Stream transport may still fail after headers are committed.
            return response()->streamDownload(function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, $file->display_filename, ['Content-Type' => $file->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function retryVerification(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives, InstitutionalArchiveVerificationService $verifications)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $verification = $archive->currentVerification;
            if (! $verification) {
                throw new HttpException(409, 'Registry verifikasi belum tersedia.');
            }
            $verification = $verifications->retry($verification->getKey());

            return $this->successfulResponseJSON(['verification' => $verification->verification_summary], 'Pemrosesan ulang dijadwalkan.', 202);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function downloadMaster(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives, ArsipDigitalStorageService $storage)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $file = $archive->currentFile;
            $response = $storage->downloadPrivate($file->storage_disk, $file->storage_path, $file->display_filename, $file->mime_type);
            $archives->auditDownload($archive, auth()->user(), $file);

            return $response;
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function previewVerified(Request $request, int $id, RoleResolverService $roles, InstitutionalArchiveService $archives, InstitutionalArchiveVerificationService $verifications, ArsipDigitalStorageService $storage)
    {
        try {
            $roles->resolve($request, ['admin']);
            $archive = $archives->find($id);
            $file = $verifications->readyForSource($archive->current_file_id);

            return $storage->streamPdfPrivate($file->storage_disk, $file->storage_path, $file->display_filename);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function payload(Request $request, bool $update = false): array
    {
        return $request->validate([
            'file' => [$update ? 'prohibited' : 'required', 'file'], 'title' => [$update ? 'sometimes' : 'required', 'string', 'max:255', 'not_regex:/^\\s*$/'],
            'document_number' => ['nullable', 'string', 'max:255'], 'document_year' => ['nullable', 'integer', 'min:1900', 'max:2100'], 'document_date' => ['nullable', 'date'], 'received_date' => ['nullable', 'date'],
            'unit_id' => [$update ? 'sometimes' : 'required', 'integer'],
            'category_id' => [$update ? 'prohibited' : 'nullable', 'integer'], 'description' => ['nullable', 'string'],
            'access_level' => ['sometimes', 'in:internal,restricted'], 'retention_note' => ['nullable', 'string'], 'tags' => ['nullable', 'array', 'max:50'], 'tags.*' => ['string', 'max:100'],
        ]);
    }
}
