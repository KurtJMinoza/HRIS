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
        private readonly HolidayPayRuleEngine $ruleEngine,
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
        $qualification = $this->ruleEngine->evaluateAttendanceQualification($employee, $holidayDate, $resolved, 'regular');

        return [
            'date' => $qualification['date'] ?? null,
            'met' => (bool) ($qualification['met'] ?? false),
            'reason' => (string) ($qualification['reason'] ?? ''),
            'rule' => (string) ($qualification['rule'] ?? ''),
        ];
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

        $resolvedPolicy = $this->resolveEffectivePolicy(
            $policy,
            $holiday,
            $companyId
        );
        $qualification = $this->evaluate($employee, $holiday, $dateKey, $worked, $policy);
        $unworkedMultiplier = $this->unworkedMultiplier($holiday, $resolvedPolicy);

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
            $componentCode = $this->holidayPayComponentCode($normalizedType);
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
                'policy_source' => 'policy_settings',
                'previous_workday_passed' => $qualification['attendance_requirement_met'] ?? null,
                'attendance_rule_applied' => $qualification['rule'] ?? null,
            ];
        } elseif ($worked && $qualification['eligible'] && $paidRegularMinutes > 0 && $workedFirst8 > 1.00001) {
            $normalizedType = $this->normalizeHolidayType($holiday['type'] ?? null) ?? 'regular';
            $componentCode = $this->holidayPayComponentCode($normalizedType, false);
            $holidayName = (string) ($holiday['name'] ?? 'Holiday');
            $holidayPremiumPay = round(($paidRegularMinutes / 60.0) * $hourlyRate * $workedFirst8, 2);
            $breakdown = [
                'component' => 'holiday_premium',
                'component_code' => $componentCode,
                'description' => $this->holidayPayDescription($componentCode, $holidayName),
                'minutes' => $paidRegularMinutes,
                'rate' => $hourlyRate,
                'multiplier' => $workedFirst8,
                'premium_multiplier' => round($workedFirst8, 2),
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
        $allowedEmploymentTypes = $this->eligibleEmploymentTypes($resolved, $kind);
        $employmentTypeMatch = $this->employmentTypeAllowed($unworkedPolicy, $employmentType, $allowedEmploymentTypes, $kind);
        $workedEmploymentRule = $this->workedEmploymentTypeRule($resolved, $kind);
        $workedAllowedEmploymentTypes = $this->eligibleEmploymentTypesForWorked($resolved, $kind);
        $workedEmploymentTypeMatch = $this->workedEmploymentTypeAllowed(
            $workedEmploymentRule,
            $employmentType,
            $workedAllowedEmploymentTypes
        );

        if ($worked) {
            $engineResult = in_array($normalizedType, ['regular', 'double'], true)
                ? $this->ruleEngine->evaluateRegularWorked($employee, $holiday, $dateKey, $resolved)
                : $this->ruleEngine->evaluateSpecialWorked($employee, $holiday, $dateKey, $resolved);
            $result = $this->result(
                (bool) $engineResult['eligible'],
                (bool) $engineResult['attendance_requirement_met'],
                (string) $engineResult['reason'],
                (string) $engineResult['rule']
            );
        } elseif (in_array($normalizedType, ['special_working', 'company'], true) || $normalizedType === null) {
            $result = $this->result(false, true, 'This day does not carry unworked holiday pay.', 'not_non_working_holiday');
        } elseif ($normalizedType === 'special' && ! in_array($unworkedPolicy, [self::UNWORKED_NO_PAY, self::UNWORKED_PAID_LEAVE, self::UNWORKED_PAID_LEAVE_ONLY], true) && ! $employmentTypeMatch) {
            $result = $this->result(false, true, 'Employee employment type is not selected for unworked special holiday pay.', 'special_employment_type_excluded');
        } elseif ($normalizedType === 'special') {
            $engineResult = $this->ruleEngine->evaluateSpecialUnworked($employee, $holiday, $dateKey, $resolved);
            $result = $this->result(
                (bool) $engineResult['eligible'],
                (bool) $engineResult['attendance_requirement_met'],
                (string) $engineResult['reason'],
                (string) $engineResult['rule']
            );
        } elseif (in_array($normalizedType, ['regular', 'double'], true) && ! $employmentTypeMatch) {
            $result = $this->result(false, true, 'Employee employment type is not selected for unworked regular holiday pay.', 'regular_employment_type_excluded');
        } elseif (in_array($normalizedType, ['regular', 'double'], true)) {
            $attendance = (array) ($resolved['attendance'] ?? []);
            if (! ($attendance['require_previous_workday_presence'] ?? true)) {
                $result = $this->result(true, true, 'Company policy waives the preceding-workday condition.', 'company_attendance_waiver');
            } else {
                $engineResult = $this->ruleEngine->evaluateRegularUnworked($employee, $holiday, $dateKey, $resolved);
                $result = $this->result(
                    (bool) $engineResult['eligible'],
                    (bool) $engineResult['attendance_requirement_met'],
                    (string) $engineResult['reason'],
                    (string) $engineResult['rule']
                );
            }
        } else {
            $result = $this->result(false, true, 'This day does not carry unworked holiday pay.', 'not_non_working_holiday');
        }

        $payload = [
            'eligible' => $result['eligible'],
            'reason' => $result['reason'],
            'attendance_requirement_met' => $result['attendance_requirement_met'],
            'policy_match' => $holidayScopeMatch,
            'employment_type' => $employmentType,
            'allowed_employment_types' => $allowedEmploymentTypes,
            'employment_type_match' => $worked ? $workedEmploymentTypeMatch : $employmentTypeMatch,
            'worked_employment_type_rule' => $workedEmploymentRule,
            'worked_allowed_employment_types' => $workedAllowedEmploymentTypes,
            'company_override' => ($resolved['attendance']['require_previous_workday_presence'] ?? true) === false,
            'holiday_scope_match' => $holidayScopeMatch,
            'rule' => $result['rule'],
            'rule_used' => $worked
                ? 'worked'
                : ($resolved[$kind === 'special' ? 'special_unworked' : 'regular_unworked']['attendance_rule']['minimum_condition'] ?? null),
            'attendance_rule' => $this->ruleEngine->resolvedAttendanceRule(
                (array) ($resolved[$kind === 'special' ? 'special_unworked' : 'regular_unworked'] ?? []),
                $kind
            ),
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
        $unworkedMultiplier = $this->unworkedMultiplier($holiday, $resolved);
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
            'should_pay_unworked_holiday' => $shouldPay,
            'line_item_created' => false,
            'skip_reason' => $shouldPay ? null : (string) ($determination['reason'] ?? 'not_eligible'),
        ];

        if (filter_var(config('payroll.debug_holiday_eligibility', false), FILTER_VALIDATE_BOOL)) {
            Log::debug('holiday_unworked_pay', $payload);
        }

        return $payload;
    }

    public function holidayPayComponentCode(?string $normalizedHolidayType, bool $unworked = true): string
    {
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
     * Whether payroll may pay outside Holiday module organizational coverage.
     * Calendar/attendance always respect coverage regardless of this setting.
     */
    public function shouldIgnoreHolidayCoverage(array $resolvedPolicy, string $holidayKind, bool $worked): bool
    {
        return $this->coverageBehaviour($resolvedPolicy, $holidayKind, $worked) === self::COVERAGE_IGNORE;
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
    private function precedingWorkdayRequirement(User $employee, string $dateKey, array $policy, array $visited, bool $includeDate = false): array
    {
        if (isset($visited[$dateKey]) || count($visited) > 370) {
            return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
        }
        $visited[$dateKey] = true;
        $cursor = Carbon::parse($dateKey)->subDay();
        $schedule = EmployeeScheduleResolver::resolve($employee);
        $attendance = (array) ($policy['attendance'] ?? []);

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

                $chain = $this->precedingWorkdayRequirement($employee, $priorKey, $policy, $visited, $includeDate);

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

            if ($this->workedOn($employee, $priorKey)) {
                return [
                    'date' => $includeDate ? $priorKey : null,
                    'met' => true,
                    'reason' => 'Present on the immediately preceding working day.',
                    'rule' => 'present_previous_workday',
                ];
            }
            if (($attendance['paid_leave_qualifies'] ?? true) && $this->hasApprovedPaidLeave($employee, $priorKey)) {
                return [
                    'date' => $includeDate ? $priorKey : null,
                    'met' => true,
                    'reason' => 'Approved paid leave qualifies on the preceding working day.',
                    'rule' => 'paid_leave_previous_workday',
                ];
            }

            return [
                'date' => $includeDate ? $priorKey : null,
                'met' => false,
                'reason' => 'Absent without pay on the immediately preceding working day.',
                'rule' => 'unpaid_absence_previous_workday',
            ];
        }

        return ['date' => null, 'met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
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
    private function normalizeResolvedPolicy(array $resolved): array
    {
        $resolved = array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $resolved);
        $resolved['pay_unworked_regular'] = ($resolved['regular_unworked']['unworked_pay_policy'] ?? self::UNWORKED_DOLE_DEFAULT) !== self::UNWORKED_DISABLED;
        $specialPolicy = (string) ($resolved['special_unworked']['unworked_pay_policy'] ?? self::UNWORKED_NO_PAY);
        // ponytail: legacy policies only had pay_unworked_special — infer once when special_unworked was never stored
        if (! isset($resolved['special_unworked']['unworked_pay_policy']) && empty($resolved['special_unworked'])) {
            if ($resolved['pay_unworked_special'] ?? false) {
                $specialPolicy = self::UNWORKED_ALL;
                $resolved['special_unworked']['unworked_pay_policy'] = self::UNWORKED_ALL;
            }
        }
        $resolved['pay_unworked_special'] = $specialPolicy !== self::UNWORKED_NO_PAY;
        $resolved['eligibility']['company_may_pay_unworked_special'] = $resolved['pay_unworked_special'];
        $resolved['eligibility']['special_no_work_no_pay'] = $specialPolicy === self::UNWORKED_NO_PAY;
        unset($resolved['unworked_special_multiplier']);
        $resolved['regular_unworked']['unworked_pay_policy'] ??= self::UNWORKED_DOLE_DEFAULT;
        $resolved['special_unworked']['unworked_pay_policy'] ??= self::UNWORKED_NO_PAY;
        $resolved = $this->ruleEngine->mergePolicyDefaults($resolved);
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

        return $resolved;
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
