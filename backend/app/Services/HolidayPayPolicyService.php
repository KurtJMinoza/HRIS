<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Policy;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * DOLE holiday eligibility engine. Determines who qualifies; multipliers determine how much.
 */
class HolidayPayPolicyService
{
    public function __construct(
        private readonly AttendanceSessionService $attendanceSession,
        private readonly HolidayService $holidayService,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly PolicyResolverService $policyResolver,
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly ?EmploymentTypeResolver $employmentTypeResolver = null,
    ) {}

    /** @return array<string, mixed> */
    public function resolveHolidayPolicy(?int $companyId, ?Policy $policy = null, ?int $branchId = null, ?string $dateKey = null): array
    {
        if ($policy === null && $dateKey !== null) {
            $policy = $this->policyResolver->getActivePolicy($companyId, $branchId, $dateKey);
        }

        return $this->policyFor($policy, $companyId);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveEffectivePolicy(?Policy $policy, array $holiday, ?int $companyId = null): array
    {
        return $this->policyFor($policy, $companyId ?? $policy?->company_id);
    }

    public function getPreviousQualifyingWorkday(User $employee, string $holidayDate, ?Policy $policy = null, ?array $holiday = null): array
    {
        $holiday ??= $this->holidayService->resolveHolidayForPayroll($employee, $holidayDate) ?? ['date' => $holidayDate];
        $resolved = $this->resolveEffectivePolicy($policy, $holiday, $employee->getEffectiveCompanyId());
        $kind = in_array($this->normalizeHolidayType($holiday['type'] ?? null), ['regular', 'double'], true)
            ? 'regular'
            : 'special';

        return $this->precedingWorkdayRequirement($employee, $holidayDate, $resolved, [], true, $kind);
    }

    public function getFollowingQualifyingWorkday(User $employee, string $holidayDate, ?Policy $policy = null, ?array $holiday = null): array
    {
        $holiday ??= $this->holidayService->resolveHolidayForPayroll($employee, $holidayDate) ?? ['date' => $holidayDate];
        $resolved = $this->resolveEffectivePolicy($policy, $holiday, $employee->getEffectiveCompanyId());
        $kind = in_array($this->normalizeHolidayType($holiday['type'] ?? null), ['regular', 'double'], true)
            ? 'regular'
            : 'special';

        return $this->followingWorkdayRequirement($employee, $holidayDate, $resolved, [], true, $kind);
    }

    public function isQualifiedForUnworkedRegularHoliday(User $employee, array $holiday, string $dateKey, ?Policy $policy = null): bool
    {
        $type = $this->normalizeHolidayType($holiday['type'] ?? null);
        if (! in_array($type, ['regular', 'double'], true)) {
            return false;
        }

        return $this->evaluate($employee, $holiday, $dateKey, false, $policy)['eligible'];
    }

    /**
     * @param  array{date_key?: string, worked?: bool, daily_rate?: float, hourly_rate?: float, is_rest_day?: bool, required_minutes?: int, paid_regular_minutes?: int}  $attendance
     * @return array{
     *   eligible: bool,
     *   qualification: array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string},
     *   unworked_multiplier: float,
     *   worked_first8_multiplier: float,
     *   rule_code: string,
     *   holiday_premium_pay: float,
     *   breakdown: ?array<string, mixed>
     * }
     */
    public function computeHolidayPay(User $employee, array $attendance, array $holiday, ?Policy $policy = null): array
    {
        $companyId = $employee->getEffectiveCompanyId();
        $dateKey = (string) ($attendance['date_key'] ?? $holiday['date'] ?? '');
        $worked = (bool) ($attendance['worked'] ?? false);
        $dailyRate = (float) ($attendance['daily_rate'] ?? 0);
        $hourlyRate = (float) ($attendance['hourly_rate'] ?? ($dailyRate > 0 ? $dailyRate / 8 : 0));
        $isRestDay = (bool) ($attendance['is_rest_day'] ?? false);
        $requiredMinutes = (int) ($attendance['required_minutes'] ?? 480);
        $paidRegularMinutes = (int) ($attendance['paid_regular_minutes'] ?? 0);

        if (! $worked && $isRestDay) {
            $resolvedHolidayType = $this->rulesEngine->holidayTypeFromHolidayRow($holiday);

            return [
                'eligible' => false,
                'qualification' => $this->result(
                    false,
                    true,
                    'Unworked holiday pay does not apply on an employee scheduled rest day.',
                    'unworked_holiday_on_rest_day'
                ),
                'unworked_multiplier' => 0.0,
                'unworked_pay_source' => null,
                'worked_first8_multiplier' => 1.0,
                'rule_code' => $this->rulesEngine->resolveRuleCode(true, $resolvedHolidayType),
                'holiday_premium_pay' => 0.0,
                'breakdown' => null,
            ];
        }

        $resolvedPolicy = $this->resolveEffectivePolicy(
            $policy,
            $holiday,
            $companyId
        );
        $scopeMatch = $this->holidayService->holidayCoversEmployee(
            array_merge($holiday, ['date' => $dateKey]),
            $employee
        );
        $holidayModuleGrant = ! $worked && $this->holidayModuleGrantsUnworkedPay($holiday, $scopeMatch);
        $qualification = $this->determineEligibility(
            $employee,
            $holiday,
            $dateKey,
            $worked,
            $policy,
            $scopeMatch
        );
        $unworkedMultiplier = $holidayModuleGrant
            ? $this->holidayModuleUnworkedMultiplier($holiday)
            : $this->unworkedMultiplier($holiday, $resolvedPolicy);

        $resolvedHolidayType = $this->rulesEngine->holidayTypeFromHolidayRow($holiday);
        $ruleCode = $this->rulesEngine->resolveRuleCode($isRestDay, $resolvedHolidayType);
        $rateMultipliers = $this->policyResolver->getMultipliersForRule($policy, $ruleCode);
        $statutoryFirst8 = (float) $rateMultipliers['first_8'];
        $workedFirst8 = ($qualification['eligible'] && $worked && $statutoryFirst8 > 1.00001)
            ? $statutoryFirst8
            : 1.0;

        $holidayPremiumPay = 0.0;
        $breakdown = null;

        if (! $worked && $qualification['eligible'] && $unworkedMultiplier > 0) {
            $holidayPremiumPay = round($dailyRate * $unworkedMultiplier, 2);
            $normalizedType = $this->normalizeHolidayType($holiday['type'] ?? null) ?? 'regular';
            $componentCode = $this->holidayPayComponentCode($normalizedType, true, $isRestDay);
            $holidayName = (string) ($holiday['name'] ?? 'Holiday');
            $breakdown = [
                'component' => 'holiday_premium',
                'component_code' => $componentCode,
                'description' => $this->holidayPayDescription($componentCode, $holidayName),
                'minutes' => $requiredMinutes > 0 ? $requiredMinutes : 480,
                'rate' => $hourlyRate,
                'multiplier' => $unworkedMultiplier,
                'premium_multiplier' => $unworkedMultiplier,
                'holiday_id' => $holiday['id'] ?? null,
                'holiday_name' => $holidayName,
                'holiday_type' => $holiday['type'] ?? null,
                'amount' => $holidayPremiumPay,
                'worked' => false,
                'unworked' => true,
                'policy_source' => $holidayModuleGrant ? 'holiday_module_coverage' : 'policy_settings',
                'previous_workday_passed' => $qualification['attendance_requirement_met'] ?? null,
                'attendance_rule_applied' => $qualification['rule'] ?? null,
            ];
        } elseif ($worked && $qualification['eligible'] && $paidRegularMinutes > 0 && $workedFirst8 > 1.00001) {
            $normalizedType = $this->normalizeHolidayType($holiday['type'] ?? null) ?? 'regular';
            $componentCode = $this->holidayPayComponentCode($normalizedType, false, $isRestDay);
            $holidayName = (string) ($holiday['name'] ?? 'Holiday');
            $basePay = round(($paidRegularMinutes / 60.0) * $hourlyRate, 2);
            // SH on a workday and any worked holiday on a rest day (RHRD/SHRD/DHRD) use the full statutory rate on one line.
            $useFullRateOnHolidayLine = ($normalizedType === 'special' && ! $isRestDay) || $isRestDay;
            $premiumIncrement = $useFullRateOnHolidayLine
                ? $workedFirst8
                : max(0.0, $workedFirst8 - 1.0);
            $holidayPremiumPay = round($basePay * ($useFullRateOnHolidayLine ? $workedFirst8 : $premiumIncrement), 2);
            $breakdown = [
                'component' => 'holiday_premium',
                'component_code' => $componentCode,
                'description' => $this->holidayPayDescription($componentCode, $holidayName),
                'minutes' => $paidRegularMinutes,
                'rate' => $hourlyRate,
                'multiplier' => $workedFirst8,
                'premium_multiplier' => round($premiumIncrement, 2),
                'holiday_id' => $holiday['id'] ?? null,
                'holiday_name' => $holidayName,
                'holiday_type' => $holiday['type'] ?? null,
                'amount' => $holidayPremiumPay,
                'worked' => true,
                'unworked' => false,
                'policy_source' => 'policy_settings',
                'multiplier_source' => 'multipliers_tab',
                'attendance_rule_applied' => $qualification['rule'] ?? null,
            ];
        }

        return [
            'eligible' => $qualification['eligible'],
            'qualification' => $qualification,
            'unworked_multiplier' => $unworkedMultiplier,
            'unworked_pay_source' => $holidayModuleGrant ? 'holiday_module_coverage' : 'policy_settings',
            'worked_first8_multiplier' => $workedFirst8,
            'rule_code' => $ruleCode,
            'holiday_premium_pay' => $holidayPremiumPay,
            'breakdown' => $breakdown,
        ];
    }

    public function workedMultiplierForHoliday(array $holiday, ?Policy $policy, bool $isRestDay = false, ?int $companyId = null, ?int $branchId = null, ?string $dateKey = null): float
    {
        $policy ??= $this->policyResolver->getActivePolicy($companyId, $branchId, $dateKey ?? (string) ($holiday['date'] ?? now()->toDateString()));
        $resolved = $this->resolveEffectivePolicy($policy, $holiday, $companyId);
        $resolvedHolidayType = $this->resolvedPayrollHolidayType($holiday, $resolved);
        $ruleCode = $this->rulesEngine->resolveRuleCode($isRestDay, $resolvedHolidayType);

        return (float) $this->policyResolver->getMultipliersForRule($policy, $ruleCode)['first_8'];
    }

    /** @param  array<string, mixed>  $holiday  @param  array<string, mixed>  $policy */
    public function resolvedPayrollHolidayType(array $holiday, array $policy): ?string
    {
        $raw = $this->normalize((string) ($holiday['type'] ?? ''));
        $nonStatutoryKey = match ($raw) {
            'special_working', 'special_working_day' => 'special_working',
            'company', 'company_event' => 'company',
            default => null,
        };
        if ($nonStatutoryKey !== null) {
            $payOrdinary = (bool) (($policy['non_statutory'][$nonStatutoryKey]['pay_as_ordinary_day'] ?? true));

            return $payOrdinary ? null : 'special';
        }

        return $this->rulesEngine->holidayTypeFromHolidayRow($holiday);
    }

    public function isLeaveBillableOnDate(User $user, string $dateKey): bool
    {
        $holiday = $this->holidayService->resolveHolidayForPayroll($user, $dateKey);
        if ($holiday === null) {
            return true;
        }

        return in_array($this->normalizeHolidayType($holiday['type'] ?? null), ['special_working'], true);
    }

    /** @return array<string, mixed> */
    public function policyFor(?Policy $policy, ?int $companyId): array
    {
        $companyId = max(0, (int) $companyId);
        HolidayPolicyCache::trackCompany($companyId);
        $cacheKey = HolidayPolicyCache::policyKey($companyId);
        $policyKey = (string) ($policy?->id ?? 'default');
        if ($policy?->id === null) {
            return $this->normalizeResolvedPolicy(
                $policy ? $policy->resolvedHolidayPolicy() : Policy::DEFAULT_HOLIDAY_POLICY
            );
        }

        $cached = Cache::get($cacheKey, []);
        $cached = is_array($cached) ? $cached : [];

        if (isset($cached[$policyKey]) && is_array($cached[$policyKey])) {
            return $cached[$policyKey];
        }

        $resolved = $policy
            ? $policy->resolvedHolidayPolicy()
            : Policy::DEFAULT_HOLIDAY_POLICY;
        $resolved = $this->normalizeResolvedPolicy($resolved);

        $cached[$policyKey] = $resolved;
        Cache::put($cacheKey, $cached, HolidayPolicyCache::TTL_SECONDS);

        return $resolved;
    }

    /** @return array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string} */
    public function evaluate(User $employee, array $holiday, string $dateKey, bool $worked, ?Policy $policy = null): array
    {
        return $this->determineEligibility($employee, $holiday, $dateKey, $worked, $policy);
    }

    /**
     * Full eligibility result for payroll, attendance, and audit.
     *
     * Order: holiday scope (caller) → policy → employment type → attendance qualification.
     *
     * @return array{
     *   eligible: bool,
     *   reason: string,
     *   attendance_requirement_met: bool,
     *   policy_match: bool,
     *   company_override: bool,
     *   holiday_scope_match: bool,
     *   rule: string
     * }
     */
    public function determineEligibility(User $employee, array $holiday, string $dateKey, bool $worked, ?Policy $policy = null, ?bool $calendarScopeMatch = null): array
    {
        $resolved = $this->resolveEffectivePolicy($policy, $holiday, $employee->getEffectiveCompanyId());
        $normalizedType = $this->normalizeHolidayType($holiday['type'] ?? null);
        $holidayScopeMatch = $calendarScopeMatch ?? ($this->holidayService->resolveHolidayForPayroll($employee, $dateKey) !== null);
        $kind = in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special';
        $unworkedPolicy = $this->unworkedPayPolicy($resolved, $kind);
        $employmentType = $this->employmentTypeResolver()->resolveForEmployee($employee);
        $laborEmploymentType = $employmentType === 'execom'
            ? $this->employmentTypeResolver()->resolveLaborEmploymentType($employee)
            : $employmentType;
        $allowedEmploymentTypes = $this->eligibleEmploymentTypes($resolved, $kind);
        $employmentTypeMode = $this->employmentTypeMode($resolved, $kind);
        // EXECOM is a payroll module, not a DOLE class — still match selected regular/full_time/etc.
        $employmentTypeMatch = $this->employmentTypeAllowed($employmentTypeMode, $employmentType, $allowedEmploymentTypes, $kind)
            || (
                $employmentType === 'execom'
                && $laborEmploymentType !== ''
                && $this->employmentTypeAllowed($employmentTypeMode, $laborEmploymentType, $allowedEmploymentTypes, $kind)
            );
        $workedEmploymentRule = $this->workedEmploymentTypeRule($resolved, $kind);
        $workedAllowedEmploymentTypes = $this->eligibleEmploymentTypesForWorked($resolved, $kind);
        $workedEmploymentTypeMatch = $this->workedEmploymentTypeAllowed(
            $workedEmploymentRule,
            $employmentType,
            $workedAllowedEmploymentTypes
        ) || (
            $employmentType === 'execom'
            && $laborEmploymentType !== ''
            && $this->workedEmploymentTypeAllowed(
                $workedEmploymentRule,
                $laborEmploymentType,
                $workedAllowedEmploymentTypes
            )
        );
        // Employees inside Holiday-module coverage remain eligible under the
        // unworked-pay policy. A selected-holiday list only narrows an
        // ignore-coverage override for employees outside that scope.
        $holidayAllowedForUnworkedPay = $holidayScopeMatch
            || $this->holidaySelectedForUnworkedPay($resolved, $kind, $holiday);
        $holidayModuleGrant = ! $worked && $this->holidayModuleGrantsUnworkedPay($holiday, $holidayScopeMatch);

        if ($worked) {
            $result = $workedEmploymentTypeMatch
                ? $this->result(
                    true,
                    true,
                    'Worked on the holiday; the preceding-workday condition does not bar holiday work pay.',
                    'worked_holiday'
                )
                : $this->result(
                    false,
                    true,
                    'Employee employment type is not selected for worked holiday pay.',
                    'worked_employment_type_excluded'
                );
        } elseif (in_array($normalizedType, ['special_working', 'company'], true) || $normalizedType === null) {
            $result = $this->result(false, true, 'This day does not carry unworked holiday pay.', 'not_non_working_holiday');
        } elseif ($normalizedType === 'special' && $unworkedPolicy === self::UNWORKED_NO_PAY) {
            $result = $this->result(false, true, 'Special non-working holiday follows No Work, No Pay.', 'special_no_work_no_pay');
        } elseif (! $holidayAllowedForUnworkedPay) {
            $result = $this->result(
                false,
                true,
                'This holiday is not selected for unworked holiday pay.',
                $kind.'_holiday_not_selected'
            );
        } elseif ($normalizedType === 'special' && in_array($unworkedPolicy, [self::UNWORKED_PAID_LEAVE, self::UNWORKED_PAID_LEAVE_ONLY], true)) {
            $paidLeave = $this->hasApprovedPaidLeave($employee, $dateKey);
            $result = $this->result(
                $paidLeave,
                true,
                $paidLeave ? 'Approved paid leave is paid as leave.' : 'No approved paid leave covers this special holiday.',
                $paidLeave ? 'special_paid_leave' : 'special_no_paid_leave'
            );
        } elseif ($normalizedType === 'special' && ! $employmentTypeMatch) {
            $result = $this->result(false, true, 'Employee employment type is not selected for unworked special holiday pay.', 'special_employment_type_excluded');
        } elseif ($normalizedType === 'special') {
            $attendance = $this->resolveUnworkedAttendanceRules($resolved, 'special');
            $prevRequired = (bool) ($attendance['require_previous_workday_presence'] ?? false);
            $followRequired = (bool) ($attendance['require_following_workday_presence'] ?? false);

            if (! $prevRequired && ! $followRequired) {
                $result = $this->result(true, true, 'Company policy pays this covered employee for the unworked special holiday.', 'special_unworked_company_policy');
            } else {
                $qualification = $this->evaluateWorkdayAttendanceRequirements(
                    $employee,
                    $dateKey,
                    $resolved,
                    $prevRequired,
                    $followRequired,
                    'special'
                );
                $result = $this->result(
                    $qualification['met'],
                    $qualification['met'],
                    $qualification['reason'],
                    $qualification['rule']
                );
            }
        } elseif (! $holidayModuleGrant && in_array($normalizedType, ['regular', 'double'], true)
            && ($unworkedPolicy === self::UNWORKED_DISABLED || ! ($resolved['pay_unworked_regular'] ?? true))) {
            $result = $this->result(false, true, 'Unworked regular holiday pay is disabled for this policy.', 'unworked_regular_disabled');
        } elseif (! $holidayModuleGrant && in_array($normalizedType, ['regular', 'double'], true) && ! $employmentTypeMatch) {
            $result = $this->result(false, true, 'Employee employment type is not selected for unworked regular holiday pay.', 'regular_employment_type_excluded');
        } elseif (in_array($normalizedType, ['regular', 'double'], true)
            && (bool) ($resolved['regular_unworked']['always_pay'] ?? false)) {
            $result = $this->result(true, true, 'Always Pay Regular Holiday policy override applies.', 'regular_always_pay_override');
        } else {
            $attendance = $this->resolveUnworkedAttendanceRules($resolved, 'regular');
            $prevRequired = (bool) ($attendance['require_previous_workday_presence'] ?? true);
            $followRequired = (bool) ($attendance['require_following_workday_presence'] ?? false);

            if (! $prevRequired && ! $followRequired) {
                $result = $this->result(true, true, 'Company policy waives the workday attendance conditions.', 'company_attendance_waiver');
            } else {
                $qualification = $this->evaluateWorkdayAttendanceRequirements(
                    $employee,
                    $dateKey,
                    $resolved,
                    $prevRequired,
                    $followRequired,
                    'regular'
                );
                $result = $this->result(
                    $qualification['met'],
                    $qualification['met'],
                    $qualification['reason'],
                    $qualification['rule']
                );
            }
        }

        $appliedAttendance = $this->resolveUnworkedAttendanceRules(
            $resolved,
            in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special'
        );

        $payload = [
            'eligible' => $result['eligible'],
            'reason' => $result['reason'],
            'attendance_requirement_met' => $result['attendance_requirement_met'],
            'policy_match' => $holidayScopeMatch,
            'employment_type' => $employmentType,
            'allowed_employment_types' => $allowedEmploymentTypes,
            'employment_type_mode' => $employmentTypeMode,
            'employment_type_match' => $worked ? $workedEmploymentTypeMatch : $employmentTypeMatch,
            'worked_employment_type_rule' => $workedEmploymentRule,
            'worked_allowed_employment_types' => $workedAllowedEmploymentTypes,
            'company_override' => ($appliedAttendance['require_previous_workday_presence'] ?? true) === false
                && ($appliedAttendance['require_following_workday_presence'] ?? false) === false,
            'holiday_scope_match' => $holidayScopeMatch,
            'rule' => $result['rule'],
        ];

        if (filter_var(config('payroll.debug_holiday_eligibility', false), FILTER_VALIDATE_BOOL)) {
            Log::debug('holiday_eligibility', array_merge($payload, [
                'employee_id' => $employee->id,
                'holiday_id' => $holiday['id'] ?? null,
                'holiday_name' => $holiday['name'] ?? null,
                'holiday_date' => $dateKey,
                'worked' => $worked,
                'employment_type' => $employmentType,
                'allowed_employment_types' => $allowedEmploymentTypes,
                'employment_type_match' => $worked ? $workedEmploymentTypeMatch : $employmentTypeMatch,
                'unworked_pay_policy_regular' => $this->unworkedPayPolicy($resolved, 'regular'),
                'unworked_pay_policy_special' => $this->unworkedPayPolicy($resolved, 'special'),
            ]));
        }

        return $payload;
    }

    /**
     * Full unworked-holiday decision for payroll draft generation (no attendance log required).
     *
     * @return array<string, mixed>
     */
    public function shouldPayUnworkedHoliday(User $employee, array $holiday, string $dateKey, ?Policy $policy = null, ?bool $calendarScopeMatch = null): array
    {
        $companyId = $employee->getEffectiveCompanyId();
        $resolved = $this->resolveEffectivePolicy($policy, $holiday, $companyId);
        $normalizedType = $this->normalizeHolidayType($holiday['type'] ?? null);
        $kind = in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special';
        $workedOnHoliday = $this->workedOn($employee, $dateKey);
        $hasAttendanceLog = $workedOnHoliday;
        $holidayScopeMatch = $calendarScopeMatch ?? ($this->holidayService->resolveHolidayForPayroll($employee, $dateKey) !== null);
        $determination = $this->determineEligibility($employee, $holiday, $dateKey, $workedOnHoliday, $policy, $holidayScopeMatch);
        $previousWorkday = $this->getPreviousQualifyingWorkday($employee, $dateKey, $policy, $holiday);
        $followingWorkday = $this->getFollowingQualifyingWorkday($employee, $dateKey, $policy, $holiday);
        $unworkedMultiplier = $this->holidayModuleGrantsUnworkedPay($holiday, $holidayScopeMatch)
            ? $this->holidayModuleUnworkedMultiplier($holiday)
            : $this->unworkedMultiplier($holiday, $resolved);
        $shouldPay = ! $workedOnHoliday
            && ($determination['eligible'] ?? false)
            && $unworkedMultiplier > 0.00001;

        $payload = [
            'employee_id' => $employee->id,
            'employee_name' => (string) ($employee->display_name ?? $employee->name ?? ''),
            'holiday_id' => $holiday['id'] ?? null,
            'holiday_name' => $holiday['name'] ?? null,
            'holiday_type' => $holiday['type'] ?? null,
            'holiday_scope_match' => $holidayScopeMatch,
            'policy_found' => $policy !== null,
            'unworked_pay_policy' => $this->unworkedPayPolicy($resolved, $kind),
            'employment_type' => $determination['employment_type'] ?? null,
            'allowed_employment_types' => $determination['allowed_employment_types'] ?? [],
            'employment_type_match' => (bool) ($determination['employment_type_match'] ?? false),
            'has_attendance_log' => $hasAttendanceLog,
            'worked_on_holiday' => $workedOnHoliday,
            'previous_workday_date' => $previousWorkday['date'] ?? null,
            'previous_workday_status' => ($previousWorkday['met'] ?? false) ? 'qualified' : 'not_qualified',
            'previous_workday_passed' => (bool) ($previousWorkday['met'] ?? false),
            'following_workday_date' => $followingWorkday['date'] ?? null,
            'following_workday_status' => ($followingWorkday['met'] ?? false) ? 'qualified' : 'not_qualified',
            'following_workday_passed' => (bool) ($followingWorkday['met'] ?? false),
            'should_pay_unworked_holiday' => $shouldPay,
            'line_item_created' => false,
            'skip_reason' => $shouldPay ? null : (string) ($determination['reason'] ?? 'not_eligible'),
        ];

        if (filter_var(config('payroll.debug_holiday_eligibility', false), FILTER_VALIDATE_BOOL)) {
            Log::debug('holiday_unworked_pay', $payload);
        }

        return $payload;
    }

    public function holidayPayComponentCode(
        ?string $normalizedHolidayType,
        bool $unworked = true,
        bool $isRestDay = false
    ): string {
        if ($normalizedHolidayType === 'special_working') {
            return 'SPECIAL_WORKING_DAY_PAY';
        }

        if ($isRestDay) {
            if (in_array($normalizedHolidayType, ['regular', 'double'], true)) {
                return $unworked
                    ? 'RESTDAY_REGULAR_HOLIDAY_UNWORKED_PAY'
                    : 'RESTDAY_REGULAR_HOLIDAY_PAY';
            }

            return $unworked
                ? 'RESTDAY_SPECIAL_HOLIDAY_UNWORKED_PAY'
                : 'RESTDAY_SPECIAL_HOLIDAY_PAY';
        }

        if (! $unworked) {
            return in_array($normalizedHolidayType, ['regular', 'double'], true)
                ? 'REGULAR_HOLIDAY_WORKED_PAY'
                : 'SPECIAL_HOLIDAY_WORKED_PAY';
        }

        return in_array($normalizedHolidayType, ['regular', 'double'], true)
            ? 'REGULAR_HOLIDAY_UNWORKED_PAY'
            : 'SPECIAL_HOLIDAY_UNWORKED_PAY';
    }

    public function holidayPayDescription(string $componentCode, string $holidayName): string
    {
        $prefix = match ($componentCode) {
            'REGULAR_HOLIDAY_UNWORKED_PAY' => 'Regular Holiday — Unworked Pay',
            'SPECIAL_HOLIDAY_UNWORKED_PAY' => 'Special Holiday — Unworked Pay',
            'REGULAR_HOLIDAY_WORKED_PAY', 'REGULAR_HOLIDAY_PAY' => 'Regular Holiday — Worked Pay',
            'SPECIAL_HOLIDAY_WORKED_PAY', 'SPECIAL_HOLIDAY_PAY' => 'Special Holiday — Worked Pay',
            'SPECIAL_WORKING_DAY_PAY' => 'Special Working Day Pay',
            'RESTDAY_REGULAR_HOLIDAY_PAY' => 'Regular Holiday — Rest Day Worked Pay',
            'RESTDAY_SPECIAL_HOLIDAY_PAY' => 'Special Holiday — Rest Day Worked Pay',
            'RESTDAY_REGULAR_HOLIDAY_UNWORKED_PAY' => 'Regular Holiday — Rest Day Unworked Pay',
            'RESTDAY_SPECIAL_HOLIDAY_UNWORKED_PAY' => 'Special Holiday — Rest Day Unworked Pay',
            default => 'Holiday Pay',
        };

        return $prefix.': '.$holidayName;
    }

    public function unworkedMultiplier(array $holiday, array $policy): float
    {
        return match ($this->normalizeHolidayType($holiday['type'] ?? null)) {
            'regular' => 1.0,
            'double' => 2.0,
            'special' => in_array($this->unworkedPayPolicy($policy, 'special'), [
                self::UNWORKED_COVERED,
                self::UNWORKED_ALL,
                self::UNWORKED_ALL_TYPES,
                'selected_employment_types',
                self::UNWORKED_PAID_LEAVE,
                self::UNWORKED_PAID_LEAVE_ONLY,
                self::UNWORKED_COMPANY_POLICY,
                self::UNWORKED_CBA,
            ], true)
                ? 1.0
                : 0.0,
            default => 0.0,
        };
    }

    /**
     * Persisted Admin Regular Holidays grant unworked pay to covered employees.
     * Special non-working holidays stay No Work, No Pay unless Policy Settings
     * explicitly enables special_unworked.
     */
    private function holidayModuleGrantsUnworkedPay(array $holiday, bool $scopeMatch): bool
    {
        if (! $scopeMatch || ! is_numeric($holiday['id'] ?? null) || (int) $holiday['id'] <= 0) {
            return false;
        }

        return in_array($this->normalizeHolidayType($holiday['type'] ?? null), [
            'regular',
            'double',
        ], true);
    }

    private function holidayModuleUnworkedMultiplier(array $holiday): float
    {
        return $this->normalizeHolidayType($holiday['type'] ?? null) === 'double' ? 2.0 : 1.0;
    }

    public const UNWORKED_NO_PAY = 'no_work_no_pay';

    public const UNWORKED_COVERED = 'covered_employees';

    public const UNWORKED_DOLE_DEFAULT = 'dole_default';

    public const UNWORKED_ALL = 'all_employees';

    public const UNWORKED_ALL_TYPES = 'all_employment_types';

    public const UNWORKED_DISABLED = 'disabled';

    public const UNWORKED_PAID_LEAVE = 'paid_leave';

    public const UNWORKED_PAID_LEAVE_ONLY = 'paid_leave_only';

    public const UNWORKED_COMPANY_POLICY = 'company_policy';

    public const UNWORKED_CBA = 'cba';

    public const COVERAGE_RESPECT = 'respect_coverage';

    public const COVERAGE_IGNORE = 'ignore_coverage';

    /**
     * Whether the matching unworked/worked panel is set to ignore coverage.
     * Calendar/attendance always respect coverage regardless of this setting.
     */
    public function shouldIgnoreHolidayCoverage(array $resolvedPolicy, string $holidayKind, bool $worked): bool
    {
        return $this->coverageBehaviour($resolvedPolicy, $holidayKind, $worked) === self::COVERAGE_IGNORE;
    }

    /**
     * Outside-scope payroll gate for a specific holiday.
     *
     * - Unworked: Step 1 Ignore Coverage.
     * - Worked: Step 3 worked Ignore Coverage, OR Step 1 Ignore Coverage when this
     *   holiday is included in the selected/all unworked holiday list (so checking TEST
     *   pays outside-scope employees who worked that day, without reopening every holiday).
     *
     * @param  array<string, mixed>  $resolvedPolicy
     * @param  array<string, mixed>  $holiday
     */
    public function mayPayOutsideHolidayCoverage(
        array $resolvedPolicy,
        string $holidayKind,
        bool $worked,
        array $holiday
    ): bool {
        if ($holidayKind === 'special' && ! $worked
            && $this->unworkedPayPolicy($resolvedPolicy, 'special') === self::UNWORKED_NO_PAY) {
            return false;
        }

        if ($this->shouldIgnoreHolidayCoverage($resolvedPolicy, $holidayKind, $worked)) {
            return true;
        }

        // Step 1 Ignore + included holiday also opens worked premium outside scope.
        return $worked
            && $this->shouldIgnoreHolidayCoverage($resolvedPolicy, $holidayKind, false)
            && $this->holidaySelectedForUnworkedPay($resolvedPolicy, $holidayKind, $holiday);
    }

    /**
     * Payroll-only gate: in-scope employees always evaluate; out-of-scope only when policy ignores coverage.
     *
     * @return array{scope_match: bool, coverage_behaviour: string, eligible_for_holiday_evaluation: bool}
     */
    public function eligibleForHolidayPayEvaluation(
        User $employee,
        array $holiday,
        array $resolvedPolicy,
        string $kind,
        bool $worked
    ): array {
        $scopeMatch = $this->holidayService->holidayCoversEmployee($holiday, $employee);
        $coverageBehaviour = $this->coverageBehaviour($resolvedPolicy, $kind, $worked);
        $eligible = $scopeMatch
            || $this->mayPayOutsideHolidayCoverage($resolvedPolicy, $kind, $worked, $holiday);

        return [
            'scope_match' => $scopeMatch,
            'coverage_behaviour' => $coverageBehaviour,
            'eligible_for_holiday_evaluation' => $eligible,
        ];
    }

    public function coverageBehaviour(array $resolvedPolicy, string $holidayKind, bool $worked): string
    {
        $blockKey = match ($holidayKind) {
            'regular' => $worked ? 'regular_worked' : 'regular_unworked',
            'special' => $worked ? 'special_worked' : 'special_unworked',
            default => $worked ? 'regular_worked' : 'regular_unworked',
        };
        $behaviour = (string) (($resolvedPolicy[$blockKey]['coverage_behaviour'] ?? null)
            ?? ($worked ? self::COVERAGE_RESPECT : self::COVERAGE_RESPECT));

        return in_array($behaviour, [self::COVERAGE_IGNORE, self::COVERAGE_RESPECT], true)
            ? $behaviour
            : self::COVERAGE_RESPECT;
    }

    public function normalizeHolidayType(mixed $type): ?string
    {
        $value = $this->normalize((string) $type);

        return match ($value) {
            'regular', 'regular_holiday' => 'regular',
            'double', 'double_holiday' => 'double',
            'special_working', 'special_working_day' => 'special_working',
            'special', 'special_non_working', 'special_non_working_holiday' => 'special',
            'company', 'company_event' => 'company',
            default => null,
        };
    }

    /** @return array{date: ?string, met: bool, reason: string, rule: string} */
    private function precedingWorkdayRequirement(
        User $employee,
        string $dateKey,
        array $policy,
        array $visited,
        bool $includeDate = false,
        string $kind = 'regular'
    ): array {
        if (isset($visited[$dateKey]) || count($visited) > 370) {
            return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
        }
        $visited[$dateKey] = true;
        $cursor = Carbon::parse($dateKey)->subDay();
        $schedule = EmployeeScheduleResolver::resolve($employee);
        $attendance = $this->resolveUnworkedAttendanceRules($policy, $kind);

        while (count($visited) <= 370) {
            $priorKey = $cursor->toDateString();
            if (isset($visited[$priorKey])) {
                return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
            }

            $priorHoliday = $this->holidayService->resolveHolidayForPayroll($employee, $priorKey);
            $priorType = $this->normalizeHolidayType($priorHoliday['type'] ?? null);
            if ($priorHoliday !== null && in_array($priorType, ['regular', 'double'], true)
                && (bool) ($policy['regular_unworked']['successive_holiday_rule'] ?? true)) {
                if ($this->workedOn($employee, $priorKey)) {
                    return [
                        'date' => $includeDate ? $priorKey : null,
                        'met' => true,
                        'reason' => 'Work on the first regular holiday qualifies the succeeding regular holiday.',
                        'rule' => 'successive_holiday_worked_first',
                    ];
                }

                $chain = $this->precedingWorkdayRequirement($employee, $priorKey, $policy, $visited, $includeDate, $kind);

                return [
                    'date' => $chain['date'],
                    'met' => $chain['met'],
                    'reason' => $chain['met']
                        ? 'The condition before the first regular holiday qualifies the successive holidays.'
                        : 'The condition before the first regular holiday was not met for the successive holidays.',
                    'rule' => 'successive_holiday_chain',
                ];
            }

            if ($this->shouldSkipDate($schedule, $cursor, $priorType, $attendance)) {
                $visited[$priorKey] = true;
                $cursor->subDay();

                continue;
            }

            return $this->workdayAttendanceResult($employee, $priorKey, $attendance, $includeDate, 'preceding');
        }

        return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
    }

    /** @return array{date: ?string, met: bool, reason: string, rule: string} */
    private function followingWorkdayRequirement(
        User $employee,
        string $dateKey,
        array $policy,
        array $visited,
        bool $includeDate = false,
        string $kind = 'regular'
    ): array {
        $attendance = $this->resolveUnworkedAttendanceRules($policy, $kind);
        if (! ($attendance['require_following_workday_presence'] ?? false)) {
            return [
                'date' => null,
                'met' => true,
                'reason' => 'Following-workday attendance is not required.',
                'rule' => 'following_workday_not_required',
            ];
        }

        if (isset($visited[$dateKey]) || count($visited) > 370) {
            return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a following working day.', 'rule' => 'attendance_unresolved'];
        }
        $visited[$dateKey] = true;
        $cursor = Carbon::parse($dateKey)->addDay();
        $schedule = EmployeeScheduleResolver::resolve($employee);

        while (count($visited) <= 370) {
            $nextKey = $cursor->toDateString();
            if (isset($visited[$nextKey])) {
                return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a following working day.', 'rule' => 'attendance_unresolved'];
            }

            $nextHoliday = $this->holidayService->resolveHolidayForPayroll($employee, $nextKey);
            $nextType = $this->normalizeHolidayType($nextHoliday['type'] ?? null);
            if ($this->shouldSkipDate($schedule, $cursor, $nextType, $attendance)) {
                $visited[$nextKey] = true;
                $cursor->addDay();

                continue;
            }

            if ($this->isFutureAttendanceDate($nextKey)) {
                return [
                    'date' => $includeDate ? $nextKey : null,
                    'met' => false,
                    'reason' => 'The following working day has not occurred yet.',
                    'rule' => 'following_workday_not_yet_occurred',
                ];
            }

            return $this->workdayAttendanceResult(
                $employee,
                $nextKey,
                $attendance,
                $includeDate,
                'following'
            );
        }

        return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a following working day.', 'rule' => 'attendance_unresolved'];
    }

    /** @return array{met: bool, reason: string, rule: string} */
    private function evaluateWorkdayAttendanceRequirements(
        User $employee,
        string $dateKey,
        array $policy,
        bool $previousRequired,
        bool $followingRequired,
        string $kind = 'regular'
    ): array {
        $preceding = null;
        if ($previousRequired) {
            $preceding = $this->precedingWorkdayRequirement($employee, $dateKey, $policy, [], false, $kind);
            if (! $preceding['met']) {
                return $preceding;
            }
        }

        if ($followingRequired) {
            return $this->followingWorkdayRequirement($employee, $dateKey, $policy, [], false, $kind);
        }

        if ($preceding !== null) {
            return $preceding;
        }

        return [
            'met' => true,
            'reason' => 'Workday attendance conditions are satisfied.',
            'rule' => 'company_attendance_waiver',
        ];
    }

    /** @return array{date: ?string, met: bool, reason: string, rule: string} */
    private function workdayAttendanceResult(
        User $employee,
        string $workdayKey,
        array $attendance,
        bool $includeDate,
        string $direction
    ): array {
        $isFollowing = $direction === 'following';
        $position = $isFollowing ? 'following' : 'preceding';

        if ($this->presentOn($employee, $workdayKey)) {
            return [
                'date' => $includeDate ? $workdayKey : null,
                'met' => true,
                'reason' => "Present on the immediately {$position} working day.",
                'rule' => $isFollowing ? 'present_following_workday' : 'present_previous_workday',
            ];
        }
        if (($this->paidLeaveQualifiesForDirection($attendance, $isFollowing)) && $this->hasApprovedPaidLeave($employee, $workdayKey)) {
            return [
                'date' => $includeDate ? $workdayKey : null,
                'met' => true,
                'reason' => "Approved paid leave qualifies on the {$position} working day.",
                'rule' => $isFollowing ? 'paid_leave_following_workday' : 'paid_leave_previous_workday',
            ];
        }

        return [
            'date' => $includeDate ? $workdayKey : null,
            'met' => false,
            'reason' => "Absent without pay on the immediately {$position} working day.",
            'rule' => $isFollowing ? 'unpaid_absence_following_workday' : 'unpaid_absence_previous_workday',
        ];
    }

    private function paidLeaveQualifiesForDirection(array $attendance, bool $isFollowing): bool
    {
        $key = $isFollowing
            ? 'paid_leave_qualifies_following_workday'
            : 'paid_leave_qualifies_previous_workday';
        if (array_key_exists($key, $attendance)) {
            return (bool) $attendance[$key];
        }

        return (bool) ($attendance['paid_leave_qualifies'] ?? true);
    }

    private function isFutureAttendanceDate(string $dateKey): bool
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        return Carbon::parse($dateKey, $tz)->startOfDay()->gt(Carbon::now($tz)->startOfDay());
    }

    protected function workedOn(User $employee, string $dateKey): bool
    {
        [$timeIn, $timeOut] = $this->attendanceSession->getTimesForDate(
            $employee,
            $dateKey,
            config('attendance.timezone', config('app.timezone', 'Asia/Manila'))
        );

        return $timeIn !== null && $timeOut !== null;
    }

    protected function presentOn(User $employee, string $dateKey): bool
    {
        // Holiday preceding/following workday presence requires a complete session
        // (clock-in and clock-out). A lone punch must not unlock unworked holiday pay.
        return $this->workedOn($employee, $dateKey);
    }

    public function hasWorkedOnDate(User $employee, string $dateKey): bool
    {
        return $this->workedOn($employee, $dateKey);
    }

    protected function hasApprovedPaidLeave(User $employee, string $dateKey): bool
    {
        $leave = $this->approvedLeaveOnDate($employee, $dateKey);
        if ($leave === null) {
            return false;
        }

        return $this->leaveCreditService->consumesCredits((string) $leave->type)
            && $this->leaveCreditService->dateIsPaidLeavePortion($employee, $leave, $dateKey);
    }

    public function isApprovedPaidLeaveOnDate(User $employee, string $dateKey): bool
    {
        return $this->hasApprovedPaidLeave($employee, $dateKey);
    }

    private function approvedLeaveOnDate(User $employee, string $dateKey): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->orderByDesc('id')
            ->first();
    }

    private function shouldSkipDate(?array $schedule, Carbon $date, ?string $holidayType, array $attendance): bool
    {
        if ($holidayType === 'special' && ($attendance['skip_company_non_working_days'] ?? true)) {
            return true;
        }
        if (in_array($holidayType, ['regular', 'double'], true)) {
            return true;
        }
        if (! ($attendance['skip_rest_days'] ?? true) || $schedule === null) {
            return false;
        }

        $day = $schedule[EmployeeScheduleResolver::dayKeyForDate($date)] ?? null;

        return ! is_array($day) || empty($day['in']);
    }

    /** @param array<string, mixed> $holiday */
    public function holidaySelectedForUnworkedPay(array $policy, string $kind, array $holiday): bool
    {
        $key = $kind === 'special' ? 'special_unworked' : 'regular_unworked';
        $block = (array) ($policy[$key] ?? []);
        $default = $kind === 'special' ? 'no_work_no_pay_default' : 'dole_default';
        $mode = (string) ($block['holiday_selection_mode'] ?? $default);

        if (in_array($mode, ['disabled', 'no_work_no_pay_default'], true)) {
            return false;
        }
        if (in_array($mode, ['dole_default', 'all_regular_holidays', 'all_special_holidays'], true)) {
            return true;
        }

        $selectedMode = $kind === 'special' ? 'selected_special_holidays' : 'selected_regular_holidays';
        if ($mode !== $selectedMode || ! is_numeric($holiday['id'] ?? null)) {
            return false;
        }

        return in_array((int) $holiday['id'], array_map('intval', (array) ($block['holiday_ids'] ?? [])), true);
    }

    private function employmentTypeMode(array $policy, string $kind): string
    {
        $key = $kind === 'special' ? 'special_unworked' : 'regular_unworked';
        $block = (array) ($policy[$key] ?? []);
        $mode = (string) ($block['employment_type_mode'] ?? '');
        if (in_array($mode, ['all_employment_types', 'selected_employment_types'], true)) {
            return $mode;
        }

        return $this->unworkedPayPolicy($policy, $kind) === 'selected_employment_types'
            ? 'selected_employment_types'
            : 'all_employment_types';
    }

    private function unworkedPayPolicy(array $policy, string $holidayKind): string
    {
        $configKey = $holidayKind === 'special' ? 'special_unworked' : 'regular_unworked';
        $config = (array) ($policy[$configKey] ?? []);

        return (string) ($config['unworked_pay_policy'] ?? ($holidayKind === 'special' ? self::UNWORKED_NO_PAY : self::UNWORKED_DOLE_DEFAULT));
    }

    /** @return list<string> */
    private function eligibleEmploymentTypesForWorked(array $policy, string $holidayKind): array
    {
        $key = $holidayKind === 'special' ? 'special_worked' : 'regular_worked';

        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => $this->normalize((string) $value),
            (array) ($policy[$key]['eligible_employment_types'] ?? [])
        ))));
    }

    private function workedEmploymentTypeRule(array $policy, string $holidayKind): string
    {
        $key = $holidayKind === 'special' ? 'special_worked' : 'regular_worked';
        $rule = (string) ($policy[$key]['employment_type_rule'] ?? 'all_employment_types');

        return in_array($rule, ['all_employment_types', 'selected_employment_types'], true)
            ? $rule
            : 'all_employment_types';
    }

    /** @param list<string> $allowed */
    private function workedEmploymentTypeAllowed(string $rule, string $employeeType, array $allowed): bool
    {
        return match ($rule) {
            'selected_employment_types' => in_array($employeeType, $allowed, true),
            default => true,
        };
    }

    /** @return list<string> */
    private function eligibleEmploymentTypes(array $policy, string $holidayKind): array
    {
        $key = $holidayKind === 'special' ? 'special_unworked' : 'regular_unworked';

        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => $this->normalize((string) $value),
            (array) ($policy[$key]['eligible_employment_types'] ?? [])
        ))));
    }

    /** @param list<string> $allowed */
    private function employmentTypeAllowed(string $mode, string $employeeType, array $allowed, string $kind): bool
    {
        return match ($mode) {
            self::UNWORKED_DISABLED, self::UNWORKED_NO_PAY => false,
            'selected_employment_types' => in_array($employeeType, $allowed, true),
            self::UNWORKED_PAID_LEAVE, self::UNWORKED_PAID_LEAVE_ONLY => true,
            self::UNWORKED_DOLE_DEFAULT, self::UNWORKED_COVERED, self::UNWORKED_ALL,
            self::UNWORKED_ALL_TYPES, self::UNWORKED_COMPANY_POLICY, self::UNWORKED_CBA => true,
            default => $kind === 'regular',
        };
    }

    private function employmentTypeResolver(): EmploymentTypeResolver
    {
        return $this->employmentTypeResolver ?? app(EmploymentTypeResolver::class);
    }

    /** @param  array<string, mixed>  $resolved */
    public function syncUnworkedBlocksFromSelectionMode(array $resolved): array
    {
        $storedPayUnworkedSpecial = $this->storedPayUnworkedSpecialFlag($resolved);

        foreach (['regular' => 'regular_unworked', 'special' => 'special_unworked'] as $kind => $block) {
            $defaultMode = $kind === 'regular' ? 'dole_default' : 'no_work_no_pay_default';
            $mode = (string) ($resolved[$block]['holiday_selection_mode'] ?? $defaultMode);

            if ($mode === 'no_work_no_pay_default' && $kind === 'special') {
                if (! $storedPayUnworkedSpecial) {
                    $resolved[$block]['unworked_pay_policy'] = self::UNWORKED_NO_PAY;

                    continue;
                }

                $resolved[$block]['holiday_selection_mode'] = 'all_special_holidays';
                $mode = 'all_special_holidays';
            }

            if (in_array($mode, ['disabled', 'no_work_no_pay_default'], true)) {
                $resolved[$block]['unworked_pay_policy'] = $kind === 'special'
                    ? self::UNWORKED_NO_PAY
                    : self::UNWORKED_DISABLED;

                continue;
            }

            if ($mode === 'dole_default') {
                $resolved[$block]['unworked_pay_policy'] = self::UNWORKED_DOLE_DEFAULT;

                continue;
            }

            if (in_array($mode, ['all_regular_holidays', 'all_special_holidays'], true)) {
                $resolved[$block]['unworked_pay_policy'] = self::UNWORKED_ALL_TYPES;

                continue;
            }

            if (in_array($mode, ['selected_regular_holidays', 'selected_special_holidays'], true)) {
                $employmentMode = (string) ($resolved[$block]['employment_type_mode'] ?? 'all_employment_types');
                $resolved[$block]['unworked_pay_policy'] = $employmentMode === 'selected_employment_types'
                    ? 'selected_employment_types'
                    : self::UNWORKED_ALL_TYPES;
            }
        }

        return $resolved;
    }

    /** @param  array<string, mixed>  $resolved */
    private function storedPayUnworkedSpecialFlag(array $resolved): bool
    {
        if (array_key_exists('pay_unworked_special', $resolved)) {
            return (bool) $resolved['pay_unworked_special'];
        }

        $mode = (string) ($resolved['special_unworked']['holiday_selection_mode'] ?? 'no_work_no_pay_default');
        if (! in_array($mode, ['no_work_no_pay_default', 'disabled'], true)) {
            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $resolved */
    private function normalizeResolvedPolicy(array $resolved): array
    {
        $resolved = array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $resolved);
        unset($resolved['unworked_special_multiplier']);
        $resolved['regular_unworked']['unworked_pay_policy'] ??= self::UNWORKED_DOLE_DEFAULT;
        $resolved['special_unworked']['unworked_pay_policy'] ??= self::UNWORKED_NO_PAY;
        foreach (['regular' => 'regular_unworked', 'special' => 'special_unworked'] as $kind => $block) {
            $selectionModes = $kind === 'regular'
                ? ['dole_default', 'selected_regular_holidays', 'all_regular_holidays', 'disabled']
                : ['no_work_no_pay_default', 'selected_special_holidays', 'all_special_holidays', 'disabled'];
            $selectionDefault = $kind === 'regular' ? 'dole_default' : 'no_work_no_pay_default';
            $selectionMode = (string) ($resolved[$block]['holiday_selection_mode'] ?? $selectionDefault);
            $resolved[$block]['holiday_selection_mode'] = in_array($selectionMode, $selectionModes, true)
                ? $selectionMode
                : $selectionDefault;
            $resolved[$block]['holiday_ids'] = array_values(array_unique(array_map(
                'intval',
                (array) ($resolved[$block]['holiday_ids'] ?? [])
            )));
            $employmentMode = (string) ($resolved[$block]['employment_type_mode'] ?? 'all_employment_types');
            // Legacy policies used unworked_pay_policy for this dimension.
            if (($resolved[$block]['unworked_pay_policy'] ?? null) === 'selected_employment_types') {
                $employmentMode = 'selected_employment_types';
            }
            $resolved[$block]['employment_type_mode'] = in_array($employmentMode, ['all_employment_types', 'selected_employment_types'], true)
                ? $employmentMode
                : 'all_employment_types';
        }

        $resolved = $this->syncUnworkedBlocksFromSelectionMode($resolved);

        $resolved['pay_unworked_regular'] = ($resolved['regular_unworked']['unworked_pay_policy'] ?? self::UNWORKED_DOLE_DEFAULT) !== self::UNWORKED_DISABLED;
        $specialPolicy = (string) ($resolved['special_unworked']['unworked_pay_policy'] ?? self::UNWORKED_NO_PAY);
        $resolved['pay_unworked_special'] = $specialPolicy !== self::UNWORKED_NO_PAY;
        $resolved['eligibility']['company_may_pay_unworked_special'] = $resolved['pay_unworked_special'];
        $resolved['eligibility']['special_no_work_no_pay'] = ! $resolved['pay_unworked_special'];
        $resolved['eligibility']['pay_unworked_regular'] = $resolved['pay_unworked_regular'];
        $resolved['regular_unworked']['eligible_employment_types'] = $this->eligibleEmploymentTypes($resolved, 'regular');
        $resolved['special_unworked']['eligible_employment_types'] = $this->eligibleEmploymentTypes($resolved, 'special');
        foreach (['regular_worked', 'special_worked'] as $block) {
            $kind = $block === 'special_worked' ? 'special' : 'regular';
            $rule = (string) ($resolved[$block]['employment_type_rule'] ?? 'all_employment_types');
            $resolved[$block]['employment_type_rule'] = in_array($rule, ['all_employment_types', 'selected_employment_types'], true)
                ? $rule
                : 'all_employment_types';
            $resolved[$block]['eligible_employment_types'] = $this->eligibleEmploymentTypesForWorked($resolved, $kind);
        }
        foreach (['regular_unworked', 'regular_worked', 'special_unworked', 'special_worked'] as $block) {
            $behaviour = (string) ($resolved[$block]['coverage_behaviour'] ?? self::COVERAGE_RESPECT);
            $resolved[$block]['coverage_behaviour'] = in_array($behaviour, [self::COVERAGE_IGNORE, self::COVERAGE_RESPECT], true)
                ? $behaviour
                : self::COVERAGE_RESPECT;
        }
        $resolved['non_statutory'] = array_replace_recursive(
            Policy::DEFAULT_HOLIDAY_POLICY['non_statutory'] ?? [],
            (array) ($resolved['non_statutory'] ?? [])
        );
        $resolved['non_statutory']['special_working']['pay_as_ordinary_day'] = true;
        $resolved['non_statutory']['company']['pay_as_ordinary_day'] =
            (bool) ($resolved['non_statutory']['company']['pay_as_ordinary_day'] ?? true);

        foreach (['paid_leave_qualifies', 'skip_rest_days', 'skip_company_non_working_days'] as $mandatory) {
            $resolved['attendance'][$mandatory] = true;
        }

        $resolved['attendance'] = $this->normalizeUnworkedAttendanceBlocks((array) ($resolved['attendance'] ?? []));

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $attendance
     * @return array<string, mixed>
     */
    private function normalizeUnworkedAttendanceBlocks(array $attendance): array
    {
        $legacyRegular = [
            'require_previous_workday_presence' => (bool) ($attendance['require_previous_workday_presence'] ?? true),
            'require_following_workday_presence' => (bool) ($attendance['require_following_workday_presence'] ?? false),
            'paid_leave_qualifies_previous_workday' => (bool) ($attendance['paid_leave_qualifies_previous_workday'] ?? true),
            'paid_leave_qualifies_following_workday' => (bool) ($attendance['paid_leave_qualifies_following_workday'] ?? true),
        ];
        $attendance['regular_unworked'] = array_merge(
            $legacyRegular,
            is_array($attendance['regular_unworked'] ?? null) ? $attendance['regular_unworked'] : []
        );
        $attendance['special_unworked'] = array_merge(
            [
                'require_previous_workday_presence' => false,
                'require_following_workday_presence' => false,
                'paid_leave_qualifies_previous_workday' => true,
                'paid_leave_qualifies_following_workday' => true,
            ],
            is_array($attendance['special_unworked'] ?? null) ? $attendance['special_unworked'] : []
        );
        $attendance['require_previous_workday_presence'] = (bool) ($attendance['regular_unworked']['require_previous_workday_presence'] ?? true);
        $attendance['require_following_workday_presence'] = (bool) ($attendance['regular_unworked']['require_following_workday_presence'] ?? false);
        $attendance['paid_leave_qualifies_previous_workday'] = (bool) ($attendance['regular_unworked']['paid_leave_qualifies_previous_workday'] ?? true);
        $attendance['paid_leave_qualifies_following_workday'] = (bool) ($attendance['regular_unworked']['paid_leave_qualifies_following_workday'] ?? true);

        return $attendance;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function resolveUnworkedAttendanceRules(array $policy, string $kind): array
    {
        $global = is_array($policy['attendance'] ?? null) ? $policy['attendance'] : [];
        $blockKey = $kind === 'special' ? 'special_unworked' : 'regular_unworked';
        $block = is_array($global[$blockKey] ?? null) ? $global[$blockKey] : [];

        $defaults = $kind === 'special'
            ? [
                'require_previous_workday_presence' => false,
                'require_following_workday_presence' => false,
                'paid_leave_qualifies_previous_workday' => true,
                'paid_leave_qualifies_following_workday' => true,
            ]
            : [
                'require_previous_workday_presence' => true,
                'require_following_workday_presence' => false,
                'paid_leave_qualifies_previous_workday' => true,
                'paid_leave_qualifies_following_workday' => true,
            ];

        $legacy = $kind === 'regular' ? [
            'require_previous_workday_presence' => $global['require_previous_workday_presence'] ?? null,
            'require_following_workday_presence' => $global['require_following_workday_presence'] ?? null,
            'paid_leave_qualifies_previous_workday' => $global['paid_leave_qualifies_previous_workday'] ?? null,
            'paid_leave_qualifies_following_workday' => $global['paid_leave_qualifies_following_workday'] ?? null,
        ] : [];
        $legacy = array_filter($legacy, static fn ($value) => $value !== null);

        return array_merge(
            [
                'paid_leave_qualifies' => (bool) ($global['paid_leave_qualifies'] ?? true),
                'skip_rest_days' => (bool) ($global['skip_rest_days'] ?? true),
                'skip_company_non_working_days' => (bool) ($global['skip_company_non_working_days'] ?? true),
            ],
            $defaults,
            $legacy,
            $block
        );
    }

    private function normalize(string $value): string
    {
        return strtolower(trim(str_replace(['-', ' '], '_', $value)));
    }

    /** @return array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string} */
    private function result(bool $eligible, bool $attendanceMet, string $reason, string $rule): array
    {
        return [
            'eligible' => $eligible,
            'attendance_requirement_met' => $attendanceMet,
            'reason' => $reason,
            'rule' => $rule,
        ];
    }
}
