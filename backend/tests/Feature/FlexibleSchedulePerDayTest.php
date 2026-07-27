<?php

namespace Tests\Feature;

use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
use App\Services\ScheduleComputationService;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Tests\TestCase;

class FlexibleSchedulePerDayTest extends TestCase
{
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
}
