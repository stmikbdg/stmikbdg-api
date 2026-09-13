<?php

namespace Tests\Feature\ArsipDigital;

use App\Models\Users\UserView;
use Illuminate\Support\Facades\DB;

class AuditLogNoteTest extends ArsipDigitalFeatureTestCase
{
    private string $url;

    private int $auditLogId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditLogId = DB::table('arsip_digital.audit_logs')->insertGetId([
            'actor_user_id' => 2,
            'actor_role' => 'mahasiswa',
            'action' => 'file.uploaded',
            'entity_type' => 'archive_file',
            'entity_id' => '10',
            'description' => 'File diunggah.',
            'created_at' => now(),
        ]);
        $this->url = "/api/arsip-digital/admin/audit-logs/{$this->auditLogId}/note";
    }

    public function test_get_returns_null_before_note_exists_and_missing_parent_returns_not_found(): void
    {
        $this->actingAsAdmin()
            ->getJson($this->url)
            ->assertOk()
            ->assertExactJson([
                'status' => 'success',
                'data' => ['note' => null, 'revisions' => []],
            ]);

        foreach (['getJson', 'postJson', 'putJson'] as $method) {
            $payload = $method === 'getJson' ? [] : ['note' => 'Tidak tersimpan', 'expected_updated_at' => now()->toISOString()];
            $this->actingAsAdmin()->{$method}('/api/arsip-digital/admin/audit-logs/999999/note', $payload)->assertNotFound();
        }
    }

    public function test_admin_create_and_cross_admin_update_preserve_identity_and_all_revisions(): void
    {
        $created = $this->actingAsAdmin()->postJson($this->url, [
            'note' => '  Versi pertama  ',
            'creator_user_id' => 999,
            'creator_name_snapshot' => 'Injected Creator',
            'last_editor_user_id' => 999,
            'last_editor_name_snapshot' => 'Injected Editor',
            'current_version' => 99,
        ])->assertCreated()
            ->assertJsonPath('data.note.note', 'Versi pertama')
            ->assertJsonPath('data.note.creator_user_id', 1)
            ->assertJsonPath('data.note.creator_name_snapshot', 'Admin Test')
            ->assertJsonPath('data.note.last_editor_user_id', 1)
            ->assertJsonPath('data.note.last_editor_name_snapshot', 'Admin Test')
            ->assertJsonPath('data.note.current_version', 1)
            ->assertJsonPath('data.revisions.0.version', 1)
            ->assertJsonPath('data.revisions.0.note', 'Versi pertama')
            ->assertJsonPath('data.revisions.0.actor_user_id', 1)
            ->assertJsonPath('data.revisions.0.actor_name_snapshot', 'Admin Test');

        $adminB = $this->adminUser(4, 'Admin B');
        $updated = $this->actingAs($adminB, 'api')->withHeader('X-Active-Role', 'admin')->putJson($this->url, [
            'note' => 'Versi kedua',
            'expected_updated_at' => $created->json('data.note.updated_at'),
            'creator_user_id' => 777,
            'creator_name_snapshot' => 'Injected Again',
            'last_editor_user_id' => 777,
            'last_editor_name_snapshot' => 'Injected Again',
        ])->assertOk()
            ->assertJsonPath('data.note.note', 'Versi kedua')
            ->assertJsonPath('data.note.creator_user_id', 1)
            ->assertJsonPath('data.note.creator_name_snapshot', 'Admin Test')
            ->assertJsonPath('data.note.last_editor_user_id', 4)
            ->assertJsonPath('data.note.last_editor_name_snapshot', 'Admin B')
            ->assertJsonPath('data.note.current_version', 2)
            ->assertJsonCount(2, 'data.revisions')
            ->assertJsonPath('data.revisions.0.version', 2)
            ->assertJsonPath('data.revisions.0.note', 'Versi kedua')
            ->assertJsonPath('data.revisions.0.actor_user_id', 4)
            ->assertJsonPath('data.revisions.0.actor_name_snapshot', 'Admin B')
            ->assertJsonPath('data.revisions.1.version', 1)
            ->assertJsonPath('data.revisions.1.note', 'Versi pertama');

        $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Versi ketiga',
            'expected_updated_at' => $updated->json('data.note.updated_at'),
        ])->assertOk()
            ->assertJsonPath('data.note.current_version', 3)
            ->assertJsonPath('data.revisions.0.version', 3)
            ->assertJsonPath('data.revisions.1.version', 2)
            ->assertJsonPath('data.revisions.2.version', 1);
        $this->actingAsAdmin()->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data.note.note', 'Versi ketiga')
            ->assertJsonCount(3, 'data.revisions')
            ->assertJsonPath('data.revisions.0.version', 3)
            ->assertJsonPath('data.revisions.2.version', 1);

        $this->assertDatabaseHas('arsip_digital.audit_log_notes', [
            'audit_log_id' => $this->auditLogId,
            'creator_user_id' => 1,
            'creator_name_snapshot' => 'Admin Test',
            'last_editor_user_id' => 1,
            'last_editor_name_snapshot' => 'Admin Test',
            'current_version' => 3,
        ], 'sqlite');
        $this->assertSame(3, DB::table('arsip_digital.audit_log_note_revisions')->count());
    }

    public function test_multi_role_admin_uses_canonical_dosen_name_without_admin_profile(): void
    {
        DB::table('users')->where('id', 1)->update(['kd_user' => 'DSN-IF054', 'is_admin' => 1, 'is_dosen' => 1]);
        DB::table('admins')->where('user_id', 1)->delete();
        DB::table('dosen')->insert(['kd_dosen' => 'IF054', 'nm_dosen' => '  Mina Ismu Rahayu, M.T  ']);
        $actor = new UserView;
        $actor->setRawAttributes(['id' => 1, 'kd_user' => 'DSN-IF054', 'is_admin' => true, 'is_mhs' => false, 'is_dosen' => true, 'is_staff' => false], true);

        $this->actingAs($actor, 'api')->withHeader('X-Active-Role', 'admin')->postJson($this->url, [
            'note' => 'Dosen sebagai admin',
            'creator_name_snapshot' => 'Injected',
            'last_editor_name_snapshot' => 'Injected',
        ])->assertCreated()
            ->assertJsonPath('data.note.creator_name_snapshot', 'Mina Ismu Rahayu, M.T')
            ->assertJsonPath('data.note.last_editor_name_snapshot', 'Mina Ismu Rahayu, M.T')
            ->assertJsonPath('data.revisions.0.actor_name_snapshot', 'Mina Ismu Rahayu, M.T');
    }

    public function test_admin_without_profile_uses_deterministic_name_fallback(): void
    {
        DB::table('users')->insert(['id' => 5, 'kd_user' => 'MHS-MULTI5', 'is_admin' => 1, 'is_mhs' => 1]);
        $actor = new UserView;
        $actor->setRawAttributes(['id' => 5, 'kd_user' => 'MHS-MULTI5', 'is_admin' => true, 'is_mhs' => true, 'is_dosen' => false, 'is_staff' => false], true);

        $this->actingAs($actor, 'api')->withHeader('X-Active-Role', 'admin')->postJson($this->url, ['note' => 'Fallback'])
            ->assertCreated()
            ->assertJsonPath('data.note.creator_name_snapshot', 'Admin')
            ->assertJsonPath('data.note.last_editor_name_snapshot', 'Admin')
            ->assertJsonPath('data.revisions.0.actor_name_snapshot', 'Admin');
    }

    public function test_duplicate_create_stale_update_and_delete_are_rejected(): void
    {
        $created = $this->actingAsAdmin()->postJson($this->url, ['note' => 'Awal'])->assertCreated();
        $expected = $created->json('data.note.updated_at');

        $this->actingAsAdmin()->postJson($this->url, ['note' => 'Duplikat'])->assertStatus(409);
        $updated = $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Baru',
            'expected_updated_at' => $expected,
        ])->assertOk();
        $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Stale',
            'expected_updated_at' => $expected,
        ])->assertStatus(409);
        $this->actingAsAdmin()->deleteJson($this->url)->assertStatus(405);

        $this->assertSame('Baru', DB::table('arsip_digital.audit_log_notes')->value('note'));
        $this->assertSame(2, DB::table('arsip_digital.audit_log_note_revisions')->count());
        $this->assertSame(2, $updated->json('data.note.current_version'));
    }

    public function test_non_admin_is_rejected_by_every_note_endpoint(): void
    {
        $this->actingAsMahasiswa()->getJson($this->url)->assertForbidden();
        $this->actingAsMahasiswa()->postJson($this->url, ['note' => 'Tidak boleh'])->assertForbidden();
        $this->actingAsMahasiswa()->putJson($this->url, [
            'note' => 'Tidak boleh',
            'expected_updated_at' => now()->toISOString(),
        ])->assertForbidden();
    }

    public function test_note_validation_and_update_token_are_required(): void
    {
        foreach ([null, '', '   ', "\n\t", str_repeat('x', 5001)] as $note) {
            $this->actingAsAdmin()->postJson($this->url, ['note' => $note])->assertUnprocessable();
        }

        $created = $this->actingAsAdmin()->postJson($this->url, ['note' => str_repeat('x', 5000)])->assertCreated();
        $this->actingAsAdmin()->putJson($this->url, ['note' => 'Tanpa token'])->assertUnprocessable();
        $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Token salah',
            'expected_updated_at' => 'not-a-date',
        ])->assertUnprocessable();
        $this->actingAsAdmin()->putJson($this->url, [
            'note' => '   ',
            'expected_updated_at' => $created->json('data.note.updated_at'),
        ])->assertUnprocessable();
    }

    public function test_create_and_update_write_sanitized_audit_events(): void
    {
        $created = $this->actingAsAdmin()->postJson($this->url, ['note' => 'Rahasia awal'])->assertCreated();
        $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Rahasia revisi',
            'expected_updated_at' => $created->json('data.note.updated_at'),
        ])->assertOk();

        $events = DB::table('arsip_digital.audit_logs')
            ->whereIn('action', ['audit_log_note.created', 'audit_log_note.updated'])
            ->orderBy('audit_log_id')
            ->get();
        $this->assertSame(['audit_log_note.created', 'audit_log_note.updated'], $events->pluck('action')->all());
        foreach ($events as $index => $event) {
            $this->assertSame(1, $event->actor_user_id);
            $this->assertSame('admin', $event->actor_role);
            $this->assertSame('audit_log_note', $event->entity_type);
            $this->assertSame([
                'parent_audit_log_id' => $this->auditLogId,
                'version' => $index + 1,
            ], json_decode($event->metadata, true));
            $this->assertStringNotContainsString('Rahasia', (string) $event->metadata);
        }
    }

    public function test_note_revision_and_current_state_rollback_when_audit_event_fails(): void
    {
        DB::statement("CREATE TRIGGER arsip_digital.fail_note_create BEFORE INSERT ON audit_logs WHEN NEW.action = 'audit_log_note.created' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->actingAsAdmin()->postJson($this->url, ['note' => 'Tidak tersimpan'])->assertServerError();
        $this->assertSame(0, DB::table('arsip_digital.audit_log_notes')->count());
        $this->assertSame(0, DB::table('arsip_digital.audit_log_note_revisions')->count());

        DB::statement('DROP TRIGGER arsip_digital.fail_note_create');
        $created = $this->actingAsAdmin()->postJson($this->url, ['note' => 'Tetap'])->assertCreated();
        DB::statement("CREATE TRIGGER arsip_digital.fail_note_update BEFORE INSERT ON audit_logs WHEN NEW.action = 'audit_log_note.updated' BEGIN SELECT RAISE(FAIL, 'audit failed'); END");
        $this->actingAsAdmin()->putJson($this->url, [
            'note' => 'Tidak tersimpan',
            'expected_updated_at' => $created->json('data.note.updated_at'),
        ])->assertServerError();

        $this->assertDatabaseHas('arsip_digital.audit_log_notes', ['note' => 'Tetap', 'current_version' => 1], 'sqlite');
        $this->assertSame(1, DB::table('arsip_digital.audit_log_note_revisions')->count());
    }

    public function test_backfill_replaces_only_matching_placeholders_with_canonical_name(): void
    {
        DB::table('users')->where('id', 1)->update(['kd_user' => 'DSN-IF054']);
        DB::table('admins')->where('user_id', 1)->delete();
        DB::table('dosen')->insert(['kd_dosen' => 'IF054', 'nm_dosen' => 'Mina Ismu Rahayu, M.T']);
        $noteId = DB::table('arsip_digital.audit_log_notes')->insertGetId([
            'audit_log_id' => $this->auditLogId,
            'note' => 'Legacy',
            'creator_user_id' => 1,
            'creator_name_snapshot' => 'Admin #1',
            'last_editor_user_id' => 1,
            'last_editor_name_snapshot' => 'Admin',
            'current_version' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('arsip_digital.audit_log_note_revisions')->insert([
            ['audit_log_note_id' => $noteId, 'version' => 1, 'note' => 'Legacy', 'actor_user_id' => 1, 'actor_name_snapshot' => 'Admin #1', 'created_at' => now()],
            ['audit_log_note_id' => $noteId, 'version' => 2, 'note' => 'Legacy', 'actor_user_id' => 1, 'actor_name_snapshot' => 'Nama Tersimpan', 'created_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_08_20_000018_backfill_audit_log_note_actor_names.php');
        $migration->up();

        $note = DB::table('arsip_digital.audit_log_notes')->where('audit_log_note_id', $noteId)->first();
        $this->assertSame('Mina Ismu Rahayu, M.T', $note->creator_name_snapshot);
        $this->assertSame('Mina Ismu Rahayu, M.T', $note->last_editor_name_snapshot);
        $this->assertSame('Legacy', $note->note);
        $this->assertSame(2, $note->current_version);
        $this->assertSame([
            'Mina Ismu Rahayu, M.T',
            'Nama Tersimpan',
        ], DB::table('arsip_digital.audit_log_note_revisions')->orderBy('version')->pluck('actor_name_snapshot')->all());
    }

    public function test_audit_log_list_eager_loads_note_summary_without_n_plus_one(): void
    {
        $this->actingAsAdmin()->postJson($this->url, ['note' => 'Ringkasan'])->assertCreated();
        DB::table('arsip_digital.audit_logs')->insert([
            'action' => 'file.deleted',
            'entity_type' => 'archive_file',
            'entity_id' => '11',
            'created_at' => now()->subSecond(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAsAdmin()->getJson('/api/arsip-digital/admin/audit-logs?per_page=100')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $item = collect($response->json('data.audit_logs'))->firstWhere('audit_log_id', $this->auditLogId);
        $this->assertSame('Ringkasan', $item['note']['note']);
        $this->assertSame('Admin Test', $item['note']['creator_name_snapshot']);
        $this->assertSame('Admin Test', $item['note']['last_editor_name_snapshot']);
        $this->assertSame(1, $item['note']['current_version']);
        $this->assertArrayHasKey('created_at', $item['note']);
        $this->assertArrayHasKey('updated_at', $item['note']);
        $this->assertSame(1, $queries->filter(fn (string $query): bool => str_contains($query, 'audit_log_notes'))->count());
    }

    private function adminUser(int $id, string $name): UserView
    {
        DB::table('users')->insert(['id' => $id, 'kd_user' => 'MHS-MULTI'.$id, 'name' => $name, 'is_admin' => 1, 'is_mhs' => 1]);
        DB::table('admins')->insert(['kd_admin' => 'ADM'.$id, 'user_id' => $id, 'nm_admin' => "  $name  "]);
        $user = new UserView;
        $user->setRawAttributes(['id' => $id, 'kd_user' => 'MHS-MULTI'.$id, 'is_admin' => true, 'is_mhs' => true, 'is_dosen' => false, 'is_staff' => false], true);

        return $user;
    }
}
