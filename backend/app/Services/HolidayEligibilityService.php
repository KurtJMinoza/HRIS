<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Policy;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the holiday entitlement portion of the existing payroll policy.
 * PayrollComputationService remains the only service that calculates earnings.
 */
class HolidayEligibilityService
{
    public function __construct(
        private readonly AttendanceSessionService $attendanceSession,
        private readonly HolidayService $holidayService,
        private readonly LeaveCreditService $leaveCreditService,
    ) {}

    /** @return array<string, mixed> */
    public function policyFor(?Policy $policy, ?int $companyId): array
    {
        $companyId = max(0, (int) $companyId);
        HolidayPolicyCache::trackCompany($companyId);
        $cacheKey = HolidayPolicyCache::policyKey($companyId);
        $policyKey = (string) ($policy?->id ?? 'default');
        $cached = Cache::get($cacheKey, []);
        $cached = is_array($cached) ? $cached : [];

        if (isset($cached[$policyKey]) && is_array($cached[$policyKey])) {
            return $cached[$policyKey];
        }

        $resolved = $policy
            ? $policy->resolvedHolidayPolicy()
            : Policy::DEFAULT_HOLIDAY_POLICY;
        $resolved = array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $resolved);
        // Mandatory legal protections are never configurable downward.
        $resolved['pay_unworked_regular'] = true;
        $resolved['successive_regular_holidays'] = true;
        $resolved['unworked_special_multiplier'] = max(1.0, (float) ($resolved['unworked_special_multiplier'] ?? 1.0));
        foreach (['paid_leave_qualifies', 'skip_rest_days', 'skip_company_non_working_days'] as $mandatory) {
            $resolved['attendance'][$mandatory] = true;
        }
        foreach (['rank_and_file', 'probationary', 'regular'] as $mandatory) {
            $resolved['coverage'][$mandatory] = true;
        }
        foreach (['government', 'field_personnel', 'micro_retail_service'] as $excluded) {
            $resolved['coverage'][$excluded] = false;
        }

        $cached[$policyKey] = $resolved;
        Cache::put($cacheKey, $cached, HolidayPolicyCache::TTL_SECONDS);

        return $resolved;
    }

    /** @return array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string, category: string} */
    public function evaluate(User $employee, array $holiday, string $dateKey, bool $worked, array $policy): array
    {
        [$covered, $category, $coverageReason] = $this->employmentCoverage($employee, $policy);
        if (! $covered) {
            return $this->result(false, false, $coverageReason, 'employment_coverage', $category);
        }

        if ($worked) {
            return $this->result(
                true,
                true,
                'Worked on the holiday; the preceding-workday condition does not bar holiday work pay.',
                'worked_holiday',
                $category
            );
        }

        $type = $this->normalizeHolidayType($holiday['type'] ?? null);
        if ($type === 'special' && ! ($policy['pay_unworked_special'] ?? false)) {
            return $this->result(false, true, 'Special non-working holiday follows no work, no pay.', 'special_no_work_no_pay', $category);
        }
        if ($type === 'special_working' || $type === null) {
            return $this->result(false, true, 'This day does not carry unworked holiday pay.', 'not_non_working_holiday', $category);
        }

        $attendance = (array) ($policy['attendance'] ?? []);
        if (! ($attendance['require_previous_workday_presence'] ?? true)) {
            return $this->result(true, true, 'Company policy waives the preceding-workday condition.', 'company_attendance_waiver', $category);
        }

        $qualification = $this->precedingWorkdayRequirement($employee, $dateKey, $policy, []);

        return $this->result(
            $qualification['met'],
            $qualification['met'],
            $qualification['reason'],
            $qualification['rule'],
            $category
        );
    }

    public function unworkedMultiplier(array $holiday, array $policy): float
    {
        return match ($this->normalizeHolidayType($holiday['type'] ?? null)) {
            'regular' => 1.0,
            'double' => 2.0,
            'special' => ($policy['pay_unworked_special'] ?? false)
                ? max(1.0, (float) ($policy['unworked_special_multiplier'] ?? 1.0))
                : 0.0,
            default => 0.0,
        };
    }

    public function normalizeHolidayType(mixed $type): ?string
    {
        $value = $this->normalize((string) $type);

        return match ($value) {
            'regular', 'regular_holiday' => 'regular',
            'double', 'double_holiday' => 'double',
            'special_working', 'special_working_day' => 'special_working',
            'special', 'special_non_working', 'special_non_working_holiday', 'company' => 'special',
            default => null,
        };
    }

    /** @return array{met: bool, reason: string, rule: string} */
    private function precedingWorkdayRequirement(User $employee, string $dateKey, array $policy, array $visited): array
    {
        if (isset($visited[$dateKey]) || count($visited) > 370) {
            return ['met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
        }
        $visited[$dateKey] = true;
        $cursor = Carbon::parse($dateKey)->subDay();
        $schedule = EmployeeScheduleResolver::resolve($employee);
        $attendance = (array) ($policy['attendance'] ?? []);

        while (count($visited) <= 370) {
            $priorKey = $cursor->toDateString();
            if (isset($visited[$priorKey])) {
                return ['met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
            }

            $priorHoliday = $this->holidayService->resolveHolidayForPayroll($employee, $priorKey);
            $priorType = $this->normalizeHolidayType($priorHoliday['type'] ?? null);
            if ($priorHoliday !== null && in_array($priorType, ['regular', 'double'], true)) {
                if ($this->workedOn($employee, $priorKey)) {
                    return [
                        'met' => true,
                        'reason' => 'Work on the first regular holiday qualifies the succeeding regular holiday.',
                        'rule' => 'successive_holiday_worked_first',
                    ];
                }

                if ($policy['successive_regular_holidays'] ?? true) {
                    $chain = $this->precedingWorkdayRequirement($employee, $priorKey, $policy, $visited);

                    return [
                        'met' => $chain['met'],
                        'reason' => $chain['met']
                            ? 'The condition before the first regular holiday qualifies the successive holidays.'
                            : 'The condition before the first regular holiday was not met for the successive holidays.',
                        'rule' => 'successive_holiday_chain',
                    ];
                }
            }

            if ($this->shouldSkipDate($schedule, $cursor, $priorType, $attendance)) {
                $visited[$priorKey] = true;
                $cursor->subDay();

                continue;
            }

            if ($this->workedOn($employee, $priorKey)) {
                return ['met' => true, 'reason' => 'Present on the immediately preceding working day.', 'rule' => 'present_previous_workday'];
            }
            if (($attendance['paid_leave_qualifies'] ?? true) && $this->hasApprovedPaidLeave($employee, $priorKey)) {
                return ['met' => true, 'reason' => 'Approved paid leave qualifies on the preceding working day.', 'rule' => 'paid_leave_previous_workday'];
            }
            if (! ($attendance['unpaid_absence_disqualifies'] ?? true)) {
                return ['met' => true, 'reason' => 'Company policy does not disqualify an unpaid preceding-day absence.', 'rule' => 'company_absence_waiver'];
            }

            return ['met' => false, 'reason' => 'Absent without pay on the immediately preceding working day.', 'rule' => 'unpaid_absence_previous_workday'];
        }

        return ['met' => false, 'reason' => 'Unable to resolve a preceding working day.', 'rule' => 'attendance_unresolved'];
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

    protected function hasApprovedPaidLeave(User $employee, string $dateKey): bool
    {
        $leave = LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->orderByDesc('id')
            ->first();

        return $leave !== null
            && $this->leaveCreditService->consumesCredits((string) $leave->type)
            && $this->leaveCreditService->dateIsPaidLeavePortion($employee, $leave, $dateKey);
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

    /** @return array{0: bool, 1: string, 2: string} */
    private function employmentCoverage(User $employee, array $policy): array
    {
        $rules = array_replace(Policy::DEFAULT_HOLIDAY_POLICY['coverage'], (array) ($policy['coverage'] ?? []));
        $employmentType = $this->normalize((string) ($employee->employment_type ?? ''));
        $employmentStatus = $this->normalize((string) ($employee->employment_status ?? ''));
        $position = $this->normalize((string) ($employee->position ?? ''));

        $category = 'rank_and_file';
        if (str_contains($employmentType, 'government')) {
            $category = 'government';
        } elseif (str_contains($employmentType, 'field_personnel') || str_contains($position, 'field_personnel')) {
            $category = 'field_personnel';
        } elseif (str_contains($employmentType, 'micro_retail') || str_contains($employmentType, 'micro_service')) {
            $category = 'micro_retail_service';
        } elseif (str_contains($position, 'manager') || str_contains($position, 'director') || str_contains($position, 'executive')) {
            $category = 'managerial';
        } elseif (str_contains($employmentType, 'consultant')) {
            $category = 'consultants';
        } elseif (str_contains($employmentType, 'fixed_term') || str_contains($employmentStatus, 'fixed_term')) {
            $category = 'fixed_term';
        } elseif (str_contains($employmentType, 'contract') || str_contains($employmentStatus, 'contract')) {
            $category = 'contractual';
        } elseif (str_contains($employmentType, 'probation') || str_contains($employmentStatus, 'probation')) {
            $category = 'probationary';
        } elseif (str_contains($employmentStatus, 'regular')) {
            $category = 'regular';
        }

        $covered = (bool) ($rules[$category] ?? $rules['rank_and_file'] ?? true);

        return [$covered, $category, $covered
            ? 'Employee category is covered by this holiday policy.'
            : ucfirst(str_replace('_', ' ', $category)).' employees are excluded by this holiday policy.'];
    }

    private function normalize(string $value): string
    {
        return strtolower(trim(str_replace(['-', ' '], '_', $value)));
    }

    /** @return array{eligible: bool, attendance_requirement_met: bool, reason: string, rule: string, category: string} */
    private function result(bool $eligible, bool $attendanceMet, string $reason, string $rule, string $category): array
    {
        return [
            'eligible' => $eligible,
            'attendance_requirement_met' => $attendanceMet,
            'reason' => $reason,
            'rule' => $rule,
            'category' => $category,
        ];
    }
}
