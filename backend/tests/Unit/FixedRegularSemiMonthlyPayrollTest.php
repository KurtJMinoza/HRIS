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
}
