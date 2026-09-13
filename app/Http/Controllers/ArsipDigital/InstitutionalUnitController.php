<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Services\ArsipDigital\InstitutionalUnitService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class InstitutionalUnitController extends Controller
{
    public function index(Request $request, RoleResolverService $roles, InstitutionalUnitService $units)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate([
                'with_deleted' => ['sometimes', 'boolean'],
                'search' => ['sometimes', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ]);
            $paginator = $units->paginate($payload);

            return $this->successfulResponseJSON([
                'units' => $paginator->items(),
                'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            ]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roles, InstitutionalUnitService $units)
    {
        try {
            $roles->resolve($request, ['admin']);
            $unit = $units->create($this->payload($request), auth()->user());

            return $this->successfulResponseJSON(['unit' => $unit->toArray()], 'Unit berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $unit_id, RoleResolverService $roles, InstitutionalUnitService $units)
    {
        try {
            $roles->resolve($request, ['admin']);
            $unit = $units->update($unit_id, $this->payload($request, true), auth()->user());

            return $this->successfulResponseJSON(['unit' => $unit->toArray()], 'Unit berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $unit_id, RoleResolverService $roles, InstitutionalUnitService $units)
    {
        try {
            $roles->resolve($request, ['admin']);
            $units->deactivate($unit_id, auth()->user());

            return $this->successfulResponseJSONV2('Unit berhasil dinonaktifkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function restore(Request $request, int $unit_id, RoleResolverService $roles, InstitutionalUnitService $units)
    {
        try {
            $roles->resolve($request, ['admin']);
            $unit = $units->restore($unit_id, auth()->user());

            return $this->successfulResponseJSON(['unit' => $unit->toArray()], 'Unit berhasil dipulihkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function payload(Request $request, bool $update = false): array
    {
        return $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255', 'not_regex:/^\\s*$/'],
            'code' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
