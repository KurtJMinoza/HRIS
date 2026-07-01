<?php

namespace App\Services;

use App\Models\Policy;
use App\Models\User;

/**
 * Thin facade over {@see HolidayPayPolicyService} for payroll consumers.
 */
class HolidayEligibilityService
{
    public function __construct(
        private readonly HolidayPayPolicyService $holidayPayPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function policyFor(?Policy $policy, ?int $companyId): array
    {
        return $this->holidayPayPolicy->policyFor($policy, $companyId);
    }

    /** @return array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string} */
    public function evaluate(User $employee, array $holiday, string $dateKey, bool $worked, ?Policy $policy = null): array
    {
        return $this->holidayPayPolicy->evaluate($employee, $holiday, $dateKey, $worked, $policy);
    }

    /** @return array<string, mixed> */
    public function shouldPayUnworkedHoliday(User $employee, array $holiday, string $dateKey, ?Policy $policy = null, ?bool $calendarScopeMatch = null): array
    {
        return $this->holidayPayPolicy->shouldPayUnworkedHoliday($employee, $holiday, $dateKey, $policy, $calendarScopeMatch);
    }

    public function shouldIgnoreHolidayCoverage(array $resolvedPolicy, string $holidayKind, bool $worked): bool
    {
        return $this->holidayPayPolicy->shouldIgnoreHolidayCoverage($resolvedPolicy, $holidayKind, $worked);
    }

    public function coverageBehaviour(array $resolvedPolicy, string $holidayKind, bool $worked): string
    {
        return $this->holidayPayPolicy->coverageBehaviour($resolvedPolicy, $holidayKind, $worked);
    }

    /** @return array<string, mixed> */
    public function determineEligibility(User $employee, array $holiday, string $dateKey, bool $worked, ?Policy $policy = null, ?bool $calendarScopeMatch = null): array
    {
        return $this->holidayPayPolicy->determineEligibility($employee, $holiday, $dateKey, $worked, $policy, $calendarScopeMatch);
    }

    /**
     * @param  array{date_key?: string, worked?: bool, daily_rate?: float, hourly_rate?: float, is_rest_day?: bool, required_minutes?: int, paid_regular_minutes?: int}  $attendance
     * @return array<string, mixed>
     */
    public function computeHolidayPay(User $employee, array $attendance, array $holiday, ?Policy $policy = null): array
    {
        return $this->holidayPayPolicy->computeHolidayPay($employee, $attendance, $holiday, $policy);
    }

    public function holidayPayComponentCode(?string $normalizedHolidayType, bool $unworked = true): string
    {
        return $this->holidayPayPolicy->holidayPayComponentCode($normalizedHolidayType, $unworked);
    }

    public function holidayPayDescription(string $componentCode, string $holidayName): string
    {
        return $this->holidayPayPolicy->holidayPayDescription($componentCode, $holidayName);
    }

    /** @return array<string, mixed> */
    public function resolveEffectivePolicy(?Policy $policy, array $holiday, ?int $companyId = null): array
    {
        return $this->holidayPayPolicy->resolveEffectivePolicy($policy, $holiday, $companyId);
    }

    public function unworkedMultiplier(array $holiday, array $policy): float
    {
        return $this->holidayPayPolicy->unworkedMultiplier($holiday, $policy);
    }

    public function normalizeHolidayType(mixed $type): ?string
    {
        return $this->holidayPayPolicy->normalizeHolidayType($type);
    }

    public function resolvedPayrollHolidayType(array $holiday, array $policy): ?string
    {
        return $this->holidayPayPolicy->resolvedPayrollHolidayType($holiday, $policy);
    }
}
