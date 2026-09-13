<?php

namespace Tests\Unit\ArsipDigital;

use App\Models\ArsipDigital\ArchiveRequest;
use App\Services\ArsipDigital\RequestStatusWorkflowService;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RequestStatusWorkflowServiceTest extends TestCase
{
    public function test_submission_status_follows_requires_verification(): void
    {
        $service = new RequestStatusWorkflowService();

        $withVerification = new ArchiveRequest();
        $withVerification->setRawAttributes(['requires_verification' => true], true);

        $withoutVerification = new ArchiveRequest();
        $withoutVerification->setRawAttributes(['requires_verification' => false], true);

        $this->assertSame('waiting_verification', $service->submissionStatus($withVerification));
        $this->assertSame('approved', $service->submissionStatus($withoutVerification));
    }

    public function test_submission_after_closed_deadline_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        $request = new ArchiveRequest();
        $request->setRawAttributes([
            'status' => 'published',
            'deadline_at' => Carbon::now()->subDay(),
            'close_after_deadline' => true,
        ], true);

        (new RequestStatusWorkflowService())->assertUserSubmissionAllowed($request);
    }

    public function test_submission_after_open_deadline_is_late_but_allowed(): void
    {
        $request = new ArchiveRequest();
        $request->setRawAttributes([
            'status' => 'published',
            'deadline_at' => Carbon::now()->subDay(),
            'close_after_deadline' => false,
        ], true);

        $service = new RequestStatusWorkflowService();
        $service->assertUserSubmissionAllowed($request);

        $this->assertTrue($service->isLate($request));
    }
}
