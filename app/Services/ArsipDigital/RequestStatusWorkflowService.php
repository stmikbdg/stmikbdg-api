<?php

namespace App\Services\ArsipDigital;

use App\Models\ArsipDigital\ArchiveRequest;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RequestStatusWorkflowService
{
    public function submissionStatus(ArchiveRequest $request): string
    {
        return $request->requires_verification ? 'waiting_verification' : 'approved';
    }

    public function isLate(ArchiveRequest $request): bool
    {
        return $request->deadline_at !== null && Carbon::now()->greaterThan(Carbon::parse($request->deadline_at));
    }

    public function assertUserSubmissionAllowed(ArchiveRequest $request): void
    {
        if ($request->status !== 'published') {
            throw new HttpException(422, 'Request belum dipublish atau sudah tidak aktif.');
        }

        if ($this->isLate($request) && $request->close_after_deadline) {
            throw new HttpException(422, 'Request sudah melewati deadline dan tidak menerima submission baru.');
        }
    }
}
