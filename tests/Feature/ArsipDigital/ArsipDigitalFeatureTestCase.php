<?php

namespace Tests\Feature\ArsipDigital;

use App\Http\Middleware\JwtMiddleware;
use App\Models\Users\UserView;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ArsipDigitalFeatureTestCase extends TestCase
{
    protected UserView $admin;

    protected UserView $mahasiswa;

    protected UserView $dosen;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'myconfig.database.first_connection' => 'sqlite',
            'myconfig.database.second_connection' => 'sqlite',
            'filesystems.disks.s3' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/arsip-s3'),
                'throw' => false,
            ],
            'queue.default' => 'sync',
        ]);

        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();
        DB::statement("ATTACH DATABASE ':memory:' AS arsip_digital");

        $this->createCampusTables();
        $this->createArsipTables();
        $this->seedUsers();
        Storage::fake('s3');

        $this->withoutMiddleware(JwtMiddleware::class);
    }

    protected function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'api')->withHeader('X-Active-Role', 'admin');
    }

    protected function actingAsMahasiswa(): static
    {
        return $this->actingAs($this->mahasiswa, 'api')->withHeader('X-Active-Role', 'mahasiswa');
    }

    protected function actingAsDosen(): static
    {
        return $this->actingAs($this->dosen, 'api')->withHeader('X-Active-Role', 'dosen');
    }

    protected function pdfUpload(string $name = 'dokumen.pdf', string $content = '%PDF-1.4 test'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    protected function createPublishedRequestForMahasiswa(bool $requiresVerification = true): array
    {
        $requestId = DB::table('arsip_digital.requests')->insertGetId([
            'title' => 'Upload Akta',
            'target_role' => 'mahasiswa',
            'scope_type' => 'specific',
            'target_identifiers' => json_encode(['22010001']),
            'max_files' => 3,
            'allowed_extensions' => json_encode(['pdf']),
            'requires_verification' => $requiresVerification,
            'allow_file_reuse' => true,
            'close_after_deadline' => false,
            'status' => 'published',
            'created_by_user_id' => 1,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = DB::table('arsip_digital.request_assignments')->insertGetId([
            'request_id' => $requestId,
            'target_user_id' => 2,
            'target_role' => 'mahasiswa',
            'identifier' => '22010001',
            'name_snapshot' => 'Mahasiswa Test',
            'status' => 'not_submitted',
            'is_late' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$requestId, $assignmentId];
    }

    protected function createActiveArchiveFileForMahasiswa(string $filename = 'akta.pdf'): int
    {
        Storage::disk('s3')->put('arsip-digital/testing/source/'.$filename, 'file lama');

        return DB::table('arsip_digital.files')->insertGetId([
            'owner_user_id' => 2,
            'owner_role' => 'mahasiswa',
            'owner_identifier' => '22010001',
            'owner_name_snapshot' => 'Mahasiswa Test',
            'uploaded_by_user_id' => 2,
            'uploaded_by_role' => 'mahasiswa',
            'source_type' => 'personal',
            'original_filename' => $filename,
            'display_filename' => $filename,
            'storage_disk' => 's3',
            'storage_path' => 'arsip-digital/testing/source/'.$filename,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size_bytes' => 9,
            'checksum_sha256' => hash('sha256', 'file lama'),
            'version_group_uuid' => (string) Str::uuid(),
            'version_number' => 1,
            'is_current' => true,
            'status' => 'active',
            'storage_availability' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCampusTables(): void
    {
        DB::statement('CREATE TABLE users (id integer primary key autoincrement, kd_user varchar, name varchar, is_admin integer default 0, is_mhs integer default 0, is_dosen integer default 0, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE vusers (id integer primary key, kd_user varchar, name varchar, is_admin integer, is_mhs integer, is_dosen integer, is_staff integer, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE vmahasiswa (nim varchar primary key, nm_mhs varchar, angkatan varchar, prodi varchar, sts_mhs varchar)');
        DB::statement('CREATE TABLE dosen (kd_dosen varchar primary key, nm_dosen varchar, prodi varchar, status varchar)');
        DB::statement('CREATE TABLE admins (kd_admin varchar primary key, nm_admin varchar)');
    }

    private function createArsipTables(): void
    {
        DB::statement('CREATE TABLE arsip_digital.settings (setting_id integer primary key autoincrement, key varchar unique, value text, description text, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.categories (category_id integer primary key autoincrement, owner_user_id integer, owner_role varchar, category_type varchar, name varchar, description text, visibility varchar, parent_category_id integer, created_by_user_id integer, created_by_role varchar, is_system integer default 0, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.files (file_id integer primary key autoincrement, category_id integer, owner_user_id integer not null, owner_role varchar not null, owner_identifier varchar not null, owner_name_snapshot varchar, owner_status_snapshot varchar, uploaded_by_user_id integer not null, uploaded_by_role varchar not null, source_type varchar not null, original_filename varchar not null, display_filename varchar not null, storage_disk varchar not null, storage_path text not null, mime_type varchar, extension varchar not null, file_size_bytes integer not null, checksum_sha256 varchar, version_group_uuid varchar not null, version_number integer not null default 1, is_current integer not null default 1, status varchar not null default "active", storage_availability varchar not null default "unknown", metadata text, created_at datetime, updated_at datetime, deleted_at datetime, deleted_by_user_id integer, deleted_by_role varchar, delete_source varchar, delete_reason text)');
        DB::statement('CREATE TABLE arsip_digital.scholarship_types (scholarship_type_id integer primary key autoincrement, code varchar, name varchar, description text, is_active integer default 1, source varchar default "manual", created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.student_scholarships (student_scholarship_id integer primary key autoincrement, student_user_id integer, nim varchar, student_name_snapshot varchar, angkatan_snapshot varchar, scholarship_type_id integer, status varchar default "active", period_label varchar, start_date date, end_date date, source varchar default "manual", metadata text, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.segments (segment_id integer primary key autoincrement, name varchar, description text, target_role varchar, source varchar default "manual", is_active integer default 1, created_by_user_id integer, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.segment_members (segment_member_id integer primary key autoincrement, segment_id integer, target_user_id integer, target_role varchar, identifier varchar, name_snapshot varchar, angkatan_snapshot varchar, prodi_snapshot varchar, status_snapshot varchar, metadata text, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.requests (request_id integer primary key autoincrement, title varchar, description text, target_role varchar, scope_type varchar, target_filters text, target_identifiers text, target_segment_ids text, max_files integer default 1, max_file_size_mb integer, allowed_extensions text, requires_verification integer default 1, allow_file_reuse integer default 1, allow_inactive_upload integer default 0, deadline_at datetime, close_after_deadline integer default 0, status varchar default "draft", created_by_user_id integer, published_at datetime, closed_at datetime, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.request_assignments (assignment_id integer primary key autoincrement, request_id integer, target_user_id integer, target_role varchar, identifier varchar, name_snapshot varchar, angkatan_snapshot varchar, prodi_snapshot varchar, status_snapshot varchar, scholarship_snapshot text, metadata text, status varchar default "not_submitted", is_late integer default 0, submitted_at datetime, verified_at datetime, verified_by_user_id integer, reject_reason text, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.request_files (request_file_id integer primary key autoincrement, request_id integer, assignment_id integer, file_id integer, submission_type varchar, status varchar default "waiting_verification", is_late integer default 0, is_current integer default 1, note text, created_by_user_id integer, created_by_role varchar, reviewed_by_user_id integer, reviewed_at datetime, reject_reason text, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.distributions (distribution_id integer primary key autoincrement, original_distribution_id integer references distributions(distribution_id), title varchar, description text, target_role varchar, scope_type varchar, target_filters text, target_identifiers text, target_segment_ids text, status varchar default "draft", created_by_user_id integer, published_at datetime, withdrawn_at datetime, withdrawn_by_user_id integer, withdrawal_reason text, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.distribution_recipients (recipient_id integer primary key autoincrement, distribution_id integer, target_user_id integer, target_role varchar, identifier varchar, name_snapshot varchar, angkatan_snapshot varchar, prodi_snapshot varchar, status_snapshot varchar, metadata text, file_id integer, delivery_status varchar default "pending", download_count integer not null default 0, first_downloaded_at datetime, last_downloaded_at datetime, created_at datetime, updated_at datetime, deleted_at datetime)');
        DB::statement('CREATE INDEX arsip_digital.distribution_recipients_distribution_identifier_idx ON distribution_recipients (distribution_id, identifier)');
        DB::statement('CREATE INDEX arsip_digital.distribution_recipients_distribution_status_idx ON distribution_recipients (distribution_id, delivery_status)');
        DB::statement('CREATE INDEX arsip_digital.distributions_created_order_idx ON distributions (created_at DESC, distribution_id DESC)');
        DB::statement('CREATE TABLE arsip_digital.distribution_bulk_upload_jobs (bulk_upload_job_id integer primary key autoincrement, distribution_id integer, uploaded_by_user_id integer, status varchar default "uploaded", original_filename varchar, storage_disk varchar, storage_path text, file_size_bytes integer, summary text, error_message text, expires_at datetime, processed_at datetime, confirmed_at datetime, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.distribution_bulk_upload_entries (bulk_upload_entry_id integer primary key autoincrement, bulk_upload_job_id integer, recipient_id integer, identifier varchar, entry_path text, original_filename varchar, display_filename varchar, temporary_disk varchar, temporary_path text, mime_type varchar, extension varchar, file_size_bytes integer, checksum_sha256 varchar, match_status varchar, match_reason text, metadata text, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.export_jobs (export_job_id integer primary key autoincrement, requested_by_user_id integer, export_type varchar, filters text, status varchar default "queued", storage_disk varchar, storage_path text, file_size_bytes integer, error_message text, expires_at datetime, created_at datetime, updated_at datetime, completed_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.audit_logs (audit_log_id integer primary key autoincrement, actor_user_id integer, actor_role varchar, action varchar, entity_type varchar, entity_id varchar, description text, ip_address varchar, user_agent text, metadata text, created_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.pdf_sign_sessions (sign_session_id varchar primary key, owner_user_id integer not null, owner_role varchar not null, source_file_id integer, signature_request_file_id integer, source_path text not null, result_path text, source_sha256 varchar not null, result_sha256 varchar, original_filename varchar not null, status varchar not null default "created", storage_disk varchar not null default "local", error_message varchar, started_at datetime, finished_at datetime, expires_at datetime not null, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.dosen_signature_availability (user_id integer primary key, is_available integer not null default 0, created_at datetime, updated_at datetime)');
        DB::statement('CREATE TABLE arsip_digital.signature_requests (signature_request_id integer primary key autoincrement, student_user_id integer not null, lecturer_user_id integer not null, student_name varchar, lecturer_name varchar, title varchar not null, description text, status varchar not null default "requested", rejection_reason text, expires_at datetime not null, finished_at datetime, created_at datetime, updated_at datetime)');
        DB::statement('CREATE UNIQUE INDEX arsip_digital.signature_requests_active_pair_idx ON signature_requests (student_user_id, lecturer_user_id) WHERE status IN ("requested", "draft")');
        DB::statement('CREATE TABLE arsip_digital.signature_request_files (signature_request_file_id integer primary key autoincrement, signature_request_id integer not null, source_file_id integer not null, source_sha256 varchar not null, source_filename varchar not null, source_size_bytes integer not null, sign_session_id varchar, signed_result_disk varchar, signed_result_path text, result_sha256 varchar, result_file_id integer, signed_at datetime, created_at datetime, updated_at datetime, unique(signature_request_id, source_file_id))');
        DB::statement('CREATE TABLE arsip_digital.notifications (notification_id integer primary key autoincrement, recipient_user_id integer not null, recipient_role varchar not null, type varchar not null, title varchar not null, message text, entity_type varchar, entity_id integer, data text, read_at datetime, created_at datetime, updated_at datetime)');

        DB::table('arsip_digital.settings')->insert([
            'key' => 'archive_defaults',
            'value' => json_encode([
                'default_max_file_size_mb' => 10,
                'default_allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'],
                'storage_disk' => 's3',
            ]),
            'description' => 'test defaults',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUsers(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'kd_user' => 'ADM-ADM001', 'name' => 'Admin Test', 'is_admin' => 1, 'is_mhs' => 0, 'is_dosen' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'kd_user' => 'MHS-22010001', 'name' => 'Mahasiswa Test', 'is_admin' => 0, 'is_mhs' => 1, 'is_dosen' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'kd_user' => 'DSN-DSN001', 'name' => 'Dosen Test', 'is_admin' => 0, 'is_mhs' => 0, 'is_dosen' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vmahasiswa')->insert(['nim' => '22010001', 'nm_mhs' => 'Mahasiswa Test', 'angkatan' => '2022', 'prodi' => 'TI', 'sts_mhs' => 'aktif']);
        DB::table('dosen')->insert(['kd_dosen' => 'DSN001', 'nm_dosen' => 'Dosen Test', 'prodi' => 'TI', 'status' => 'aktif']);
        DB::table('admins')->insert(['kd_admin' => 'ADM001', 'nm_admin' => 'Admin Test']);

        $this->admin = $this->userView(1, 'ADM-ADM001', 'Admin Test', true, false, false);
        $this->mahasiswa = $this->userView(2, 'MHS-22010001', 'Mahasiswa Test', false, true, false);
        $this->dosen = $this->userView(3, 'DSN-DSN001', 'Dosen Test', false, false, true);
    }

    private function userView(int $id, string $kdUser, string $name, bool $admin, bool $mhs, bool $dosen): UserView
    {
        $user = new UserView;
        $user->setRawAttributes([
            'id' => $id,
            'kd_user' => $kdUser,
            'name' => $name,
            'is_admin' => $admin,
            'is_mhs' => $mhs,
            'is_dosen' => $dosen,
            'is_staff' => false,
        ], true);

        return $user;
    }
}
