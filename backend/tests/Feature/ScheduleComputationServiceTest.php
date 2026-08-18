<?php

namespace Tests\Feature;

use App\Services\AttendanceStatusService;
use App\Services\ScheduleComputationService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleComputationServiceTest extends TestCase
{
    private ScheduleComputationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleComputationService;
    }

    /**
     * ACCEPTANCE TEST from spec:
     * Schedule: 12:00 PM – 9:00 PM, Break: 5:00 PM – 6:00 PM
     * Attendance: 12:14 PM – 9:02 PM
     * Expected: Not Half Day, Present/Late, ~7.8 hours payable, break deducted.
     */
    public function test_acceptance_afternoon_shift_with_break(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '12:00',
            'out' => '21:00',
            'break_start' => '17:00',
            'break_end' => '18:00',
            'shift_type' => 'fixed',
            'grace_period_minutes' => 5,
            'overtime_buffer_minutes' => 15,
        ];

        $timeIn = Carbon::parse('2026-06-25 12:14:00', $tz);
        $timeOut = Carbon::parse('2026-06-25 21:02:00', $tz);

        $result = $this->service->compute($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        // Scheduled: 12:00 - 21:00 = 9 hours span, minus 1 hour break = 8 hours paid = 480 min
        $this->assertEquals(480, $result['scheduled_paid_minutes'], 'Scheduled paid should be 480 minutes (8 hours)');

        // Half-day threshold = 480 / 2 = 240 min
        $this->assertEquals(240, $result['half_day_threshold_minutes'], 'Half-day threshold should be 240 minutes');

        // Actual worked: 12:14 - 21:02 = 528 raw minutes, minus 60 break overlap = 468 minutes
        $this->assertEquals(468, $result['actual_worked_minutes'], 'Actual worked should be 468 minutes (~7.8h)');

        // Break deducted = 60 minutes
        $this->assertEquals(60, $result['break_deducted_minutes'], 'Break deducted should be 60 minutes');

        // Payable = min(468, 480) = 468 minutes
        $this->assertEquals(468, $result['payable_minutes'], 'Payable should be 468 minutes');

        // Late = 14 minutes (12:14 - 12:00)
        $this->assertEquals(14, $result['late_minutes'], 'Late should be 14 minutes');

        // Status should NOT be half_day (468 > 240)
        $this->assertNotEquals('half_day', $result['status'], 'Should NOT be half day');
        $this->assertEquals('late', $result['status'], 'Status should be late');
    }

    public function test_standard_8_to_5_shift(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
            'grace_period_minutes' => 5,
        ];

        $timeIn = Carbon::parse('2026-06-25 08:00:00', $tz);
        $timeOut = Carbon::parse('2026-06-25 17:00:00', $tz);

        $result = $this->service->compute($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        $this->assertEquals(480, $result['scheduled_paid_minutes']);
        $this->assertEquals(240, $result['half_day_threshold_minutes']);
        $this->assertEquals(480, $result['actual_worked_minutes']);
        $this->assertEquals(0, $result['late_minutes']);
        $this->assertEquals('present', $result['status']);
    }

    public function test_exact_half_day_fixed_shift_is_half_day(): void
    {
        $tz = 'Asia/Manila';
        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
        ];

        $result = $this->service->compute(
            '2026-08-15',
            $daySchedule,
            Carbon::parse('2026-08-15 08:00:00', $tz),
            Carbon::parse('2026-08-15 12:00:00', $tz),
            $tz,
        );

        $this->assertSame(480, $result['scheduled_paid_minutes']);
        $this->assertSame(240, $result['payable_minutes']);
        $this->assertSame(240, $result['half_day_threshold_minutes']);
        $this->assertSame('half_day', $result['status']);
    }

    public function test_overnight_shift_10pm_to_7am(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '22:00',
            'out' => '07:00',
            'breaks' => [['start' => '02:00', 'end' => '03:00', 'is_paid' => false]],
            'shift_type' => 'overnight',
            'crosses_midnight' => true,
            'grace_period_minutes' => 5,
        ];

        $timeIn = Carbon::parse('2026-06-25 22:00:00', $tz);
        $timeOut = Carbon::parse('2026-06-26 07:00:00', $tz);

        $result = $this->service->compute($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        $this->assertEquals(480, $result['scheduled_paid_minutes'], 'Overnight: 9h span - 1h break = 8h');
        $this->assertEquals(480, $result['actual_worked_minutes']);
        $this->assertEquals('present', $result['status']);
    }

    public function test_split_shift(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '08:00',
            'out' => '18:00',
            'shift_type' => 'split',
            'work_blocks' => [
                ['start' => '08:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '18:00'],
            ],
            'expected_paid_minutes' => 480,
        ];

        $summary = $this->service->summarize($dateKey, $daySchedule, $tz);

        $this->assertEquals(480, $summary['required_minutes'], 'Split shift: 8 hours total');
    }

    public function test_7_5_hour_shift_half_day_threshold(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '08:00',
            'out' => '16:30',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
            'grace_period_minutes' => 5,
        ];

        $result = $this->service->compute($dateKey, $daySchedule, null, null, $tz);

        // 8.5h span - 1h break = 7.5h = 450 min
        $this->assertEquals(450, $result['scheduled_paid_minutes']);
        // Half-day threshold = floor(450/2) = 225
        $this->assertEquals(225, $result['half_day_threshold_minutes']);
    }

    public function test_10_hour_shift_half_day_threshold(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '06:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
            'expected_paid_minutes' => 600,
            'grace_period_minutes' => 5,
        ];

        $result = $this->service->compute($dateKey, $daySchedule, null, null, $tz);

        // Explicit 10h = 600 min
        $this->assertEquals(600, $result['scheduled_paid_minutes']);
        // Half-day threshold = 600/2 = 300
        $this->assertEquals(300, $result['half_day_threshold_minutes']);
    }

    public function test_rest_day_status(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-22'; // This is a Monday — but we mark mon as rest day

        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'shift_type' => 'fixed',
            'rest_days' => ['mon'],
        ];

        $result = $this->service->compute($dateKey, $daySchedule, null, null, $tz);

        $this->assertEquals('rest_day', $result['status']);
        $this->assertTrue($result['is_rest_day']);
    }

    public function test_multiple_breaks(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '08:00',
            'out' => '18:00',
            'breaks' => [
                ['start' => '10:00', 'end' => '10:15', 'is_paid' => true],
                ['start' => '12:00', 'end' => '13:00', 'is_paid' => false],
                ['start' => '15:00', 'end' => '15:15', 'is_paid' => true],
            ],
            'shift_type' => 'fixed',
        ];

        $summary = $this->service->summarize($dateKey, $daySchedule, $tz);

        // 10h span - 1h unpaid break = 9h paid (paid breaks don't deduct)
        $this->assertEquals(540, $summary['required_minutes']);
        $this->assertEquals(60, $summary['break_minutes']);
    }

    public function test_flexible_shift(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';

        $daySchedule = [
            'in' => '07:00',
            'out' => '22:00',
            'shift_type' => 'flexible',
            'flexible_required_minutes' => 480,
        ];

        $timeIn = Carbon::parse('2026-06-25 09:00:00', $tz);
        $timeOut = Carbon::parse('2026-06-25 17:30:00', $tz);

        $result = $this->service->compute($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        // 9:00 - 17:30 = 510 min, required = 480, surplus = 30
        $this->assertEquals(510, $result['actual_worked_minutes']);
        $this->assertEquals(480, $result['payable_minutes']);
        $this->assertEquals('present', $result['status']);
    }

    public function test_exact_half_day_flexible_shift_is_half_day(): void
    {
        $tz = 'Asia/Manila';
        $daySchedule = [
            'in' => '07:00',
            'out' => '22:00',
            'shift_type' => 'flexible',
            'flexible_required_minutes' => 480,
            'expected_paid_minutes' => 480,
        ];

        $result = $this->service->compute(
            '2026-06-25',
            $daySchedule,
            Carbon::parse('2026-06-25 09:00:00', $tz),
            Carbon::parse('2026-06-25 13:00:00', $tz),
            $tz,
        );

        $this->assertSame(480, $result['scheduled_paid_minutes']);
        $this->assertSame(240, $result['payable_minutes']);
        $this->assertSame(240, $result['half_day_threshold_minutes']);
        $this->assertSame('half_day', $result['status']);
    }

    public function test_exact_half_day_split_shift_is_half_day(): void
    {
        $tz = 'Asia/Manila';
        $daySchedule = [
            'in' => '08:00',
            'out' => '18:00',
            'shift_type' => 'split',
            'work_blocks' => [
                ['start' => '08:00', 'end' => '12:00'],
                ['start' => '14:00', 'end' => '18:00'],
            ],
            'expected_paid_minutes' => 480,
        ];

        $result = $this->service->compute(
            '2026-06-25',
            $daySchedule,
            Carbon::parse('2026-06-25 08:00:00', $tz),
            Carbon::parse('2026-06-25 14:00:00', $tz),
            $tz,
        );

        $this->assertSame(480, $result['scheduled_paid_minutes']);
        $this->assertSame(240, $result['payable_minutes']);
        $this->assertSame(240, $result['half_day_threshold_minutes']);
        $this->assertSame('half_day', $result['status']);
    }

    public function test_half_day_leave_windows_follow_schedule_not_noon(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';
        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
            'expected_paid_minutes' => 480,
        ];

        $windows = $this->service->halfDayLeaveWindows($dateKey, $daySchedule, $tz);

        $this->assertSame('12:00', $windows['split_at']);
        $this->assertSame(240, $windows['half_paid_minutes']);
        $this->assertSame('13:00', $windows['am']['earliest_clock_in']);
        $this->assertSame('12:00', $windows['pm']['latest_clock_out']);
        $this->assertSame('13:00', $windows['am']['suggested_half_day_time']);
        $this->assertSame('12:00', $windows['pm']['suggested_half_day_time']);
    }

    public function test_half_day_leave_windows_include_flexible_shift_options(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';
        $daySchedule = [
            'shift_type' => 'flexible',
            'schedule_type' => 'flexible',
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'expected_paid_minutes' => 480,
            'flexible_shift_options' => [
                [
                    'matched_schedule_option_id' => 101,
                    'matched_schedule_option_name' => 'Default',
                    'in' => '08:00',
                    'out' => '17:00',
                    'break_start' => '12:00',
                    'break_end' => '13:00',
                    'expected_paid_minutes' => 480,
                    'is_default' => true,
                ],
                [
                    'matched_schedule_option_id' => 102,
                    'matched_schedule_option_name' => 'Option 2',
                    'in' => '12:00',
                    'out' => '21:00',
                    'break_start' => '17:00',
                    'break_end' => '18:00',
                    'expected_paid_minutes' => 480,
                    'is_default' => false,
                ],
            ],
        ];

        $default = $this->service->halfDayLeaveWindows($dateKey, $daySchedule, $tz);
        $this->assertTrue($default['has_flexible_options']);
        $this->assertCount(2, $default['flexible_options']);
        $this->assertSame(101, $default['selected_option_id']);
        $this->assertSame('08:00', $default['scheduled_start']);
        $this->assertSame('17:00', $default['scheduled_end']);

        $option2 = $this->service->halfDayLeaveWindows($dateKey, $daySchedule, $tz, 102);
        $this->assertSame(102, $option2['selected_option_id']);
        $this->assertSame('12:00', $option2['scheduled_start']);
        $this->assertSame('21:00', $option2['scheduled_end']);
        $this->assertNotSame($default['am']['work_start'] ?? null, $option2['am']['work_start'] ?? null);
    }

    public function test_half_day_filed_time_overrides_pm_clock_out_boundary(): void
    {
        $tz = 'Asia/Manila';
        $dateKey = '2026-06-25';
        $daySchedule = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'shift_type' => 'fixed',
            'expected_paid_minutes' => 480,
        ];

        $at1130 = Carbon::parse($dateKey.' 11:30', $tz);
        AttendanceStatusService::assertHalfDayLeaveClockAllowed(
            $dateKey,
            $daySchedule,
            'pm',
            'clock_out',
            $at1130,
            $tz,
            '11:30'
        );

        $at1201 = Carbon::parse($dateKey.' 12:01', $tz);
        $this->expectException(ValidationException::class);
        AttendanceStatusService::assertHalfDayLeaveClockAllowed(
            $dateKey,
            $daySchedule,
            'pm',
            'clock_out',
            $at1201,
            $tz,
            '11:30'
        );
    }
}
