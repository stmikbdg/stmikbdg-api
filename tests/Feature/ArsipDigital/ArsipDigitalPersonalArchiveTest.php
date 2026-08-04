<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArsipDigitalPersonalArchiveTest extends ArsipDigitalFeatureTestCase
{
    public function test_user_can_search_own_files_by_display_or_original_filename_case_insensitively(): void
    {
        $displayMatchId = $this->createActiveArchiveFileForMahasiswa('source-one.pdf');
        DB::table('arsip_digital.files')->where('file_id', $displayMatchId)->update(['display_filename' => 'Laporan Akhir.pdf']);
        $originalMatchId = $this->createActiveArchiveFileForMahasiswa('LAPORAN-source.pdf');
        $foreignMatchId = $this->createActiveArchiveFileForMahasiswa('laporan-rahasia.pdf');
        DB::table('arsip_digital.files')->where('file_id', $foreignMatchId)->update([
            'owner_user_id' => 3,
            'owner_role' => 'dosen',
        ]);
        $this->createActiveArchiveFileForMahasiswa('catatan.pdf');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/files?search=LaPoRaN')
            ->assertOk()
            ->assertJsonCount(2, 'data.files')
            ->assertJsonFragment(['file_id' => $displayMatchId])
            ->assertJsonFragment(['file_id' => $originalMatchId])
            ->assertJsonMissing(['file_id' => $foreignMatchId]);
    }

    public function test_personal_archive_upload_storage_failure_does_not_create_file_row(): void
    {
        $adapter = new class
        {
            public function put(): bool
            {
                return false;
            }
        };

        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($adapter);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('gagal.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal menyimpan file arsip digital ke storage.');

        $this->assertSame(0, DB::table('arsip_digital.files')->count());
    }

    public function test_admin_upload_for_user_fails_if_target_quota_insufficient(): void
    {
        DB::table('arsip_digital.settings')->update([
            'value' => json_encode([
                'default_max_file_size_mb' => 10,
                'default_allowed_extensions' => ['pdf'],
                'storage_disk' => 's3',
                'personal_quota_mb_by_role' => ['mahasiswa' => 1, 'dosen' => 50],
            ]),
        ]);
        $this->createActiveArchiveFileForMahasiswa('quota.pdf');
        DB::table('arsip_digital.files')
            ->where('owner_user_id', 2)
            ->update(['file_size_bytes' => 1024 * 1024]);

        $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/files/upload-for-user', [
                'owner_role' => 'mahasiswa',
                'owner_identifier' => '22010001',
                'file' => $this->pdfUpload('admin-quota.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_admin_upload_for_user_succeeds_and_is_visible_to_user_and_admin(): void
    {
        $categoryId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Admin Uploads',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $fileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/files/upload-for-user', [
                'owner_role' => 'mahasiswa',
                'owner_identifier' => '22010001',
                'category_id' => $categoryId,
                'file' => $this->pdfUpload('admin-ok.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.file.owner_user_id', 2)
            ->assertJsonPath('data.file.uploaded_by_user_id', 1)
            ->assertJsonPath('data.file.source_type', 'admin_upload')
            ->json('data.file.file_id');

        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'actor_user_id' => 1,
            'actor_role' => 'admin',
            'action' => 'request_file.admin_uploaded',
            'entity_type' => 'file',
            'entity_id' => (string) $fileId,
        ]);
        $this->assertSame($fileId, json_decode(DB::table('arsip_digital.audit_logs')->where('action', 'request_file.admin_uploaded')->value('metadata'), true)['file_id']);

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/files')
            ->assertOk()
            ->assertJsonPath('data.files.0.file_id', $fileId);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/files?owner_user_id=2&owner_role=mahasiswa')
            ->assertOk()
            ->assertJsonPath('data.files.0.file_id', $fileId);

        $userReplacementId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'category_id' => $categoryId,
                'file' => $this->pdfUpload('admin-ok.pdf', '%PDF user replacement'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->assertJsonPath('data.file.version_number', 2)
            ->json('data.file.file_id');

        $this->assertDatabaseHas('arsip_digital.files', [
            'file_id' => $fileId,
            'status' => 'replaced',
            'is_current' => false,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.files', [
            'file_id' => $userReplacementId,
            'status' => 'active',
            'is_current' => true,
        ], 'sqlite');

        $this->actingAsAdmin()
            ->deleteJson('/api/arsip-digital/files/'.$fileId, ['reason' => 'admin cleanup'])
            ->assertOk();

        $this->assertNotNull(DB::table('arsip_digital.files')->where('file_id', $fileId)->value('deleted_at'));
        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'actor_user_id' => 1,
            'actor_role' => 'admin',
            'action' => 'file.deleted',
            'entity_type' => 'file',
            'entity_id' => (string) $fileId,
        ]);
        $this->assertSame('admin cleanup', json_decode(DB::table('arsip_digital.audit_logs')->where('action', 'file.deleted')->where('entity_id', (string) $fileId)->value('metadata'), true)['reason']);
    }

    public function test_distribution_file_does_not_reduce_personal_quota(): void
    {
        $summaryBefore = $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/me/archive-summary')
            ->assertOk()
            ->json('data.personal_used_bytes');

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Distribusi quota',
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
                'file' => $this->pdfUpload('distribution-quota.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated();

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/me/archive-summary')
            ->assertOk()
            ->assertJsonPath('data.personal_used_bytes', $summaryBefore);
    }

    public function test_user_can_move_personal_files_and_version_history_between_root_and_category(): void
    {
        $categoryId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Dokumen Akademik',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $firstVersionId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('riwayat.pdf', '%PDF versi 1'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.file.file_id');

        $currentVersionId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('riwayat.pdf', '%PDF versi 2'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.file.file_id');

        $secondFileId = $this->createActiveArchiveFileForMahasiswa('transkrip.pdf');

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$currentVersionId, $secondFileId],
                'category_id' => $categoryId,
            ])
            ->assertOk()
            ->assertJsonPath('data.move.moved_files', 2)
            ->assertJsonPath('data.move.moved_versions', 3);

        foreach ([$firstVersionId, $currentVersionId, $secondFileId] as $fileId) {
            $this->assertDatabaseHas('arsip_digital.files', [
                'file_id' => $fileId,
                'category_id' => $categoryId,
            ], 'sqlite');
        }

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$currentVersionId],
                'category_id' => null,
            ])
            ->assertOk();

        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $firstVersionId)->value('category_id'));
        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $currentVersionId)->value('category_id'));
        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'actor_user_id' => 2,
            'actor_role' => 'mahasiswa',
            'action' => 'file.moved',
            'entity_type' => 'file_batch',
        ]);
    }

    public function test_move_is_atomic_and_rejects_foreign_category_file_and_filename_conflict(): void
    {
        $ownCategoryId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Tujuan',
            ])
            ->assertCreated()
            ->json('data.category.category_id');
        $foreignCategoryId = $this->actingAsDosen()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Milik Dosen',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $firstFileId = $this->createActiveArchiveFileForMahasiswa('pertama.pdf');
        $foreignFileId = $this->createActiveArchiveFileForMahasiswa('asing.pdf');
        DB::table('arsip_digital.files')->where('file_id', $foreignFileId)->update([
            'owner_user_id' => 3,
            'owner_role' => 'dosen',
            'owner_identifier' => 'DSN001',
        ]);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$firstFileId, $foreignFileId],
                'category_id' => $ownCategoryId,
            ])
            ->assertForbidden();
        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $firstFileId)->value('category_id'));

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$firstFileId],
                'category_id' => $foreignCategoryId,
            ])
            ->assertForbidden();

        $conflictingFileId = $this->createActiveArchiveFileForMahasiswa('pertama.pdf');
        DB::table('arsip_digital.files')->where('file_id', $conflictingFileId)->update(['category_id' => $ownCategoryId]);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$firstFileId],
                'category_id' => $ownCategoryId,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Kategori tujuan sudah memiliki file aktif dengan nama yang sama.');
        $this->assertNull(DB::table('arsip_digital.files')->where('file_id', $firstFileId)->value('category_id'));
    }

    public function test_request_and_replaced_files_cannot_be_moved(): void
    {
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);
        $requestFileId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$assignmentId.'/files/upload', [
                'file' => $this->pdfUpload('workflow.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.request_file.file_id');

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$requestFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya file arsip pribadi aktif yang dapat dipindahkan.');

        $replacedFileId = $this->createActiveArchiveFileForMahasiswa('lama.pdf');
        DB::table('arsip_digital.files')->where('file_id', $replacedFileId)->update([
            'status' => 'replaced',
            'is_current' => false,
        ]);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$replacedFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya file arsip pribadi aktif yang dapat dipindahkan.');
    }

    public function test_workflow_linked_personal_distribution_deleted_and_admin_request_files_cannot_be_moved(): void
    {
        $personalFileId = $this->createActiveArchiveFileForMahasiswa('dipakai-request.pdf');
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/request-assignments/'.$assignmentId.'/files/reuse', [
                'file_id' => $personalFileId,
            ])
            ->assertCreated();

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$personalFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File workflow tidak dapat dipindahkan.');

        $distributionFileId = $this->createActiveArchiveFileForMahasiswa('distribusi.pdf');
        DB::table('arsip_digital.files')->where('file_id', $distributionFileId)->update(['source_type' => 'distribution']);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$distributionFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya file arsip pribadi aktif yang dapat dipindahkan.');

        $deletedFileId = $this->createActiveArchiveFileForMahasiswa('deleted.pdf');
        DB::table('arsip_digital.files')->where('file_id', $deletedFileId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$deletedFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sebagian file yang dipilih tidak valid.');

        DB::table('vusers')->insert(['id' => 1, 'kd_user' => 'ADM-ADM001', 'name' => 'Admin Test', 'is_admin' => 1]);
        [, $adminAssignmentId] = $this->createPublishedRequestForMahasiswa(true);
        $adminRequestFileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/files/upload-for-user', [
                'owner_role' => 'mahasiswa',
                'owner_identifier' => '22010001',
                'request_assignment_id' => $adminAssignmentId,
                'file' => $this->pdfUpload('admin-workflow.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.file.source_type', 'request')
            ->json('data.file.file_id');

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/files/move', [
                'file_ids' => [$adminRequestFileId],
                'category_id' => null,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hanya file arsip pribadi aktif yang dapat dipindahkan.');
    }

    public function test_main_file_list_hides_history_and_version_endpoint_returns_it(): void
    {
        $firstVersionId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/files/upload-for-user', [
                'owner_role' => 'mahasiswa',
                'owner_identifier' => '22010001',
                'file' => $this->pdfUpload('history.pdf', '%PDF admin version'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.file.file_id');

        $currentVersionId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('history.pdf', '%PDF personal version'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->assertJsonPath('data.file.version_number', 2)
            ->json('data.file.file_id');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/files')
            ->assertOk()
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.file_id', $currentVersionId)
            ->assertJsonPath('data.files.0.status', 'active');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/files?with_deleted=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.file_id', $currentVersionId);

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/files/'.$currentVersionId.'/versions')
            ->assertOk()
            ->assertJsonCount(2, 'data.versions')
            ->assertJsonPath('data.versions.0.file_id', $currentVersionId)
            ->assertJsonPath('data.versions.0.version_number', 2)
            ->assertJsonPath('data.versions.1.file_id', $firstVersionId)
            ->assertJsonPath('data.versions.1.source_type', 'admin_upload')
            ->assertJsonPath('data.versions.1.status', 'replaced');

        $this->actingAsDosen()
            ->getJson('/api/arsip-digital/files/'.$currentVersionId.'/versions')
            ->assertForbidden();
    }

    public function test_replaced_versions_consume_quota_and_owner_can_delete_unreferenced_history(): void
    {
        $firstVersionId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('quota-history.pdf', '%PDF version one'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.file.file_id');
        $firstSize = (int) DB::table('arsip_digital.files')->where('file_id', $firstVersionId)->value('file_size_bytes');

        $currentVersionId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'file' => $this->pdfUpload('quota-history.pdf', '%PDF version two'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.file.file_id');
        $currentSize = (int) DB::table('arsip_digital.files')->where('file_id', $currentVersionId)->value('file_size_bytes');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/me/archive-summary')
            ->assertOk()
            ->assertJsonPath('data.personal_used_bytes', $firstSize + $currentSize);

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/files/'.$firstVersionId, ['reason' => 'hapus riwayat'])
            ->assertOk();

        $this->assertDatabaseMissing('arsip_digital.files', ['file_id' => $firstVersionId], 'sqlite');
        Storage::disk('s3')->assertMissing('arsip-digital/test/'.$firstVersionId);
        $this->assertDatabaseHas('arsip_digital.files', [
            'file_id' => $currentVersionId,
            'status' => 'active',
            'is_current' => true,
        ], 'sqlite');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/me/archive-summary')
            ->assertOk()
            ->assertJsonPath('data.personal_used_bytes', $currentSize);
    }

    public function test_workflow_references_block_historical_version_deletion(): void
    {
        $requestFileId = $this->createActiveArchiveFileForMahasiswa('request-reference.pdf');
        DB::table('arsip_digital.files')->where('file_id', $requestFileId)->update([
            'status' => 'replaced',
            'is_current' => false,
        ]);
        [$requestId, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);
        DB::table('arsip_digital.request_files')->insert([
            'request_id' => $requestId,
            'assignment_id' => $assignmentId,
            'file_id' => $requestFileId,
            'submission_type' => 'reused',
            'status' => 'replaced',
            'is_late' => false,
            'is_current' => false,
            'created_by_user_id' => 2,
            'created_by_role' => 'mahasiswa',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/files/'.$requestFileId)
            ->assertStatus(409)
            ->assertJsonPath('message', 'File sedang dipakai pada request berkas.');

        $distributionFileId = $this->createActiveArchiveFileForMahasiswa('distribution-reference.pdf');
        DB::table('arsip_digital.files')->where('file_id', $distributionFileId)->update([
            'status' => 'replaced',
            'is_current' => false,
        ]);
        $distributionId = DB::table('arsip_digital.distributions')->insertGetId([
            'title' => 'Referensi',
            'target_role' => 'mahasiswa',
            'scope_type' => 'specific',
            'status' => 'published',
            'created_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('arsip_digital.distribution_recipients')->insert([
            'distribution_id' => $distributionId,
            'target_user_id' => 2,
            'target_role' => 'mahasiswa',
            'identifier' => '22010001',
            'name_snapshot' => 'Mahasiswa Test',
            'file_id' => $distributionFileId,
            'delivery_status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/files/'.$distributionFileId)
            ->assertStatus(409)
            ->assertJsonPath('message', 'File sedang dipakai pada distribusi berkas.');
    }

    public function test_personal_archive_upload_download_and_permanent_delete(): void
    {
        $categoryId = $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/categories', [
                'category_type' => 'personal',
                'name' => 'Dokumen Pribadi',
            ])
            ->assertCreated()
            ->json('data.category.category_id');

        $fileId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/files', [
                'category_id' => $categoryId,
                'file' => $this->pdfUpload('akta.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->assertJsonPath('data.file.status', 'active')
            ->json('data.file.file_id');

        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/files/'.$fileId.'/download')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/files/'.$fileId, ['reason' => 'test permanent delete'])
            ->assertOk();

        $this->assertDatabaseMissing('arsip_digital.files', [
            'file_id' => $fileId,
        ], 'sqlite');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/files/'.$fileId.'/restore')
            ->assertNotFound();
    }
}
