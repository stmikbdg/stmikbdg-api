<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class ArsipDigitalAdminMonitoringTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_request_index_reports_assignment_progress_counts(): void
    {
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests')
            ->assertOk()
            ->assertJsonPath('data.requests.0.assignments_count', 1)
            ->assertJsonPath('data.requests.0.submitted_assignments_count', 0)
            ->assertJsonPath('data.requests.0.approved_assignments_count', 0);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$assignmentId.'/files/upload', [
                'file' => $this->pdfUpload('progress.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests')
            ->assertOk()
            ->assertJsonPath('data.requests.0.assignments_count', 1)
            ->assertJsonPath('data.requests.0.submitted_assignments_count', 1)
            ->assertJsonPath('data.requests.0.approved_assignments_count', 0);

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/approve')
            ->assertOk();

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests')
            ->assertOk()
            ->assertJsonPath('data.requests.0.assignments_count', 1)
            ->assertJsonPath('data.requests.0.submitted_assignments_count', 1)
            ->assertJsonPath('data.requests.0.approved_assignments_count', 1);
    }

    public function test_admin_monitoring_can_approve_reject_download_and_upload_for_user(): void
    {
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $requestFileId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$assignmentId.'/files/upload', [
                'file' => $this->pdfUpload('monitoring.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.request_file.request_file_id');

        $this->actingAsAdmin()
            ->get('/api/arsip-digital/admin/request-files/'.$requestFileId.'/download')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.assignment.status', 'approved');

        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_file_approved',
            'entity_type' => 'request_assignment',
            'entity_id' => $assignmentId,
        ], 'sqlite');

        [, $rejectedAssignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$rejectedAssignmentId.'/files/upload', [
                'file' => $this->pdfUpload('monitoring-reject.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$rejectedAssignmentId.'/reject', [
                'reason' => 'File buram',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignment.status', 'rejected')
            ->assertJsonPath('data.assignment.reject_reason', 'File buram');

        $rejectNotification = DB::table('arsip_digital.notifications')
            ->where('type', 'request_file_rejected')
            ->where('entity_id', $rejectedAssignmentId)
            ->first();
        $this->assertNotNull($rejectNotification);
        $this->assertSame(2, $rejectNotification->recipient_user_id);
        $this->assertSame('mahasiswa', $rejectNotification->recipient_role);
        $this->assertStringContainsString('File buram', $rejectNotification->message);
        $this->assertSame('File buram', json_decode($rejectNotification->data, true)['reason']);

        $this->actingAsAdmin()
            ->post('/api/arsip-digital/admin/files/upload-for-user', [
                'owner_role' => 'mahasiswa',
                'owner_identifier' => '22010001',
                'request_assignment_id' => $assignmentId,
                'file' => $this->pdfUpload('admin-upload.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated();
    }

    public function test_single_approve_and_reject_reject_not_submitted_assignment(): void
    {
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/approve')
            ->assertUnprocessable();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/reject', [
                'reason' => 'Belum submit',
            ])
            ->assertUnprocessable();

        $this->assertSame('not_submitted', DB::table('arsip_digital.request_assignments')->where('assignment_id', $assignmentId)->value('status'));
    }

    public function test_single_approve_and_reject_require_current_file(): void
    {
        [, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);
        DB::table('arsip_digital.request_assignments')->where('assignment_id', $assignmentId)->update(['status' => 'waiting_verification']);

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/approve')
            ->assertUnprocessable();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$assignmentId.'/reject', [
                'reason' => 'File tidak ada',
            ])
            ->assertUnprocessable();

        $this->assertSame('waiting_verification', DB::table('arsip_digital.request_assignments')->where('assignment_id', $assignmentId)->value('status'));
    }

    public function test_bulk_approve_skips_assignments_that_single_approve_would_reject(): void
    {
        [, $notSubmittedId] = $this->createPublishedRequestForMahasiswa(true);
        [, $withoutFileId] = $this->createPublishedRequestForMahasiswa(true);
        [, $withFileId] = $this->createPublishedRequestForMahasiswa(true);

        DB::table('arsip_digital.request_assignments')->where('assignment_id', $withoutFileId)->update(['status' => 'waiting_verification']);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$withFileId.'/files/upload', [
                'file' => $this->pdfUpload('bulk-approve.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/bulk-approve', [
                'assignment_ids' => [$notSubmittedId, $withoutFileId, $withFileId],
            ])
            ->assertOk()
            ->assertJsonPath('data.result.updated', 1)
            ->assertJsonPath('data.result.skipped', 2);

        $this->assertSame('not_submitted', DB::table('arsip_digital.request_assignments')->where('assignment_id', $notSubmittedId)->value('status'));
        $this->assertSame('waiting_verification', DB::table('arsip_digital.request_assignments')->where('assignment_id', $withoutFileId)->value('status'));
        $this->assertSame('approved', DB::table('arsip_digital.request_assignments')->where('assignment_id', $withFileId)->value('status'));
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_file_approved')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'type' => 'request_file_approved',
            'entity_id' => $withFileId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'type' => 'request_file_approved',
            'entity_id' => $notSubmittedId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'type' => 'request_file_approved',
            'entity_id' => $withoutFileId,
        ], 'sqlite');
    }

    public function test_bulk_reject_skips_assignments_without_current_file(): void
    {
        [, $notSubmittedId] = $this->createPublishedRequestForMahasiswa(true);
        [, $withoutFileId] = $this->createPublishedRequestForMahasiswa(true);
        [, $withFileId] = $this->createPublishedRequestForMahasiswa(true);

        DB::table('arsip_digital.request_assignments')->where('assignment_id', $withoutFileId)->update(['status' => 'waiting_verification']);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$withFileId.'/files/upload', [
                'file' => $this->pdfUpload('bulk-reject.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/bulk-reject', [
                'assignment_ids' => [$notSubmittedId, $withoutFileId, $withFileId],
                'reason' => 'File salah',
            ])
            ->assertOk()
            ->assertJsonPath('data.result.updated', 1)
            ->assertJsonPath('data.result.skipped', 2);

        $this->assertSame('not_submitted', DB::table('arsip_digital.request_assignments')->where('assignment_id', $notSubmittedId)->value('status'));
        $this->assertSame('waiting_verification', DB::table('arsip_digital.request_assignments')->where('assignment_id', $withoutFileId)->value('status'));
        $this->assertSame('rejected', DB::table('arsip_digital.request_assignments')->where('assignment_id', $withFileId)->value('status'));
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_file_rejected')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'type' => 'request_file_rejected',
            'entity_id' => $withFileId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'type' => 'request_file_rejected',
            'entity_id' => $notSubmittedId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'type' => 'request_file_rejected',
            'entity_id' => $withoutFileId,
        ], 'sqlite');
    }

    public function test_admin_assignments_are_paginated(): void
    {
        [$requestId] = $this->createPublishedRequestForMahasiswa(true);

        foreach (['22010002', '22010003', '22010004'] as $identifier) {
            DB::table('arsip_digital.request_assignments')->insert([
                'request_id' => $requestId,
                'target_user_id' => 2,
                'target_role' => 'mahasiswa',
                'identifier' => $identifier,
                'name_snapshot' => 'Mahasiswa '.$identifier,
                'status' => 'not_submitted',
                'is_late' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests/'.$requestId.'/assignments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.per_page', 2)
            ->assertJsonPath('data.meta.total', 4);
    }
}
