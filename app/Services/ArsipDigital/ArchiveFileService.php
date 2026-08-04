<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Category;
use App\Models\ArsipDigital\DistributionRecipient;
use App\Models\ArsipDigital\RequestFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ArchiveFileService
{
    public function __construct(
        private readonly ArchivePermissionService $permissions,
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArsipDigitalStorageService $storage,
        private readonly TargetResolverService $targetResolver,
        private readonly ArchiveUploadValidationService $uploadValidation,
    ) {}

    public function queryFor(object $user, string $role, array $filters = []): Builder
    {
        $query = ArchiveFile::query()->with(['category', 'requestFile.request']);

        if ($role === 'admin') {
            if (! empty($filters['with_deleted'])) {
                $query->withTrashed();
            }

            if (! empty($filters['owner_role'])) {
                $query->where('owner_role', $filters['owner_role']);
            }

            if (! empty($filters['owner_user_id'])) {
                $query->where('owner_user_id', $filters['owner_user_id']);
            }

            if (! empty($filters['owner_identifier'])) {
                $query->where('owner_identifier', $filters['owner_identifier']);
            }
        } else {
            $query->where('owner_user_id', $user->id)
                ->where('owner_role', $role);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['extension'])) {
            $query->where('extension', strtolower($filters['extension']));
        }

        if (! empty($filters['search'])) {
            $search = '%'.strtolower($filters['search']).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->whereRaw('LOWER(display_filename) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(original_filename) LIKE ?', [$search]);
            });
        }

        if (array_key_exists('is_current', $filters) && $filters['is_current'] !== null) {
            $query->where('is_current', filter_var($filters['is_current'], FILTER_VALIDATE_BOOL));
        } elseif ($role !== 'admin' || empty($filters['with_deleted'])) {
            $query->where('is_current', true)->where('status', 'active');
        }

        return $query->orderByDesc('created_at')->orderByDesc('file_id');
    }

    public function uploadPersonal(UploadedFile $uploadedFile, array $payload, object $user, string $role): ArchiveFile
    {
        if (! in_array($role, ['mahasiswa', 'dosen'], true)) {
            throw new HttpException(403, 'Upload personal hanya tersedia untuk mahasiswa/dosen pada Phase 2.');
        }

        $settings = $this->settings->getDefaults();
        $this->uploadValidation->validateUploadedFile(
            $uploadedFile,
            (int) $settings['default_max_file_size_mb'],
            $settings['default_allowed_extensions']
        );
        $this->assertPersonalQuotaAvailable($user, $role, $uploadedFile);

        $categoryId = ! empty($payload['category_id']) ? (int) $payload['category_id'] : null;
        if ($categoryId !== null) {
            $category = Category::findOrFail($categoryId);

            if (! $this->permissions->canUploadToCategory($category, $user, $role)) {
                throw new HttpException(403, 'Tidak memiliki akses upload ke kategori ini.');
            }
        }

        $resolved = $this->targetResolver->resolveCurrentUser($user, $role);
        if (! $resolved['valid']) {
            throw new HttpException(422, $resolved['error']);
        }

        $storageMetadata = $this->storage->uploadPrivate($uploadedFile, 'personal', [
            'owner_user_id' => $user->id,
            'category_id' => $categoryId,
        ]);
        $displayFilename = $payload['display_filename'] ?? $storageMetadata['display_filename'];
        $normalizedDisplayFilename = $this->normalizeDisplayFilename($displayFilename);

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use (
                $user,
                $role,
                $categoryId,
                $resolved,
                $storageMetadata,
                $displayFilename,
                $normalizedDisplayFilename,
                $payload,
                $uploadedFile
            ): ArchiveFile {
                $this->lockOwner($user->id);
                $this->assertPersonalQuotaAvailable($user, $role, $uploadedFile);

                if ($categoryId !== null) {
                    $category = Category::where('category_id', $categoryId)->lockForUpdate()->first();
                    if (! $category || ! $this->permissions->canUploadToCategory($category, $user, $role)) {
                        throw new HttpException(403, 'Kategori tujuan tidak dapat digunakan.');
                    }
                }

                $version = $this->nextPersonalVersion($user->id, $role, $categoryId, $normalizedDisplayFilename);

                $currentQuery = ArchiveFile::where('owner_user_id', $user->id)
                    ->where('owner_role', $role)
                    ->whereIn('source_type', ['personal', 'admin_upload'])
                    ->where('display_filename', $normalizedDisplayFilename)
                    ->where('is_current', true)
                    ->whereNull('deleted_at');
                $this->applyCategoryFilter($currentQuery, $categoryId);
                $currentQuery->update([
                    'is_current' => false,
                    'status' => 'replaced',
                    'updated_at' => now(),
                ]);

                return ArchiveFile::create([
                    'category_id' => $categoryId,
                    'owner_user_id' => $user->id,
                    'owner_role' => $role,
                    'owner_identifier' => $resolved['identifier'],
                    'owner_name_snapshot' => $resolved['name_snapshot'],
                    'owner_status_snapshot' => $resolved['status_snapshot'],
                    'uploaded_by_user_id' => $user->id,
                    'uploaded_by_role' => $role,
                    'source_type' => 'personal',
                    'original_filename' => $storageMetadata['original_filename'],
                    'display_filename' => $normalizedDisplayFilename,
                    'storage_disk' => $storageMetadata['storage_disk'],
                    'storage_path' => $storageMetadata['storage_path'],
                    'mime_type' => $storageMetadata['mime_type'],
                    'extension' => $storageMetadata['extension'],
                    'file_size_bytes' => $storageMetadata['file_size_bytes'],
                    'checksum_sha256' => $storageMetadata['checksum_sha256'],
                    'version_group_uuid' => $version['version_group_uuid'],
                    'version_number' => $version['version_number'],
                    'is_current' => true,
                    'status' => 'active',
                    'metadata' => array_merge($payload['metadata'] ?? [], [
                        'requested_display_filename' => $displayFilename,
                        'note' => $payload['note'] ?? null,
                    ]),
                ]);
            }, 3);
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($storageMetadata['storage_disk'], $storageMetadata['storage_path']);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    public function uploadSignatureRequestResult(UploadedFile $uploadedFile, object $student, object $lecturer, int $requestId, string $lecturerName, array $metadata = []): ArchiveFile
    {
        $resolved = $this->targetResolver->resolveCurrentUser($student, 'mahasiswa');
        if (! $resolved['valid']) {
            throw new HttpException(422, $resolved['error']);
        }

        $folderName = 'request_ttd_'.(Str::slug($lecturerName, '_') ?: 'dosen');
        $storageMetadata = $this->storage->uploadPrivate($uploadedFile, 'request', [
            'request_id' => 'signature-'.$requestId,
            'assignment_id' => $student->id,
        ]);
        $displayFilename = $this->normalizeDisplayFilename($uploadedFile->getClientOriginalName());

        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($student, $lecturer, $requestId, $resolved, $folderName, $storageMetadata, $displayFilename, $metadata): ArchiveFile {
                $this->lockOwner($student->id);
                $category = Category::where('owner_user_id', $student->id)
                    ->where('owner_role', 'mahasiswa')
                    ->where('category_type', 'personal')
                    ->where('name', $folderName)
                    ->lockForUpdate()
                    ->first();
                if (! $category) {
                    $category = Category::create([
                        'owner_user_id' => $student->id,
                        'owner_role' => 'mahasiswa',
                        'category_type' => 'personal',
                        'name' => $folderName,
                        'visibility' => 'admin_visible',
                        'created_by_user_id' => $lecturer->id,
                        'created_by_role' => 'dosen',
                        'is_system' => false,
                    ]);
                }

                $latest = ArchiveFile::withTrashed()
                    ->where('owner_user_id', $student->id)
                    ->where('owner_role', 'mahasiswa')
                    ->where('source_type', 'request')
                    ->where('category_id', $category->category_id)
                    ->where('display_filename', $displayFilename)
                    ->orderByDesc('version_number')
                    ->lockForUpdate()
                    ->first();
                ArchiveFile::where('owner_user_id', $student->id)
                    ->where('owner_role', 'mahasiswa')
                    ->where('source_type', 'request')
                    ->where('category_id', $category->category_id)
                    ->where('display_filename', $displayFilename)
                    ->where('is_current', true)
                    ->whereNull('deleted_at')
                    ->update(['is_current' => false, 'status' => 'replaced', 'updated_at' => now()]);

                return ArchiveFile::create([
                    'category_id' => $category->category_id,
                    'owner_user_id' => $student->id,
                    'owner_role' => 'mahasiswa',
                    'owner_identifier' => $resolved['identifier'],
                    'owner_name_snapshot' => $resolved['name_snapshot'],
                    'owner_status_snapshot' => $resolved['status_snapshot'],
                    'uploaded_by_user_id' => $lecturer->id,
                    'uploaded_by_role' => 'dosen',
                    'source_type' => 'request',
                    'original_filename' => $storageMetadata['original_filename'],
                    'display_filename' => $displayFilename,
                    'storage_disk' => $storageMetadata['storage_disk'],
                    'storage_path' => $storageMetadata['storage_path'],
                    'mime_type' => $storageMetadata['mime_type'],
                    'extension' => $storageMetadata['extension'],
                    'file_size_bytes' => $storageMetadata['file_size_bytes'],
                    'checksum_sha256' => $storageMetadata['checksum_sha256'],
                    'version_group_uuid' => $latest?->version_group_uuid ?? (string) Str::uuid(),
                    'version_number' => $latest ? $latest->version_number + 1 : 1,
                    'is_current' => true,
                    'status' => 'active',
                    'metadata' => array_merge($metadata, ['folder' => $folderName, 'signature_request_id' => $requestId]),
                ]);
            }, 3);
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($storageMetadata['storage_disk'], $storageMetadata['storage_path']);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function move(array $fileIds, ?int $categoryId, object $user, string $role): array
    {
        if (! in_array($role, ['mahasiswa', 'dosen'], true)) {
            throw new HttpException(403, 'Pemindahan file hanya tersedia untuk mahasiswa/dosen.');
        }

        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($fileIds, $categoryId, $user, $role): array {
            $this->lockOwner($user->id);

            if ($categoryId !== null) {
                $category = Category::where('category_id', $categoryId)->lockForUpdate()->first();
                if (! $category || ! $this->permissions->canUploadToCategory($category, $user, $role)) {
                    throw new HttpException(403, 'Kategori tujuan tidak dapat digunakan.');
                }
            }

            $files = ArchiveFile::whereIn('file_id', $fileIds)->orderBy('file_id')->lockForUpdate()->get();

            if ($files->count() !== count($fileIds)) {
                throw new HttpException(422, 'Sebagian file yang dipilih tidak valid.');
            }

            foreach ($files as $file) {
                if (! $this->permissions->canViewFile($file, $user, $role)) {
                    throw new HttpException(403, 'Tidak memiliki akses memindahkan file.');
                }

                if (! $file->is_current || $file->status !== 'active' || ! in_array($file->source_type, ['personal', 'admin_upload'], true)) {
                    throw new HttpException(422, 'Hanya file arsip pribadi aktif yang dapat dipindahkan.');
                }

                if (RequestFile::where('file_id', $file->file_id)->exists()) {
                    throw new HttpException(422, 'File workflow tidak dapat dipindahkan.');
                }
            }

            $versionGroups = $files->pluck('version_group_uuid')->unique()->values();
            $duplicateNames = $files->groupBy('display_filename')
                ->contains(fn ($sameNameFiles): bool => $sameNameFiles->pluck('version_group_uuid')->unique()->count() > 1);

            if ($duplicateNames) {
                throw new HttpException(409, 'Terdapat nama file yang sama pada pilihan pemindahan.');
            }

            $conflictQuery = ArchiveFile::where('owner_user_id', $user->id)
                ->where('owner_role', $role)
                ->whereIn('source_type', ['personal', 'admin_upload'])
                ->where('is_current', true)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereIn('display_filename', $files->pluck('display_filename')->unique())
                ->whereNotIn('version_group_uuid', $versionGroups)
                ->lockForUpdate();
            $this->applyCategoryFilter($conflictQuery, $categoryId);

            if ($conflictQuery->orderBy('file_id')->first()) {
                throw new HttpException(409, 'Kategori tujuan sudah memiliki file aktif dengan nama yang sama.');
            }

            $versions = ArchiveFile::withTrashed()
                ->whereIn('version_group_uuid', $versionGroups)
                ->orderBy('file_id')
                ->lockForUpdate()
                ->get();

            $invalidVersion = $versions->contains(fn (ArchiveFile $file): bool => $file->owner_user_id !== $user->id || $file->owner_role !== $role);
            if ($invalidVersion) {
                throw new HttpException(403, 'Riwayat versi file tidak dapat dipindahkan.');
            }

            if (RequestFile::whereIn('file_id', $versions->pluck('file_id'))->exists()) {
                throw new HttpException(422, 'File workflow tidak dapat dipindahkan.');
            }

            $activeVersions = $versions->whereNull('deleted_at');
            ArchiveFile::whereIn('file_id', $activeVersions->pluck('file_id'))->update([
                'category_id' => $categoryId,
                'updated_at' => now(),
            ]);

            return [
                'file_ids' => array_values($fileIds),
                'category_id' => $categoryId,
                'moved_files' => $files->count(),
                'moved_versions' => $activeVersions->count(),
            ];
        }, 3);
    }

    private function lockOwner(int $ownerUserId): void
    {
        DB::connection(config('myconfig.database.first_connection'))
            ->table('users')
            ->where('id', $ownerUserId)
            ->lockForUpdate()
            ->first();
    }

    public function versionsFor(int $fileId, object $user, string $role)
    {
        $anchor = $this->findVisible($fileId, $user, $role);

        if (! in_array($anchor->source_type, ['personal', 'admin_upload'], true)) {
            throw new HttpException(422, 'Riwayat versi hanya tersedia untuk file arsip pribadi.');
        }

        return ArchiveFile::where('version_group_uuid', $anchor->version_group_uuid)
            ->where('owner_user_id', $anchor->owner_user_id)
            ->where('owner_role', $anchor->owner_role)
            ->whereIn('source_type', ['personal', 'admin_upload'])
            ->orderByDesc('version_number')
            ->orderByDesc('file_id')
            ->get();
    }

    public function findVisible(int $fileId, object $user, string $role, bool $withTrashed = false): ArchiveFile
    {
        $query = ArchiveFile::query();

        if ($withTrashed && $role === 'admin') {
            $query->withTrashed();
        }

        $file = $query->findOrFail($fileId);

        if (! $this->permissions->canViewFile($file, $user, $role)) {
            throw new HttpException(403, 'Tidak memiliki akses ke file.');
        }

        return $file;
    }

    public function delete(ArchiveFile $file, object $user, string $role, ?string $reason = null): ArchiveFile
    {
        $storage = null;

        $deletedFile = DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($file, $user, $role, $reason, &$storage): ArchiveFile {
            $this->lockOwner((int) $file->owner_user_id);
            $lockedFile = ArchiveFile::where('file_id', $file->file_id)->lockForUpdate()->firstOrFail();

            if (! $this->permissions->canViewFile($lockedFile, $user, $role)) {
                throw new HttpException(403, 'Tidak memiliki akses menghapus file.');
            }

            if (! $this->permissions->canDeleteFile($lockedFile, $user, $role)) {
                throw new HttpException(403, 'File workflow tidak dapat dihapus dari Arsip Pengguna.');
            }

            if (! in_array($lockedFile->source_type, ['personal', 'admin_upload'], true)) {
                throw new HttpException(403, 'File workflow tidak dapat dihapus dari Arsip Pengguna.');
            }

            if (RequestFile::withTrashed()->where('file_id', $lockedFile->file_id)->lockForUpdate()->first()) {
                throw new HttpException(409, 'File sedang dipakai pada request berkas.');
            }

            if (DistributionRecipient::withTrashed()->where('file_id', $lockedFile->file_id)->lockForUpdate()->first()) {
                throw new HttpException(409, 'File sedang dipakai pada distribusi berkas.');
            }

            if ($role !== 'admin' && in_array($lockedFile->source_type, ['personal', 'admin_upload'], true)) {
                $storage = [$lockedFile->storage_disk, $lockedFile->storage_path];
                $lockedFile->forceDelete();

                return $lockedFile;
            }

            $lockedFile->fill([
                'status' => 'deleted',
                'deleted_by_user_id' => $user->id,
                'deleted_by_role' => $role,
                'delete_source' => $role === 'admin' ? 'admin' : 'owner',
                'delete_reason' => $reason,
            ]);
            $lockedFile->save();
            $lockedFile->delete();

            return $lockedFile;
        }, 3);

        if ($storage) {
            $this->storage->deletePrivate($storage[0], $storage[1]);
        }

        return $deletedFile;
    }

    public function restore(int $fileId): ArchiveFile
    {
        return DB::connection(config('myconfig.database.first_connection'))->transaction(function () use ($fileId): ArchiveFile {
            $candidate = ArchiveFile::onlyTrashed()->findOrFail($fileId);
            $this->lockOwner((int) $candidate->owner_user_id);

            if ($candidate->category_id !== null && ! Category::where('category_id', $candidate->category_id)->lockForUpdate()->first()) {
                throw new HttpException(422, 'Kategori file sudah tidak tersedia.');
            }

            $versions = ArchiveFile::withTrashed()
                ->where('version_group_uuid', $candidate->version_group_uuid)
                ->orderBy('file_id')
                ->lockForUpdate()
                ->get();
            $file = $versions->firstWhere('file_id', $fileId);
            $hasActiveCurrentReplacement = $versions->contains(fn (ArchiveFile $version): bool => $version->file_id !== $file->file_id && $version->deleted_at === null && $version->is_current);

            $file->restore();
            $file->fill([
                'status' => $hasActiveCurrentReplacement ? 'replaced' : 'active',
                'is_current' => ! $hasActiveCurrentReplacement,
                'deleted_by_user_id' => null,
                'deleted_by_role' => null,
                'delete_source' => null,
                'delete_reason' => null,
            ]);
            $file->save();

            return $file;
        }, 3);
    }

    public function personalUsageBytes(int $ownerUserId, string $role): int
    {
        return (int) ArchiveFile::where('owner_user_id', $ownerUserId)
            ->where('owner_role', $role)
            ->whereIn('source_type', ['personal', 'admin_upload'])
            ->whereNull('deleted_at')
            ->sum('file_size_bytes');
    }

    public function normalizeDisplayFilename(string $filename): string
    {
        return $this->storage->safeFilename($filename);
    }

    private function assertPersonalQuotaAvailable(object $user, string $role, UploadedFile $uploadedFile): void
    {
        $quotaMb = $this->settings->personalQuotaMbForRole($role);
        if ($quotaMb === null) {
            return;
        }

        $usedBytes = $this->personalUsageBytes($user->id, $role);
        $quotaBytes = $quotaMb * 1024 * 1024;
        $uploadBytes = (int) ($uploadedFile->getSize() ?: 0);

        if ($usedBytes + $uploadBytes > $quotaBytes) {
            $remainingMb = max(0, round(($quotaBytes - $usedBytes) / 1024 / 1024, 2));
            throw ValidationException::withMessages([
                'file' => "Kuota penyimpanan arsip pribadi sudah tidak cukup. Sisa kuota: {$remainingMb} MB.",
            ]);
        }
    }

    private function nextPersonalVersion(int $ownerUserId, string $role, ?int $categoryId, string $displayFilename): array
    {
        $query = ArchiveFile::withTrashed()
            ->where('owner_user_id', $ownerUserId)
            ->where('owner_role', $role)
            ->whereIn('source_type', ['personal', 'admin_upload'])
            ->where('display_filename', $displayFilename)
            ->orderByDesc('version_number');
        $this->applyCategoryFilter($query, $categoryId);
        $latest = $query->first();

        if (! $latest) {
            return [
                'version_group_uuid' => (string) Str::uuid(),
                'version_number' => 1,
            ];
        }

        return [
            'version_group_uuid' => $latest->version_group_uuid,
            'version_number' => $latest->version_number + 1,
        ];
    }

    private function applyCategoryFilter(Builder $query, ?int $categoryId): void
    {
        if ($categoryId === null) {
            $query->whereNull('category_id');

            return;
        }

        $query->where('category_id', $categoryId);
    }
}
