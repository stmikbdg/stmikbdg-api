<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizeExistingWorkflowFilesMigrationTest extends ArsipDigitalFeatureTestCase
{
    public function test_it_moves_eligible_files_and_is_idempotent(): void
    {
        $requestId = DB::table('arsip_digital.requests')->insertGetId([
            'title' => '  Berkas Registrasi  ',
            'target_role' => 'mahasiswa',
            'scope_type' => 'specific',
            'created_by_user_id' => 1,
        ]);
        $assignmentId = DB::table('arsip_digital.request_assignments')->insertGetId([
            'request_id' => $requestId,
            'target_user_id' => 2,
            'target_role' => 'mahasiswa',
            'identifier' => '22010001',
        ]);
        $requestFileId = $this->workflowFile('request');
        $reusedFileId = $this->workflowFile('request');
        $signatureSourceFileId = $this->workflowFile('request');
        $signatureResultFileId = $this->workflowFile('request');

        foreach ([[$requestFileId, 'uploaded'], [$reusedFileId, 'reused'], [$signatureSourceFileId, 'uploaded'], [$signatureResultFileId, 'uploaded']] as [$fileId, $type]) {
            DB::table('arsip_digital.request_files')->insert([
                'request_id' => $requestId,
                'assignment_id' => $assignmentId,
                'file_id' => $fileId,
                'submission_type' => $type,
            ]);
        }

        DB::table('arsip_digital.signature_request_files')->insert([
            'signature_request_id' => 1,
            'source_file_id' => $signatureSourceFileId,
            'result_file_id' => $signatureResultFileId,
            'source_sha256' => str_repeat('a', 64),
            'source_filename' => 'signature.pdf',
            'source_size_bytes' => 1,
        ]);

        $distributionId = DB::table('arsip_digital.distributions')->insertGetId([
            'title' => '  Sertifikat Seminar  ',
            'target_role' => 'mahasiswa',
            'scope_type' => 'specific',
            'created_by_user_id' => 1,
        ]);
        $distributionFileId = $this->workflowFile('distribution');
        DB::table('arsip_digital.distribution_recipients')->insert([
            'distribution_id' => $distributionId,
            'target_user_id' => 2,
            'target_role' => 'mahasiswa',
            'identifier' => '22010001',
            'file_id' => $distributionFileId,
        ]);

        $migration = require database_path('migrations/2026_07_29_000006_organize_existing_workflow_files.php');
        $migration->up();
        $migration->up();

        $requestCategoryId = DB::table('arsip_digital.files')->where('file_id', $requestFileId)->value('category_id');
        $distributionCategoryId = DB::table('arsip_digital.files')->where('file_id', $distributionFileId)->value('category_id');

        $this->assertNotNull($requestCategoryId);
        $this->assertNotNull($distributionCategoryId);
        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $reusedFileId)->value('category_id'));
        $this->assertSame($requestCategoryId, DB::table('arsip_digital.files')->where('file_id', $signatureSourceFileId)->value('category_id'));
        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $signatureResultFileId)->value('category_id'));
        $this->assertDatabaseHas('arsip_digital.categories', [
            'category_id' => $requestCategoryId,
            'name' => 'Berkas Registrasi',
            'owner_user_id' => 2,
            'owner_role' => 'mahasiswa',
            'category_type' => 'personal',
            'visibility' => 'admin_visible',
            'created_by_user_id' => 1,
            'created_by_role' => 'admin',
            'is_system' => 1,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.categories', [
            'category_id' => $distributionCategoryId,
            'name' => 'Sertifikat Seminar',
            'is_system' => 1,
        ], 'sqlite');
        $this->assertSame(2, DB::table('arsip_digital.categories')->where('is_system', true)->count());
    }

    private function workflowFile(string $sourceType): int
    {
        return DB::table('arsip_digital.files')->insertGetId([
            'owner_user_id' => 2,
            'owner_role' => 'mahasiswa',
            'owner_identifier' => '22010001',
            'uploaded_by_user_id' => 1,
            'uploaded_by_role' => 'admin',
            'source_type' => $sourceType,
            'original_filename' => Str::uuid().'.pdf',
            'display_filename' => 'file.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'test/'.Str::uuid().'.pdf',
            'extension' => 'pdf',
            'file_size_bytes' => 1,
            'version_group_uuid' => (string) Str::uuid(),
        ]);
    }
}
