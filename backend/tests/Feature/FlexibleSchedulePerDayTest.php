<?php

namespace Tests\Feature;

use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
use App\Models\WorkingScheduleDayOption;
use App\Services\AttendanceStatusService;
use App\Services\ScheduleComputationService;
use App\Services\TimeSegmentationService;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexibleSchedulePerDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_flexible_resolver_returns_per_weekday_times(): void
    {
        $schedule = new WorkingSchedule([
            'name' => 'Flexible Office Schedule',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'grace_period_minutes' => 10,
            'rest_days' => ['sat', 'sun'],
        ]);

        $schedule->setRelation('days', collect([
            new WorkingScheduleDay([
                'day_of_week' => 'mon', 'is_working_day' => true,
                'time_in' => '08:00', 'time_out' => '17:00',
                'break_start' => '12:00', 'break_end' => '13:00', 'grace_period_minutes' => 10,
            ]),
            new WorkingScheduleDay([
                'day_of_week' => 'tue', 'is_working_day' => true,
                'time_in' => '07:00', 'time_out' => '16:00',
                'break_start' => '12:00', 'break_end' => '13:00', 'grace_period_minutes' => 10,
            ]),
            new WorkingScheduleDay([
                'day_of_week' => 'wed', 'is_working_day' => true,
                'time_in' => '09:00', 'time_out' => '18:00',
                'break_start' => '12:00', 'break_end' => '13:00', 'grace_period_minutes' => 10,
            ]),
            new WorkingScheduleDay(['day_of_week' => 'sat', 'is_working_day' => false]),
            new WorkingScheduleDay(['day_of_week' => 'sun', 'is_working_day' => false]),
        ]));

        $payload = EmployeeScheduleResolver::buildFromWorkingSchedule($schedule);

        $this->assertSame('07:00', substr((string) $payload['tue']['in'], 0, 5));
        $this->assertSame('16:00', substr((string) $payload['tue']['out'], 0, 5));
        $this->assertNull($payload['sat']);
    }

    public function test_flexible_resolver_returns_multiple_options_for_a_weekday(): void
    {
        $schedule = new WorkingSchedule([
            'name' => 'Flexible Multi Option',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'grace_period_minutes' => 5,
            'rest_days' => ['sat', 'sun'],
        ]);

        $mon = new WorkingScheduleDay([
            'day_of_week' => 'mon',
            'is_working_day' => true,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
        ]);
        $mon->setRelation('options', collect([
            new WorkingScheduleDayOption([
                'id' => 11,
                'option_name' => 'Morning',
                'time_in' => '08:00',
                'time_out' => '17:00',
                'break_start' => '12:00',
                'break_end' => '13:00',
                'grace_period_minutes' => 5,
                'is_default' => true,
                'sequence' => 1,
            ]),
            new WorkingScheduleDayOption([
                'id' => 12,
                'option_name' => 'Afternoon',
                'time_in' => '12:00',
                'time_out' => '21:00',
                'break_start' => '17:00',
                'break_end' => '18:00',
                'grace_period_minutes' => 5,
                'is_default' => false,
                'sequence' => 2,
            ]),
        ]));

        $schedule->setRelation('days', collect([$mon]));

        $payload = EmployeeScheduleResolver::buildFromWorkingSchedule($schedule);

        $this->assertCount(2, $payload['mon']['flexible_shift_options']);
        $this->assertSame('Morning', $payload['mon']['matched_schedule_option_name']);
        $this->assertSame('12:00', substr((string) $payload['mon']['flexible_shift_options'][1]['in'], 0, 5));
    }

    public function test_tuesday_late_uses_that_days_grace_period(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';

        $daySchedule = [
            'in' => '07:00',
            'out' => '16:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'breaks' => [['start' => '12:00', 'end' => '13:00', 'is_paid' => false]],
            'shift_type' => 'flexible',
            'grace_period_minutes' => 10,
        ];

        $timeIn = Carbon::parse('2026-08-04 07:20:00', $tz);
        $timeOut = Carbon::parse('2026-08-04 16:00:00', $tz);

        $result = $service->compute('2026-08-04', $daySchedule, $timeIn, $timeOut, $tz);

        $this->assertSame(20, $result['late_minutes']);
        $this->assertSame('late', $result['status']);

        $withinGrace = $service->compute(
            '2026-08-04',
            $daySchedule,
            Carbon::parse('2026-08-04 07:09:00', $tz),
            $timeOut,
            $tz,
        );
        $this->assertSame(0, $withinGrace['late_minutes']);
        $this->assertSame('present', $withinGrace['status']);
    }

    public function test_multiple_option_flexible_day_matches_afternoon_shift(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';
        $daySchedule = $this->multiOptionMonday();

        $result = $service->compute(
            '2026-08-10',
            $daySchedule,
            Carbon::parse('2026-08-10 12:00:00', $tz),
            Carbon::parse('2026-08-10 21:00:00', $tz),
            $tz,
        );

        $this->assertSame(102, $result['matched_schedule_option_id']);
        $this->assertSame('Afternoon', $result['matched_schedule_option_name']);
        $this->assertSame('present', $result['status']);
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame(0, $result['undertime_minutes']);
        $this->assertSame(480, $result['payable_minutes']);
    }

    public function test_persisted_flexible_schedule_options_are_loaded_for_attendance_matching(): void
    {
        $schedule = WorkingSchedule::query()->create([
            'name' => 'Persisted Flexible Multi Option',
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'rest_days' => ['sat', 'sun'],
        ]);
        $monday = $schedule->days()->create([
            'day_of_week' => 'mon',
            'is_working_day' => true,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
        ]);
        $morning = $monday->options()->create([
            'option_name' => 'Morning',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'break_is_paid' => false,
            'grace_period_minutes' => 5,
            'is_default' => true,
            'sequence' => 1,
        ]);
        $afternoon = $monday->options()->create([
            'option_name' => 'Afternoon',
            'time_in' => '12:00',
            'time_out' => '21:00',
            'break_start' => '17:00',
            'break_end' => '18:00',
            'break_is_paid' => false,
            'grace_period_minutes' => 5,
            'is_default' => false,
            'sequence' => 2,
        ]);

        $payload = EmployeeScheduleResolver::buildFromWorkingSchedule(
            WorkingSchedule::query()->findOrFail($schedule->id)
        );
        $daySchedule = $payload['mon'];

        $this->assertCount(2, $daySchedule['flexible_shift_options']);

        $result = (new ScheduleComputationService)->compute(
            '2026-08-10',
            $daySchedule,
            Carbon::parse('2026-08-10 12:00:00', 'Asia/Manila'),
            Carbon::parse('2026-08-10 21:00:00', 'Asia/Manila'),
            'Asia/Manila',
        );

        $this->assertSame($afternoon->id, $result['matched_schedule_option_id']);
        $this->assertNotSame($morning->id, $result['matched_schedule_option_id']);
        $this->assertSame('present', $result['status']);
    }

    public function test_clock_in_status_uses_matching_flexible_shift_option(): void
    {
        $result = AttendanceStatusService::getClockInStatus(
            $this->multiOptionMonday(),
            '2026-08-10',
            Carbon::parse('2026-08-10 12:00:00', 'Asia/Manila'),
        );

        $this->assertSame('on_time', $result['status']);
        $this->assertSame(0, $result['late_minutes']);
        $this->assertSame('Present', $result['late_label']);
    }

    public function test_undertime_and_overtime_helpers_use_matching_flexible_shift_option(): void
    {
        $tz = 'Asia/Manila';
        $daySchedule = $this->multiOptionMonday();
        $timeIn = Carbon::parse('2026-08-10 12:00:00', $tz);
        $fullShiftOut = Carbon::parse('2026-08-10 21:00:00', $tz);

        $overtime = AttendanceStatusService::computeRawOvertimeBreakdown(
            '2026-08-10',
            $daySchedule,
            $timeIn,
            $fullShiftOut,
            $tz,
        );
        $this->assertSame(0, $overtime['total_minutes']);

        $undertime = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
            '2026-08-10',
            $daySchedule,
            $timeIn,
            Carbon::parse('2026-08-10 20:00:00', $tz),
            $tz,
        );
        $this->assertSame(60, $undertime);
    }

    public function test_multiple_option_flexible_day_still_matches_morning_shift(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';

        $result = $service->compute(
            '2026-08-10',
            $this->multiOptionMonday(),
            Carbon::parse('2026-08-10 08:00:00', $tz),
            Carbon::parse('2026-08-10 17:00:00', $tz),
            $tz,
        );

        $this->assertSame(101, $result['matched_schedule_option_id']);
        $this->assertSame('Morning', $result['matched_schedule_option_name']);
        $this->assertSame('present', $result['status']);
    }

    public function test_short_completed_pair_is_undertime_for_each_matched_flexible_option(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';
        $date = '2026-08-10';

        foreach ([
            ['id' => 101, 'name' => 'Morning', 'in' => '08:00', 'out' => '08:10'],
            ['id' => 102, 'name' => 'Afternoon', 'in' => '12:00', 'out' => '12:10'],
        ] as $case) {
            $result = $service->compute(
                $date,
                $this->multiOptionMonday(),
                Carbon::parse($date.' '.$case['in'], $tz),
                Carbon::parse($date.' '.$case['out'], $tz),
                $tz,
            );

            $this->assertSame($case['id'], $result['matched_schedule_option_id']);
            $this->assertSame($case['name'], $result['matched_schedule_option_name']);
            $this->assertSame(10, $result['actual_worked_minutes']);
            $this->assertSame(480, $result['scheduled_paid_minutes']);
            $this->assertSame(470, $result['undertime_minutes']);
            $this->assertSame('undertime', $result['status']);
        }
    }

    public function test_exact_half_day_pair_is_half_day_for_each_matched_flexible_option(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';
        $date = '2026-08-10';

        foreach ([
            ['id' => 101, 'name' => 'Morning', 'in' => '08:00', 'out' => '12:00'],
            ['id' => 102, 'name' => 'Afternoon', 'in' => '12:00', 'out' => '16:00'],
        ] as $case) {
            $result = $service->compute(
                $date,
                $this->multiOptionMonday(),
                Carbon::parse($date.' '.$case['in'], $tz),
                Carbon::parse($date.' '.$case['out'], $tz),
                $tz,
            );

            $this->assertSame($case['id'], $result['matched_schedule_option_id']);
            $this->assertSame($case['name'], $result['matched_schedule_option_name']);
            $this->assertSame(240, $result['payable_minutes']);
            $this->assertSame(240, $result['half_day_threshold_minutes']);
            $this->assertSame('half_day', $result['status']);
        }
    }

    public function test_late_afternoon_shift_is_not_late_against_morning_option(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';

        $result = $service->compute(
            '2026-08-10',
            $this->multiOptionMonday(),
            Carbon::parse('2026-08-10 12:20:00', $tz),
            Carbon::parse('2026-08-10 21:00:00', $tz),
            $tz,
        );

        $this->assertSame(102, $result['matched_schedule_option_id']);
        $this->assertSame(20, $result['late_minutes']);
        $this->assertSame('late', $result['status']);
    }

    public function test_undertime_afternoon_shift_uses_afternoon_end(): void
    {
        $service = new ScheduleComputationService;
        $tz = 'Asia/Manila';

        $result = $service->compute(
            '2026-08-10',
            $this->multiOptionMonday(),
            Carbon::parse('2026-08-10 12:00:00', $tz),
            Carbon::parse('2026-08-10 20:00:00', $tz),
            $tz,
        );

        $this->assertSame(102, $result['matched_schedule_option_id']);
        $this->assertSame(60, $result['undertime_minutes']);
        $this->assertSame('undertime', $result['status']);
    }

    public function test_no_attendance_uses_default_option_for_absence(): void
    {
        $service = new ScheduleComputationService;

        $result = $service->compute('2026-08-10', $this->multiOptionMonday(), null, null, 'Asia/Manila');

        $this->assertSame(101, $result['matched_schedule_option_id']);
        $this->assertSame('Morning', $result['matched_schedule_option_name']);
        $this->assertSame('default', $result['match_source']);
        $this->assertSame('absent', $result['status']);
        $this->assertSame(480, $result['scheduled_paid_minutes']);
    }

    public function test_schedule_label_shows_all_flexible_options_before_punch(): void
    {
        $service = new ScheduleComputationService;

        $label = $service->scheduleLabelForDaySchedule($this->multiOptionMonday());

        $this->assertStringContainsString('08:00 – 17:00', (string) $label);
        $this->assertStringContainsString('12:00 – 21:00', (string) $label);
        $this->assertStringContainsString('Afternoon', (string) $label);
    }

    public function test_schedule_label_shows_matched_option_after_punch(): void
    {
        $service = new ScheduleComputationService;
        $matched = array_merge($this->multiOptionMonday(), [
            'in' => '12:00',
            'out' => '21:00',
            'matched_schedule_option_name' => 'Afternoon',
            'match_source' => 'automatic',
        ]);

        $label = $service->scheduleLabelForDaySchedule($matched);

        $this->assertSame('Afternoon: 12:00 – 21:00', $label);
    }

    public function test_matched_afternoon_option_segments_full_eight_regular_hours(): void
    {
        $tz = 'Asia/Manila';
        $date = '2026-08-10';
        $in = Carbon::parse($date.' 12:00:00', $tz);
        $out = Carbon::parse($date.' 21:00:00', $tz);

        $matched = (new ScheduleComputationService)
            ->resolveFlexibleShiftForAttendance($date, $this->multiOptionMonday(), $in, $out, $tz)['schedule'];

        $this->assertSame('Afternoon', $matched['matched_schedule_option_name'] ?? null);
        $this->assertSame('12:00', substr((string) ($matched['in'] ?? ''), 0, 5));

        $seg = app(TimeSegmentationService::class)->segment($in, $out, $tz, $matched, $date);

        $this->assertSame(480, (int) ($seg['regular_minutes'] ?? 0));
        $this->assertSame(0, (int) ($seg['overtime_minutes'] ?? 0));
    }

    private function multiOptionMonday(): array
    {
        return [
            'shift_type' => 'flexible',
            'schedule_type' => 'flexible',
            'rest_days' => ['sat', 'sun'],
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => 5,
            'flexible_shift_options' => [
                [
                    'matched_schedule_option_id' => 101,
                    'matched_schedule_option_name' => 'Morning',
                    'in' => '08:00',
                    'out' => '17:00',
                    'break_start' => '12:00',
                    'break_end' => '13:00',
                    'breaks' => [['start' => '12:00', 'end' => '13:00', 'is_paid' => false]],
                    'expected_paid_minutes' => 480,
                    'grace_period_minutes' => 5,
                    'is_default' => true,
                    'sequence' => 1,
                ],
                [
                    'matched_schedule_option_id' => 102,
                    'matched_schedule_option_name' => 'Afternoon',
                    'in' => '12:00',
                    'out' => '21:00',
                    'break_start' => '17:00',
                    'break_end' => '18:00',
                    'breaks' => [['start' => '17:00', 'end' => '18:00', 'is_paid' => false]],
                    'expected_paid_minutes' => 480,
                    'grace_period_minutes' => 5,
                    'is_default' => false,
                    'sequence' => 2,
                ],
            ],
        ];
    }
}
