<?php

namespace Tests\Unit;

use App\Jobs\AttendanceCorrectionBulkFollowUpJob;
use PHPUnit\Framework\TestCase;

class AttendanceCorrectionBulkFollowUpJobTest extends TestCase
{
    public function test_job_uses_dedicated_redis_queue(): void
    {
        $job = new AttendanceCorrectionBulkFollowUpJob([1, 2], 9);

        $this->assertSame('redis', $job->connection);
        $this->assertSame('attendance-corrections', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(120, $job->timeout);
    }
}
