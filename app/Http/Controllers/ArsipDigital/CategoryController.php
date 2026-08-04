<?php

namespace App\Http\Controllers\ArsipDigital;

use App\Exceptions\ErrorHandler;
use App\Http\Controllers\Controller;
use App\Models\ArsipDigital\Category;
use App\Services\ArsipDigital\ArchiveCategoryService;
use App\Services\ArsipDigital\AuditLogService;
use App\Services\ArsipDigital\RoleResolverService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request, RoleResolverService $roleResolver, ArchiveCategoryService $categoryService)
    {
        try {
            $role = $roleResolver->resolve($request);

            $filters = $request->validate([
                'category_type' => ['sometimes', 'in:personal,official,distribution'],
                'owner_role' => ['sometimes', 'in:admin,mahasiswa,dosen'],
                'owner_user_id' => ['sometimes', 'integer'],
                'with_deleted' => ['sometimes', 'boolean'],
            ]);

            $categories = $categoryService->queryFor(auth()->user(), $role, $filters)->get();

            return $this->successfulResponseJSON(['categories' => $categories->toArray()]);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function store(
        Request $request,
        RoleResolverService $roleResolver,
        ArchiveCategoryService $categoryService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request);

            $payload = $request->validate([
                'category_type' => ['sometimes', 'in:personal,official'],
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'visibility' => ['sometimes', 'in:private,admin_visible,official'],
                'parent_category_id' => ['nullable', 'integer'],
                'owner_role' => ['sometimes', 'in:mahasiswa,dosen'],
                'owner_identifier' => ['sometimes', 'string', 'max:100'],
                'owner_user_id' => ['sometimes', 'integer'],
            ]);

            $category = $categoryService->create($payload, auth()->user(), $role);

            $auditLogService->record(
                'category.created',
                'category',
                $category->category_id,
                'Kategori arsip digital dibuat.',
                ['category_type' => $category->category_type],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Kategori berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function update(
        Request $request,
        int $category_id,
        RoleResolverService $roleResolver,
        ArchiveCategoryService $categoryService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request);
            $category = Category::findOrFail($category_id);

            $payload = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'visibility' => ['sometimes', 'in:private,admin_visible,official'],
                'parent_category_id' => ['nullable', 'integer'],
            ]);

            $category = $categoryService->update($category, $payload, auth()->user(), $role);

            $auditLogService->record(
                'category.updated',
                'category',
                $category->category_id,
                'Kategori arsip digital diperbarui.',
                ['updated_keys' => array_keys($payload)],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Kategori berhasil diperbarui.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function destroy(
        Request $request,
        int $category_id,
        RoleResolverService $roleResolver,
        ArchiveCategoryService $categoryService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request);
            $category = Category::findOrFail($category_id);

            $categoryService->delete($category, auth()->user(), $role);

            $auditLogService->record(
                'category.deleted',
                'category',
                $category_id,
                'Kategori arsip digital dihapus secara soft delete.',
                [],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSONV2('Kategori berhasil dihapus.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }

    public function restore(
        Request $request,
        int $category_id,
        RoleResolverService $roleResolver,
        ArchiveCategoryService $categoryService,
        AuditLogService $auditLogService
    ) {
        try {
            $role = $roleResolver->resolve($request, ['admin']);
            $category = $categoryService->restore($category_id);

            $auditLogService->record(
                'category.restored',
                'category',
                $category->category_id,
                'Kategori arsip digital direstore oleh admin.',
                [],
                $request,
                auth()->user()?->id,
                $role
            );

            return $this->successfulResponseJSON(['category' => $category->toArray()], 'Kategori berhasil direstore.');
        } catch (\Exception $e) {
            return ErrorHandler::handle($e);
        }
    }
}
