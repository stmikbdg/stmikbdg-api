<?php

namespace App\Services\ArsipDigital;

use App\Jobs\ArsipDigital\ReconcileInstitutionalStorageJob;
use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\InstitutionalStorageReconciliationReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstitutionalStorageMonitoringService
{
    public function __construct(private readonly ArsipDigitalStorageService $storage, private readonly ArsipDigitalSettingsService $settings, private readonly AuditLogService $audit) {}

    public function summary(): array
    {
        $files = ArchiveFile::withTrashed()->where('source_type', 'institutional');
        $bytes = (int) (clone $files)->sum('file_size_bytes');
        $defaults = $this->settings->getDefaults();
        $limit = $defaults['institutional_storage_soft_limit_bytes'];
        $month = DB::connection(config('myconfig.database.first_connection'))->getDriverName() === 'pgsql' ? "to_char(created_at, 'YYYY-MM')" : "strftime('%Y-%m', created_at)";
        $latest = InstitutionalStorageReconciliationReport::latest('created_at')->first();
        $versionMetrics = DB::connection(config('myconfig.database.first_connection'))->table('arsip_digital.files as f')->join('arsip_digital.institutional_archives as a', 'a.institutional_archive_id', '=', 'f.institutional_archive_id')->where('f.source_type', 'institutional')->selectRaw("sum(case when f.deleted_at is null and a.deleted_at is null and a.status <> 'deleted' and f.is_current = true then 1 else 0 end) as active_current_files, coalesce(sum(case when f.deleted_at is null and a.deleted_at is null and a.status <> 'deleted' and f.is_current = true then f.file_size_bytes else 0 end), 0) as active_current_bytes, sum(case when f.deleted_at is null and a.deleted_at is null and a.status <> 'deleted' and f.is_current = false then 1 else 0 end) as active_historical_files, coalesce(sum(case when f.deleted_at is null and a.deleted_at is null and a.status <> 'deleted' and f.is_current = false then f.file_size_bytes else 0 end), 0) as active_historical_bytes, sum(case when f.deleted_at is not null or a.deleted_at is not null or a.status = 'deleted' then 1 else 0 end) as deleted_files, coalesce(sum(case when f.deleted_at is not null or a.deleted_at is not null or a.status = 'deleted' then f.file_size_bytes else 0 end), 0) as deleted_bytes")->first();

        return [
            'active_archives' => DB::connection(config('myconfig.database.first_connection'))->table('arsip_digital.institutional_archives')->where('status', 'active')->whereNull('deleted_at')->count(),
            'total_files' => (clone $files)->count(), 'total_bytes' => $bytes,
            'active_current_files' => (int) $versionMetrics->active_current_files, 'active_current_bytes' => (int) $versionMetrics->active_current_bytes,
            'active_historical_files' => (int) $versionMetrics->active_historical_files, 'active_historical_bytes' => (int) $versionMetrics->active_historical_bytes,
            'deleted_files' => (int) $versionMetrics->deleted_files, 'deleted_bytes' => (int) $versionMetrics->deleted_bytes,
            'current_bytes' => (int) (clone $files)->join('arsip_digital.institutional_archives as current_archive', 'current_archive.institutional_archive_id', '=', 'arsip_digital.files.institutional_archive_id')->where('arsip_digital.files.is_current', true)->where('arsip_digital.files.status', 'active')->whereNull('arsip_digital.files.deleted_at')->where('current_archive.status', 'active')->whereNull('current_archive.deleted_at')->sum('arsip_digital.files.file_size_bytes'),
            'soft_deleted_files' => (clone $files)->join('arsip_digital.institutional_archives as deleted_archive', 'deleted_archive.institutional_archive_id', '=', 'arsip_digital.files.institutional_archive_id')->where(fn ($query) => $query->whereNotNull('arsip_digital.files.deleted_at')->orWhereNotNull('deleted_archive.deleted_at')->orWhere('deleted_archive.status', 'deleted'))->count(),
            'soft_deleted_bytes' => (int) (clone $files)->join('arsip_digital.institutional_archives as deleted_archive', 'deleted_archive.institutional_archive_id', '=', 'arsip_digital.files.institutional_archive_id')->where(fn ($query) => $query->whereNotNull('arsip_digital.files.deleted_at')->orWhereNotNull('deleted_archive.deleted_at')->orWhere('deleted_archive.status', 'deleted'))->sum('arsip_digital.files.file_size_bytes'),
            'availability' => (clone $files)->selectRaw('storage_availability, count(*) AS count')->groupBy('storage_availability')->pluck('count', 'storage_availability'),
            'by_unit' => (clone $files)->join('arsip_digital.institutional_archives as a', 'a.institutional_archive_id', '=', 'arsip_digital.files.institutional_archive_id')->join('arsip_digital.institutional_units as u', 'u.unit_id', '=', 'a.unit_id')->selectRaw('u.unit_id, u.name, count(*) AS files, coalesce(sum(arsip_digital.files.file_size_bytes), 0) AS bytes')->groupBy('u.unit_id', 'u.name')->orderByDesc('bytes')->limit(50)->get(),
            'by_category' => (clone $files)->join('arsip_digital.institutional_archives as a', 'a.institutional_archive_id', '=', 'arsip_digital.files.institutional_archive_id')->leftJoin('arsip_digital.categories as c', 'c.category_id', '=', 'a.category_id')->selectRaw("a.category_id, coalesce(c.name, 'Tanpa Folder') AS name, count(*) AS files, coalesce(sum(arsip_digital.files.file_size_bytes), 0) AS bytes")->groupBy('a.category_id', 'c.name')->orderByDesc('bytes')->limit(50)->get(),
            'uploads_by_month' => (clone $files)->selectRaw("$month AS month, count(*) AS files, coalesce(sum(file_size_bytes), 0) AS bytes")->groupByRaw($month)->orderByDesc('month')->limit(24)->get(),
            'largest_files' => $this->fileQuery()->orderByDesc('f.file_size_bytes')->orderBy('f.file_id')->limit(10)->get(),
            'distributions' => DB::connection(config('myconfig.database.first_connection'))->table('arsip_digital.distributions')->whereNotNull('institutional_archive_id')->selectRaw("sum(case when status = 'published' and (expires_at is null or expires_at > ?) then 1 else 0 end) as active, sum(case when status = 'published' and expires_at <= ? then 1 else 0 end) as expired, sum(case when status = 'closed' then 1 else 0 end) as withdrawn", [now(), now()])->first(),
            'soft_limit_bytes' => $limit, 'soft_limit_percent' => $limit > 0 ? round($bytes * 100 / $limit, 2) : null,
            'storage_disk_label' => Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '', basename((string) $defaults['storage_disk'])), 50, ''),
            'latest_reconciliation' => $latest ? $this->reportDto($latest) : null,
            'reconciliation_stale' => ! $latest || ! $latest->finished_at || $latest->finished_at->lt(now()->subDay()),
        ];
    }

    public function files(array $filters)
    {
        return $this->fileQuery()->when($filters['storage_availability'] ?? null, fn ($q, $v) => $q->where('f.storage_availability', $v))
            ->when($filters['unit_id'] ?? null, fn ($q, $v) => $q->where('a.unit_id', $v))
            ->when(array_key_exists('category_id', $filters), fn ($q) => $filters['category_id'] === 'root' ? $q->whereNull('a.category_id') : $q->where('a.category_id', $filters['category_id']))
            ->when($filters['version'] ?? null, fn ($q, $v) => $q->where('f.is_current', $v === 'current'))
            ->when(($filters['deleted'] ?? 'exclude') !== 'include', function ($query) use ($filters) {
                $deleted = fn ($q) => $q->whereNotNull('f.deleted_at')->orWhereNotNull('a.deleted_at')->orWhere('a.status', 'deleted');

                return ($filters['deleted'] ?? 'exclude') === 'only'
                    ? $query->where($deleted)
                    : $query->where(fn ($q) => $q->whereNull('f.deleted_at')->whereNull('a.deleted_at')->where('a.status', '!=', 'deleted'));
            })
            ->orderBy('f.'.($filters['sort'] ?? 'file_size_bytes'), $filters['direction'] ?? 'desc')->orderBy('f.file_id')->paginate($filters['per_page'] ?? 20);
    }

    private function fileQuery()
    {
        return DB::connection(config('myconfig.database.first_connection'))->table('arsip_digital.files as f')->join('arsip_digital.institutional_archives as a', 'a.institutional_archive_id', '=', 'f.institutional_archive_id')->join('arsip_digital.institutional_units as u', 'u.unit_id', '=', 'a.unit_id')->leftJoin('arsip_digital.categories as c', 'c.category_id', '=', 'a.category_id')->where('f.source_type', 'institutional')->select(['f.file_id', 'f.institutional_archive_id', 'a.title as archive_title', 'u.unit_id', 'u.name as unit_name', 'a.category_id', 'c.name as category_name', 'f.display_filename', 'f.file_size_bytes', 'f.storage_availability', 'f.is_current', 'f.status', 'f.created_at'])->selectRaw("case when f.deleted_at is not null or a.deleted_at is not null or a.status = 'deleted' then true else false end as deleted");
    }

    public function trigger(int $userId): array
    {
        try {
            $report = DB::connection(config('myconfig.database.first_connection'))->transaction(fn () => InstitutionalStorageReconciliationReport::create(['mode' => 'existence', 'status' => 'queued', 'requested_by_user_id' => $userId]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return ['created' => false, 'report' => $this->reportDto($this->activeReport())];
            }
            throw $e;
        }
        try {
            Bus::dispatch((new ReconcileInstitutionalStorageJob($report->getKey()))->afterCommit());
        } catch (\Throwable $exception) {
            $this->fail($report->getKey(), $exception);
            throw new \RuntimeException('Gagal menjadwalkan rekonsiliasi storage.', 0, $exception);
        }

        return ['created' => true, 'report' => $this->reportDto($report)];
    }

    public function job(int $id): array
    {
        return $this->reportDto(InstitutionalStorageReconciliationReport::findOrFail($id));
    }

    private function activeReport()
    {
        return InstitutionalStorageReconciliationReport::whereIn('status', ['queued', 'running'])->latest('created_at')->firstOrFail();
    }

    public function process(int $id): void
    {
        $files = ArchiveFile::withTrashed()->where('source_type', 'institutional')->whereHas('institutionalArchive', fn ($query) => $query->withTrashed());
        if (! InstitutionalStorageReconciliationReport::whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'total_files' => (clone $files)->count(), 'updated_at' => now()])) {
            return;
        }
        try {
            $files->select(['file_id', 'storage_disk', 'storage_path'])->chunkById(100, function ($files) use ($id): void {
                foreach ($files as $file) {
                    try {
                        $available = $this->storage->exists($file->storage_disk, $file->storage_path);
                        ArchiveFile::withTrashed()->whereKey($file->file_id)->update(['storage_availability' => $available ? 'available' : 'missing']);
                        InstitutionalStorageReconciliationReport::whereKey($id)->increment($available ? 'available_files' : 'missing_files');
                    } catch (\Throwable) {
                        InstitutionalStorageReconciliationReport::whereKey($id)->increment('failed_files');
                    } finally {
                        InstitutionalStorageReconciliationReport::whereKey($id)->increment('checked_files');
                    }
                }
            }, 'file_id');
            if (InstitutionalStorageReconciliationReport::whereKey($id)->where('status', 'running')->update(['status' => 'completed', 'finished_at' => now(), 'updated_at' => now()])) {
                $this->audit->record('institutional_storage.reconciled', 'institutional_storage_reconciliation', $id, 'Rekonsiliasi storage lembaga selesai.');
            }
        } catch (\Throwable $e) {
            $this->fail($id, $e);
            throw $e;
        }
    }

    public function fail(int $id, \Throwable $exception): void
    {
        InstitutionalStorageReconciliationReport::whereKey($id)->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error_message' => $this->safeError($exception), 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function reportDto(InstitutionalStorageReconciliationReport $r): array
    {
        return ['job_id' => $r->getKey(), 'mode' => $r->mode, 'status' => $r->status, 'total_files' => $r->total_files, 'checked_files' => $r->checked_files, 'available_files' => $r->available_files, 'missing_files' => $r->missing_files, 'failed_files' => $r->failed_files, 'error_summary' => $r->error_message ? 'Storage provider check failed.' : null, 'started_at' => $r->started_at, 'finished_at' => $r->finished_at, 'created_at' => $r->created_at];
    }

    private function safeError(\Throwable $e): string
    {
        return $e::class.' during storage provider check';
    }
}
