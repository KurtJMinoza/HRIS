<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

/**
 * Payroll-facing holiday decision layer.
 *
 * Holiday coverage, eligibility policy, attendance/leave qualification, and
 * multiplier lookup deliberately remain separate concerns behind this result.
 */
class HolidayPayEvaluationService
{
    public function __construct(
        private readonly HolidayService $holidayService,
        private readonly HolidayPayPolicyService $holidayPayPolicy,
        private readonly PolicyResolverService $policyResolver,
    ) {}

    /**
     * Evaluate one calendar holiday for payroll. An attendance row is not required.
     *
     * @param  Holiday|array<string, mixed>|null  $holiday
     * @param  array{daily_rate?: float, hourly_rate?: float, required_minutes?: int}  $context
     * @return array<string, mixed>
     */
    public function evaluateHoliday(
        User $employee,
        Holiday|array|null $holiday,
        Carbon $date,
        ?PayrollBatchRun $run = null,
        array $context = []
    ): array {
        $dateKey = $date->toDateString();
        $policy = $this->policyResolver->getActivePolicy(
            $employee->getEffectiveCompanyId(),
            $employee->branch_id,
            $dateKey
        );
        $worked = $this->holidayPayPolicy->hasWorkedOnDate($employee, $dateKey);
        $resolution = $this->holidayService->resolveHolidayForPayrollEarnings($employee, $dateKey);

        if ($resolution['holiday'] === null) {
            $fallback = $holiday instanceof Holiday
                ? array_merge($holiday->toArray(), ['date' => $dateKey])
                : (is_array($holiday) ? array_merge($holiday, ['date' => $dateKey]) : ['date' => $dateKey]);

            return array_merge(
                $this->result($fallback, false, false, false, 'No active holiday on this date.', 'not_covered', 0, null, $run),
                ['employee_id' => $employee->id, 'holiday_scope_match' => false]
            );
        }

        $holidayRow = array_merge($resolution['holiday'], ['date' => $dateKey]);
        $calendarScopeMatch = $resolution['calendar_scope_match'];
        $resolvedPolicy = $this->holidayPayPolicy->resolveEffectivePolicy($policy, $holidayRow, $employee->getEffectiveCompanyId());
        $normalizedType = $this->holidayPayPolicy->normalizeHolidayType($holidayRow['type'] ?? null);
        $kind = in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special';

        if (! $calendarScopeMatch && ! $this->holidayPayPolicy->shouldIgnoreHolidayCoverage($resolvedPolicy, $kind, $worked)) {
            return array_merge(
                $this->result($holidayRow, false, false, false, 'Holiday does not cover this employee.', 'not_covered', 0, null, $run),
                [
                    'employee_id' => $employee->id,
                    'holiday_scope_match' => false,
                    'coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, $worked),
                ]
            );
        }

        $paidLeave = $this->holidayPayPolicy->isApprovedPaidLeaveOnDate($employee, $dateKey);

        if ($normalizedType === 'special_working') {
            $eligible = $worked || $paidLeave;
            $reason = $worked
                ? 'Special working day worked; normal daily wage applies.'
                : ($paidLeave ? 'Approved paid leave is paid as leave.' : 'No work and no paid leave on a special working day.');

            return array_merge($this->result(
                $holidayRow,
                $eligible,
                $worked,
                $eligible,
                $reason,
                $worked ? 'normal_wage' : ($paidLeave ? 'leave_pay' : 'no_pay'),
                0,
                null,
                $run
            ), [
                'employee_id' => $employee->id,
                'holiday_scope_match' => $calendarScopeMatch,
                'coverage_override_applied' => ! $calendarScopeMatch,
            ]);
        }

        $schedule = EmployeeScheduleResolver::resolve($employee);
        $scheduleDay = is_array($schedule)
            ? ($schedule[EmployeeScheduleResolver::dayKeyForDate($date)] ?? null)
            : null;
        $isRestDay = ! is_array($scheduleDay) || empty($scheduleDay['in']);
        $dailyRate = (float) ($context['daily_rate'] ?? 0);
        $hourlyRate = (float) ($context['hourly_rate'] ?? ($dailyRate > 0 ? $dailyRate / 8 : 0));
        $requiredMinutes = (int) ($context['required_minutes'] ?? 480);

        $pay = $this->holidayPayPolicy->computeHolidayPay($employee, [
            'date_key' => $dateKey,
            'worked' => $worked,
            'daily_rate' => $dailyRate,
            'hourly_rate' => $hourlyRate,
            'required_minutes' => $requiredMinutes,
            'paid_regular_minutes' => $worked ? $requiredMinutes : 0,
            'is_rest_day' => $isRestDay,
        ], $holidayRow, $policy);
        $determination = $this->holidayPayPolicy->determineEligibility(
            $employee,
            $holidayRow,
            $dateKey,
            $worked,
            $policy,
            $calendarScopeMatch
        );
        $policyBlock = in_array($normalizedType, ['regular', 'double'], true)
            ? (array) ($resolvedPolicy['regular_unworked'] ?? [])
            : (array) ($resolvedPolicy['special_unworked'] ?? []);
        $previousWorkday = in_array($normalizedType, ['regular', 'double'], true)
            ? $this->holidayPayPolicy->getPreviousQualifyingWorkday($employee, $dateKey, $policy, $holidayRow)
            : null;
        $qualified = (bool) ($pay['qualification']['eligible'] ?? false);
        $amount = round((float) ($pay['holiday_premium_pay'] ?? 0), 2);
        $payType = $worked
            ? 'holiday_work_pay'
            : ($amount > 0 ? 'holiday_pay' : 'no_pay');
        $componentCode = $amount > 0
            ? $this->holidayPayPolicy->holidayPayComponentCode($normalizedType, ! $worked)
            : null;
        $shouldCreateWorked = $worked && $qualified && $amount > 0.0001;
        $shouldCreateUnworked = ! $worked && $qualified && $amount > 0.0001;

        return array_merge($this->result(
            $holidayRow,
            $qualified && ($worked || $amount > 0 || $payType === 'leave_pay'),
            $worked,
            $qualified,
            (string) ($pay['qualification']['reason'] ?? 'Not qualified.'),
            $payType,
            $amount,
            $componentCode,
            $run
        ), [
            'employee_id' => $employee->id,
            'employee_company' => $employee->getEffectiveCompanyId(),
            'holiday_scope' => $holidayRow['scope'] ?? null,
            'rule' => $pay['qualification']['rule'] ?? null,
            'rule_code' => $pay['rule_code'] ?? null,
            'multiplier' => $worked ? ($pay['worked_first8_multiplier'] ?? null) : ($pay['unworked_multiplier'] ?? null),
            'multiplier_source' => 'multipliers_tab',
            'policy_source' => 'policy_settings',
            'holiday_scope_match' => $calendarScopeMatch,
            'coverage_override_applied' => ! $calendarScopeMatch,
            'coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, $worked),
            'policy_worked_coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, true),
            'policy_unworked_coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, false),
            'policy_found' => $policy !== null,
            'unworked_policy' => $policyBlock['unworked_pay_policy'] ?? null,
            'employment_type' => $determination['employment_type'] ?? null,
            'allowed_employment_types' => $determination['allowed_employment_types'] ?? [],
            'employment_type_match' => (bool) ($determination['employment_type_match'] ?? false),
            'has_attendance_log' => $worked,
            'worked_on_holiday' => $worked,
            'previous_workday_passed' => $previousWorkday === null ? null : (bool) ($previousWorkday['met'] ?? false),
            'should_create_worked_holiday_pay' => $shouldCreateWorked,
            'should_create_unworked_holiday_pay' => $shouldCreateUnworked,
            'should_create_holiday_pay' => $shouldCreateWorked || $shouldCreateUnworked,
            'line_item_created' => false,
            'skip_reason' => ($shouldCreateWorked || $shouldCreateUnworked)
                ? null
                : (string) ($pay['qualification']['reason'] ?? 'not_eligible'),
        ]);
    }

    /** @return array<string, mixed> */
    private function result(array $holiday, bool $eligible, bool $worked, bool $qualified, string $reason, string $payType, float $amount, ?string $componentCode, ?PayrollBatchRun $run): array
    {
        return [
            'eligible' => $eligible,
            'holiday_id' => $holiday['id'] ?? null,
            'holiday_name' => $holiday['name'] ?? 'Holiday',
            'holiday_type' => $holiday['type'] ?? null,
            'date' => $holiday['date'] ?? null,
            'worked' => $worked,
            'qualified' => $qualified,
            'reason' => $reason,
            'pay_type' => $payType,
            'amount' => round($amount, 2),
            'component_code' => $componentCode,
            'payroll_run_id' => $run?->id,
        ];
    }
}
