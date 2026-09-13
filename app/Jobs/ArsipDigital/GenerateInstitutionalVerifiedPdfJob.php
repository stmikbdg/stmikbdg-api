<?php

namespace App\Jobs\ArsipDigital;

use App\Services\ArsipDigital\InstitutionalArchiveVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

class GenerateInstitutionalVerifiedPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public readonly string $encryptedToken;

    public function __construct(public readonly int $verificationId, string $token)
    {
        $this->encryptedToken = Crypt::encryptString($token);
    }

    public function handle(InstitutionalArchiveVerificationService $service): void
    {
        $service->process($this->verificationId, Crypt::decryptString($this->encryptedToken));
    }
}
