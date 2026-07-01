<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Policy;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable holiday pay qualification engine (unworked + worked).
 * Reads structured rules from policies.holiday_policy JSON — no hardcoded DOLE-only paths.
 */
class HolidayPayRuleEngine
{
    public const POLICY_MODE_DOLE = 'dole_default';

    public const POLICY_MODE_CUSTOM = 'custom';

    public const MIN_PREVIOUS_ONLY = 'previous_working_day_only';

    public const MIN_PREVIOUS_AND_NEXT = 'previous_and_next';

    public const MIN_PREVIOUS_OR_NEXT = 'previous_or_next';

    public const MIN_NEXT_ONLY = 'next_working_day_only';

    public const MIN_NONE = 'none';

    public function __construct(
        private readonly AttendanceSessionService $attendanceSession,
        private readonly HolidayService $holidayService,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly HolidayPayAttendanceStatusRegistry $statusRegistry,
    ) {}

    /**
     * @return array{
     *   eligible: bool,
     *   reason: string,
     *   rule: string,
     *   attendance_requirement_met: bool,
     *   rule_used: ?string,
     *   evaluated_dates: array<string, mixed>
     * }
     */
    public function evaluateRegularUnworked(User $employee, array $holiday, string $dateKey, array $policy): array
    {
        $block = (array) ($policy['regular_unworked'] ?? []);

        if ((bool) ($block['always_pay'] ?? false)) {
            return $this->result(true, true, 'Always Pay Unworked Regular Holiday policy override applies.', 'regular_always_pay_override', 'always_pay');
        }

        if (($block['unworked_pay_policy'] ?? '') === HolidayPayPolicyService::UNWORKED_DISABLED) {
            return $this->result(false, true, 'Unworked regular holiday pay is disabled for this policy.', 'unworked_regular_disabled', 'disabled');
        }

        return $this->evaluateUnworkedAttendance($employee, $holiday, $dateKey, $policy, 'regular');
    }

    /** @return array{eligible: bool, reason: string, rule: string, attendance_requirement_met: bool, rule_used: ?string, evaluated_dates: array<string, mixed>} */
    public function evaluateSpecialUnworked(User $employee, array $holiday, string $dateKey, array $policy): array
    {
        $block = (array) ($policy['special_unworked'] ?? []);
        $mode = (string) ($block['unworked_pay_policy'] ?? HolidayPayPolicyService::UNWORKED_NO_PAY);

        if ($mode === HolidayPayPolicyService::UNWORKED_NO_PAY) {
            return $this->result(false, true, 'Special non-working holiday follows No Work, No Pay.', 'special_no_work_no_pay', 'no_work_no_pay');
        }

        if (in_array($mode, [HolidayPayPolicyService::UNWORKED_PAID_LEAVE, HolidayPayPolicyService::UNWORKED_PAID_LEAVE_ONLY], true)) {
            $paidLeave = $this->hasApprovedPaidLeave($employee, $dateKey);

            return $this->result(
                $paidLeave,
                true,
                $paidLeave ? 'Approved paid leave is paid as leave.' : 'No approved paid leave covers this special holiday.',
                $paidLeave ? 'special_paid_leave' : 'special_no_paid_leave',
                'paid_leave_only'
            );
        }

        if ($this->isCustomPolicyMode($block)) {
            return $this->evaluateUnworkedAttendance($employee, $holiday, $dateKey, $policy, 'special');
        }

        return $this->result(true, true, 'Company policy pays this covered employee for the unworked special holiday.', 'special_unworked_company_policy', 'company_policy');
    }

    /** @return array{eligible: bool, reason: string, rule: string, attendance_requirement_met: bool, rule_used: ?string} */
    public function evaluateRegularWorked(User $employee, array $holiday, string $dateKey, array $policy): array
    {
        return $this->evaluateWorkedEmployment($employee, $policy, 'regular');
    }

    /** @return array{eligible: bool, reason: string, rule: string, attendance_requirement_met: bool, rule_used: ?string} */
    public function evaluateSpecialWorked(User $employee, array $holiday, string $dateKey, array $policy): array
    {
        return $this->evaluateWorkedEmployment($employee, $policy, 'special');
    }

    /**
     * @return array{date: ?string, met: bool, reason: string, rule: string, rule_used: ?string, evaluated_dates: array<string, mixed>}
     */
    public function evaluateAttendanceQualification(User $employee, string $dateKey, array $policy, string $holidayKind = 'regular'): array
    {
        $blockKey = $holidayKind === 'special' ? 'special_unworked' : 'regular_unworked';
        $block = (array) ($policy[$blockKey] ?? []);
        $rules = $this->resolvedAttendanceRule($block, $holidayKind);
        $minimum = (string) ($rules['minimum_condition'] ?? self::MIN_PREVIOUS_ONLY);

        if ($minimum === self::MIN_NONE) {
            return [
                'date' => null,
                'met' => true,
                'reason' => 'No attendance requirement configured.',
                'rule' => 'no_attendance_requirement',
                'rule_used' => self::MIN_NONE,
                'evaluated_dates' => [],
            ];
        }

        if ($holidayKind === 'regular' && (bool) ($block['successive_holiday_rule'] ?? true)) {
            $successive = $this->evaluateSuccessiveRegularHoliday($employee, $dateKey, $policy, $rules);
            if ($successive !== null) {
                return $successive;
            }
        }

        $lookup = (array) ($rules['lookup'] ?? []);
        $evaluated = [];

        $previousDate = $this->findAdjacentWorkingDay($employee, $dateKey, 'previous', $lookup);
        $nextDate = $this->findAdjacentWorkingDay($employee, $dateKey, 'next', $lookup);

        $prevMet = $previousDate !== null && $this->dayMeetsQualification($employee, $previousDate, $rules);
        $nextMet = $nextDate !== null && $this->dayMeetsQualification($employee, $nextDate, $rules);

        if ($previousDate !== null) {
            $evaluated['previous'] = ['date' => $previousDate, 'met' => $prevMet, 'statuses' => $this->resolveDayStatusTags($employee, $previousDate)];
        }
        if ($nextDate !== null) {
            $evaluated['next'] = ['date' => $nextDate, 'met' => $nextMet, 'statuses' => $this->resolveDayStatusTags($employee, $nextDate)];
        }

        $met = match ($minimum) {
            self::MIN_PREVIOUS_AND_NEXT => $prevMet && $nextMet,
            self::MIN_PREVIOUS_OR_NEXT => $prevMet || $nextMet,
            self::MIN_NEXT_ONLY => $nextMet,
            default => $prevMet,
        };

        $reason = match ($minimum) {
            self::MIN_PREVIOUS_AND_NEXT => $met
                ? 'Present or qualifying status on both the previous and next working day.'
                : 'Previous AND next working day qualification was not met.',
            self::MIN_PREVIOUS_OR_NEXT => $met
                ? 'Present or qualifying status on the previous or next working day.'
                : 'Neither the previous nor the next working day qualified.',
            self::MIN_NEXT_ONLY => $met
                ? 'Present or qualifying status on the next working day.'
                : 'The next working day did not qualify.',
            default => $met
                ? 'Present or qualifying status on the immediately preceding working day.'
                : 'The immediately preceding working day did not qualify.',
        };

        $rule = $met
            ? match ($minimum) {
                self::MIN_PREVIOUS_AND_NEXT => 'qualified_previous_and_next',
                self::MIN_PREVIOUS_OR_NEXT => 'qualified_previous_or_next',
                self::MIN_NEXT_ONLY => 'qualified_next_workday',
                default => ($prevMet && in_array('approved_paid_leave', (array) ($evaluated['previous']['statuses'] ?? []), true)
                    && ! in_array('present', (array) ($evaluated['previous']['statuses'] ?? []), true)
                    && ! in_array('late', (array) ($evaluated['previous']['statuses'] ?? []), true))
                    ? 'paid_leave_previous_workday'
                    : 'present_previous_workday',
            }
            : match ($minimum) {
                self::MIN_PREVIOUS_AND_NEXT => 'failed_previous_and_next',
                self::MIN_PREVIOUS_OR_NEXT => 'failed_previous_or_next',
                self::MIN_NEXT_ONLY => 'failed_next_workday',
                default => 'unpaid_absence_previous_workday',
            };

        return [
            'date' => $previousDate ?? $nextDate,
            'met' => $met,
            'reason' => $reason,
            'rule' => $rule,
            'rule_used' => $minimum,
            'evaluated_dates' => $evaluated,
        ];
    }

    /** @param  array<string, mixed>  $block */
    public function resolvedAttendanceRule(array $block, string $holidayKind): array
    {
        if ($this->isCustomPolicyMode($block)) {
            $custom = (array) ($block['attendance_rule'] ?? []);

            return $this->normalizeAttendanceRule($custom);
        }

        return $this->doleDefaultAttendanceRule();
    }

    /** @return array<string, mixed> */
    public function doleDefaultAttendanceRule(): array
    {
        return [
            'minimum_condition' => self::MIN_PREVIOUS_ONLY,
            'qualifying_statuses' => $this->statusRegistry->defaultDoleQualifying(),
            'disqualifying_statuses' => $this->statusRegistry->defaultDoleDisqualifying(),
            'lookup' => [
                'skip_rest_days' => true,
                'skip_non_working_days' => true,
                'skip_holidays' => true,
                'skip_paid_leave' => false,
            ],
        ];
    }

    /** @param  array<string, mixed>  $raw */
    public function normalizeAttendanceRule(array $raw): array
    {
        $minimum = (string) ($raw['minimum_condition'] ?? self::MIN_PREVIOUS_ONLY);
        $allowedMinimum = [
            self::MIN_PREVIOUS_ONLY,
            self::MIN_PREVIOUS_AND_NEXT,
            self::MIN_PREVIOUS_OR_NEXT,
            self::MIN_NEXT_ONLY,
            self::MIN_NONE,
        ];

        $qualifying = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) ($raw['qualifying_statuses'] ?? [])
        ))));
        if ($qualifying === []) {
            $qualifying = $this->statusRegistry->defaultDoleQualifying();
        }

        $disqualifying = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) ($raw['disqualifying_statuses'] ?? [])
        ))));
        if ($disqualifying === []) {
            $disqualifying = $this->statusRegistry->defaultDoleDisqualifying();
        }

        $lookup = array_merge($this->doleDefaultAttendanceRule()['lookup'], (array) ($raw['lookup'] ?? []));

        return [
            'minimum_condition' => in_array($minimum, $allowedMinimum, true) ? $minimum : self::MIN_PREVIOUS_ONLY,
            'qualifying_statuses' => $qualifying,
            'disqualifying_statuses' => $disqualifying,
            'lookup' => [
                'skip_rest_days' => (bool) ($lookup['skip_rest_days'] ?? true),
                'skip_non_working_days' => (bool) ($lookup['skip_non_working_days'] ?? true),
                'skip_holidays' => (bool) ($lookup['skip_holidays'] ?? true),
                'skip_paid_leave' => (bool) ($lookup['skip_paid_leave'] ?? false),
            ],
        ];
    }

    /** @param  array<string, mixed>  $block */
    public function isCustomPolicyMode(array $block): bool
    {
        $mode = (string) ($block['policy_mode'] ?? '');

        return $mode === self::POLICY_MODE_CUSTOM;
    }

    /** @return list<string> */
    public function resolveDayStatusTags(User $employee, string $dateKey): array
    {
        $tags = [];
        $tz = (string) config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        [$timeIn, $timeOut] = $this->attendanceSession->getTimesForDate($employee, $dateKey, $tz);

        if ($this->hasApprovedPaidLeave($employee, $dateKey)) {
            $tags[] = 'approved_paid_leave';
        }

        $leave = $this->approvedLeaveOnDate($employee, $dateKey);
        if ($leave !== null && ! $this->leaveCreditService->dateIsPaidLeavePortion($employee, $leave, $dateKey)) {
            $tags[] = 'leave_without_pay';
        }

        $filingTag = $this->approvedPresenceFilingTag($employee, $dateKey);
        if ($filingTag !== null) {
            $tags[] = $filingTag;
        }

        if ($timeIn !== null && $timeOut === null) {
            $tags[] = 'incomplete';
        }

        if ($timeIn === null && $timeOut === null && $leave === null && $filingTag === null) {
            if (! in_array('approved_paid_leave', $tags, true)) {
                $tags[] = 'absent';
                $tags[] = 'unpaid_absence';
            }

            return array_values(array_unique($tags));
        }

        if ($timeIn !== null && $timeOut !== null) {
            $tags[] = 'present';
            $schedule = EmployeeScheduleResolver::resolve($employee);
            $daySchedule = is_array($schedule)
                ? ($schedule[EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz))] ?? null)
                : null;
            if (is_array($daySchedule) && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $dateKey,
                    $timeIn->copy()->timezone($tz)
                );
                if (($clockInResult['status'] ?? '') === AttendanceStatusResolver::STATUS_LATE) {
                    $tags[] = 'late';
                }
                $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
                if ($scheduledEnd instanceof Carbon) {
                    $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                    $undertime = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                        $dateKey,
                        $daySchedule,
                        $timeIn->copy()->timezone($tz),
                        $timeOut->copy()->timezone($tz),
                        $tz,
                        $earlyTimeout
                    );
                    if ($undertime > 0) {
                        $tags[] = 'undertime';
                    }
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /** @param  array<string, mixed>  $rules */
    public function dayMeetsQualification(User $employee, string $dateKey, array $rules): bool
    {
        $tags = $this->resolveDayStatusTags($employee, $dateKey);
        foreach ((array) ($rules['disqualifying_statuses'] ?? []) as $disqualifier) {
            if (in_array($disqualifier, $tags, true)) {
                return false;
            }
        }

        foreach ((array) ($rules['qualifying_statuses'] ?? []) as $qualifier) {
            if (in_array($qualifier, $tags, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $lookup
     */
    public function findAdjacentWorkingDay(User $employee, string $dateKey, string $direction, array $lookup): ?string
    {
        $cursor = Carbon::parse($dateKey);
        $schedule = EmployeeScheduleResolver::resolve($employee);
        $step = $direction === 'next' ? 1 : -1;
        $visited = [];

        for ($i = 0; $i < 370; $i++) {
            $cursor = $cursor->copy()->addDays($step);
            $key = $cursor->toDateString();
            if (isset($visited[$key])) {
                return null;
            }
            $visited[$key] = true;

            if ($this->shouldSkipLookupDate($employee, $key, $schedule, $lookup)) {
                continue;
            }

            return $key;
        }

        return null;
    }

    /** @param  array<string, mixed>  $policy */
    private function evaluateUnworkedAttendance(User $employee, array $holiday, string $dateKey, array $policy, string $kind): array
    {
        $qualification = $this->evaluateAttendanceQualification($employee, $dateKey, $policy, $kind);

        return $this->result(
            (bool) $qualification['met'],
            (bool) $qualification['met'],
            (string) $qualification['reason'],
            (string) $qualification['rule'],
            (string) ($qualification['rule_used'] ?? null),
            (array) ($qualification['evaluated_dates'] ?? [])
        );
    }

    /** @param  array<string, mixed>  $rules */
    private function evaluateSuccessiveRegularHoliday(User $employee, string $dateKey, array $policy, array $rules): ?array
    {
        $cursor = Carbon::parse($dateKey)->subDay();
        $successiveMode = (string) (($policy['regular_unworked']['successive_qualification'] ?? 'previous_working_day'));

        for ($i = 0; $i < 370; $i++) {
            $priorKey = $cursor->toDateString();
            $priorHoliday = $this->holidayService->resolveHolidayForPayroll($employee, $priorKey);
            $priorType = strtolower((string) ($priorHoliday['type'] ?? ''));
            if ($priorHoliday === null || ! in_array($priorType, ['regular', 'double'], true)) {
                break;
            }

            if ($this->workedOn($employee, $priorKey)) {
                return [
                    'date' => $priorKey,
                    'met' => true,
                    'reason' => 'Work on the first regular holiday qualifies the succeeding regular holiday.',
                    'rule' => 'successive_holiday_worked_first',
                    'rule_used' => 'successive_holiday_worked_first',
                    'evaluated_dates' => ['successive_holiday' => $priorKey],
                ];
            }

            if ($successiveMode === 'previous_and_first_holiday_worked') {
                return [
                    'date' => $priorKey,
                    'met' => false,
                    'reason' => 'Work on the first regular holiday is required for this successive rule.',
                    'rule' => 'successive_holiday_work_required',
                    'rule_used' => 'successive_holiday_work_required',
                    'evaluated_dates' => ['successive_holiday' => $priorKey],
                ];
            }

            $chain = $this->evaluateAttendanceQualification($employee, $priorKey, $policy, 'regular');

            return [
                'date' => $chain['date'],
                'met' => (bool) $chain['met'],
                'reason' => $chain['met']
                    ? 'The condition before the first regular holiday qualifies the successive holidays.'
                    : 'The condition before the first regular holiday was not met for the successive holidays.',
                'rule' => 'successive_holiday_chain',
                'rule_used' => 'successive_holiday_chain',
                'evaluated_dates' => (array) ($chain['evaluated_dates'] ?? []),
            ];
        }

        return null;
    }

    /** @param  array<string, mixed>  $policy */
    private function evaluateWorkedEmployment(User $employee, array $policy, string $kind): array
    {
        $blockKey = $kind === 'special' ? 'special_worked' : 'regular_worked';
        $block = (array) ($policy[$blockKey] ?? []);
        $rule = (string) ($block['employment_type_rule'] ?? 'all_employment_types');
        $allowed = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) ($block['eligible_employment_types'] ?? [])
        ))));
        $employmentType = app(EmploymentTypeResolver::class)->resolveForEmployee($employee);
        $allowedMatch = $rule !== 'selected_employment_types' || in_array($employmentType, $allowed, true);

        if (! $allowedMatch) {
            return $this->result(false, true, 'Employee employment type is not selected for worked holiday pay.', 'worked_employment_type_excluded', 'employment_type');
        }

        return $this->result(true, true, 'Worked on the holiday; attendance qualification does not bar holiday work pay.', 'worked_holiday', 'worked');
    }

    /** @param  array<string, bool>  $lookup */
    private function shouldSkipLookupDate(User $employee, string $dateKey, ?array $schedule, array $lookup): bool
    {
        $holiday = $this->holidayService->resolveHolidayForPayroll($employee, $dateKey);
        $type = strtolower((string) ($holiday['type'] ?? ''));

        if (($lookup['skip_holidays'] ?? true) && in_array($type, ['regular', 'double'], true)) {
            return true;
        }
        if (($lookup['skip_non_working_days'] ?? true) && $type === 'special') {
            return true;
        }
        if (($lookup['skip_paid_leave'] ?? false) && $this->hasApprovedPaidLeave($employee, $dateKey)) {
            return true;
        }
        if (($lookup['skip_rest_days'] ?? true) && $schedule !== null) {
            $day = $schedule[EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey))] ?? null;

            return ! is_array($day) || empty($day['in']);
        }

        return false;
    }

    private function approvedPresenceFilingTag(User $employee, string $dateKey): ?string
    {
        if (! Schema::hasTable('attendance_corrections')) {
            return null;
        }

        $correction = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->where('pending_approval', false)
            ->orderByDesc('id')
            ->first();

        if ($correction === null) {
            return null;
        }

        $reason = strtolower(trim((string) ($correction->manual_presence_reason ?? '')));
        $remarks = strtolower(trim((string) ($correction->remarks ?? '')));
        $blob = $reason.' '.$remarks;

        return match (true) {
            str_contains($blob, 'field') => 'field_work',
            str_contains($blob, 'official business'), str_contains($blob, 'official_business') => 'official_business',
            str_contains($blob, 'work from home'), str_contains($blob, 'wfh') => 'work_from_home',
            str_contains($blob, 'training'), str_contains($blob, 'seminar') => 'training',
            str_contains($blob, 'offset') => 'approved_offset',
            str_contains($blob, 'paid suspension') => 'paid_suspension',
            default => null,
        };
    }

    protected function workedOn(User $employee, string $dateKey): bool
    {
        $tz = (string) config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        [$timeIn, $timeOut] = $this->attendanceSession->getTimesForDate($employee, $dateKey, $tz);

        return $timeIn !== null && $timeOut !== null;
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

    /** @return array{eligible: bool, reason: string, rule: string, attendance_requirement_met: bool, rule_used: ?string, evaluated_dates: array<string, mixed>} */
    private function result(
        bool $eligible,
        bool $attendanceMet,
        string $reason,
        string $rule,
        ?string $ruleUsed = null,
        array $evaluatedDates = []
    ): array {
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'rule' => $rule,
            'attendance_requirement_met' => $attendanceMet,
            'rule_used' => $ruleUsed,
            'evaluated_dates' => $evaluatedDates,
        ];
    }

    /** @return array<string, mixed> */
    public function mergePolicyDefaults(array $policy): array
    {
        $merged = array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $policy);
        foreach (['regular_unworked', 'special_unworked'] as $blockKey) {
            $block = (array) ($merged[$blockKey] ?? []);
            $merged[$blockKey]['attendance_rule'] = $this->resolvedAttendanceRule($block, str_starts_with($blockKey, 'special') ? 'special' : 'regular');
        }

        return $merged;
    }
}
