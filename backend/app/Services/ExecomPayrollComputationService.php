<?php

namespace App\Services;

use App\Models\ExecomEmployeeProfile;
use App\Models\ExecomPayrollSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\LeaveScheduleSupport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ExecomPayrollComputationService
{
    private readonly GovernmentDeductionExemptionResolver $governmentExemptionResolver;

    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly DeductionApplicationService $deductionApplicationService,
        ?GovernmentDeductionExemptionResolver $governmentExemptionResolver = null,
        private readonly ?PayrollComputationService $payrollComputation = null,
        private readonly ?LeaveCreditService $leaveCreditService = null,
        private readonly ?ExecomPayrollPolicyResolver $policyResolver = null,
    ) {
        $this->governmentExemptionResolver = $governmentExemptionResolver ?? new GovernmentDeductionExemptionResolver;
    }

    /**
     * Fixed-salary EXECOM computation with Quick Setup feature gates for allowances,
     * deductions, paid leave, overtime, holiday pay, and auto-present attendance labels.
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
        $companyId = $profile->company_id ? (int) $profile->company_id : null;
        $settings ??= $this->resolveSettingsModel($companyId);
        $policy = $settings->toPolicyArray();

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
        $governmentExemption = $this->resolveGovernmentExemptionForPayroll($employee, $from, $to);
        $payrollExemptionContext = [
            'employee_id' => (int) $employee->id,
            'employee_name' => $employee->display_name,
            'payroll_run_id' => $periodContext['payroll_batch_run_id'] ?? $periodContext['batch_run_id'] ?? null,
            'payroll_period_start' => $from->toDateString(),
            'payroll_period_end' => $to->toDateString(),
        ];
        // Government deductions always apply; Employee Exemptions is the only statutory override.
        $statutoryMonthly = $this->governmentExemptionResolver->applyToStatutory(
            $statutoryMonthly,
            $governmentExemption,
            $payrollExemptionContext
        );
        $employeeStatutoryMonthly = round((float) data_get($statutoryMonthly, 'totals.employee_deduction', 0), 2);
        $monthlyTaxableBase = $this->calculator->monthlyTaxableCompensationForWithholding(
            $monthlyFixedSalary,
            $statutoryMonthly
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
        $withholdingMonthly = round((float) ($withholding['withholding_per_month'] ?? 0), 2);
        [$withholding, $withholdingMonthly] = $this->governmentExemptionResolver->applyToWithholding(
            $withholding,
            $withholdingMonthly,
            $governmentExemption,
            $payrollExemptionContext
        );

        $compensationForSchedule = $this->compensationSummaryWithExecomSalary(
            $compensation,
            $monthlyFixedSalary,
            $statutoryMonthly,
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

        $leaveUnits = $this->resolveLeaveDayUnits($employee, $from, $to, (bool) $policy['allow_paid_leave'], $periodContext);
        $paidLeaveDayUnits = (float) $leaveUnits['paid_units'];
        $unpaidLeaveDayUnits = (float) $leaveUnits['unpaid_units'];
        $paidLeaveAmount = (bool) $policy['allow_paid_leave']
            ? round(max(0.0, $paidLeaveDayUnits * $dailyRate), 2)
            : 0.0;
        $leaveDeduction = round(max(0.0, $unpaidLeaveDayUnits * $dailyRate), 2);
        $basicDisplay = round(max(0.0, $fixedSalary - $paidLeaveAmount), 2);

        $earningLines = [[
            'key' => 'execom_basic_pay',
            'label' => 'Basic Pay',
            'name' => 'Basic Pay',
            'category' => 'basic_pay',
            'component_code' => 'BASIC_SALARY',
            'amount' => $basicDisplay,
            'resolved_amount' => $basicDisplay,
            'full_monthly' => $monthlyFixedSalary,
            'schedule_type' => (string) $periodBasic['schedule_type'],
            'metadata' => [
                'payroll_module' => 'execom',
                'source' => (string) $salarySources['salary_source_used'],
                'salary_source_used' => (string) $salarySources['salary_source_used'],
                'period_basic_before_leave_split' => $fixedSalary,
            ],
        ]];

        if ($paidLeaveAmount > 0.0) {
            $earningLines[] = [
                'key' => 'execom_approved_paid_leave',
                'label' => 'Approved Paid Leave',
                'name' => 'Approved Paid Leave',
                'category' => 'paid_leave',
                'component_code' => 'EXECOM_PAID_LEAVE',
                'amount' => $paidLeaveAmount,
                'resolved_amount' => $paidLeaveAmount,
                'units' => $this->formatDayBasedUnits($paidLeaveDayUnits),
                'metadata' => [
                    'payroll_module' => 'execom',
                    'leave_day_units' => $paidLeaveDayUnits,
                    'allow_paid_leave' => true,
                ],
            ];
        }

        $allowanceLines = (bool) $policy['apply_allowances']
            ? $this->deductionScheduleService->buildPayslipEarningDisplayLines(
                is_array($deductionSchedule['earning_lines'] ?? null) ? $deductionSchedule['earning_lines'] : []
            )
            : [];
        $allowanceTotal = 0.0;
        if ((bool) $policy['apply_allowances']) {
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

        $dailyExtras = $this->resolveGatedDailyExtras($employee, $from, $to, $periodContext, $policy);
        $overtimeAmount = (bool) $policy['allow_overtime'] ? (float) $dailyExtras['overtime_amount'] : 0.0;
        $holidayPayAmount = (bool) $policy['allow_holiday_pay'] ? (float) $dailyExtras['holiday_pay_amount'] : 0.0;
        $overtimeBreakdown = (bool) $policy['allow_overtime'] ? $dailyExtras['overtime_breakdown'] : [];
        $holidayPremiumBreakdown = (bool) $policy['allow_holiday_pay'] ? $dailyExtras['holiday_premium_breakdown'] : [];
        $overtimeHours = (bool) $policy['allow_overtime'] ? (float) $dailyExtras['overtime_total_hours'] : 0.0;

        foreach ((bool) $policy['allow_overtime'] ? $dailyExtras['overtime_earning_lines'] : [] as $line) {
            $earningLines[] = $line;
        }
        foreach ((bool) $policy['allow_holiday_pay'] ? $dailyExtras['holiday_earning_lines'] : [] as $line) {
            $earningLines[] = $line;
        }

        $grossPay = round($basicDisplay + $paidLeaveAmount + $allowanceTotal + $overtimeAmount + $holidayPayAmount, 2);
        $deductionLines = $this->deductionScheduleService->buildPayslipDeductionDisplayLines(
            is_array($deductionSchedule['government'] ?? null) ? $deductionSchedule['government'] : [],
            $withholdingMonthly
        );
        $employeeStatutory = round((float) ($deductionSchedule['employee_statutory_this_period'] ?? 0), 2);
        $withholdingThisPeriod = round((float) ($deductionSchedule['withholding_this_period'] ?? 0), 2);

        $phase3Deduction = $this->deductionApplicationService->enforcePriorityAndLegalLimitsForPayrollPeriod(
            $employee,
            (bool) $policy['apply_custom_deductions'] && is_array($deductionSchedule['custom_lines'] ?? null)
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
        $customDeductions = (bool) $policy['apply_custom_deductions']
            ? round((float) ($phase3Deduction['custom_deductions_this_period'] ?? 0), 2)
            : 0.0;
        $customDeductionLines = (bool) $policy['apply_custom_deductions']
            ? $this->deductionScheduleService->buildPayslipCustomDeductionDisplayLines(
                is_array($deductionSchedule['custom_lines'] ?? null) ? $deductionSchedule['custom_lines'] : []
            )
            : [];

        $leaveDeductionLines = [];
        if ($leaveDeduction > 0.0) {
            $leaveDeductionLines[] = [
                'key' => 'execom_leave_unpaid_policy',
                'label' => 'Approved Leave — Unpaid under EXECOM payroll policy',
                'name' => 'Approved Leave — Unpaid under EXECOM payroll policy',
                'category' => 'leave_deduction',
                'component_code' => 'EXECOM_UNPAID_LEAVE',
                'amount' => $leaveDeduction,
                'resolved_amount' => $leaveDeduction,
                'units' => $this->formatDayBasedUnits($unpaidLeaveDayUnits),
                'metadata' => [
                    'payroll_module' => 'execom',
                    'leave_day_units' => $unpaidLeaveDayUnits,
                    'allow_paid_leave' => (bool) $policy['allow_paid_leave'],
                ],
            ];
        }

        $governmentDeductionAmount = round($employeeStatutory + $withholdingThisPeriod, 2);
        $totalDeductions = round($governmentDeductionAmount + $customDeductions + $leaveDeduction, 2);
        $netPay = round($grossPay - $totalDeductions, 2);
        $days = $this->autoPresentDays($from, $to, (bool) $policy['auto_present_attendance_reports']);
        $autoPresentCount = count($days);

        $this->logExecomPolicyDebug($employee, $profile, $periodContext, $policy, [
            'base_pay' => $fixedSalary,
            'paid_leave_amount' => $paidLeaveAmount,
            'leave_deduction' => $leaveDeduction,
            'allowance_amount' => $allowanceTotal,
            'overtime_amount' => $overtimeAmount,
            'holiday_pay_amount' => $holidayPayAmount,
            'government_deduction_amount' => $governmentDeductionAmount,
            'custom_deduction_amount' => $customDeductions,
            'gross_pay' => $grossPay,
            'net_pay' => $netPay,
        ]);

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
                'basic_salary' => $basicDisplay,
                'basic_salary_period' => $basicDisplay,
                'basic_pay' => $basicDisplay,
                'basic_pay_this_period' => $basicDisplay,
                'period_basic_before_leave_split' => $fixedSalary,
                'paid_leave_day_units' => $paidLeaveDayUnits,
                'unpaid_leave_day_units' => $unpaidLeaveDayUnits,
                'total_pay' => round($basicDisplay + $paidLeaveAmount + $overtimeAmount + $holidayPayAmount, 2),
                'basic_salary_schedule_type' => (string) $periodBasic['schedule_type'],
                'basic_salary_schedule_factor' => (float) $periodBasic['factor'],
                'execom_salary_basis' => (string) $salarySources['salary_source_used'],
                'execom_salary_source_used' => (string) $salarySources['salary_source_used'],
                'execom_fixed_salary' => (float) $salarySources['execom_fixed_salary'],
                'employee_compensation_salary' => (float) $salarySources['employee_compensation_salary'],
                'employee_monthly_salary' => (float) $salarySources['employee_monthly_salary'],
                'attendance_status' => (bool) $policy['auto_present_attendance_reports'] ? 'Auto Present' : 'EXECOM Payroll',
                'absent_days' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'attendance_deduction' => 0.0,
                'leave_deduction' => $leaveDeduction,
                'paid_leave_amount' => $paidLeaveAmount,
                'attendance_premium_pay_this_period' => round($overtimeAmount + $holidayPayAmount, 2),
                'gross_pay_this_period' => $grossPay,
                'total_deductions_this_period' => $totalDeductions,
                'actual_days_worked' => (float) $autoPresentCount,
                'daily_rate' => $dailyRate,
                'daily_rate_divisor_days' => $workingDays,
                'employee_statutory_total' => $employeeStatutoryMonthly,
                'employee_statutory_this_period' => $employeeStatutory,
                'employer_statutory_total' => round((float) data_get($statutoryMonthly, 'totals.employer_liability', data_get($statutoryMonthly, 'totals.employer_contribution', 0)), 2),
                'custom_deductions_full_monthly' => (bool) $policy['apply_custom_deductions']
                    ? round((float) ($deductionSchedule['custom_deductions_full_monthly'] ?? $customDeductions), 2)
                    : 0.0,
                'custom_deductions_this_period' => round($customDeductions, 2),
                'net_pay' => $netPay,
                'withholding_tax_monthly_estimate' => $withholdingMonthly,
                'withholding_tax_this_period_estimate' => $withholdingThisPeriod,
                'withholding_breakdown' => $withholding,
                'net_pay_after_withholding_estimate' => $netPay,
                'statutory_breakdown' => $statutoryMonthly,
                'government_deduction_exemption' => $governmentExemption,
                'compensation_breakdown' => array_merge($compensation, [
                    'basic_salary' => $fixedSalary,
                    'basic_salary_period' => $fixedSalary,
                    'basic_pay' => $fixedSalary,
                    'basic_pay_this_period' => $fixedSalary,
                    'monthly_salary' => $monthlyFixedSalary,
                    'fixed_salary' => $monthlyFixedSalary,
                    'payroll_module' => 'execom',
                    'statutory' => $statutoryMonthly,
                    'withholding' => $withholding,
                    'government_deduction_exemption' => $governmentExemption,
                    'tax_classification' => [
                        'taxable_total' => $grossPay,
                        'non_taxable_total' => 0.0,
                        'gross_total' => $grossPay,
                    ],
                ]),
                'deduction_schedule' => $deductionSchedule,
                'legal_warnings' => $phase3Deduction['legal_warnings'],
                'minimum_take_home_floor' => $phase3Deduction['minimum_take_home_floor'],
                'non_basic_earnings_this_period' => round($allowanceTotal + $overtimeAmount + $holidayPayAmount, 2),
                'payslip_deduction_lines' => array_values(array_merge($deductionLines, $leaveDeductionLines)),
                'payslip_custom_deduction_lines' => $customDeductionLines,
                'payslip_earning_lines' => $earningLines,
                'daily_computation_earning_lines' => array_values(array_merge(
                    (bool) $policy['allow_overtime'] ? $dailyExtras['overtime_earning_lines'] : [],
                    (bool) $policy['allow_holiday_pay'] ? $dailyExtras['holiday_earning_lines'] : [],
                )),
                'attendance_display_summary' => [
                    'attendance_status' => (bool) $policy['auto_present_attendance_reports'] ? 'Auto Present' : 'EXECOM Payroll',
                    'status_label' => (bool) $policy['auto_present_attendance_reports'] ? 'Auto Present' : 'EXECOM Payroll',
                    'working_days_count' => $autoPresentCount,
                    'presence_days_count' => $autoPresentCount,
                    'lines' => $this->autoPresentAttendanceDisplayLines($days),
                    'total_regular_hours' => 0.0,
                    'total_presence_regular_hours' => 0.0,
                    'absent_days' => 0,
                    'absent_days_count' => 0,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'leave_deduction' => $leaveDeduction,
                    'unpaid_leave_days_count' => $unpaidLeaveDayUnits,
                    'payroll_impact' => 0.0,
                    'payroll_impact_deduction' => $leaveDeduction,
                    'payroll_note' => (bool) $policy['auto_present_attendance_reports']
                        ? 'EXECOM auto-present when eligible; fixed Basic Pay with Quick Setup earning/deduction gates.'
                        : 'EXECOM fixed Basic Pay with Quick Setup earning/deduction gates; attendance not auto-presented.',
                ],
                'holiday_premium_breakdown' => $holidayPremiumBreakdown,
                'total_worked_minutes' => 0,
                'total_regular_day_minutes' => 0,
                'total_regular_night_minutes' => 0,
                'total_ot_day_minutes' => 0,
                'total_ot_night_minutes' => 0,
                'overtime_breakdown' => $overtimeBreakdown,
                'overtime_total_hours' => $overtimeHours,
                'overtime_total_amount' => $overtimeAmount,
                'attendance_proration' => [
                    'factor' => 1.0,
                    'source' => 'execom_fixed_basic_pay',
                ],
                'execom_settings' => $policy,
                'execom_payroll_policy' => $this->humanPolicyLabels($policy),
            ],
        ];
    }

    private function resolveSettingsModel(?int $companyId): ExecomPayrollSetting
    {
        if ($this->policyResolver instanceof ExecomPayrollPolicyResolver) {
            return $this->policyResolver->setting($companyId);
        }

        try {
            return app(ExecomPayrollPolicyResolver::class)->setting($companyId);
        } catch (\Throwable) {
            // fall through
        }

        return ExecomPayrollSetting::forCompany($companyId);
    }

    private function resolvePayrollComputationEngine(): ?PayrollComputationService
    {
        if ($this->payrollComputation instanceof PayrollComputationService) {
            return $this->payrollComputation;
        }

        // Optional ctor dep is often null: Laravel skips auto-wiring when default is null,
        // and app()->bound(Concrete::class) is false for unbound concretes.
        try {
            return app(PayrollComputationService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLeaveCreditService(): ?LeaveCreditService
    {
        if ($this->leaveCreditService instanceof LeaveCreditService) {
            return $this->leaveCreditService;
        }

        try {
            return app(LeaveCreditService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $periodContext
     * @param  array<string, bool>  $policy
     * @return array{
     *     overtime_amount: float,
     *     overtime_total_hours: float,
     *     overtime_breakdown: list<array<string, mixed>>,
     *     overtime_earning_lines: list<array<string, mixed>>,
     *     holiday_pay_amount: float,
     *     holiday_premium_breakdown: list<array<string, mixed>>,
     *     holiday_earning_lines: list<array<string, mixed>>
     * }
     */
    private function resolveGatedDailyExtras(
        User $employee,
        Carbon $from,
        Carbon $to,
        array $periodContext,
        array $policy,
    ): array {
        $empty = [
            'overtime_amount' => 0.0,
            'overtime_total_hours' => 0.0,
            'overtime_breakdown' => [],
            'overtime_earning_lines' => [],
            'holiday_pay_amount' => 0.0,
            'holiday_premium_breakdown' => [],
            'holiday_earning_lines' => [],
        ];

        if (! (bool) $policy['allow_overtime'] && ! (bool) $policy['allow_holiday_pay']) {
            return $empty;
        }

        // Test/debug override — avoid full daily engine in unit tests.
        if (isset($periodContext['execom_daily_extras']) && is_array($periodContext['execom_daily_extras'])) {
            return array_merge($empty, $periodContext['execom_daily_extras']);
        }

        $engine = $this->resolvePayrollComputationEngine();
        if (! $engine instanceof PayrollComputationService) {
            return $empty;
        }

        try {
            $computed = $engine->computeEmployeePayroll($employee, $from, $to, null, $periodContext);
        } catch (\Throwable $e) {
            Log::warning('execom.payroll.daily_extras_failed', [
                'employee_id' => (int) $employee->id,
                'message' => $e->getMessage(),
            ]);

            return $empty;
        }

        $summary = is_array($computed['summary'] ?? null) ? $computed['summary'] : [];
        $dailyLines = array_merge(
            is_array($summary['daily_computation_earning_lines'] ?? null)
                ? $summary['daily_computation_earning_lines']
                : [],
            is_array($summary['payslip_earning_lines'] ?? null)
                ? $summary['payslip_earning_lines']
                : []
        );

        $otComponents = ['ot_pay', 'overtime_premium', 'nd_pay', 'night_diff'];
        $holidayComponents = ['holiday_premium', 'rest_day_worked_pay'];

        $overtimeLines = [];
        $holidayLines = [];
        $overtimeAmount = 0.0;
        $holidayAmount = 0.0;

        foreach ($dailyLines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $component = strtolower(trim((string) ($line['component'] ?? $line['component_code'] ?? '')));
            $key = strtolower(trim((string) ($line['key'] ?? '')));
            $label = strtolower(trim((string) ($line['label'] ?? $line['name'] ?? '')));
            $amount = round((float) ($line['amount'] ?? $line['resolved_amount'] ?? 0), 2);
            if ($amount <= 0.0) {
                continue;
            }

            $isOt = in_array($component, $otComponents, true)
                || str_contains($key, 'ot_pay')
                || str_contains($key, 'overtime')
                || str_contains($key, 'nd_pay')
                || str_contains($key, 'night_diff')
                || $label === 'overtime'
                || str_contains($label, 'night differential');
            $isHoliday = in_array($component, $holidayComponents, true)
                || str_contains($key, 'holiday')
                || str_contains($key, 'rest_day_worked')
                || str_contains($label, 'holiday');

            if ($isOt && (bool) $policy['allow_overtime']) {
                $overtimeAmount += $amount;
                $overtimeLines[] = array_merge($line, [
                    'category' => 'overtime',
                    'amount' => $amount,
                    'resolved_amount' => $amount,
                ]);
            } elseif ($isHoliday && (bool) $policy['allow_holiday_pay']) {
                if (! $this->isExecomHolidayLineInHolidayScope($line)) {
                    continue;
                }
                $holidayAmount += $amount;
                $holidayLines[] = array_merge($line, [
                    'category' => 'holiday_pay',
                    'amount' => $amount,
                    'resolved_amount' => $amount,
                ]);
            }
        }

        if ($overtimeAmount <= 0.0 && (bool) $policy['allow_overtime']) {
            $overtimeAmount = round((float) ($summary['overtime_total_amount'] ?? 0), 2);
        }
        if ($overtimeAmount > 0.0 && $overtimeLines === [] && (bool) $policy['allow_overtime']) {
            $overtimeLines[] = [
                'key' => 'execom_overtime',
                'label' => 'Overtime',
                'name' => 'Overtime',
                'category' => 'overtime',
                'component' => 'ot_pay',
                'component_code' => 'OT_PAY',
                'amount' => $overtimeAmount,
                'resolved_amount' => $overtimeAmount,
            ];
        }

        $holidayBreakdown = [];
        if ((bool) $policy['allow_holiday_pay']) {
            foreach (is_array($summary['holiday_premium_breakdown'] ?? null) ? $summary['holiday_premium_breakdown'] : [] as $item) {
                if (! $this->isExecomHolidayBreakdownInHolidayScope($item)) {
                    continue;
                }
                $holidayBreakdown[] = $item;
            }
        }
        if ($holidayAmount <= 0.0 && (bool) $policy['allow_holiday_pay']) {
            foreach ($holidayBreakdown as $item) {
                $holidayAmount += round((float) ($item['amount'] ?? 0), 2);
            }
            $holidayAmount = round($holidayAmount, 2);
        }
        if ($holidayAmount > 0.0 && $holidayLines === [] && (bool) $policy['allow_holiday_pay']) {
            $holidayLines[] = [
                'key' => 'execom_holiday_pay',
                'label' => 'Holiday premium',
                'name' => 'Holiday premium',
                'category' => 'holiday_pay',
                'component' => 'holiday_premium',
                'component_code' => 'HOLIDAY_PREMIUM',
                'amount' => $holidayAmount,
                'resolved_amount' => $holidayAmount,
            ];
        }

        return [
            'overtime_amount' => round($overtimeAmount, 2),
            'overtime_total_hours' => round((float) ($summary['overtime_total_hours'] ?? 0), 2),
            'overtime_breakdown' => is_array($summary['overtime_breakdown'] ?? null) ? array_values($summary['overtime_breakdown']) : [],
            'overtime_earning_lines' => array_values($overtimeLines),
            'holiday_pay_amount' => round($holidayAmount, 2),
            'holiday_premium_breakdown' => array_values($holidayBreakdown),
            'holiday_earning_lines' => array_values($holidayLines),
        ];
    }

    /**
     * EXECOM Allow holiday pay still requires Holiday Module coverage/scope.
     * Policy "ignore coverage" must not pay out-of-scope holidays into EXECOM payroll.
     *
     * @param  array<string, mixed>  $line
     */
    private function isExecomHolidayLineInHolidayScope(array $line): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($line['key'] ?? ''),
            (string) ($line['component'] ?? ''),
            (string) ($line['component_code'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
        ]));

        // Pure rest-day worked pay is not Holiday Module coverage-scoped.
        if (str_contains($haystack, 'rest_day') && ! str_contains($haystack, 'holiday')) {
            return true;
        }

        $meta = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
        if (array_key_exists('scope_match', $meta)) {
            return (bool) $meta['scope_match'];
        }
        if (array_key_exists('calendar_scope_match', $meta)) {
            return (bool) $meta['calendar_scope_match'];
        }
        if (array_key_exists('scope_match', $line)) {
            return (bool) $line['scope_match'];
        }

        // Aggregate/synthetic holiday lines without scope — deny for EXECOM scope gate.
        return false;
    }

    private function isExecomHolidayBreakdownInHolidayScope(mixed $item): bool
    {
        if (! is_array($item)) {
            return false;
        }
        if (! (bool) ($item['eligible'] ?? false)) {
            return false;
        }
        if (round((float) ($item['amount'] ?? 0), 2) <= 0.0) {
            return false;
        }

        return (bool) ($item['scope_match'] ?? $item['calendar_scope_match'] ?? false);
    }

    /**
     * Split approved leave day-units in the payroll window into paid vs unpaid under EXECOM policy.
     *
     * @param  array<string, mixed>  $periodContext
     * @return array{paid_units: float, unpaid_units: float}
     */
    private function resolveLeaveDayUnits(
        User $employee,
        Carbon $from,
        Carbon $to,
        bool $allowPaidLeave,
        array $periodContext,
    ): array {
        if (isset($periodContext['execom_leave_units']) && is_array($periodContext['execom_leave_units'])) {
            return [
                'paid_units' => max(0.0, (float) ($periodContext['execom_leave_units']['paid'] ?? 0)),
                'unpaid_units' => max(0.0, (float) ($periodContext['execom_leave_units']['unpaid'] ?? 0)),
            ];
        }

        // Backward-compatible test override: treat as unpaid units under policy.
        if (isset($periodContext['execom_leave_day_units']) && is_numeric($periodContext['execom_leave_day_units'])) {
            $units = max(0.0, (float) $periodContext['execom_leave_day_units']);

            return $allowPaidLeave
                ? ['paid_units' => $units, 'unpaid_units' => 0.0]
                : ['paid_units' => 0.0, 'unpaid_units' => $units];
        }

        try {
            $leaves = LeaveRequest::query()
                ->where('user_id', (int) $employee->id)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->get([
                    'id', 'user_id', 'type', 'half_type', 'start_date', 'end_date', 'status',
                    'leave_credits_charged', 'leave_unpaid_credit_days',
                ]);
        } catch (\Throwable) {
            return ['paid_units' => 0.0, 'unpaid_units' => 0.0];
        }

        if ($leaves->isEmpty()) {
            return ['paid_units' => 0.0, 'unpaid_units' => 0.0];
        }

        $leaveCredits = $this->resolveLeaveCreditService();
        if (! $leaveCredits instanceof LeaveCreditService) {
            // Fall through: split using stored leave_credits_charged / unpaid days on the request.
            $leaveCredits = null;
        }

        $paidUnits = 0.0;
        $unpaidUnits = 0.0;
        foreach ($leaves as $leave) {
            if (! $leave instanceof LeaveRequest) {
                continue;
            }
            $type = strtolower(trim((string) $leave->type));
            if ($type === 'undertime') {
                continue;
            }

            $rangeStart = $leave->start_date->copy()->startOfDay()->max($from->copy()->startOfDay())->toDateString();
            $rangeEnd = $leave->end_date->copy()->startOfDay()->min($to->copy()->startOfDay())->toDateString();
            if ($rangeEnd < $rangeStart) {
                continue;
            }

            $dayUnit = $type === 'half_day' ? 0.5 : 1.0;
            if ($type === 'half_day') {
                $dateKey = $leave->start_date->toDateString();
                if ($dateKey < $from->toDateString() || $dateKey > $to->toDateString()) {
                    continue;
                }
                if ($this->isPaidLeaveDayForExecom($employee, $leave, $dateKey, $allowPaidLeave, $leaveCredits)) {
                    $paidUnits += $dayUnit;
                } else {
                    $unpaidUnits += $dayUnit;
                }
                continue;
            }

            try {
                $workDates = LeaveScheduleSupport::listWorkingDateStringsInRangeOrdered($employee, $rangeStart, $rangeEnd);
            } catch (\Throwable) {
                $workDates = [];
                $cursor = Carbon::parse($rangeStart)->startOfDay();
                $end = Carbon::parse($rangeEnd)->startOfDay();
                while ($cursor->lte($end)) {
                    $workDates[] = $cursor->toDateString();
                    $cursor->addDay();
                }
            }

            // If schedule support returns no workdays but leave exists in range, count calendar days in range.
            if ($workDates === []) {
                $cursor = Carbon::parse($rangeStart)->startOfDay();
                $end = Carbon::parse($rangeEnd)->startOfDay();
                while ($cursor->lte($end)) {
                    $workDates[] = $cursor->toDateString();
                    $cursor->addDay();
                }
            }

            foreach ($workDates as $dateKey) {
                if ($this->isPaidLeaveDayForExecom($employee, $leave, $dateKey, $allowPaidLeave, $leaveCredits)) {
                    $paidUnits += $dayUnit;
                } else {
                    $unpaidUnits += $dayUnit;
                }
            }
        }

        return [
            'paid_units' => round($paidUnits, 4),
            'unpaid_units' => round($unpaidUnits, 4),
        ];
    }

    private function isPaidLeaveDayForExecom(
        User $employee,
        LeaveRequest $leave,
        string $dateKey,
        bool $allowPaidLeave,
        ?LeaveCreditService $leaveCredits,
    ): bool {
        if (! $allowPaidLeave) {
            return false;
        }

        if ($leaveCredits instanceof LeaveCreditService) {
            $status = $leaveCredits->leavePayStatusForDate($employee, $leave, $dateKey);
            if ($status === 'paid') {
                return true;
            }
            if ($status === 'unpaid') {
                return false;
            }
        }

        // Fallback when status resolver returns null: credit-charged leave counts as paid.
        $charged = (int) ($leave->leave_credits_charged ?? 0);

        return $charged > 0;
    }

    /**
     * @param  array<string, bool>  $policy
     * @return array<string, string>
     */
    private function humanPolicyLabels(array $policy): array
    {
        return [
            'Government deductions' => 'Applied via Employee Exemptions',
            'Custom deductions' => (bool) $policy['apply_custom_deductions'] ? 'Applied' : 'Not applied',
            'Allowances' => (bool) $policy['apply_allowances'] ? 'Included' : 'Not included',
            'Paid leave' => (bool) $policy['allow_paid_leave'] ? 'Included when approved and paid' : 'Not included',
            'Overtime' => (bool) $policy['allow_overtime'] ? 'Included' : 'Not included',
            'Holiday pay' => (bool) $policy['allow_holiday_pay']
                ? 'Included when in Holiday Module scope'
                : 'Not included',
            'Attendance' => (bool) $policy['auto_present_attendance_reports']
                ? 'Auto-present when eligible'
                : 'Actual attendance',
        ];
    }

    /**
     * @param  array<string, bool>  $policy
     * @param  array<string, float>  $amounts
     */
    private function logExecomPolicyDebug(
        User $employee,
        ExecomEmployeeProfile $profile,
        array $periodContext,
        array $policy,
        array $amounts,
    ): void {
        if (! $this->canWriteExecomPayrollLogs()) {
            return;
        }

        Log::info('execom.payroll.policy_totals', [
            'employee_id' => (int) $employee->id,
            'payroll_period_id' => $periodContext['payroll_period_id'] ?? null,
            'execom_policy_id' => $profile->id ? (int) $profile->id : null,
            'apply_custom_deductions' => (bool) $policy['apply_custom_deductions'],
            'apply_allowances' => (bool) $policy['apply_allowances'],
            'allow_paid_leave' => (bool) $policy['allow_paid_leave'],
            'allow_overtime' => (bool) $policy['allow_overtime'],
            'allow_holiday_pay' => (bool) $policy['allow_holiday_pay'],
            'auto_present_attendance_reports' => (bool) $policy['auto_present_attendance_reports'],
            'base_pay' => $amounts['base_pay'],
            'paid_leave_amount' => $amounts['paid_leave_amount'],
            'leave_deduction' => $amounts['leave_deduction'],
            'allowance_amount' => $amounts['allowance_amount'],
            'overtime_amount' => $amounts['overtime_amount'],
            'holiday_pay_amount' => $amounts['holiday_pay_amount'],
            'government_deduction_amount' => $amounts['government_deduction_amount'],
            'custom_deduction_amount' => $amounts['custom_deduction_amount'],
            'gross_pay' => $amounts['gross_pay'],
            'net_pay' => $amounts['net_pay'],
        ]);
    }

    /**
     * Unit tests instantiate this service with unsaved User models and no database resolver.
     * Production calls still use the resolver and persisted exemption settings.
     *
     * @return array<string, mixed>
     */
    private function resolveGovernmentExemptionForPayroll(User $employee, Carbon $from, Carbon $to): array
    {
        try {
            return $this->governmentExemptionResolver->resolve(
                (int) $employee->id,
                GovernmentDeductionExemptionResolver::PAYROLL_EXECOM,
                $from,
                $to
            );
        } catch (\Throwable) {
            return array_merge($this->governmentExemptionResolver->defaultPayload(), [
                'active_for_period' => false,
                'government_exemption_found' => false,
                'scope_applies' => false,
                'payroll_type' => GovernmentDeductionExemptionResolver::PAYROLL_EXECOM,
                'exempted_types' => [],
            ]);
        }
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

    private function formatDayBasedUnits(float $days): ?string
    {
        $days = round(max(0.0, $days), 4);
        if ($days <= 0.0) {
            return null;
        }

        $label = abs($days - round($days)) < 0.00005
            ? (string) (int) round($days)
            : rtrim(rtrim(number_format($days, 4, '.', ''), '0'), '.');
        $n = (float) $label;

        return $label.' '.($n == 1.0 ? 'day' : 'days');
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
