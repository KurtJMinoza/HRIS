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
        $combine = $ref->getMethod('combineEfficiency');
        $combine->setAccessible(true);
        $service = app(CompanyEfficiencyService::class);

        $this->assertSame(75.0, $combine->invoke($service, 50.0, 100.0));
        $this->assertSame(50.0, $combine->invoke($service, 50.0, null));
        $this->assertSame(100.0, $combine->invoke($service, null, 100.0));
        $this->assertNull($combine->invoke($service, null, null));
    }

    public function test_average_company_evaluation_reads_evaluation_module_results(): void
    {
        $ref = new ReflectionClass(CompanyEfficiencyService::class);
        $avg = $ref->getMethod('averageCompanyEvaluationPct');
        $avg->setAccessible(true);
        $service = app(CompanyEfficiencyService::class);

        $employeeA = new \App\Models\User;
        $employeeA->id = 10;
        $employeeB = new \App\Models\User;
        $employeeB->id = 11;

        $pct = $avg->invoke($service, [
            'all_employees_by_company' => collect([
                1 => collect([$employeeA, $employeeB]),
            ]),
            'latest_evaluations' => collect([
                10 => [
                    'evaluation_id' => 1,
                    'employee_id' => 10,
                    'evaluation_percentage' => 80.0,
                    'performance_level' => 'Very Satisfactory',
                    'evaluated_at' => '2026-07-01',
                    'status' => 'completed',
                ],
                11 => [
                    'evaluation_id' => 2,
                    'employee_id' => 11,
                    'evaluation_percentage' => 90.0,
                    'performance_level' => 'Outstanding',
                    'evaluated_at' => '2026-07-02',
                    'status' => 'completed',
                ],
            ]),
        ], 1);

        $this->assertSame(85.0, $pct);
    }

    public function test_missing_out_is_incomplete_with_zero_stored_payroll_impact(): void
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

    public function test_incomplete_missing_in_keeps_scheduled_hours_but_zero_pay_in_agg(): void
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
                'presence_issue' => 'missing_in',
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

    public function test_incomplete_missing_out_credits_provisional_pay_in_agg(): void
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
                'status_label' => 'Missing out',
                'presence_issue' => 'missing_out',
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

        $this->assertSame(1, $agg['present']);
        $this->assertSame(8.0, $agg['total_scheduled_hours']);
        $this->assertSame(8.0, $agg['total_payroll_impact_hours']);
    }

    public function test_no_punch_scheduled_day_counts_as_absent_with_zero_pay_in_agg(): void
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
                'status' => '—',
                'status_label' => '—',
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
        $this->assertSame(1, $agg['absent']);
        $this->assertSame(8.0, $agg['total_scheduled_hours']);
        $this->assertSame(0.0, $agg['total_payroll_impact_hours']);
    }

    public function test_rest_day_worked_counts_in_agg_and_efficiency_hours(): void
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
            'rest_days_worked' => 0,
            'total_scheduled_hours' => 0.0,
            'total_payroll_impact_hours' => 0.0,
        ];

        $accumulate->invokeArgs($service, [
            &$agg,
            $employee,
            'sat',
            [
                'status' => 'present_with_ot',
                'status_label' => 'Rest Day Worked',
                'schedule_in' => null,
                'schedule_out' => null,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 8.0,
                'is_leave' => false,
                'is_rest_day' => true,
                'is_rest_day_worked' => true,
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

        $this->assertSame(1, $agg['present']);
        $this->assertSame(1, $agg['rest_days_worked']);
        $this->assertSame(8.0, $agg['total_scheduled_hours']);
        $this->assertSame(8.0, $agg['total_payroll_impact_hours']);
    }

    public function test_rest_day_worked_ot_does_not_inflate_efficiency_hours(): void
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
            'rest_days_worked' => 0,
            'total_scheduled_hours' => 0.0,
            'total_payroll_impact_hours' => 0.0,
        ];

        $accumulate->invokeArgs($service, [
            &$agg,
            $employee,
            'sat',
            [
                'status' => 'present_with_ot',
                'status_label' => 'Rest Day Worked',
                'schedule_in' => null,
                'schedule_out' => null,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 11.0,
                'is_leave' => false,
                'is_rest_day' => true,
                'is_rest_day_worked' => true,
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

        // Expected baseline matches payroll impact so 11/11 = 100%, not 11/8 = 137.5%.
        $this->assertSame(11.0, $agg['total_scheduled_hours']);
        $this->assertSame(11.0, $agg['total_payroll_impact_hours']);
    }
}
