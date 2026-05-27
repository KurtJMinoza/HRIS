<?php

namespace App\Services;

use App\Models\ExecomEmployeeProfile;
use App\Models\ExecomPayrollSetting;
use App\Models\User;
use Carbon\Carbon;

class ExecomPayrollComputationService
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly DeductionApplicationService $deductionApplicationService,
    ) {}

    /**
     * Fixed-salary EXECOM computation. It intentionally does not read attendance logs,
     * leaves, holidays, late/undertime, absences, night differential, or daily premiums.
     *
     * @param  array<string, mixed>  $periodContext
     * @return array<string, mixed>
     */
    public function computeExecomPayroll(
        User $employee,
        Carbon $from,
        Carbon $to,
        ExecomEmployeeProfile $profile,
        ?ExecomPayrollSetting $settings = null,
        array $periodContext = [],
    ): array {
        $settings ??= ExecomPayrollSetting::forCompany($profile->company_id ? (int) $profile->company_id : null);
        $salaryBasis = $this->resolvePeriodFixedSalary($profile, $employee, $from, $to);
        $fixedSalary = (float) $salaryBasis['amount'];
        $monthlyFixedSalary = (float) $salaryBasis['monthly_fixed_salary'];
        $workingDays = $this->resolveCompanyWorkingDays($periodContext);
        $dailyRate = $workingDays > 0 ? round($monthlyFixedSalary / $workingDays, 2) : 0.0;
        $compensation = $this->calculator->buildEmployeeCompensationSummary($employee, [
            'as_of_date' => $to->toDateString(),
            'proration_factor' => 1.0,
            'hours_worked' => 0.0,
            'include_deduction_schedule_catalog' => false,
            'cache' => false,
        ]);
        $statutoryMonthly = $this->calculator->calculateAllStatutoryContributions($monthlyFixedSalary, [
            'sss' => $monthlyFixedSalary,
            'philhealth' => $monthlyFixedSalary,
            'pagibig' => $monthlyFixedSalary,
        ]);
        $employeeStatutoryMonthly = (bool) $settings->apply_government_deductions
            ? round((float) data_get($statutoryMonthly, 'totals.employee_deduction', 0), 2)
            : 0.0;
        $monthlyTaxableBase = $this->calculator->monthlyTaxableCompensationForWithholding(
            $monthlyFixedSalary,
            (bool) $settings->apply_government_deductions ? $statutoryMonthly : []
        );
        $withholding = $this->calculator->calculateWithholdingTax(
            $this->calculator->mergeEmployeeTaxProfileIntoWithholdingParams($employee, [
                'monthly_taxable_compensation' => $monthlyTaxableBase,
                'withholding_base_is_net_of_mandatory' => true,
                'withholding_gross_taxable_monthly' => $monthlyFixedSalary,
                'withholding_employee_mandatory_monthly' => $employeeStatutoryMonthly,
                'method' => 'annualized',
                'period_type' => 'monthly',
            ])
        );
        $withholdingMonthly = (bool) $settings->apply_government_deductions
            ? round((float) ($withholding['withholding_per_month'] ?? 0), 2)
            : 0.0;
        $compensationForSchedule = $this->compensationSummaryWithExecomSalary(
            $compensation,
            $monthlyFixedSalary,
            (bool) $settings->apply_government_deductions ? $statutoryMonthly : [],
            $withholding,
            $withholdingMonthly,
            $periodContext,
            $from,
            $to
        );
        $deductionSchedule = $this->deductionScheduleService->summarizeForPayrollComputation(
            $employee,
            $this->resolveScheduleReferenceDate($periodContext, $to),
            $compensationForSchedule
        );

        $earningLines = [[
            'key' => 'execom_fixed_salary',
            'label' => 'Basic Salary',
            'name' => 'Basic Salary',
            'category' => 'basic_salary',
            'component_code' => 'BASIC_SALARY',
            'amount' => $fixedSalary,
            'resolved_amount' => $fixedSalary,
            'full_monthly' => $monthlyFixedSalary,
            'schedule_type' => (string) $salaryBasis['basis'],
            'metadata' => [
                'payroll_module' => 'execom',
                'source' => 'execom_fixed_salary',
            ],
        ]];

        $allowanceLines = (bool) $settings->apply_allowances
            ? $this->deductionScheduleService->buildPayslipEarningDisplayLines(
                is_array($deductionSchedule['earning_lines'] ?? null) ? $deductionSchedule['earning_lines'] : []
            )
            : [];
        $allowanceTotal = 0.0;
        if ((bool) $settings->apply_allowances) {
            foreach ($allowanceLines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if ($this->isBasicSalaryLine($line)) {
                    continue;
                }
                $amount = round(max(0.0, (float) ($line['amount'] ?? 0)), 2);
                if ($amount <= 0.0) {
                    continue;
                }
                $allowanceTotal += $amount;
                $earningLines[] = array_merge($line, [
                    'key' => (string) ($line['key'] ?? 'execom_allowance_'.count($earningLines)),
                    'label' => (string) ($line['label'] ?? $line['name'] ?? 'Allowance'),
                    'name' => (string) ($line['name'] ?? $line['label'] ?? 'Allowance'),
                    'category' => 'allowance',
                    'amount' => $amount,
                    'resolved_amount' => $amount,
                ]);
            }
        }

        $grossPay = round($fixedSalary + $allowanceTotal, 2);
        $deductionLines = (bool) $settings->apply_government_deductions
            ? $this->deductionScheduleService->buildPayslipDeductionDisplayLines(
                is_array($deductionSchedule['government'] ?? null) ? $deductionSchedule['government'] : [],
                $withholdingMonthly
            )
            : [];
        $employeeStatutory = (bool) $settings->apply_government_deductions
            ? round((float) ($deductionSchedule['employee_statutory_this_period'] ?? 0), 2)
            : 0.0;
        $withholdingThisPeriod = (bool) $settings->apply_government_deductions
            ? round((float) ($deductionSchedule['withholding_this_period'] ?? 0), 2)
            : 0.0;

        $phase3Deduction = $this->deductionApplicationService->enforcePriorityAndLegalLimitsForPayrollPeriod(
            $employee,
            (bool) $settings->apply_custom_deductions && is_array($deductionSchedule['custom_lines'] ?? null)
                ? $deductionSchedule['custom_lines']
                : [],
            $grossPay,
            $employeeStatutory,
            $withholdingThisPeriod,
            $from,
            $to,
            null
        );
        $deductionSchedule['custom_lines'] = $phase3Deduction['custom_lines'];
        $deductionSchedule['custom_deductions_this_period'] = $phase3Deduction['custom_deductions_this_period'];
        $deductionSchedule['legal_warnings'] = $phase3Deduction['legal_warnings'];
        $deductionSchedule['minimum_take_home_floor'] = $phase3Deduction['minimum_take_home_floor'];
        $customDeductions = (bool) $settings->apply_custom_deductions
            ? round((float) ($phase3Deduction['custom_deductions_this_period'] ?? 0), 2)
            : 0.0;
        $customDeductionLines = (bool) $settings->apply_custom_deductions
            ? $this->deductionScheduleService->buildPayslipCustomDeductionDisplayLines(
                is_array($deductionSchedule['custom_lines'] ?? null) ? $deductionSchedule['custom_lines'] : []
            )
            : [];
        if ((bool) $settings->apply_custom_deductions) {
            foreach ($customDeductionLines as &$line) {
                $line['category'] = (string) ($line['category'] ?? 'deduction');
            }
            unset($line);
        }

        $totalDeductions = round($employeeStatutory + $withholdingThisPeriod + $customDeductions, 2);
        $netPay = round($grossPay - $totalDeductions, 2);
        $days = $this->autoPresentDays($from, $to, (bool) $settings->auto_present_attendance_reports);
        $autoPresentCount = count($days);

        return [
            'user_id' => $employee->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'daily_rate' => $dailyRate,
            'daily_rate_divisor_days' => $workingDays,
            'basic_salary_used' => $monthlyFixedSalary,
            'days' => $days,
            'summary' => [
                'payroll_module' => 'execom',
                'execom_badge' => true,
                'execom_profile_id' => (int) $profile->id,
                'fixed_salary' => $monthlyFixedSalary,
                'basic_salary' => $fixedSalary,
                'basic_salary_period' => $fixedSalary,
                'total_pay' => $fixedSalary,
                'basic_pay_this_period' => $fixedSalary,
                'basic_salary_schedule_type' => 'execom_'.$salaryBasis['basis'],
                'basic_salary_schedule_factor' => (float) $salaryBasis['factor'],
                'execom_salary_basis' => (string) $salaryBasis['basis'],
                'attendance_status' => 'Auto Present',
                'absent_days' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'attendance_deduction' => 0.0,
                'leave_deduction' => 0.0,
                'attendance_premium_pay_this_period' => 0.0,
                'gross_pay_this_period' => $grossPay,
                'total_deductions_this_period' => $totalDeductions,
                'actual_days_worked' => (float) $autoPresentCount,
                'daily_rate' => $dailyRate,
                'daily_rate_divisor_days' => $workingDays,
                'employee_statutory_total' => $employeeStatutoryMonthly,
                'employee_statutory_this_period' => $employeeStatutory,
                'employer_statutory_total' => (bool) $settings->apply_government_deductions
                    ? round((float) data_get($statutoryMonthly, 'totals.employer_liability', data_get($statutoryMonthly, 'totals.employer_contribution', 0)), 2)
                    : 0.0,
                'custom_deductions_full_monthly' => (bool) $settings->apply_custom_deductions
                    ? round((float) ($deductionSchedule['custom_deductions_full_monthly'] ?? $customDeductions), 2)
                    : 0.0,
                'custom_deductions_this_period' => round($customDeductions, 2),
                'net_pay' => $netPay,
                'withholding_tax_monthly_estimate' => $withholdingMonthly,
                'withholding_tax_this_period_estimate' => $withholdingThisPeriod,
                'withholding_breakdown' => $withholding,
                'net_pay_after_withholding_estimate' => $netPay,
                'statutory_breakdown' => (bool) $settings->apply_government_deductions ? $statutoryMonthly : [],
                'compensation_breakdown' => array_merge($compensation, [
                    'basic_salary' => $fixedSalary,
                    'basic_salary_period' => $fixedSalary,
                    'monthly_salary' => $monthlyFixedSalary,
                    'fixed_salary' => $monthlyFixedSalary,
                    'payroll_module' => 'execom',
                    'tax_classification' => [
                        'taxable_total' => $grossPay,
                        'non_taxable_total' => 0.0,
                        'gross_total' => $grossPay,
                    ],
                ]),
                'deduction_schedule' => $deductionSchedule,
                'legal_warnings' => $phase3Deduction['legal_warnings'],
                'minimum_take_home_floor' => $phase3Deduction['minimum_take_home_floor'],
                'non_basic_earnings_this_period' => round($allowanceTotal, 2),
                'payslip_deduction_lines' => $deductionLines,
                'payslip_custom_deduction_lines' => $customDeductionLines,
                'payslip_earning_lines' => $earningLines,
                'daily_computation_earning_lines' => [],
                'attendance_display_summary' => [
                    'attendance_status' => (bool) $settings->auto_present_attendance_reports ? 'Auto Present' : 'EXECOM Payroll',
                    'status_label' => (bool) $settings->auto_present_attendance_reports ? 'Auto Present' : 'EXECOM Payroll',
                    'working_days_count' => $autoPresentCount,
                    'presence_days_count' => $autoPresentCount,
                    'lines' => $this->autoPresentAttendanceDisplayLines($days),
                    'total_regular_hours' => 0.0,
                    'total_presence_regular_hours' => 0.0,
                    'absent_days' => 0,
                    'absent_days_count' => 0,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'leave_deduction' => 0.0,
                    'unpaid_leave_days_count' => 0,
                    'payroll_impact' => 0.0,
                    'payroll_impact_deduction' => 0.0,
                    'payroll_note' => 'EXECOM employees are treated as present for payroll; fixed salary is independent from attendance logs.',
                ],
                'holiday_premium_breakdown' => [],
                'total_worked_minutes' => 0,
                'total_regular_day_minutes' => 0,
                'total_regular_night_minutes' => 0,
                'total_ot_day_minutes' => 0,
                'total_ot_night_minutes' => 0,
                'overtime_breakdown' => [],
                'overtime_total_hours' => 0.0,
                'overtime_total_amount' => 0.0,
                'attendance_proration' => [
                    'factor' => 1.0,
                    'source' => 'execom_fixed_salary',
                ],
                'execom_settings' => [
                    'apply_government_deductions' => (bool) $settings->apply_government_deductions,
                    'apply_custom_deductions' => (bool) $settings->apply_custom_deductions,
                    'apply_allowances' => (bool) $settings->apply_allowances,
                    'allow_overtime' => (bool) $settings->allow_overtime,
                    'allow_holiday_pay' => (bool) $settings->allow_holiday_pay,
                    'auto_present_attendance_reports' => (bool) $settings->auto_present_attendance_reports,
                ],
            ],
        ];
    }

    /**
     * @return int<1, max>
     */
    private function resolveCompanyWorkingDays(array $periodContext): int
    {
        foreach (['company_working_days', 'working_days_per_month', 'daily_rate_divisor_days'] as $key) {
            if (isset($periodContext[$key]) && is_numeric($periodContext[$key])) {
                return max(1, (int) round((float) $periodContext[$key]));
            }
        }

        try {
            return max(1, (int) config('payroll.execom_working_days_per_month', 26));
        } catch (\Throwable) {
            return 26;
        }
    }

    private function resolveScheduleReferenceDate(array $periodContext, Carbon $fallback): Carbon
    {
        if (! empty($periodContext['selected_pay_date'])) {
            return Carbon::parse((string) $periodContext['selected_pay_date'])->startOfDay();
        }

        return $fallback->copy()->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $compensation
     * @param  array<string, mixed>  $statutory
     * @param  array<string, mixed>  $withholding
     * @param  array<string, mixed>  $periodContext
     * @return array<string, mixed>
     */
    private function compensationSummaryWithExecomSalary(
        array $compensation,
        float $monthlyFixedSalary,
        array $statutory,
        array $withholding,
        float $withholdingMonthly,
        array $periodContext,
        Carbon $from,
        Carbon $to
    ): array {
        $earnings = [];
        $hasBasic = false;
        foreach ((array) ($compensation['earnings'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $code = strtoupper(trim((string) ($line['code'] ?? '')));
            if ($code === 'BASIC_SALARY') {
                $hasBasic = true;
                $line['computed_amount'] = $monthlyFixedSalary;
                $line['configured_value'] = $monthlyFixedSalary;
                $line['name'] = $line['name'] ?? 'Basic Salary';
            }
            $earnings[] = $line;
        }
        if (! $hasBasic) {
            $earnings[] = [
                'id' => null,
                'pay_component_id' => null,
                'code' => 'BASIC_SALARY',
                'name' => 'Basic Salary',
                'computed_amount' => $monthlyFixedSalary,
                'configured_value' => $monthlyFixedSalary,
                'is_basic_salary_line' => true,
            ];
        }

        $totals = is_array($compensation['totals'] ?? null) ? $compensation['totals'] : [];
        $grossEarnings = collect($earnings)->sum(function ($line): float {
            return is_array($line) ? max(0.0, (float) ($line['computed_amount'] ?? 0)) : 0.0;
        });

        return array_merge($compensation, [
            'basic_salary' => $monthlyFixedSalary,
            'monthly_salary' => $monthlyFixedSalary,
            'fixed_salary' => $monthlyFixedSalary,
            'earnings' => $earnings,
            'statutory' => $statutory,
            'withholding' => array_merge($withholding, [
                'withholding_per_month' => $withholdingMonthly,
            ]),
            'totals' => array_merge($totals, [
                'gross_earnings' => round($grossEarnings, 2),
                'withholding_tax' => $withholdingMonthly,
                'employee_statutory' => round((float) data_get($statutory, 'totals.employee_deduction', 0), 2),
            ]),
            'pay_period_start' => (string) ($periodContext['pay_period_start'] ?? $from->toDateString()),
            'pay_period_end' => (string) ($periodContext['pay_period_end'] ?? $to->toDateString()),
            'selected_pay_date' => (string) ($periodContext['selected_pay_date'] ?? $to->toDateString()),
            'pay_cycle_preview' => is_array($periodContext['pay_cycle_preview'] ?? null) ? $periodContext['pay_cycle_preview'] : null,
            'pay_cycle_code' => (string) ($periodContext['pay_cycle_code'] ?? ''),
            '_attendance_proration' => [
                'factor' => 1.0,
                'scheduled_workdays' => 0.0,
                'credited_day_units' => 0.0,
                'source' => 'execom_fixed_salary',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isBasicSalaryLine(array $line): bool
    {
        $code = strtoupper(trim((string) ($line['component_code'] ?? $line['code'] ?? $line['key'] ?? '')));
        $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));

        return $code === 'BASIC_SALARY'
            || str_contains($code, 'BASIC_SALARY')
            || $label === 'basic salary'
            || $label === 'regular pay / fixed salary'
            || $label === 'regular pay';
    }

    /**
     * @return array{amount: float, factor: float, basis: string, monthly_fixed_salary: float}
     */
    private function resolvePeriodFixedSalary(ExecomEmployeeProfile $profile, User $employee, Carbon $from, Carbon $to): array
    {
        $profileFixed = round(max(0.0, (float) $profile->fixed_salary), 2);
        $employeeMonthly = round(max(0.0, (float) ($employee->monthly_salary ?? $employee->monthly_rate ?? 0)), 2);
        $monthlyFixed = $profileFixed > 0.0 ? $profileFixed : $employeeMonthly;
        $schedule = strtolower(trim((string) ($profile->pay_schedule ?? ExecomEmployeeProfile::PAY_SCHEDULE_PER_PERIOD)));

        if (in_array($schedule, [ExecomEmployeeProfile::PAY_SCHEDULE_MONTHLY_SPLIT, 'monthly_split', 'semi_monthly'], true)) {
            return [
                'amount' => round($monthlyFixed / 2, 2),
                'factor' => 0.5,
                'basis' => 'monthly_split',
                'monthly_fixed_salary' => $monthlyFixed,
            ];
        }

        return [
            'amount' => $monthlyFixed,
            'factor' => 1.0,
            'basis' => 'fixed_per_period',
            'monthly_fixed_salary' => $monthlyFixed,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function autoPresentAttendanceDisplayLines(array $days): array
    {
        return array_values(array_map(static function (array $day): array {
            return [
                'date' => (string) ($day['date'] ?? ''),
                'attendance_status' => 'Auto Present',
                'status' => 'auto_present',
                'status_label' => 'Auto Present',
                'source' => 'execom_auto_present',
                'payroll_impact' => 0.0,
                'payroll_impact_deduction' => 0.0,
            ];
        }, $days));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function autoPresentDays(Carbon $from, Carbon $to, bool $enabled): array
    {
        if (! $enabled) {
            return [];
        }

        $days = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'attendance_status' => 'Auto Present',
                'status' => 'auto_present',
                'status_label' => 'Auto Present',
                'source' => 'execom_auto_present',
                'regular_pay' => 0.0,
                'late_deduction' => 0.0,
                'undertime_deduction' => 0.0,
                'absence_deduction' => 0.0,
                'leave_deduction' => 0.0,
                'payroll_impact' => 0.0,
                'payroll_impact_deduction' => 0.0,
                'overtime_pay' => 0.0,
                'holiday_pay' => 0.0,
                'night_differential' => 0.0,
            ];
            $cursor->addDay();
        }

        return $days;
    }
}
