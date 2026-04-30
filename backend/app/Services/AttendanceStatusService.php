<?php

namespace App\Services;

use App\Models\Overtime;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Attendance lateness and half-day computation (QA / System Requirement).
 *
 * Rules based on employee's scheduled start time (default 8:00 AM):
 *
 * | Time Range        | Status                |
 * |------------------|------------------------|
 * | Before 8:05 AM   | Present                |
 * | 8:06 – 8:29 AM  | 30 Minutes late        |
 * | 8:30 – 8:59 AM  | 1 Hour Late            |
 * | 9:00 – 9:29 AM  | 1 Hour 30 minutes late |
 * | 9:30 – 9:59 AM  | 2 Hours Late           |
 * | 10:00 – 10:29 AM| 2 Hours 30 minutes late|
 * | 10:30 – 10:59 AM| 3 Hours Late           |
 * | 11:00 – 11:29 AM| 3 Hours 30 minutes late|
 * | 11:30 – 11:59 AM| 4 Hours Late           |
 * | 12:00 PM – 1:00 PM (config window) | Half Day |
 *
 * Applied consistently in: Admin Reports, Employee Dashboard, Admin Dashboard, DTR.
 */
class AttendanceStatusService
{
    /** Default grace minutes when not in schedule; fallback after config. */
    public const DEFAULT_GRACE_MINUTES = 5;

    /** Default hour (24h) for half-day; 12 = 12:00 PM (noon). */
    public const DEFAULT_HALF_DAY_START_HOUR = 12;

    /** Maximum grace minutes (8:05 cutoff). Schedules cannot exceed this for Present vs Late. */
    public const MAX_GRACE_MINUTES = 5;

    /**
     * Grace minutes: from day schedule (grace_minutes/grace) else from config else default.
     * Capped at MAX_GRACE_MINUTES (5) so Present = at or before 8:05 AM only; 8:06+ = Late.
     */
    public static function getGraceMinutes(array $daySchedule): int
    {
        $g = $daySchedule['grace_minutes'] ?? $daySchedule['grace'] ?? $daySchedule['grace_period_minutes'] ?? null;
        $fromSchedule = ($g !== null && $g !== '' && (int) $g > 0) ? (int) $g : null;
        $grace = $fromSchedule ?? (int) config('attendance.grace_period_minutes', self::DEFAULT_GRACE_MINUTES);

        return min($grace, self::MAX_GRACE_MINUTES);
    }

    /** Hour (24h) at or after which first time-in is half day; from config. */
    public static function getHalfDayStartHour(): int
    {
        $h = config('attendance.half_day_start_hour', self::DEFAULT_HALF_DAY_START_HOUR);

        return (int) $h;
    }

    /**
     * End of half-day clock-in window (exclusive): e.g. 13 = treat 12:00–12:59 as half day, 13:00+ uses tardiness bands.
     */
    public static function getHalfDayEndHour(): int
    {
        return (int) config('attendance.half_day_end_hour', 13);
    }

    public static function getHalfDayEndMinute(): int
    {
        return (int) config('attendance.half_day_end_minute', 0);
    }

    /**
     * Minutes from midnight of the exclusive end of the half-day clock-in window (e.g. 13:00 → 780).
     */
    public static function getHalfDayClockInWindowEndMinutesFromMidnight(): int
    {
        $h = self::getHalfDayEndHour();
        $m = self::getHalfDayEndMinute();

        return $h * 60 + $m;
    }

    /**
     * Paid regular cap for half-day classification (default 4h = 240m).
     */
    public static function getHalfDayRegularCapMinutes(): int
    {
        return (int) config('attendance.half_day_regular_minutes', 240);
    }

    /**
     * Minutes late after grace: max(0, actual_time_in - scheduled_start - grace).
     * Used only to decide Present vs Late (after grace). For display/payroll late minutes
     * use computeLateMinutesFromScheduleStart so Late Minutes = Actual Time-In - 8:00 AM.
     */
    public static function computeMinutesLate(Carbon $scheduledStart, int $graceMinutes, Carbon $actualTimeIn): int
    {
        $midnight = $scheduledStart->copy()->startOfDay();
        $scheduledMinutes = $scheduledStart->diffInMinutes($midnight, false);
        $actualMinutes = $actualTimeIn->diffInMinutes($actualTimeIn->copy()->startOfDay(), false);

        $cutoff = $scheduledMinutes + $graceMinutes;
        $late = $actualMinutes - $cutoff;

        return $late > 0 ? $late : 0;
    }

    /**
     * Late minutes from schedule start only: max(0, actual_time_in - scheduled_start).
     * Use this for Late status so that e.g. 8:06 AM → 6 minutes late, 9:15 AM → 75 minutes.
     * All times are same-day in the same timezone.
     */
    public static function computeLateMinutesFromScheduleStart(Carbon $scheduledStart, Carbon $actualTimeIn): int
    {
        $midnight = $scheduledStart->copy()->startOfDay();
        $scheduledMinutes = $scheduledStart->diffInMinutes($midnight, false);
        $actualMinutes = $actualTimeIn->diffInMinutes($actualTimeIn->copy()->startOfDay(), false);
        $late = $actualMinutes - $scheduledMinutes;

        return $late > 0 ? $late : 0;
    }

    /**
     * Late buckets by raw minutes late (from scheduled start, 8:00-style bands).
     * 8:06–8:29 → 30 min; 8:30–8:59 → 1 hr; 9:00–9:29 → 1 hr 30 min; 9:30–9:59 → 2 hr;
     * 10:00–10:29 → 2 hr 30 min; 10:30–10:59 → 3 hr; 11:00–11:29 → 3 hr 30 min; 11:30–11:59 → 4 hr.
     *
     * @return array{minutes: int, label: string}
     */
    public static function getLateBucket(int $rawMinutesLate): array
    {
        if ($rawMinutesLate <= 0) {
            return ['minutes' => 0, 'label' => 'Present'];
        }
        if ($rawMinutesLate <= 29) {
            return ['minutes' => 30, 'label' => '30 Minutes late'];
        }
        if ($rawMinutesLate <= 59) {
            return ['minutes' => 60, 'label' => '1 Hour Late'];
        }
        if ($rawMinutesLate <= 89) {
            return ['minutes' => 90, 'label' => '1 Hour 30 minutes late'];
        }
        if ($rawMinutesLate <= 119) {
            return ['minutes' => 120, 'label' => '2 Hours Late'];
        }
        if ($rawMinutesLate <= 149) {
            return ['minutes' => 150, 'label' => '2 Hours 30 minutes late'];
        }
        if ($rawMinutesLate <= 179) {
            return ['minutes' => 180, 'label' => '3 Hours Late'];
        }
        if ($rawMinutesLate <= 209) {
            return ['minutes' => 210, 'label' => '3 Hours 30 minutes late'];
        }

        return ['minutes' => 240, 'label' => '4 Hours Late'];
    }

    /**
     * Map raw minutes late to display label (uses same buckets as getLateBucket).
     * (Clock-in at or after half_day_start_hour is handled as Half Day before this.)
     */
    public static function lateMinutesToLabel(int $minutesLate): string
    {
        return self::getLateBucket($minutesLate)['label'];
    }

    /**
     * Deduction/bucket minutes for payroll: 0, 30, 60, 90, 120, 150, 180, 210, 240.
     */
    public static function lateMinutesToDeductionMinutes(int $minutesLate): int
    {
        return self::getLateBucket($minutesLate)['minutes'];
    }

    /**
     * Clock-in status for a single time-in.
     * All comparisons use the attendance timezone. Status priority: Half-Day > Late > Present.
     * Schedule date is taken from the clock-in date in attendance timezone so same-day comparison is correct.
     *
     * Time-in rules (scheduled start 8:00 AM by default, grace 5 min → Present through 8:05):
     * - Below 8:05 → Present.
     * - 8:06–8:29 → 30 Minutes late; … 11:30–11:59 → 4 Hours Late (see getLateBucket).
     * - 12:00 PM–1:00 PM (half-day window) → Half Day.
     *
     * @return array{status: 'on_time'|'late'|'half_day', late_minutes: int, late_label: string}
     */
    public static function getClockInStatus(array $daySchedule, string $dateKey, Carbon $actualTimeIn): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $actualTimeIn = $actualTimeIn->copy()->timezone($tz);
        // Use clock-in calendar date in attendance timezone so schedule is always same-day
        $dateKey = $actualTimeIn->format('Y-m-d');

        $graceMinutes = self::getGraceMinutes($daySchedule);
        $halfDayStartMin = self::getHalfDayStartHour() * 60;
        $halfDayEndMin = self::getHalfDayClockInWindowEndMinutesFromMidnight();
        $scheduleIn = trim((string) ($daySchedule['in'] ?? '08:00'));
        if (strpos($scheduleIn, ':') === false) {
            $scheduleIn = '08:00';
        }
        $scheduledStart = Carbon::parse($dateKey.' '.$scheduleIn, $tz);

        // Minutes from midnight (same timezone) for explicit comparison
        $actualMinutesFromMidnight = (int) $actualTimeIn->format('G') * 60 + (int) $actualTimeIn->format('i');
        $scheduledMinutesFromMidnight = (int) $scheduledStart->format('G') * 60 + (int) $scheduledStart->format('i');

        // 1) Half-day rule: clock-in in [half_day_start, half_day_end), e.g. 12:00 PM–1:00 PM (end exclusive)
        if ($actualMinutesFromMidnight >= $halfDayStartMin && $actualMinutesFromMidnight < $halfDayEndMin) {
            return [
                'status' => 'half_day',
                'late_minutes' => 0,
                'late_label' => 'Half Day',
            ];
        }

        // 2) Present vs Late: at or before scheduled_start + grace (e.g. 8:05) → Present; after that → late brackets.
        $graceCutoffMinutes = $scheduledMinutesFromMidnight + $graceMinutes;
        if ($actualMinutesFromMidnight <= $graceCutoffMinutes) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
                'late_label' => 'Present',
            ];
        }

        // 3) Late: raw minutes late = actual - scheduled start
        $rawMinutesLate = $actualMinutesFromMidnight - $scheduledMinutesFromMidnight;

        // Extremely late (≥4 hours): treat as Half Day so badge appears consistently
        $halfDayLateThreshold = (int) config('attendance.half_day_late_minutes_threshold', 240);
        if ($rawMinutesLate >= $halfDayLateThreshold) {
            return [
                'status' => 'half_day',
                'late_minutes' => $rawMinutesLate,
                'late_label' => 'Half Day',
            ];
        }

        $bucket = self::getLateBucket($rawMinutesLate);

        return [
            'status' => 'late',
            'late_minutes' => $bucket['minutes'],
            'late_label' => $bucket['label'],
        ];
    }

    /**
     * Whether it is past the absent cutoff (e.g. 5:00 PM) on the given date in attendance timezone.
     * Used for "Absent Today": user is marked absent only if not present until this time.
     */
    public static function isPastAbsentCutoff(string $dateKey, Carbon $now): bool
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $now = $now->copy()->timezone($tz);
        $hour = (int) config('attendance.absent_cutoff_hour', 17);
        $minute = (int) config('attendance.absent_cutoff_minute', 0);
        $cutoff = Carbon::parse($dateKey, $tz)->setTime($hour, $minute, 0);

        return $now->greaterThanOrEqualTo($cutoff);
    }

    /**
     * Scheduled end for a given date and day schedule (supports night shift: if out <= in, end is next day at out).
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array{in?: string, out?: string}  $daySchedule
     */
    public static function getScheduledEndForDate(string $dateKey, array $daySchedule, ?string $tz = null): ?Carbon
    {
        $out = $daySchedule['out'] ?? null;
        if ($out === null || $out === '') {
            return null;
        }
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));
        $in = $daySchedule['in'] ?? '';
        if ($in !== '' && $out <= $in) {
            return Carbon::parse(Carbon::parse($dateKey)->addDay()->toDateString().' '.$out, $tz);
        }

        return Carbon::parse($dateKey.' '.$out, $tz);
    }

    /**
     * Scheduled shift start on dateKey (same calendar day as the schedule row; night shift "in" is on dateKey).
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array{in?: string, out?: string}  $daySchedule
     */
    public static function getScheduledStartForDate(string $dateKey, array $daySchedule, ?string $tz = null): ?Carbon
    {
        $in = $daySchedule['in'] ?? null;
        if ($in === null || trim((string) $in) === '') {
            return null;
        }
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));

        return Carbon::parse($dateKey.' '.substr(trim((string) $in), 0, 5), $tz);
    }

    /**
     * Required working minutes for the day: (scheduled end − scheduled start) − break duration.
     * Break time (e.g. 12:00–1:00) is unpaid and must be deducted so total = 8 hours, not 9.
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array{in?: string, out?: string, break_start?: string|null, break_end?: string|null}  $daySchedule
     */
    public static function getRequiredWorkingMinutes(string $dateKey, array $daySchedule, ?string $tz = null): int
    {
        $in = $daySchedule['in'] ?? null;
        $out = $daySchedule['out'] ?? null;
        if ($in === null || $in === '' || $out === null || $out === '') {
            return 0;
        }
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));
        $scheduledStart = Carbon::parse($dateKey.' '.$in, $tz);
        $scheduledEnd = self::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            return 0;
        }
        $totalSpanMinutes = (int) $scheduledStart->diffInMinutes($scheduledEnd);

        $breakStart = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEnd = trim((string) ($daySchedule['break_end'] ?? ''));
        if ($breakStart !== '' && $breakEnd !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $breakStart) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $breakEnd)) {
            $breakStartCarbon = Carbon::parse($dateKey.' '.substr($breakStart, 0, 5), $tz);
            $breakEndCarbon = Carbon::parse($dateKey.' '.substr($breakEnd, 0, 5), $tz);
            if ($breakEndCarbon->lessThanOrEqualTo($breakStartCarbon)) {
                $breakEndCarbon->addDay();
            }
            $breakMinutes = (int) $breakStartCarbon->diffInMinutes($breakEndCarbon);
            $totalSpanMinutes = max(0, $totalSpanMinutes - $breakMinutes);
        }

        return $totalSpanMinutes;
    }

    /**
     * Net worked minutes between timeIn and timeOut, subtracting any overlap with the
     * schedule's unpaid break window (e.g. 12:00–13:00). Use this for manual corrections
     * where a single clock-in/clock-out pair covers the full shift including break time.
     *
     * Example: 08:00–17:00 with break 12:00–13:00 → 540 raw − 60 break = 480 net (8 h).
     * Night shift (22:00–06:00): timeOut before timeIn on same date is treated as next day.
     */
    public static function getNetWorkedMinutes(
        Carbon $timeIn,
        Carbon $timeOut,
        array $daySchedule,
        string $dateKey,
        ?string $tz = null
    ): int {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));
        $out = $timeOut->copy();
        $in = trim((string) ($daySchedule['in'] ?? ''));
        $schedOut = trim((string) ($daySchedule['out'] ?? ''));
        $isNightShift = $schedOut !== '' && $in !== '' && $schedOut <= $in;
        if ($isNightShift && $out->lessThanOrEqualTo($timeIn)) {
            $out = $out->copy()->addDay();
        }
        // Early clock-in ("early bird") must not extend net worked time — count from scheduled start onward.
        $effectiveIn = $timeIn->copy();
        $schedStart = self::getScheduledStartForDate($dateKey, $daySchedule, $tz);
        if ($schedStart !== null && $effectiveIn->lessThan($schedStart)) {
            $effectiveIn = $schedStart->copy();
        }
        $rawMinutes = max(0, (int) $effectiveIn->diffInMinutes($out));

        $breakStart = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEnd = trim((string) ($daySchedule['break_end'] ?? ''));

        if ($breakStart === '' || $breakEnd === '' ||
            ! preg_match('/^\d{1,2}:\d{2}/', $breakStart) ||
            ! preg_match('/^\d{1,2}:\d{2}/', $breakEnd)) {
            return $rawMinutes;
        }

        $breakStartC = Carbon::parse($dateKey.' '.substr($breakStart, 0, 5), $tz);
        $breakEndC = Carbon::parse($dateKey.' '.substr($breakEnd, 0, 5), $tz);

        if ($breakEndC->lessThanOrEqualTo($breakStartC)) {
            $breakEndC->addDay();
        }

        // Intersect [effectiveIn, timeOut] ∩ [breakStart, breakEnd].
        $overlapStart = $effectiveIn->greaterThan($breakStartC) ? $effectiveIn->copy() : $breakStartC->copy();
        $overlapEnd = $out->lessThan($breakEndC) ? $out->copy() : $breakEndC->copy();

        if ($overlapEnd->greaterThan($overlapStart)) {
            return max(0, $rawMinutes - (int) $overlapStart->diffInMinutes($overlapEnd));
        }

        return $rawMinutes;
    }

    /**
     * Whether the current time is past the scheduled end for the given day (in attendance timezone).
     * Used for undertime / shift end checks. For "absent" use isPastAbsentCutoff (e.g. 5 PM).
     */
    public static function isPastShiftEnd(array $daySchedule, string $dateKey, Carbon $now): bool
    {
        $scheduledEnd = self::getScheduledEndForDate($dateKey, $daySchedule);
        if ($scheduledEnd === null) {
            return true;
        }
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $now = $now->copy()->timezone($tz);

        return $now->greaterThanOrEqualTo($scheduledEnd);
    }

    /**
     * Undertime minutes when time out is earlier than scheduled end.
     *
     * Time-out rules (schedule end e.g. 5:00 PM):
     * - If employee logs out before 5:00 PM → Status = Undertime.
     * - Undertime Minutes = 5:00 PM − Actual Time-Out (e.g. 4:50 PM → 10 min, 4:00 PM → 60 min).
     * - No time-out: business rule may treat as Incomplete or Undertime (handled by caller).
     *
     * Returns 0 if no scheduled end or actual time out, or when time out >= scheduled end,
     * or when time out is within early_timeout_minutes before scheduled end (optional).
     *
     * @param  int|null  $earlyTimeoutMinutes  If set, time-out within this many minutes before scheduled end is not undertime (e.g. 15 = allow up to 15 min early with no undertime).
     */
    public static function getUndertimeMinutes(?Carbon $scheduledEnd, $actualTimeOut, ?int $earlyTimeoutMinutes = null): int
    {
        if (! $scheduledEnd || ! $actualTimeOut) {
            return 0;
        }
        $out = $actualTimeOut instanceof Carbon ? $actualTimeOut->copy() : Carbon::parse($actualTimeOut);

        if ($out->greaterThanOrEqualTo($scheduledEnd)) {
            return 0;
        }

        if ($earlyTimeoutMinutes !== null && $earlyTimeoutMinutes > 0) {
            $effectiveEnd = $scheduledEnd->copy()->subMinutes($earlyTimeoutMinutes);
            if ($out->greaterThanOrEqualTo($effectiveEnd)) {
                return 0;
            }
        }

        return (int) $out->diffInMinutes($scheduledEnd, false);
    }

    /**
     * Schedule-aware undertime: required_working_minutes − net_worked_minutes.
     *
     * Unlike {@see getUndertimeMinutes} (scheduledEnd − clockOut), this method
     * subtracts unpaid break time so the shortfall reflects actual missed paid
     * hours only.  Example: 08:00–09:42 on an 08:00–17:00 schedule with 1 h
     * break → required 480 − worked 102 = 378 min (not 438).
     *
     * @param  int|null  $earlyTimeoutMinutes  Grace window before scheduled end (no undertime within this buffer).
     */
    public static function getScheduleAwareUndertimeMinutes(
        string $dateKey,
        array $daySchedule,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        ?string $tz = null,
        ?int $earlyTimeoutMinutes = null
    ): int {
        if (! $timeIn || ! $timeOut) {
            return 0;
        }

        $scheduledEnd = self::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            return 0;
        }

        $out = $timeOut instanceof Carbon ? $timeOut->copy() : Carbon::parse($timeOut);

        if ($out->greaterThanOrEqualTo($scheduledEnd)) {
            return 0;
        }

        if ($earlyTimeoutMinutes !== null && $earlyTimeoutMinutes > 0) {
            $effectiveEnd = $scheduledEnd->copy()->subMinutes($earlyTimeoutMinutes);
            if ($out->greaterThanOrEqualTo($effectiveEnd)) {
                return 0;
            }
        }

        $requiredMinutes = self::getRequiredWorkingMinutes($dateKey, $daySchedule, $tz);
        if ($requiredMinutes <= 0) {
            return 0;
        }

        $netWorked = self::getNetWorkedMinutes($timeIn, $timeOut, $daySchedule, $dateKey, $tz);

        return max(0, $requiredMinutes - $netWorked);
    }

    /**
     * Human-readable premium flag for display (e.g. "OT + ND on Rest Day").
     * Used in Employee dashboard and Admin attendance views.
     *
     * @param  string|null  $premiumType  ordinary, rest_day, special_holiday, regular_holiday, etc.
     */
    public static function getPremiumDescription(?float $overtimeHours, ?float $nightHours, ?string $premiumType): string
    {
        $parts = [];
        if (($overtimeHours ?? 0) > 0) {
            $parts[] = 'OT';
        }
        if (($nightHours ?? 0) > 0) {
            $parts[] = 'ND';
        }
        if (empty($parts)) {
            return '';
        }
        $prefix = implode(' + ', $parts);
        $premiumLabels = [
            'ordinary' => 'Ordinary Day',
            'rest_day' => 'Rest Day',
            'special_holiday' => 'Special Holiday',
            'regular_holiday' => 'Regular Holiday',
            'special_holiday_rest_day' => 'Special Holiday + Rest Day',
            'regular_holiday_rest_day' => 'Regular Holiday + Rest Day',
        ];
        $dayLabel = $premiumLabels[$premiumType ?? ''] ?? ($premiumType ?: 'Ordinary Day');

        return $prefix.' on '.$dayLabel;
    }

    /**
     * Build the datetime for an approved overtime end (expected_end_time / time_out) on an office date.
     * Matches night-shift handling used when validating manual corrections and extending clock-out windows.
     */
    public static function resolveOvertimeEndForOfficeDate(
        string $dateKey,
        ?array $todaySchedule,
        string $tz,
        mixed $otEnd
    ): Carbon {
        $timeStr = $otEnd instanceof CarbonInterface
            ? $otEnd->format('H:i:s')
            : trim((string) $otEnd);
        if ($timeStr === '' || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeStr)) {
            $timeStr = Carbon::parse($otEnd)->format('H:i:s');
        }
        $expectedEnd = Carbon::parse($dateKey.' '.$timeStr, $tz);
        if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
            $schStart = Carbon::parse($dateKey.' '.trim((string) $todaySchedule['in']), $tz);
            $schEnd = Carbon::parse($dateKey.' '.trim((string) $todaySchedule['out']), $tz);
            if ($schEnd->lessThanOrEqualTo($schStart)) {
                $schEnd->addDay();
            }
            $overnightShift = $schEnd->toDateString() !== $schStart->toDateString();
            if ($overnightShift && $expectedEnd->lessThanOrEqualTo($schStart)) {
                $expectedEnd->addDay();
            }
        }

        return $expectedEnd;
    }

    /**
     * Approved OT end time for display/virtual clock-out: expected_end_time or time_out, else schedule end + computed OT minutes.
     * Keeps employee self-service aligned with admin when legacy rows lack explicit end times.
     */
    public static function resolveApprovedOvertimeVirtualEnd(
        Overtime $ot,
        string $dateKey,
        ?array $daySchedule,
        string $tz
    ): ?Carbon {
        if ($ot->status !== Overtime::STATUS_APPROVED) {
            return null;
        }

        $primary = $ot->expected_end_time ?? $ot->time_out;
        if ($primary !== null) {
            return self::resolveOvertimeEndForOfficeDate($dateKey, $daySchedule, $tz, $primary);
        }

        $mins = (int) ($ot->computed_minutes ?? 0);
        if ($mins <= 0 && isset($ot->computed_hours)) {
            $mins = (int) round((float) $ot->computed_hours * 60);
        }
        if ($mins <= 0) {
            return null;
        }

        $anchor = null;
        if (is_array($daySchedule) && ! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
            $anchor = self::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        }
        if ($anchor === null && $ot->schedule_end !== null) {
            $schedEnd = $ot->schedule_end;
            $timeStr = $schedEnd instanceof CarbonInterface
                ? $schedEnd->format('H:i:s')
                : trim((string) $schedEnd);
            if ($timeStr === '' || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeStr)) {
                return null;
            }
            $anchor = Carbon::parse($dateKey.' '.$timeStr, $tz);
        }
        if ($anchor === null) {
            return null;
        }

        return $anchor->copy()->addMinutes($mins);
    }
}
