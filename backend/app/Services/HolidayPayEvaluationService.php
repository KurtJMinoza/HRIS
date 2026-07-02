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
     * @param  array{daily_rate?: float, hourly_rate?: float, required_minutes?: int, paid_regular_minutes?: int}  $context
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
        $candidateRow = $holiday instanceof Holiday
            ? array_merge($holiday->toArray(), ['date' => $dateKey])
            : (is_array($holiday) ? array_merge($holiday, ['date' => $dateKey]) : null);

        $resolution = $this->holidayService->resolveHolidayForPayrollEarnings($employee, $dateKey);
        $holidayRow = $resolution['holiday'];
        if ($candidateRow !== null && ! empty($candidateRow['id'])) {
            $candidateScope = $this->holidayService->holidayCoversEmployee($candidateRow, $employee);
            if ($candidateScope || $holidayRow === null) {
                $holidayRow = $candidateRow;
            }
        }

        if ($holidayRow === null) {
            return array_merge(
                $this->result(['date' => $dateKey], false, false, false, 'No active holiday on this date.', 'not_covered', 0, null, $run),
                [
                    'employee_id' => $employee->id,
                    'holiday_scope_match' => false,
                    'eligible_for_holiday_evaluation' => false,
                    'skip_reason' => 'no_holiday_on_date',
                ]
            );
        }

        $holidayRow = array_merge($holidayRow, ['date' => $dateKey]);
        $scopeMatch = $this->holidayService->holidayCoversEmployee($holidayRow, $employee);
        $resolvedPolicy = $this->holidayPayPolicy->resolveEffectivePolicy($policy, $holidayRow, $employee->getEffectiveCompanyId());
        $normalizedType = $this->holidayPayPolicy->normalizeHolidayType($holidayRow['type'] ?? null);
        $kind = in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special';
        $gate = $this->holidayPayPolicy->eligibleForHolidayPayEvaluation($employee, $holidayRow, $resolvedPolicy, $kind, $worked);

        if (! $gate['eligible_for_holiday_evaluation']) {
            return array_merge(
                $this->result($holidayRow, false, false, false, 'Holiday does not cover this employee.', 'not_covered', 0, null, $run),
                [
                    'employee_id' => $employee->id,
                    'employee_company' => $employee->getEffectiveCompanyId(),
                    'holiday_scope_match' => $scopeMatch,
                    'scope_match' => $scopeMatch,
                    'eligible_for_holiday_evaluation' => false,
                    'coverage_behaviour' => $gate['coverage_behaviour'],
                    'skip_reason' => 'outside_holiday_scope',
                ]
            );
        }

        $paidLeave = $this->holidayPayPolicy->isApprovedPaidLeaveOnDate($employee, $dateKey);
        $dailyRate = (float) ($context['daily_rate'] ?? 0);
        $hourlyRate = (float) ($context['hourly_rate'] ?? ($dailyRate > 0 ? $dailyRate / 8 : 0));
        $requiredMinutes = (int) ($context['required_minutes'] ?? 480);
        $paidRegularMinutes = (int) ($context['paid_regular_minutes'] ?? 0);
        if ($worked && $paidRegularMinutes <= 0) {
            $paidRegularMinutes = $requiredMinutes > 0 ? $requiredMinutes : 480;
        }

        $schedule = EmployeeScheduleResolver::resolve($employee);
        $scheduleDay = is_array($schedule)
            ? ($schedule[EmployeeScheduleResolver::dayKeyForDate($date)] ?? null)
            : null;
        $isRestDay = ! is_array($scheduleDay) || empty($scheduleDay['in']);

        if ($normalizedType === 'special_working') {
            if ($worked) {
                $pay = $this->holidayPayPolicy->computeHolidayPay($employee, [
                    'date_key' => $dateKey,
                    'worked' => true,
                    'daily_rate' => $dailyRate,
                    'hourly_rate' => $hourlyRate,
                    'required_minutes' => $requiredMinutes,
                    'paid_regular_minutes' => $paidRegularMinutes,
                    'is_rest_day' => $isRestDay,
                ], $holidayRow, $policy);
                $amount = round((float) ($pay['holiday_premium_pay'] ?? 0), 2);
                $componentCode = $amount > 0
                    ? $this->holidayPayPolicy->holidayPayComponentCode('special_working', false, $isRestDay)
                    : null;

                return $this->enrichEvaluation($this->result(
                    $holidayRow,
                    true,
                    true,
                    true,
                    $amount > 0
                        ? 'Special working day worked; holiday premium applies per multipliers.'
                        : 'Special working day worked; ordinary daily wage applies.',
                    $amount > 0 ? 'holiday_work_pay' : 'normal_wage',
                    $amount,
                    $componentCode,
                    $run
                ), $employee, $holidayRow, $scopeMatch, $gate, $worked, $pay, $resolvedPolicy, $kind, $policy);
            }

            $eligible = $paidLeave;

            return $this->enrichEvaluation($this->result(
                $holidayRow,
                $eligible,
                false,
                $eligible,
                $paidLeave
                    ? 'Approved paid leave is paid as leave.'
                    : 'No work and no paid leave on a special working day.',
                $paidLeave ? 'leave_pay' : 'no_pay',
                0,
                null,
                $run
            ), $employee, $holidayRow, $scopeMatch, $gate, false, null, $resolvedPolicy, $kind, $policy);
        }

        $pay = $this->holidayPayPolicy->computeHolidayPay($employee, [
            'date_key' => $dateKey,
            'worked' => $worked,
            'daily_rate' => $dailyRate,
            'hourly_rate' => $hourlyRate,
            'required_minutes' => $requiredMinutes,
            'paid_regular_minutes' => $paidRegularMinutes,
            'is_rest_day' => $isRestDay,
        ], $holidayRow, $policy);
        $determination = $this->holidayPayPolicy->determineEligibility(
            $employee,
            $holidayRow,
            $dateKey,
            $worked,
            $policy,
            $scopeMatch
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
            ? $this->holidayPayPolicy->holidayPayComponentCode($normalizedType, ! $worked, $isRestDay)
            : null;
        $shouldCreateWorked = $worked && $qualified && $amount > 0.0001;
        $shouldCreateUnworked = ! $worked && $qualified && $amount > 0.0001;

        return $this->enrichEvaluation($this->result(
            $holidayRow,
            $qualified && ($worked || $amount > 0 || $payType === 'leave_pay'),
            $worked,
            $qualified,
            (string) ($pay['qualification']['reason'] ?? 'Not qualified.'),
            $payType,
            $amount,
            $componentCode,
            $run
        ), $employee, $holidayRow, $scopeMatch, $gate, $worked, $pay, $resolvedPolicy, $kind, $policy, [
            'determination' => $determination,
            'policy_block' => $policyBlock,
            'previous_workday' => $previousWorkday,
            'should_create_worked' => $shouldCreateWorked,
            'should_create_unworked' => $shouldCreateUnworked,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $pay
     * @param  array<string, mixed>  $extras
     */
    private function enrichEvaluation(
        array $base,
        User $employee,
        array $holidayRow,
        bool $scopeMatch,
        array $gate,
        bool $worked,
        ?array $pay,
        array $resolvedPolicy,
        string $kind,
        ?\App\Models\Policy $policy,
        array $extras = []
    ): array {
        $determination = $extras['determination'] ?? [];
        $policyBlock = $extras['policy_block'] ?? [];
        $previousWorkday = $extras['previous_workday'] ?? null;
        $shouldCreateWorked = $extras['should_create_worked'] ?? ($worked && ($base['amount'] ?? 0) > 0.0001);
        $shouldCreateUnworked = $extras['should_create_unworked'] ?? (! $worked && ($base['amount'] ?? 0) > 0.0001);

        return array_merge($base, [
            'employee_id' => $employee->id,
            'employee_company' => $employee->getEffectiveCompanyId(),
            'holiday_scope' => $holidayRow['scope'] ?? null,
            'holiday_scope_match' => $scopeMatch,
            'scope_match' => $scopeMatch,
            'eligible_for_holiday_evaluation' => true,
            'coverage_override_applied' => ! $scopeMatch,
            'coverage_behaviour' => $gate['coverage_behaviour'],
            'policy_worked_coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, true),
            'policy_unworked_coverage_behaviour' => $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $kind, false),
            'policy_found' => $policy !== null,
            'unworked_policy' => $policyBlock['unworked_pay_policy'] ?? null,
            'employment_type' => $determination['employment_type'] ?? null,
            'allowed_employment_types' => $determination['allowed_employment_types'] ?? [],
            'employment_type_match' => (bool) ($determination['employment_type_match'] ?? false),
            'employment_type_allowed' => (bool) ($determination['employment_type_match'] ?? false),
            'has_attendance_log' => $worked,
            'worked_on_holiday' => $worked,
            'rule' => $pay['qualification']['rule'] ?? null,
            'rule_code' => $pay['rule_code'] ?? null,
            'multiplier' => $worked ? ($pay['worked_first8_multiplier'] ?? null) : ($pay['unworked_multiplier'] ?? null),
            'multiplier_loaded' => $worked
                ? (float) ($pay['worked_first8_multiplier'] ?? 1.0)
                : (float) ($pay['unworked_multiplier'] ?? 0),
            'multiplier_source' => 'policy_settings_multipliers',
            'policy_source' => ($pay['unworked_pay_source'] ?? null) === 'holiday_module_coverage'
                ? 'holiday_module_coverage'
                : 'policy_settings_holiday_pay',
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
