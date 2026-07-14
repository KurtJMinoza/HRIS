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

    public function test_evaluation_is_not_part_of_company_efficiency_service(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);

        $this->assertFalse($ref->hasMethod('combineEfficiency'));
    }

    public function test_missing_out_is_incomplete_with_zero_payroll_impact(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $normalize = $ref->getMethod('normalizeIncompletePunches');
        $normalize->setAccessible(true);
        $service = app(CompanyEfficiencyService::class);

        $summary = $normalize->invoke($service, [
            'status' => 'present',
            'status_label' => 'Present',
            'time_in' => null,
            'time_out' => '17:00:00',
            'formatted_time_in' => null,
            'formatted_time_out' => '5:00 PM',
            'payroll_impact_hours' => 8.0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
        ]);

        $this->assertSame('incomplete', $summary['status']);
        $this->assertSame('Missing in', $summary['status_label']);
        $this->assertSame('missing_in', $summary['presence_issue']);
        $this->assertSame(0.0, $summary['payroll_impact_hours']);
    }

    public function test_missing_in_pair_helper_labels_by_punch(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $meta = $ref->getMethod('missingPunchMeta');
        $meta->setAccessible(true);
        $service = app(CompanyEfficiencyService::class);

        $this->assertSame('Missing out', $meta->invoke($service, true, false)['status_label']);
        $this->assertSame('Missing in', $meta->invoke($service, false, true)['status_label']);
        $this->assertNull($meta->invoke($service, true, true));
        $this->assertNull($meta->invoke($service, false, false));
    }

    public function test_incomplete_day_keeps_scheduled_hours_but_zero_pay_in_agg(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $accumulate = $ref->getMethod('accumulateDayStats');
        $accumulate->setAccessible(true);
        $service = app(CompanyEfficiencyService::class);

        $employee = new \App\Models\User;
        $employee->id = 1;
        $agg = [
            'scheduled_employees' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'undertime' => 0,
            'on_leave' => 0,
            'total_scheduled_hours' => 0.0,
            'total_payroll_impact_hours' => 0.0,
        ];

        $accumulate->invokeArgs($service, [
            &$agg,
            $employee,
            'mon',
            [
                'status' => 'incomplete',
                'status_label' => 'Missing in',
                'schedule_in' => '08:00:00',
                'schedule_out' => '17:00:00',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 0.0,
                'scheduled_regular_hours' => 8.0,
                'is_leave' => false,
                'is_rest_day' => false,
                'is_holiday' => false,
            ],
            [
                1 => [
                    'mon' => [
                        'in' => '08:00:00',
                        'out' => '17:00:00',
                        'break_start' => '12:00:00',
                        'break_end' => '13:00:00',
                        'breaks' => [
                            ['start' => '12:00:00', 'end' => '13:00:00', 'is_paid' => false],
                        ],
                        'shift_type' => 'fixed',
                    ],
                ],
            ],
            null,
        ]);

        $this->assertSame(0, $agg['present']);
        $this->assertSame(8.0, $agg['total_scheduled_hours']);
        $this->assertSame(0.0, $agg['total_payroll_impact_hours']);
    }
}
