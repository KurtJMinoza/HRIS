<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeCompensationComponent;
use App\Models\EmployeeDeduction;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payroll computation — Philippines Labor Code (Arts. 87, 93, 94) aligned with `config('payroll.rules')`.
 *
 * Uses {@see PayrollRulesEngineService}, {@see TimeSegmentationService}, {@see AttendanceSessionService}.
 * Docs: `docs/PAYROLL_RULES_ENGINE.md`; Admin snapshot: `GET /admin/payroll/policy-reference`.
 */
class PayrollComputationService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** @var array<string, ?LeaveRequest> */
    private array $approvedLeaveForDateCache = [];

    /** @var array<string, string> */
    private array $dayStatusCache = [];

    /** @var array<string, float> */
    private array $overtimeHoursCache = [];

    /** @var array<string, bool> */
    private array $overtimeExistsCache = [];

    /** @var array<string, bool> */
    private array $attendanceLogExistsCache = [];

    public function __construct(
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly PayrollCalculatorService $payrollCalculator,
        private readonly ScheduleRateService $scheduleRateService,
        private readonly TimeSegmentationService $timeSegmentation,
        private readonly AttendanceSessionService $attendanceSession,
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly HolidayService $holidayService,
        private readonly PolicyResolverService $policyResolver,
        private readonly DataScopeService $dataScopeService,
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly DeductionApplicationService $deductionApplicationService,
        private readonly LoanAmortizationService $loanAmortizationService,
    ) {}

    public function flushRuntimeCaches(): void
    {
        $this->approvedLeaveForDateCache = [];
        $this->dayStatusCache = [];
        $this->overtimeHoursCache = [];
        $this->overtimeExistsCache = [];
        $this->attendanceLogExistsCache = [];
        $this->attendanceSession->flushRuntimeCache();
        $this->policyResolver->flushRuntimeCaches();
        $this->holidayService->flushRuntimeCaches();
    }

    /**
     * Load attendance corrections, logs, approved OT stubs, approved leaves, and overtime aggregates once for
     * all employees in the pay window so bulk payslip generation avoids per-employee-per-day queries.
     *
     * Pair with {@see endBulkPayrollAttendancePrefetch()} in a {@code finally} block.
     *
     * @param  list<int>  $userIds
     * @return array{
     *     corrections_ms: float,
     *     ot_stub_ms: float,
     *     logs_ms: float,
     *     load_leaves_ms: float,
     *     load_overtime_ms: float,
     * }
     */
    public function beginBulkPayrollAttendancePrefetch(array $userIds, Carbon $from, Carbon $to): array
    {
        $out = [
            'corrections_ms' => 0.0,
            'ot_stub_ms' => 0.0,
            'logs_ms' => 0.0,
            'load_leaves_ms' => 0.0,
            'load_overtime_ms' => 0.0,
            'corrections_count' => 0,
            'logs_count' => 0,
            'leave_rows_count' => 0,
            'overtime_rows_count' => 0,
        ];

        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($ids === []) {
            return $out;
        }

        $sessionTimings = $this->attendanceSession->beginBulkPayrollSession(
            $ids,
            $from->toDateString(),
            $to->toDateString()
        );
        $out['corrections_ms'] = (float) ($sessionTimings['corrections_ms'] ?? 0);
        $out['ot_stub_ms'] = (float) ($sessionTimings['ot_stub_ms'] ?? 0);
        $out['logs_ms'] = (float) ($sessionTimings['logs_ms'] ?? 0);
        $out['corrections_count'] = (int) ($sessionTimings['corrections_count'] ?? 0);
        $out['logs_count'] = (int) ($sessionTimings['logs_count'] ?? 0);

        $__t = microtime(true);
        $this->seedBulkLeaveCachesForPayWindow($ids, $from, $to);
        $out['load_leaves_ms'] = (microtime(true) - $__t) * 1000;
        $out['leave_rows_count'] = count($this->approvedLeaveForDateCache);

        $__t = microtime(true);
        $this->seedBulkOvertimeCachesForPayWindow($ids, $from, $to);
        $out['load_overtime_ms'] = (microtime(true) - $__t) * 1000;
        $out['overtime_rows_count'] = count(array_filter($this->overtimeExistsCache));

        $tz = $this->getTimezone();
        $__t = microtime(true);
        $this->seedBulkAttendanceLogExistsCache($ids, $from, $to, $tz);
        $out['seed_attendance_log_cache_ms'] = (microtime(true) - $__t) * 1000;

        $__t = microtime(true);
        $this->holidayCalendar->preloadYearsForDateRange($from->toDateString(), $to->toDateString());
        $this->holidayService->preloadSwapHolidaysForRange($from->toDateString(), $to->toDateString());
        $this->preloadPoliciesForUsers($ids, $from, $to);
        $out['load_compute_context_ms'] = (microtime(true) - $__t) * 1000;

        return $out;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function seedBulkAttendanceLogExistsCache(array $userIds, Carbon $from, Carbon $to, string $tz): void
    {
        /** @var array<string, true> $datesWithLogs */
        $datesWithLogs = [];
        foreach ($this->attendanceSession->bulkLogsByUserId() as $uid => $logs) {
            foreach ($logs as $log) {
                $verifiedAt = $log->verified_at;
                if ($verifiedAt === null) {
                    continue;
                }
                $localDate = $verifiedAt->copy()->timezone($tz)->toDateString();
                $datesWithLogs[(int) $uid.'|'.$localDate.'|'.$tz] = true;
            }
        }

        foreach ($userIds as $uid) {
            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dk = $cursor->toDateString();
                $cacheKey = (int) $uid.'|'.$dk.'|'.$tz;
                $this->attendanceLogExistsCache[$cacheKey] = isset($datesWithLogs[$cacheKey]);
                $cursor->addDay();
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function preloadPoliciesForUsers(array $userIds, Carbon $from, Carbon $to): void
    {
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'company_id', 'branch_id']);
        $scopes = [];
        $seen = [];
        foreach ($users as $user) {
            $companyId = $user->getEffectiveCompanyId();
            $branchId = $user->branch_id !== null ? (int) $user->branch_id : null;
            $key = ($companyId ?? 'null').'|'.($branchId ?? 'null');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $scopes[] = ['company_id' => $companyId, 'branch_id' => $branchId];
        }
        if ($scopes === []) {
            return;
        }
        $this->policyResolver->preloadActivePoliciesForDateRange(
            $scopes,
            $from->toDateString(),
            $to->toDateString()
        );
    }

    public function endBulkPayrollAttendancePrefetch(): void
    {
        $this->attendanceSession->endBulkPayrollSession();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function seedBulkLeaveCachesForPayWindow(array $userIds, Carbon $from, Carbon $to): void
    {
        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($ids === []) {
            return;
        }

        $leaves = LeaveRequest::query()
            ->whereIn('user_id', $ids)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderByDesc('id')
            ->get();

        /** @var array<int, list<LeaveRequest>> */
        $byUser = [];
        foreach ($leaves as $lr) {
            $uid = (int) $lr->user_id;
            $byUser[$uid][] = $lr;
        }

        foreach ($ids as $uid) {
            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dk = $cursor->toDateString();
                $chosen = null;
                foreach ($byUser[$uid] ?? [] as $lr) {
                    $sd = $lr->start_date instanceof Carbon
                        ? $lr->start_date->toDateString()
                        : Carbon::parse((string) $lr->start_date)->toDateString();
                    $ed = $lr->end_date instanceof Carbon
                        ? $lr->end_date->toDateString()
                        : Carbon::parse((string) $lr->end_date)->toDateString();
                    if ($dk >= $sd && $dk <= $ed) {
                        $chosen = $lr;
                        break;
                    }
                }
                $this->approvedLeaveForDateCache[$uid.'|'.$dk] = $chosen;
                $cursor->addDay();
            }
        }
    }

    /**
     * Warm {@see $overtimeHoursCache} / {@see $overtimeExistsCache} for the pay window ±1 day (prev-day OT fallback).
     *
     * @param  list<int>  $userIds
     */
    private function seedBulkOvertimeCachesForPayWindow(array $userIds, Carbon $from, Carbon $to): void
    {
        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($ids === []) {
            return;
        }

        $fromExt = $from->copy()->startOfDay()->subDay();
        $toExt = $to->copy()->startOfDay()->addDay();

        foreach ($ids as $uid) {
            $cursor = $fromExt->copy();
            while ($cursor->lessThanOrEqualTo($toExt)) {
                $d = $cursor->toDateString();
                $existsKey = $uid.'|'.$d;
                $this->overtimeExistsCache[$existsKey] = false;
                foreach ([Overtime::STATUS_APPROVED, Overtime::STATUS_PENDING] as $st) {
                    $this->overtimeHoursCache[$existsKey.'|'.$st] = 0.0;
                }
                $cursor->addDay();
            }
        }

        $rows = Overtime::query()
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $fromExt->toDateString())
            ->whereDate('date', '<=', $toExt->toDateString())
            ->get(['user_id', 'date', 'status', 'computed_hours']);

        foreach ($rows as $ot) {
            $d = $ot->date instanceof Carbon
                ? $ot->date->toDateString()
                : Carbon::parse((string) $ot->date)->toDateString();
            $uid = (int) $ot->user_id;
            $existsKey = $uid.'|'.$d;
            $this->overtimeExistsCache[$existsKey] = true;
            $hk = $existsKey.'|'.(string) $ot->status;
            $this->overtimeHoursCache[$hk] = ($this->overtimeHoursCache[$hk] ?? 0.0) + (float) ($ot->computed_hours ?? 0);
        }
    }

    public function getTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Resolve effective schedule for a user (JSON schedule or WorkingSchedule).
     */
    public function resolveEffectiveSchedule(User $user): ?array
    {
        return $this->scheduleRateService->resolveEffectiveSchedule($user);
    }

    /**
     * Schedule for Admin Daily Computation when the employee has no JSON schedule and no WorkingSchedule.
     * Matches typical PH office (Mon–Sat 08:00–17:00 with lunch) so attendance + OT still appear in the list
     * (same expectation as Attendance reports, which do not skip unscheduled employees with punches).
     */
    private function defaultOfficeScheduleFallback(): array
    {
        $grace = (int) config('attendance.grace_period_minutes', 5);
        $otBuf = (int) config('attendance.overtime_buffer_minutes', 15);
        $workDay = [
            'in' => '08:00',
            'out' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'grace_period_minutes' => $grace,
            'early_timeout_minutes' => null,
            'overtime_buffer_minutes' => $otBuf,
        ];
        $out = [];
        foreach (self::DAY_KEYS as $key) {
            $out[$key] = ($key === 'sun') ? null : $workDay;
        }

        return $out;
    }

    /**
     * @return array{0: array, 1: bool} [schedule, usedFallback]
     */
    private function resolveEffectiveScheduleForDailyComputation(User $user): array
    {
        $assigned = $this->resolveEffectiveSchedule($user);
        if ($assigned !== null) {
            return [$assigned, false];
        }

        return [$this->defaultOfficeScheduleFallback(), true];
    }

    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $ws): ?array
    {
        return $this->scheduleRateService->buildScheduleFromWorkingSchedule($ws);
    }

    /**
     * Get day key (sun–sat) for a date.
     */
    public function dayKeyForDate(Carbon $date): string
    {
        $w = max(0, min(6, (int) $date->format('w')));

        return self::DAY_KEYS[$w];
    }

    /**
     * Is this date a rest day for the user's schedule?
     */
    public function isRestDay(array $effectiveSchedule, Carbon $date): bool
    {
        $dayKey = $this->dayKeyForDate($date);

        return ! isset($effectiveSchedule[$dayKey]) || $effectiveSchedule[$dayKey] === null;
    }

    /**
     * Get holiday for a date (regular, special, company, double).
     * Also checks swap holidays with coverage.
     *
     * @return array{name: string, type: string}|null
     */
    public function getHolidayForDate(
        string $dateKey,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null
    ): ?array
    {
        $row = $this->holidayCalendar->holidayForDate($dateKey, $companyId, $branchId, $departmentId, $employeeId);
        if (! $row) {
            return null;
        }

        return ['name' => $row['name'], 'type' => $row['type']];
    }

    /**
     * Get holiday for a date with full swap coverage resolution for a specific user.
     *
     * @return array{name: string, type: string, is_swap?: bool}|null
     */
    public function getHolidayForUserDate(User $user, string $dateKey): ?array
    {
        $row = $this->holidayService->resolveHolidayForPayroll($user, $dateKey);
        if (! $row) {
            return null;
        }

        return [
            'name' => $row['name'],
            'type' => $row['type'],
            'is_swap' => $row['is_swap'] ?? false,
        ];
    }

    /**
     * Night differential: +10% for work between 10PM and 6AM.
     * Returns 1.10 for ND hours; 1.0 for non-ND.
     */
    public function getNightDifferentialMultiplier(): float
    {
        return (float) (config('payroll.night_differential_multiplier', 1.10));
    }

    /**
     * Split worked minutes into day vs night (10PM–6AM).
     *
     * @return array{day_minutes: int, night_minutes: int}
     */
    public function splitDayAndNightMinutes(
        Carbon $timeIn,
        Carbon $timeOut,
        string $dateKey,
        ?string $tz = null
    ): array {
        $tz = $tz ?? $this->getTimezone();
        $ndStart = (int) config('payroll.night_differential_start_hour', 22); // 10PM
        $ndEnd = (int) config('payroll.night_differential_end_hour', 6);     // 6AM

        $dayMinutes = 0;
        $nightMinutes = 0;

        $in = $timeIn->copy()->timezone($tz);
        $out = $timeOut->copy()->timezone($tz);

        $cursor = $in->copy();
        $stepMinutes = 1;
        $totalMinutes = (int) $in->diffInMinutes($out);

        for ($m = 0; $m < $totalMinutes; $m += $stepMinutes) {
            $minStep = min($stepMinutes, $totalMinutes - $m);
            $hour = (int) $cursor->format('G');
            $isNight = $hour >= $ndStart || $hour < $ndEnd;
            if ($isNight) {
                $nightMinutes += $minStep;
            } else {
                $dayMinutes += $minStep;
            }
            $cursor->addMinutes($minStep);
        }

        return ['day_minutes' => $dayMinutes, 'night_minutes' => $nightMinutes];
    }

    /**
     * Split minutes into regular (≤8h) vs overtime (>8h) and day vs night.
     *
     * @return array{
     *   regular_day: int,
     *   regular_night: int,
     *   ot_day: int,
     *   ot_night: int,
     *   total_minutes: int
     * }
     */
    public function splitRegularAndOvertimeWithND(
        Carbon $timeIn,
        Carbon $timeOut,
        string $dateKey,
        int $requiredMinutes,
        ?string $tz = null
    ): array {
        $tz = $tz ?? $this->getTimezone();
        $workedMinutes = (int) $timeIn->diffInMinutes($timeOut);
        $regularMinutes = min($workedMinutes, $requiredMinutes);
        $otMinutes = max(0, $workedMinutes - $requiredMinutes);

        $regularEnd = $timeIn->copy()->addMinutes($regularMinutes);
        $splitRegular = $this->splitDayAndNightMinutes($timeIn, $regularEnd, $dateKey, $tz);
        $splitOt = $otMinutes > 0
            ? $this->splitDayAndNightMinutes($regularEnd, $timeOut, $dateKey, $tz)
            : ['day_minutes' => 0, 'night_minutes' => 0];

        return [
            'regular_day' => $splitRegular['day_minutes'],
            'regular_night' => $splitRegular['night_minutes'],
            'ot_day' => $splitOt['day_minutes'],
            'ot_night' => $splitOt['night_minutes'],
            'total_minutes' => $workedMinutes,
        ];
    }

    /**
     * Compute hourly rate from daily rate (daily / 8).
     */
    public function hourlyRateFromDaily(float $dailyRate): float
    {
        return $dailyRate / 8.0;
    }

    /**
     * Compute pay for a single day with full breakdown (audit-grade).
     *
     * @return array{
     *   date: string,
     *   is_rest_day: bool,
     *   holiday: array{name: string, type: string}|null,
     *   status: string,
     *   conditions: array,
     *   breakdown: array,
     *   total_pay: float,
     *   worked_minutes: int,
     *   required_minutes: int,
     *   regular_day_minutes: int,
     *   regular_night_minutes: int,
     *   ot_day_minutes: int,
     *   ot_night_minutes: int,
     *   late_deduction_minutes: int,
     *   undertime_deduction_minutes: int,
     *   ...audit fields
     * }
     */
    public function computeDayPayroll(
        User $user,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        array $effectiveSchedule,
        float $dailyRate,
        ?string $tz = null
    ): array {
        $tz = $tz ?? $this->getTimezone();
        $dayKey = $this->dayKeyForDate(Carbon::parse($dateKey, $tz));
        $daySchedule = $effectiveSchedule[$dayKey] ?? null;
        $isRestDay = $this->isRestDay($effectiveSchedule, Carbon::parse($dateKey, $tz));
        $holiday = $this->getHolidayForUserDate($user, $dateKey)
            ?? $this->getHolidayForDate(
                $dateKey,
                $user->getEffectiveCompanyId(),
                $user->branch_id !== null ? (int) $user->branch_id : null,
                $user->department_id !== null ? (int) $user->department_id : null,
                (int) $user->id
            );

        // Phase 2: Rules Engine – resolve rule code and multipliers (policy-aware)
        $companyId = $user->getEffectiveCompanyId();
        $branchId = $user->branch_id;
        $policy = $this->policyResolver->getActivePolicy($companyId, $branchId, $dateKey);
        $resolvedHolidayType = $holiday ? $this->rulesEngine->holidayTypeFromHolidayRow($holiday) : null;
        $ruleCode = $this->rulesEngine->resolveRuleCode($isRestDay, $resolvedHolidayType);
        $multipliers = $this->rulesEngine->getMultipliersForRule($ruleCode, $policy);
        $first8 = $multipliers['first_8'];
        $otMult = $multipliers['ot'];
        $ndBase = $multipliers['nd_base'] ?? $first8;
        $ndPremium = (float) ($multipliers['nd_addon'] ?? config('payroll.nd_premium', 0.10));

        $ndConfig = $this->policyResolver->getNdConfig($policy);
        $ndStartHour = $ndConfig['start_hour'] ?? null;
        $ndEndHour = $ndConfig['end_hour'] ?? null;

        $hourlyRate = $this->hourlyRateFromDaily($dailyRate);
        $requiredMinutes = $daySchedule
            ? AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $tz)
            : 0;

        $conditions = [
            'rule_code' => $ruleCode,
            'is_rest_day' => $isRestDay,
            'holiday_type' => $holiday['type'] ?? null,
            'holiday_name' => $holiday['name'] ?? null,
            'first_8' => $first8,
            'ot' => $otMult,
            'nd_base' => $ndBase,
        ];

        $breakdown = [];
        $totalPay = 0.0;
        $workedMinutes = 0;
        $regularDayMinutes = 0;
        $regularNightMinutes = 0;
        $otDayMinutes = 0;
        $otNightMinutes = 0;
        $lateDeductionMinutes = 0;
        $undertimeDeductionMinutes = 0;

        if (! $timeIn || ! $timeOut) {
            $status = $this->resolveDayStatus($user, $dateKey, $daySchedule);
            $breakdown = [];
            $totalPay = 0.0;
            $workedMinutes = 0;
            $regularDayMinutes = 0;
            $regularNightMinutes = 0;

            $holidayPremiumPayForLeave = 0.0;
            if ($status === 'leave') {
                $leave = $this->approvedPrimaryLeaveForDate($user, $dateKey);
                $leaveCredits = app(LeaveCreditService::class);
                if ($leave && $leaveCredits->consumesCredits((string) $leave->type) && ! $isRestDay) {
                    if ($leaveCredits->dateIsPaidLeavePortion($user, $leave, $dateKey)) {
                        $paid = $this->computePaidLeaveCompensationForDay(
                            $daySchedule,
                            $leave,
                            $dailyRate,
                            $hourlyRate,
                            $first8,
                            $requiredMinutes,
                            $holiday
                        );
                        $totalPay = $paid['total_pay'];
                        $breakdown = $paid['breakdown'];
                        $workedMinutes = $paid['worked_minutes'];
                        $regularDayMinutes = $paid['regular_day_minutes'];
                        $regularNightMinutes = $paid['regular_night_minutes'];
                        $holidayPremiumPayForLeave = (float) ($paid['holiday_premium_pay'] ?? 0.0);
                    } else {
                        $breakdown = [[
                            'component' => 'unpaid_leave',
                            'amount' => 0.0,
                            'note' => 'Approved leave without paid leave credits (probationary, exhausted pool, or unpaid portion of request).',
                            'leave_type' => $leave->type,
                        ]];
                    }
                }
            }

            // Section 12: Approved OT without actual clock logs.
            // Default = not payable. Exception: rest day, holiday, or no schedule (special policy).
            $noPunchApprovedOtHours = 0.0;
            $noPunchOtPay = 0.0;
            $noPunchOtApplied = 0.0;
            $noPunchHasOtRequest = false;
            $isPolicyException = $isRestDay || $holiday !== null || $daySchedule === null;

            if ($isPolicyException) {
                $uid = (int) $user->id;
                $hoursCacheKey = $uid.'|'.$dateKey.'|'.Overtime::STATUS_APPROVED;
                $existsCacheKey = $uid.'|'.$dateKey;
                if (array_key_exists($hoursCacheKey, $this->overtimeHoursCache)) {
                    $noPunchApprovedOtHours = (float) $this->overtimeHoursCache[$hoursCacheKey];
                } else {
                    $noPunchApprovedOtHours = (float) Overtime::query()
                        ->where('user_id', $user->id)
                        ->where('status', Overtime::STATUS_APPROVED)
                        ->whereDate('date', $dateKey)
                        ->sum('computed_hours');
                }
                if (array_key_exists($existsCacheKey, $this->overtimeExistsCache)) {
                    $noPunchHasOtRequest = $noPunchApprovedOtHours > 0.0001
                        || (bool) $this->overtimeExistsCache[$existsCacheKey];
                } else {
                    $noPunchHasOtRequest = $noPunchApprovedOtHours > 0.0001
                        || Overtime::query()
                            ->where('user_id', $user->id)
                            ->whereDate('date', $dateKey)
                            ->exists();
                }

                if ($noPunchApprovedOtHours > 0.0001) {
                    $noPunchOtApplied = $noPunchApprovedOtHours;
                    $noPunchOtPay = round($noPunchApprovedOtHours * $hourlyRate * $otMult, 2);
                    $totalPay += $noPunchOtPay;
                    $breakdown[] = [
                        'component' => 'ot_pay_no_clock',
                        'hours' => $noPunchApprovedOtHours,
                        'rate' => $hourlyRate,
                        'multiplier' => $otMult,
                        'amount' => $noPunchOtPay,
                        'note' => 'Approved OT on rest day/holiday/no-schedule without clock logs (policy exception).',
                    ];
                }
            }

            $base = $this->buildDayResult($dateKey, $isRestDay, $holiday, $status, $conditions, $breakdown, $totalPay, $workedMinutes, $regularDayMinutes, $regularNightMinutes, 0, 0, $requiredMinutes, $lateDeductionMinutes, $undertimeDeductionMinutes);

            $leaveRegularPay = ($holidayPremiumPayForLeave > 0.0001)
                ? round($totalPay - $holidayPremiumPayForLeave - $noPunchOtPay, 2)
                : round($totalPay - $noPunchOtPay, 2);

            return array_merge($base, [
                'policy_id' => $policy?->id,
                'policy_snapshot' => $this->policyResolver->buildPolicySnapshot($policy, $ruleCode),
                'regular_pay' => max(0.0, $leaveRegularPay),
                'ot_pay' => $noPunchOtPay,
                'nd_pay' => 0.0,
                'holiday_premium_pay' => round($holidayPremiumPayForLeave, 2),
                'approved_ot_hours' => $noPunchApprovedOtHours,
                'pending_ot_hours' => 0.0,
                'unapproved_ot_hours' => 0.0,
                'has_overtime_request' => $noPunchHasOtRequest,
                'ot_premium_applied_hours' => round($noPunchOtApplied, 2),
                'ot_premium_ratio' => 0.0,
                'uncovered_ot_hours' => 0.0,
                'nd_premium_applied' => false,
            ]);
        }

        $timeInTz = $timeIn->copy()->timezone($tz);
        $timeOutTz = $timeOut->copy()->timezone($tz);

        // Phase 4: Time Segmentation with ND Regular vs ND OT split (net of meal break when schedule has break)
        $seg = $this->timeSegmentation->segment($timeInTz, $timeOutTz, $tz, $daySchedule, $dateKey, $ndStartHour, $ndEndHour);
        $regSeg = (int) $seg['regular_minutes'];
        $ndReg = (int) ($seg['nd_regular_minutes'] ?? 0);
        $otDayMinutes = (int) $seg['overtime_minutes'] - (int) ($seg['nd_overtime_minutes'] ?? 0);
        $otNightMinutes = (int) ($seg['nd_overtime_minutes'] ?? 0);
        $workedMinutes = (int) $seg['total_minutes'];

        $clockInResult = null;
        if ($daySchedule) {
            $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $timeInTz);
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
            $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
            $undertimeDeductionMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                $dateKey, $daySchedule, $timeInTz, $timeOutTz, $tz, $earlyTimeout
            );
        }

        // Tardiness / half-day: cap paid regular minutes (net of segmentation) before pay; OT after scheduled end unchanged.
        $paidReg = $regSeg;
        if ($daySchedule && $clockInResult !== null) {
            $schedStatus = $clockInResult['status'] ?? '';
            if ($schedStatus === 'late') {
                $bucketMin = (int) ($clockInResult['late_minutes'] ?? 0);
                $cap = max(0, $requiredMinutes - $bucketMin);
                $paidReg = min($regSeg, $cap);
            } elseif ($schedStatus === 'half_day') {
                $paidReg = min($regSeg, AttendanceStatusService::getHalfDayRegularCapMinutes());
            }
        }

        // Grace: full scheduled net regular pay when clock-in is within ±grace minutes of scheduled start
        // (same window as grace / Present), no fractional pay cut vs segmentation (e.g. 8:01 → 8.00h like 07:59).
        if ($daySchedule && $clockInResult !== null
            && ($clockInResult['status'] ?? '') === 'on_time'
            && $requiredMinutes > 0
            && filter_var(config('attendance.grace_period_full_regular_pay', true), FILTER_VALIDATE_BOOL)) {
            $schedStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $tz);
            if ($schedStart) {
                $graceM = AttendanceStatusService::getGraceMinutes($daySchedule);
                $actualM = (int) $timeInTz->format('G') * 60 + (int) $timeInTz->format('i');
                $schedM = (int) $schedStart->format('G') * 60 + (int) $schedStart->format('i');
                $diffMin = $actualM - $schedM;
                if (abs($diffMin) <= $graceM
                    && $undertimeDeductionMinutes === 0
                    && $regSeg >= $requiredMinutes - $graceM) {
                    $paidReg = max($paidReg, $requiredMinutes);
                }
            }
        }

        // Payroll Impact / Payslip base: once the employee fully covers the scheduled shift,
        // regular payable minutes must be the schedule's net required minutes. This prevents
        // 7.98h-style drift from second-level punches or minute segmentation on an 8.00h schedule.
        if ($this->coversScheduledRegularShift(
            $dateKey,
            $daySchedule,
            $timeInTz,
            $timeOutTz,
            $requiredMinutes,
            $undertimeDeductionMinutes,
            $clockInResult,
            $tz
        )) {
            $paidReg = max($paidReg, $requiredMinutes);
        }

        $graceCreditMinutes = max(0, $paidReg - $regSeg);
        $lateDeductionMinutes = max(0, $regSeg - $paidReg);

        // PH Labor Code Art. 83: normal-hours regular pay is capped at 8h/day.
        // Use a fixed 8-hour cap for regular-pay computation (instead of env-config threshold) so
        // long scheduled shifts never inflate payslip "Regular pay" day-equivalent counts.
        // Any minutes beyond 8h/day must flow through overtime policy, not basic regular pay.
        $regularDailyThresholdMinutes = 8 * 60;
        $regularMinutesOverThreshold = 0;
        if ($regularDailyThresholdMinutes > 0 && $paidReg > $regularDailyThresholdMinutes) {
            $regularMinutesOverThreshold = $paidReg - $regularDailyThresholdMinutes;
            $paidReg = $regularDailyThresholdMinutes;
            // Clamp segmented regular + ND regular so ratio / ND split below stays consistent.
            $regSeg = min($regSeg, $regularDailyThresholdMinutes);
            if ($ndReg > $regularDailyThresholdMinutes) {
                $ndReg = $regularDailyThresholdMinutes;
            }
        }

        $ratio = $regSeg > 0 ? ($paidReg / $regSeg) : 0.0;
        $ndRegPaid = (int) round($ndReg * $ratio);
        $regularDayMinutes = (int) round(($regSeg - $ndReg) * $ratio);
        $regularNightMinutes = $ndRegPaid;
        $deltaPaid = $paidReg - $regularDayMinutes - $regularNightMinutes;
        if ($deltaPaid !== 0) {
            $regularDayMinutes += $deltaPaid;
        }

        // Phase 5 (before pay): OT requests from Overtime module — premium pay only on approved hours.
        $approvedOtHours = $this->sumOvertimeHoursForWorkday($user, $dateKey, $timeInTz, Overtime::STATUS_APPROVED);
        $pendingOtHours = $this->sumOvertimeHoursForWorkday($user, $dateKey, $timeInTz, Overtime::STATUS_PENDING);
        $hasOvertimeRequest = $this->hasOvertimeRequestForWorkday($user, $dateKey, $timeInTz);

        $renderedOtMinutes = (int) $seg['overtime_minutes'];
        $approvedOtMinutes = (int) round($approvedOtHours * 60);
        $paidOtMinutes = min($renderedOtMinutes, max(0, $approvedOtMinutes));
        $otPremiumRatio = $renderedOtMinutes > 0 ? ($paidOtMinutes / $renderedOtMinutes) : 0.0;
        $ndOvertimeMinutesRaw = (int) ($seg['nd_overtime_minutes'] ?? 0);
        $effectiveNdOvertimeMinutes = (int) round($ndOvertimeMinutesRaw * $otPremiumRatio);

        $renderedOtHoursForAudit = (float) ($seg['overtime_hours'] ?? ($renderedOtMinutes / 60.0));
        $uncoveredOtHours = max(0, $renderedOtHoursForAudit - $approvedOtHours - $pendingOtHours);
        $unapprovedOtHours = $hasOvertimeRequest ? $uncoveredOtHours : 0.0;

        // ND premium (pay + weighted-units audit): only when approved OT exists OR day is not plain ordinary
        // (rest/holiday/non-ORD rule or first_8 > 1). Otherwise ND hours still appear in attendance but earn no ND premium.
        $hasCalendarHoliday = $holiday !== null;
        $isPremiumDayContext = $isRestDay || $hasCalendarHoliday || $ruleCode !== 'ORD' || $first8 > 1.00001;
        $allowNdPremium = ($approvedOtHours > 0.00001) || $isPremiumDayContext;

        // Phase 3 + 4: Pay formulas (Labor Code Art. 87 / 93 / 94; ND +10% on applicable hourly rate)
        // First8Pay = (paid_regular_minutes/60) × hourly × first_8 — tardiness already reflected in paidReg
        // OTPay = approved OT minutes only × HWR × ot (rendered OT without approval does not earn OT premium)
        // NDPay = ND_regular_paid × (HWR × nd_base × premium) + ND_ot × (HWR × ot × premium), scaled with OT premium gate
        //
        // Statutory/special holiday premium (first_8 > 1): only when the employee is treated as present for a full
        // scheduled shift, or paid leave applies (handled in the no-punch branch above). Partial/absent days pay
        // regular minutes at ordinary 1.0× — no holiday_premium line — matches Daily Computation / payslip "(absent)".
        $isStatutoryHolidayRate = $holiday !== null && $first8 > 1.00001;
        $scheduleToleranceMin = 3;
        $meetsFullScheduledPresence = $requiredMinutes <= 0
            ? $paidReg > 0
            : $paidReg >= max(0, $requiredMinutes - $scheduleToleranceMin);
        $qualifiesStatutoryHolidayPremium = $isStatutoryHolidayRate && $meetsFullScheduledPresence;
        $payFirst8Multiplier = $qualifiesStatutoryHolidayPremium ? $first8 : 1.0;

        $first8Pay = ($paidReg / 60.0) * $hourlyRate * $payFirst8Multiplier;
        $otPay = ($paidOtMinutes / 60.0) * $hourlyRate * $otMult;
        $ndPayRegular = ($ndRegPaid / 60.0) * $hourlyRate * $ndBase * $ndPremium;
        $ndPayOt = ($effectiveNdOvertimeMinutes / 60.0) * $hourlyRate * $otMult * $ndPremium;
        $ndPay = $allowNdPremium ? ($ndPayRegular + $ndPayOt) : 0.0;

        $totalPay = $first8Pay + $otPay + $ndPay;
        // Holiday premium = full first-8 pay at holiday rate (entire day compensation, NOT just increment).
        // On holiday days, regular_pay becomes 0 and the full first8Pay moves to holiday_premium.
        $isHolidayDay = $qualifiesStatutoryHolidayPremium;
        $holidayPremiumPay = $isHolidayDay ? round($first8Pay, 2) : 0.0;
        $regularBasePayOnly = $isHolidayDay ? 0.0 : round($first8Pay, 2);

        $ndNightMinutesForBreakdown = $ndRegPaid + $effectiveNdOvertimeMinutes;
        $breakdown[] = ['component' => 'regular_pay', 'minutes' => $isHolidayDay ? 0 : $paidReg, 'rate' => $hourlyRate, 'multiplier' => 1.0, 'amount' => max(0.0, $regularBasePayOnly)];
        if ($regularMinutesOverThreshold > 0) {
            $breakdown[] = [
                'component' => 'regular_hours_over_threshold',
                'minutes' => $regularMinutesOverThreshold,
                'amount' => 0.0,
                'note' => 'Scheduled minutes beyond '.($regularDailyThresholdMinutes / 60).'h daily threshold (Labor Code Art. 83). Excluded from Regular pay — file as overtime to earn premium.',
            ];
        }
        $breakdown[] = [
            'component' => 'ot_pay',
            'minutes' => $paidOtMinutes,
            'rendered_ot_minutes' => $renderedOtMinutes,
            'rate' => $hourlyRate,
            'multiplier' => $otMult,
            'amount' => round($otPay, 2),
        ];
        $breakdown[] = [
            'component' => 'nd_pay',
            'minutes' => $ndNightMinutesForBreakdown,
            'rate' => $hourlyRate,
            'premium' => $ndPremium,
            'amount' => round($ndPay, 2),
            'nd_premium_applied' => $allowNdPremium,
        ];
        if (! $allowNdPremium && ($ndRegPaid > 0 || $ndOvertimeMinutesRaw > 0)) {
            $breakdown[] = [
                'component' => 'nd_premium_blocked',
                'minutes' => $ndRegPaid + $ndOvertimeMinutesRaw,
                'amount' => 0.0,
                'note' => 'ND premium not applied — ordinary day with no approved overtime (attendance ND hours unchanged).',
            ];
        }
        if ($holidayPremiumPay > 0) {
            $breakdown[] = [
                'component' => 'holiday_premium',
                'minutes' => $paidReg,
                'rate' => $hourlyRate,
                'multiplier' => $first8,
                'premium_multiplier' => round($first8, 2),
                'holiday_name' => $holiday['name'] ?? null,
                'holiday_type' => $holiday['type'] ?? null,
                'amount' => round($holidayPremiumPay, 2),
            ];
        }
        if ($graceCreditMinutes > 0) {
            $breakdown[] = [
                'component' => 'grace_period_regular_credit',
                'minutes' => $graceCreditMinutes,
                'amount' => 0.0,
                'note' => 'Full scheduled net regular pay within grace period (no deduction vs segmented minutes).',
            ];
        }
        if ($lateDeductionMinutes > 0 && $daySchedule && $clockInResult !== null) {
            $breakdown[] = [
                'component' => 'tardiness',
                'label' => $clockInResult['late_label'] ?? 'Tardiness',
                'minutes_segmented_regular' => $regSeg,
                'minutes_paid_regular' => $paidReg,
                'minutes_adjustment' => -$lateDeductionMinutes,
                'amount' => 0.0,
            ];
        }

        if ($undertimeDeductionMinutes > 0) {
            $breakdown[] = [
                'component' => 'undertime_deduction',
                'minutes' => $undertimeDeductionMinutes,
                'amount' => 0.0,
                'note' => 'Regular pay is based on actual worked regular minutes; undertime minutes are the unpaid scheduled shortfall and are not deducted a second time.',
            ];
        }

        $policySnapshot = $this->policyResolver->buildPolicySnapshot($policy, $ruleCode);

        // Phase 6: Ledger structure
        // Display OT minutes = approved (paid) OT only. Raw rendered OT is kept for audit.
        $paidOtDayMinutes = (int) round($otDayMinutes * $otPremiumRatio);
        $paidOtNightMinutes = (int) round($otNightMinutes * $otPremiumRatio);
        $deltaOtPaid = $paidOtMinutes - ($paidOtDayMinutes + $paidOtNightMinutes);
        if ($deltaOtPaid !== 0) {
            $paidOtDayMinutes += $deltaOtPaid;
        }

        return array_merge($this->buildDayResult($dateKey, $isRestDay, $holiday, 'worked', $conditions, $breakdown, round($totalPay, 2), $workedMinutes, $regularDayMinutes, $regularNightMinutes, $paidOtDayMinutes, $paidOtNightMinutes, $requiredMinutes, $lateDeductionMinutes, $undertimeDeductionMinutes), [
            'regular_pay' => round($regularBasePayOnly, 2),
            'ot_pay' => round($otPay, 2),
            'nd_pay' => round($ndPay, 2),
            'holiday_premium_pay' => round($holidayPremiumPay, 2),
            // Applied approved OT is capped by actual rendered OT. Keep the raw request separately
            // so payroll/daily records do not display an 8.00h approval when only 0.17h was worked.
            'approved_ot_hours' => round($paidOtMinutes / 60.0, 2),
            'approved_ot_requested_hours' => round($approvedOtHours, 2),
            'pending_ot_hours' => $pendingOtHours,
            'unapproved_ot_hours' => $unapprovedOtHours,
            'has_overtime_request' => $hasOvertimeRequest,
            'ot_premium_applied_hours' => round($paidOtMinutes / 60.0, 2),
            'ot_premium_ratio' => $otPremiumRatio,
            'uncovered_ot_hours' => round($uncoveredOtHours, 2),
            'rendered_ot_day_minutes' => $otDayMinutes,
            'rendered_ot_night_minutes' => $otNightMinutes,
            'nd_premium_applied' => $allowNdPremium,
            'policy_id' => $policy?->id,
            'policy_snapshot' => $policySnapshot,
            'tardiness_label' => $daySchedule && $clockInResult ? ($clockInResult['late_label'] ?? null) : null,
            'tardiness_status' => $daySchedule && $clockInResult ? ($clockInResult['status'] ?? null) : null,
            'segmented_regular_minutes' => $regSeg,
            'paid_regular_minutes' => $paidReg,
            'grace_period_credit_minutes' => $graceCreditMinutes,
        ]);
    }

    /**
     * Payable minutes for Attendance / Reports "Payroll Impact (hrs)" — same engine as the payslip daily row:
     * {@see computeDayPayroll()} paid regular (day + night) plus paid OT (day + night).
     *
     * Uses segmentation, tardiness caps, and grace regular credit — not raw
     * {@see AttendanceStatusService::getNetWorkedMinutes()} (total-hours includes pre-shift span that payroll
     * books as OT, which inflated Impact vs the Regular pay line).
     *
     * Missing punches follow the payroll no-punch branch (0 worked pay unless paid leave / policy OT-without-clock);
     * never substitutes full scheduled hours. Schedule resolution matches payroll via
     * {@see resolveEffectiveScheduleForDailyComputation()}.
     *
     * @param  Carbon|null  $timeIn  Log/correction/virtual OT end time in (any TZ); normalized inside computeDayPayroll.
     * @param  Carbon|null  $timeOut
     */
    public function payrollImpactMinutesForAttendanceDisplay(
        User $user,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        ?string $tz = null
    ): int {
        $tz = $tz ?? $this->getTimezone();
        [$effectiveSchedule] = $this->resolveEffectiveScheduleForDailyComputation($user);
        // Peso amounts use this rate; minute ledger for worked days is invariant for any positive stub.
        $day = $this->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 1000.0, $tz);
        $reg = (int) ($day['regular_day_minutes'] ?? 0) + (int) ($day['regular_night_minutes'] ?? 0);
        $ot = (int) ($day['ot_day_minutes'] ?? 0) + (int) ($day['ot_night_minutes'] ?? 0);

        return max(0, $reg + $ot);
    }

    /**
     * True when a worked day covers the whole scheduled regular shift.
     *
     * This is the schedule-based gate used by payroll impact, daily computation, and payslip totals:
     * full scheduled presence earns the schedule's net required minutes as regular base pay, while
     * late, half-day, undertime, and incomplete days continue using the actual/capped regular minutes.
     */
    private function coversScheduledRegularShift(
        string $dateKey,
        ?array $daySchedule,
        Carbon $timeInTz,
        Carbon $timeOutTz,
        int $requiredMinutes,
        int $undertimeDeductionMinutes,
        ?array $clockInResult,
        string $tz
    ): bool {
        if (! $daySchedule || $requiredMinutes <= 0 || $undertimeDeductionMinutes > 0) {
            return false;
        }

        $status = (string) ($clockInResult['status'] ?? '');
        if ($status === 'late' || $status === 'half_day') {
            return false;
        }

        $scheduledStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $tz);
        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledStart || ! $scheduledEnd) {
            return false;
        }

        $toleranceMinutes = max(0, (int) config('attendance.payroll_schedule_coverage_tolerance_minutes', 2));
        if ($timeInTz->greaterThan($scheduledStart->copy()->addMinutes($toleranceMinutes))) {
            return false;
        }
        if ($timeOutTz->lessThan($scheduledEnd->copy()->subMinutes($toleranceMinutes))) {
            return false;
        }

        $coveredNetMinutes = AttendanceStatusService::getScheduleClippedNetWorkedMinutes(
            $timeInTz,
            $timeOutTz,
            $daySchedule,
            $dateKey,
            $tz
        );

        return $coveredNetMinutes >= max(0, $requiredMinutes - $toleranceMinutes);
    }

    /**
     * Sum OT request hours for the workday. Matches primary payroll date first; if none, matches the
     * previous calendar day ONLY when the clock-in is actually from a different calendar day
     * (true overnight/graveyard shift where OT was filed on the prior date).
     */
    private function sumOvertimeHoursForWorkday(User $user, string $dateKey, Carbon $timeInTz, string $status): float
    {
        $sumForDate = function (string $d) use ($user, $status): float {
            $cacheKey = ((int) $user->id).'|'.$d.'|'.$status;
            if (array_key_exists($cacheKey, $this->overtimeHoursCache)) {
                return $this->overtimeHoursCache[$cacheKey];
            }

            return $this->overtimeHoursCache[$cacheKey] = (float) Overtime::query()
                ->where('user_id', $user->id)
                ->where('status', $status)
                ->whereDate('date', $d)
                ->sum('computed_hours');
        };

        $primary = $sumForDate($dateKey);
        if ($primary > 0.0001) {
            return $primary;
        }

        $clockInDate = $timeInTz->toDateString();
        if ($clockInDate !== $dateKey && (int) $timeInTz->format('G') < 12) {
            $prevDay = Carbon::parse($dateKey, $timeInTz->timezone)->subDay()->toDateString();

            return $sumForDate($prevDay);
        }

        return 0.0;
    }

    /**
     * True if any Overtime module row exists for this workday (any status).
     * Uses the same date fallback as {@see sumOvertimeHoursForWorkday}: previous calendar day
     * only when the clock-in is from a different calendar day (true overnight shift).
     */
    private function hasOvertimeRequestForWorkday(User $user, string $dateKey, Carbon $timeInTz): bool
    {
        $existsOn = function (string $d) use ($user): bool {
            $cacheKey = ((int) $user->id).'|'.$d;
            if (array_key_exists($cacheKey, $this->overtimeExistsCache)) {
                return $this->overtimeExistsCache[$cacheKey];
            }

            return $this->overtimeExistsCache[$cacheKey] = Overtime::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $d)
                ->exists();
        };

        if ($existsOn($dateKey)) {
            return true;
        }

        $clockInDate = $timeInTz->toDateString();
        if ($clockInDate !== $dateKey && (int) $timeInTz->format('G') < 12) {
            $prevDay = Carbon::parse($dateKey, $timeInTz->timezone)->subDay()->toDateString();

            return $existsOn($prevDay);
        }

        return false;
    }

    private function buildDayResult(
        string $dateKey,
        bool $isRestDay,
        ?array $holiday,
        string $status,
        array $conditions,
        array $breakdown,
        float $totalPay,
        int $workedMinutes,
        int $regularDayMinutes,
        int $regularNightMinutes,
        int $otDayMinutes,
        int $otNightMinutes,
        int $requiredMinutes,
        int $lateDeductionMinutes,
        int $undertimeDeductionMinutes
    ): array {
        return [
            'date' => $dateKey,
            'is_rest_day' => $isRestDay,
            'holiday' => $holiday,
            'status' => $status,
            'conditions' => $conditions,
            'breakdown' => $breakdown,
            'total_pay' => $totalPay,
            'worked_minutes' => $workedMinutes,
            'required_minutes' => $requiredMinutes,
            'regular_day_minutes' => $regularDayMinutes,
            'regular_night_minutes' => $regularNightMinutes,
            'ot_day_minutes' => $otDayMinutes,
            'ot_night_minutes' => $otNightMinutes,
            'late_deduction_minutes' => $lateDeductionMinutes,
            'undertime_deduction_minutes' => $undertimeDeductionMinutes,
        ];
    }

    private function resolveDayStatus(User $user, string $dateKey, ?array $daySchedule): string
    {
        $cacheKey = ((int) $user->id).'|'.$dateKey.'|'.($daySchedule && ! empty($daySchedule['in']) ? 'scheduled' : 'unscheduled');
        if (array_key_exists($cacheKey, $this->dayStatusCache)) {
            return $this->dayStatusCache[$cacheKey];
        }

        $hasLeave = $this->approvedPrimaryLeaveForDate($user, $dateKey) !== null;
        if ($hasLeave) {
            return $this->dayStatusCache[$cacheKey] = 'leave';
        }
        if (! $daySchedule || empty($daySchedule['in'])) {
            return $this->dayStatusCache[$cacheKey] = 'rest_or_unscheduled';
        }

        return $this->dayStatusCache[$cacheKey] = 'absent';
    }

    private function approvedPrimaryLeaveForDate(User $user, string $dateKey): ?LeaveRequest
    {
        $cacheKey = ((int) $user->id).'|'.$dateKey;
        if (array_key_exists($cacheKey, $this->approvedLeaveForDateCache)) {
            return $this->approvedLeaveForDateCache[$cacheKey];
        }

        return $this->approvedLeaveForDateCache[$cacheKey] = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Paid leave (credit-consuming types) when there is no attendance punch: taxable basic pay for the day or half-day.
     *
     * @return array{total_pay: float, breakdown: array<int, array<string, mixed>>, worked_minutes: int, regular_day_minutes: int, regular_night_minutes: int}
     */
    private function computePaidLeaveCompensationForDay(
        ?array $daySchedule,
        LeaveRequest $leave,
        float $dailyRate,
        float $hourlyRate,
        float $first8,
        int $requiredMinutes,
        ?array $holiday = null
    ): array {
        $type = strtolower((string) $leave->type);
        $factor = $type === 'half_day' ? 0.5 : 1.0;
        $isHolidayDay = $holiday !== null && $first8 > 1.00001;

        if ($daySchedule && $requiredMinutes > 0) {
            $paidMin = max(0, (int) round($requiredMinutes * $factor));
            $first8Pay = ($paidMin / 60.0) * $hourlyRate * $first8;
            // On holidays: full day pay → holiday_premium; paid_leave base = 0.
            $holidayPremiumPay = $isHolidayDay ? round($first8Pay, 2) : 0.0;
            $leaveBaseAmount = $isHolidayDay ? 0.0 : round($first8Pay, 2);

            $breakdown = [[
                'component' => 'paid_leave',
                'minutes' => $isHolidayDay ? 0 : $paidMin,
                'rate' => $hourlyRate,
                'multiplier' => 1.0,
                'amount' => $leaveBaseAmount,
                'taxable_compensation' => true,
                'leave_type' => $leave->type,
            ]];
            if ($isHolidayDay) {
                $breakdown[] = [
                    'component' => 'holiday_premium',
                    'minutes' => $paidMin,
                    'rate' => $hourlyRate,
                    'multiplier' => $first8,
                    'premium_multiplier' => round($first8, 2),
                    'holiday_name' => $holiday['name'] ?? null,
                    'holiday_type' => $holiday['type'] ?? null,
                    'amount' => round($holidayPremiumPay, 2),
                ];
            }

            return [
                'total_pay' => round($first8Pay, 2),
                'breakdown' => $breakdown,
                'worked_minutes' => $paidMin,
                'regular_day_minutes' => $paidMin,
                'regular_night_minutes' => 0,
                'holiday_premium_pay' => round($holidayPremiumPay, 2),
            ];
        }

        $amount = round($dailyRate * $factor, 2);
        $approxMin = (int) round(480 * $factor);
        // On holidays: full flat-rate day pay → holiday_premium; leave base = 0.
        $holidayPremiumPayFlat = $isHolidayDay ? $amount : 0.0;
        $leaveBaseFlat = $isHolidayDay ? 0.0 : $amount;

        $breakdown = [[
            'component' => 'paid_leave_daily_flat',
            'day_fraction' => $factor,
            'daily_rate' => $dailyRate,
            'amount' => $leaveBaseFlat,
            'taxable_compensation' => true,
            'leave_type' => $leave->type,
        ]];
        if ($isHolidayDay) {
            $breakdown[] = [
                'component' => 'holiday_premium',
                'minutes' => $approxMin,
                'rate' => $hourlyRate,
                'multiplier' => $first8,
                'premium_multiplier' => round($first8, 2),
                'holiday_name' => $holiday['name'] ?? null,
                'holiday_type' => $holiday['type'] ?? null,
                'amount' => round($holidayPremiumPayFlat, 2),
            ];
        }

        return [
            'total_pay' => $amount,
            'breakdown' => $breakdown,
            'worked_minutes' => $approxMin,
            'regular_day_minutes' => $approxMin,
            'regular_night_minutes' => 0,
            'holiday_premium_pay' => round($holidayPremiumPayFlat, 2),
        ];
    }

    /**
     * Get time in/out for a user and date (logs + corrections).
     */
    public function getTimesForDate(User $user, string $dateKey, string $tz): array
    {
        return $this->attendanceSession->getTimesForDate($user, $dateKey, $tz);
    }

    /**
     * Scheduled shift window for a calendar date (matches Admin → Shifts / schedule JSON).
     */
    public function scheduleLabelForDate(?array $effectiveSchedule, string $dateKey, string $tz): ?string
    {
        if (! $effectiveSchedule) {
            return null;
        }
        $date = Carbon::parse($dateKey, $tz);
        $dayKey = $this->dayKeyForDate($date);
        $day = $effectiveSchedule[$dayKey] ?? null;
        if ($day === null || ! is_array($day) || empty($day['in'])) {
            return 'Rest day';
        }
        $inRaw = $day['in'] ?? '';
        $outRaw = $day['out'] ?? '';
        $in = is_string($inRaw) ? substr(trim($inRaw), 0, 5) : '';
        $out = is_string($outRaw) ? substr(trim($outRaw), 0, 5) : '';
        if ($in !== '' && $out !== '') {
            return "{$in} – {$out}";
        }

        return $in !== '' ? $in : '—';
    }

    /**
     * Compute full payroll for an employee over a date range (audit-grade breakdown per day).
     *
     * **Payslip integration:** {@see \App\Services\PayslipService} calls this once per period; the returned `summary`
     * is snapshotted on `payslips.snapshot` — no alternate calculation path. Chain: assigned pay components (via
     * {@see PayrollCalculatorService}), schedule + attendance + holidays per day, {@see DeductionScheduleService} for
     * earning/deduction timing, statutory + loan amortization in the calculator snapshot.
     *
     * @return array{
     *   user_id: int,
     *   from_date: string,
     *   to_date: string,
     *   daily_rate: float,
     *   days: array,
     *   summary: array
     * }
     */
    public function computeEmployeePayroll(
        User $user,
        Carbon $from,
        Carbon $to,
        ?float $overrideDailyRate = null,
        array $periodContext = []
    ): array {
        $timingSink = $periodContext['_timing_sink'] ?? null;
        $__segStart = microtime(true);

        $tz = $this->getTimezone();
        $monthlyBaseForRate = $this->resolveMonthlyBaseForDailyRate($user, $to->toDateString());
        [$effectiveSchedule] = $this->resolveEffectiveScheduleForDailyComputation($user);
        $dailyRateDivisorDays = $this->resolveStableScheduleMonthlyDivisor($effectiveSchedule);
        $scheduleMetrics = $this->scheduleRateService->describeForUser(
            $user,
            $monthlyBaseForRate > 0 ? $monthlyBaseForRate : null,
            $to->copy()->startOfDay(),
            $effectiveSchedule
        );
        $scheduledDailyHours = max(0.0, (float) ($scheduleMetrics['working_hours_per_day'] ?? 0));
        // Single source for daily rate across Daily Computation and payroll finalize/payslip:
        // prefer schedule-rate resolver (same path used by daily-computation listings), then payroll fallback.
        $resolvedScheduleDailyRate = $monthlyBaseForRate > 0
            ? $this->scheduleRateService->resolveDailyRate(
                $user,
                $effectiveSchedule,
                null,
                $to->copy()->startOfDay(),
                $monthlyBaseForRate
            )
            : 0.0;
        $dailyRate = $overrideDailyRate
            ?? ($resolvedScheduleDailyRate > 0
                ? $resolvedScheduleDailyRate
                : $this->resolvePayrollDailyRateForPeriod(
                    $user,
                    $from,
                    $to,
                    $monthlyBaseForRate,
                    $effectiveSchedule
                ));
        if ($dailyRate <= 0) {
            if (is_object($timingSink)) {
                $timingSink->load_schedules_ms = ($timingSink->load_schedules_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
            }

            return [
                'user_id' => $user->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'daily_rate' => $dailyRate,
                'days' => [],
                'summary' => [
                    'total_pay' => 0,
                    'employee_statutory_total' => 0,
                    'employer_statutory_total' => 0,
                    'net_pay' => 0,
                    'statutory_breakdown' => null,
                    'total_worked_minutes' => 0,
                    'total_regular_day_minutes' => 0,
                    'total_regular_night_minutes' => 0,
                    'total_ot_day_minutes' => 0,
                    'total_ot_night_minutes' => 0,
                    'attendance_proration' => [
                        'factor' => 1.0,
                        'scheduled_workdays' => 0.0,
                        'credited_day_units' => 0.0,
                    ],
                ],
            ];
        }

        if (is_object($timingSink)) {
            $timingSink->load_schedules_ms = ($timingSink->load_schedules_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
        }
        $__segStart = microtime(true);

        $days = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $dateKey = $cursor->toDateString();
            [$timeIn, $timeOut] = $this->getTimesForDate($user, $dateKey, $tz);
            $dayPayroll = $this->computeDayPayroll(
                $user,
                $dateKey,
                $timeIn,
                $timeOut,
                $effectiveSchedule,
                $dailyRate,
                $tz
            );
            $allowanceAttendance = $this->resolveAllowanceDayForProration($user, $dateKey, $timeIn, $timeOut, $dayPayroll, $tz);
            $dayPayroll['allowance_attendance_valid'] = $allowanceAttendance['valid'];
            $dayPayroll['allowance_attendance_reason'] = $allowanceAttendance['reason'];
            $dayPayroll['allowance_attendance_sources'] = $allowanceAttendance['sources'];
            $dayPayroll['allowance_proration_day'] = $allowanceAttendance;
            $days[] = $dayPayroll;
            $cursor->addDay();
        }

        if (is_object($timingSink)) {
            $timingSink->daily_iteration_ms = ($timingSink->daily_iteration_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
        }
        $__segStart = microtime(true);

        $totalPay = 0.0;
        $basicPayThisPeriod = 0.0;
        $attendancePremiumPayThisPeriod = 0.0;
        $actualWorkedDayUnits = 0.0;
        $totalWorkedMinutes = 0;
        $totalRegularDay = 0;
        $totalRegularNight = 0;
        $totalOtDay = 0;
        $totalOtNight = 0;
        $dailyBreakdownTotals = [];
        $dailyBreakdownMinutes = [];
        $dailyBreakdownDays = [];
        foreach ($days as $d) {
            $dayTotalPay = (float) ($d['total_pay'] ?? 0);
            $regularPaidMinutes = (int) (($d['regular_day_minutes'] ?? 0) + ($d['regular_night_minutes'] ?? 0));
            $requiredMinutes = (int) ($d['required_minutes'] ?? 0);
            // Keep "Regular pay" strictly attendance-driven and schedule-aware:
            // count only worked days that are NOT schedule rest days.
            // Rest-day work (RD), leave credits, and holiday-only premium days must not inflate
            // ordinary regular-pay day counts in payslip preview/generate/finalize.
            $dayStatus = strtolower(trim((string) ($d['status'] ?? '')));
            $dayIsRest = (bool) ($d['is_rest_day'] ?? false);
            $dayBreakdown = (array) ($d['breakdown'] ?? []);
            $regularBasePay = collect($dayBreakdown)
                ->filter(function ($entry) use ($dayStatus, $dayIsRest): bool {
                    if ($dayStatus !== 'worked' || $dayIsRest) {
                        return false;
                    }

                    return strtolower(trim((string) ($entry['component'] ?? ''))) === 'regular_pay';
                })
                ->sum(function ($entry): float {
                    return (float) ($entry['amount'] ?? 0);
                });
            $regularBasePay = round(max(0.0, $regularBasePay), 2);

            // Undertime is deducted by paying only actual worked regular minutes.
            // Do not subtract undertime again here: a 102-minute workday on an 8-hour schedule
            // should pay 102 minutes, while the 378-minute shortfall remains audit metadata.
            $netRegularBasePay = $regularBasePay;

            $dayPremium = round(max(0.0, $dayTotalPay - $netRegularBasePay), 2);

            $totalPay += $dayTotalPay;
            $basicPayThisPeriod += $netRegularBasePay;
            $attendancePremiumPayThisPeriod += $dayPremium;
            $totalWorkedMinutes += $d['worked_minutes'];
            $totalRegularDay += $d['regular_day_minutes'];
            $totalRegularNight += $d['regular_night_minutes'];
            $totalOtDay += $d['ot_day_minutes'];
            $totalOtNight += $d['ot_night_minutes'];
            if ($requiredMinutes > 0 && $dayStatus === 'worked' && ! $dayIsRest) {
                $actualWorkedDayUnits += min(1.0, $regularPaidMinutes / $requiredMinutes);
            }
            foreach ($dayBreakdown as $entry) {
                $component = strtolower(trim((string) ($entry['component'] ?? '')));
                $amount = (float) ($entry['amount'] ?? 0);
                if ($component === '' || $amount <= 0) {
                    continue;
                }
                // Regular-pay line is ordinary worked non-rest-day only.
                if ($component === 'regular_pay' && ($dayStatus !== 'worked' || $dayIsRest)) {
                    continue;
                }
                // Accumulate exact (unrounded) amounts to avoid compounding rounding errors across days.
                // The per-line round(…, 2) happens once below when writing the earning line.
                $dailyBreakdownTotals[$component] = (float) ($dailyBreakdownTotals[$component] ?? 0) + $amount;
                $mins = (int) ($entry['minutes'] ?? 0);
                $dailyBreakdownMinutes[$component] = ($dailyBreakdownMinutes[$component] ?? 0) + $mins;
                if ($mins > 0) {
                    $dailyBreakdownDays[$component] = ($dailyBreakdownDays[$component] ?? 0) + 1;
                }
            }
        }

        // Needed before daily earning lines so "Regular pay" units match regular-rate attendance days
        // (premium holidays excluded — same filter as buildAttendanceDisplaySummary()).
        $attendanceDisplaySummary = $this->buildAttendanceDisplaySummary($days, $effectiveSchedule, $tz);

        $componentLabelMap = [
            // Daily Computation is the source of truth for attendance-driven earning add-ons.
            'regular_pay' => 'Regular pay',
            'ot_pay' => 'Overtime',
            'overtime_premium' => 'Overtime',
            'nd_pay' => 'Night differential',
            'night_diff' => 'Night differential',
            'holiday_premium' => 'Holiday premium',
            'paid_leave' => 'Leave adjustments',
            'paid_leave_daily_flat' => 'Leave adjustments',
            'attendance_correction' => 'Attendance corrections',
            'unpaid_leave' => 'Unpaid leave',
            'undertime_deduction' => 'Undertime',
        ];
        $componentSortRank = [
            'regular_pay' => 5,
            'undertime_deduction' => 6,
            'ot_pay' => 10,
            'overtime_premium' => 10,
            'nd_pay' => 20,
            'night_diff' => 20,
            'holiday_premium' => 30,
            'attendance_correction' => 40,
            'paid_leave' => 50,
            'paid_leave_daily_flat' => 50,
            'unpaid_leave' => 60,
        ];
        // Regular-pay line amount excludes statutory-holiday base when premium was earned (see $dayIsHolidayForBaseSplit
        // above). $actualWorkedDayUnits still counts those days as "present", so using it for the Regular pay *units*
        // mislabels rows (e.g. "5 days" while amount is 4 × daily rate). Derive day-equivalent from amount ÷ daily rate.
        $dailyComputationEarningLines = collect($dailyBreakdownTotals)
            ->map(function (float $amount, string $component) use ($componentLabelMap, $componentSortRank, $dailyBreakdownMinutes, $dailyBreakdownDays, $dailyRate, $attendanceDisplaySummary): array {
                $mins = (int) ($dailyBreakdownMinutes[$component] ?? 0);
                $dayCount = (int) ($dailyBreakdownDays[$component] ?? 0);

                // Minute-based components leave units = null; the single downstream formatter
                // (PayslipService::formatUnitsAndAmount) derives the canonical display string
                // from minutes_worked. Day-count components keep their day-based label.

                if ($component === 'regular_pay') {
                    $units = null;
                    if ($mins <= 0 && $amount > 0 && $dailyRate > 0) {
                        $hourlyRate = $dailyRate / 8.0;
                        $mins = $hourlyRate > 0 ? (int) round(($amount / $hourlyRate) * 60) : 0;
                    }
                } elseif (in_array($component, ['ot_pay', 'overtime_premium', 'nd_pay', 'night_diff'], true)) {
                    $units = null;
                } elseif ($component === 'holiday_premium') {
                    $units = $dayCount > 0 ? $dayCount.' '.($dayCount === 1 ? 'day' : 'days') : null;
                } elseif (in_array($component, ['paid_leave', 'paid_leave_daily_flat'], true)) {
                    $units = $dayCount > 0 ? $dayCount.' '.($dayCount === 1 ? 'day' : 'days') : null;
                } else {
                    $units = null;
                }

                // Hourly-rate source of truth:
                // - Regular pay must use payroll daily rate / 8 (exact business rule), not back-derived
                //   from rounded amounts.
                // - Other minute-based components can use an effective rate derived from minutes + amount.
                $effectiveHourlyRate = null;
                if ($mins > 0) {
                    if ($component === 'regular_pay' && $dailyRate > 0) {
                        // Keep the exact rate from daily rate for regular-pay reconciliation.
                        $effectiveHourlyRate = $dailyRate / 8.0;
                    } else {
                        $effectiveHourlyRate = ((float) $amount * 60.0) / (float) $mins;
                    }
                }

                // Canonical amount: recompute from exact minutes × effective rate, rounded once.
                // This eliminates drift from summing individually-rounded per-day amounts.
                $canonicalAmount = ($effectiveHourlyRate !== null && $mins > 0)
                    ? round(($mins / 60.0) * $effectiveHourlyRate, 2)
                    : round($amount, 2);

                return [
                    'key' => 'daily:'.$component,
                    'label' => $componentLabelMap[$component] ?? Str::headline(str_replace('_', ' ', $component)),
                    'amount' => $canonicalAmount,
                    'units' => $units,
                    'minutes_worked' => $mins,
                    'hourly_rate' => $effectiveHourlyRate,
                    '_sort' => $componentSortRank[$component] ?? 999,
                ];
            })
            ->sortBy('_sort')
            ->map(function (array $line): array {
                unset($line['_sort']);

                return $line;
            })
            ->values()
            ->all();
        $holidayPremiumBreakdown = [];
        $holidayPresenceToleranceMin = 3;
        foreach ($days as $d) {
            $holiday = is_array($d['holiday'] ?? null) ? $d['holiday'] : null;
            if (! $holiday) {
                continue;
            }
            $breakdown = is_array($d['breakdown'] ?? null) ? $d['breakdown'] : [];
            $holidayPremiumAmountFromBreakdown = collect($breakdown)
                ->filter(fn ($entry) => strtolower(trim((string) ($entry['component'] ?? ''))) === 'holiday_premium')
                ->sum(fn ($entry) => (float) ($entry['amount'] ?? 0));
            $hasPaidLeave = collect($breakdown)->contains(function ($entry) {
                $component = strtolower(trim((string) ($entry['component'] ?? '')));

                return $component === 'paid_leave' || $component === 'paid_leave_daily_flat';
            });
            $regularMinutes = (int) (($d['regular_day_minutes'] ?? 0) + ($d['regular_night_minutes'] ?? 0));
            $hours = round($regularMinutes / 60, 2);
            $multiplier = round((float) (($d['conditions']['first_8'] ?? 1.0)), 2);
            $amount = round(max(0.0, (float) $holidayPremiumAmountFromBreakdown), 2);
            $status = strtolower(trim((string) ($d['status'] ?? '')));
            $requiredForDay = (int) ($d['required_minutes'] ?? 0);
            // Same gate as computeDayPayroll worked path + paid-leave path: premium only for full scheduled
            // presence (within tolerance) or approved paid leave credit on that date.
            $fullScheduledPresence = $requiredForDay <= 0
                ? $regularMinutes > 0
                : $regularMinutes >= max(0, $requiredForDay - $holidayPresenceToleranceMin);
            $eligible = ($status === 'worked' && $fullScheduledPresence) || ($status === 'leave' && $hasPaidLeave);
            $ruleCodeForDay = (string) ($d['conditions']['rule_code'] ?? '');
            $holidayPremiumBreakdown[] = [
                'date' => (string) ($d['date'] ?? ''),
                'holiday_name' => (string) ($holiday['name'] ?? 'Holiday'),
                'holiday_type' => (string) ($holiday['type'] ?? ''),
                'rule_code' => $ruleCodeForDay,
                'multiplier' => $multiplier,
                'hours' => $hours,
                'attendance_status' => $status,
                'eligible' => $eligible,
                'amount' => $eligible ? $amount : 0.0,
            ];
        }

        $attendanceProration = $this->computeScheduleAttendanceProrationForPeriod(
            $days,
            $dailyRateDivisorDays,
            $scheduledDailyHours > 0.0 ? $scheduledDailyHours : 8.0
        );

        if (is_object($timingSink)) {
            $timingSink->compute_loop_ms = ($timingSink->compute_loop_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
        }
        $__segStart = microtime(true);

        $compensationSummary = $this->payrollCalculator->buildEmployeeCompensationSummary($user, [
            'as_of_date' => $to->toDateString(),
            'proration_factor' => 1,
            'include_deduction_schedule_catalog' => false,
            'cache' => true,
        ]);
        $basicSalary = (float) ($compensationSummary['basic_salary'] ?? 0);
        $statutory = $compensationSummary['statutory'] ?? $this->payrollCalculator->calculateAllStatutoryContributions($basicSalary);
        $employeeStatutoryFullMonthly = (float) ($statutory['totals']['employee_deduction'] ?? 0);
        $employerStatutoryTotal = (float) ($statutory['totals']['employer_liability'] ?? 0);
        $customDeductionsFullMonthly = (float) ($compensationSummary['totals']['custom_deductions'] ?? 0);

        $taxClassification = is_array($compensationSummary['tax_classification'] ?? null)
            ? $compensationSummary['tax_classification']
            : [];
        // Keep payslip preview/generate/finalize in sync with Compliance Audit:
        // monthly withholding uses BASIC salary gross, then subtract EE mandatory contributions first.
        $grossTaxableMonthly = round(max(0.0, $basicSalary), 2);
        if ($grossTaxableMonthly <= 0.0) {
            $grossTaxableMonthly = (float) ($taxClassification['taxable_total'] ?? 0);
        }
        $monthlyBaseNetOfMandatory = $this->payrollCalculator->monthlyTaxableCompensationForWithholding(
            $grossTaxableMonthly,
            $statutory
        );
        // Single source of truth with Compliance Audit / Government Deductions: same call chain as
        // {@see PayrollCalculatorService::buildEmployeeCompensationSummary}. Do not use cached
        // `compensationSummary['withholding']` here — file-cached compensation can show ₱313.80 in audit while
        // payslip used a stale withholding object (e.g. ₱383.xx). Always merge tax profile + recompute.
        $withholdingPreview = $this->payrollCalculator->calculateWithholdingTax(
            $this->payrollCalculator->mergeEmployeeTaxProfileIntoWithholdingParams($user, [
                'monthly_taxable_compensation' => $monthlyBaseNetOfMandatory,
                'withholding_base_is_net_of_mandatory' => true,
                'withholding_gross_taxable_monthly' => $grossTaxableMonthly,
                'withholding_employee_mandatory_monthly' => (float) data_get($statutory, 'totals.employee_deduction', 0),
                'method' => 'annualized',
                'period_type' => 'monthly',
            ])
        );
        $withholdingMonthlyFull = (float) ($withholdingPreview['withholding_per_month'] ?? 0);

        $refForSchedule = $to->copy()->timezone($tz)->startOfDay();
        $selectedPayDate = ! empty($periodContext['selected_pay_date'])
            ? Carbon::parse((string) $periodContext['selected_pay_date'], $tz)->startOfDay()
            : null;
        $periodStartDate = $periodContext['pay_period_start'] ?? $from->toDateString();
        $periodEndDate = $periodContext['pay_period_end'] ?? $to->toDateString();
        $companyId = $user->getEffectiveCompanyId();

        // Resolve BASIC SALARY schedule metadata (15th/30th/Both) for snapshot/reporting only.
        // Payroll amount itself remains attendance-derived from daily computation.
        $basicScheduleType = DeductionScheduleSetting::SCHEDULE_BOTH;
        $basicLine = collect($compensationSummary['earnings'] ?? [])->first(function ($line) {
            return strtoupper(trim((string) ($line['code'] ?? ''))) === 'BASIC_SALARY';
        });
        $basicPayComponentId = $basicLine['pay_component_id'] ?? null;
        $basicAssignmentId = isset($basicLine['id']) && is_numeric($basicLine['id']) ? (int) $basicLine['id'] : null;
        if ($basicPayComponentId) {
            $basicScheduleType = $this->deductionScheduleService->resolveScheduleType(
                'pay_component:'.((int) $basicPayComponentId),
                $companyId,
                (int) $user->id,
                (int) $basicPayComponentId,
                $basicAssignmentId,
            );
        }
        $basicFactor = $this->deductionScheduleService->factorForScheduleInPeriod(
            $user,
            $basicScheduleType,
            $refForSchedule,
            $selectedPayDate,
            $periodStartDate,
            $periodEndDate
        );
        // Keep schedule metadata for traceability, but DO NOT override attendance-derived regular pay.
        // Basic pay for payroll/payslip must come from daily computation (worked minutes, leave, holidays, OT context).
        // Overriding here with prorated monthly basic causes inaccurate finalize/preview totals.

        if (is_object($timingSink)) {
            $timingSink->load_pay_components_ms = ($timingSink->load_pay_components_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
        }
        $__segStart = microtime(true);

        $deductionSchedule = $this->deductionScheduleService->summarizeForPayrollComputation(
            $user,
            $refForSchedule,
            array_merge($compensationSummary, [
                '_attendance_proration' => $attendanceProration,
                'totals' => array_merge($compensationSummary['totals'] ?? [], [
                    'withholding_tax' => $withholdingMonthlyFull,
                ]),
                'withholding' => array_merge($compensationSummary['withholding'] ?? [], [
                    'withholding_per_month' => $withholdingMonthlyFull,
                ]),
                'pay_period_start' => $periodStartDate,
                'pay_period_end' => $periodEndDate,
                'selected_pay_date' => $selectedPayDate?->toDateString(),
            ])
        );

        $employeeStatutoryThisPeriod = (float) ($deductionSchedule['employee_statutory_this_period'] ?? $employeeStatutoryFullMonthly);
        $withholdingThisPeriod = (float) ($deductionSchedule['withholding_this_period'] ?? $withholdingMonthlyFull);

        $grossEarnings = (float) ($compensationSummary['totals']['gross_earnings'] ?? 0);
        $nonBasicEarningsThisPeriod = array_key_exists('non_basic_earnings_this_period', $deductionSchedule)
            ? (float) $deductionSchedule['non_basic_earnings_this_period']
            : max(0.0, round($grossEarnings - $basicSalary, 2));
        $grossThisPeriod = round($basicPayThisPeriod + $attendancePremiumPayThisPeriod + $nonBasicEarningsThisPeriod, 2);

        // Phase 3 compliance: enforce custom deduction priority + legal minimum take-home + garnishment caps.
        $phase3Deduction = $this->deductionApplicationService->enforcePriorityAndLegalLimitsForPayrollPeriod(
            $user,
            is_array($deductionSchedule['custom_lines'] ?? null) ? $deductionSchedule['custom_lines'] : [],
            $grossThisPeriod,
            $employeeStatutoryThisPeriod,
            $withholdingThisPeriod,
            $from,
            $to,
            $actualWorkedDayUnits > 0 ? $actualWorkedDayUnits : null
        );
        $deductionSchedule['custom_lines'] = $phase3Deduction['custom_lines'];
        $deductionSchedule['custom_deductions_this_period'] = $phase3Deduction['custom_deductions_this_period'];
        $deductionSchedule['legal_warnings'] = $phase3Deduction['legal_warnings'];
        $deductionSchedule['minimum_take_home_floor'] = $phase3Deduction['minimum_take_home_floor'];
        $customDeductionsThisPeriod = (float) $phase3Deduction['custom_deductions_this_period'];

        // Statutory (SSS/PhilHealth/Pag-IBIG) before loan/custom deductions — aligns with {@see PayrollCalculatorService::buildEmployeeCompensationSummary} net ordering.
        $netPay = max(0, round(
            ($basicPayThisPeriod + $attendancePremiumPayThisPeriod) + $nonBasicEarningsThisPeriod - $employeeStatutoryThisPeriod - $customDeductionsThisPeriod,
            2
        ));
        $netPayAfterWithholding = max(0, round($netPay - $withholdingThisPeriod, 2));

        if (is_object($timingSink)) {
            $timingSink->load_deductions_ms = ($timingSink->load_deductions_ms ?? 0.0) + (microtime(true) - $__segStart) * 1000;
        }

        return [
            'user_id' => $user->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'daily_rate' => $dailyRate,
            'daily_rate_divisor_days' => $dailyRateDivisorDays,
            'basic_salary_used' => round($basicSalary, 2),
            'days' => $days,
            'summary' => [
                'total_pay' => round($basicPayThisPeriod + $attendancePremiumPayThisPeriod, 2),
                'basic_pay_this_period' => round($basicPayThisPeriod, 2),
                'basic_salary_schedule_type' => $basicScheduleType,
                'basic_salary_schedule_factor' => round($basicFactor, 4),
                'attendance_premium_pay_this_period' => round($attendancePremiumPayThisPeriod, 2),
                'gross_pay_this_period' => round($grossThisPeriod, 2),
                'actual_days_worked' => round($actualWorkedDayUnits, 2),
                'daily_rate' => round($dailyRate, 2),
                'employee_statutory_total' => round($employeeStatutoryFullMonthly, 2),
                'employee_statutory_this_period' => round($employeeStatutoryThisPeriod, 2),
                'employer_statutory_total' => round($employerStatutoryTotal, 2),
                'custom_deductions_full_monthly' => round($customDeductionsFullMonthly, 2),
                'custom_deductions_this_period' => round($customDeductionsThisPeriod, 2),
                'net_pay' => $netPay,
                'withholding_tax_monthly_estimate' => round($withholdingMonthlyFull, 2),
                'withholding_tax_this_period_estimate' => round($withholdingThisPeriod, 2),
                'withholding_breakdown' => $withholdingPreview,
                'net_pay_after_withholding_estimate' => $netPayAfterWithholding,
                'statutory_breakdown' => $statutory,
                'compensation_breakdown' => $compensationSummary,
                'deduction_schedule' => $deductionSchedule,
                'legal_warnings' => $phase3Deduction['legal_warnings'],
                'minimum_take_home_floor' => $phase3Deduction['minimum_take_home_floor'],
                // Phase 3 integration hook: final-pay / clearance views can recover outstanding balances from this snapshot.
                'outstanding_loans_for_final_pay' => $this->loanAmortizationService->outstandingLoanSummary($user),
                'non_basic_earnings_this_period' => round($nonBasicEarningsThisPeriod, 2),
                'payslip_deduction_lines' => $this->deductionScheduleService->buildPayslipDeductionDisplayLines(
                    $deductionSchedule['government'] ?? [],
                    $withholdingMonthlyFull
                ),
                'payslip_custom_deduction_lines' => $this->deductionScheduleService->buildPayslipCustomDeductionDisplayLines(
                    $deductionSchedule['custom_lines'] ?? []
                ),
                'payslip_earning_lines' => $this->deductionScheduleService->buildPayslipEarningDisplayLines(
                    $deductionSchedule['earning_lines'] ?? []
                ),
                'daily_computation_earning_lines' => $dailyComputationEarningLines,
                'attendance_display_summary' => $attendanceDisplaySummary,
                'holiday_premium_breakdown' => $holidayPremiumBreakdown,
                'total_worked_minutes' => $totalWorkedMinutes,
                'total_regular_day_minutes' => $totalRegularDay,
                'total_regular_night_minutes' => $totalRegularNight,
                'total_ot_day_minutes' => $totalOtDay,
                'total_ot_night_minutes' => $totalOtNight,
                'daily_rate_divisor_days' => $dailyRateDivisorDays,
                'attendance_proration' => $deductionSchedule['attendance_proration'] ?? $attendanceProration,
            ],
        ];
    }

    /**
     * For each scheduled workday in the pay period (non-rest with required minutes > 0), credit
     * min(1, paid_regular_minutes / required_minutes). Paid leave / undertime are already reflected
     * in regular_day/regular_night minutes from {@see computeDayPayroll()}.
     *
     * Used only when a pay component has is_proratable — others keep full semi-monthly schedule amounts.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @return array{factor: float, scheduled_workdays: float, credited_day_units: float, allowance: array<string, mixed>}
     */
    private function computeScheduleAttendanceProrationForPeriod(
        array $days,
        int $monthlyDivisorDays,
        float $scheduledDailyHours
    ): array
    {
        $scheduled = 0.0;
        $credited = 0.0;
        $payableDays = 0.0;
        $unpaidAbsentDays = 0.0;
        $nonDeductibleDays = 0.0;
        $presentDays = 0.0;
        $approvedPaidLeaveDays = 0.0;
        $approvedCorrectionDays = 0.0;
        $attendanceCounted = [];
        $attendanceExcluded = [];
        $unpaidAbsences = [];

        foreach ($days as $d) {
            $dateKey = (string) ($d['date'] ?? '');
            $resolution = is_array($d['allowance_proration_day'] ?? null) ? $d['allowance_proration_day'] : null;
            $isRest = (bool) ($d['is_rest_day'] ?? false);
            $isHoliday = is_array($d['holiday'] ?? null);
            $required = (int) ($d['required_minutes'] ?? 0);
            if ($resolution !== null && ! (bool) ($resolution['scheduled_deductible_day'] ?? false)) {
                if ($dateKey !== '') {
                    $nonDeductibleDays += 1.0;
                    $attendanceExcluded[] = [
                        'date' => $dateKey,
                        'status' => (string) ($d['status'] ?? ''),
                        'reason' => (string) ($resolution['reason'] ?? 'non_deductible_day'),
                        'sources' => $resolution['sources'] ?? [],
                    ];
                }
                continue;
            }
            if ($resolution === null && ($isRest || $isHoliday || $required <= 0)) {
                if ($dateKey !== '') {
                    $nonDeductibleDays += 1.0;
                    $attendanceExcluded[] = [
                        'date' => $dateKey,
                        'status' => (string) ($d['status'] ?? ''),
                        'reason' => $isRest ? 'rest_day' : ($isHoliday ? 'holiday' : 'no_required_minutes'),
                    ];
                }
                continue;
            }
            $scheduled += 1.0;

            $status = strtolower(trim((string) ($d['status'] ?? '')));
            $isUnpaidAbsent = $resolution !== null
                ? (bool) ($resolution['unpaid_absent_day'] ?? false)
                : ! (bool) ($d['allowance_attendance_valid'] ?? false);
            if ($isUnpaidAbsent) {
                $unpaidAbsentDays += 1.0;
                if ($dateKey !== '') {
                    $row = [
                        'date' => $dateKey,
                        'status' => $status,
                        'required_minutes' => $required,
                        'reason' => $resolution !== null
                            ? (string) ($resolution['reason'] ?? 'unpaid_absence')
                            : (string) ($d['allowance_attendance_reason'] ?? 'unpaid_absence'),
                        'sources' => $resolution['sources'] ?? ($d['allowance_attendance_sources'] ?? []),
                    ];
                    $attendanceExcluded[] = $row;
                    $unpaidAbsences[] = $row;
                }
                continue;
            }

            $credited += 1.0;
            $payableDays += 1.0;
            $reason = $resolution !== null ? (string) ($resolution['reason'] ?? 'payable_day') : 'payable_day';
            $sources = $resolution['sources'] ?? [];
            if ($reason === 'approved_paid_leave') {
                $approvedPaidLeaveDays += 1.0;
            } elseif ($reason === 'approved_attendance_correction' || (bool) ($sources['approved_correction'] ?? false)) {
                $approvedCorrectionDays += 1.0;
            } else {
                $presentDays += 1.0;
            }
            $attendanceCounted[] = [
                'date' => $dateKey,
                'status' => $status,
                'required_minutes' => $required,
                'payable_day_unit' => 1.0,
                'reason' => $reason,
                'sources' => $sources,
            ];
        }
        $factor = $scheduled > 0.0 ? max(0.0, min(1.0, $credited / $scheduled)) : 1.0;
        $monthlyDivisorDays = max(1, $monthlyDivisorDays);

        return [
            'factor' => round($factor, 6),
            'scheduled_workdays' => round($scheduled, 4),
            'credited_day_units' => round($credited, 4),
            'unpaid_absent_days' => round($unpaidAbsentDays, 4),
            'payable_day_units' => round($payableDays, 4),
            'non_deductible_days' => round($nonDeductibleDays, 4),
            'allowance' => [
                'worked_day_units' => round($payableDays, 6),
                'payable_day_units' => round($payableDays, 6),
                'present_day_units' => round($presentDays, 6),
                'approved_paid_leave_day_units' => round($approvedPaidLeaveDays, 6),
                'approved_correction_day_units' => round($approvedCorrectionDays, 6),
                'unpaid_absent_days' => round($unpaidAbsentDays, 6),
                'non_deductible_days' => round($nonDeductibleDays, 6),
                'worked_minutes' => 0,
                'converted_hours' => 0.0,
                'daily_hours' => round(max(0.0, $scheduledDailyHours), 4),
                'monthly_divisor_days' => $monthlyDivisorDays,
                'divisor_source' => 'stable_schedule_monthly',
                'proration_basis' => 'unpaid_absent_days',
                'attendance_counted' => $attendanceCounted,
                'attendance_excluded' => $attendanceExcluded,
                'unpaid_absences' => $unpaidAbsences,
            ],
        ];
    }

    /**
     * Centralized payable-day resolver for proratable allowances/components.
     * Present/corrected/paid-leave days are payable; only scheduled workdays with no payable
     * signal are unpaid absences. Hours worked, tardiness, and undertime are deliberately ignored.
     *
     * @return array<string, mixed>
     */
    private function resolveAllowanceDayForProration(
        User $user,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        array $dayPayroll,
        string $tz
    ): array {
        $status = strtolower(trim((string) ($dayPayroll['status'] ?? '')));
        $isRest = (bool) ($dayPayroll['is_rest_day'] ?? false);
        $isHoliday = is_array($dayPayroll['holiday'] ?? null);
        $required = (int) ($dayPayroll['required_minutes'] ?? 0);

        if ($isRest || $isHoliday || $required <= 0) {
            return [
                'valid' => true,
                'scheduled_deductible_day' => false,
                'payable_day' => false,
                'unpaid_absent_day' => false,
                'reason' => $isRest ? 'rest_day' : ($isHoliday ? 'holiday' : 'no_scheduled_workday'),
                'sources' => [
                    'rest_day' => $isRest,
                    'holiday' => $isHoliday,
                    'scheduled_workday' => false,
                ],
            ];
        }

        $attendance = $this->resolveAllowanceAttendanceValidity($user, $dateKey, $timeIn, $timeOut, $tz);
        if ((bool) ($attendance['valid'] ?? false)) {
            return array_merge($attendance, [
                'scheduled_deductible_day' => true,
                'payable_day' => true,
                'unpaid_absent_day' => false,
            ]);
        }

        $leave = $this->approvedPrimaryLeaveForDate($user, $dateKey);
        if ($leave !== null) {
            $leaveCredits = app(LeaveCreditService::class);
            $isPaidLeave = $leaveCredits->consumesCredits((string) $leave->type)
                && $leaveCredits->dateIsPaidLeavePortion($user, $leave, $dateKey);

            return [
                'valid' => $isPaidLeave,
                'scheduled_deductible_day' => true,
                'payable_day' => $isPaidLeave,
                'unpaid_absent_day' => ! $isPaidLeave,
                'reason' => $isPaidLeave ? 'approved_paid_leave' : 'approved_unpaid_leave',
                'sources' => array_merge($attendance['sources'] ?? [], [
                    'approved_leave' => true,
                    'approved_paid_leave' => $isPaidLeave,
                    'approved_unpaid_leave' => ! $isPaidLeave,
                    'leave_type' => (string) $leave->type,
                ]),
            ];
        }

        return [
            'valid' => false,
            'scheduled_deductible_day' => true,
            'payable_day' => false,
            'unpaid_absent_day' => true,
            'reason' => $status === 'absent' ? 'absent_without_leave' : (string) ($attendance['reason'] ?? 'unpaid_absence'),
            'sources' => $attendance['sources'] ?? [],
        ];
    }

    /**
     * Allowance eligibility is stricter than payroll's payable-time fallback:
     * actual attendance must have a valid IN and OUT from raw logs and/or approved correction.
     * Approved OT virtual time-outs may pay OT in payroll, but must not turn an incomplete log
     * into an allowance day.
     *
     * @return array{valid: bool, reason: string, sources: array<string, bool>}
     */
    private function resolveAllowanceAttendanceValidity(
        User $user,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        string $tz
    ): array {
        if ($this->attendanceSession->isBulkPayrollMode()) {
            return $this->attendanceSession->allowanceAttendanceValidityForPayroll($user, $dateKey, $tz);
        }

        $sources = [
            'raw_clock_in' => false,
            'raw_clock_out' => false,
            'approved_correction' => false,
            'approved_correction_time_in' => false,
            'approved_correction_time_out' => false,
        ];

        $correction = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();

        if ($correction) {
            $sources['approved_correction'] = true;
            $sources['approved_correction_time_in'] = $correction->time_in !== null;
            $sources['approved_correction_time_out'] = $correction->time_out !== null;
        }

        $dayStartUtc = Carbon::parse($dateKey, $tz)->startOfDay()->setTimezone('UTC');
        $dayEndUtc = Carbon::parse($dateKey, $tz)->endOfDay()->setTimezone('UTC');
        $sources['raw_clock_in'] = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();

        $sources['raw_clock_out'] = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        $hasValidInOut = ($timeIn !== null && $timeOut !== null)
            && (($sources['raw_clock_in'] || $sources['approved_correction_time_in'])
                && ($sources['raw_clock_out'] || $sources['approved_correction_time_out']));
        $valid = $hasValidInOut || $sources['approved_correction'];

        return [
            'valid' => $valid,
            'reason' => $valid
                ? ($sources['approved_correction'] ? 'approved_attendance_correction' : 'valid_attendance_session')
                : 'missing_attendance_or_approved_correction',
            'sources' => $sources,
        ];
    }

    /**
     * Monthly basic to daily rate for payroll. Uses a stable schedule divisor:
     * 5 days/week => 22, 6 days/week => 26.
     *
     * @param  Carbon  $from  Retained for call-site compatibility.
     */
    private function resolvePayrollDailyRateForPeriod(
        User $user,
        Carbon $from,
        Carbon $to,
        float $monthlyBaseForRate,
        ?array $effectiveSchedule
    ): float {
        $reference = $to->copy()->startOfDay();

        if ($monthlyBaseForRate > 0) {
            $divisor = $this->resolveStableScheduleMonthlyDivisor($effectiveSchedule);
            if ($divisor > 0) {
                return round($monthlyBaseForRate / $divisor, 2);
            }
        }

        if ($monthlyBaseForRate <= 0) {
            return 0.0;
        }

        // Fallback when schedule is unresolved.
        return $this->scheduleRateService->resolveDailyRate(
            $user,
            $effectiveSchedule,
            null,
            $reference,
            $monthlyBaseForRate,
        );
    }

    /**
     * Monthly base for daily-rate derivation.
     * The Salary tab is authoritative: an empty/zero monthly salary means no base pay.
     */
    private function resolveMonthlyBaseForDailyRate(User $user, string $asOfDate): float
    {
        $salaryTabBase = (float) ($user->monthly_salary ?? 0);
        if ($salaryTabBase > 0) {
            return round($salaryTabBase, 2);
        }

        return 0.0;
    }

    /**
     * Schedule-based monthly divisor for payroll daily rate.
     * 5 days/week => 22, 6 days/week => 26, else config fallback.
     */
    private function resolveStableScheduleMonthlyDivisor(?array $effectiveSchedule): int
    {
        $workingDaysPerWeek = 0;
        if (is_array($effectiveSchedule) && $effectiveSchedule !== []) {
            foreach (self::DAY_KEYS as $dayKey) {
                $cfg = $effectiveSchedule[$dayKey] ?? null;
                if (! is_array($cfg)) {
                    continue;
                }
                $in = trim((string) ($cfg['in'] ?? ''));
                $out = trim((string) ($cfg['out'] ?? ''));
                if ($in !== '' && $out !== '') {
                    $workingDaysPerWeek++;
                }
            }
        }

        if ($workingDaysPerWeek > 0) {
            return max(1, (int) round(($workingDaysPerWeek * 52) / 12));
        }

        return max(1, (int) config('payroll.working_days_per_month', 22));
    }

    /**
     * True when calendar premium (first_8 > 1) holiday pay is booked as holiday_premium, not 1× regular base.
     * Matches computeEmployeePayroll() $dayIsHolidayForBaseSplit — those dates must not appear as "regular working days"
     * on payslips (minutes still exist in attendance; pay is under Holiday premium).
     */
    private function dayIsPremiumHolidayExcludedFromRegularAttendanceSummary(array $day): bool
    {
        $calendarPremiumHoliday = is_array($day['holiday'] ?? null)
            && ((float) ($day['conditions']['first_8'] ?? 1.0)) > 1.00001;
        $earnedHolidayPremium = round((float) ($day['holiday_premium_pay'] ?? 0), 2) > 0.0001;

        return $calendarPremiumHoliday && $earnedHolidayPremium;
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @param  array<string, mixed>  $effectiveSchedule
     * @return array<string, mixed>
     */
    private function buildAttendanceDisplaySummary(array $days, array $effectiveSchedule, string $tz): array
    {
        $lines = [];
        $presenceDays = 0;
        $totalMinutesRegularRateDays = 0;
        $totalMinutesAllPresence = 0;

        foreach ($days as $day) {
            $dateKey = (string) ($day['date'] ?? '');
            if ($dateKey === '') {
                continue;
            }
            $dayKey = $this->dayKeyForDate(Carbon::parse($dateKey, $tz));
            // Schedule module = single source of truth for rest days.
            $dayCfgForRestCheck = $effectiveSchedule[$dayKey] ?? null;
            $dayIsScheduledRestDay = ($day['is_rest_day'] ?? false) === true || $dayCfgForRestCheck === null;
            $dayHasActualAttendance = (int) ($day['worked_minutes'] ?? 0) > 0;
            if ($dayIsScheduledRestDay && ! $dayHasActualAttendance) {
                continue;
            }
            $status = strtolower(trim((string) ($day['status'] ?? '')));
            // Attendance module source of truth: only actual present/worked days count toward
            // regular-pay day display. Leave may be compensated, but is not a "present day".
            if ($status !== 'worked') {
                continue;
            }
            if ($dayIsScheduledRestDay) {
                // Present on a rest day is treated as premium/rest-day work, not ordinary regular day.
                continue;
            }
            $regularMinutes = (int) (($day['regular_day_minutes'] ?? 0) + ($day['regular_night_minutes'] ?? 0));
            // Only attendance-backed regular minutes.
            if ($regularMinutes <= 0) {
                continue;
            }

            $totalMinutesAllPresence += $regularMinutes;
            $presenceDays++;

            // Premium holiday (worked RH/SH at &gt;1×): compensation is in Holiday premium, not Regular pay — omit from
            // regular-rate day list so counts match {@see daily_computation_earning_lines} "Regular pay".
            if ($this->dayIsPremiumHolidayExcludedFromRegularAttendanceSummary($day)) {
                continue;
            }

            $dayCfg = $effectiveSchedule[$dayKey] ?? null;
            $shift = (is_array($dayCfg) && ! empty($dayCfg['in']) && ! empty($dayCfg['out']))
                ? $this->humanShiftLabel((string) $dayCfg['in'], (string) $dayCfg['out'])
                : 'Worked / paid day';
            $lines[] = [
                'date' => $dateKey,
                'shift' => $shift,
            ];
            $totalMinutesRegularRateDays += $regularMinutes;
        }

        return [
            'working_days_count' => count($lines),
            'presence_days_count' => $presenceDays,
            'lines' => $lines,
            'total_regular_hours' => round($totalMinutesRegularRateDays / 60, 2),
            'total_presence_regular_hours' => round($totalMinutesAllPresence / 60, 2),
        ];
    }

    private function humanShiftLabel(string $in, string $out): string
    {
        $toDisplay = function (string $raw): string {
            $value = trim(substr($raw, 0, 5));
            if ($value === '') {
                return '—';
            }
            [$h, $m] = array_pad(explode(':', $value), 2, '00');
            $hour = (int) $h;
            $minute = str_pad((string) ((int) $m), 2, '0', STR_PAD_LEFT);
            $suffix = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour % 12;
            if ($displayHour === 0) {
                $displayHour = 12;
            }

            return $displayHour.':'.$minute.' '.$suffix;
        };

        return $toDisplay($in).' - '.$toDisplay($out);
    }

    /**
     * Admin Daily Computation: one row per scoped active employee per calendar day in the range.
     * Times and pay use {@see getTimesForDate} (approved attendance corrections override raw logs) and
     * {@see computeDayPayroll} — same chain as payroll.
     *
     * @return array{data: array<int, array>, meta: array<string, mixed>, summary: array<string, mixed>}
     */
    public function dailyComputationLogsForAdmin(
        User $actor,
        Carbon $from,
        Carbon $to,
        ?string $search,
        ?string $statusFilter,
        ?int $companyId,
        int $page,
        int $perPage
    ): array {
        $startedAt = microtime(true);
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $tz = $this->getTimezone();

        $baseEmployeeQuery = User::query()->activeRoster();

        $this->dataScopeService->restrictEmployeeQuery($actor, $baseEmployeeQuery);

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $baseEmployeeQuery->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if ($companyId !== null) {
            $baseEmployeeQuery->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('company_id', $companyId))
                    ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)));
            });
            $baseEmployeeQuery->where(function ($q) use ($companyId) {
                $q->whereDoesntHave('companyHeadships')
                    ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $companyId));
            });
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, User> $scopedEmployees */
        $scopedEmployees = (clone $baseEmployeeQuery)
            ->with(['company', 'branch', 'departmentRelation.branch.company', 'workingSchedule', 'companyHeadships'])
            ->orderByLastName()
            ->get();

        if ($scopedEmployees->isEmpty()) {
            return [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'from_date' => $from->toDateString(),
                    'to_date' => $to->toDateString(),
                    'status_filter' => $statusFilter ?? 'all',
                ],
                'summary' => [
                    'anomaly_count' => 0,
                    'total_ot_hours' => 0.0,
                    'total_nd_hours' => 0.0,
                    'total_rendered_hours' => 0.0,
                    'total_regular_hours' => 0.0,
                    'total_rendered_display' => '00:00',
                    'total_regular_display' => '00:00',
                    'total_nd_display' => '00:00',
                    'unique_employees' => 0,
                    'total_logs' => 0,
                    'ot_basis' => (string) config('payroll.ot_basis', 'schedule_end'),
                ],
            ];
        }

        $grid = $this->buildDailyComputationGrid($scopedEmployees, $from, $to);
        $totalGrid = count($grid);

        $scheduleCache = [];
        $scheduleFallbackCache = [];
        $dailyRateCache = [];

        foreach ($scopedEmployees as $emp) {
            [$sched, $fallback] = $this->resolveEffectiveScheduleForDailyComputation($emp);
            $scheduleCache[$emp->id] = $sched;
            $scheduleFallbackCache[$emp->id] = $fallback;
            $monthlyBase = $this->resolveMonthlyBaseForDailyRate($emp, $to->toDateString());
            $dailyRateCache[$emp->id] = $this->resolvePayrollDailyRateForPeriod($emp, $from, $to, $monthlyBase, $sched);
        }

        $offset = ($page - 1) * $perPage;
        $pageCells = array_slice($grid, $offset, $perPage);

        $usersById = $scopedEmployees->keyBy('id');
        $pageRows = [];

        foreach ($pageCells as $cell) {
            /** @var User|null $user */
            $user = $usersById->get($cell['user_id']);
            if (! $user) {
                continue;
            }
            $dateKey = $cell['date'];
            $sched = $scheduleCache[$user->id] ?? $this->defaultOfficeScheduleFallback();
            $dailyRate = (float) ($dailyRateCache[$user->id] ?? 0.0);
            [$timeIn, $timeOut] = $this->getTimesForDate($user, $dateKey, $tz);
            $day = $this->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $sched, $dailyRate, $tz);
            $pageRows[] = $this->mapDayPayrollToDailyComputationRow(
                $user,
                $dateKey,
                $day,
                $timeIn,
                $timeOut,
                $dailyRate,
                (bool) ($scheduleFallbackCache[$user->id] ?? false),
                $sched,
                $tz
            );
        }

        $pageRows = $this->enrichDailyComputationRows($pageRows, $from, $to);

        $summary = $this->summarizeDailyComputationEngineGrid(
            $scopedEmployees,
            $grid,
            $scheduleCache,
            $dailyRateCache,
            $tz
        );

        Log::info('Admin daily computation engine grid completed', [
            'actor_user_id' => (int) $actor->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'search' => $search !== null && $search !== '',
            'status_filter' => $statusFilter ?? 'all',
            'company_id' => $companyId,
            'page' => $page,
            'per_page' => $perPage,
            'rows_returned' => count($pageRows),
            'total_pairs' => $totalGrid,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return [
            'data' => $pageRows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil(max(1, $totalGrid) / $perPage)),
                'per_page' => $perPage,
                'total' => $totalGrid,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'status_filter' => $statusFilter ?? 'all',
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $scopedEmployees
     * @return array<int, array{user_id: int, date: string}>
     */
    private function buildDailyComputationGrid($scopedEmployees, Carbon $from, Carbon $to): array
    {
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $grid = [];
        foreach ($scopedEmployees as $emp) {
            foreach ($dates as $d) {
                $grid[] = ['user_id' => (int) $emp->id, 'date' => $d];
            }
        }

        $byId = $scopedEmployees->keyBy('id');
        usort($grid, function (array $a, array $b) use ($byId): int {
            $cmp = strcmp($b['date'], $a['date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $nameA = (string) ($byId->get($a['user_id'])?->employeeListingSortKey() ?? '');
            $nameB = (string) ($byId->get($b['user_id'])?->employeeListingSortKey() ?? '');

            return strcmp($nameA, $nameB);
        });

        return $grid;
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function mapDayPayrollToDailyComputationRow(
        User $user,
        string $dateKey,
        array $day,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        float $computedDailyRate,
        bool $usedScheduleFallback,
        array $effectiveSchedule,
        string $tz
    ): array {
        $regularMinutes = (int) (($day['regular_day_minutes'] ?? 0) + ($day['regular_night_minutes'] ?? 0));
        $paidOtMinutes = (int) (($day['ot_day_minutes'] ?? 0) + ($day['ot_night_minutes'] ?? 0));
        $ndMinutes = (int) (($day['regular_night_minutes'] ?? 0) + ($day['ot_night_minutes'] ?? 0));
        $rawRenderedOtMinutes = (int) (($day['rendered_ot_day_minutes'] ?? $day['ot_day_minutes'] ?? 0) + ($day['rendered_ot_night_minutes'] ?? $day['ot_night_minutes'] ?? 0));
        $renderedMinutes = $regularMinutes + $rawRenderedOtMinutes;
        $paidOtHours = round($paidOtMinutes / 60, 2);
        $rawRenderedOtHours = round($rawRenderedOtMinutes / 60, 2);
        $approvedOtHours = round((float) ($day['approved_ot_hours'] ?? 0), 2);
        $approvedOtRequestedHours = round((float) ($day['approved_ot_requested_hours'] ?? $approvedOtHours), 2);
        $pendingOtHours = round((float) ($day['pending_ot_hours'] ?? 0), 2);
        $unapprovedOtHours = round((float) ($day['unapproved_ot_hours'] ?? 0), 2);

        $conditions = is_array($day['conditions'] ?? null) ? $day['conditions'] : [];
        $ruleCode = isset($conditions['rule_code']) && is_string($conditions['rule_code']) ? $conditions['rule_code'] : 'ORD';

        $needsReview = ((int) ($day['late_deduction_minutes'] ?? 0) > 0) || ($unapprovedOtHours > 0.01);
        $rowStatus = $needsReview ? 'needs_review' : 'valid';

        $holidayName = is_array($day['holiday'] ?? null) ? ($day['holiday']['name'] ?? null) : null;
        $holidayType = $conditions['holiday_type'] ?? (is_array($day['holiday'] ?? null) ? ($day['holiday']['type'] ?? null) : null);

        $flags = [];
        if ($unapprovedOtHours > 0.01) {
            $flags[] = 'UNAPPROVED_OT';
        }
        if ((int) ($day['late_deduction_minutes'] ?? 0) > 0) {
            $flags[] = 'LATE_DEDUCTION';
        }
        $dayStatus = (string) ($day['status'] ?? '');
        if ((! $timeIn || ! $timeOut) && $dayStatus !== 'leave' && ! ($day['is_rest_day'] ?? false)) {
            $flags[] = 'MISSING_TIME';
        }
        $hasNoPunchOtPay = (! $timeIn || ! $timeOut) && $approvedOtHours > 0.01;
        if ($hasNoPunchOtPay) {
            $flags[] = 'APPROVED_OT_NO_CLOCK';
        }

        $totalPay = (float) ($day['total_pay'] ?? 0);
        $engineStatus = (string) ($day['status'] ?? '');
        $payNote = null;
        if ($totalPay <= 0.0001) {
            if ($engineStatus === 'absent') {
                $payNote = 'No pay (absent)';
            } elseif ($engineStatus === 'leave') {
                $payNote = 'No pay (leave — unpaid or no credits)';
            } elseif ($engineStatus === 'rest_or_unscheduled') {
                $payNote = 'No pay (not scheduled / rest)';
            } else {
                $payNote = 'No pay';
            }
        }

        $scheduleLabel = $this->scheduleLabelForDate($effectiveSchedule, $dateKey, $tz);

        $timeInStr = $timeIn ? $timeIn->copy()->timezone($tz)->format('H:i:s') : '—';
        $timeOutStr = $timeOut ? $timeOut->copy()->timezone($tz)->format('H:i:s') : '—';

        return [
            'id' => ((int) $user->id).'-'.$dateKey,
            'user_id' => (int) $user->id,
            'employeeId' => $user->employee_code ? (string) $user->employee_code : 'EMP-'.((int) $user->id),
            'name' => (string) ($user->display_name ?? ''),
            'department' => $user->department ? (string) $user->department : null,
            'position' => $user->position ? (string) $user->position : null,
            'company_name' => $this->resolveCompanyDisplayName($user),
            'monthly_salary' => $user->monthly_salary !== null ? (string) $user->monthly_salary : null,
            'monthly_rate' => $user->monthly_rate !== null ? (string) $user->monthly_rate : null,
            'stored_daily_rate' => round((float) ($user->daily_rate ?? 0), 2),
            'effective_daily_rate' => round($computedDailyRate, 2),
            'schedule_rate_basis' => $usedScheduleFallback ? 'default_office' : 'employee_schedule',
            'profile_image' => $user->profile_image ? (string) $user->profile_image : null,
            'initials' => $this->initialsFromName((string) ($user->display_name ?? '')),
            'avatarColor' => $this->avatarColorForUserId((int) $user->id),
            'schedule_label' => $scheduleLabel,
            'schedule_source' => $usedScheduleFallback ? 'fallback' : 'assigned',
            'date' => $dateKey,
            'ot_basis' => (string) config('payroll.ot_basis', 'schedule_end'),
            'totalHrs' => $this->floatHoursToHhMm($renderedMinutes / 60),
            'regular' => $this->floatHoursToHhMm($regularMinutes / 60),
            'ot' => $this->floatHoursToHhMm($paidOtHours),
            'rendered_ot_hours' => $paidOtHours,
            'raw_rendered_ot_hours' => $rawRenderedOtHours,
            'ot_status' => $this->otWorkflowStatus(
                $rawRenderedOtHours,
                max($approvedOtHours, $approvedOtRequestedHours),
                $pendingOtHours,
                (bool) ($day['has_overtime_request'] ?? false)
            ),
            'nd' => $this->floatHoursToHhMm($ndMinutes / 60),
            'policy_snapshot' => $day['policy_snapshot'] ?? null,
            'dayType' => $this->dayTypeBadgeFromRuleCode($ruleCode),
            'rule' => $ruleCode,
            'ruleTooltip' => $this->ruleTooltipForCode($ruleCode, is_string($holidayName) ? $holidayName : null),
            'status' => $rowStatus,
            'holiday_name' => $holidayName ? (string) $holidayName : null,
            'holiday_type' => $holidayType ? (string) $holidayType : 'none',
            'rules_engine_holiday_type' => $holidayType ? (string) $holidayType : 'none',
            'is_rest_day' => (bool) ($day['is_rest_day'] ?? false),
            'first_8_multiplier' => (float) ($conditions['first_8'] ?? 1.0),
            'ot_multiplier' => (float) ($conditions['ot'] ?? 1.25),
            'approved_ot_hours' => $approvedOtHours,
            'approved_ot_requested_hours' => $approvedOtRequestedHours,
            'pending_ot_hours' => $pendingOtHours,
            'unapproved_ot_hours' => $unapprovedOtHours,
            'has_overtime_request' => (bool) ($day['has_overtime_request'] ?? false),
            'ot_premium_applied_hours' => round((float) ($day['ot_premium_applied_hours'] ?? 0), 2),
            'uncovered_ot_hours' => round((float) ($day['uncovered_ot_hours'] ?? 0), 2),
            'nd_premium_applied' => (bool) ($day['nd_premium_applied'] ?? false),
            'total_pay' => round($totalPay, 2),
            'regular_pay' => round((float) ($day['regular_pay'] ?? 0), 2),
            'ot_pay' => round((float) ($day['ot_pay'] ?? 0), 2),
            'nd_pay' => round((float) ($day['nd_pay'] ?? 0), 2),
            'holiday_premium_pay' => round((float) ($day['holiday_premium_pay'] ?? 0), 2),
            'conditions' => $conditions,
            'breakdown' => is_array($day['breakdown'] ?? null) ? $day['breakdown'] : [],
            'time_in' => $timeInStr,
            'time_out' => $timeOutStr,
            'tardiness_label' => $day['tardiness_label'] ?? null,
            'tardiness_status' => $day['tardiness_status'] ?? null,
            'late_deduction_minutes' => (int) ($day['late_deduction_minutes'] ?? 0),
            'segmented_regular_minutes' => (int) ($day['segmented_regular_minutes'] ?? $regularMinutes),
            'paid_regular_minutes' => (int) ($day['paid_regular_minutes'] ?? $regularMinutes),
            'grace_period_credit_minutes' => (int) ($day['grace_period_credit_minutes'] ?? 0),
            'regular_night_minutes' => (int) ($day['regular_night_minutes'] ?? 0),
            'ot_night_minutes' => (int) ($day['ot_night_minutes'] ?? 0),
            'expanded' => [
                'flags' => $flags,
                'comment' => $payNote,
            ],
            'pay_status' => $totalPay > 0.0001 ? 'payable' : 'no_pay',
            'pay_note' => $payNote,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $scopedEmployees
     * @param  array<int, array{user_id: int, date: string}>  $grid
     */
    private function summarizeDailyComputationEngineGrid(
        $scopedEmployees,
        array $grid,
        array $scheduleCache,
        array $dailyRateCache,
        string $tz
    ): array {
        $pairLimit = 2000;
        $totalPairs = count($grid);
        if ($totalPairs > $pairLimit) {
            return [
                'anomaly_count' => null,
                'total_ot_hours' => null,
                'total_nd_hours' => null,
                'total_rendered_hours' => null,
                'total_regular_hours' => null,
                'total_rendered_display' => '—',
                'total_regular_display' => '—',
                'total_nd_display' => '—',
                'unique_employees' => $scopedEmployees->count(),
                'total_logs' => $totalPairs,
                'ot_basis' => (string) config('payroll.ot_basis', 'schedule_end'),
                'summary_truncated' => true,
                'summary_note' => 'Totals omitted for large range; narrow dates for on-screen aggregates.',
            ];
        }

        $usersById = $scopedEmployees->keyBy('id');
        $anomaly = 0;
        $totalOtMin = 0;
        $totalNdMin = 0;
        $totalRenderedMin = 0;
        $totalRegularMin = 0;

        foreach ($grid as $cell) {
            $user = $usersById->get($cell['user_id']);
            if (! $user) {
                continue;
            }
            $dateKey = $cell['date'];
            $sched = $scheduleCache[$user->id] ?? $this->defaultOfficeScheduleFallback();
            $dailyRate = (float) ($dailyRateCache[$user->id] ?? 0.0);
            [$timeIn, $timeOut] = $this->getTimesForDate($user, $dateKey, $tz);
            $day = $this->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $sched, $dailyRate, $tz);
            if (((int) ($day['late_deduction_minutes'] ?? 0) > 0) || ((float) ($day['unapproved_ot_hours'] ?? 0) > 0.01)) {
                $anomaly++;
            }
            $totalOtMin += (int) ($day['ot_day_minutes'] ?? 0) + (int) ($day['ot_night_minutes'] ?? 0);
            $rawRenderedOt = (int) ($day['rendered_ot_day_minutes'] ?? $day['ot_day_minutes'] ?? 0) + (int) ($day['rendered_ot_night_minutes'] ?? $day['ot_night_minutes'] ?? 0);
            $totalNdMin += (int) ($day['regular_night_minutes'] ?? 0) + (int) ($day['ot_night_minutes'] ?? 0);
            $reg = (int) ($day['regular_day_minutes'] ?? 0) + (int) ($day['regular_night_minutes'] ?? 0);
            $totalRegularMin += $reg;
            $totalRenderedMin += $reg + $rawRenderedOt;
        }

        $totalOtHours = round($totalOtMin / 60, 2);
        $totalNdHours = round($totalNdMin / 60, 2);
        $totalRenderedHours = round($totalRenderedMin / 60, 2);
        $totalRegularHours = round($totalRegularMin / 60, 2);

        return [
            'anomaly_count' => $anomaly,
            'total_ot_hours' => $totalOtHours,
            'total_nd_hours' => $totalNdHours,
            'total_rendered_hours' => $totalRenderedHours,
            'total_regular_hours' => $totalRegularHours,
            'total_rendered_display' => $this->floatHoursToHhMm($totalRenderedHours),
            'total_regular_display' => $this->floatHoursToHhMm($totalRegularHours),
            'total_nd_display' => $this->floatHoursToHhMm($totalNdHours),
            'unique_employees' => $scopedEmployees->count(),
            'total_logs' => $totalPairs,
            'ot_basis' => (string) config('payroll.ot_basis', 'schedule_end'),
            'summary_truncated' => false,
            'summary_note' => null,
        ];
    }

    /**
     * One correction row for Admin Daily Computation detail (modal) — same query as table aggregates.
     *
     * Duration: uses {@see AttendanceStatusService::getNetWorkedMinutes} (minus unpaid break) when schedule exists;
     * for presence-filing “missing punch” kinds that cover a full scheduled day, uses required net hours from schedule.
     *
     * @return array<string, mixed>
     */
    private function serializeAttendanceCorrectionForDailyComputation(
        AttendanceCorrection $c,
        string $tz,
        ?User $user,
        ?string $dateKey
    ): array {
        $rejected = $c->rejected_at !== null;
        $finalApproved = ! $rejected && (bool) $c->approved && ! (bool) $c->pending_approval;
        $status = $rejected ? 'rejected' : ($finalApproved ? 'approved' : 'pending');

        $ti = $c->time_in ? $c->time_in->copy()->timezone($tz) : null;
        $to = $c->time_out ? $c->time_out->copy()->timezone($tz) : null;

        $issueKind = strtolower(trim((string) ($c->issue_kind ?? '')));
        $isMissingPunchKind = in_array($issueKind, ['both', 'missing_in', 'missing_out'], true);

        $scheduledNetMin = 0;
        $daySchedule = null;
        if ($user && $dateKey && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateKey)) {
            $effective = EmployeeScheduleResolver::resolve($user);
            if (is_array($effective)) {
                $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz));
                $candidate = $effective[$dayKey] ?? null;
                if (is_array($candidate) && ! empty($candidate['in']) && ! empty($candidate['out'])) {
                    $daySchedule = $candidate;
                    $scheduledNetMin = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $tz);
                }
            }
        }

        $correctedHours = null;
        $rawClockMinutes = null;
        $durationBasis = null;
        if ($ti && $to) {
            $rawClockMinutes = abs((int) $ti->diffInMinutes($to));
            if ($daySchedule !== null && $scheduledNetMin > 0) {
                $netFromPunch = AttendanceStatusService::getNetWorkedMinutes($ti, $to, $daySchedule, $dateKey, $tz);
                $fullDayToleranceMin = 15;
                $coversFullScheduledDay = $netFromPunch >= max(0, $scheduledNetMin - $fullDayToleranceMin);
                // Full shift: missing-punch kinds, or legacy rows with no issue_kind that still cover a full scheduled day.
                if ($coversFullScheduledDay && ($isMissingPunchKind || $issueKind === '')) {
                    $displayMin = $scheduledNetMin;
                    $durationBasis = 'schedule';
                } else {
                    $displayMin = $netFromPunch;
                    $durationBasis = 'net_worked';
                }
                $correctedHours = round($displayMin / 60, 2);
            } else {
                $correctedHours = round($rawClockMinutes / 60, 2);
                $durationBasis = 'raw_clock';
            }
        }

        $scheduledHours = $scheduledNetMin > 0 ? round($scheduledNetMin / 60, 2) : null;
        $requestedSpanHours = $rawClockMinutes !== null ? round($rawClockMinutes / 60, 2) : null;

        return [
            'id' => $c->id,
            'status' => $status,
            'reason_code' => is_string($c->reason_code) && trim($c->reason_code) !== '' ? trim($c->reason_code) : null,
            'remarks' => is_string($c->remarks) && trim($c->remarks) !== '' ? trim($c->remarks) : null,
            'issue_kind' => is_string($c->issue_kind) && trim($c->issue_kind) !== '' ? trim($c->issue_kind) : null,
            'time_in' => $ti ? $ti->format('H:i:s') : null,
            'time_out' => $to ? $to->format('H:i:s') : null,
            'corrected_hours' => $correctedHours,
            'duration_basis' => $durationBasis,
            'scheduled_net_hours' => $scheduledHours,
            'scheduled_hours' => $scheduledHours,
            'requested_clock_span_hours' => $requestedSpanHours,
            'raw_clock_hours' => $requestedSpanHours,
            'filed_at' => $c->filed_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'rejected_at' => $c->rejected_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichDailyComputationRows(array $rows, Carbon $from, Carbon $to): array
    {
        if ($rows === []) {
            return $rows;
        }

        $tz = $this->getTimezone();
        $userIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) ($r['user_id'] ?? 0),
            $rows
        )));
        $userIds = array_values(array_filter($userIds, static fn (int $id): bool => $id > 0));
        if ($userIds === []) {
            return $rows;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with(['workingSchedule:id,name,time_in,time_out,break_start,break_end'])
            ->get([
                'id',
                'department',
                'position',
                'profile_image',
                'monthly_salary',
                'monthly_rate',
                'daily_rate',
                'working_schedule_id',
                'schedule',
            ])
            ->keyBy('id');

        $fromUtc = $from->copy()->setTimezone($tz)->startOfDay()->setTimezone('UTC');
        $toUtc = $to->copy()->setTimezone($tz)->endOfDay()->setTimezone('UTC');
        $attendanceLogs = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('verified_at', [$fromUtc, $toUtc])
            ->orderBy('verified_at')
            ->get(['user_id', 'type', 'verified_at']);

        $attendanceByUserDate = [];
        foreach ($attendanceLogs as $log) {
            $dateKey = $log->verified_at?->copy()->timezone($tz)->toDateString();
            if (! $dateKey) {
                continue;
            }
            $k = $log->user_id.'|'.$dateKey;
            $bucket = $attendanceByUserDate[$k] ?? [
                'has_in' => false,
                'has_out' => false,
                'first_in' => null,
                'last_out' => null,
                'count' => 0,
            ];
            $bucket['count'] = (int) $bucket['count'] + 1;
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $bucket['has_in'] = true;
                $bucket['first_in'] = $bucket['first_in'] ?? $log->verified_at?->copy()->timezone($tz)->format('H:i:s');
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $bucket['has_out'] = true;
                $bucket['last_out'] = $log->verified_at?->copy()->timezone($tz)->format('H:i:s');
            }
            $attendanceByUserDate[$k] = $bucket;
        }

        $corrections = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $correctionsByUserDate = [];
        $correctionItemsByUserDate = [];
        foreach ($corrections as $correction) {
            $dateKey = $correction->date?->toDateString();
            if (! $dateKey) {
                continue;
            }
            $k = $correction->user_id.'|'.$dateKey;
            $bucket = $correctionsByUserDate[$k] ?? [
                'total' => 0,
                'approved' => 0,
                'pending' => 0,
                'rejected' => 0,
                'latest_remark' => null,
                'latest_reason' => null,
            ];
            $bucket['total'] = (int) $bucket['total'] + 1;
            if ($correction->rejected_at !== null) {
                $bucket['rejected'] = (int) $bucket['rejected'] + 1;
            } elseif ((bool) $correction->approved && ! (bool) $correction->pending_approval) {
                $bucket['approved'] = (int) $bucket['approved'] + 1;
            } else {
                $bucket['pending'] = (int) $bucket['pending'] + 1;
            }
            if (is_string($correction->remarks) && trim($correction->remarks) !== '') {
                $bucket['latest_remark'] = trim($correction->remarks);
            }
            if (is_string($correction->reason_code) && trim($correction->reason_code) !== '') {
                $bucket['latest_reason'] = trim($correction->reason_code);
            }
            $correctionsByUserDate[$k] = $bucket;

            $items = $correctionItemsByUserDate[$k] ?? [];
            $items[] = $this->serializeAttendanceCorrectionForDailyComputation(
                $correction,
                $tz,
                $users->get($correction->user_id),
                $correction->date?->toDateString()
            );
            $correctionItemsByUserDate[$k] = $items;
        }

        $leaves = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_PENDING])
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderByDesc('id')
            ->get([
                'id', 'user_id', 'type', 'status', 'start_date', 'end_date', 'half_type', 'notes',
                'leave_credits_charged', 'leave_unpaid_credit_days', 'pending_approval', 'filed_at', 'reviewed_at',
            ]);
        $leaveItemsByUserDate = [];
        foreach ($leaves as $leave) {
            $cursor = Carbon::parse($leave->start_date->toDateString(), $tz)->startOfDay();
            $end = Carbon::parse($leave->end_date->toDateString(), $tz)->startOfDay();
            $totalSpanDays = max(1, (int) $cursor->diffInDays($end) + 1);
            while ($cursor->lessThanOrEqualTo($end)) {
                $d = $cursor->toDateString();
                if ($d >= $from->toDateString() && $d <= $to->toDateString()) {
                    $k = $leave->user_id.'|'.$d;
                    $halfRaw = is_string($leave->half_type) ? strtolower(trim($leave->half_type)) : '';
                    $dayFraction = ($halfRaw !== '' && str_contains($halfRaw, 'half')) ? 0.5 : 1.0;
                    $leaveItemsByUserDate[$k][] = [
                        'id' => $leave->id,
                        'type' => (string) ($leave->type ?? 'leave'),
                        'status' => (string) ($leave->status ?? ''),
                        'start_date' => $leave->start_date->toDateString(),
                        'end_date' => $leave->end_date->toDateString(),
                        'half_type' => $leave->half_type ? (string) $leave->half_type : null,
                        'notes' => is_string($leave->notes) && trim($leave->notes) !== '' ? mb_substr(trim($leave->notes), 0, 480) : null,
                        'leave_credits_charged' => $leave->leave_credits_charged !== null ? (int) $leave->leave_credits_charged : null,
                        'leave_unpaid_credit_days' => $leave->leave_unpaid_credit_days !== null ? (int) $leave->leave_unpaid_credit_days : null,
                        'pending_approval' => (bool) $leave->pending_approval,
                        'day_fraction' => $dayFraction,
                        'request_span_days' => $totalSpanDays,
                        'filed_at' => $leave->filed_at?->toIso8601String(),
                        'reviewed_at' => $leave->reviewed_at?->toIso8601String(),
                    ];
                }
                $cursor->addDay();
            }
        }

        $payrollRules = config('payroll.rules', []);
        $overtimes = Overtime::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'date', 'status', 'computed_hours', 'ot_type', 'ph_ot_rule', 'reason', 'approved_at', 'rejected_at']);

        $otByUserDate = [];
        $otItemsByUserDate = [];
        foreach ($overtimes as $ot) {
            $dateKey = $ot->date?->toDateString();
            if (! $dateKey) {
                continue;
            }
            $k = $ot->user_id.'|'.$dateKey;
            $bucket = $otByUserDate[$k] ?? [
                'approved_hours' => 0.0,
                'pending_hours' => 0.0,
                'rejected_hours' => 0.0,
                'all_hours' => 0.0,
                'types' => [],
                'rules' => [],
                'approved_ot_end_time' => null,
            ];
            $hrs = (float) ($ot->computed_hours ?? 0);
            $bucket['all_hours'] += $hrs;
            if ($ot->status === Overtime::STATUS_APPROVED) {
                $bucket['approved_hours'] += $hrs;
                if ($ot->expected_end_time && ($bucket['approved_ot_end_time'] === null
                    || $ot->expected_end_time->format('H:i') > $bucket['approved_ot_end_time'])) {
                    $bucket['approved_ot_end_time'] = $ot->expected_end_time->format('H:i');
                }
            } elseif ($ot->status === Overtime::STATUS_PENDING) {
                $bucket['pending_hours'] += $hrs;
            } else {
                $bucket['rejected_hours'] += $hrs;
            }
            if (is_string($ot->ot_type) && trim($ot->ot_type) !== '') {
                $bucket['types'][trim($ot->ot_type)] = true;
            }
            if (is_string($ot->ph_ot_rule) && trim($ot->ph_ot_rule) !== '') {
                $bucket['rules'][trim($ot->ph_ot_rule)] = true;
            }
            $otByUserDate[$k] = $bucket;

            $ruleCode = is_string($ot->ph_ot_rule) && trim($ot->ph_ot_rule) !== '' ? strtoupper(trim($ot->ph_ot_rule)) : null;
            $ruleRow = $ruleCode && isset($payrollRules[$ruleCode]) ? $payrollRules[$ruleCode] : null;
            $otMult = $ruleRow ? (float) ($ruleRow['ot'] ?? 1.25) : (float) ($payrollRules['ORD']['ot'] ?? 1.25);
            $first8Mult = $ruleRow ? (float) ($ruleRow['first_8'] ?? 1.0) : 1.0;
            $otItemsByUserDate[$k][] = [
                'id' => $ot->id,
                'status' => (string) ($ot->status ?? ''),
                'computed_hours' => round($hrs, 2),
                'ot_type' => is_string($ot->ot_type) && trim($ot->ot_type) !== '' ? trim($ot->ot_type) : null,
                'ph_ot_rule' => $ruleCode,
                'ot_multiplier' => round($otMult, 2),
                'first_8_multiplier' => round($first8Mult, 2),
                'reason' => is_string($ot->reason) && trim($ot->reason) !== '' ? mb_substr(trim($ot->reason), 0, 400) : null,
                'approved_at' => $ot->approved_at?->toIso8601String(),
                'rejected_at' => $ot->rejected_at?->toIso8601String(),
                'expected_end_time' => $ot->expected_end_time?->format('H:i'),
                'schedule_end' => $ot->schedule_end?->format('H:i'),
            ];
        }

        $components = EmployeeCompensationComponent::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->where(function ($q) use ($to) {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $to->toDateString());
            })
            ->where(function ($q) use ($from) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString());
            })
            ->get(['user_id', 'name', 'type', 'category', 'value']);
        $componentsByUser = [];
        foreach ($components as $component) {
            $uid = (int) $component->user_id;
            $bucket = $componentsByUser[$uid] ?? ['basic' => 0.0, 'allowances' => 0.0];
            $name = strtolower(trim((string) ($component->name ?? '')));
            $type = strtolower(trim((string) ($component->type ?? '')));
            $category = strtolower(trim((string) ($component->category ?? '')));
            $value = (float) ($component->value ?? 0);
            $isBasic = $name === 'basic salary' || $type === 'basic' || $category === 'basic';
            if ($isBasic) {
                $bucket['basic'] += $value;
            } else {
                $bucket['allowances'] += $value;
            }
            $componentsByUser[$uid] = $bucket;
        }

        $deductions = EmployeeDeduction::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->where(function ($q) use ($to) {
                $q->whereNull('start_date')->orWhereDate('start_date', '<=', $to->toDateString());
            })
            ->where(function ($q) use ($from) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString());
            })
            ->with(['deductionType:id,name'])
            ->get(['id', 'user_id', 'deduction_type_id', 'amount']);
        $deductionsByUser = [];
        foreach ($deductions as $deduction) {
            $uid = (int) $deduction->user_id;
            $bucket = $deductionsByUser[$uid] ?? ['government' => 0.0, 'automated' => 0.0, 'total' => 0.0];
            $name = strtolower(trim((string) ($deduction->deductionType?->name ?? '')));
            $isGovernment = str_contains($name, 'sss') || str_contains($name, 'philhealth') || str_contains($name, 'pag-ibig') || str_contains($name, 'withholding');
            $amount = (float) ($deduction->amount ?? 0);
            if ($isGovernment) {
                $bucket['government'] += $amount;
            } else {
                $bucket['automated'] += $amount;
            }
            $bucket['total'] += $amount;
            $deductionsByUser[$uid] = $bucket;
        }

        foreach ($rows as &$row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $date = (string) ($row['date'] ?? '');
            $key = $uid.'|'.$date;
            $user = $users->get($uid);
            $attendance = $attendanceByUserDate[$key] ?? [];
            $correction = $correctionsByUserDate[$key] ?? [];
            $correctionItems = $correctionItemsByUserDate[$key] ?? [];
            $leaveItems = $leaveItemsByUserDate[$key] ?? [];
            $primaryApprovedLeave = null;
            foreach ($leaveItems as $li) {
                if (($li['status'] ?? '') === LeaveRequest::STATUS_APPROVED) {
                    $primaryApprovedLeave = $li;
                    break;
                }
            }
            $primaryLeaveForDisplay = $primaryApprovedLeave ?? ($leaveItems[0] ?? null);
            $leave = $primaryLeaveForDisplay ? [
                'type' => $primaryLeaveForDisplay['type'],
                'status' => $primaryLeaveForDisplay['status'],
            ] : null;
            $hasApprovedLeaveThisDay = $primaryApprovedLeave !== null;
            $ot = $otByUserDate[$key] ?? [];
            $otRawItems = $otItemsByUserDate[$key] ?? [];
            $dailyForOtEstimate = (float) ($row['effective_daily_rate'] ?? 0);
            $hourlyForOt = $dailyForOtEstimate > 0 ? $dailyForOtEstimate / 8.0 : 0.0;
            $otDetailItems = [];
            foreach ($otRawItems as $item) {
                $h = (float) ($item['computed_hours'] ?? 0);
                $mult = (float) ($item['ot_multiplier'] ?? 1.25);
                $isAppr = ($item['status'] ?? '') === Overtime::STATUS_APPROVED;
                $item['estimated_ot_pay'] = ($isAppr && $h > 0.0001 && $hourlyForOt > 0)
                    ? round($h * $hourlyForOt * $mult, 2)
                    : null;
                $otDetailItems[] = $item;
            }
            $comp = $componentsByUser[$uid] ?? ['basic' => 0.0, 'allowances' => 0.0];
            $ded = $deductionsByUser[$uid] ?? ['government' => 0.0, 'automated' => 0.0, 'total' => 0.0];

            $row['department'] = $row['department'] ?? $user?->department;
            $row['position'] = $row['position'] ?? $user?->position;
            $row['profile_image'] = $row['profile_image'] ?? $user?->profile_image;
            $row['profile_image_url'] = $user?->profile_image_url;
            $row['schedule'] = [
                'id' => $user?->workingSchedule?->id,
                'name' => $user?->workingSchedule?->name,
                'time_in' => $user?->workingSchedule?->time_in,
                'time_out' => $user?->workingSchedule?->time_out,
            ];

            [$sessionIn, $sessionOut] = $user
                ? $this->getTimesForDate($user, $date, $tz)
                : [null, null];
            $row['attendance_record'] = [
                'has_time_in' => $sessionIn !== null,
                'has_time_out' => $sessionOut !== null,
                'time_in' => $sessionIn ? $sessionIn->copy()->timezone($tz)->format('H:i:s') : ($attendance['first_in'] ?? null),
                'time_out' => $sessionOut ? $sessionOut->copy()->timezone($tz)->format('H:i:s') : ($attendance['last_out'] ?? null),
                'log_count' => (int) ($attendance['count'] ?? 0),
            ];
            $row['correction_applied'] = ((int) ($correction['approved'] ?? 0) > 0);
            $row['attendance_corrections'] = [
                'count' => (int) ($correction['total'] ?? 0),
                'approved_count' => (int) ($correction['approved'] ?? 0),
                'pending_count' => (int) ($correction['pending'] ?? 0),
                'rejected_count' => (int) ($correction['rejected'] ?? 0),
                'latest_remark' => $correction['latest_remark'] ?? null,
                'reason_code' => $correction['latest_reason'] ?? null,
                'items' => $correctionItems,
            ];

            $row['leave_record'] = $leave;
            $row['leave_records'] = [
                'count' => count($leaveItems),
                'items' => $leaveItems,
            ];
            $row['leave_status'] = $primaryLeaveForDisplay
                ? strtoupper((string) ($primaryLeaveForDisplay['status'] ?? '')).' · '.strtoupper((string) ($primaryLeaveForDisplay['type'] ?? 'leave'))
                : null;
            $row['overtime_record'] = [
                'hours' => round((float) ($ot['all_hours'] ?? 0.0), 2),
                'approved_hours' => round((float) ($ot['approved_hours'] ?? 0.0), 2),
                'pending_hours' => round((float) ($ot['pending_hours'] ?? 0.0), 2),
                'rejected_hours' => round((float) ($ot['rejected_hours'] ?? 0.0), 2),
                'approved_ot_end_time' => $ot['approved_ot_end_time'] ?? null,
                'types' => array_keys((array) ($ot['types'] ?? [])),
                'rules' => array_keys((array) ($ot['rules'] ?? [])),
                'items' => $otDetailItems,
                'engine_ot_pay' => round((float) ($row['ot_pay'] ?? 0), 2),
                'rendered_multiplier' => [
                    'first_8' => round((float) ($row['first_8_multiplier'] ?? 1.0), 2),
                    'ot' => round((float) ($row['ot_multiplier'] ?? 1.25), 2),
                ],
            ];

            $row['pay_components_preview'] = [
                'basic_salary' => round((float) ($comp['basic'] ?? 0.0), 2),
                'allowances' => round((float) ($comp['allowances'] ?? 0.0), 2),
            ];
            $row['government_deductions_preview'] = round((float) ($ded['government'] ?? 0.0), 2);
            $row['automated_deductions_preview'] = round((float) ($ded['automated'] ?? 0.0), 2);
            $row['deductions_preview_total'] = round((float) ($ded['total'] ?? 0.0), 2);
            $row['daily_pay_preview'] = round((float) ($row['total_pay'] ?? 0.0), 2);

            $hasApprovedCorrection = ((int) ($correction['approved'] ?? 0) > 0);
            $hasFullSession = $sessionIn !== null && $sessionOut !== null;

            $status = 'Absent';
            if ($hasApprovedLeaveThisDay) {
                $status = 'On leave';
            } elseif ((int) ($row['late_deduction_minutes'] ?? 0) > 0 && $hasFullSession) {
                $status = 'Late';
            } elseif ($hasFullSession && $hasApprovedCorrection) {
                $status = 'Corrected';
            } elseif ($hasFullSession) {
                $status = 'Present';
            } elseif ($sessionIn !== null || $sessionOut !== null) {
                $status = 'Incomplete';
            } elseif ((bool) ($row['is_rest_day'] ?? false)) {
                $status = 'Rest day';
            }
            $row['attendance_status'] = $status;
        }
        unset($row);

        return $rows;
    }

    private function hasAnyAttendanceLogForDate(User $user, string $dateKey, string $tz): bool
    {
        $cacheKey = ((int) $user->id).'|'.$dateKey.'|'.$tz;
        if (array_key_exists($cacheKey, $this->attendanceLogExistsCache)) {
            return $this->attendanceLogExistsCache[$cacheKey];
        }

        if ($this->attendanceSession->isBulkPayrollMode()) {
            return $this->attendanceLogExistsCache[$cacheKey] = $this->attendanceSession->hasAnyVerifiedLogOnLocalDate(
                (int) $user->id,
                $dateKey,
                $tz
            );
        }

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();

        return $this->attendanceLogExistsCache[$cacheKey] = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStart->copy()->setTimezone('UTC'), $dayEnd->copy()->setTimezone('UTC')])
            ->exists();
    }

    /**
     * Workflow state for OT: attendance-rendered hours vs Overtime module (requests + approved/pending hours).
     *
     * @return 'none'|'not_filed'|'approved'|'pending_review'|'partial_pending'|'unapproved'
     */
    private function otWorkflowStatus(float $renderedHours, float $approvedHours, float $pendingHours, bool $hasOvertimeRequest): string
    {
        if ($renderedHours <= 0.01) {
            return 'none';
        }
        if (! $hasOvertimeRequest) {
            return 'not_filed';
        }
        if ($approvedHours + 0.01 >= $renderedHours) {
            return 'approved';
        }
        if ($approvedHours + $pendingHours + 0.01 >= $renderedHours) {
            return 'pending_review';
        }
        if ($pendingHours > 0.01) {
            return 'partial_pending';
        }

        return 'unapproved';
    }

    private function floatHoursToHhMm(float $hours): string
    {
        $totalM = (int) round($hours * 60);
        $totalM = max(0, $totalM);

        return sprintf('%02d:%02d', intdiv($totalM, 60), $totalM % 60);
    }

    /**
     * Human-readable company for the row (aligned with {@see User::getEffectiveCompanyId()} ordering).
     */
    private function resolveCompanyDisplayName(User $user): ?string
    {
        $head = $user->relationLoaded('companyHeadships')
            ? $user->companyHeadships->first()
            : $user->companyHeadships()->first();
        if ($head && ! empty($head->name)) {
            return $head->name;
        }
        if ($user->company_id && $user->company) {
            return $user->company->name;
        }
        if ($user->branch_id && $user->branch?->company) {
            return $user->branch->company->name;
        }
        if ($user->department_id && $user->departmentRelation?->branch?->company) {
            return $user->departmentRelation->branch->company->name;
        }

        return null;
    }

    private function initialsFromName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
        $b = strtoupper(mb_substr($parts[1] ?? ($parts[0] ?? '?'), 0, 1));

        return $a.$b;
    }

    private function avatarColorForUserId(int $id): string
    {
        $colors = [
            'bg-slate-500', 'bg-emerald-600', 'bg-blue-600', 'bg-violet-600',
            'bg-rose-600', 'bg-amber-600', 'bg-cyan-600', 'bg-indigo-600',
        ];

        return $colors[$id % count($colors)];
    }

    private function dayTypeBadgeFromRuleCode(string $ruleCode): string
    {
        return match ($ruleCode) {
            'RD' => 'REST DAY',
            'RH', 'RHRD', 'SH', 'SHRD', 'DH', 'DHRD' => 'HOLIDAY',
            default => 'ORDINARY',
        };
    }

    private function ruleTooltipForCode(string $ruleCode, ?string $holidayName): string
    {
        $map = [
            'ORD' => 'Ordinary day — pay uses work after scheduled shift end as rendered OT (not net−8h); 1.00× non-OT work, OT ×1.25; ND +10% (10PM–6AM)',
            'RD' => 'Rest day — 1.30× first 8h, OT 1.69×; ND +10% on (HWR×1.30) / OT ND on (HWR×1.69)',
            'RH' => 'Regular holiday worked — 2.00× first 8h, OT 2.60×; ND +10% on premium rates',
            'RHRD' => 'Regular holiday + rest day — 2.60× first 8h, OT 3.38×; ND +10% on premium rates',
            'SH' => 'Special holiday — 1.30× first 8h, OT 1.69×; ND +10% on premium rates',
            'SHRD' => 'Special holiday + rest day — 1.50× first 8h, OT 1.95×; ND +10% on premium rates',
            'DH' => 'Double holiday — 3.00× first 8h, OT 3.90× (per company calendar)',
            'DHRD' => 'Double holiday + rest day — 3.00× first 8h, OT 3.90×',
        ];
        $base = $map[$ruleCode] ?? ($ruleCode.' — PH rules engine');
        if ($holidayName) {
            return $holidayName.': '.$base;
        }

        return $base;
    }
}
