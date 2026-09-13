<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\InstitutionalStorageMonitoringService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class InstitutionalStorageMonitoringController extends Controller
{
    public function summary(Request $request, RoleResolverService $roles, InstitutionalStorageMonitoringService $service)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON($service->summary());
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function files(Request $request, RoleResolverService $roles, InstitutionalStorageMonitoringService $service)
    {
        try {
            $roles->resolve($request, ['admin']);
            $data = $request->validate([
                'storage_availability' => ['sometimes', 'in:available,missing,unknown'], 'unit_id' => ['sometimes', 'integer'],
                'category_id' => ['sometimes', function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'root' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $fail('Folder tidak valid.');
                    }
                }],
                'version' => ['sometimes', 'in:current,historical'], 'deleted' => ['sometimes', 'in:exclude,only,include'],
                'sort' => ['sometimes', 'in:file_size_bytes,created_at,storage_availability'], 'direction' => ['sometimes', 'in:asc,desc'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1'],
            ]);
            $result = $service->files($data);

            return $this->successfulResponseJSON(['files' => $result->items(), 'pagination' => ['current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function trigger(Request $request, RoleResolverService $roles, InstitutionalStorageMonitoringService $service)
    {
        try {
            $roles->resolve($request, ['admin']);

            $result = $service->trigger(auth()->id());

            if (! $result['created']) {
                return response()->json(['status' => 'fail', 'message' => 'Rekonsiliasi storage masih berjalan.', 'data' => ['job' => $result['report']]], 409);
            }

            return $this->successfulResponseJSON(['job' => $result['report']], 'Rekonsiliasi storage dijadwalkan.', 202);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $jobId, RoleResolverService $roles, InstitutionalStorageMonitoringService $service)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON(['job' => $service->job($jobId)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
