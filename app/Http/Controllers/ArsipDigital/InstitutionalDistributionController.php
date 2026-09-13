<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Distribution;
use App\Models\ArsipDigital\InstitutionalArchive;
use App\Services\ArsipDigital\InstitutionalDistributionService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class InstitutionalDistributionController extends Controller
{
    public function preview(Request $request, int $id, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $roles->resolve($request, ['admin']);
            InstitutionalArchive::findOrFail($id);

            return $this->successfulResponseJSON(['preview' => $service->preview($this->targets($request))]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, int $id, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $role = $roles->resolve($request, ['admin']);
            $payload = array_merge($this->targets($request), $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'expires_at' => ['nullable', 'date_format:Y-m-d\\TH:i:sP', 'after:now']]));
            $item = $service->create(InstitutionalArchive::findOrFail($id), $payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $service->distributionDto($item)], 'Draft distribusi dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function index(Request $request, int $id, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $roles->resolve($request, ['admin']);
            InstitutionalArchive::withTrashed()->findOrFail($id);
            $perPage = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']])['per_page'] ?? 20;
            $items = Distribution::where('institutional_archive_id', $id)->with(['institutionalArchive.currentFile', 'sourceFile'])->withCount('recipients')->orderByDesc('distribution_id')->paginate($perPage);

            return $this->successfulResponseJSON(['distributions' => collect($items->items())->map(fn ($item) => $service->distributionDto($item))->all(), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function show(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $roles->resolve($request, ['admin']);
            $item = $this->find($distributionId)->load(['institutionalArchive.unit', 'institutionalArchive.currentFile', 'sourceFile'])->loadCount('recipients');

            return $this->successfulResponseJSON(['distribution' => $service->distributionDto($item)]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function draftTargets(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $roles->resolve($request, ['admin']);

            return $this->successfulResponseJSON($service->draftTargets($this->find($distributionId)));
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $role = $roles->resolve($request, ['admin']);
            $payload = array_merge($this->targets($request), $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'expires_at' => ['nullable', 'date_format:Y-m-d\\TH:i:sP', 'after:now'], 'expected_updated_at' => ['required', 'date']]));
            $item = $service->update($this->find($distributionId), $payload, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $service->distributionDto($item)], 'Draft distribusi diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $role = $roles->resolve($request, ['admin']);
            $payload = $request->validate(['expected_updated_at' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:2000']]);
            $service->cancel($this->find($distributionId), $payload['expected_updated_at'], $payload['reason'] ?? null, auth()->user(), $role, $request);

            return $this->successfulResponseJSON([], 'Draft distribusi dibatalkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function recipients(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $roles->resolve($request, ['admin']);
            $item = $this->find($distributionId);
            if ($item->status === 'draft') {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Draft belum memiliki penerima. Gunakan endpoint target draft.');
            }
            $pagination = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
            $rows = $item->recipients()->orderBy('identifier')->paginate($pagination['per_page'] ?? 50);

            return $this->successfulResponseJSON(['recipients' => collect($rows->items())->map(fn ($recipient) => $service->recipientDto($recipient))->all(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function publish(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $role = $roles->resolve($request, ['admin']);
            $payload = $request->validate(['source_file_id' => ['required', 'integer', 'min:1'], 'expected_updated_at' => ['nullable', 'date'], 'target_fingerprint' => ['nullable', 'string', 'size:64']]);
            $item = $service->publish($this->find($distributionId), $payload['source_file_id'], auth()->user(), $role, $request, $payload['expected_updated_at'] ?? null, $payload['target_fingerprint'] ?? null);

            return $this->successfulResponseJSON(['distribution' => $service->distributionDto($item)], 'Distribusi dipublish.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function withdraw(Request $request, int $distributionId, RoleResolverService $roles, InstitutionalDistributionService $service)
    {
        try {
            $role = $roles->resolve($request, ['admin']);
            $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];
            $item = $service->withdraw($this->find($distributionId), $reason, auth()->user(), $role, $request);

            return $this->successfulResponseJSON(['distribution' => $service->distributionDto($item)], 'Distribusi ditarik.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function targets(Request $request): array
    {
        return $request->validate(['target_role' => ['required', 'in:mahasiswa,dosen'], 'scope_type' => ['required', 'in:filter,specific,segment'], 'target_filters' => ['nullable', 'array'], 'target_identifiers' => ['nullable'], 'target_segment_ids' => ['nullable', 'array'], 'target_segment_ids.*' => ['integer']]);
    }

    private function find(int $id): Distribution
    {
        return Distribution::whereNotNull('institutional_archive_id')->findOrFail($id);
    }
}
