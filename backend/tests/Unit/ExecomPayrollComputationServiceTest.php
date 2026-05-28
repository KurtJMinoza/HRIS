<?php

namespace Tests\Unit;

use App\Models\ExecomEmployeeProfile;
use App\Models\ExecomPayrollSetting;
use App\Models\User;
use App\Services\DeductionApplicationService;
use App\Services\DeductionScheduleService;
use App\Services\ExecomPayrollComputationService;
use App\Services\PayrollCalculatorService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ExecomPayrollComputationServiceTest extends TestCase
{
    public function test_absent_execom_employee_receives_full_fixed_salary_without_attendance_impact(): void
    {
        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'earnings' => [],
            'deductions' => [],
            'totals' => [],
        ]);
        $calculator->method('calculateAllStatutoryContributions')->willReturn([
            'totals' => [
                'employee_deduction' => 0.0,
                'employer_contribution' => 0.0,
            ],
        ]);
        $calculator->method('calculateWithholdingTax')->willReturn([
            'withholding_per_month' => 0.0,
        ]);
        $calculator->method('monthlyTaxableCompensationForWithholding')->willReturn(125000.00);
        $calculator->method('mergeEmployeeTaxProfileIntoWithholdingParams')->willReturnArgument(1);
        $schedule = $this->createMock(DeductionScheduleService::class);
        $schedule->method('summarizeForPayrollComputation')->willReturn([
            'government' => [],
            'custom_lines' => [],
            'earning_lines' => [
                [
                    'code' => 'BASIC_SALARY',
                    'name' => 'Basic Pay',
                    'is_basic_salary_line' => true,
                    'scheduled_this_period' => 125000.00,
                    'earning_schedule_type' => 'both',
                ],
            ],
            'employee_statutory_this_period' => 0.0,
            'withholding_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'custom_deductions_full_monthly' => 0.0,
        ]);
        $schedule->method('buildPayslipEarningDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipDeductionDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipCustomDeductionDisplayLines')->willReturn([]);
        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $deductionApplication->method('enforcePriorityAndLegalLimitsForPayrollPeriod')->willReturn([
            'custom_lines' => [],
            'custom_deductions_this_period' => 0.0,
            'legal_warnings' => [],
            'minimum_take_home_floor' => 0.0,
        ]);

        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);
        $employee = (new User)->forceFill(['id' => 1001, 'name' => 'EXECOM Employee']);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 10,
            'employee_id' => 1001,
            'company_id' => 1,
            'fixed_salary' => 125000.00,
            'is_active' => true,
        ]);
        $settings = new ExecomPayrollSetting([
            ...ExecomPayrollSetting::defaults(1),
            'apply_government_deductions' => false,
            'apply_custom_deductions' => false,
            'apply_allowances' => false,
        ]);

        $computed = $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-15'),
            $profile,
            $settings
        );

        $summary = $computed['summary'];
        $this->assertSame('execom', $summary['payroll_module']);
        $this->assertSame(125000.00, $summary['fixed_salary']);
        $this->assertSame(125000.00, $summary['basic_salary_period']);
        $this->assertSame(125000.00, $summary['basic_salary']);
        $this->assertSame(125000.00, data_get($summary, 'compensation_breakdown.basic_salary'));
        $this->assertSame('Basic Pay', $summary['payslip_earning_lines'][0]['label']);
        $this->assertSame(125000.00, $summary['gross_pay_this_period']);
        $this->assertSame(125000.00, $summary['net_pay_after_withholding_estimate']);
        $this->assertSame(4807.69, $computed['daily_rate']);
        $this->assertSame(26, $computed['daily_rate_divisor_days']);
        $this->assertSame(4807.69, $summary['daily_rate']);
        $this->assertSame(15.0, $summary['actual_days_worked']);
        $this->assertSame(0, $summary['total_worked_minutes']);
        $this->assertSame(0.0, $summary['overtime_total_amount']);
        $this->assertSame([], $summary['holiday_premium_breakdown']);
        $this->assertSame('Auto Present', $computed['days'][0]['attendance_status']);
        $this->assertSame('Auto Present', $computed['days'][0]['status_label']);
        $this->assertSame('Auto Present', $summary['attendance_display_summary']['attendance_status']);
        $this->assertSame('Auto Present', $summary['attendance_display_summary']['status_label']);
        $this->assertSame(15, $summary['attendance_display_summary']['presence_days_count']);
        $this->assertSame(0, $summary['attendance_display_summary']['absent_days']);
        $this->assertSame(0, $summary['absent_days']);
        $this->assertSame(0, $summary['late_minutes']);
        $this->assertSame(0, $summary['undertime_minutes']);
        $this->assertSame(0.0, $summary['attendance_deduction']);
        $this->assertSame(0.0, $summary['leave_deduction']);
        $this->assertSame(0.0, $summary['attendance_display_summary']['payroll_impact_deduction']);
    }

    public function test_execom_uses_regular_deduction_schedule_lines_with_fixed_salary_source(): void
    {
        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'earnings' => [
                ['code' => 'ALLOWANCE', 'name' => 'Allowance', 'computed_amount' => 4000.00],
            ],
            'deductions' => [
                ['code' => 'LOAN', 'name' => 'Loan', 'computed_amount' => 3000.00],
            ],
            'totals' => [],
        ]);
        $calculator->method('calculateAllStatutoryContributions')->with(20000.00)->willReturn([
            'sss' => ['employee_amount' => 600.00],
            'philhealth' => ['employee_amount' => 400.00],
            'pagibig' => ['employee_amount' => 100.00],
            'totals' => [
                'employee_deduction' => 1100.00,
                'employer_liability' => 1200.00,
            ],
        ]);
        $calculator->method('monthlyTaxableCompensationForWithholding')->willReturn(18900.00);
        $calculator->method('mergeEmployeeTaxProfileIntoWithholdingParams')->willReturnArgument(1);
        $calculator->method('calculateWithholdingTax')->willReturn([
            'withholding_per_month' => 1200.00,
        ]);

        $schedule = $this->createMock(DeductionScheduleService::class);
        $schedule->method('summarizeForPayrollComputation')->willReturn([
            'government' => ['lines' => []],
            'custom_lines' => [
                ['name' => 'FAMES EVERY 15', 'scheduled_this_period' => 1500.00, 'deduction_schedule_type' => '15th', 'payroll_run_type' => '15th'],
                ['name' => 'FAMES EVERY 30', 'scheduled_this_period' => 0.00, 'deduction_schedule_type' => '30th', 'payroll_run_type' => '15th'],
            ],
            'earning_lines' => [
                ['code' => 'BASIC_SALARY', 'name' => 'Basic Pay', 'is_basic_salary_line' => true, 'scheduled_this_period' => 10000.00],
                ['name' => 'Allowance', 'scheduled_this_period' => 2000.00],
            ],
            'employee_statutory_this_period' => 1100.00,
            'withholding_this_period' => 600.00,
            'custom_deductions_this_period' => 1500.00,
            'custom_deductions_full_monthly' => 3000.00,
        ]);
        $schedule->method('buildPayslipEarningDisplayLines')->willReturn([
            ['key' => 'pay_component:1', 'component_code' => 'BASIC_SALARY', 'label' => 'Basic Salary', 'amount' => 10000.00],
            ['key' => 'pay_component:10', 'label' => 'Allowance', 'amount' => 2000.00],
        ]);
        $schedule->method('buildPayslipDeductionDisplayLines')->willReturn([
            ['key' => 'SSS', 'label' => 'SSS', 'amount' => 600.00],
            ['key' => 'PHILHEALTH', 'label' => 'PhilHealth', 'amount' => 400.00],
            ['key' => 'PAGIBIG', 'label' => 'Pag-IBIG', 'amount' => 100.00],
            ['key' => 'WITHHOLDING_TAX', 'label' => 'Withholding tax', 'amount' => 600.00],
        ]);
        $schedule->expects($this->once())
            ->method('buildPayslipCustomDeductionDisplayLines')
            ->with($this->callback(function (array $lines): bool {
                return count($lines) === 2
                    && ($lines[0]['name'] ?? '') === 'FAMES EVERY 15'
                    && (float) ($lines[0]['applied_this_period'] ?? 0) === 1500.00
                    && ($lines[1]['name'] ?? '') === 'FAMES EVERY 30'
                    && (float) ($lines[1]['applied_this_period'] ?? 0) === 0.00;
            }))
            ->willReturn([
                ['key' => 'pay_component:20', 'label' => 'FAMES EVERY 15', 'amount' => 1500.00],
            ]);
        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $deductionApplication->method('enforcePriorityAndLegalLimitsForPayrollPeriod')->willReturn([
            'custom_lines' => [
                ['name' => 'FAMES EVERY 15', 'scheduled_this_period' => 1500.00, 'applied_this_period' => 1500.00, 'deduction_schedule_type' => '15th', 'payroll_run_type' => '15th'],
                ['name' => 'FAMES EVERY 30', 'scheduled_this_period' => 0.00, 'applied_this_period' => 0.00, 'deduction_schedule_type' => '30th', 'payroll_run_type' => '15th'],
            ],
            'custom_deductions_this_period' => 1500.00,
            'legal_warnings' => [],
            'minimum_take_home_floor' => 0.0,
        ]);

        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);
        $employee = (new User)->forceFill(['id' => 1001, 'name' => 'EXECOM Employee']);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 10,
            'employee_id' => 1001,
            'company_id' => 1,
            'fixed_salary' => 20000.00,
            'pay_schedule' => ExecomEmployeeProfile::PAY_SCHEDULE_MONTHLY_SPLIT,
            'is_active' => true,
        ]);
        $settings = new ExecomPayrollSetting(ExecomPayrollSetting::defaults(1));

        $computed = $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-15'),
            $profile,
            $settings,
            ['company_working_days' => 26]
        );

        $summary = $computed['summary'];
        $this->assertSame(769.23, $summary['daily_rate']);
        $this->assertSame(10000.00, $summary['basic_salary_period']);
        $this->assertSame(12000.00, $summary['gross_pay_this_period']);
        $this->assertSame(3200.00, $summary['total_deductions_this_period']);
        $this->assertSame(8800.00, $summary['net_pay']);
        $this->assertSame(8800.00, $summary['net_pay_after_withholding_estimate']);
        $this->assertSame(4, count($summary['payslip_deduction_lines']));
        $this->assertSame(1, count($summary['payslip_custom_deduction_lines']));
        $this->assertSame('Allowance', $summary['payslip_earning_lines'][1]['label']);
        $this->assertCount(2, $summary['payslip_earning_lines']);
        $this->assertSame('Basic Pay', $summary['payslip_earning_lines'][0]['label']);
        $this->assertSame('FAMES EVERY 15', $summary['payslip_custom_deduction_lines'][0]['label']);
    }

    public function test_execom_falls_back_to_employee_salary_when_profile_fixed_salary_is_empty(): void
    {
        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'earnings' => [],
            'deductions' => [],
            'totals' => [],
        ]);
        $calculator->method('calculateAllStatutoryContributions')->willReturn([
            'totals' => [
                'employee_deduction' => 0.0,
                'employer_liability' => 0.0,
            ],
        ]);
        $calculator->method('monthlyTaxableCompensationForWithholding')->willReturn(30000.00);
        $calculator->method('mergeEmployeeTaxProfileIntoWithholdingParams')->willReturnArgument(1);
        $calculator->method('calculateWithholdingTax')->willReturn([
            'withholding_per_month' => 0.0,
        ]);

        $schedule = $this->createMock(DeductionScheduleService::class);
        $schedule->method('summarizeForPayrollComputation')->willReturn([
            'government' => [],
            'custom_lines' => [],
            'earning_lines' => [
                [
                    'code' => 'BASIC_SALARY',
                    'name' => 'Basic Pay',
                    'is_basic_salary_line' => true,
                    'scheduled_this_period' => 30000.00,
                    'earning_schedule_type' => 'both',
                ],
            ],
            'employee_statutory_this_period' => 0.0,
            'withholding_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'custom_deductions_full_monthly' => 0.0,
        ]);
        $schedule->method('buildPayslipEarningDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipDeductionDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipCustomDeductionDisplayLines')->willReturn([]);

        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $deductionApplication->method('enforcePriorityAndLegalLimitsForPayrollPeriod')->willReturn([
            'custom_lines' => [],
            'custom_deductions_this_period' => 0.0,
            'legal_warnings' => [],
            'minimum_take_home_floor' => 0.0,
        ]);

        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);
        $employee = (new User)->forceFill([
            'id' => 1003,
            'name' => 'EXECOM Employee',
            'monthly_salary' => 30000.00,
        ]);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 12,
            'employee_id' => 1003,
            'company_id' => 1,
            'fixed_salary' => 0.00,
            'pay_schedule' => ExecomEmployeeProfile::PAY_SCHEDULE_PER_PERIOD,
            'is_active' => true,
        ]);

        $computed = $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-15'),
            $profile,
            new ExecomPayrollSetting([
                ...ExecomPayrollSetting::defaults(1),
                'apply_government_deductions' => false,
                'apply_custom_deductions' => false,
                'apply_allowances' => false,
            ]),
            ['company_working_days' => 26]
        );

        $summary = $computed['summary'];
        $this->assertSame(30000.00, $summary['basic_salary_period']);
        $this->assertSame(30000.00, $summary['gross_pay_this_period']);
        $this->assertSame('Basic Pay', $summary['payslip_earning_lines'][0]['label']);
        $this->assertSame(1153.85, $summary['daily_rate']);
    }

    public function test_execom_passes_regular_payroll_period_context_to_deduction_scheduler(): void
    {
        $employee = (new User)->forceFill(['id' => 1002, 'name' => 'EXECOM Scheduled Employee']);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 11,
            'employee_id' => 1002,
            'company_id' => 1,
            'fixed_salary' => 100416.00,
            'pay_schedule' => ExecomEmployeeProfile::PAY_SCHEDULE_PER_PERIOD,
            'is_active' => true,
        ]);

        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'earnings' => [],
            'deductions' => [],
            'totals' => [],
        ]);
        $calculator->method('calculateAllStatutoryContributions')->willReturn([
            'totals' => [
                'employee_deduction' => 0.0,
                'employer_liability' => 0.0,
            ],
        ]);
        $calculator->method('monthlyTaxableCompensationForWithholding')->willReturn(100416.00);
        $calculator->method('mergeEmployeeTaxProfileIntoWithholdingParams')->willReturnArgument(1);
        $calculator->method('calculateWithholdingTax')->willReturn([
            'withholding_per_month' => 0.0,
        ]);

        $schedule = $this->createMock(DeductionScheduleService::class);
        $schedule->expects($this->once())
            ->method('summarizeForPayrollComputation')
            ->with(
                $this->identicalTo($employee),
                $this->callback(fn (Carbon $date): bool => $date->toDateString() === '2026-06-15'),
                $this->callback(function (array $summary): bool {
                    return ($summary['pay_period_start'] ?? null) === '2026-05-26'
                        && ($summary['pay_period_end'] ?? null) === '2026-06-10'
                        && ($summary['selected_pay_date'] ?? null) === '2026-06-15'
                        && data_get($summary, 'pay_cycle_preview.semi_month_segment') === 'first'
                        && ($summary['pay_cycle_code'] ?? null) === 'semi_monthly'
                        && (float) ($summary['basic_salary'] ?? 0) === 100416.00;
                })
            )
            ->willReturn([
                'government' => [],
                'custom_lines' => [],
                'earning_lines' => [
                    [
                        'code' => 'BASIC_SALARY',
                        'name' => 'Basic Pay',
                        'is_basic_salary_line' => true,
                        'scheduled_this_period' => 50208.00,
                        'earning_schedule_type' => 'both',
                    ],
                ],
                'employee_statutory_this_period' => 0.0,
                'withholding_this_period' => 0.0,
                'custom_deductions_this_period' => 0.0,
                'custom_deductions_full_monthly' => 0.0,
            ]);
        $schedule->method('buildPayslipEarningDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipDeductionDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipCustomDeductionDisplayLines')->willReturn([]);

        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $deductionApplication->method('enforcePriorityAndLegalLimitsForPayrollPeriod')->willReturn([
            'custom_lines' => [],
            'custom_deductions_this_period' => 0.0,
            'legal_warnings' => [],
            'minimum_take_home_floor' => 0.0,
        ]);

        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);
        $computed = $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-26'),
            Carbon::parse('2026-06-10'),
            $profile,
            new ExecomPayrollSetting(ExecomPayrollSetting::defaults(1)),
            [
                'pay_period_start' => '2026-05-26',
                'pay_period_end' => '2026-06-10',
                'selected_pay_date' => '2026-06-15',
                'pay_cycle_code' => 'semi_monthly',
                'pay_cycle_preview' => [
                    'pay_date' => '2026-06-15',
                    'semi_month_segment' => 'first',
                ],
            ]
        );

        $this->assertSame(3862.15, $computed['daily_rate']);
        $this->assertSame(50208.00, $computed['summary']['basic_salary_period']);
    }

    public function test_execom_prefers_employee_compensation_salary_when_fixed_salary_is_empty(): void
    {
        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'basic_salary' => 45000.00,
            'earnings' => [
                ['code' => 'BASIC_SALARY', 'name' => 'Basic Pay', 'computed_amount' => 45000.00],
            ],
            'deductions' => [],
            'totals' => [],
        ]);
        $calculator->method('calculateAllStatutoryContributions')->willReturn([
            'totals' => ['employee_deduction' => 0.0, 'employer_liability' => 0.0],
        ]);
        $calculator->method('monthlyTaxableCompensationForWithholding')->willReturn(45000.00);
        $calculator->method('mergeEmployeeTaxProfileIntoWithholdingParams')->willReturnArgument(1);
        $calculator->method('calculateWithholdingTax')->willReturn(['withholding_per_month' => 0.0]);

        $schedule = $this->createMock(DeductionScheduleService::class);
        $schedule->method('summarizeForPayrollComputation')->willReturn([
            'government' => [],
            'custom_lines' => [],
            'earning_lines' => [
                [
                    'code' => 'BASIC_SALARY',
                    'name' => 'Basic Pay',
                    'is_basic_salary_line' => true,
                    'scheduled_this_period' => 22500.00,
                    'earning_schedule_type' => 'both',
                ],
            ],
            'employee_statutory_this_period' => 0.0,
            'withholding_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'custom_deductions_full_monthly' => 0.0,
        ]);
        $schedule->method('buildPayslipEarningDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipDeductionDisplayLines')->willReturn([]);
        $schedule->method('buildPayslipCustomDeductionDisplayLines')->willReturn([]);

        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $deductionApplication->method('enforcePriorityAndLegalLimitsForPayrollPeriod')->willReturn([
            'custom_lines' => [],
            'custom_deductions_this_period' => 0.0,
            'legal_warnings' => [],
            'minimum_take_home_floor' => 0.0,
        ]);

        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);
        $employee = (new User)->forceFill(['id' => 1004, 'name' => 'EXECOM Compensation Employee']);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 13,
            'employee_id' => 1004,
            'company_id' => 1,
            'fixed_salary' => 0.00,
            'is_active' => true,
        ]);

        $computed = $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-15'),
            $profile,
            new ExecomPayrollSetting([
                ...ExecomPayrollSetting::defaults(1),
                'apply_government_deductions' => false,
                'apply_custom_deductions' => false,
                'apply_allowances' => false,
            ]),
            ['company_working_days' => 26]
        );

        $summary = $computed['summary'];
        $this->assertSame('employee_compensation_basic_salary', $summary['execom_salary_source_used']);
        $this->assertSame(45000.00, $summary['fixed_salary']);
        $this->assertSame(22500.00, $summary['basic_salary_period']);
        $this->assertSame('Basic Pay', $summary['payslip_earning_lines'][0]['label']);
    }

    public function test_execom_throws_when_all_salary_sources_are_empty(): void
    {
        $calculator = $this->createMock(PayrollCalculatorService::class);
        $calculator->method('buildEmployeeCompensationSummary')->willReturn([
            'basic_salary' => 0.0,
            'earnings' => [],
            'deductions' => [],
            'totals' => [],
        ]);

        $schedule = $this->createMock(DeductionScheduleService::class);
        $deductionApplication = $this->createMock(DeductionApplicationService::class);
        $service = new ExecomPayrollComputationService($calculator, $schedule, $deductionApplication);

        $employee = (new User)->forceFill(['id' => 1005, 'name' => 'No Salary EXECOM']);
        $profile = (new ExecomEmployeeProfile)->forceFill([
            'id' => 14,
            'employee_id' => 1005,
            'company_id' => 1,
            'fixed_salary' => 0.00,
            'is_active' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no salary source');

        $service->computeExecomPayroll(
            $employee,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-15'),
            $profile,
            new ExecomPayrollSetting(ExecomPayrollSetting::defaults(1))
        );
    }
}
