<?php

namespace Tests\Feature\ArsipDigital;

use Illuminate\Support\Facades\DB;

class AdminTargetTest extends ArsipDigitalFeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('ALTER TABLE vmahasiswa ADD COLUMN masuk_tahun varchar');
        DB::statement('CREATE TABLE vdosen (kd_dosen varchar primary key, nm_dosen varchar, sts_dosen varchar)');
        DB::table('vmahasiswa')->where('nim', '22010001')->update(['masuk_tahun' => '2022', 'sts_mhs' => 'A']);
        DB::table('vdosen')->insert(['kd_dosen' => 'DSN001', 'nm_dosen' => 'Dosen Test', 'sts_dosen' => 'A']);
    }

    public function test_mahasiswa_targets_filter_active_and_inactive_status(): void
    {
        DB::table('vmahasiswa')->insert([
            ['nim' => '22010002', 'nm_mhs' => 'Mahasiswa Inactive', 'masuk_tahun' => '2022', 'sts_mhs' => 'N'],
            ['nim' => '22010003', 'nm_mhs' => 'Mahasiswa Null', 'masuk_tahun' => '2022', 'sts_mhs' => null],
        ]);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status[]=active')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.targets.0.identifier', '22010001');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status[]=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonMissing(['identifier' => '22010001']);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status[]=active&status[]=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status[]=A')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.targets.0.identifier', '22010001');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status[]=N')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.targets.0.identifier', '22010002');
    }

    public function test_dosen_targets_filter_active_and_inactive_status(): void
    {
        DB::table('vdosen')->insert([
            ['kd_dosen' => 'DSN002', 'nm_dosen' => 'Dosen Inactive', 'sts_dosen' => 'N'],
            ['kd_dosen' => 'DSN003', 'nm_dosen' => 'Dosen Null', 'sts_dosen' => null],
        ]);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=dosen&status[]=active')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.targets.0.identifier', 'DSN001');

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=dosen&status[]=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonMissing(['identifier' => 'DSN001']);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=dosen&status[]=active&status[]=inactive')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_status_must_be_an_array(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa&status=active')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_targets_without_status_include_inactive_accounts(): void
    {
        DB::table('vmahasiswa')->where('nim', '22010001')->update(['sts_mhs' => 'N']);
        DB::table('vdosen')->where('kd_dosen', 'DSN001')->update(['sts_dosen' => 'N']);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=mahasiswa')
            ->assertOk()
            ->assertJsonFragment(['identifier' => '22010001', 'has_account' => true]);

        $this->actingAsAdmin()
            ->getJson('/api/arsip-digital/admin/targets?role=dosen')
            ->assertOk()
            ->assertJsonFragment(['identifier' => 'DSN001', 'has_account' => true]);
    }
}
