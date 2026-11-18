<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Services\AttendanceStatusService;
use Carbon\Carbon;
use Tests\TestCase;

class AttendanceDayPunchPairTest extends TestCase
{
    public function test_ignores_orphan_out_before_first_clock_in(): void
    {
        $tz = 'Asia/Manila';
        [$in, $out] = AttendanceStatusService::resolveDayPunchPair([
            ['type' => AttendanceLog::TYPE_CLOCK_OUT, 'verified_at' => '2026-08-05 01:10:00'],
            ['type' => AttendanceLog::TYPE_CLOCK_IN, 'verified_at' => '2026-08-05 08:05:00'],
            ['type' => AttendanceLog::TYPE_CLOCK_OUT, 'verified_at' => '2026-08-05 17:02:00'],
        ], $tz);

        $this->assertSame('08:05', $in?->timezone($tz)->format('H:i'));
        $this->assertSame('17:02', $out?->timezone($tz)->format('H:i'));
    }

    public function test_keeps_orphan_out_when_no_clock_in(): void
    {
        $tz = 'Asia/Manila';
        [$in, $out] = AttendanceStatusService::resolveDayPunchPair([
            ['type' => AttendanceLog::TYPE_CLOCK_OUT, 'verified_at' => '2026-08-05 01:10:00'],
        ], $tz);

        $this->assertNull($in);
        $this->assertSame('01:10', $out?->timezone($tz)->format('H:i'));
    }

    public function test_next_day_out_after_morning_in_remains_valid_session(): void
    {
        $tz = 'Asia/Manila';
        $ok = AttendanceStatusService::punchesFormValidShiftSession(
            '2026-08-04',
            ['in' => '08:00', 'out' => '17:00'],
            Carbon::parse('2026-08-04 08:00:00', $tz),
            Carbon::parse('2026-08-05 01:10:00', $tz),
            $tz,
        );

        $this->assertTrue($ok);
    }
}
