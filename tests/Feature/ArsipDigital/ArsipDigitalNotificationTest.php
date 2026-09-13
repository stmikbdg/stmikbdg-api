<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class ArsipDigitalNotificationTest extends ArsipDigitalFeatureTestCase
{
    public function test_publish_request_specific_to_mahasiswa_notifies_only_selected_target(): void
    {
        $this->seedExtraMahasiswa();

        $requestId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/requests', [
                'title' => 'Akta Kelahiran',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
                'max_files' => 1,
            ])
            ->assertCreated()
            ->json('data.request.request_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/requests/'.$requestId.'/publish')
            ->assertOk();

        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_published')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_published',
            'entity_type' => 'request',
            'entity_id' => $requestId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'recipient_user_id' => 4,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_published',
            'entity_id' => $requestId,
        ], 'sqlite');
    }

    public function test_append_target_notifies_only_new_target(): void
    {
        $this->seedExtraMahasiswa();

        $requestId = $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/requests', [
                'title' => 'Ijazah',
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001'],
                'max_files' => 1,
            ])
            ->assertCreated()
            ->json('data.request.request_id');

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/requests/'.$requestId.'/publish')
            ->assertOk();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/requests/'.$requestId.'/targets', [
                'target_role' => 'mahasiswa',
                'scope_type' => 'specific',
                'target_identifiers' => ['22010001', '22010002'],
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.created', 1)
            ->assertJsonPath('data.summary.skipped_duplicate', 1);

        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_target_added')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 4,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_target_added',
            'entity_type' => 'request',
            'entity_id' => $requestId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_target_added',
            'entity_id' => $requestId,
        ], 'sqlite');
    }

    public function test_request_file_upload_notifies_admin(): void
    {
        $this->seedAdminView();
        [$requestId, $assignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $requestFileId = $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$assignmentId.'/files/upload', [
                'file' => $this->pdfUpload('upload-request.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated()
            ->json('data.request_file.request_file_id');

        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_file_submitted')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 1,
            'recipient_role' => 'admin',
            'type' => 'request_file_submitted',
            'entity_type' => 'request_file',
            'entity_id' => $requestFileId,
        ], 'sqlite');
        $this->assertSame($requestId, json_decode(DB::table('arsip_digital.notifications')->where('type', 'request_file_submitted')->value('data'), true)['request_id']);
    }

    public function test_admin_approve_and_reject_notify_mahasiswa(): void
    {
        $this->seedAdminView();
        [, $approvedAssignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$approvedAssignmentId.'/files/upload', [
                'file' => $this->pdfUpload('approve.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$approvedAssignmentId.'/approve')
            ->assertOk();

        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_file_approved',
            'entity_type' => 'request_assignment',
            'entity_id' => $approvedAssignmentId,
        ], 'sqlite');

        [, $rejectedAssignmentId] = $this->createPublishedRequestForMahasiswa(true);

        $this->actingAsMahasiswa()
            ->post('/api/arsip-digital/request-assignments/'.$rejectedAssignmentId.'/files/upload', [
                'file' => $this->pdfUpload('reject.pdf'),
            ], ['X-Active-Role' => 'mahasiswa'])
            ->assertCreated();

        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/admin/request-assignments/'.$rejectedAssignmentId.'/reject', [
                'reason' => 'File buram',
            ])
            ->assertOk();

        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'request_file_rejected',
            'entity_type' => 'request_assignment',
            'entity_id' => $rejectedAssignmentId,
        ], 'sqlite');
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_file_approved')->count());
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'request_file_rejected')->count());
    }

    public function test_distribution_file_upload_notifies_only_recipient(): void
    {
        $this->seedExtraMahasiswa();

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
                'file' => $this->pdfUpload('sertifikat.pdf'),
            ], ['X-Active-Role' => 'admin'])
            ->assertCreated();

        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('type', 'distribution_file_available')->count());
        $this->assertDatabaseHas('arsip_digital.notifications', [
            'recipient_user_id' => 2,
            'recipient_role' => 'mahasiswa',
            'type' => 'distribution_file_available',
            'entity_type' => 'distribution_recipient',
            'entity_id' => $recipientId,
        ], 'sqlite');
        $this->assertDatabaseMissing('arsip_digital.notifications', [
            'recipient_user_id' => 4,
            'recipient_role' => 'mahasiswa',
            'type' => 'distribution_file_available',
        ], 'sqlite');
    }

    public function test_lists_only_current_user_role_notifications_with_pagination(): void
    {
        $this->notification(2, 'mahasiswa', 'request.published', 'A');
        $this->notification(2, 'mahasiswa', 'distribution.published', 'B');
        $this->notification(2, 'dosen', 'request.published', 'Wrong role');
        $this->notification(3, 'dosen', 'request.published', 'Wrong user');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications?unread_only=1&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(1, 'data.notifications');
    }

    public function test_filters_by_type_and_validates_query(): void
    {
        $this->notification(2, 'mahasiswa', 'request.published', 'A');
        $this->notification(2, 'mahasiswa', 'distribution.published', 'B');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications?type=distribution.published')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.title', 'B');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications?per_page=51')
            ->assertUnprocessable();
    }

    public function test_unread_count_uses_active_role(): void
    {
        $this->notification(2, 'mahasiswa', 'request.published', 'A');
        $this->notification(2, 'mahasiswa', 'request.published', 'Read', now());
        $this->notification(2, 'dosen', 'request.published', 'Wrong role');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_mark_read_is_limited_to_current_user_and_role(): void
    {
        $ownId = $this->notification(2, 'mahasiswa', 'request.published', 'Own');
        $otherUserId = $this->notification(3, 'dosen', 'request.published', 'Other user');
        $otherRoleId = $this->notification(2, 'dosen', 'request.published', 'Other role');

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/notifications/'.$otherUserId.'/read')
            ->assertNotFound();

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/notifications/'.$otherRoleId.'/read')
            ->assertNotFound();

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/notifications/'.$ownId.'/read')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.notification.notification_id', $ownId);

        $this->assertNotNull(DB::table('arsip_digital.notifications')->where('notification_id', $ownId)->value('read_at'));
        $this->assertNull(DB::table('arsip_digital.notifications')->where('notification_id', $otherUserId)->value('read_at'));
        $this->assertNull(DB::table('arsip_digital.notifications')->where('notification_id', $otherRoleId)->value('read_at'));
    }

    public function test_unread_count_changes_after_mark_read_and_read_all(): void
    {
        $ownId = $this->notification(2, 'mahasiswa', 'request.published', 'Own');
        $ownSecondId = $this->notification(2, 'mahasiswa', 'request.published', 'Own second');
        $otherRoleId = $this->notification(2, 'dosen', 'request.published', 'Other role');
        $otherUserId = $this->notification(3, 'dosen', 'request.published', 'Other user');

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/notifications/'.$ownId.'/read')
            ->assertOk();

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 1);

        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertNotNull(DB::table('arsip_digital.notifications')->where('notification_id', $ownId)->value('read_at'));
        $this->assertNotNull(DB::table('arsip_digital.notifications')->where('notification_id', $ownSecondId)->value('read_at'));
        $this->assertNull(DB::table('arsip_digital.notifications')->where('notification_id', $otherRoleId)->value('read_at'));
        $this->assertNull(DB::table('arsip_digital.notifications')->where('notification_id', $otherUserId)->value('read_at'));
    }

    private function seedAdminView(): void
    {
        DB::table('vusers')->insert(['id' => 1, 'kd_user' => 'ADM-ADM001', 'name' => 'Admin Test', 'is_admin' => 1]);
    }

    private function seedExtraMahasiswa(): void
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
    }

    private function notification(int $userId, string $role, string $type, string $title, mixed $readAt = null): int
    {
        return DB::table('arsip_digital.notifications')->insertGetId([
            'recipient_user_id' => $userId,
            'recipient_role' => $role,
            'type' => $type,
            'title' => $title,
            'message' => 'Pesan',
            'entity_type' => 'request',
            'entity_id' => 10,
            'data' => json_encode(['foo' => 'bar']),
            'read_at' => $readAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
