<?php

namespace Tests\Unit\ArsipDigital;

use App\Models\ArsipDigital\ArchiveFile;
use App\Models\ArsipDigital\Category;
use App\Services\ArsipDigital\ArchivePermissionService;
use Tests\TestCase;

class ArchivePermissionServiceTest extends TestCase
{
    public function test_owner_can_manage_personal_category(): void
    {
        $service = new ArchivePermissionService();
        $category = new Category();
        $category->setRawAttributes([
            'category_type' => 'personal',
            'owner_user_id' => 10,
            'owner_role' => 'mahasiswa',
        ], true);

        $this->assertTrue($service->canManageCategory($category, (object) ['id' => 10], 'mahasiswa'));
        $this->assertFalse($service->canManageCategory($category, (object) ['id' => 11], 'mahasiswa'));
    }

    public function test_admin_can_manage_official_category(): void
    {
        $service = new ArchivePermissionService();
        $category = new Category();
        $category->setRawAttributes([
            'category_type' => 'official',
            'owner_user_id' => null,
            'owner_role' => null,
            'created_by_user_id' => 99,
        ], true);

        $this->assertTrue($service->canManageCategory($category, (object) ['id' => 1], 'admin'));
        $this->assertFalse($service->canManageCategory($category, (object) ['id' => 1], 'dosen'));
    }

    public function test_owner_and_admin_can_download_file(): void
    {
        $service = new ArchivePermissionService();
        $file = new ArchiveFile();
        $file->setRawAttributes([
            'owner_user_id' => 10,
            'owner_role' => 'dosen',
            'source_type' => 'personal',
        ], true);

        $this->assertTrue($service->canDownloadFile($file, (object) ['id' => 10], 'dosen'));
        $this->assertTrue($service->canDownloadFile($file, (object) ['id' => 1], 'admin'));
        $this->assertFalse($service->canDownloadFile($file, (object) ['id' => 11], 'dosen'));
        $this->assertFalse($service->canDownloadFile($file, (object) ['id' => 10], 'mahasiswa'));

        $file->source_type = 'distribution';

        $this->assertFalse($service->canDownloadFile($file, (object) ['id' => 10], 'dosen'));
        $this->assertTrue($service->canDownloadFile($file, (object) ['id' => 1], 'admin'));
    }
}
