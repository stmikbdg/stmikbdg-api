<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\ExportJobService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminExportJobController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, ExportJobService $exportJobService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $filters = $request->validate([
                'export_type' => ['sometimes', 'in:request,archive_browser,distribution'],
                'status' => ['sometimes', 'in:queued,processing,completed,failed,expired'],
            ]);

            return $this->successfulResponseJSON([
                'export_jobs' => $exportJobService->adminQuery($filters)->get()->toArray(),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roleResolver, ExportJobService $exportJobService)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $payload = $request->validate([
                'export_type' => ['required', 'in:request,archive_browser,distribution'],
                'filters' => ['required', 'array'],
                'filters.request_id' => ['required_if:export_type,request', 'integer'],
                'filters.distribution_id' => ['required_if:export_type,distribution', 'integer'],
                'filters.request_file_ids' => ['sometimes', 'array'],
                'filters.request_file_ids.*' => ['integer'],
                'filters.recipient_ids' => ['sometimes', 'array'],
                'filters.recipient_ids.*' => ['integer'],
                'filters.file_ids' => ['sometimes', 'array'],
                'filters.file_ids.*' => ['integer'],
                'filters.owner_role' => ['sometimes', 'nullable', 'in:mahasiswa,dosen,admin'],
                'filters.owner_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
                'filters.extension' => ['sometimes', 'nullable', 'string', 'max:20'],
                'filters.with_deleted' => ['sometimes', 'boolean'],
                'filters.statuses' => ['sometimes', 'array'],
                'filters.statuses.*' => ['string', 'in:waiting_verification,approved,rejected,replaced'],
                'filters.assignment_statuses' => ['sometimes', 'array'],
                'filters.assignment_statuses.*' => ['string', 'in:not_submitted,waiting_verification,approved,rejected,closed'],
                'filters.delivery_statuses' => ['sometimes', 'array'],
                'filters.delivery_statuses.*' => ['string', 'in:pending,file_uploaded,available,downloaded'],
            ]);

            $exportJob = $exportJobService->create($payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['export_job' => $exportJob->toArray()], 'Export job berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $export_job_id, RoleResolverService $roleResolver, ExportJobService $exportJobService)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $exportJob = $exportJobService->findForAdmin($export_job_id);

            return $this->successfulResponseJSON(['export_job' => $exportJob->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function download(
        Request $request,
        int $export_job_id,
        RoleResolverService $roleResolver,
        ExportJobService $exportJobService,
        ArsipDigitalStorageService $storageService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $exportJob = $exportJobService->findForAdmin($export_job_id);
            $exportJobService->assertDownloadable($exportJob);
            $filename = $exportJob->filters['download_filename'] ?? ('arsip-digital-export-' . $exportJob->export_job_id . '.zip');
            $response = $storageService->downloadPrivate(
                $exportJob->storage_disk,
                $exportJob->storage_path,
                $filename
            );
            $exportJobService->markDownloaded($exportJob, auth()->user(), $role, $request);

            return $response;
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
