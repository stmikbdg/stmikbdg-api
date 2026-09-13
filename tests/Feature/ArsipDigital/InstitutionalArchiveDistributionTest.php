<?php

namespace Tests\Feature\ArsipDigital;

use App\Models\ArsipDigital\Distribution;
use App\Models\Users\UserView;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InstitutionalArchiveDistributionTest extends ArsipDigitalFeatureTestCase
{
    private int $archiveId;

    private int $fileId;

    protected function setUp(): void
    {
        parent::setUp();
        $unit = DB::table('arsip_digital.institutional_units')->insertGetId(['name' => 'Akademik', 'is_active' => true, 'created_by_user_id' => 1]);
        $archive = $this->actingAsAdmin()->post('/api/arsip-digital/admin/institutional-archives', [
            'file' => $this->pdfUpload('lembaga.pdf', '%PDF-1.4 institutional'), 'title' => 'Arsip Lembaga', 'unit_id' => $unit,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.archive');
        $this->archiveId = $archive['institutional_archive_id'];
        $this->fileId = $archive['current_file_id'];
        $this->markInstitutionalVerificationReady($this->fileId);
    }

    public function test_management_requires_admin_and_is_shared_between_admins(): void
    {
        $payload = $this->targets();
        foreach ([$this->actingAsMahasiswa(), $this->actingAsDosen()] as $client) {
            $client->postJson($this->base().'/preview-targets', $payload)->assertForbidden();
            $client->postJson($this->base(), $payload + ['title' => 'Forbidden'])->assertForbidden();
        }

        $draft = $this->draft();
        $publishPayload = $this->publishPayload($draft, $this->fileId);
        $adminB = $this->adminUser(9);
        $this->actingAs($adminB, 'api')->withHeader('X-Active-Role', 'admin')->getJson("/api/arsip-digital/admin/institutional-distributions/$draft")->assertOk()->assertJsonPath('data.distribution.distribution_id', $draft);
        $this->actingAs($adminB, 'api')->withHeader('X-Active-Role', 'admin')->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $publishPayload)->assertOk();
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_distribution.published', 'actor_user_id' => 9]);
    }

    public function test_draft_persists_target_snapshot_without_materializing_or_notifying(): void
    {
        $notifications = DB::table('arsip_digital.notifications')->count();
        $response = $this->actingAsAdmin()->postJson($this->base(), $this->targets() + ['title' => 'Snapshot'])
            ->assertCreated()
            ->assertJsonPath('data.distribution.target_count', 1)
            ->assertJsonPath('data.distribution.target_role', 'mahasiswa')
            ->assertJsonPath('data.distribution.scope_type', 'specific')
            ->assertJsonMissing(['target_filters', 'target_identifiers', 'target_segment_ids'])
            ->assertJsonPath('data.distribution.recipients_count', 0);
        $draft = $response->json('data.distribution.distribution_id');

        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'target_count' => 1, 'status' => 'draft']);
        $this->assertSame(0, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
        $this->assertSame($notifications, DB::table('arsip_digital.notifications')->count());
        $this->assertSame(1, Distribution::findOrFail($draft)->target_count);
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$draft")->assertOk()->assertJsonPath('data.distribution.target_count', 1)->assertJsonPath('data.distribution.recipients_count', 0);
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->assertJsonCount(0, 'data.distributions');

        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))
            ->assertOk()->assertJsonPath('data.distribution.target_count', 1)->assertJsonPath('data.distribution.recipients_count', 1);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'target_count' => 1]);
    }

    public function test_publish_uses_current_authoritative_targets_without_overwriting_draft_snapshot(): void
    {
        $payload = ['target_role' => 'mahasiswa', 'scope_type' => 'filter', 'target_filters' => ['has_account' => true]];
        $draft = $this->draft($payload);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'target_count' => 1]);

        DB::table('users')->insert(['id' => 99, 'kd_user' => 'MHS-22999999', 'name' => 'New Student', 'is_mhs' => 1]);
        DB::table('vmahasiswa')->insert(['mhs_id' => 99, 'nim' => '22999999', 'nm_mhs' => 'New Student', 'angkatan' => '2022', 'prodi' => 'TI', 'sts_mhs' => 'aktif']);

        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))
            ->assertOk()
            ->assertJsonPath('data.distribution.target_count', 1)
            ->assertJsonPath('data.distribution.recipients_count', 2);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'target_count' => 1]);
        $this->assertSame(2, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
    }

    public function test_specific_student_and_lecturer_preview_publish_exact_set_and_duplicates(): void
    {
        foreach ([['mahasiswa', ['22010001']], ['dosen', ['DSN001']]] as [$role, $identifiers]) {
            $payload = $this->targets($role, $identifiers);
            $preview = $this->actingAsAdmin()->postJson($this->base().'/preview-targets', $payload)->assertOk()->assertJsonPath('data.preview.total_valid', 1)->json('data.preview.valid_targets');
            $draft = $this->draft($payload);
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk()->assertJsonPath('data.distribution.recipients_count', 1);
            $actual = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->pluck('identifier')->all();
            $this->assertSame(collect($preview)->pluck('identifier')->all(), $actual);
        }

        $duplicate = $this->targets('mahasiswa', ['22010001', '22010001']);
        $draft = $this->draft($duplicate);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk()->assertJsonPath('data.distribution.recipients_count', 1);
    }

    public function test_filter_segment_and_preview_over_one_hundred_have_exact_publish_parity(): void
    {
        $users = $this->students(101);
        $segment = DB::table('arsip_digital.segments')->insertGetId(['name' => 'Bulk', 'target_role' => 'mahasiswa', 'is_active' => 1, 'created_by_user_id' => 1]);
        DB::table('arsip_digital.segment_members')->insert(collect($users)->map(fn ($row) => ['segment_id' => $segment, 'target_user_id' => $row['id'], 'target_role' => 'mahasiswa', 'identifier' => $row['nim'], 'name_snapshot' => $row['name']])->all());
        foreach ([
            ['target_role' => 'mahasiswa', 'scope_type' => 'filter', 'target_filters' => ['has_account' => true]],
            ['target_role' => 'mahasiswa', 'scope_type' => 'segment', 'target_segment_ids' => [$segment]],
        ] as $payload) {
            $preview = $this->actingAsAdmin()->postJson($this->base().'/preview-targets', $payload)->assertOk()->json('data.preview');
            $this->assertGreaterThan(100, $preview['total_valid']);
            $this->assertCount(100, $preview['valid_targets']);
            $draft = $this->draft($payload);
            $this->assertSame($preview['total_valid'], Distribution::findOrFail($draft)->target_count);
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk()->assertJsonPath('data.distribution.recipients_count', $preview['total_valid']);
        }
    }

    public function test_draft_targets_update_cancel_and_new_route_guards(): void
    {
        DB::table('users')->insert(['id' => 98, 'kd_user' => 'MHS-22010002', 'name' => 'Second Student', 'is_mhs' => 1]);
        DB::table('vmahasiswa')->insert(['mhs_id' => 98, 'nim' => '22010002', 'nm_mhs' => 'Second Student', 'angkatan' => '2022', 'prodi' => 'TI', 'sts_mhs' => 'aktif']);
        $draft = $this->draft();
        $url = "/api/arsip-digital/admin/institutional-distributions/$draft";
        foreach ([$this->actingAsMahasiswa(), $this->actingAsDosen()] as $client) {
            $client->getJson("$url/targets")->assertForbidden();
            $client->putJson($url, [])->assertForbidden();
            $client->deleteJson($url, [])->assertForbidden();
        }
        $targets = $this->actingAsAdmin()->getJson("$url/targets")->assertOk()->assertJsonMissing(['target_user_id', 'kd_user', 'scholarship', 'metadata'])->json('data');
        $notifications = DB::table('arsip_digital.notifications')->count();
        $objects = Storage::disk('s3')->allFiles();
        $payload = $this->targets('mahasiswa', ['22010001', '22010002']) + ['title' => 'Updated', 'description' => 'Metadata', 'expected_updated_at' => $targets['updated_at']];
        $updated = $this->actingAsAdmin()->putJson($url, $payload)->assertOk()->assertJsonPath('data.distribution.target_count', 2)->json('data.distribution');
        $this->assertSame(0, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
        $this->assertSame($notifications, DB::table('arsip_digital.notifications')->count());
        DB::table('arsip_digital.distributions')->where('distribution_id', $draft)->update(['updated_at' => now()->addSecond()]);
        $this->actingAsAdmin()->putJson($url, [...$payload, 'title' => 'Stale'])->assertStatus(409);
        $this->actingAsAdmin()->getJson("$url/recipients")->assertStatus(409);
        $freshToken = $this->actingAsAdmin()->getJson("$url/targets")->json('data.updated_at');
        $this->actingAsAdmin()->deleteJson($url, ['expected_updated_at' => $freshToken])->assertOk();
        $this->assertSoftDeleted('arsip_digital.distributions', ['distribution_id' => $draft]);
        $this->assertDatabaseHas('arsip_digital.audit_logs', ['action' => 'institutional_distribution.cancelled', 'entity_id' => (string) $draft]);
        $this->assertSame($objects, Storage::disk('s3')->allFiles());
        $this->assertSame($notifications, DB::table('arsip_digital.notifications')->count());

        $inactive = $this->draft();
        $token = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$inactive/targets")->json('data.updated_at');
        DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $this->archiveId)->update(['status' => 'deleted', 'deleted_at' => now()]);
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-distributions/$inactive", $this->targets() + ['title' => 'No', 'expected_updated_at' => $token])->assertStatus(409);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-distributions/$inactive", ['expected_updated_at' => $token])->assertStatus(409);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $inactive, 'status' => 'draft', 'deleted_at' => null]);
    }

    public function test_published_hides_criteria_and_recipients_remain_read_only(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk()->assertJsonMissing(['target_filters', 'target_identifiers', 'target_segment_ids']);
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$draft/recipients")->assertOk()->assertJsonCount(1, 'data.recipients');
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$draft/targets")->assertStatus(409);
        $this->actingAsAdmin()->putJson("/api/arsip-digital/admin/institutional-distributions/$draft", $this->targets() + ['title' => 'No', 'expected_updated_at' => Distribution::findOrFail($draft)->updated_at->toISOString()])->assertStatus(409);
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-distributions/$draft", ['expected_updated_at' => now()->toISOString()])->assertStatus(409);
    }

    public function test_update_and_cancel_roll_back_when_audit_insert_fails(): void
    {
        $draft = $this->draft();
        $url = "/api/arsip-digital/admin/institutional-distributions/$draft";
        $before = Distribution::findOrFail($draft)->only(['title', 'description', 'target_count', 'updated_at']);
        $token = $this->actingAsAdmin()->getJson("$url/targets")->json('data.updated_at');
        DB::unprepared("CREATE TEMP TRIGGER fail_distribution_audit BEFORE INSERT ON arsip_digital.audit_logs WHEN NEW.action IN ('institutional_distribution.updated', 'institutional_distribution.cancelled') BEGIN SELECT RAISE(ABORT, 'forced audit failure'); END");
        try {
            $this->actingAsAdmin()->putJson($url, $this->targets() + ['title' => 'Must rollback', 'expected_updated_at' => $token])->assertServerError();
            $after = Distribution::findOrFail($draft)->only(['title', 'description', 'target_count', 'updated_at']);
            $this->assertEquals($before, $after);
            $this->actingAsAdmin()->deleteJson($url, ['expected_updated_at' => $token])->assertServerError();
            $this->assertNotSoftDeleted('arsip_digital.distributions', ['distribution_id' => $draft]);
            $this->assertSame(0, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
            $this->assertSame(0, DB::table('arsip_digital.notifications')->where('data', 'like', '%"distribution_id":'.$draft.'%')->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_distribution_audit');
        }
    }

    public function test_inactive_archive_rejects_withdraw_without_side_effects(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
        $recipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->first();
        $audits = DB::table('arsip_digital.audit_logs')->where('entity_id', (string) $draft)->count();
        DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $this->archiveId)->update(['status' => 'deleted', 'deleted_at' => now()]);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/withdraw", ['reason' => 'No'])->assertStatus(409);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'status' => 'published', 'withdrawn_at' => null]);
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', ['recipient_id' => $recipient->recipient_id, 'delivery_status' => $recipient->delivery_status]);
        $this->assertSame($audits, DB::table('arsip_digital.audit_logs')->where('entity_id', (string) $draft)->count());
    }

    public function test_invalid_empty_and_mixed_targets_cannot_create_drafts(): void
    {
        foreach ([[], ['UNKNOWN'], ['22010001', 'UNKNOWN']] as $identifiers) {
            $before = DB::table('arsip_digital.distributions')->count();
            $this->actingAsAdmin()->postJson($this->base(), $this->targets('mahasiswa', $identifiers) + ['title' => 'Invalid'])->assertUnprocessable();
            $this->assertSame($before, DB::table('arsip_digital.distributions')->count());
        }
    }

    public function test_draft_exposes_authoritative_source_and_stale_publish_is_rejected(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$draft")
            ->assertOk()
            ->assertJsonPath('data.distribution.source_file_id', $this->fileId)
            ->assertJsonPath('data.distribution.source_file.file_id', $this->fileId)
            ->assertJsonPath('data.distribution.source_file.version_number', 1)
            ->assertJsonPath('data.distribution.source_file.display_filename', 'lembaga.pdf');

        $stale = $this->fileId;
        $this->actingAsAdmin()->post("/api/arsip-digital/admin/institutional-archives/$this->archiveId/versions", ['file' => $this->pdfUpload('v2.pdf'), 'reason' => 'V2'], ['Accept' => 'application/json'])->assertCreated();
        $current = DB::table('arsip_digital.institutional_archives')->where('institutional_archive_id', $this->archiveId)->value('current_file_id');
        $this->markInstitutionalVerificationReady($current);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $stale))->assertStatus(409);
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'status' => 'draft', 'source_file_id' => null]);
        $this->assertSame(0, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $current))->assertOk();
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'status' => 'published', 'source_file_id' => $current]);
    }

    public function test_publish_pins_exact_version_reuses_source_and_is_idempotent(): void
    {
        $beforeObjects = Storage::disk('s3')->allFiles();
        $draft = $this->draft();
        $payload = $this->publishPayload($draft, $this->fileId);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $payload)->assertOk();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $payload)->assertOk();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", [...$payload, 'source_file_id' => $this->fileId + 999])->assertStatus(409);
        $this->assertSame($beforeObjects, Storage::disk('s3')->allFiles(), 'Publish must reuse source object without creating a storage copy.');
        $this->actingAsAdmin()->post("/api/arsip-digital/admin/institutional-archives/$this->archiveId/versions", ['file' => $this->pdfUpload('v2.pdf'), 'reason' => 'V2'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertDatabaseHas('arsip_digital.distributions', ['distribution_id' => $draft, 'source_file_id' => $this->fileId]);
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', ['distribution_id' => $draft, 'file_id' => $this->fileId]);
        $this->assertSame(1, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
        $this->assertSame(1, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.published')->where('entity_id', (string) $draft)->count());
        $this->assertSame(1, DB::table('arsip_digital.notifications')->where('entity_type', 'distribution_recipient')->where('data', 'like', '%"distribution_id":'.$draft.'%')->count());
    }

    public function test_publish_tokens_are_required_only_for_first_materialization(): void
    {
        $draft = $this->draft();
        $url = "/api/arsip-digital/admin/institutional-distributions/$draft/publish";
        $this->actingAsAdmin()->postJson($url, ['source_file_id' => $this->fileId])->assertStatus(422);
        $payload = $this->publishPayload($draft, $this->fileId);
        $this->actingAsAdmin()->postJson($url, $payload)->assertOk();
        $recipients = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count();
        $notifications = DB::table('arsip_digital.notifications')->count();
        $audits = DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.published')->where('entity_id', (string) $draft)->count();
        $this->actingAsAdmin()->postJson($url, ['source_file_id' => $this->fileId])->assertOk();
        $this->actingAsAdmin()->postJson($url, ['source_file_id' => $this->fileId, 'expected_updated_at' => now()->subYear()->toISOString(), 'target_fingerprint' => str_repeat('0', 64)])->assertOk();
        $this->assertSame($recipients, DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->count());
        $this->assertSame($notifications, DB::table('arsip_digital.notifications')->count());
        $this->assertSame($audits, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.published')->where('entity_id', (string) $draft)->count());
        $this->actingAsAdmin()->postJson($url, ['source_file_id' => $this->fileId + 999])->assertStatus(409);
    }

    public function test_recipient_pagination_validates_and_is_stable(): void
    {
        $this->students(5);
        $draft = $this->draft(['target_role' => 'mahasiswa', 'scope_type' => 'filter', 'target_filters' => ['has_account' => true]]);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
        $url = "/api/arsip-digital/admin/institutional-distributions/$draft/recipients";
        foreach (['page=0', 'page=-1', 'page=no', 'per_page=0', 'per_page=-1', 'per_page=no', 'per_page=101'] as $query) {
            $this->actingAsAdmin()->getJson("$url?$query")->assertStatus(422);
        }
        $first = $this->actingAsAdmin()->getJson("$url?page=1&per_page=2")->assertOk()->assertJsonPath('data.meta.current_page', 1)->json('data.recipients');
        $middle = $this->actingAsAdmin()->getJson("$url?page=2&per_page=2")->assertOk()->assertJsonPath('data.meta.current_page', 2)->json('data.recipients');
        $this->assertNotSame(array_column($first, 'identifier'), array_column($middle, 'identifier'));
        $this->actingAsAdmin()->getJson("$url?page=999&per_page=2")->assertOk()->assertJsonPath('data.meta.current_page', 999)->assertJsonCount(0, 'data.recipients');
        $this->assertSame($first, $this->actingAsAdmin()->getJson("$url?page=1&per_page=2")->json('data.recipients'));
    }

    public function test_expiry_null_future_offset_boundary_and_invalid_are_enforced(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00 UTC');
        try {
            $this->assertIsInt($this->draft($this->targets(), null));
            foreach (['2026-08-03T12:00:00+02:00', '2026-08-03T09:59:59+00:00', 'invalid'] as $expiry) {
                $this->actingAsAdmin()->postJson($this->base(), $this->targets() + ['title' => 'Expiry', 'expires_at' => $expiry])->assertUnprocessable();
            }
            $draft = $this->draft($this->targets(), '2026-08-04T10:00:00+00:00');
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
            $recipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->value('recipient_id');
            Carbon::setTestNow('2026-08-04 10:00:00 UTC');
            $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient/download")->assertGone();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_recipient_isolation_tracking_preview_missing_and_payload_privacy(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
        $recipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->first();
        $this->actingAsDosen()->get("/api/arsip-digital/distribution-recipients/$recipient->recipient_id/download")->assertNotFound();
        $downloadAudits = DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.downloaded')->count();
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient->recipient_id/preview")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-disposition', 'inline; filename="lembaga.pdf"');
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', ['recipient_id' => $recipient->recipient_id, 'download_count' => 0]);
        $this->assertSame($downloadAudits, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.downloaded')->count());
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient->recipient_id/download")->assertOk();
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-files/$this->fileId/download")->assertOk();
        $this->assertDatabaseHas('arsip_digital.distribution_recipients', ['recipient_id' => $recipient->recipient_id, 'download_count' => 2, 'delivery_status' => 'downloaded']);
        $second = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$second/publish", $this->publishPayload($second, $this->fileId))->assertOk();
        $pageOne = $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions?per_page=1&page=1')->assertOk()->assertJsonMissing(['storage_path', 'storage_disk', 'checksum_sha256', 'target_user_id', 'created_by_user_id'])->assertJsonPath('data.meta.current_page', 1)->assertJsonPath('data.meta.last_page', 2)->assertJsonPath('data.meta.total', 2);
        $pageTwo = $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions?per_page=1&page=2')->assertOk()->assertJsonPath('data.meta.current_page', 2);
        $this->assertSame($second, $pageOne->json('data.distributions.0.distribution_id'));
        $this->assertSame($draft, $pageTwo->json('data.distributions.0.distribution_id'));
        $this->assertSame([$recipient->recipient_id], collect($pageTwo->json('data.distributions.0.recipients'))->pluck('recipient_id')->all());
        $this->actingAsDosen()->getJson('/api/arsip-digital/distributions')->assertOk()->assertJsonCount(0, 'data.distributions');
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions?per_page=101')->assertUnprocessable();
        Storage::disk('s3')->delete(DB::table('arsip_digital.institutional_archive_verifications')->where('source_file_id', $this->fileId)->value('storage_path'));
        $count = DB::table('arsip_digital.distribution_recipients')->where('recipient_id', $recipient->recipient_id)->value('download_count');
        $audits = DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.downloaded')->count();
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient->recipient_id/download")->assertNotFound();
        $this->assertSame($count, DB::table('arsip_digital.distribution_recipients')->where('recipient_id', $recipient->recipient_id)->value('download_count'));
        $this->assertSame($audits, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.downloaded')->count());
        $this->actingAsMahasiswa()->get("/api/arsip-digital/files/$this->fileId/download")->assertForbidden();
    }

    public function test_published_institutional_distribution_appears_as_safe_isolated_virtual_file(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
        $recipientId = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->value('recipient_id');

        $response = $this->actingAsMahasiswa()->getJson('/api/arsip-digital/files')
            ->assertOk()
            ->assertJsonFragment([
                'row_id' => "institutional-distribution-$recipientId",
                'recipient_id' => $recipientId,
                'distribution_id' => $draft,
                'source_type' => 'institutional_distribution',
            ]);
        $rows = collect($response->json('data.files'))->where('row_id', "institutional-distribution-$recipientId");
        $this->assertSame(1, $rows->count());
        $row = $rows->first();
        foreach (['storage_path', 'storage_disk', 'checksum_sha256', 'target_user_id', 'owner_user_id'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }

        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/files?search=LEMBAGA')->assertOk()->assertJsonFragment(['recipient_id' => $recipientId]);
        $this->actingAsMahasiswa()->getJson('/api/arsip-digital/files?search=tidak-ada')->assertOk()->assertJsonMissing(['recipient_id' => $recipientId]);
        $this->actingAsDosen()->getJson('/api/arsip-digital/files')->assertOk()->assertJsonMissing(['recipient_id' => $recipientId]);
        $this->actingAsMahasiswa()->get("/api/arsip-digital/files/$this->fileId/download")->assertForbidden();
    }

    public function test_withdraw_requires_reason_is_repeat_safe_and_preserves_source(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertOk();
        $recipient = DB::table('arsip_digital.distribution_recipients')->where('distribution_id', $draft)->value('recipient_id');
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/withdraw", [])->assertUnprocessable();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/withdraw", ['reason' => ' Diganti '])->assertOk();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/withdraw", ['reason' => 'Lagi'])->assertOk();
        $this->actingAsMahasiswa()->get("/api/arsip-digital/distribution-recipients/$recipient/download")->assertGone();
        $this->assertDatabaseHas('arsip_digital.institutional_archives', ['institutional_archive_id' => $this->archiveId, 'status' => 'active', 'current_file_id' => $this->fileId]);
        $this->assertDatabaseHas('arsip_digital.files', ['file_id' => $this->fileId, 'status' => 'active', 'is_current' => 1]);
        $this->assertSame(1, DB::table('arsip_digital.audit_logs')->where('action', 'institutional_distribution.withdrawn')->where('entity_id', (string) $draft)->count());
    }

    public function test_user_list_hides_deleted_source_and_restore_only_reveals_live_published_distribution(): void
    {
        Carbon::setTestNow('2026-08-03 10:00:00 UTC');
        try {
            $distribution = $this->draft($this->targets(), '2026-08-04T10:00:00+00:00');
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$distribution/publish", $this->publishPayload($distribution, $this->fileId))->assertOk();
            $this->assertSame([$distribution], collect($this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->json('data.distributions'))->pluck('distribution_id')->all());

            $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$this->archiveId", ['reason' => 'Hapus sementara'])->assertOk();
            $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->assertJsonCount(0, 'data.distributions');
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/$this->archiveId/restore")->assertOk();
            $this->assertSame([$distribution], collect($this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->json('data.distributions'))->pluck('distribution_id')->all());

            DB::table('arsip_digital.distributions')->where('distribution_id', $distribution)->update(['expires_at' => '2026-08-03 10:00:00']);
            $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->assertJsonCount(0, 'data.distributions');
            DB::table('arsip_digital.distributions')->where('distribution_id', $distribution)->update(['expires_at' => null]);
            $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$distribution/withdraw", ['reason' => 'Tidak berlaku'])->assertOk();
            $this->actingAsMahasiswa()->getJson('/api/arsip-digital/distributions')->assertOk()->assertJsonCount(0, 'data.distributions');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_deleted_archive_blocks_create_publish_and_download_but_restore_allows_new_draft(): void
    {
        $draft = $this->draft();
        $this->actingAsAdmin()->deleteJson("/api/arsip-digital/admin/institutional-archives/$this->archiveId", ['reason' => 'Hapus'])->assertOk();
        $this->actingAsAdmin()->postJson($this->base(), $this->targets() + ['title' => 'No'])->assertNotFound();
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-distributions/$draft/publish", $this->publishPayload($draft, $this->fileId))->assertStatus(409);
        $this->actingAsAdmin()->postJson("/api/arsip-digital/admin/institutional-archives/$this->archiveId/restore")->assertOk();
        $this->draft();
    }

    private function base(): string
    {
        return "/api/arsip-digital/admin/institutional-archives/$this->archiveId/distributions";
    }

    private function targets(string $role = 'mahasiswa', array $identifiers = ['22010001']): array
    {
        return ['target_role' => $role, 'scope_type' => 'specific', 'target_identifiers' => $identifiers];
    }

    private function draft(?array $targets = null, ?string $expires = null): int
    {
        $payload = ($targets ?? $this->targets()) + ['title' => 'Distribusi'];
        if ($expires !== null) {
            $payload['expires_at'] = $expires;
        }

        return $this->actingAsAdmin()->postJson($this->base(), $payload)->assertCreated()->json('data.distribution.distribution_id');
    }

    private function publishPayload(int $distributionId, int $fileId): array
    {
        $targets = $this->actingAsAdmin()->getJson("/api/arsip-digital/admin/institutional-distributions/$distributionId/targets")->assertOk()->json('data');

        return ['source_file_id' => $fileId, 'expected_updated_at' => $targets['updated_at'], 'target_fingerprint' => $targets['target_fingerprint']];
    }

    private function students(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $id = 100 + $i;
            $nim = sprintf('22%06d', $i + 2);
            $name = "Student $i";
            DB::table('users')->insert(['id' => $id, 'kd_user' => "MHS-$nim", 'name' => $name, 'is_mhs' => 1]);
            DB::table('vmahasiswa')->insert(['mhs_id' => $id, 'nim' => $nim, 'nm_mhs' => $name, 'angkatan' => '2026', 'prodi' => 'TI', 'sts_mhs' => 'aktif']);
            $rows[] = compact('id', 'nim', 'name');
        }

        return $rows;
    }

    private function adminUser(int $id): UserView
    {
        DB::table('users')->insert(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => 1]);
        $user = new UserView;
        $user->setRawAttributes(['id' => $id, 'kd_user' => 'ADM-B', 'name' => 'Admin B', 'is_admin' => true, 'is_mhs' => false, 'is_dosen' => false, 'is_staff' => false], true);

        return $user;
    }
}
