<?php

namespace Tests\Unit;

use App\Services\AttendanceStatusService;
use PHPUnit\Framework\TestCase;

class AttendanceStatusLateBucketTest extends TestCase
{
    public function test_exactly_thirty_minutes_late_buckets_as_thirty_not_sixty(): void
    {
        $bucket = AttendanceStatusService::getLateBucket(30);

        $this->assertSame(30, $bucket['minutes']);
        $this->assertSame('30 Minutes late', $bucket['label']);
    }

    public function test_thirty_one_minutes_late_buckets_as_one_hour(): void
    {
        $bucket = AttendanceStatusService::getLateBucket(31);

        $this->assertSame(60, $bucket['minutes']);
        $this->assertSame('1 Hour Late', $bucket['label']);
    }

    public function test_one_to_thirty_minutes_share_thirty_minute_bucket(): void
    {
        $this->assertSame(30, AttendanceStatusService::getLateBucket(1)['minutes']);
        $this->assertSame(30, AttendanceStatusService::getLateBucket(29)['minutes']);
        $this->assertSame(30, AttendanceStatusService::getLateBucket(30)['minutes']);
    }
}
