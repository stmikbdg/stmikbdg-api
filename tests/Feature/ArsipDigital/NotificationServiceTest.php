<?php

namespace Tests\Feature\ArsipDigital;

use App\Services\ArsipDigital\NotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'myconfig.database.first_connection' => 'sqlite',
        ]);

        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();
        DB::statement("ATTACH DATABASE ':memory:' AS arsip_digital");
        DB::statement('CREATE TABLE vusers (id integer primary key, kd_user varchar, name varchar, is_admin integer, is_mhs integer, is_dosen integer, is_staff integer, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.notifications (notification_id integer primary key autoincrement, recipient_user_id integer not null, recipient_role varchar not null, type varchar not null, title varchar not null, message text, entity_type varchar, entity_id integer, data text, read_at datetime, created_at datetime, updated_at datetime)');
    }

    public function test_creates_and_queries_only_current_user_role_notifications(): void
    {
        $service = new NotificationService();

        $service->sendToUser(2, 'mahasiswa', 'request.published', 'Upload Akta', null, 'request', 10);
        $service->sendToUser(2, 'dosen', 'request.published', 'Dosen', null, 'request', 10);
        $service->sendToUser(3, 'mahasiswa', 'request.published', 'Other', null, 'request', 10);

        $notifications = $service->queryForUser((object) ['id' => 2], 'mahasiswa', [
            'type' => 'request.published',
            'unread' => true,
        ])->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Upload Akta', $notifications->first()->title);
        $this->assertSame(1, $service->unreadCount((object) ['id' => 2], 'mahasiswa'));
    }

    public function test_bulk_send_dedupes_skips_missing_target_and_marks_read_safely(): void
    {
        $service = new NotificationService();

        $inserted = $service->sendToManyUsers([
            ['target_user_id' => 2, 'target_role' => 'mahasiswa'],
            ['user_id' => 2, 'role' => 'mahasiswa'],
            ['id' => 3, 'role' => 'dosen'],
            ['role' => 'mahasiswa'],
        ], [
            'type' => 'distribution.published',
            'title' => 'Arsip Baru',
            'message' => 'Silakan unduh.',
            'entity_type' => 'distribution',
            'entity_id' => 20,
            'data' => ['foo' => 'bar'],
        ]);

        $this->assertSame(2, $inserted);
        $this->assertSame(2, DB::table('arsip_digital.notifications')->count());

        $notificationId = DB::table('arsip_digital.notifications')
            ->where('recipient_user_id', 2)
            ->value('notification_id');

        $this->assertNull($service->markRead((object) ['id' => 3], 'mahasiswa', $notificationId));
        $this->assertSame(1, $service->unreadCount((object) ['id' => 2], 'mahasiswa'));

        $this->assertNotNull($service->markRead((object) ['id' => 2], 'mahasiswa', $notificationId));
        $this->assertSame(0, $service->unreadCount((object) ['id' => 2], 'mahasiswa'));

        $this->assertSame(1, $service->markAllRead((object) ['id' => 3], 'dosen'));
        $this->assertSame(0, $service->unreadCount((object) ['id' => 3], 'dosen'));
    }

    public function test_send_to_admins_targets_only_admin_users(): void
    {
        DB::table('vusers')->insert([
            ['id' => 1, 'kd_user' => 'ADM-ADM001', 'name' => 'Admin', 'is_admin' => 1],
            ['id' => 2, 'kd_user' => 'MHS-001', 'name' => 'Mahasiswa', 'is_admin' => 0],
        ]);

        $inserted = (new NotificationService())->sendToAdmins([
            'type' => 'system.info',
            'title' => 'Info',
        ]);

        $this->assertSame(1, $inserted);
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('recipient_role', 'admin')->value('recipient_user_id'));
    }
}
