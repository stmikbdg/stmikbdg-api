<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArchiveCategoryService
{
    public function __construct(
        private readonly ArchivePermissionService $permissions,
        private readonly TargetResolverService $targetResolver
    ) {}

    public function queryFor(object $user, string $role, array $filters = []): Builder
    {
        $query = Category::query();

        if (! empty($filters['with_deleted']) && $role === 'admin') {
            $query->withTrashed();
        }

        if ($role === 'admin') {
            if (! empty($filters['category_type'])) {
                $query->where('category_type', $filters['category_type']);
            }

            if (! empty($filters['owner_role'])) {
                $query->where('owner_role', $filters['owner_role']);
            }

            if (! empty($filters['owner_user_id'])) {
                $query->where('owner_user_id', $filters['owner_user_id']);
            }

            return $query->orderBy('category_type')->orderBy('name');
        }

        return $query
            ->where(function (Builder $query) use ($user, $role): void {
                $query->where(function (Builder $query) use ($user, $role): void {
                    $query->where('category_type', 'personal')
                        ->where('owner_user_id', $user->id)
                        ->where('owner_role', $role);
                })->orWhere('category_type', 'official');
            })
            ->orderBy('category_type')
            ->orderBy('name');
    }

    public function systemPersonalCategory(int $ownerUserId, string $ownerRole, string $title, object $actor, string $actorRole): Category
    {
        DB::connection(config('myconfig.database.first_connection'))
            ->table('users')
            ->where('id', $ownerUserId)
            ->lockForUpdate()
            ->first();

        $category = Category::where('owner_user_id', $ownerUserId)
            ->where('owner_role', $ownerRole)
            ->where('category_type', 'personal')
            ->where('name', $title)
            ->where('is_system', true)
            ->lockForUpdate()
            ->first();

        return $category ?? Category::create([
            'owner_user_id' => $ownerUserId,
            'owner_role' => $ownerRole,
            'category_type' => 'personal',
            'name' => $title,
            'visibility' => 'admin_visible',
            'created_by_user_id' => $actor->id,
            'created_by_role' => $actorRole,
            'is_system' => true,
        ]);
    }

    public function create(array $payload, object $user, string $role): Category
    {
        $categoryType = $payload['category_type'] ?? ($role === 'admin' ? 'official' : 'personal');

        if ($role !== 'admin' && $categoryType !== 'personal') {
            throw new HttpException(403, 'Mahasiswa/dosen hanya dapat membuat kategori personal.');
        }

        $ownerUserId = $categoryType === 'personal' ? $user->id : null;
        $ownerRole = $categoryType === 'personal' ? $role : null;

        if ($role === 'admin' && $categoryType === 'personal') {
            $resolved = $this->resolvePersonalOwner($payload);
            $ownerUserId = $resolved['target_user_id'];
            $ownerRole = $resolved['role'];
        }

        $parentId = $payload['parent_category_id'] ?? null;
        if ($parentId !== null) {
            $this->assertParentAllowed((int) $parentId, $user, $role, $categoryType);
        }

        return Category::create([
            'owner_user_id' => $ownerUserId,
            'owner_role' => $ownerRole,
            'category_type' => $categoryType,
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'visibility' => $payload['visibility'] ?? ($categoryType === 'official' ? 'official' : 'admin_visible'),
            'parent_category_id' => $parentId,
            'created_by_user_id' => $user->id,
            'created_by_role' => $role,
            'is_system' => false,
        ]);
    }

    public function update(Category $category, array $payload, object $user, string $role): Category
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($category, $payload, $user, $role): Category {
            $lockedCategory = Category::where('category_id', $category->category_id)->lockForUpdate()->firstOrFail();

            if (! $this->permissions->canManageCategory($lockedCategory, $user, $role)) {
                throw new HttpException(403, 'Tidak memiliki akses mengubah kategori.');
            }

            $parentId = $payload['parent_category_id'] ?? $lockedCategory->parent_category_id;
            if ($parentId !== null) {
                $this->assertParentAllowed((int) $parentId, $user, $role, $lockedCategory->category_type, (int) $lockedCategory->category_id);
            }

            $lockedCategory->fill([
                'name' => $payload['name'] ?? $lockedCategory->name,
                'description' => array_key_exists('description', $payload) ? $payload['description'] : $lockedCategory->description,
                'visibility' => $payload['visibility'] ?? $lockedCategory->visibility,
                'parent_category_id' => $parentId,
            ]);
            $lockedCategory->save();

            return $lockedCategory;
        }, 3);
    }

    public function delete(Category $category, object $user, string $role): void
    {
        DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($category, $user, $role): void {
            $lockedCategory = Category::where('category_id', $category->category_id)->lockForUpdate()->firstOrFail();

            if (! $this->permissions->canManageCategory($lockedCategory, $user, $role)) {
                throw new HttpException(403, 'Tidak memiliki akses menghapus kategori.');
            }

            if (ArchiveFile::where('category_id', $lockedCategory->category_id)->whereNull('deleted_at')->lockForUpdate()->exists()) {
                throw new HttpException(422, 'Kategori masih berisi file. Kosongkan kategori terlebih dahulu.');
            }

            if (Category::where('parent_category_id', $lockedCategory->category_id)->lockForUpdate()->exists()) {
                throw new HttpException(422, 'Kategori masih memiliki subkategori.');
            }

            $lockedCategory->delete();
        }, 3);
    }

    public function restore(int $categoryId): Category
    {
        $category = Category::withTrashed()->findOrFail($categoryId);
        $category->restore();

        return $category;
    }

    private function resolvePersonalOwner(array $payload): array
    {
        $ownerRole = $payload['owner_role'] ?? null;
        if (! in_array($ownerRole, ['mahasiswa', 'dosen'], true)) {
            throw new HttpException(422, 'Owner kategori personal wajib mahasiswa atau dosen.');
        }

        if (! empty($payload['owner_identifier'])) {
            $resolved = $this->targetResolver->resolve($ownerRole, $payload['owner_identifier']);
        } elseif (! empty($payload['owner_user_id'])) {
            $resolved = $this->targetResolver->resolveByUserId($ownerRole, (int) $payload['owner_user_id']);
        } else {
            throw new HttpException(422, 'Owner kategori personal wajib diisi.');
        }

        if (! $resolved['valid']) {
            throw new HttpException(422, $resolved['error']);
        }

        if (! empty($payload['owner_user_id']) && (int) $payload['owner_user_id'] !== (int) $resolved['target_user_id']) {
            throw new HttpException(422, 'Owner user tidak sesuai dengan identifier.');
        }

        return $resolved;
    }

    private function assertParentAllowed(int $parentId, object $user, string $role, string $categoryType, ?int $categoryId = null): void
    {
        $parent = Category::findOrFail($parentId);

        if ($parent->category_type !== $categoryType) {
            throw new HttpException(422, 'Parent kategori harus memiliki tipe kategori yang sama.');
        }

        if (! $this->permissions->canManageCategory($parent, $user, $role)) {
            throw new HttpException(403, 'Tidak memiliki akses ke parent kategori.');
        }

        $seen = [];
        while ($parent !== null) {
            $parentCategoryId = (int) $parent->category_id;
            if (($categoryId !== null && $parentCategoryId === $categoryId) || isset($seen[$parentCategoryId])) {
                throw new HttpException(422, 'Kategori tidak boleh menjadi parent dirinya sendiri atau turunannya.');
            }

            $seen[$parentCategoryId] = true;
            $parent = $parent->parent_category_id !== null ? Category::find($parent->parent_category_id) : null;
        }
    }
}
