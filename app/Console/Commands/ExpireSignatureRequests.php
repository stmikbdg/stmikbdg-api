<?php

namespace App\Console\Commands;

use App\Services\ArsipDigital\SignatureRequestService;
use Illuminate\Console\Command;

class ExpireSignatureRequests extends Command
{
    protected $signature = 'arsip-digital:expire-signature-requests';

    protected $description = 'Expire requested lecturer signature requests';

    public function handle(SignatureRequestService $service): int
    {
        $this->info((string) $service->expire());

        return self::SUCCESS;
    }
}
