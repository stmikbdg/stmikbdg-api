<?php

namespace Tests\Unit\ArsipDigital;

use App\Services\ArsipDigital\ArsipDigitalSettingsService;
use App\Services\ArsipDigital\ArsipDigitalStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArsipDigitalStorageServiceTest extends TestCase
{
    public function test_it_uploads_private_file_to_configured_disk(): void
    {
        $disk = $this->fakeDisk();

        $service = new ArsipDigitalStorageService($this->settingsService($disk));
        $file = UploadedFile::fake()->create('Akta Kelahiran.PDF', 2, 'application/pdf');

        $metadata = $service->uploadPrivate($file, 'personal', ['owner_user_id' => 10]);

        $this->assertSame($disk, $metadata['storage_disk']);
        $this->assertSame('akta-kelahiran.pdf', $metadata['display_filename']);
        $this->assertSame('pdf', $metadata['extension']);
        $this->assertNotEmpty($metadata['checksum_sha256']);
        Storage::disk($disk)->assertExists($metadata['storage_path']);
    }

    public function test_it_throws_when_private_upload_storage_write_fails(): void
    {
        $disk = 'arsip_digital_test_failed_put';
        $file = UploadedFile::fake()->create('Gagal.pdf', 2, 'application/pdf');
        $adapter = new class
        {
            public function put(): bool
            {
                return false;
            }
        };

        Storage::shouldReceive('disk')->once()->with($disk)->andReturn($adapter);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Gagal menyimpan file arsip digital ke storage.');

        (new ArsipDigitalStorageService($this->settingsService($disk)))->uploadPrivate($file, 'personal');
    }

    public function test_it_streams_private_download(): void
    {
        $disk = $this->fakeDisk();
        Storage::disk($disk)->put('arsip-digital/testing/private.txt', 'arsip-ok');

        $service = new ArsipDigitalStorageService($this->settingsService($disk));
        $response = $service->downloadPrivate($disk, 'arsip-digital/testing/private.txt', 'private.txt');

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('arsip-ok', $content);
    }

    private function fakeDisk(): string
    {
        $disk = 'arsip_digital_test_'.str_replace('\\', '_', static::class).'_'.$this->name();

        Storage::fake($disk);

        return $disk;
    }

    private function settingsService(string $disk): ArsipDigitalSettingsService
    {
        return new class($disk) extends ArsipDigitalSettingsService
        {
            public function __construct(private readonly string $disk) {}

            public function getDefaults(): array
            {
                return [
                    'default_max_file_size_mb' => 10,
                    'default_allowed_extensions' => ['pdf'],
                    'storage_disk' => $this->disk,
                ];
            }
        };
    }
}
