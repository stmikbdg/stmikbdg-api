<?php

namespace App\Services\ArsipDigital;

class OfficialDocumentTokenService
{
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
