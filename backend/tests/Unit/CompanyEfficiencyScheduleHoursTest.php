<?php

namespace Tests\Unit;

use App\Services\AttendanceStatusService;
use App\Services\CompanyEfficiencyService;
use ReflectionClass;
use Tests\TestCase;

class CompanyEfficiencyScheduleHoursTest extends TestCase
{
    public function test_compute_schedule_hours_subtracts_unpaid_break_windows(): void
    {
        $day = [
            'in' => '08:00:00',
            'out' => '17:00:00',
            'break_start' => '12:00:00',
            'break_end' => '13:00:00',
            'breaks' => [
                ['start' => '12:00:00', 'end' => '13:00:00', 'is_paid' => false],
            ],
            'shift_type' => 'fixed',
            'expected_paid_minutes' => null,
        ];

        $this->assertSame(
            480,
            AttendanceStatusService::getRequiredWorkingMinutes('2026-06-02', $day),
        );

        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $m = $ref->getMethod('computeScheduleHours');
        $m->setAccessible(true);

        $hours = $m->invoke(app(CompanyEfficiencyService::class), $day);
        $this->assertSame(8.0, $hours);
    }

    public function test_combine_efficiency_averages_attendance_and_evaluation(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $m = $ref->getMethod('combineEfficiency');
        $m->setAccessible(true);
        $svc = app(CompanyEfficiencyService::class);

        $this->assertSame(90.0, $m->invoke($svc, 100.0, 80.0));
        $this->assertSame(100.0, $m->invoke($svc, 100.0, null));
        $this->assertSame(80.0, $m->invoke($svc, null, 80.0));
        $this->assertNull($m->invoke($svc, null, null));
    }
}
