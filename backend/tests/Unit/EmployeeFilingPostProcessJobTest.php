<?php

namespace Tests\Unit;

use App\Jobs\EmployeeFilingPostProcessJob;
use App\Services\OrgApprovalWorkflowService;
use PHPUnit\Framework\TestCase;

class EmployeeFilingPostProcessJobTest extends TestCase
{
    public function test_job_uses_dedicated_redis_queue(): void
    {
        $job = new EmployeeFilingPostProcessJob(
            OrgApprovalWorkflowService::MODULE_LEAVE,
            45,
            9,
        );

        $this->assertSame('redis', $job->connection);
        $this->assertSame('employee-filing-post-process', $job->queue);
        $this->assertSame(2, $job->tries);
        $this->assertSame(60, $job->timeout);
    }
}
