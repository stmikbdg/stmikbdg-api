<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArsipDigitalDistributionTest extends ArsipDigitalFeatureTestCase
{
    public function test_distribution_create_preview_publish_upload_recipient_and_user_download(): void
    {
        $payload = [
            'title' => 'Sertifikat Seminar',
            'target_role' => 'mahasiswa',
            'scope_type' => 'specific',
            'target_identifiers' => ['22010001'],
        ];

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/preview-targets', $payload)
            ->assertOk()
            ->assertJsonPath('data.preview.total_valid', 1);

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.distribution.status', 'draft')
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.distribution.status', 'published')
            ->assertJsonPath('data.distribution.recipients_count', 1)
            ->assertJsonMissingPath('data.distribution.recipients');
        $recipientId = DB::table('arsip_digital.distribution_recipients')
            ->where('distribution_id', $distributionId)
            ->value('recipient_id');

        $fileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', [
                'file' => $this->pdfUpload('sertifikat.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.recipient.delivery_status', 'available')
            ->json('data.recipient.file_id');

        $category = DB::table('arsip_digital.categories')->where('is_system', true)->first();
        $this->assertSame('Sertifikat Seminar', $category->name);
        $this->assertSame(2, $category->owner_user_id);
        $this->assertSame($category->category_id, DB::table('arsip_digital.files')->where('file_id', $fileId)->value('category_id'));

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/distributions')
            ->assertOk()
            ->assertJsonPath('data.distributions.0.recipients.0.file_id', $fileId)
            ->assertJsonPath('data.distributions.0.files.0.file_id', $fileId);

        $this->actingAsMahasiswa()
            ->deleteJson('/api/arsip-digital/files/'.$fileId)
            ->assertForbidden()
            ->assertJsonPath('message', 'File workflow tidak dapat dihapus dari Arsip Pengguna.');

        $this->actingAsAdmin()
            ->deleteJson('/api/arsip-digital/files/'.$fileId)
            ->assertForbidden()
            ->assertJsonPath('message', 'File workflow tidak dapat dihapus dari Arsip Pengguna.');

        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/files/'.$fileId.'/download')
            ->assertForbidden()
            ->assertJsonPath('message', 'File distribution harus didownload melalui endpoint distribution.');

        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'recipient_id' => $recipientId,
            'delivery_status' => 'available',
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'distribution_file_available',
            'entity_type' => 'distribution_recipient',
            'entity_id' => $recipientId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'recipient_user_id' => 3,
            'type' => 'distribution_file_available',
        ], 'sqlite');

        $this->actingAsMahasiswa()
            ->get('/api/arsip-digital/distribution-files/'.$fileId.'/download')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'recipient_id' => $recipientId,
            'delivery_status' => 'downloaded',
            'download_count' => 1,
        ], 'sqlite');
    }

    public function test_published_distribution_can_replace_before_download_but_not_after_download(): void
    {
        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Lifecycle replace',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')->assertOk();
        $recipientId = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $distributionId)->value('recipient_id');

        $firstFileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', ['file' => $this->pdfUpload('first.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.recipient.file_id');

        $secondFileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', ['file' => $this->pdfUpload('second.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.recipient.file_id');

        $this->assertNotSame($firstFileId, $secondFileId);
        $this->actingAsMahasiswa()->get('/api/arsip-digital/distribution-files/'.$secondFileId.'/download')->assertOk();
        $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', ['file' => $this->pdfUpload('third.pdf')], ['X-Active-Role' => 'admin'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File distribution tidak dapat diganti setelah ada download.');
    }

    public function test_published_distribution_allows_initial_upload_for_other_recipient_after_download(): void
    {
        DB::table('users')->insert([
            'id' => 4,
            'kd_user' => 'MHS-22010002',
            'name' => 'Mahasiswa Dua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vmahasiswa')->insert([
            'nim' => '22010002',
            'nm_mhs' => 'Mahasiswa Dua',
            'angkatan' => '2022',
            'prodi' => 'TI',
            'sts_mhs' => 'aktif',
        ]);

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Initial upload after download',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001', '22010002'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')->assertOk();
        $recipients = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $distributionId)->pluck('recipient_id', 'identifier');
        $fileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipients['22010001'].'/file', ['file' => $this->pdfUpload('first.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.recipient.file_id');

        $this->actingAsMahasiswa()->get('/api/arsip-digital/distribution-files/'.$fileId.'/download')->assertOk();
        $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipients['22010002'].'/file', ['file' => $this->pdfUpload('second.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.recipient.delivery_status', 'available');
    }

    public function test_withdraw_blocks_download_and_correction_copies_recipients(): void
    {
        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Lifecycle withdraw',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')->assertOk();
        $recipientId = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $distributionId)->value('recipient_id');
        $fileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', ['file' => $this->pdfUpload('withdraw.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.recipient.file_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/withdraw', ['reason' => 'File perlu diperbaiki'])
            ->assertOk()
            ->assertJsonPath('data.distribution.status', 'closed');
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'recipient_id' => $recipientId,
            'delivery_status' => 'revoked',
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.files', [
            'file_id' => $fileId,
            'status' => 'revoked',
            'is_current' => false,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'distribution_withdrawn',
            'entity_id' => $distributionId,
        ], 'sqlite');
        $this->actingAsMahasiswa()->get('/api/arsip-digital/distribution-files/'.$fileId.'/download')->assertStatus(410);
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertJsonMissing(['distribution_id' => $distributionId]);

        $correctionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/corrections')
            ->assertCreated()
            ->assertJsonPath('data.distribution.status', 'draft')
            ->json('data.distribution.distribution_id');
        $originalRecipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $distributionId)->first();
        DB::table('arsip_digital.distribution_recipients')->insert([
            'distribution_id' => $correctionId,
            'target_user_id' => $originalRecipient->target_user_id,
            'target_role' => $originalRecipient->target_role,
            'identifier' => $originalRecipient->identifier,
            'name_snapshot' => $originalRecipient->name_snapshot,
            'delivery_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $correctionId)->count());
        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$correctionId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.distribution.recipients_count', 1);
        $this->assertSame(1, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $correctionId)->whereNull('deleted_at')->count());
        $this->assertDatabaseHas('arsip_digital.distributions', [
            'distribution_id' => $correctionId,
            'original_distribution_id' => $distributionId,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.audit_logs', [
            'action' => 'distribution.correction_created',
            'entity_id' => $correctionId,
        ], 'sqlite');
    }

    public function test_bulk_confirm_notifies_only_matched_saved_recipients(): void
    {
        DB::table('users')->insert([
            'id' => 4,
            'kd_user' => 'MHS-22010002',
            'name' => 'Mahasiswa Dua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vmahasiswa')->insert([
            'nim' => '22010002',
            'nm_mhs' => 'Mahasiswa Dua',
            'angkatan' => '2022',
            'prodi' => 'TI',
            'sts_mhs' => 'aktif',
        ]);

        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Distribusi bulk',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001', '22010002'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/publish')
            ->assertOk();

        $recipientId = DB::table('arsip_digital.distribution_recipients')
            ->where('distribution_id', $distributionId)
            ->where('identifier', '22010001')
            ->value('recipient_id');
        $otherRecipientId = DB::table('arsip_digital.distribution_recipients')
            ->where('distribution_id', $distributionId)
            ->where('identifier', '22010002')
            ->value('recipient_id');

        $downloadedFileId = $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/distribution-recipients/'.$recipientId.'/file', ['file' => $this->pdfUpload('downloaded.pdf')], ['X-Active-Role' => 'admin'])
            ->assertCreated()
            ->json('data.recipient.file_id');
        $this->actingAsMahasiswa()->get('/api/arsip-digital/distribution-files/'.$downloadedFileId.'/download')->assertOk();

        Storage::disk('s3')->put('tmp/bulk/22010002.pdf', '%PDF-1.4 bulk');
        $jobId = DB::table('arsip_digital.distribution_bulk_upload_jobs')->insertGetId([
            'distribution_id' => $distributionId,
            'uploaded_by_user_id' => 1,
            'status' => 'preview_ready',
            'original_filename' => 'bulk.zip',
            'summary' => json_encode(['total_entries' => 4, 'matched' => 1, 'unmatched' => 1, 'duplicate' => 1, 'invalid' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([
            [
                'bulk_upload_job_id' => $jobId,
                'recipient_id' => $otherRecipientId,
                'identifier' => '22010002',
                'entry_path' => '22010002.pdf',
                'original_filename' => '22010002.pdf',
                'display_filename' => '22010002.pdf',
                'temporary_disk' => 's3',
                'temporary_path' => 'tmp/bulk/22010002.pdf',
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'file_size_bytes' => 13,
                'checksum_sha256' => hash('sha256', '%PDF-1.4 bulk'),
                'match_status' => 'matched',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bulk_upload_job_id' => $jobId,
                'entry_path' => 'unmatched.pdf',
                'original_filename' => 'unmatched.pdf',
                'display_filename' => 'unmatched.pdf',
                'match_status' => 'unmatched',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bulk_upload_job_id' => $jobId,
                'recipient_id' => $otherRecipientId,
                'identifier' => '22010002',
                'entry_path' => 'duplicate.pdf',
                'original_filename' => 'duplicate.pdf',
                'display_filename' => 'duplicate.pdf',
                'match_status' => 'duplicate',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'bulk_upload_job_id' => $jobId,
                'entry_path' => 'invalid.exe',
                'original_filename' => 'invalid.exe',
                'display_filename' => 'invalid.exe',
                'match_status' => 'invalid',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ] as $entry) {
            DB::table('arsip_digital.distribution_bulk_upload_entries')->insert($entry);
        }

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distribution-bulk-upload-jobs/'.$jobId.'/confirm')
            ->assertOk();

        $bulkFileId = DB::table('arsip_digital.distribution_recipients')->where('recipient_id', $otherRecipientId)->value('file_id');
        $bulkCategory = DB::table('arsip_digital.categories')->where('owner_user_id', 4)->where('is_system', true)->where('name', 'Distribusi bulk')->first();
        $this->assertNotNull($bulkCategory);
        $this->assertSame($bulkCategory->category_id, DB::table('arsip_digital.files')->where('file_id', $bulkFileId)->value('category_id'));

        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 4,
            'recipient_role' => 'mahasiswa',
            'type' => 'distribution_file_available',
            'entity_type' => 'distribution',
            'entity_id' => $distributionId,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'recipient_id' => $recipientId,
            'delivery_status' => 'downloaded',
            'file_id' => $downloadedFileId,
            'download_count' => 1,
        ], 'sqlite');
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', [
            'recipient_id' => $otherRecipientId,
            'delivery_status' => 'available',
            'file_id' => $bulkFileId,
        ], 'sqlite');
    }

    public function test_bulk_upload_for_deleted_distribution_returns_safe_not_found_message(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/distributions/999999/bulk-upload-jobs')
            ->assertNotFound()
            ->assertJsonPath('message', 'Data tidak ditemukan atau sudah dihapus.');
    }

    public function test_admin_distribution_recipients_are_paginated(): void
    {
        $distributionId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/distributions', [
                'title' => 'Distribusi paginated',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
            ])
            ->assertCreated()
            ->json('data.distribution.distribution_id');

        foreach (['22010001', '22010002', '22010003', '22010004'] as $identifier) {
            DB::table('arsip_digital.distribution_recipients')->insert([
                'distribution_id' => $distributionId,
                'target_user_id' => 2,
                'target_role' => 'mahasiswa',
                'identifier' => $identifier,
                'name_snapshot' => 'Mahasiswa '.$identifier,
                'delivery_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/distributions/'.$distributionId.'/recipients?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.recipients')
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.per_page', 2)
            ->assertJsonPath('data.meta.total', 4);
    }
}
