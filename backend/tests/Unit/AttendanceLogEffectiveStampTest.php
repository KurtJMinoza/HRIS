<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use Carbon\Carbon;
use Tests\TestCase;

class AttendanceLogEffectiveStampTest extends TestCase
{
    public function test_punch_instant_prefers_verified_at(): void
    {
        $log = new AttendanceLog([
            'verified_at' => Carbon::parse('2026-06-12 08:00:00', 'Asia/Manila')->utc(),
            'created_at' => Carbon::parse('2026-06-12 09:00:00', 'Asia/Manila')->utc(),
        ]);

        $this->assertSame(
            '2026-06-12 00:00:00',
            AttendanceLog::punchInstant($log)?->utc()->toDateTimeString()
        );
    }

    public function test_punch_instant_falls_back_to_created_at(): void
    {
        $log = new AttendanceLog;
        $log->forceFill([
            'verified_at' => null,
            'created_at' => Carbon::parse('2026-06-12 08:15:00', 'Asia/Manila')->utc(),
        ]);

        $this->assertNotNull(AttendanceLog::punchInstant($log));
    }
}
