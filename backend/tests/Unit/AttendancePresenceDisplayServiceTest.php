<?php

namespace Tests\Unit;

use App\Services\AttendancePresenceDisplayService;
use Carbon\Carbon;
use Tests\TestCase;

class AttendancePresenceDisplayServiceTest extends TestCase
{
    public function test_incomplete_pair_remains_auditable_when_displayed_as_present(): void
    {
        $service = app(AttendancePresenceDisplayService::class);
        $now = Carbon::parse('2026-07-13 18:00:00', 'Asia/Manila');

        $result = $service->qualify(
            '2026-07-13',
            '2026-07-13',
            $now,
            ['in' => '08:00:00', 'out' => '17:00:00'],
            'incomplete',
            $now->copy()->setTime(8, 1),
            null,
            null,
            false,
        );

        $this->assertSame('present', $result['status']);
        $this->assertSame('incomplete_pair', $result['presence_issue']);
        $this->assertSame('Present (Incomplete Records)', $result['presence_label']);
    }

    public function test_day_shift_out_before_in_is_invalid_records_not_present(): void
    {
        $service = app(AttendancePresenceDisplayService::class);
        $now = Carbon::parse('2026-08-04 18:00:00', 'Asia/Manila');
        $timeIn = Carbon::parse('2026-08-04 08:44:00', 'Asia/Manila');
        $timeOut = Carbon::parse('2026-08-04 08:43:00', 'Asia/Manila');

        $result = $service->qualify(
            '2026-08-04',
            '2026-08-04',
            $now,
            ['in' => '08:00', 'out' => '17:00'],
            'late',
            $timeIn,
            $timeOut,
            null,
            false,
        );

        $this->assertSame('invalid', $result['status']);
        $this->assertSame('invalid_pair', $result['presence_issue']);
        $this->assertSame('Invalid Shift', $result['presence_label']);
    }

    public function test_overnight_shift_out_next_day_is_valid(): void
    {
        $service = app(AttendancePresenceDisplayService::class);
        $now = Carbon::parse('2026-08-05 08:00:00', 'Asia/Manila');
        $timeIn = Carbon::parse('2026-08-04 22:10:00', 'Asia/Manila');
        $timeOut = Carbon::parse('2026-08-05 06:05:00', 'Asia/Manila');

        $result = $service->qualify(
            '2026-08-04',
            '2026-08-05',
            $now,
            ['in' => '22:00', 'out' => '06:00'],
            'present',
            $timeIn,
            $timeOut,
            null,
            false,
        );

        $this->assertSame('present', $result['status']);
        $this->assertSame('none', $result['presence_issue']);
        $this->assertNull($result['presence_label']);
    }

    public function test_overnight_same_calendar_out_wraps_to_next_day(): void
    {
        $service = app(AttendancePresenceDisplayService::class);
        $now = Carbon::parse('2026-08-05 08:00:00', 'Asia/Manila');
        $timeIn = Carbon::parse('2026-08-04 22:10:00', 'Asia/Manila');
        $timeOut = Carbon::parse('2026-08-04 06:05:00', 'Asia/Manila');

        $result = $service->qualify(
            '2026-08-04',
            '2026-08-05',
            $now,
            ['in' => '22:00', 'out' => '06:00'],
            'present',
            $timeIn,
            $timeOut,
            null,
            false,
        );

        $this->assertSame('present', $result['status']);
        $this->assertSame('none', $result['presence_issue']);
    }
}
