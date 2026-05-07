<?php

namespace App\Services;

use App\Models\Policy;
use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\Carbon;

/**
 * Payroll Rules Engine — policy layer (PH Labor Code Arts. 87, 93, 94).
 *
 * Decision order (same as company policy manual): HolidayType → RestDay → segment hours → multipliers from
 * `payroll_rules` (DB) or `config('payroll.rules')` via {@see self::getMultipliersForRule()}.
 *
 * Architecture:
 *   Attendance Logs → TimeSegmentationService → Rules Engine → PayrollComputationService / PremiumReportService
 *
 * Combines:
 * - Time segmentation (regular ≤8h, OT, ND 22:00–06:00)
 * - Holiday classification (regular / special / double from {@see HolidayCalendarService} + `config('payroll.holiday_types')`)
 * - Rest day from schedule JSON / WorkingSchedule
 *
 * See: docs/PAYROLL_RULES_ENGINE.md — GET /admin/payroll/policy-reference for JSON snapshot.
 */
class PayrollRulesEngineService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly TimeSegmentationService $timeSegmentation,
        private readonly AttendanceSessionService $attendanceSession,
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly PolicyResolverService $policyResolver,
    ) {}

    public function getTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Resolve effective schedule for a user.
     */
    public function resolveEffectiveSchedule(User $user): ?array
    {
        $schedule = $user->schedule;
        if (is_array($schedule) && $schedule !== []) {
            return $schedule;
        }
        if ($user->working_schedule_id !== null) {
            return $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
        }

        return null;
    }

    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $ws): ?array
    {
        if (! $ws) {
            return null;
        }
        $restDays = $ws->rest_days ?? [];
        $dayConfig = [];
        foreach (self::DAY_KEYS as $key) {
            if (in_array($key, $restDays, true)) {
                $dayConfig[$key] = null;

                continue;
            }
            $dayConfig[$key] = [
                'in' => $ws->time_in,
                'out' => $ws->time_out,
                'break_start' => $ws->break_start,
                'break_end' => $ws->break_end,
            ];
        }

        return $dayConfig;
    }

    private function dayKeyForDate(Carbon $date): string
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
     * Phase 2: Resolve rule code from conditions.
     * Engine Decision Logic:
     * IF holiday = double AND rest_day = true → DHRD
     * ELSE IF holiday = double → DH
     * ELSE IF holiday = regular AND rest_day = true → RHRD
     * ELSE IF holiday = regular → RH
     * ELSE IF holiday = special AND rest_day = true → SHRD
     * ELSE IF holiday = special → SH
     * ELSE IF rest_day = true → RD
     * ELSE → ORD
     */
    public function resolveRuleCode(bool $isRestDay, ?string $holidayType): string
    {
        if ($holidayType === 'double' && $isRestDay) {
            return 'DHRD';
        }
        if ($holidayType === 'double') {
            return 'DH';
        }
        if ($holidayType === 'regular' && $isRestDay) {
            return 'RHRD';
        }
        if ($holidayType === 'regular') {
            return 'RH';
        }
        if ($holidayType === 'special' && $isRestDay) {
            return 'SHRD';
        }
        if ($holidayType === 'special') {
            return 'SH';
        }
        if ($isRestDay) {
            return 'RD';
        }

        return 'ORD';
    }

    /**
     * Phase 2: Get multipliers for a rule code.
     * Uses PolicyResolverService when policy provided; otherwise payroll_rules / config.
     *
     * @return array{first_8: float, ot: float, nd_base: float, nd_addon?: float}
     */
    public function getMultipliersForRule(string $ruleCode, ?Policy $policy = null): array
    {
        return $this->policyResolver->getMultipliersForRule($policy, $ruleCode);
    }

    /**
     * Get holiday classification for a date (rules engine).
     * Returns: "regular" | "special" | "double" | null — must align with resolveRuleCode().
     */
    public function getHolidayType(
        string $dateKey,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null
    ): ?string
    {
        $holiday = $this->holidayCalendar->holidayForDate($dateKey, $companyId, $branchId, $departmentId, $employeeId);

        if (! $holiday) {
            return null;
        }

        $raw = strtolower(trim($holiday['type'] ?? ''));
        if ($raw === '') {
            return 'special'; // default
        }

        $map = config('payroll.holiday_types', [
            'regular' => 'regular',
            'special' => 'special',
            'special_non_working' => 'special',
            'special_working' => 'special',
            'double' => 'double',
            'company' => 'special',
        ]);

        return $map[$raw] ?? 'special';
    }

    /**
     * Get time in/out for a user and date (logs + corrections).
     */
    public function getTimesForDate(User $user, string $dateKey): array
    {
        return $this->attendanceSession->getTimesForDate($user, $dateKey, $this->getTimezone());
    }

    /**
     * Classify a single day – MVP output (NO PAY).
     *
     * @return array{
     *   date: string,
     *   regular_hours: float,
     *   overtime_hours: float,
     *   night_hours: float,
     *   total_hours: float,
     *   is_rest_day: bool,
     *   holiday_type: string|null,
     *   has_attendance: bool
     * }
     */
    public function classifyDay(User $user, string $dateKey): array
    {
        $tz = $this->getTimezone();
        $date = Carbon::parse($dateKey, $tz);

        $effectiveSchedule = $this->resolveEffectiveSchedule($user);
        $isRestDay = $effectiveSchedule ? $this->isRestDay($effectiveSchedule, $date) : false;
        $holidayType = $this->getHolidayType($dateKey, $user->getEffectiveCompanyId());

        [$timeIn, $timeOut] = $this->getTimesForDate($user, $dateKey);

        if (! $timeIn || ! $timeOut) {
            $segmentation = $this->timeSegmentation->segmentEmpty();

            return [
                'date' => $dateKey,
                'regular_hours' => $segmentation['regular_hours'],
                'overtime_hours' => $segmentation['overtime_hours'],
                'night_hours' => $segmentation['night_hours'],
                'total_hours' => $segmentation['total_hours'],
                'is_rest_day' => $isRestDay,
                'holiday_type' => $holidayType,
                'has_attendance' => false,
            ];
        }

        $daySchedule = $effectiveSchedule[$this->dayKeyForDate($date)] ?? null;
        $segmentation = $this->timeSegmentation->segment($timeIn, $timeOut, $tz, $daySchedule, $dateKey);

        return [
            'date' => $dateKey,
            'regular_hours' => $segmentation['regular_hours'],
            'overtime_hours' => $segmentation['overtime_hours'],
            'night_hours' => $segmentation['night_hours'],
            'total_hours' => $segmentation['total_hours'],
            'is_rest_day' => $isRestDay,
            'holiday_type' => $holidayType,
            'has_attendance' => true,
        ];
    }

    /**
     * Classify a date range for an employee – Phase 1 MVP output.
     *
     * @return array{
     *   user_id: int,
     *   from_date: string,
     *   to_date: string,
     *   days: array,
     *   summary: array
     * }
     */
    public function classifyRange(User $user, Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $days[] = $this->classifyDay($user, $cursor->toDateString());
            $cursor->addDay();
        }

        $totalRegular = 0.0;
        $totalOvertime = 0.0;
        $totalNight = 0.0;
        $totalHours = 0.0;

        foreach ($days as $d) {
            $totalRegular += $d['regular_hours'];
            $totalOvertime += $d['overtime_hours'];
            $totalNight += $d['night_hours'];
            $totalHours += $d['total_hours'];
        }

        return [
            'user_id' => $user->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $days,
            'summary' => [
                'regular_hours' => round($totalRegular, 2),
                'overtime_hours' => round($totalOvertime, 2),
                'night_hours' => round($totalNight, 2),
                'total_hours' => round($totalHours, 2),
                'days_with_attendance' => count(array_filter($days, fn ($d) => $d['has_attendance'])),
            ],
        ];
    }
}
