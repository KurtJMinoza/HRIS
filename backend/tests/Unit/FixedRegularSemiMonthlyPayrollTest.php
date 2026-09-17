<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PayrollComputationService;
use Tests\TestCase;

class FixedRegularSemiMonthlyPayrollTest extends TestCase
{
    public function test_regular_employment_status_is_fixed_semi_monthly_salary_type(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new \ReflectionMethod($service, 'isFixedSemiMonthlySalaryEmployee');
        $method->setAccessible(true);

        $regularStatus = (new User)->forceFill([
            'employment_status' => 'regular',
            'employment_type' => 'full_time',
        ]);
        $regularStatusLegacyLabel = (new User)->forceFill([
            'employment_status' => 'Regular',
            'employment_type' => 'consultant',
        ]);
        $probationaryStatus = (new User)->forceFill([
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
        ]);
        $regularTypeOnly = (new User)->forceFill([
            'employment_status' => 'probationary',
            'employment_type' => 'regular',
        ]);
        $consultant = (new User)->forceFill([
            'employment_status' => 'consultant',
            'employment_type' => 'consultant',
        ]);

        $this->assertTrue($method->invoke($service, $regularStatus, false));
        $this->assertTrue($method->invoke($service, $regularStatusLegacyLabel, false));
        $this->assertFalse($method->invoke($service, $probationaryStatus, false));
        $this->assertFalse($method->invoke($service, $regularTypeOnly, false));
        $this->assertFalse($method->invoke($service, $consultant, true));
    }

    public function test_fixed_attendance_deductions_include_absence_and_unpaid_leave(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new \ReflectionMethod($service, 'computeFixedRegularAttendanceDeductions');
        $method->setAccessible(true);

        $dailyRate = 1153.85;
        $hourlyRate = $dailyRate / 8.0;
        $lateRegularPay = round($hourlyRate * (420 / 60.0), 2);
        $days = [
            [
                'status' => 'worked',
                'required_minutes' => 480,
                'is_rest_day' => false,
                'regular_pay' => $lateRegularPay,
                'late_deduction_minutes' => 60,
                'undertime_deduction_minutes' => 0,
                'tardiness_status' => 'late',
                'breakdown' => [
                    ['component' => 'regular_pay', 'minutes' => 420, 'amount' => $lateRegularPay],
                ],
            ],
            [
                'status' => 'absent',
                'required_minutes' => 480,
                'is_rest_day' => false,
                'late_deduction_minutes' => 0,
                'undertime_deduction_minutes' => 0,
                'breakdown' => [],
            ],
            [
                'status' => 'leave',
                'required_minutes' => 480,
                'is_rest_day' => false,
                'late_deduction_minutes' => 0,
                'undertime_deduction_minutes' => 0,
                'breakdown' => [
                    ['component' => 'unpaid_leave', 'amount' => 0.0, 'leave_type' => 'half_day'],
                ],
            ],
        ];

        $breakdown = $method->invoke($service, $days, $dailyRate);
        $rowsByKey = collect($breakdown['rows'] ?? [])->keyBy('key');

        $lateAmount = round($dailyRate - $lateRegularPay, 2);
        $absenceAmount = round($dailyRate, 2);
        $unpaidLeaveAmount = round($dailyRate * 0.5, 2);

        $this->assertSame($lateAmount, (float) ($rowsByKey['late']['deduction_amount'] ?? 0));
        $this->assertSame($absenceAmount, (float) ($rowsByKey['absence']['deduction_amount'] ?? 0));
        $this->assertSame($unpaidLeaveAmount, (float) ($rowsByKey['unpaid_leave']['deduction_amount'] ?? 0));
        $this->assertSame(
            round($lateAmount + $absenceAmount + $unpaidLeaveAmount, 2),
            (float) ($breakdown['total_deduction'] ?? 0)
        );
    }

    public function test_evalaroza_style_regular_and_leave_display_sum_to_semi_monthly(): void
    {
        $semiMonthlyGross = 10000.0;
        $leaveDisplay = 1538.46;
        $regularDisplay = round($semiMonthlyGross - $leaveDisplay, 2);
        $lateDeduction = 192.32;
        $netBasic = round($semiMonthlyGross - $lateDeduction, 2);
        $regularPayable = round($netBasic - $leaveDisplay, 2);
        $regularAfterReductions = round($regularDisplay - $lateDeduction, 2);

        $this->assertSame(8461.54, $regularDisplay);
        $this->assertSame(10000.0, round($regularDisplay + $leaveDisplay, 2));
        $this->assertSame(9807.68, $netBasic);
        $this->assertSame(8269.22, $regularPayable);
        $this->assertSame(8269.22, $regularAfterReductions);
    }

    public function test_fixed_semi_monthly_display_net_pay_subtracts_late_when_headline_shows_pre_reduction_gross(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $lateDeduction = 192.32;
        $customDeductions = 1648.0;
        $allowance = 2500.0;
        $snapshot = [
            'daily_rate' => 769.23,
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => 10000.0,
                'semi_monthly_basic_salary' => 10000.0,
                'basic_pay_this_period' => 9807.68,
                'regular_fixed_paid_leave_amount' => 1538.46,
                'regular_fixed_paid_leave_day_units' => 2.0,
                'regular_pay_present_day_units' => 14.0,
                'daily_rate' => 769.23,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'total_deduction' => $lateDeduction,
                    'scheduled_days_count' => 14,
                    'rows' => [[
                        'key' => 'late',
                        'label' => 'Late',
                        'amount' => $lateDeduction,
                    ]],
                ],
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 8269.22,
                        'display_amount' => 8461.54,
                        'units' => '12 days',
                    ],
                    [
                        'key' => 'daily:paid_leave',
                        'label' => 'Leave adjustments',
                        'amount' => 1538.46,
                        'display_amount' => 1538.46,
                        'units' => '2 days',
                        'metadata' => [
                            'included_in_fixed_semi_monthly_basic' => true,
                            'leave_day_units' => 2.0,
                        ],
                    ],
                ],
                'payslip_earning_lines' => [[
                    'key' => 'pay_component:22',
                    'label' => 'Allowance',
                    'amount' => $allowance,
                ]],
                'payslip_custom_deduction_lines' => [[
                    'key' => 'deduction:1',
                    'label' => 'Custom deduction',
                    'amount' => $customDeductions,
                ]],
            ],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);
        $summary = $normalized['summary'];

        $this->assertSame(12500.0, round((float) ($summary['display_gross_pay'] ?? 0), 2));
        $this->assertSame(
            10659.68,
            round((float) ($summary['display_net_pay'] ?? 0), 2)
        );

        $lineTotals = $payslipService->payslipLineTotalsFromSnapshot($snapshot);
        $this->assertSame(10659.68, round((float) ($lineTotals['net_pay'] ?? 0), 2));
        $this->assertSame($customDeductions, round((float) ($lineTotals['total_deductions'] ?? 0), 2));
    }

    public function test_paid_leave_split_with_refund_display_totals_include_refund_line(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $lateDeduction = 240.4;
        $paidLeave = 1538.46;
        $refund = 1538.46;
        $regularAfterLate = 8221.14;
        $expectedGross = round($regularAfterLate + $paidLeave + $refund, 2);

        $snapshot = [
            'daily_rate' => 769.23,
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => 10000.0,
                'semi_monthly_basic_salary' => 10000.0,
                'basic_pay_this_period' => $regularAfterLate + $paidLeave,
                'regular_fixed_paid_leave_amount' => $paidLeave,
                'regular_fixed_paid_leave_day_units' => 2.0,
                'regular_pay_present_day_units' => 14.0,
                'daily_rate' => 769.23,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'regular_pay_after_reductions' => $regularAfterLate,
                    'total_deduction' => $lateDeduction,
                    'scheduled_days_count' => 14,
                    'rows' => [[
                        'key' => 'late',
                        'label' => 'Late',
                        'deduction_amount' => $lateDeduction,
                    ]],
                ],
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => $regularAfterLate,
                        'display_amount' => 8461.54,
                        'units' => '12 days',
                    ],
                    [
                        'key' => 'daily:paid_leave',
                        'label' => 'Leave adjustments',
                        'amount' => $paidLeave,
                        'display_amount' => $paidLeave,
                        'units' => '2 days',
                        'metadata' => [
                            'included_in_fixed_semi_monthly_basic' => true,
                            'leave_day_units' => 2.0,
                        ],
                    ],
                ],
                'payslip_earning_lines' => [[
                    'key' => 'refund_basic_pay',
                    'label' => 'Attendance Refund',
                    'amount' => $refund,
                    'component_code' => 'refund_basic_pay',
                    'metadata' => [
                        'refund_request_id' => 99,
                    ],
                ]],
            ],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);
        $summary = $normalized['summary'];

        $this->assertSame($expectedGross, round((float) ($summary['display_gross_pay'] ?? 0), 2));
        $this->assertSame($expectedGross, round((float) ($summary['display_net_pay'] ?? 0), 2));
    }

    public function test_fixed_semi_monthly_display_gross_uses_after_reductions_when_no_paid_leave_split(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $lateDeduction = 420.70;
        $fixedGross = 12500.0;
        $netBasic = round($fixedGross - $lateDeduction, 2);
        $customDeductions = 440.0;
        $snapshot = [
            'daily_rate' => 961.54,
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => $fixedGross,
                'semi_monthly_basic_salary' => $fixedGross,
                'basic_pay_this_period' => $netBasic,
                'regular_pay_present_day_units' => 14.0,
                'daily_rate' => 961.54,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'total_deduction' => $lateDeduction,
                    'scheduled_days_count' => 14,
                    'regular_pay_after_reductions' => $netBasic,
                    'fixed_basic_pay_after_reductions' => $netBasic,
                    'rows' => [[
                        'key' => 'late',
                        'label' => 'Late',
                        'amount' => $lateDeduction,
                    ]],
                ],
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => $netBasic,
                    'display_amount' => $fixedGross,
                    'units' => '14 days',
                ]],
                'payslip_custom_deduction_lines' => [[
                    'key' => 'deduction:1',
                    'label' => 'Custom deduction',
                    'amount' => $customDeductions,
                ]],
            ],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);
        $summary = $normalized['summary'];

        $this->assertSame($fixedGross, round((float) ($summary['daily_computation_earning_lines'][0]['display_amount'] ?? 0), 2));
        $this->assertSame($netBasic, round((float) ($summary['display_gross_pay'] ?? 0), 2));
        $this->assertEqualsWithDelta(
            round($netBasic - $customDeductions, 2),
            round((float) ($summary['display_net_pay'] ?? 0), 2),
            0.1
        );
    }

    public function test_fixed_semi_monthly_paid_leave_split_gross_uses_regular_pay_after_reductions(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $regularDisplay = 5400.0;
        $regularAfterReductions = 5097.38;
        $paidLeave = 1620.0;
        $holidayPremium = 506.25;
        $restDayPremium = 162.0;
        $expectedGross = round($regularAfterReductions + $paidLeave + $holidayPremium + $restDayPremium, 2);

        $earningLines = [
            [
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => $regularAfterReductions,
                'display_amount' => $regularDisplay,
            ],
            [
                'key' => 'daily:paid_leave',
                'label' => 'Leave adjustments',
                'amount' => $paidLeave,
                'display_amount' => $paidLeave,
                'metadata' => ['included_in_fixed_semi_monthly_basic' => true],
            ],
            [
                'key' => 'holiday:2026-08-31:REGULAR_HOLIDAY_WORKED_PAY',
                'label' => 'Regular Holiday — Worked Pay: NATIONAL HEROES DAY',
                'amount' => $holidayPremium,
                'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
                'metadata' => ['worked' => true, 'display_split_applied' => true],
            ],
            [
                'key' => 'daily:rest_day_worked',
                'label' => 'Rest Day Worked Pay (30% Additional)',
                'amount' => $restDayPremium,
                'component_code' => 'REST_DAY_WORKED_PAY',
                'metadata' => ['display_split_applied' => true],
            ],
        ];
        $breakdown = [
            'available' => true,
            'regular_pay_after_reductions' => $regularAfterReductions,
            'total_deduction' => 302.62,
        ];

        $method = new \ReflectionMethod($payslipService, 'sumPayslipLineDisplayAmounts');
        $method->setAccessible(true);
        $gross = $method->invoke($payslipService, $earningLines, $breakdown);

        $this->assertEqualsWithDelta($expectedGross, $gross, 0.02);
    }

    public function test_late_breakdown_prefers_ledger_minutes_over_inflated_policy_label(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $snapshot = [
            'daily_rate' => 450.0,
            'summary' => [
                'daily_rate' => 450.0,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 5097.38,
                    'display_amount' => 5400.0,
                ]],
            ],
            'daily_computation_days' => [[
                'date' => '2026-08-26',
                'status' => 'worked',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'regular_day_minutes' => 390,
                'regular_night_minutes' => 0,
                'late_deduction_minutes' => 90,
                'undertime_deduction_minutes' => 0,
                'tardiness_status' => 'late',
                'tardiness_label' => '9 hours 30 minutes late',
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 390,
                    'rate' => 56.25,
                    'amount' => 365.63,
                ]],
            ]],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);
        $rows = collect($normalized['summary']['attendance_pay_breakdown']['rows'] ?? [])->keyBy('key');

        $this->assertSame(90, (int) ($rows['late']['minutes'] ?? 0));
        $this->assertSame('1 hr 30 mins', $rows['late']['details'] ?? null);
    }

    public function test_fixed_semi_monthly_zero_presence_yields_zero_basic_pay(): void
    {
        $service = app(PayrollComputationService::class);
        $countPresent = new \ReflectionMethod($service, 'countFixedRegularPresentDayUnits');
        $countPresent->setAccessible(true);

        $days = [
            [
                'status' => 'leave',
                'required_minutes' => 480,
                'is_rest_day' => false,
                'breakdown' => [
                    ['component' => 'unpaid_leave', 'amount' => 0.0, 'leave_type' => 'full_day'],
                ],
            ],
            [
                'status' => 'absent',
                'required_minutes' => 480,
                'is_rest_day' => false,
                'breakdown' => [],
            ],
        ];

        $presentUnits = $countPresent->invoke($service, $days);
        $this->assertSame(0.0, $presentUnits);

        $semiMonthly = 9035.0;
        $dailyRate = 695.0;
        $presentDayBaseRegularPay = 0.0;
        if ($presentUnits > 0.0001 && $dailyRate > 0.0001) {
            $presentDayBaseRegularPay = round($presentUnits * $dailyRate, 2);
        }

        $baseRegularPay = round(min($semiMonthly, $presentDayBaseRegularPay), 2);
        $nonAbsenceDeduction = 1390.0;
        $basicPayThisPeriod = round(max(0.0, $baseRegularPay - $nonAbsenceDeduction), 2);

        $this->assertSame(0.0, $baseRegularPay);
        $this->assertSame(0.0, $basicPayThisPeriod);
    }

    public function test_fixed_semi_monthly_present_day_base_excludes_absence_from_payable_deductions(): void
    {
        $semiMonthlyGross = 10000.0;
        $dailyRate = 769.23;
        $presentUnits = 11.0;
        $lateDeduction = 192.32;
        $presentBase = round(min($semiMonthlyGross, $presentUnits * $dailyRate), 2);
        $netBasic = round(max(0.0, $presentBase - $lateDeduction), 2);

        $this->assertSame(8461.53, $presentBase);
        $this->assertSame(8269.21, $netBasic);
    }

    public function test_present_day_cap_excludes_unpaid_leave_from_payable_deductions(): void
    {
        $service = app(PayrollComputationService::class);
        $nonAbsenceMethod = new \ReflectionMethod($service, 'sumFixedRegularNonAbsenceAttendanceDeduction');
        $nonAbsenceMethod->setAccessible(true);

        $breakdown = [
            'rows' => [
                [
                    'key' => 'unpaid_leave',
                    'deduction_amount' => 1650.0,
                ],
                [
                    'key' => 'late',
                    'deduction_amount' => 0.0,
                ],
            ],
        ];

        $this->assertSame(0.0, $nonAbsenceMethod->invoke($service, $breakdown));

        $payslipService = app(\App\Services\PayslipService::class);
        $displayMethod = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $displayMethod->setAccessible(true);

        $semiMonthlyGross = 7150.0;
        $dailyRate = 550.0;
        $presentUnits = 11.0;
        $presentDayBasePay = round($presentUnits * $dailyRate, 2);
        $unpaidLeaveDeduction = 1650.0;

        $summary = [
            'regular_fixed_semi_monthly_payroll' => true,
            'regular_fixed_present_day_cap_applied' => true,
            'fixed_semi_monthly_basic_gross' => $semiMonthlyGross,
            'regular_pay_present_day_units' => $presentUnits,
            'regular_fixed_present_day_base_pay' => $presentDayBasePay,
            'daily_rate' => $dailyRate,
            'basic_pay_this_period' => round($presentDayBasePay - $unpaidLeaveDeduction, 2),
            'attendance_deduction' => $unpaidLeaveDeduction,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => round($presentDayBasePay - $unpaidLeaveDeduction, 2),
                'display_amount' => $presentDayBasePay,
                'units' => '11 days',
                'metadata' => [
                    'regular_fixed_semi_monthly_payroll' => true,
                    'regular_fixed_present_day_cap_applied' => true,
                ],
            ]],
        ];
        $attendanceBreakdown = [
            'available' => true,
            'scheduled_days_count' => 14,
            'rows' => [
                [
                    'key' => 'unpaid_leave',
                    'label' => 'Unpaid leave',
                    'deduction_amount' => $unpaidLeaveDeduction,
                    'details' => '3 days',
                ],
            ],
            'total_deduction' => $unpaidLeaveDeduction,
        ];

        $updated = $displayMethod->invoke(
            $payslipService,
            $summary,
            $attendanceBreakdown,
            $summary['daily_computation_earning_lines'][0]
        );

        $this->assertSame($presentDayBasePay, (float) ($updated['daily_computation_earning_lines'][0]['display_amount'] ?? 0));
        $this->assertSame(
            $presentDayBasePay,
            (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0)
        );
        $this->assertSame(0.0, (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0));
    }

    public function test_fixed_semi_monthly_display_amount_uses_present_day_base_when_absence_applies(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $method = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $method->setAccessible(true);

        $summary = [
            'regular_fixed_semi_monthly_payroll' => true,
            'fixed_semi_monthly_basic_gross' => 10000.0,
            'regular_pay_present_day_units' => 11.0,
            'daily_rate' => 769.23,
            'basic_pay_this_period' => 7499.99,
            'attendance_deduction' => 2500.01,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => 7499.99,
                'display_amount' => 7499.99,
                'units' => '11 days',
                'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
            ]],
        ];
        $breakdown = [
            'rows' => [
                [
                    'key' => 'late',
                    'label' => 'Late',
                    'deduction_amount' => 192.32,
                    'details' => '2 hrs',
                ],
                [
                    'key' => 'absence',
                    'label' => 'Absence',
                    'deduction_amount' => 2307.69,
                    'details' => '3 days',
                ],
            ],
            'total_deduction' => 2500.01,
        ];

        $updated = $method->invoke($payslipService, $summary, $breakdown, $summary['daily_computation_earning_lines'][0]);
        $line = $updated['daily_computation_earning_lines'][0];

        $this->assertSame(8461.53, (float) ($line['display_amount'] ?? 0));
        $this->assertSame(8269.21, (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0));
        $this->assertSame(192.32, (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0));
    }

    public function test_near_full_cutoff_display_uses_present_day_base_when_absence_applies(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $method = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $method->setAccessible(true);

        $summary = [
            'regular_fixed_semi_monthly_payroll' => true,
            'fixed_semi_monthly_basic_gross' => 8000.0,
            'regular_pay_present_day_units' => 13.0,
            'regular_fixed_present_day_base_pay' => 7999.94,
            'daily_rate' => 615.38,
            'basic_pay_this_period' => 7384.62,
            'attendance_deduction' => 615.38,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => 7384.62,
                'display_amount' => 7384.62,
                'units' => '13 days',
                'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
            ]],
            'attendance_pay_breakdown' => [
                'scheduled_days_count' => 14,
            ],
        ];
        $breakdown = [
            'scheduled_days_count' => 14,
            'rows' => [[
                'key' => 'absence',
                'label' => 'Absence',
                'deduction_amount' => 615.38,
                'details' => '1 day',
            ]],
            'total_deduction' => 615.38,
        ];

        $updated = $method->invoke($payslipService, $summary, $breakdown, $summary['daily_computation_earning_lines'][0]);
        $line = $updated['daily_computation_earning_lines'][0];

        $this->assertSame(7999.94, (float) ($line['display_amount'] ?? 0));
        $this->assertSame(7384.62, (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0));
        $this->assertSame(615.38, (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0));
    }

    public function test_present_day_cap_display_shows_base_pay_with_late_deducted_separately(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $method = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $method->setAccessible(true);

        $summary = [
            'regular_fixed_semi_monthly_payroll' => true,
            'regular_fixed_present_day_cap_applied' => true,
            'fixed_semi_monthly_basic_gross' => 8250.0,
            'regular_pay_present_day_units' => 12.0,
            'regular_fixed_present_day_base_pay' => 7615.44,
            'daily_rate' => 634.62,
            'basic_pay_this_period' => 7496.46,
            'attendance_deduction' => 118.98,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => 7496.46,
                'display_amount' => 7496.46,
                'units' => '12 days',
                'metadata' => [
                    'regular_fixed_semi_monthly_payroll' => true,
                    'regular_fixed_present_day_cap_applied' => true,
                ],
            ]],
        ];
        $breakdown = [
            'rows' => [[
                'key' => 'late',
                'label' => 'Late',
                'deduction_amount' => 118.98,
                'details' => '1 hr 30 mins',
            ]],
            'total_deduction' => 118.98,
        ];

        $updated = $method->invoke($payslipService, $summary, $breakdown, $summary['daily_computation_earning_lines'][0]);
        $line = $updated['daily_computation_earning_lines'][0];

        $this->assertSame(7615.44, (float) ($line['display_amount'] ?? 0));
        $this->assertSame(7496.46, (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0));
        $this->assertSame(118.98, (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0));
    }

    public function test_paid_leave_split_with_absence_deducts_absence_from_regular_pay_after_reductions(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $method = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $method->setAccessible(true);

        $semiMonthlyGross = 12500.0;
        $dailyRate = 961.54;
        $presentUnits = 12.5;
        $paidLeaveDisplay = 480.77;
        $absenceDeduction = 961.54;
        $presentDayBasePay = round(min($semiMonthlyGross, $presentUnits * $dailyRate), 2);
        $regularLineNet = round($presentDayBasePay - $paidLeaveDisplay, 2);

        $summary = [
            'regular_fixed_semi_monthly_payroll' => true,
            'fixed_semi_monthly_basic_gross' => $semiMonthlyGross,
            'regular_pay_present_day_units' => $presentUnits,
            'daily_rate' => $dailyRate,
            'basic_pay_this_period' => $presentDayBasePay,
            'regular_fixed_paid_leave_amount' => $paidLeaveDisplay,
            'regular_fixed_paid_leave_day_units' => 0.5,
            'attendance_deduction' => 0.0,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => $regularLineNet,
                'display_amount' => $presentDayBasePay,
                'units' => '12.5 days',
                'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
            ]],
        ];
        $breakdown = [
            'available' => true,
            'scheduled_days_count' => 14,
            'rows' => [
                [
                    'key' => 'half_day',
                    'label' => 'Half day',
                    'deduction_amount' => 0.0,
                    'details' => '4 hrs',
                ],
                [
                    'key' => 'absence',
                    'label' => 'Absence',
                    'deduction_amount' => $absenceDeduction,
                    'details' => '1 day',
                ],
            ],
            'total_deduction' => $absenceDeduction,
        ];

        $updated = $method->invoke($payslipService, $summary, $breakdown, $summary['daily_computation_earning_lines'][0]);
        $line = $updated['daily_computation_earning_lines'][0];

        $this->assertSame($presentDayBasePay, (float) ($line['display_amount'] ?? 0));
        $this->assertSame(
            round($presentDayBasePay - $absenceDeduction, 2),
            (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0)
        );
        $this->assertSame(
            $absenceDeduction,
            (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0)
        );
    }

    public function test_paid_leave_split_with_absence_display_net_uses_headline_attendance_logic(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $regularAmount = 11538.48;
        $paidLeave = 480.77;
        $allowance = 15000.0;
        $deductions = 3963.36;
        $expectedNet = 22575.12;

        $snapshot = [
            'daily_rate' => 961.54,
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => 12500.0,
                'semi_monthly_basic_salary' => 12500.0,
                'basic_pay_this_period' => 12019.25,
                'regular_fixed_paid_leave_amount' => $paidLeave,
                'regular_fixed_paid_leave_day_units' => 0.5,
                'regular_pay_present_day_units' => 12.5,
                'daily_rate' => 961.54,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'regular_pay_after_reductions' => 11057.71,
                    'total_deduction' => 961.54,
                    'scheduled_days_count' => 14,
                    'rows' => [
                        [
                            'key' => 'half_day',
                            'label' => 'Half day',
                            'deduction_amount' => 0.0,
                            'details' => '4 hrs',
                        ],
                        [
                            'key' => 'absence',
                            'label' => 'Absence',
                            'deduction_amount' => 961.54,
                            'details' => '1 day',
                        ],
                    ],
                ],
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => $regularAmount,
                        'display_amount' => 12019.25,
                        'units' => '12.5 days',
                        'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
                    ],
                    [
                        'key' => 'daily:paid_leave',
                        'label' => 'Leave adjustments',
                        'amount' => $paidLeave,
                        'display_amount' => $paidLeave,
                        'units' => '0.5 days',
                        'metadata' => [
                            'included_in_fixed_semi_monthly_basic' => true,
                            'leave_day_units' => 0.5,
                        ],
                    ],
                ],
                'payslip_earning_lines' => [[
                    'key' => 'pay_component:22',
                    'label' => 'ALLOWANCE EVERY 15 ONLY',
                    'amount' => $allowance,
                ]],
                'payslip_deduction_lines' => [[
                    'key' => 'deduction:statutory',
                    'label' => 'Statutory',
                    'amount' => $deductions,
                ]],
            ],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);
        $summary = $normalized['summary'];

        $this->assertSame($expectedNet, round((float) ($summary['display_net_pay'] ?? 0), 2));

        $payslip = new \App\Models\Payslip([
            'status' => \App\Models\Payslip::STATUS_FINALIZED,
            'gross_pay' => round($regularAmount + $paidLeave + $allowance, 2),
            'total_deductions' => $deductions,
            'net_pay' => $expectedNet,
            'snapshot' => $snapshot,
        ]);

        $displayTotals = $payslipService->payslipDisplayTotalsFromSnapshot($snapshot);
        $this->assertSame($expectedNet, round((float) ($displayTotals['net_pay'] ?? 0), 2));
    }

    public function test_finalized_rest_day_split_display_net_matches_payslip_ui(): void
    {
        $snapshot = json_decode(
            file_get_contents(__DIR__.'/Fixtures/telebrico_18790_snapshot.json'),
            true
        );
        if (! is_array($snapshot)) {
            $this->markTestSkipped('Telebrico fixture snapshot unavailable.');
        }

        $payslipService = app(\App\Services\PayslipService::class);
        $display = $payslipService->payslipDisplayTotalsFromSnapshot($snapshot);

        $this->assertEqualsWithDelta(5073.5, (float) $display['gross_pay'], 0.02);
        $this->assertEqualsWithDelta(5073.5, (float) $display['net_pay'], 0.02);
    }

    public function test_display_net_uses_headline_regular_when_refund_line_is_present(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);
        $regularAmount = 8264.45;
        $refund = 692.3;
        $expectedNet = 8956.75;

        $snapshot = [
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'regular_pay_after_reductions' => $regularAmount,
                    'total_deduction' => 43.27,
                ],
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => $regularAmount,
                    'display_amount' => 8307.72,
                    'units' => '12 days',
                    'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
                ]],
                'payslip_earning_lines' => [[
                    'key' => 'refund_basic_pay',
                    'label' => 'Refund',
                    'amount' => $refund,
                    'component_code' => 'refund_basic_pay',
                ]],
            ],
        ];

        $normalized = $payslipService->normalizeSnapshotForPayslipView($snapshot);

        $this->assertSame($expectedNet, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));
    }
}
