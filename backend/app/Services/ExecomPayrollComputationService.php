<?php

namespace App\Services;

use App\Models\ExecomEmployeeProfile;
use App\Models\ExecomPayrollSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        $compensation = $this->calculator->buildEmployeeCompensationSummary($employee, [
            'as_of_date' => $to->toDateString(),
            'proration_factor' => 1.0,
            'hours_worked' => 0.0,
            'include_deduction_schedule_catalog' => false,
            'cache' => false,
        ]);

        $salarySources = $this->resolveExecomSalarySources($profile, $employee, $compensation);
        if ($salarySources['resolved_monthly'] <= 0.0) {
            throw new RuntimeException(sprintf(
                'EXECOM payroll cannot proceed: no salary source for %s (user_id=%d). Set EXECOM fixed salary, Employee Compensation basic salary, or employee monthly salary.',
                trim((string) ($employee->display_name ?? $employee->name ?? 'employee')),
                (int) $employee->id
            ));
        }

        $monthlyFixedSalary = (float) $salarySources['resolved_monthly'];
        $workingDays = $this->resolveCompanyWorkingDays($periodContext);
        $dailyRate = $workingDays > 0 ? round($monthlyFixedSalary / $workingDays, 2) : 0.0;

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

        $scheduleReferenceDate = ! empty($periodContext['selected_pay_date'])
            ? Carbon::parse((string) $periodContext['selected_pay_date'])->startOfDay()
            : $to->copy()->startOfDay();
        $deductionSchedule = $this->deductionScheduleService->summarizeForPayrollComputation(
            $employee,
            $scheduleReferenceDate,
            $compensationForSchedule
        );

        $periodBasic = $this->resolveExecomPeriodBasicPay($employee, $compensationForSchedule, $deductionSchedule, $periodContext, $from, $to);
        $fixedSalary = (float) $periodBasic['amount'];
        if ($fixedSalary <= 0.0) {
            throw new RuntimeException(sprintf(
                'EXECOM payroll cannot proceed: resolved Basic Pay is zero for %s (user_id=%d) on pay date %s. Verify basic salary schedule and compensation setup.',
                trim((string) ($employee->display_name ?? $employee->name ?? 'employee')),
                (int) $employee->id,
                (string) ($periodContext['selected_pay_date'] ?? $to->toDateString())
            ));
        }

        $this->logExecomPayrollResolution($employee, $profile, $periodContext, $salarySources, $periodBasic, $deductionSchedule);

        $earningLines = [[
            'key' => 'execom_basic_pay',
            'label' => 'Basic Pay',
            'name' => 'Basic Pay',
            'category' => 'basic_pay',
            'component_code' => 'BASIC_SALARY',
            'amount' => $fixedSalary,
            'resolved_amount' => $fixedSalary,
            'full_monthly' => $monthlyFixedSalary,
            'schedule_type' => (string) $periodBasic['schedule_type'],
            'metadata' => [
                'payroll_module' => 'execom',
                'source' => (string) $salarySources['salary_source_used'],
                'salary_source_used' => (string) $salarySources['salary_source_used'],
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
                'basic_pay' => $fixedSalary,
                'basic_pay_this_period' => $fixedSalary,
                'total_pay' => $fixedSalary,
                'basic_salary_schedule_type' => (string) $periodBasic['schedule_type'],
                'basic_salary_schedule_factor' => (float) $periodBasic['factor'],
                'execom_salary_basis' => (string) $salarySources['salary_source_used'],
                'execom_salary_source_used' => (string) $salarySources['salary_source_used'],
                'execom_fixed_salary' => (float) $salarySources['execom_fixed_salary'],
                'employee_compensation_salary' => (float) $salarySources['employee_compensation_salary'],
                'employee_monthly_salary' => (float) $salarySources['employee_monthly_salary'],
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
                    'basic_pay' => $fixedSalary,
                    'basic_pay_this_period' => $fixedSalary,
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
                    'payroll_note' => 'EXECOM employees are treated as present for payroll; fixed Basic Pay is independent from attendance logs.',
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
                    'source' => 'execom_fixed_basic_pay',
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
     * @return array{
     *     execom_fixed_salary: float,
     *     employee_compensation_salary: float,
     *     employee_monthly_salary: float,
     *     resolved_monthly: float,
     *     salary_source_used: ?string
     * }
     */
    private function resolveExecomSalarySources(ExecomEmployeeProfile $profile, User $employee, array $compensation): array
    {
        $profileFixed = round(max(0.0, (float) $profile->fixed_salary), 2);
        $compensationBasic = round(max(0.0, (float) ($compensation['basic_salary'] ?? 0)), 2);
        $employeeMonthly = round(max(0.0, (float) ($employee->monthly_salary ?? $employee->monthly_rate ?? 0)), 2);

        $source = null;
        $resolvedMonthly = 0.0;
        if ($profileFixed > 0.0) {
            $source = 'execom_fixed_salary';
            $resolvedMonthly = $profileFixed;
        } elseif ($compensationBasic > 0.0) {
            $source = 'employee_compensation_basic_salary';
            $resolvedMonthly = $compensationBasic;
        } elseif ($employeeMonthly > 0.0) {
            $source = 'employee_monthly_salary';
            $resolvedMonthly = $employeeMonthly;
        }

        return [
            'execom_fixed_salary' => $profileFixed,
            'employee_compensation_salary' => $compensationBasic,
            'employee_monthly_salary' => $employeeMonthly,
            'resolved_monthly' => $resolvedMonthly,
            'salary_source_used' => $source,
        ];
    }

    /**
     * Resolve period Basic Pay using the same pay-component schedule resolver as Regular Payroll.
     *
     * @param  array<string, mixed>  $compensationForSchedule
     * @param  array<string, mixed>  $deductionSchedule
     * @param  array<string, mixed>  $periodContext
     * @return array{amount: float, factor: float, schedule_type: string}
     */
    private function resolveExecomPeriodBasicPay(
        User $employee,
        array $compensationForSchedule,
        array $deductionSchedule,
        array $periodContext,
        Carbon $from,
        Carbon $to,
    ): array {
        foreach (is_array($deductionSchedule['earning_lines'] ?? null) ? $deductionSchedule['earning_lines'] : [] as $line) {
            if (! is_array($line) || empty($line['is_basic_salary_line'])) {
                continue;
            }

            $amount = round(max(0.0, (float) ($line['scheduled_this_period'] ?? 0)), 2);
            $resolution = is_array($line['pay_component_resolution'] ?? null) ? $line['pay_component_resolution'] : [];

            return [
                'amount' => $amount,
                'factor' => (float) ($resolution['divisor_applied'] ?? ($amount > 0 && (float) ($compensationForSchedule['basic_salary'] ?? 0) > 0
                    ? round($amount / (float) $compensationForSchedule['basic_salary'], 6)
                    : 0.0)),
                'schedule_type' => (string) ($line['earning_schedule_type'] ?? $resolution['resolved_schedule'] ?? 'both'),
            ];
        }

        $selectedPayDate = ! empty($periodContext['selected_pay_date'])
            ? Carbon::parse((string) $periodContext['selected_pay_date'])->startOfDay()
            : $to->copy()->startOfDay();
        $basicLine = collect($compensationForSchedule['earnings'] ?? [])->first(
            fn ($row) => is_array($row) && strtoupper(trim((string) ($row['code'] ?? ''))) === 'BASIC_SALARY'
        );
        if (! is_array($basicLine)) {
            $basicLine = [
                'code' => 'BASIC_SALARY',
                'name' => 'Basic Pay',
                'computed_amount' => (float) ($compensationForSchedule['basic_salary'] ?? 0),
                'configured_value' => (float) ($compensationForSchedule['basic_salary'] ?? 0),
                'is_basic_salary_line' => true,
            ];
        }

        $payrollRun = [
            'user' => $employee,
            'reference_date' => $to->copy()->startOfDay(),
            'selected_pay_date' => $selectedPayDate,
            'segment' => data_get($periodContext, 'pay_cycle_preview.semi_month_segment')
                ?? data_get($periodContext, 'semi_month_segment'),
            'pay_cycle_preview' => is_array($periodContext['pay_cycle_preview'] ?? null) ? $periodContext['pay_cycle_preview'] : null,
            'pay_cycle_code' => (string) ($periodContext['pay_cycle_code'] ?? ''),
            'pay_period_start' => (string) ($periodContext['pay_period_start'] ?? $from->toDateString()),
            'pay_period_end' => (string) ($periodContext['pay_period_end'] ?? $to->toDateString()),
            'period_start' => (string) ($periodContext['pay_period_start'] ?? $from->toDateString()),
            'period_end' => (string) ($periodContext['pay_period_end'] ?? $to->toDateString()),
        ];
        $resolution = $this->deductionScheduleService->resolvePayComponentAmount($basicLine, $payrollRun);
        $amount = round(max(0.0, (float) ($resolution['applied_amount'] ?? 0)), 2);

        return [
            'amount' => $amount,
            'factor' => (float) ($resolution['divisor_applied'] ?? 0.0),
            'schedule_type' => (string) ($resolution['resolved_schedule'] ?? 'both'),
        ];
    }

    /**
     * @param  array<string, mixed>  $salarySources
     * @param  array{amount: float, factor: float, schedule_type: string}  $periodBasic
     * @param  array<string, mixed>  $deductionSchedule
     */
    private function logExecomPayrollResolution(
        User $employee,
        ExecomEmployeeProfile $profile,
        array $periodContext,
        array $salarySources,
        array $periodBasic,
        array $deductionSchedule,
    ): void {
        $payDate = (string) ($periodContext['selected_pay_date'] ?? '');
        $batchRunId = isset($periodContext['payroll_batch_run_id']) ? (int) $periodContext['payroll_batch_run_id'] : null;

        if (! $this->canWriteExecomPayrollLogs()) {
            return;
        }

        Log::info('execom.payroll.resolution', [
            'payroll_run_id' => $batchRunId,
            'payroll_batch_run_id' => $batchRunId,
            'payroll_type' => 'execom',
            'employee_id' => (int) $employee->id,
            'employee_name' => trim((string) ($employee->display_name ?? $employee->name ?? '')),
            'execom_fixed_salary' => (float) $salarySources['execom_fixed_salary'],
            'employee_compensation_salary' => (float) $salarySources['employee_compensation_salary'],
            'employee_monthly_salary' => (float) $salarySources['employee_monthly_salary'],
            'resolved_basic_salary' => (float) $periodBasic['amount'],
            'resolved_monthly_salary' => (float) $salarySources['resolved_monthly'],
            'salary_source_used' => (string) ($salarySources['salary_source_used'] ?? ''),
            'pay_date' => $payDate,
            'basic_pay_schedule' => (string) $periodBasic['schedule_type'],
            'basic_pay_schedule_factor' => (float) $periodBasic['factor'],
        ]);

        foreach (is_array($deductionSchedule['earning_lines'] ?? null) ? $deductionSchedule['earning_lines'] : [] as $line) {
            if (! is_array($line) || ! empty($line['is_basic_salary_line'])) {
                continue;
            }
            $this->logExecomComponentScheduleLine($employee, $batchRunId, $payDate, $line, 'earning');
        }

        foreach (is_array($deductionSchedule['custom_lines'] ?? null) ? $deductionSchedule['custom_lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $this->logExecomComponentScheduleLine($employee, $batchRunId, $payDate, $line, 'deduction');
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function logExecomComponentScheduleLine(
        User $employee,
        ?int $batchRunId,
        string $payDate,
        array $line,
        string $componentType,
    ): void {
        $scheduled = round((float) ($line['scheduled_this_period'] ?? 0), 2);
        $applied = round((float) ($line['applied_this_period'] ?? $scheduled), 2);
        $payrollRunType = (string) ($line['payroll_run_type'] ?? data_get($line, 'pay_component_resolution.payroll_run_type', ''));
        $schedule = (string) ($line['deduction_schedule_type'] ?? $line['earning_schedule_type'] ?? '');
        $scheduleApplies = $scheduled > 0.0 || $applied > 0.0;

        if (! $this->canWriteExecomPayrollLogs()) {
            return;
        }

        Log::debug('execom.payroll.component_schedule', [
            'payroll_run_id' => $batchRunId,
            'payroll_batch_run_id' => $batchRunId,
            'payroll_type' => 'execom',
            'employee_id' => (int) $employee->id,
            'employee_name' => trim((string) ($employee->display_name ?? $employee->name ?? '')),
            'pay_date' => $payDate,
            'component_type' => $componentType,
            'component_code' => (string) ($line['code'] ?? ''),
            'component_name' => (string) ($line['name'] ?? ''),
            'component_schedule' => $schedule,
            'payroll_run_type' => $payrollRunType,
            'schedule_applies' => $scheduleApplies,
            'amount' => $applied > 0.0 ? $applied : $scheduled,
            'skipped_reason' => $scheduleApplies ? null : 'schedule_not_applicable_for_pay_date',
        ]);
    }

    private function canWriteExecomPayrollLogs(): bool
    {
        try {
            return function_exists('app') && app()->bound('log');
        } catch (\Throwable) {
            return false;
        }
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
                $line['name'] = $line['name'] ?? 'Basic Pay';
                $line['is_basic_salary_line'] = true;
            }
            $earnings[] = $line;
        }
        if (! $hasBasic) {
            $earnings[] = [
                'id' => null,
                'pay_component_id' => null,
                'code' => 'BASIC_SALARY',
                'name' => 'Basic Pay',
                'computed_amount' => $monthlyFixedSalary,
                'configured_value' => $monthlyFixedSalary,
                'is_basic_salary_line' => true,
            ];
        }

        $totals = is_array($compensation['totals'] ?? null) ? $compensation['totals'] : [];
        $grossEarnings = collect($earnings)->sum(function ($line): float {
            return is_array($line) ? max(0.0, (float) ($line['computed_amount'] ?? 0)) : 0.0;
        });
        $preview = is_array($periodContext['pay_cycle_preview'] ?? null) ? $periodContext['pay_cycle_preview'] : null;

        return array_merge($compensation, [
            'basic_salary' => $monthlyFixedSalary,
            'basic_pay' => $monthlyFixedSalary,
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
            'pay_cycle_preview' => $preview,
            'pay_cycle_code' => (string) ($periodContext['pay_cycle_code'] ?? data_get($preview, 'pay_cycle_code', data_get($preview, 'code', ''))),
            'semi_month_segment' => data_get($periodContext, 'semi_month_segment', data_get($preview, 'semi_month_segment')),
            '_attendance_proration' => [
                'factor' => 1.0,
                'scheduled_workdays' => 0.0,
                'credited_day_units' => 0.0,
                'source' => 'execom_fixed_basic_pay',
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
            || $label === 'basic pay'
            || $label === 'regular pay / fixed salary'
            || $label === 'regular pay';
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
