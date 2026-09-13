<?php

namespace Tests\Feature\ArsipDigital;

class PdfSelfSignTest extends ArsipDigitalFeatureTestCase
{
    public function test_pdf_self_sign_endpoints_are_disabled(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/arsip-digital/pdf-sign-sessions')
            ->assertNotFound();

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/pdf-sign-sessions', ['file_id' => 1])
            ->assertNotFound();
    }
}
