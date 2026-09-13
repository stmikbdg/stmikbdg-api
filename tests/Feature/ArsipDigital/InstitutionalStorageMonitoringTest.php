<?php

namespace Tests\Feature\ArsipDigital;

use App\Jobs\ArsipDigital\ReconcileInstitutionalStorageJob;
use App\Models\ArsipDigital\InstitutionalStorageReconciliationReport;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use App\Services\ArsipDigital\InstitutionalStorageMonitoringService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;

class InstitutionalStorageMonitoringTest extends ArsipDigitalFeatureTestCase
{
    private int $unit;

    private int $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->unit = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'is_active' => true, 'created_by_user_id' => 1]);
        $this->archive = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => $this->unit, 'title' => 'Arsip A', 'status' => 'active', 'created_by_user_id' => 1]);
    }

    public function test_endpoints_require_admin_and_summary_is_db_only_safe_and_scoped(): void
    {
        $this->file('institutional', 100);
        $this->file('personal', 900);
        $storage = Mockery::mock(ArsipDigitalStorageService::class);
        $storage->shouldNotReceive('exists');
        $this->app->instance(ArsipDigitalStorageService::class, $storage);

        foreach (['/summary', '/files'] as $path) {
            $this->actingAsMahasiswa()->getJson('/api/arsip-digital/admin/institutional-storage'.$path)->assertForbidden();
        }
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/summary')->assertOk()
            ->assertJsonPath('data.total_files', 1)->assertJsonPath('data.total_bytes', 100)
            ->assertJsonMissing(['storage_path'])->assertJsonMissing(['storage_disk'])->assertJsonStructure(['data' => ['largest_files', 'availability', 'distributions', 'reconciliation_stale']]);
    }

    public function test_summary_uses_derived_deletion_soft_limits_and_phase_six_distribution_statuses(): void
    {
        $this->file('institutional', 100);
        $this->file('institutional', 40, ['is_current' => false, 'status' => 'replaced']);
        $deletedArchive = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => $this->unit, 'title' => 'Deleted', 'status' => 'deleted', 'deleted_at' => now(), 'deleted_by_user_id' => 1, 'delete_reason' => 'test', 'created_by_user_id' => 1]);
        $this->file('institutional', 60, ['institutional_archive_id' => $deletedArchive]);
        $this->file('institutional', 20, ['deleted_at' => now()]);
        foreach ([['published', now()->addDay()], ['published', now()->subDay()], ['closed', null]] as [$status, $expires]) {
            DB::table('arsip_digital.distributions')->insert(['institutional_archive_id' => $this->archive, 'status' => $status, 'expires_at' => $expires]);
        }

        $summary = $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/summary')->assertOk();
        $summary->assertJsonPath('data.total_files', 4)->assertJsonPath('data.total_bytes', 220)->assertJsonPath('data.current_bytes', 100)
            ->assertJsonPath('data.active_current_files', 1)->assertJsonPath('data.active_current_bytes', 100)
            ->assertJsonPath('data.active_historical_files', 1)->assertJsonPath('data.active_historical_bytes', 40)
            ->assertJsonPath('data.deleted_files', 2)->assertJsonPath('data.deleted_bytes', 80)
            ->assertJsonPath('data.soft_deleted_files', 2)->assertJsonPath('data.soft_deleted_bytes', 80)
            ->assertJsonPath('data.distributions.active', 1)->assertJsonPath('data.distributions.expired', 1)->assertJsonPath('data.distributions.withdrawn', 1)
            ->assertJsonPath('data.soft_limit_bytes', null)->assertJsonPath('data.soft_limit_percent', null);

        foreach ([0, 220, 440] as $limit) {
            DB::table('arsip_digital.settings')->where('key', 'archive_defaults')->update(['value' => json_encode(['storage_disk' => 's3', 'institutional_storage_soft_limit_bytes' => $limit])]);
            $expected = $limit > 0 ? 22000 / $limit : null;
            $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/summary')->assertOk()->assertJsonPath('data.soft_limit_percent', $expected);
        }
    }

    public function test_files_are_bounded_filterable_stably_sorted_and_secret_free(): void
    {
        $category = DB::table('arsip_digital.categories')->insertGetId(['name' => 'Surat', 'category_type' => 'institutional']);
        DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $this->archive)->update(['category_id' => $category]);
        $this->file('institutional', 20, ['is_current' => false, 'status' => 'replaced', 'storage_availability' => 'missing']);
        $this->file('institutional', 20);
        $response = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-storage/files?unit_id=$this->unit&category_id=$category&version=historical&storage_availability=missing&sort=file_size_bytes&direction=asc&per_page=1")
            ->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonMissing(['storage_path'])->assertJsonMissing(['storage_disk']);
        $this->assertSame('Arsip A', $response->json('data.files.0.archive_title'));
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/files?per_page=101')->assertUnprocessable();

        $rootArchive = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => $this->unit, 'title' => 'Root Deleted', 'status' => 'deleted', 'deleted_at' => now(), 'deleted_by_user_id' => 1, 'delete_reason' => 'test', 'created_by_user_id' => 1]);
        $this->file('institutional', 30, ['institutional_archive_id' => $rootArchive]);
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/files?category_id=root&deleted=only')->assertOk()
            ->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.deleted', 1)->assertJsonMissing(['deleted_at']);
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/files?deleted=exclude')->assertOk()->assertJsonCount(2, 'data.files');
    }

    public function test_trigger_is_async_duplicate_returns_authoritative_safe_job_and_detail_is_dto(): void
    {
        Queue::fake();
        $first = $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-storage/reconciliation-jobs')->assertStatus(202)->assertJsonPath('data.job.status', 'queued');
        Queue::assertPushed(ReconcileInstitutionalStorageJob::class);
        $id = $first->json('data.job.job_id');
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-storage/reconciliation-jobs')->assertStatus(409)->assertJsonPath('data.job.job_id', $id)->assertJsonMissing(['requested_by_user_id', 'error_message']);
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-storage/reconciliation-jobs/$id")->assertOk()->assertJsonMissing(['requested_by_user_id', 'error_message']);
    }

    public function test_dispatch_failure_marks_report_failed_and_frees_guard_for_next_trigger(): void
    {
        $dispatches = 0;
        Bus::shouldReceive('dispatch')->twice()->andReturnUsing(function (ReconcileInstitutionalStorageJob $job) use (&$dispatches) {
            $dispatches++;
            if ($dispatches === 1) {
                throw new \RuntimeException('secret queue endpoint');
            }
            $this->assertTrue($job->afterCommit);

            return $job;
        });
        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-storage/reconciliation-jobs')->assertStatus(500)->assertDontSee('secret queue endpoint');
        $failed = InstitutionalStorageReconciliationReport::firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->finished_at);
        $this->assertStringNotContainsString('secret queue endpoint', $failed->error_message);

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/institutional-storage/reconciliation-jobs')->assertStatus(202)->assertJsonPath('data.job.status', 'queued');
        $this->assertSame(1, InstitutionalStorageReconciliationReport::where('status', 'queued')->count());
        $this->assertSame(2, $dispatches);
    }

    public function test_failed_report_exposes_only_sanitized_summary(): void
    {
        $report = InstitutionalStorageReconciliationReport::create(['requested_by_user_id' => 1, 'mode' => 'existence', 'status' => 'failed', 'error_message' => 'RuntimeException during storage provider check', 'finished_at' => now()]);
        $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/institutional-storage/reconciliation-jobs/'.$report->getKey())->assertOk()
            ->assertJsonPath('data.job.error_summary', 'Storage provider check failed.')
            ->assertJsonMissing(['error_message', 'requested_by_user_id', 'RuntimeException']);
    }

    public function test_fatal_chunk_database_error_marks_failed_sanitized_and_rethrows(): void
    {
        $this->file('institutional', 10, ['storage_path' => 'available']);
        $report = InstitutionalStorageReconciliationReport::create(['requested_by_user_id' => 1, 'mode' => 'existence', 'status' => 'queued']);
        $storage = Mockery::mock(ArsipDigitalStorageService::class);
        $storage->shouldReceive('exists')->once()->andReturnTrue();
        $this->app->instance(ArsipDigitalStorageService::class, $storage);
        DB::statement("CREATE TRIGGER arsip_digital.fail_reconciliation_chunk BEFORE UPDATE OF checked_files ON institutional_storage_reconciliation_reports BEGIN SELECT RAISE(FAIL, 'secret/storage/path fatal'); END");

        try {
            $this->app->make(InstitutionalStorageMonitoringService::class)->process($report->getKey());
            $this->fail('Fatal reconciliation error must be rethrown.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('secret/storage/path fatal', $exception->getMessage());
        }

        $report->refresh();
        $this->assertSame('failed', $report->status);
        $this->assertNotNull($report->finished_at);
        $this->assertStringNotContainsString('secret/storage/path fatal', $report->error_message);
        $this->app->make(InstitutionalStorageMonitoringService::class)->fail($report->getKey(), new \RuntimeException('second failure'));
        $this->assertSame($report->error_message, $report->fresh()->error_message);
    }

    public function test_worker_reconciles_active_and_soft_deleted_institutional_files_once_without_deleting_objects(): void
    {
        $active = $this->file('institutional', 10, ['storage_path' => 'active']);
        $trashedAvailable = $this->file('institutional', 10, ['storage_path' => 'trashed-available', 'storage_availability' => 'unknown', 'deleted_at' => now()]);
        $trashedMissing = $this->file('institutional', 10, ['storage_path' => 'trashed-missing', 'storage_availability' => 'available', 'deleted_at' => now()]);
        $trashedFailed = $this->file('institutional', 10, ['storage_path' => 'trashed-failed', 'storage_availability' => 'available', 'deleted_at' => now()]);
        $trashedArchive = DB::table('arsip_digital.institutional_archives')->insertGetId(['archive_uuid' => fake()->uuid(), 'unit_id' => $this->unit, 'title' => 'Arsip Terhapus', 'status' => 'deleted', 'created_by_user_id' => 1, 'deleted_at' => now()]);
        $trashedArchiveFile = $this->file('institutional', 10, ['institutional_archive_id' => $trashedArchive, 'storage_path' => 'trashed-archive']);
        $invalidRelation = $this->file('institutional', 10, ['institutional_archive_id' => null, 'storage_path' => 'invalid-relation']);
        foreach (['personal', 'request', 'distribution', 'admin_upload', 'official'] as $source) {
            $this->file($source, 10, ['storage_path' => "excluded-$source", 'deleted_at' => now()]);
        }
        $report = InstitutionalStorageReconciliationReport::create(['requested_by_user_id' => 1, 'mode' => 'existence', 'status' => 'queued']);
        $storage = Mockery::mock(ArsipDigitalStorageService::class);
        $storage->shouldReceive('exists')->with('s3', 'active')->once()->andReturnTrue();
        $storage->shouldReceive('exists')->with('s3', 'trashed-available')->once()->andReturnTrue();
        $storage->shouldReceive('exists')->with('s3', 'trashed-missing')->once()->andReturnFalse();
        $storage->shouldReceive('exists')->with('s3', 'trashed-failed')->once()->andThrow(new \RuntimeException('provider failure'));
        $storage->shouldReceive('exists')->with('s3', 'trashed-archive')->once()->andReturnTrue();
        $storage->shouldNotReceive('exists')->with('s3', 'invalid-relation');
        $storage->shouldNotReceive('deletePrivate');
        $this->app->instance(ArsipDigitalStorageService::class, $storage);

        $service = $this->app->make(InstitutionalStorageMonitoringService::class);
        $service->process($report->getKey());
        $service->process($report->getKey());
        $report->refresh();

        $this->assertSame([5, 5, 3, 1, 1], [$report->total_files, $report->checked_files, $report->available_files, $report->missing_files, $report->failed_files]);
        $this->assertSame('available', DB::table('arsip_digital.files')->where('file_id', $active)->value('storage_availability'));
        $this->assertSame('available', DB::table('arsip_digital.files')->where('file_id', $trashedAvailable)->value('storage_availability'));
        $this->assertSame('missing', DB::table('arsip_digital.files')->where('file_id', $trashedMissing)->value('storage_availability'));
        $this->assertSame('available', DB::table('arsip_digital.files')->where('file_id', $trashedFailed)->value('storage_availability'));
        $this->assertSame('available', DB::table('arsip_digital.files')->where('file_id', $trashedArchiveFile)->value('storage_availability'));
        $this->assertSame('unknown', DB::table('arsip_digital.files')->where('file_id', $invalidRelation)->value('storage_availability'));
        $this->assertSame(11, DB::table('arsip_digital.files')->count());
    }

    public function test_worker_distinguishes_missing_and_provider_failure_preserves_state_and_is_terminal_idempotent(): void
    {
        $available = $this->file('institutional', 10, ['storage_path' => 'available']);
        $missing = $this->file('institutional', 10, ['storage_path' => 'missing']);
        $failed = $this->file('institutional', 10, ['storage_path' => 'secret/provider', 'storage_availability' => 'available']);
        $report = InstitutionalStorageReconciliationReport::create(['requested_by_user_id' => 1, 'mode' => 'existence', 'status' => 'queued']);
        $storage = Mockery::mock(ArsipDigitalStorageService::class);
        $storage->shouldReceive('exists')->with('s3', 'available')->once()->andReturnTrue();
        $storage->shouldReceive('exists')->with('s3', 'missing')->once()->andReturnFalse();
        $storage->shouldReceive('exists')->with('s3', 'secret/provider')->once()->andThrow(new \RuntimeException('secret/provider token'));
        $this->app->instance(ArsipDigitalStorageService::class, $storage);
        $service = $this->app->make(InstitutionalStorageMonitoringService::class);
        $service->process($report->getKey());
        $service->process($report->getKey());
        $report->refresh();
        $this->assertSame('completed', $report->status);
        $this->assertSame([3, 1, 1, 1], [$report->checked_files, $report->available_files, $report->missing_files, $report->failed_files]);
        $this->assertSame('available', DB::table('arsip_digital.files')->where('file_id', $failed)->value('storage_availability'));
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_storage.reconciled']);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $available]);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $missing]);
    }

    private function file(string $source, int $size, array $extra = []): int
    {
        return DB::table('arsip_digital.files')->insertGetId(array_merge(['institutional_archive_id' => $source === 'institutional' ? $this->archive : null, 'owner_user_id' => 1, 'owner_role' => 'admin', 'owner_identifier' => '1', 'uploaded_by_user_id' => 1, 'uploaded_by_role' => 'admin', 'source_type' => $source, 'original_filename' => 'x.pdf', 'display_filename' => 'x.pdf', 'storage_disk' => 's3', 'storage_path' => fake()->uuid(), 'extension' => 'pdf', 'file_size_bytes' => $size, 'version_group_uuid' => fake()->uuid(), 'version_number' => 1, 'is_current' => true, 'status' => 'active', 'storage_availability' => 'unknown', 'created_at' => now()], $extra));
    }
}
