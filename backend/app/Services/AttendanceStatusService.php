<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Grace period, late buckets, half-day (12:00 PM rule), and undertime.
 * - Schedule start (e.g. 8:00) + grace (5 min) → at or before 8:05 = Present.
 * - 8:06–8:29 → 30 min late; 8:30–8:59 → 1 hr late; 9:00–9:29 → 1 hr 30 min late;
 *   9:30–9:59 → 2 hr late; 10:00–10:29 → 2 hr 30 min late; 10:30–10:59 → 3 hr late;
 *   11:00–11:29 → 3 hr 30 min late; 11:30–11:59 → 4 hr late.
 * - Clock-in at or after 12:00 PM (noon) → Half Day.
 * - No clock-in by end of shift → Absent.
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
        $g = $daySchedule['grace_minutes'] ?? $daySchedule['grace'] ?? null;
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
     * Late buckets by raw minutes late (from schedule start).
     * 8:06–8:29 → 30 min; 8:30–8:59 → 1 hr; 9:00–9:29 → 1 hr 30 min; 9:30–9:59 → 2 hr;
     * 10:00–10:29 → 2 hr 30 min; 10:30–10:59 → 3 hr; 11:00–11:29 → 3 hr 30 min; 11:30–11:59 → 4 hr.
     *
     * @return array{minutes: int, label: string}
     */
    public static function getLateBucket(int $rawMinutesLate): array
    {
        if ($rawMinutesLate <= 0) {
            return ['minutes' => 0, 'label' => 'On Time'];
        }
        if ($rawMinutesLate <= 29) {
            return ['minutes' => 30, 'label' => '30 Minutes Late'];
        }
        if ($rawMinutesLate <= 59) {
            return ['minutes' => 60, 'label' => '1 Hour Late'];
        }
        if ($rawMinutesLate <= 89) {
            return ['minutes' => 90, 'label' => '1 Hour 30 Minutes Late'];
        }
        if ($rawMinutesLate <= 119) {
            return ['minutes' => 120, 'label' => '2 Hours Late'];
        }
        if ($rawMinutesLate <= 149) {
            return ['minutes' => 150, 'label' => '2 Hours 30 Minutes Late'];
        }
        if ($rawMinutesLate <= 179) {
            return ['minutes' => 180, 'label' => '3 Hours Late'];
        }
        if ($rawMinutesLate <= 209) {
            return ['minutes' => 210, 'label' => '3 Hours 30 Minutes Late'];
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
     * Time-in rules (schedule 8:00 AM – 5:00 PM, grace 5 min):
     * - At or before 8:05 AM → Present.
     * - 8:06–8:29 → 30 min late; 8:30–8:59 → 1 hr late; 9:00–9:29 → 1 hr 30 min; 9:30–9:59 → 2 hr;
     *   10:00–10:29 → 2 hr 30 min; 10:30–10:59 → 3 hr; 11:00–11:29 → 3 hr 30 min; 11:30–11:59 → 4 hr late.
     * - 12:00 PM (noon) and later → Half-Day.
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
        $halfDayHour = self::getHalfDayStartHour();
        $scheduleIn = trim((string) ($daySchedule['in'] ?? '08:00'));
        if (strpos($scheduleIn, ':') === false) {
            $scheduleIn = '08:00';
        }
        $scheduledStart = Carbon::parse($dateKey . ' ' . $scheduleIn, $tz);

        // Minutes from midnight (same timezone) for explicit comparison
        $actualMinutesFromMidnight = (int) $actualTimeIn->format('G') * 60 + (int) $actualTimeIn->format('i');
        $scheduledMinutesFromMidnight = (int) $scheduledStart->format('G') * 60 + (int) $scheduledStart->format('i');

        // 1) Half-day rule: clock-in at or after 12:00 PM (noon) in business timezone
        $halfDayThresholdMinutes = $halfDayHour * 60;
        if ($actualMinutesFromMidnight >= $halfDayThresholdMinutes) {
            return [
                'status' => 'half_day',
                'late_minutes' => 0,
                'late_label' => 'Half Day',
            ];
        }

        // 2) Present vs Late: grace period ends at scheduled_start + grace (e.g. 8:05). After that → late brackets.
        $graceCutoffMinutes = $scheduledMinutesFromMidnight + $graceMinutes;
        if ($actualMinutesFromMidnight <= $graceCutoffMinutes) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
                'late_label' => 'On Time',
            ];
        }

        // 3) Late: raw minutes late = actual - scheduled start; map to configured brackets
        $rawMinutesLate = $actualMinutesFromMidnight - $scheduledMinutesFromMidnight;
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
     * @return \Carbon\Carbon|null
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
            return Carbon::parse(Carbon::parse($dateKey)->addDay()->toDateString() . ' ' . $out, $tz);
        }

        return Carbon::parse($dateKey . ' ' . $out, $tz);
    }

    /**
     * Required working minutes for the day: (scheduled end − scheduled start) − break duration.
     * Break time (e.g. 12:00–1:00) is unpaid and must be deducted so total = 8 hours, not 9.
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array{in?: string, out?: string, break_start?: string|null, break_end?: string|null}  $daySchedule
     * @return int
     */
    public static function getRequiredWorkingMinutes(string $dateKey, array $daySchedule, ?string $tz = null): int
    {
        $in = $daySchedule['in'] ?? null;
        $out = $daySchedule['out'] ?? null;
        if ($in === null || $in === '' || $out === null || $out === '') {
            return 0;
        }
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));
        $scheduledStart = Carbon::parse($dateKey . ' ' . $in, $tz);
        $scheduledEnd = self::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            return 0;
        }
        $totalSpanMinutes = (int) $scheduledStart->diffInMinutes($scheduledEnd);

        $breakStart = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEnd = trim((string) ($daySchedule['break_end'] ?? ''));
        if ($breakStart !== '' && $breakEnd !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $breakStart) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $breakEnd)) {
            $breakStartCarbon = Carbon::parse($dateKey . ' ' . substr($breakStart, 0, 5), $tz);
            $breakEndCarbon = Carbon::parse($dateKey . ' ' . substr($breakEnd, 0, 5), $tz);
            if ($breakEndCarbon->lessThanOrEqualTo($breakStartCarbon)) {
                $breakEndCarbon->addDay();
            }
            $breakMinutes = (int) $breakStartCarbon->diffInMinutes($breakEndCarbon);
            $totalSpanMinutes = max(0, $totalSpanMinutes - $breakMinutes);
        }

        return $totalSpanMinutes;
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
}
