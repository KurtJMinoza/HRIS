<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkingSchedule;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

class ScheduleRateService
{
    /** Order matches Carbon day-of-week: 0 = Sunday … 6 = Saturday */
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly PayCycleService $payCycleService,
    ) {}

    private function timezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Calculate schedule-based daily/hourly rates for an employee.
     *
     * Uses the employee's effective schedule from the Schedule module (or legacy
     * per-day JSON schedule when present). Working days per month are counted from
     * the calendar month of the reference date (rest days + holidays excluded),
     * not a fixed 22-day divisor.
     *
     * @return array{
     *   schedule_name: ?string,
     *   working_days_per_week: int,
     *   working_days_per_month: float,
     *   working_days_per_month_annualized: float,
     *   working_days_in_calendar_month: int,
     *   working_days_in_pay_period: int|null,
     *   working_hours_per_day: float,
     *   daily_rate: ?float,
     *   hourly_rate: ?float,
     *   derived_daily_rate: ?float,
     *   derived_hourly_rate: ?float,
     *   rate_divisor_source: string,
     * }
     */
    public function calculateDailyAndHourlyRate(int $employeeId, ?float $monthlySalary): array
    {
        $user = User::query()
            ->with('workingSchedule')
            ->findOrFail($employeeId);

        $scheduleMetrics = $this->describeForUser($user, $monthlySalary);

        return [
            'schedule_name' => $scheduleMetrics['schedule_name'] ?? null,
            'working_days_per_week' => (int) ($scheduleMetrics['working_days_per_week'] ?? 0),
            'working_days_per_month' => (float) ($scheduleMetrics['working_days_per_month'] ?? 0),
            'working_days_per_month_annualized' => (float) ($scheduleMetrics['working_days_per_month_annualized'] ?? 0),
            'working_days_in_calendar_month' => (int) ($scheduleMetrics['working_days_in_calendar_month'] ?? 0),
            'working_days_in_pay_period' => $scheduleMetrics['working_days_in_pay_period'] ?? null,
            'working_hours_per_day' => (float) ($scheduleMetrics['working_hours_per_day'] ?? 0),
            'daily_rate' => isset($scheduleMetrics['derived_daily_rate']) ? $scheduleMetrics['derived_daily_rate'] : null,
            'hourly_rate' => isset($scheduleMetrics['derived_hourly_rate']) ? $scheduleMetrics['derived_hourly_rate'] : null,
            'derived_daily_rate' => $scheduleMetrics['derived_daily_rate'] ?? null,
            'derived_hourly_rate' => $scheduleMetrics['derived_hourly_rate'] ?? null,
            'rate_divisor_source' => (string) ($scheduleMetrics['rate_divisor_source'] ?? ''),
        ];
    }

    public function resolveEffectiveSchedule(User $user): ?array
    {
        /**
         * Single source of truth with attendance / My Schedule / leave ({@see EmployeeScheduleResolver}).
         * Assigned `working_schedule_id` wins; legacy JSON is only used when there is no template
         * or the template row is missing (orphan FK).
         */
        return EmployeeScheduleResolver::resolve($user);
    }

    public function buildScheduleFromWorkingSchedule(?WorkingSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $restDays = $schedule->rest_days ?? [];
        $dayConfig = [];

        foreach (self::DAY_KEYS as $key) {
            if (in_array($key, $restDays, true)) {
                $dayConfig[$key] = null;

                continue;
            }

            $dayConfig[$key] = [
                'in' => $schedule->time_in,
                'out' => $schedule->time_out,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $dayConfig;
    }

    /**
     * Count days in [start, end] where the employee's template schedules a shift (rest days off).
     * Does **not** subtract public holidays — many employees still work on holidays; holiday premiums
     * are handled in payroll rules, not by shrinking the monthly salary divisor.
     *
     * Used for monthly salary → daily rate (calendar month / pay-period divisor).
     */
    public function countScheduledWorkdaysByScheduleOnly(User $user, Carbon $start, Carbon $end, ?array $schedule = null): int
    {
        return $this->countScheduledWorkdaysInRange($user, $start, $end, $schedule, false);
    }

    /**
     * Same as {@see countScheduledWorkdaysByScheduleOnly} but excludes dates marked as holidays
     * in the company calendar (for analytics / comparisons only).
     */
    public function countScheduledWorkdaysExcludingHolidays(User $user, Carbon $start, Carbon $end, ?array $schedule = null): int
    {
        return $this->countScheduledWorkdaysInRange($user, $start, $end, $schedule, true);
    }

    private function countScheduledWorkdaysInRange(
        User $user,
        Carbon $start,
        Carbon $end,
        ?array $schedule,
        bool $excludeCompanyHolidays,
    ): int {
        $schedule ??= $this->resolveEffectiveSchedule($user);
        if (! is_array($schedule) || $schedule === []) {
            return 0;
        }

        $tz = $this->timezone();
        $companyId = $user->getEffectiveCompanyId();
        $cursor = $start->copy()->timezone($tz)->startOfDay();
        $endAt = $end->copy()->timezone($tz)->startOfDay();
        if ($cursor->gt($endAt)) {
            return 0;
        }

        $count = 0;
        while ($cursor->lte($endAt)) {
            $dateKey = $cursor->toDateString();
            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $dayCfg = $schedule[$dayKey] ?? null;
            if (! is_array($dayCfg) || ! $this->scheduleDayHasShiftTimes($dayCfg)) {
                $cursor->addDay();

                continue;
            }
            if ($excludeCompanyHolidays && $this->holidayCalendar->holidayForDate($dateKey, $companyId) !== null) {
                $cursor->addDay();

                continue;
            }
            $count++;
            $cursor->addDay();
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>|null  $scheduleOverride  When set (e.g. Admin Daily Computation default Mon–Fri), use for day count & hours instead of DB/JSON only.
     */
    public function describeForUser(
        User $user,
        ?float $monthlySalary = null,
        ?Carbon $referenceDate = null,
        ?array $scheduleOverride = null,
        ?string $scheduleNameOverride = null,
    ): array {
        $user->loadMissing('workingSchedule');
        $schedule = $scheduleOverride ?? $this->resolveEffectiveSchedule($user);
        $ref = $referenceDate ?? Carbon::now($this->timezone())->startOfDay();
        $name = $scheduleNameOverride ?? $user->workingSchedule?->name;

        return $this->describeSchedule(
            $schedule,
            $monthlySalary,
            $name,
            $user,
            $ref
        );
    }

    public function describeSchedule(
        ?array $schedule,
        ?float $monthlySalary = null,
        ?string $scheduleName = null,
        ?User $user = null,
        ?Carbon $referenceDate = null,
    ): array {
        $workingDaysPerWeek = $this->workingDaysPerWeek($schedule);
        // Annualized average (e.g. 5-day week → ~21.67); payroll uses a stable rounded divisor.
        $annualizedMonthDays = $workingDaysPerWeek > 0
            ? round(($workingDaysPerWeek * 52) / 12, 2)
            : 0.0;
        $stablePayrollDivisor = $workingDaysPerWeek > 0
            ? (float) max(1, (int) round(($workingDaysPerWeek * 52) / 12))
            : 0.0;
        $workingHoursPerDay = $this->workingHoursPerDay($schedule);

        $calendarMonthWorkdays = 0;
        $payPeriodWorkdays = null;
        if ($user !== null) {
            $ref = $referenceDate ?? Carbon::now($this->timezone())->startOfDay();
            $monthStart = $ref->copy()->startOfMonth();
            $monthEnd = $ref->copy()->endOfMonth();
            $calendarMonthWorkdays = $this->countScheduledWorkdaysByScheduleOnly($user, $monthStart, $monthEnd, $schedule);
            $cycle = $this->payCycleService->resolveForUser($user);
            if ($cycle) {
                $period = $this->payCycleService->getCutOffPeriod($cycle, $ref);
                $payPeriodWorkdays = $this->countScheduledWorkdaysByScheduleOnly(
                    $user,
                    $period['start'],
                    $period['end'],
                    $schedule
                );
            }
        }

        $fallbackDays = (int) config('payroll.working_days_per_month', 22);
        // Align salary-tab derived daily rate with payroll computation (stable schedule divisor).
        $divisorDays = $stablePayrollDivisor > 0
            ? $stablePayrollDivisor
            : ($annualizedMonthDays > 0
                ? $annualizedMonthDays
                : (float) max(1, $fallbackDays));
        $divisorSource = $stablePayrollDivisor > 0
            ? 'stable_schedule_monthly'
            : ($annualizedMonthDays > 0 ? 'annualized_average' : 'config_fallback');

        $dailyRate = null;
        $hourlyRate = null;
        if ($monthlySalary !== null && $monthlySalary > 0 && $divisorDays > 0) {
            $dailyRate = round($monthlySalary / $divisorDays, 2);
            if ($workingHoursPerDay > 0) {
                $hourlyRate = round($dailyRate / $workingHoursPerDay, 2);
            }
        }

        return [
            'schedule_name' => $scheduleName,
            'working_days_per_week' => $workingDaysPerWeek,
            /** Primary divisor for monthly → daily (calendar month workdays, or annualized / config fallback). */
            'working_days_per_month' => round($divisorDays, 2),
            'working_days_per_month_annualized' => $annualizedMonthDays,
            'working_days_in_calendar_month' => $calendarMonthWorkdays,
            'working_days_in_pay_period' => $payPeriodWorkdays,
            'working_hours_per_day' => $workingHoursPerDay,
            'derived_daily_rate' => $dailyRate,
            'derived_hourly_rate' => $hourlyRate,
            'rate_divisor_source' => $divisorSource,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $scheduleOverride  When provided (e.g. daily computation fallback), monthly÷days uses this shape.
     */
    public function resolveDailyRate(
        User $user,
        ?array $scheduleOverride = null,
        ?string $scheduleNameOverride = null,
        ?Carbon $referenceDate = null,
        ?float $monthlyBaseOverride = null,
    ): float {
        $user->loadMissing('workingSchedule');
        $monthlyBase = $monthlyBaseOverride ?? (float) ($user->monthly_salary ?? $user->monthly_rate ?? 0);
        $scheduleMetrics = $this->describeForUser(
            $user,
            $monthlyBase > 0 ? $monthlyBase : null,
            $referenceDate,
            $scheduleOverride,
            $scheduleNameOverride
        );
        $derivedDaily = (float) ($scheduleMetrics['derived_daily_rate'] ?? 0);

        if ($derivedDaily > 0) {
            return $derivedDaily;
        }

        return (float) ($user->daily_rate ?? 0);
    }

    /**
     * Hourly rate from schedule-derived daily rate ÷ scheduled hours per day (not a fixed 8h unless schedule says so).
     *
     * @param  array<string, mixed>|null  $scheduleOverride
     */
    public function resolveHourlyRate(
        User $user,
        ?array $scheduleOverride = null,
        ?string $scheduleNameOverride = null,
        ?Carbon $referenceDate = null,
        ?float $monthlyBaseOverride = null,
    ): float {
        $user->loadMissing('workingSchedule');
        $monthlyBase = $monthlyBaseOverride ?? (float) ($user->monthly_salary ?? $user->monthly_rate ?? 0);
        $scheduleMetrics = $this->describeForUser(
            $user,
            $monthlyBase > 0 ? $monthlyBase : null,
            $referenceDate,
            $scheduleOverride,
            $scheduleNameOverride
        );
        $hourly = (float) ($scheduleMetrics['derived_hourly_rate'] ?? 0);
        if ($hourly > 0) {
            return $hourly;
        }

        return (float) ($user->hourly_rate ?? 0);
    }

    /**
     * True when both shift boundaries are non-empty (MySQL TIME may be H:i:s; avoid empty() on "0").
     */
    private function scheduleDayHasShiftTimes(?array $day): bool
    {
        if (! is_array($day)) {
            return false;
        }
        $in = trim((string) ($day['in'] ?? ''));
        $out = trim((string) ($day['out'] ?? ''));

        return $in !== '' && $out !== '';
    }

    /**
     * Parse MySQL TIME / UI strings (H:i or H:i:s) for payroll duration math.
     */
    private function parseScheduleTimeToCarbon(string $raw): ?Carbon
    {
        $t = trim($raw);
        if ($t === '') {
            return null;
        }
        foreach (['H:i:s.u', 'H:i:s', 'H:i', 'G:i:s', 'G:i'] as $fmt) {
            try {
                $c = Carbon::createFromFormat($fmt, $t);
                if ($c instanceof Carbon) {
                    return $c;
                }
            } catch (\Throwable) {
                // try next
            }
        }
        try {
            return Carbon::parse($t);
        } catch (\Throwable) {
            return null;
        }
    }

    private function workingDaysPerWeek(?array $schedule): int
    {
        if (! is_array($schedule) || $schedule === []) {
            return 0;
        }

        $count = 0;
        foreach ($schedule as $day) {
            if (is_array($day) && $this->scheduleDayHasShiftTimes($day)) {
                $count++;
            }
        }

        return $count;
    }

    private function workingHoursPerDay(?array $schedule): float
    {
        if (! is_array($schedule) || $schedule === []) {
            return 0.0;
        }

        $hours = [];
        foreach ($schedule as $day) {
            if (! is_array($day) || ! $this->scheduleDayHasShiftTimes($day)) {
                continue;
            }

            $minutes = $this->workingMinutesForDay($day);
            if ($minutes > 0) {
                $hours[] = $minutes / 60;
            }
        }

        if ($hours === []) {
            return 0.0;
        }

        return round(array_sum($hours) / count($hours), 2);
    }

    private function workingMinutesForDay(array $day): int
    {
        $timeIn = $this->parseScheduleTimeToCarbon((string) ($day['in'] ?? ''));
        $timeOut = $this->parseScheduleTimeToCarbon((string) ($day['out'] ?? ''));
        if ($timeIn === null || $timeOut === null) {
            return 0;
        }

        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut->addDay();
        }

        $minutes = $timeIn->diffInMinutes($timeOut);

        $bs = trim((string) ($day['break_start'] ?? ''));
        $be = trim((string) ($day['break_end'] ?? ''));
        if ($bs !== '' && $be !== '') {
            $breakStart = $this->parseScheduleTimeToCarbon($bs);
            $breakEnd = $this->parseScheduleTimeToCarbon($be);
            if ($breakStart !== null && $breakEnd !== null) {
                if ($breakEnd->lessThanOrEqualTo($breakStart)) {
                    $breakEnd->addDay();
                }
                $minutes -= $breakStart->diffInMinutes($breakEnd);
            }
        }

        return max(0, $minutes);
    }
}
