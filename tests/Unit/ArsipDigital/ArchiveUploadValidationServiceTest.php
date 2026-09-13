<?php

namespace Tests\Unit\ArsipDigital;

use App\Services\ArsipDigital\ArchiveUploadValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArchiveUploadValidationServiceTest extends TestCase
{
    public function test_it_accepts_matching_extension_and_mime_type(): void
    {
        $file = UploadedFile::fake()->create('akta.pdf', 8, 'application/pdf');

        (new ArchiveUploadValidationService())->validateUploadedFile($file, 10, ['pdf']);

        $this->assertTrue(true);
    }

    public function test_it_rejects_mime_type_that_does_not_match_extension(): void
    {
        $this->expectException(ValidationException::class);

        $file = UploadedFile::fake()->create('akta.pdf', 8, 'image/png');

        (new ArchiveUploadValidationService())->validateUploadedFile($file, 10, ['pdf']);
    }

    public function test_it_rejects_reused_file_metadata_with_wrong_mime_type(): void
    {
        $this->expectException(ValidationException::class);

        (new ArchiveUploadValidationService())->validateMetadataFile('pdf', 1024, 10, ['pdf'], 'image/png');
    }
}
