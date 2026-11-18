<?php

namespace Tests\Unit;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveScheduleSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveScheduleSupportRestDayExcludeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_range_is_valid_and_marks_rest_days_excluded(): void
    {
        $user = new User;
        $user->id = 1;
        $user->schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00'],
        ];

        // Fri Nov 20 – Sun Nov 22 2026 (Sun is rest)
        $summary = LeaveScheduleSupport::summarizeRangeForUser($user, '2026-11-20', '2026-11-22');

        $this->assertTrue($summary['valid']);
        $this->assertTrue($summary['includes_rest_days']);
        $this->assertSame(2, $summary['working_days_in_range']);
        $this->assertSame('2026-11-22', $summary['rest_day_hits'][0]['date'] ?? null);

        LeaveScheduleSupport::assertRangeHasWorkingDay($user, '2026-11-20', '2026-11-22');
        $this->assertTrue(true);
    }

    public function test_rest_only_range_is_blocked(): void
    {
        $user = new User;
        $user->id = 2;
        $user->schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00'],
        ];

        $summary = LeaveScheduleSupport::summarizeRangeForUser($user, '2026-11-22', '2026-11-22');
        $this->assertFalse($summary['valid']);
        $this->assertSame(0, $summary['working_days_in_range']);

        $this->expectException(ValidationException::class);
        LeaveScheduleSupport::assertRangeHasWorkingDay($user, '2026-11-22', '2026-11-22');
    }

    public function test_full_day_leave_skips_rest_day_on_calendar_unless_bypassed(): void
    {
        $user = new User;
        $user->id = 3;
        $user->schedule = [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00'],
        ];

        $leave = new LeaveRequest([
            'type' => 'vacation',
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-22',
            'rest_day_bypass' => false,
        ]);

        $this->assertTrue(LeaveScheduleSupport::leaveCoversCalendarDate($user, $leave, '2026-11-20'));
        $this->assertFalse(LeaveScheduleSupport::leaveCoversCalendarDate($user, $leave, '2026-11-22'));

        $leave->rest_day_bypass = true;
        $this->assertTrue(LeaveScheduleSupport::leaveCoversCalendarDate($user, $leave, '2026-11-22'));
    }
}
