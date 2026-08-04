<?php

namespace Tests\Feature\ArsipDigital;

use App\Services\ArsipDigital\ExportJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class ArsipDigitalExportJobTest extends ArsipDigitalFeatureTestCase
{
    public function test_export_job_create_list_show_and_download_guard(): void
    {
        Queue::fake();
        [$requestId] = $this->createPublishedRequestForMahasiswa(false);

        $jobId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/export-jobs', [
                'export_type' => 'request',
                'filters' => ['request_id' => $requestId, 'statuses' => ['approved']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.export_job.status', 'queued')
            ->json('data.export_job.export_job_id');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/export-jobs')
            ->assertOk()
            ->assertJsonPath('data.export_jobs.0.export_job_id', $jobId);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/export-jobs/'.$jobId)
            ->assertOk()
            ->assertJsonPath('data.export_job.export_job_id', $jobId);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/export-jobs/'.$jobId.'/download')
            ->assertStatus(422);

        Storage::disk('s3')->put('exports/test.zip', 'zip-content');
        DB::table('arsip_digital.export_jobs')->where('export_job_id', $jobId)->update([
            'status' => 'completed',
            'storage_disk' => 's3',
            'storage_path' => 'exports/test.zip',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAsAdmin()
            ->get('/api/arsip-digital/admin/export-jobs/'.$jobId.'/download')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_export_failure_cleanup_removes_stored_object_reference(): void
    {
        Storage::disk('s3')->put('exports/failed.zip', 'partial-zip');
        $jobId = DB::table('arsip_digital.export_jobs')->insertGetId([
            'requested_by_user_id' => 1,
            'export_type' => 'request',
            'filters' => json_encode(['request_id' => 99]),
            'status' => 'processing',
            'storage_disk' => 's3',
            'storage_path' => 'exports/failed.zip',
            'file_size_bytes' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ExportJobService::class)->fail($jobId, new \RuntimeException('zip gagal'));

        Storage::disk('s3')->assertMissing('exports/failed.zip');
        $this->assertDatabaseHas('arsip_digital.export_jobs', [
            'export_job_id' => $jobId,
            'status' => 'failed',
            'storage_disk' => null,
            'storage_path' => null,
            'file_size_bytes' => null,
            'error_message' => 'zip gagal',
        ], 'sqlite');
    }

    public function test_archive_browser_export_job_can_be_created_from_file_filters(): void
    {
        Queue::fake();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/export-jobs', [
                'export_type' => 'archive_browser',
                'filters' => [
                    'owner_role' => 'mahasiswa',
                    'owner_identifier' => '22010001',
                    'extension' => 'pdf',
                    'with_deleted' => false,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.export_job.export_type', 'archive_browser')
            ->assertJsonPath('data.export_job.status', 'queued');
    }

    public function test_distribution_export_job_generates_downloadable_zip(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive extension is not available.');
        }

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Sertifikat Seminar',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')
            ->assertOk();
        $recipientId = DB::table('arsip_digital.distribution_recipients')
            ->where('distribution_id', $distributionId)
            ->value('recipient_id');

        $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', [
                'file' => $this->pdfUpload('sertifikat.pdf', '%PDF export distribution'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated();

        $exportJob = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/export-jobs', [
                'export_type' => 'distribution',
                'filters' => ['distribution_id' => $distributionId],
            ])
            ->assertCreated()
            ->assertJsonPath('data.export_job.export_type', 'distribution')
            ->assertJsonPath('data.export_job.status', 'completed')
            ->json('data.export_job');

        Storage::disk('s3')->assertExists($exportJob['storage_path']);

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('s3')->path($exportJob['storage_path']));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertNotEmpty(array_filter($names, fn (string $name): bool => str_ends_with($name, '/22010001 - Mahasiswa Test/sertifikat.pdf')));

        $this->actingAsAdmin()
            ->get('/api/arsip-digital/admin/export-jobs/'.$exportJob['export_job_id'].'/download')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
