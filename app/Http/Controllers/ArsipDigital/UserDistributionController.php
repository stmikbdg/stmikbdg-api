<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\DistributionService;
use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class UserDistributionController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, DistributionService $distributionService)
    {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);

            $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']])['per_page'] ?? 20;
            $paginator = $distributionService->userQuery(auth()->user(), $role)->paginate($perPage);
            $distributions = collect($paginator->items());
            $distributions->each(function ($distribution): void {
                $expired = $distribution->expires_at && now()->greaterThanOrEqualTo($distribution->expires_at);
                $withdrawn = $distribution->status === 'closed';
                $sourceDeleted = $distribution->institutional_archive_id && (! $distribution->institutionalArchive || $distribution->institutionalArchive->status !== 'active');
                $distribution->setAttribute('availability_status', $withdrawn ? 'withdrawn' : ($expired ? 'expired' : ($sourceDeleted ? 'unavailable' : 'available')));
                $distribution->institutionalArchive?->setVisible(['institutional_archive_id', 'title', 'document_number', 'document_date', 'unit']);
                $distribution->institutionalArchive?->unit?->setVisible(['unit_id', 'name']);
                $files = $distribution->recipients->filter->file->map(function ($recipient) {
                    return [
                        'recipient_id' => $recipient->recipient_id,
                        'file_id' => $recipient->file_id,
                        'display_filename' => $recipient->file->display_filename,
                        'original_filename' => $recipient->file->original_filename,
                        'mime_type' => $recipient->file->mime_type,
                        'file_size_bytes' => $recipient->file->file_size_bytes,
                    ];
                })->values()->all();
                $distribution->setAttribute('files', ($withdrawn || $expired || $sourceDeleted) ? [] : $files);
                if ($distribution->institutional_archive_id) {
                    $distribution->recipients->each(fn ($recipient) => $recipient->setVisible(['recipient_id', 'target_role', 'identifier', 'delivery_status', 'file_id', 'download_count', 'first_downloaded_at', 'last_downloaded_at']));
                    $distribution->setVisible(['distribution_id', 'title', 'description', 'status', 'published_at', 'expires_at', 'availability_status', 'institutional_archive', 'recipients', 'files']);
                }
            });

            return $this->successfulResponseJSON(['distributions' => $distributions->toArray(), 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function preview(
        Request $request,
        int $recipient_id,
        RoleResolverService $roleResolver,
        DistributionService $distributionService,
        ArsipDigitalStorageService $storageService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);
            $recipient = $distributionService->findDownloadableRecipientByFile($recipient_id, auth()->user(), $role);
            $file = $recipient->file;
            if ($file->source_type === 'institutional') {
                $file = app(InstitutionalArchiveVerificationService::class)->readyForSource($file->file_id);
            }

            return $storageService->streamPdfPrivate($file->storage_disk, $file->storage_path, $file->display_filename);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function previewRecipient(
        Request $request,
        int $recipient_id,
        RoleResolverService $roleResolver,
        DistributionService $distributionService,
        ArsipDigitalStorageService $storageService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);
            $file = $distributionService->findDownloadableRecipient($recipient_id, auth()->user(), $role)->file;
            if ($file->source_type === 'institutional') {
                $file = app(InstitutionalArchiveVerificationService::class)->readyForSource($file->file_id);
            }
            if (! in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                abort(415, 'Format file tidak mendukung preview browser.');
            }
            $stream = $storageService->openPrivateStream($file->storage_disk, $file->storage_path);

            return response()->stream(function () use ($stream): void {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, [
                'Content-Type' => $file->mime_type,
                'Content-Disposition' => 'inline; filename="'.$storageService->safeFilename($file->display_filename).'"',
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function downloadRecipient(
        Request $request,
        int $recipient_id,
        RoleResolverService $roleResolver,
        DistributionService $distributionService,
        ArsipDigitalStorageService $storageService
    ) {
        return $this->downloadResolved($request, $recipient_id, true, $roleResolver, $distributionService, $storageService);
    }

    public function download(
        Request $request,
        int $recipient_id,
        RoleResolverService $roleResolver,
        DistributionService $distributionService,
        ArsipDigitalStorageService $storageService
    ) {
        return $this->downloadResolved($request, $recipient_id, false, $roleResolver, $distributionService, $storageService);
    }

    private function downloadResolved(Request $request, int $id, bool $byRecipient, RoleResolverService $roleResolver, DistributionService $distributionService, ArsipDigitalStorageService $storageService)
    {
        try {
            $role = $roleResolver->resolve($request, ['mahasiswa', 'dosen']);
            $recipient = $byRecipient
                ? $distributionService->findDownloadableRecipient($id, auth()->user(), $role)
                : $distributionService->findDownloadableRecipientByFile($id, auth()->user(), $role);
            $file = $recipient->file;
            if ($recipient->distribution?->institutional_archive_id) {
                $file = app(InstitutionalArchiveVerificationService::class)->readyForSource($file->file_id);
            }
            $stream = $storageService->openPrivateStream($file->storage_disk, $file->storage_path);
            try {
                $distributionService->markDownloaded($recipient, auth()->user(), $role, $request);
            } catch (\Throwable $e) {
                fclose($stream);
                throw $e;
            }

            return $storageService->downloadOpenedStream($stream, $file->display_filename);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
