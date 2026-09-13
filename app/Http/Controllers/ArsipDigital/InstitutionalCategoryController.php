<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Category;
use App\Services\ArsipDigital\ArchiveCategoryService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class InstitutionalCategoryController extends Controller
{
    public function index(Request $request, RoleResolverService $roles, ArchiveCategoryService $categories)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $request->validate(['with_deleted' => ['sometimes', 'boolean'], 'search' => ['sometimes', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
            $query = $categories->queryFor(auth()->user(), 'admin', ['category_type' => 'institutional', 'with_deleted' => $payload['with_deleted'] ?? false])
                ->when($payload['search'] ?? null, fn ($query, $search) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']));
            $page = $query->paginate($payload['per_page'] ?? 25);

            return $this->successfulResponseJSON(['categories' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(Request $request, RoleResolverService $roles, ArchiveCategoryService $categories)
    {
        try {
            $roles->resolve($request, ['admin']);
            $payload = $this->payload($request);
            $category = $categories->create($payload + ['category_type' => 'institutional', 'visibility' => 'admin_visible'], auth()->user(), 'admin', ['name' => $payload['name'], 'parent_category_id' => $payload['parent_category_id'] ?? null]);

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Folder berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(Request $request, int $category_id, RoleResolverService $roles, ArchiveCategoryService $categories)
    {
        try {
            $roles->resolve($request, ['admin']);
            $category = Category::where('category_type', 'institutional')->findOrFail($category_id);
            $category = $categories->update($category, $this->payload($request, true), auth()->user(), 'admin');

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Folder berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(Request $request, int $category_id, RoleResolverService $roles, ArchiveCategoryService $categories)
    {
        try {
            $roles->resolve($request, ['admin']);
            $category = Category::where('category_type', 'institutional')->findOrFail($category_id);
            $categories->delete($category, auth()->user(), 'admin');

            return $this->successfulResponseJSONV2('Folder berhasil dinonaktifkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function restore(Request $request, int $category_id, RoleResolverService $roles, ArchiveCategoryService $categories)
    {
        try {
            $roles->resolve($request, ['admin']);
            $category = $categories->restore($category_id, auth()->user(), 'admin');

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Folder berhasil dipulihkan.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    private function payload(Request $request, bool $update = false): array
    {
        return $request->validate(['name' => [$update ? 'sometimes' : 'required', 'string', 'max:255', 'not_regex:/^\s*$/'], 'description' => ['nullable', 'string'], 'parent_category_id' => ['nullable', 'integer']]);
    }
}
