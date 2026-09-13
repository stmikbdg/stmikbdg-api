<?php

namespace Tests\Feature\ArsipDigital;

class SignatureRequestTest extends ArsipDigitalFeatureTestCase
{
    public function test_signature_request_endpoints_are_disabled(): void
    {
        $this->actingAsMahasiswa()
            ->getJson('/api/arsip-digital/signature-requests')
            ->assertNotFound();

        $this->actingAsMahasiswa()
            ->postJson('/api/arsip-digital/signature-requests', [
                'lecturer_user_id' => 3,
                'title' => 'Tanda tangan',
                'file_ids' => [],
            ])
            ->assertNotFound();

        $this->actingAsDosen()
            ->getJson('/api/arsip-digital/lecturer/signature-request-availability')
            ->assertNotFound();
    }
}
