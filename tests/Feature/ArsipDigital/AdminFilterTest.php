<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class AdminFilterTest extends ArsipDigitalFeatureTestCase
{
    public function test_admin_request_searches_title_and_description_case_insensitively(): void
    {
        DB::table('arsip_digital.requests')->insert([
            [
                'title' => 'Surat KELULUSAN',
                'description' => 'Dokumen akademik',
                'target_role' => 'mahasiswa',
                'scope_type' => 'all',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Dokumen lain',
                'description' => 'Transkrip NILAI akhir',
                'target_role' => 'mahasiswa',
                'scope_type' => 'all',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title' => 'Tidak cocok',
                'description' => 'Arsip umum',
                'target_role' => 'mahasiswa',
                'scope_type' => 'all',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests?search=kelulusan')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.title', 'Surat KELULUSAN');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/requests?search=nilai')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.title', 'Dokumen lain');
    }

    public function test_admin_audit_searches_supported_fields(): void
    {
        $this->insertAuditLog('request.published', 'Publikasi selesai', 'request', 'REQ-917', '2026-07-10 09:00:00');
        $this->insertAuditLog('file.deleted', 'Arsip dihapus', 'archive_file', '42', '2026-07-10 10:00:00');

        foreach (['PUBLISHED', 'publikasi', 'ARCHIVE_FILE', '917'] as $search) {
            $this->actingAsAdmin()
                ->getJson('/api/arsip-digital/admin/audit-logs?search='.$search)
                ->assertOk()
                ->assertJsonCount(1, 'data.audit_logs')
                ->assertJsonPath('data.audit_logs.0.entity_id', $search === '917' ? 'REQ-917' : ($search === 'ARCHIVE_FILE' ? '42' : 'REQ-917'));
        }
    }

    public function test_admin_audit_date_to_includes_entire_calendar_day(): void
    {
        $this->insertAuditLog('first', 'First', 'request', '1', '2026-07-10 23:59:59');
        $this->insertAuditLog('second', 'Second', 'request', '2', '2026-07-11 00:00:00');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/audit-logs?date_from=2026-07-10&date_to=2026-07-10&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.audit_logs')
            ->assertJsonPath('data.audit_logs.0.action', 'first')
            ->assertJsonPath('data.meta.per_page', 1)
            ->assertJsonPath('data.meta.total', 1);
    }

    private function insertAuditLog(string $action, string $description, string $entityType, string $entityId, string $createdAt): void
    {
        DB::table('arsip_digital.audit_logs')->insert([
            'action' => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => $createdAt,
        ]);
    }
}
