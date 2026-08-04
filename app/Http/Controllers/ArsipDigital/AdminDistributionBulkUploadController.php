<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Distribution;
use App\Services\ArsipDigital\DistributionBulkUploadService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class AdminDistributionBulkUploadController extends Controller
{
    public function index(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionBulkUploadService $service)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            Distribution::findOrFail($distribution_id);
            $filters = $request->validate([
                'status' => ['sometimes', 'in:uploaded,processing,preview_ready,confirming,confirmed,failed,expired,cancelled'],
            ]);
            $filters['distribution_id'] = $distribution_id;

            return $this->successfulResponseJSON([
                'bulk_upload_jobs' => $service->adminQuery($filters)->get()->toArray(),
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, int $distribution_id, RoleResolverService $roleResolver, DistributionBulkUploadService $service)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $request->validate([
                'zip_file' => ['required', 'file', 'mimes:zip', 'max:102400'],
            ], [
                'zip_file.required' => 'File ZIP wajib diunggah.',
                'zip_file.file' => 'Upload bulk harus berupa file ZIP yang valid.',
                'zip_file.mimes' => 'File bulk upload harus berformat ZIP (.zip).',
                'zip_file.max' => 'Ukuran ZIP melebihi batas 100 MB.',
            ]);
            $distribution = Distribution::findOrFail($distribution_id);
            $job = $service->createPreviewJob($distribution, $request->file('zip_file'), auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['bulk_upload_job' => $job->toArray()], 'Bulk upload ZIP berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $bulk_upload_job_id, RoleResolverService $roleResolver, DistributionBulkUploadService $service)
    {
        try {
            $roleResolver->resolve($request, ['admin']);
            $job = $service->findForAdmin($bulk_upload_job_id);

            return $this->successfulResponseJSON(['bulk_upload_job' => $job->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function confirm(Request $request, int $bulk_upload_job_id, RoleResolverService $roleResolver, DistributionBulkUploadService $service)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $job = $service->confirm($bulk_upload_job_id, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['bulk_upload_job' => $job->toArray()], 'Bulk upload ZIP berhasil dikonfirmasi.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function cancel(Request $request, int $bulk_upload_job_id, RoleResolverService $roleResolver, DistributionBulkUploadService $service)
    {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $job = $service->cancel($bulk_upload_job_id, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['bulk_upload_job' => $job->toArray()], 'Bulk upload ZIP berhasil dibatalkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
