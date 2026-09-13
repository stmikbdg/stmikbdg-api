<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\AuditLog;
use App\Models\ArsipDigital\Category;
use App\Models\ArsipDigital\InstitutionalArchive;
use App\Models\ArsipDigital\InstitutionalUnit;
use App\Models\Users\Admin;
use App\Models\Users\Dosen;
use App\Models\Users\MahasiswaView;
use App\Models\Users\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InstitutionalArchiveService
{
    public function __construct(
        private readonly ArsipDigitalStorageService $storage,
        private readonly ArsipDigitalSettingsService $settings,
        private readonly ArchiveUploadValidationService $validation,
        private readonly InstitutionalArchiveVerificationService $verifications,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $sorts = ['document_date' => 'document_date', 'created_at' => 'created_at', 'title' => 'title', 'file_size' => 'currentFile.file_size_bytes'];
        $sort = $filters['sort'] ?? 'created_at';
        $query = InstitutionalArchive::query()->with(['unit', 'category', 'currentFile', 'currentVerification']);
        if ($search = trim($filters['search'] ?? '')) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(coalesce(document_number, \'\')) like ?', [$term])->orWhereRaw('lower(coalesce(description, \'\')) like ?', [$term])->orWhereHas('currentFile', fn ($f) => $f->whereRaw('lower(original_filename) like ?', [$term])));
        }
        foreach (['unit_id', 'document_year', 'access_level'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($filters['category_id'] ?? null) === 'root') {
            $query->whereNull('category_id');
        } elseif (array_key_exists('category_id', $filters)) {
            $query->where('category_id', $filters['category_id']);
        }
        if (array_key_exists('storage_availability', $filters)) {
            $query->whereHas('currentFile', fn ($q) => $q->where('storage_availability', $filters['storage_availability']));
        }
        if (array_key_exists('uploader_id', $filters)) {
            $query->whereHas('currentFile', fn ($q) => $q->where('uploaded_by_user_id', $filters['uploader_id']));
        }
        if ($sort === 'file_size') {
            $query->join('arsip_digital.files as current_file_sort', 'current_file_sort.file_id', '=', 'arsip_digital.institutional_archives.current_file_id')->select('institutional_archives.*')->orderBy('current_file_sort.file_size_bytes', $filters['direction'] ?? 'desc');
        } else {
            $query->orderBy($sorts[$sort], $filters['direction'] ?? 'desc');
        }
        $query->orderBy($sort === 'file_size' ? 'institutional_archives.institutional_archive_id' : 'institutional_archive_id', $filters['direction'] ?? 'desc');

        $paginator = $query->paginate($filters['per_page'] ?? 25);
        $paginator->getCollection()->each(fn (InstitutionalArchive $archive) => $archive->currentFile?->makeHidden(['storage_disk', 'storage_path']));

        return $paginator;
    }

    public function create(UploadedFile $uploaded, array $payload, object $actor): InstitutionalArchive
    {
        $defaults = $this->settings->getDefaults();
        $this->validation->validateUploadedFile($uploaded, $defaults['default_max_file_size_mb'], $defaults['default_allowed_extensions']);
        $payload = $this->normalize($payload);
        $this->assertReferences($payload);
        $this->assertDocumentNumberUnique($payload);
        $stored = $this->storage->uploadPrivate($uploaded, 'institutional', ['owner_user_id' => $actor->id]);
        try {
            return $this->transaction(function () use ($stored, $payload, $actor): InstitutionalArchive {
                $archive = InstitutionalArchive::create([...$payload, 'archive_uuid' => (string) Str::uuid(), 'created_by_user_id' => $actor->id]);
                $file = ArchiveFile::create([...$stored, 'category_id' => $archive->category_id, 'owner_user_id' => $actor->id, 'owner_role' => 'admin', 'owner_identifier' => (string) ($actor->kd_user ?? $actor->id), 'owner_name_snapshot' => $actor->name ?? null, 'uploaded_by_user_id' => $actor->id, 'uploaded_by_role' => 'admin', 'source_type' => 'institutional', 'institutional_archive_id' => $archive->institutional_archive_id, 'version_group_uuid' => (string) Str::uuid(), 'version_number' => 1, 'is_current' => true, 'status' => 'active', 'storage_availability' => 'available']);
                $archive->current_file_id = $file->file_id;
                $archive->save();
                $archive->load('unit');
                $this->verifications->create($archive, $file, $actor);
                $this->audit('institutional_archive.created', $archive, $actor, ['unit_id' => $archive->unit_id, 'category_id' => $archive->category_id, 'file_id' => $file->file_id]);

                return $this->find($archive->institutional_archive_id);
            });
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($stored['storage_disk'], $stored['storage_path']);
            } catch (\Throwable $cleanup) {
                Log::error('Institutional archive upload cleanup failed.', ['disk' => $stored['storage_disk'], 'exception' => $cleanup::class]);
            }
            throw $e;
        }
    }

    public function find(int $id, bool $withDeleted = false): InstitutionalArchive
    {
        $query = InstitutionalArchive::with(['unit', 'category', 'currentFile', 'currentVerification']);
        if ($withDeleted) {
            $query->withTrashed();
        }
        $archive = $query->findOrFail($id);
        $archive->currentFile?->makeHidden(['storage_disk', 'storage_path']);

        return $archive;
    }

    public function update(int $id, array $payload, object $actor): InstitutionalArchive
    {
        return $this->transaction(function () use ($id, $payload, $actor): InstitutionalArchive {
            $archive = InstitutionalArchive::whereKey($id)->lockForUpdate()->firstOrFail();
            $payload = $this->normalize($payload, $archive);
            $this->assertReferences($payload);
            $this->assertDocumentNumberUnique($payload, $id);
            $fields = ['title', 'document_number', 'document_year', 'document_date', 'received_date', 'unit_id', 'description', 'access_level', 'retention_note', 'tags'];
            $before = $archive->only($fields);
            $archive->fill($payload + ['updated_by_user_id' => $actor->id])->save();
            $after = $archive->only($fields);
            if ($before !== $after) {
                $changed = array_keys(array_filter($after, fn ($value, $field) => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));
                $this->audit('institutional_archive.metadata_updated', $archive, $actor, ['changed_fields' => $changed, 'before' => $before, 'after' => $after]);
            }

            return $this->find($id);
        });
    }

    public function move(int $id, ?int $categoryId, object $actor): InstitutionalArchive
    {
        return $this->transaction(function () use ($id, $categoryId, $actor): InstitutionalArchive {
            $archive = InstitutionalArchive::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCategory($categoryId);
            $before = $archive->category_id;
            $archive->fill(['category_id' => $categoryId, 'updated_by_user_id' => $actor->id])->save();
            ArchiveFile::whereKey($archive->current_file_id)->update(['category_id' => $categoryId]);
            if ($before !== $categoryId) {
                $this->audit('institutional_archive.moved', $archive, $actor, ['before_category_id' => $before, 'after_category_id' => $categoryId]);
            }

            return $this->find($id);
        });
    }

    public function uploadVersion(int $id, UploadedFile $uploaded, string $reason, object $actor): InstitutionalArchive
    {
        $defaults = $this->settings->getDefaults();
        $this->validation->validateUploadedFile($uploaded, $defaults['default_max_file_size_mb'], $defaults['default_allowed_extensions']);
        $stored = $this->storage->uploadPrivate($uploaded, 'institutional', ['owner_user_id' => $actor->id]);

        try {
            return $this->transaction(function () use ($id, $stored, $reason, $actor): InstitutionalArchive {
                $archive = InstitutionalArchive::whereKey($id)->lockForUpdate()->firstOrFail();
                $current = ArchiveFile::whereKey($archive->current_file_id)->where('institutional_archive_id', $id)->where('is_current', true)->firstOrFail();
                $maxVersion = (int) ArchiveFile::where('institutional_archive_id', $id)->max('version_number');

                // Deferred archive trigger validates final pointer; immediate file trigger requires clearing it before retirement.
                $archive->current_file_id = null;
                $archive->save();
                $current->fill(['is_current' => false, 'status' => 'replaced'])->save();
                $file = ArchiveFile::create([...$stored, 'category_id' => $archive->category_id, 'owner_user_id' => $actor->id, 'owner_role' => 'admin', 'owner_identifier' => (string) ($actor->kd_user ?? $actor->id), 'owner_name_snapshot' => $actor->name ?? null, 'uploaded_by_user_id' => $actor->id, 'uploaded_by_role' => 'admin', 'source_type' => 'institutional', 'institutional_archive_id' => $id, 'version_group_uuid' => $current->version_group_uuid, 'version_number' => $maxVersion + 1, 'is_current' => true, 'status' => 'active', 'storage_availability' => 'available', 'metadata' => ['version_reason' => trim($reason)]]);
                $archive->fill(['current_file_id' => $file->file_id, 'updated_by_user_id' => $actor->id])->save();
                $archive->load('unit');
                $newVerification = $this->verifications->create($archive, $file, $actor);
                \App\Models\ArsipDigital\InstitutionalArchiveVerification::where('source_file_id', $current->file_id)->whereNotIn('status', ['replaced', 'revoked'])->update(['status' => 'replaced', 'replaced_at' => now(), 'replaced_by_verification_id' => $newVerification->getKey()]);
                $this->audit('institutional_archive.file_version_uploaded', $archive, $actor, ['file_id' => $file->file_id, 'previous_file_id' => $current->file_id, 'version_number' => $file->version_number, 'reason' => trim($reason)]);

                return $this->find($id);
            });
        } catch (\Throwable $e) {
            try {
                $this->storage->deletePrivate($stored['storage_disk'], $stored['storage_path']);
            } catch (\Throwable $cleanup) {
                Log::error('Institutional archive version cleanup failed.', ['disk' => $stored['storage_disk'], 'exception' => $cleanup::class]);
            }
            throw $e;
        }
    }

    public function versions(int $id, int $perPage): LengthAwarePaginator
    {
        InstitutionalArchive::findOrFail($id);
        $paginator = ArchiveFile::with('institutionalVerification')->where('institutional_archive_id', $id)
            ->orderByDesc('version_number')->orderByDesc('file_id')->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(fn (ArchiveFile $file): array => [
            'file_id' => $file->file_id,
            'display_filename' => $file->display_filename,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'file_size_bytes' => $file->file_size_bytes,
            'checksum_sha256' => $file->checksum_sha256,
            'version_number' => $file->version_number,
            'is_current' => $file->is_current,
            'status' => $file->status,
            'storage_availability' => $file->storage_availability,
            'uploaded_by_user_id' => $file->uploaded_by_user_id,
            'created_at' => $file->created_at,
            'version_reason' => $file->metadata['version_reason'] ?? null,
            'verification' => $file->institutionalVerification?->verification_summary,
        ]));

        return $paginator;
    }

    public function version(int $id, int $fileId): ArchiveFile
    {
        return ArchiveFile::whereKey($fileId)->where('institutional_archive_id', $id)->where('source_type', 'institutional')->firstOrFail();
    }

    public function trash(array $filters): LengthAwarePaginator
    {
        $sorts = ['deleted_at', 'title', 'document_date', 'created_at'];
        $sort = $filters['sort'] ?? 'deleted_at';
        $query = InstitutionalArchive::onlyTrashed()->with(['unit', 'category', 'currentFile']);
        if ($search = trim($filters['search'] ?? '')) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(coalesce(document_number, \'\')) like ?', [$term]));
        }
        foreach (['unit_id', 'document_year'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
        $paginator = $query->orderBy($sort, $filters['direction'] ?? 'desc')->orderByDesc('institutional_archive_id')->paginate($filters['per_page'] ?? 25);
        $paginator->setCollection($paginator->getCollection()->map(fn (InstitutionalArchive $archive): array => [
            'institutional_archive_id' => $archive->institutional_archive_id,
            'title' => $archive->title,
            'document_number' => $archive->document_number,
            'document_year' => $archive->document_year,
            'document_date' => $archive->document_date,
            'unit' => $archive->unit ? ['unit_id' => $archive->unit->unit_id, 'name' => $archive->unit->name] : null,
            'category' => $archive->category ? ['category_id' => $archive->category->category_id, 'name' => $archive->category->name] : null,
            'current_file' => $archive->currentFile ? ['display_filename' => $archive->currentFile->display_filename, 'file_size_bytes' => $archive->currentFile->file_size_bytes] : null,
            'deleted_by_user_id' => $archive->deleted_by_user_id,
            'deleted_at' => $archive->deleted_at,
            'delete_reason' => $archive->delete_reason,
        ]));

        return $paginator;
    }

    public function delete(int $id, string $reason, object $actor): InstitutionalArchive
    {
        return $this->transaction(function () use ($id, $reason, $actor): InstitutionalArchive {
            $archive = InstitutionalArchive::whereKey($id)->lockForUpdate()->firstOrFail();
            $archive->fill(['status' => 'deleted', 'deleted_by_user_id' => $actor->id, 'delete_reason' => trim($reason), 'deleted_at' => now(), 'updated_by_user_id' => $actor->id])->save();
            \App\Models\ArsipDigital\InstitutionalArchiveVerification::where('institutional_archive_id', $id)->whereNotIn('status', ['replaced', 'revoked'])->update(['status' => 'revoked', 'revoked_at' => now()]);
            $this->audit('institutional_archive.deleted', $archive, $actor, ['reason' => trim($reason), 'before' => ['status' => 'active'], 'after' => ['status' => 'deleted']]);

            return $this->find($id, true);
        });
    }

    public function restore(int $id, object $actor): InstitutionalArchive
    {
        return $this->transaction(function () use ($id, $actor): InstitutionalArchive {
            $archive = InstitutionalArchive::onlyTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertDocumentNumberUnique($archive->only(['document_number', 'document_year', 'unit_id']), $id);
            $reason = $archive->delete_reason;
            $archive->fill(['status' => 'active', 'deleted_by_user_id' => null, 'delete_reason' => null, 'deleted_at' => null, 'updated_by_user_id' => $actor->id])->save();
            $this->audit('institutional_archive.restored', $archive, $actor, ['delete_reason' => $reason, 'before' => ['status' => 'deleted'], 'after' => ['status' => 'active']]);

            return $this->find($id);
        });
    }

    public function timeline(int $id, int $perPage): LengthAwarePaginator
    {
        InstitutionalArchive::withTrashed()->findOrFail($id);
        $allowed = ['institutional_archive.created', 'institutional_archive.metadata_updated', 'institutional_archive.file_version_uploaded', 'institutional_archive.moved', 'institutional_archive.deleted', 'institutional_archive.restored', 'institutional_archive.downloaded_by_admin'];
        $paginator = AuditLog::where('entity_type', 'institutional_archive')->where('entity_id', (string) $id)->whereIn('action', $allowed)->orderByDesc('created_at')->orderByDesc('audit_log_id')->paginate($perPage);
        $actorNames = $this->actorDisplayNames($paginator->getCollection()->pluck('actor_user_id')->filter()->unique()->values()->all());
        $labels = [
            'institutional_archive.created' => 'Arsip dibuat', 'institutional_archive.metadata_updated' => 'Metadata diperbarui',
            'institutional_archive.file_version_uploaded' => 'Versi baru diupload', 'institutional_archive.moved' => 'Folder dipindahkan',
            'institutional_archive.deleted' => 'Arsip dihapus', 'institutional_archive.restored' => 'Arsip dipulihkan',
            'institutional_archive.downloaded_by_admin' => 'Arsip diunduh',
        ];
        $paginator->setCollection($paginator->getCollection()->map(function (AuditLog $log) use ($labels, $actorNames): array {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $reason = $this->safeScalar($metadata['reason'] ?? $metadata['delete_reason'] ?? null);

            return [
                'audit_log_id' => $log->audit_log_id, 'action' => $log->action, 'label' => $labels[$log->action],
                'actor_display_name' => $actorNames[$log->actor_user_id] ?? null, 'actor_role' => $log->actor_role, 'occurred_at' => $log->created_at,
                'reason' => $reason, 'changed_fields' => $this->safeChangedFields($metadata['changed_fields'] ?? null),
                'before' => $this->safeAuditState($metadata['before'] ?? null), 'after' => $this->safeAuditState($metadata['after'] ?? null),
                'version_number' => is_int($metadata['version_number'] ?? null) ? $metadata['version_number'] : null,
                'before_category_id' => is_int($metadata['before_category_id'] ?? null) ? $metadata['before_category_id'] : null,
                'after_category_id' => is_int($metadata['after_category_id'] ?? null) ? $metadata['after_category_id'] : null,
            ];
        }));

        return $paginator;
    }

    private function actorDisplayNames(array $actorIds): array
    {
        if ($actorIds === []) {
            return [];
        }
        $users = User::query()->whereIn('id', $actorIds)->get(['id', 'kd_user']);
        $codes = ['ADM' => [], 'DSN' => [], 'MHS' => []];
        foreach ($users as $user) {
            [$prefix, $code] = array_pad(explode('-', (string) $user->kd_user, 2), 2, null);
            if ($code && isset($codes[$prefix])) {
                $codes[$prefix][] = $code;
            }
        }
        $profiles = [];
        try {
            if ($codes['ADM']) {
                foreach (Admin::query()->whereIn('kd_admin', $codes['ADM'])->get(['kd_admin', 'nm_admin']) as $profile) {
                    $profiles['ADM-'.$profile->kd_admin] = $profile->nm_admin;
                }
            }
            if ($codes['DSN']) {
                foreach (Dosen::query()->whereIn('kd_dosen', $codes['DSN'])->get(['kd_dosen', 'nm_dosen']) as $profile) {
                    $profiles['DSN-'.$profile->kd_dosen] = $profile->nm_dosen;
                }
            }
            if ($codes['MHS']) {
                foreach (MahasiswaView::query()->whereIn('nim', $codes['MHS'])->get(['nim', 'nm_mhs']) as $profile) {
                    $profiles['MHS-'.$profile->nim] = $profile->nm_mhs;
                }
            }
        } catch (QueryException) {
            return [];
        }

        return $users->mapWithKeys(function (User $user) use ($profiles): array {
            $name = trim((string) ($profiles[$user->kd_user] ?? ''));

            return [$user->id => $name !== '' ? $name : null];
        })->all();
    }

    public function auditDownload(InstitutionalArchive $archive, object $actor, ?ArchiveFile $file = null): void
    {
        $this->audit('institutional_archive.downloaded_by_admin', $archive, $actor, ['file_id' => $file?->file_id ?? $archive->current_file_id, 'version_number' => $file?->version_number]);
    }

    private function safeScalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function safeChangedFields(mixed $value): array
    {
        $allowed = ['title', 'document_number', 'document_year', 'document_date', 'received_date', 'unit_id', 'category_id', 'description', 'access_level', 'retention_note', 'tags', 'status'];

        return is_array($value) ? array_values(array_intersect($allowed, array_filter($value, 'is_string'))) : [];
    }

    private function safeAuditState(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $allowed = ['title', 'document_number', 'document_year', 'document_date', 'received_date', 'unit_id', 'category_id', 'description', 'access_level', 'retention_note', 'tags', 'status'];
        $safe = [];
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $value)) {
                continue;
            }
            if ($field === 'tags') {
                $safe[$field] = is_array($value[$field]) ? array_values(array_filter($value[$field], 'is_string')) : [];
            } elseif (is_scalar($value[$field]) || $value[$field] === null) {
                $safe[$field] = $value[$field];
            }
        }

        return $safe;
    }

    private function normalize(array $payload, ?InstitutionalArchive $existing = null): array
    {
        foreach (['title', 'document_number', 'description', 'retention_note'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = trim((string) $payload[$field]) ?: null;
            }
        }
        if ($existing) {
            $payload = array_merge($existing->only(['title', 'document_number', 'document_year', 'document_date', 'received_date', 'unit_id', 'category_id', 'description', 'access_level', 'retention_note', 'tags']), $payload);
        }
        if (! empty($payload['document_date'])) {
            $year = (int) substr($payload['document_date'], 0, 4);
            if (! empty($payload['document_year']) && (int) $payload['document_year'] !== $year) {
                throw ValidationException::withMessages(['document_year' => 'Tahun dokumen harus sesuai tanggal dokumen.']);
            }
            $payload['document_year'] = $year;
        }
        if (! empty($payload['document_number']) && empty($payload['document_year'])) {
            throw ValidationException::withMessages(['document_year' => 'Tanggal atau tahun dokumen wajib ketika nomor dokumen diisi.']);
        }
        $payload['tags'] = array_values(array_unique(array_filter(array_map(fn ($tag) => trim((string) $tag), $payload['tags'] ?? []))));

        return $payload;
    }

    private function assertReferences(array $payload): void
    {
        if (! InstitutionalUnit::whereKey($payload['unit_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['unit_id' => 'Unit tidak aktif atau tidak ditemukan.']);
        }
        $this->assertCategory($payload['category_id'] ?? null);
    }

    private function assertCategory(?int $id): void
    {
        if ($id !== null && ! Category::whereKey($id)->where('category_type', 'institutional')->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Folder arsip lembaga tidak valid.']);
        }
    }

    private function assertDocumentNumberUnique(array $payload, ?int $exceptId = null): void
    {
        $normalized = $this->normalizedNumber($payload['document_number'] ?? null);
        if ($normalized === null) {
            return;
        }
        $query = InstitutionalArchive::withTrashed()->where('unit_id', $payload['unit_id'])->where('document_year', $payload['document_year']);
        if (DB::connection(config('myconfig.database.first_connection'))->getDriverName() === 'sqlite') {
            $query->whereRaw("lower(replace(replace(replace(replace(document_number, ' ', ''), char(9), ''), char(10), ''), char(13), '')) = ?", [$normalized]);
        } else {
            $query->whereRaw("regexp_replace(lower(document_number), '[[:space:]]+', '', 'g') = ?", [$normalized]);
        }
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['document_number' => 'Nomor dokumen sudah digunakan pada unit dan tahun tersebut.']);
        }
    }

    private function normalizedNumber(?string $number): ?string
    {
        $number = preg_replace('/\\s+/u', '', mb_strtolower(trim((string) $number)));

        return $number === '' ? null : $number;
    }

    private function transaction(callable $callback): mixed
    {
        try {
            return DB::connection(config('myconfig.database.first_connection'))->transaction($callback, 3);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'institutional_archives_document_number_unique')) {
                throw ValidationException::withMessages(['document_number' => 'Nomor dokumen sudah digunakan pada unit dan tahun tersebut.']);
            }
            throw $e;
        }
    }

    private function audit(string $action, InstitutionalArchive $archive, object $actor, array $metadata): void
    {
        AuditLog::create(['actor_user_id' => $actor->id, 'actor_role' => 'admin', 'action' => $action, 'entity_type' => 'institutional_archive', 'entity_id' => (string) $archive->institutional_archive_id, 'description' => 'Aktivitas arsip lembaga.', 'metadata' => $metadata]);
    }
}
