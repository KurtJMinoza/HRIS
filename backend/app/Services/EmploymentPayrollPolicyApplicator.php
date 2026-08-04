<?php

namespace App\Services;

use App\Models\User;

/**
 * Gates regular payroll computation/payslip output by employment-class policy settings.
 */
class EmploymentPayrollPolicyApplicator
{
    /** @var list<string> */
    private const OT_COMPONENTS = ['ot_pay', 'overtime_premium', 'nd_pay', 'night_diff'];

    /** @var list<string> */
    private const HOLIDAY_COMPONENTS = ['holiday_premium', 'rest_day_worked_pay'];

    /** @var list<string> */
    private const PAID_LEAVE_COMPONENTS = ['paid_leave', 'paid_leave_daily_flat'];

    public function __construct(
        private readonly EmploymentPayrollPolicyResolver $policyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $computed
     * @return array<string, mixed>
     */
    public function applyToComputation(User $employee, array $computed, ?int $companyId = null): array
    {
        if ((bool) ($employee->is_execom ?? false)) {
            return $computed;
        }

        $policy = $this->policyResolver->resolveForEmployee($employee, $companyId);
        $summary = is_array($computed['summary'] ?? null) ? $computed['summary'] : [];
        $summary = $this->applyToSummary($summary, $policy);
        $computed['summary'] = $summary;

        return $computed;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public function applyToSummary(array $summary, array $policy): array
    {
        $summary['employment_payroll_settings'] = $this->policyFields($policy);

        if (! (bool) $policy['allow_paid_leave']) {
            $summary = $this->stripPaidLeave($summary);
        }

        if (! (bool) $policy['allow_overtime']) {
            $summary = $this->stripOvertime($summary);
        }

        if (! (bool) $policy['allow_holiday_pay']) {
            $summary = $this->stripHolidayPay($summary);
        } else {
            $summary = $this->filterHolidayPayByScope($summary);
        }

        if (! (bool) $policy['apply_allowances']) {
            $summary = $this->stripAllowances($summary);
        }

        if (! (bool) $policy['apply_custom_deductions']) {
            $summary = $this->stripCustomDeductions($summary);
        }

        return $this->recalculateSummaryTotals($summary);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array{
     *     employment_type: string,
     *     apply_custom_deductions: bool,
     *     apply_allowances: bool,
     *     allow_paid_leave: bool,
     *     allow_overtime: bool,
     *     allow_holiday_pay: bool
     * }
     */
    private function policyFields(array $policy): array
    {
        return [
            'employment_type' => (string) ($policy['employment_type'] ?? ''),
            'apply_custom_deductions' => (bool) $policy['apply_custom_deductions'],
            'apply_allowances' => (bool) $policy['apply_allowances'],
            'allow_paid_leave' => (bool) $policy['allow_paid_leave'],
            'allow_overtime' => (bool) $policy['allow_overtime'],
            'allow_holiday_pay' => (bool) $policy['allow_holiday_pay'],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripPaidLeave(array $summary): array
    {
        $summary['daily_computation_earning_lines'] = $this->filterLines(
            $summary['daily_computation_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isPaidLeaveLine($line)
        );
        $summary['payslip_earning_lines'] = $this->filterLines(
            $summary['payslip_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isPaidLeaveLine($line)
        );

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripOvertime(array $summary): array
    {
        $summary['daily_computation_earning_lines'] = $this->filterLines(
            $summary['daily_computation_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isOvertimeLine($line)
        );
        $summary['payslip_earning_lines'] = $this->filterLines(
            $summary['payslip_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isOvertimeLine($line)
        );
        $summary['overtime_breakdown'] = [];
        $summary['overtime_total_hours'] = 0.0;
        $summary['overtime_total_amount'] = 0.0;
        $summary['total_ot_day_minutes'] = 0;
        $summary['total_ot_night_minutes'] = 0;

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripHolidayPay(array $summary): array
    {
        $summary['daily_computation_earning_lines'] = $this->filterLines(
            $summary['daily_computation_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isHolidayLine($line)
        );
        $summary['payslip_earning_lines'] = $this->filterLines(
            $summary['payslip_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isHolidayLine($line)
        );
        $summary['holiday_premium_breakdown'] = [];

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function filterHolidayPayByScope(array $summary): array
    {
        $summary['holiday_premium_breakdown'] = array_values(array_filter(
            is_array($summary['holiday_premium_breakdown'] ?? null) ? $summary['holiday_premium_breakdown'] : [],
            fn ($item): bool => is_array($item)
                && (bool) ($item['eligible'] ?? true)
                && (bool) ($item['scope_match'] ?? $item['calendar_scope_match'] ?? false)
                && round((float) ($item['amount'] ?? 0), 2) > 0.0
        ));
        $summary['daily_computation_earning_lines'] = $this->filterLines(
            $summary['daily_computation_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isHolidayLine($line) || $this->isHolidayLineInScope($line)
        );
        $summary['payslip_earning_lines'] = $this->filterLines(
            $summary['payslip_earning_lines'] ?? [],
            fn (array $line): bool => ! $this->isHolidayLine($line) || $this->isHolidayLineInScope($line)
        );

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripAllowances(array $summary): array
    {
        $summary['payslip_earning_lines'] = $this->filterLines(
            $summary['payslip_earning_lines'] ?? [],
            fn (array $line): bool => $this->isBasicSalaryLine($line)
                || $this->isPaidLeaveLine($line)
                || $this->isOvertimeLine($line)
                || $this->isHolidayLine($line)
                || $this->isAttendanceEarningLine($line)
        );

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function stripCustomDeductions(array $summary): array
    {
        $summary['payslip_custom_deduction_lines'] = [];
        $summary['custom_deductions_this_period'] = 0.0;
        $summary['custom_deductions_full_monthly'] = 0.0;

        if (is_array($summary['deduction_schedule'] ?? null)) {
            $summary['deduction_schedule']['custom_lines'] = [];
            $summary['deduction_schedule']['custom_deductions_this_period'] = 0.0;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function recalculateSummaryTotals(array $summary): array
    {
        $dailyAmount = $this->sumLineAmounts($summary['daily_computation_earning_lines'] ?? []);
        $payslipAmount = $this->sumLineAmounts($summary['payslip_earning_lines'] ?? []);
        $attendancePremium = round(max(0.0, $dailyAmount - $this->sumRegularPayAmount($summary['daily_computation_earning_lines'] ?? [])), 2);

        $basicPay = round((float) ($summary['basic_pay_this_period'] ?? 0), 2);
        if ($basicPay <= 0.0) {
            $basicPay = round($this->sumRegularPayAmount($summary['daily_computation_earning_lines'] ?? []), 2);
            if ($basicPay <= 0.0) {
                $basicPay = round($this->sumBasicSalaryLineAmount($summary['payslip_earning_lines'] ?? []), 2);
            }
            $summary['basic_pay_this_period'] = $basicPay;
        }

        $nonBasic = round(max(0.0, $payslipAmount), 2);
        $summary['attendance_premium_pay_this_period'] = $attendancePremium;
        $summary['non_basic_earnings_this_period'] = $nonBasic;
        $summary['gross_pay_this_period'] = round($basicPay + $attendancePremium + $nonBasic, 2);
        $summary['total_pay'] = round($basicPay + $attendancePremium, 2);

        $employeeStatutory = round((float) ($summary['employee_statutory_this_period'] ?? $summary['employee_statutory_total'] ?? 0), 2);
        $customDeductions = round((float) ($summary['custom_deductions_this_period'] ?? 0), 2);
        $withholding = round((float) ($summary['withholding_tax_this_period_estimate'] ?? 0), 2);

        $netPay = round($summary['gross_pay_this_period'] - $employeeStatutory - $customDeductions, 2);
        $summary['net_pay'] = $netPay;
        $summary['net_pay_after_withholding_estimate'] = round($netPay - $withholding, 2);

        return $summary;
    }

    /**
     * @param  mixed  $lines
     * @param  callable(array<string, mixed>): bool  $keep
     * @return list<array<string, mixed>>
     */
    private function filterLines(mixed $lines, callable $keep): array
    {
        $filtered = [];
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && $keep($line)) {
                $filtered[] = $line;
            }
        }

        return array_values($filtered);
    }

    /**
     * @param  mixed  $lines
     */
    private function sumLineAmounts(mixed $lines): float
    {
        $total = 0.0;
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $total += round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
        }

        return round($total, 2);
    }

    /**
     * @param  mixed  $lines
     */
    private function sumRegularPayAmount(mixed $lines): float
    {
        $total = 0.0;
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            if ($this->isRegularPayLine($line)) {
                $total += round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
            }
        }

        return round($total, 2);
    }

    /**
     * @param  mixed  $lines
     */
    private function sumBasicSalaryLineAmount(mixed $lines): float
    {
        $total = 0.0;
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || ! $this->isBasicSalaryLine($line)) {
                continue;
            }
            $total += round(max(0.0, (float) ($line['amount'] ?? $line['resolved_amount'] ?? 0)), 2);
        }

        return round($total, 2);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isBasicSalaryLine(array $line): bool
    {
        $code = strtoupper(trim((string) ($line['component_code'] ?? '')));
        $category = strtolower(trim((string) ($line['category'] ?? '')));

        return $code === 'BASIC_SALARY' || $category === 'basic_pay';
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isRegularPayLine(array $line): bool
    {
        $component = strtolower(trim((string) ($line['component'] ?? $line['component_code'] ?? '')));
        $key = strtolower(trim((string) ($line['key'] ?? '')));

        return $component === 'regular_pay' || str_contains($key, 'regular_pay');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isAttendanceEarningLine(array $line): bool
    {
        return $this->isRegularPayLine($line)
            || $this->isOvertimeLine($line)
            || $this->isHolidayLine($line)
            || $this->isPaidLeaveLine($line);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isPaidLeaveLine(array $line): bool
    {
        $component = strtolower(trim((string) ($line['component'] ?? $line['component_code'] ?? '')));
        if (in_array($component, self::PAID_LEAVE_COMPONENTS, true)) {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($line['key'] ?? ''),
            (string) ($line['category'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
        ]));

        return str_contains($haystack, 'paid leave')
            || str_contains($haystack, 'paid_leave')
            || str_contains($haystack, 'leave adjustments');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isOvertimeLine(array $line): bool
    {
        $component = strtolower(trim((string) ($line['component'] ?? $line['component_code'] ?? '')));
        if (in_array($component, self::OT_COMPONENTS, true)) {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($line['key'] ?? ''),
            (string) ($line['category'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
        ]));

        return str_contains($haystack, 'overtime')
            || str_contains($haystack, 'ot_pay')
            || str_contains($haystack, 'nd_pay')
            || str_contains($haystack, 'night_diff')
            || str_contains($haystack, 'night differential');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isHolidayLine(array $line): bool
    {
        $component = strtolower(trim((string) ($line['component'] ?? $line['component_code'] ?? '')));
        if (in_array($component, self::HOLIDAY_COMPONENTS, true)) {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            (string) ($line['key'] ?? ''),
            (string) ($line['category'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
        ]));

        return str_contains($haystack, 'holiday')
            || str_contains($haystack, 'rest_day_worked');
    }

    /**
     * Holiday Module coverage/scope — rest-day worked pay is not scope-gated.
     *
     * @param  array<string, mixed>  $line
     */
    private function isHolidayLineInScope(array $line): bool
    {
        $haystack = strtolower(implode(' ', [
            (string) ($line['key'] ?? ''),
            (string) ($line['component'] ?? ''),
            (string) ($line['component_code'] ?? ''),
            (string) ($line['label'] ?? ''),
            (string) ($line['name'] ?? ''),
        ]));

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

        return false;
    }
}
