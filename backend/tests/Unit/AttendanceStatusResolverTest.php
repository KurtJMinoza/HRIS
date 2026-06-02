<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Services\AttendancePresenceDisplayService;
use App\Services\AttendanceStatusResolver;
use Carbon\Carbon;
use Tests\TestCase;

class AttendanceStatusResolverTest extends TestCase
{
    private AttendanceStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'attendance.timezone' => 'Asia/Manila',
            'attendance.half_day_start_hour' => 12,
            'attendance.half_day_end_hour' => 13,
            'attendance.overtime_buffer_minutes' => 15,
        ]);
        $this->resolver = new AttendanceStatusResolver(new AttendancePresenceDisplayService);
    }

    public function test_post_shift_overtime_is_present_with_ot_not_undertime(): void
    {
        $tz = config('attendance.timezone', 'Asia/Manila');
        $dateKey = '2026-06-01';
        $nowTz = Carbon::parse('2026-06-01 21:00:00', $tz);

        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_minutes' => 60,
            'grace_minutes' => 5,
        ];

        $dayLogs = [
            ['type' => AttendanceLog::TYPE_CLOCK_IN, 'verified_at' => '2026-06-01 07:06:00'],
            ['type' => AttendanceLog::TYPE_CLOCK_OUT, 'verified_at' => '2026-06-01 20:10:00'],
        ];

        $result = $this->resolver->resolve(
            dateKey: $dateKey,
            todayDate: $dateKey,
            nowTz: $nowTz,
            effectiveSchedule: ['mon' => $daySchedule],
            daySchedule: $daySchedule,
            dayLogs: $dayLogs,
            correction: null,
            holiday: null,
            leave: null,
            isRestDay: false,
            isFuture: false,
        );

        $this->assertSame(AttendanceStatusResolver::STATUS_PRESENT_WITH_OT, $result['status']);
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertGreaterThan(0, $result['overtime_minutes']);
    }

    public function test_early_clock_out_is_undertime(): void
    {
        $tz = config('attendance.timezone', 'Asia/Manila');
        $dateKey = '2026-06-02';
        $nowTz = Carbon::parse('2026-06-02 18:00:00', $tz);

        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_minutes' => 60,
            'grace_minutes' => 5,
        ];

        $dayLogs = [
            ['type' => AttendanceLog::TYPE_CLOCK_IN, 'verified_at' => '2026-06-02 08:00:00'],
            ['type' => AttendanceLog::TYPE_CLOCK_OUT, 'verified_at' => '2026-06-02 16:30:00'],
        ];

        $result = $this->resolver->resolve(
            dateKey: $dateKey,
            todayDate: $dateKey,
            nowTz: $nowTz,
            effectiveSchedule: ['tue' => $daySchedule],
            daySchedule: $daySchedule,
            dayLogs: $dayLogs,
            correction: null,
            holiday: null,
            leave: null,
            isRestDay: false,
            isFuture: false,
        );

        $this->assertSame(AttendanceStatusResolver::STATUS_UNDERTIME, $result['status']);
        $this->assertGreaterThan(0, $result['undertime_minutes']);
        $this->assertSame(0, $result['overtime_minutes']);
    }

}
