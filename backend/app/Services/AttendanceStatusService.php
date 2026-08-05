<?php

namespace App\Services;

use App\Models\Overtime;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Attendance lateness and half-day computation.
 *
 * Late bands (30-min buckets from scheduled start + grace):
 *   Present → 30 min → 1 hr → 1.5 hr → 2 hr → 2.5 hr → 3 hr → 3.5 hr → 4 hr late.
 *
 * Half Day: triggered ONLY when lateness >= half_day_threshold_minutes
 *   (= scheduled_paid_minutes / 2, typically 240 min for an 8 h shift).
 *   Never based on clock-in time-of-day or hardcoded windows.
 */
class AttendanceStatusService
{
    /** Default grace minutes when not in schedule; fallback after config. */
    public const DEFAULT_GRACE_MINUTES = 5;

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

    /**
     * Paid regular cap for half-day classification.
     * Uses schedule's half_day_threshold_minutes if available, else falls back to
     * scheduled_paid_minutes / 2, then config (default 4h = 240m).
     */
    public static function getHalfDayRegularCapMinutes(?array $daySchedule = null): int
    {
        if ($daySchedule !== null) {
            $explicit = $daySchedule['half_day_threshold_minutes'] ?? null;
            if ($explicit !== null && (int) $explicit > 0) {
                return (int) $explicit;
            }

            $paidMinutes = $daySchedule['expected_paid_minutes'] ?? null;
            if ($paidMinutes !== null && (int) $paidMinutes > 0) {
                return (int) floor((int) $paidMinutes / 2);
            }
        }

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
     *
     * Half Day is determined ONLY by lateness >= half_day_threshold (scheduled_paid_minutes / 2).
     * The old hardcoded noon clock-in window (12:00–13:00) was removed because it incorrectly
     * classified afternoon-shift employees (e.g. 12 PM start) as Half Day on normal clock-ins.
     *
     * @return array{status: 'on_time'|'late'|'half_day', late_minutes: int, late_label: string}
     */
    public static function getClockInStatus(array $daySchedule, string $dateKey, Carbon $actualTimeIn): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $actualTimeIn = $actualTimeIn->copy()->timezone($tz);
        $dateKey = $actualTimeIn->format('Y-m-d');
        $daySchedule = self::resolveFlexibleShiftForActualTimes($dateKey, $daySchedule, $actualTimeIn, null, $tz);

        $graceMinutes = self::getGraceMinutes($daySchedule);
        $scheduleIn = trim((string) ($daySchedule['in'] ?? '08:00'));
        if (strpos($scheduleIn, ':') === false) {
            $scheduleIn = '08:00';
        }
        $scheduledStart = Carbon::parse($dateKey.' '.$scheduleIn, $tz);

        $actualMinutesFromMidnight = (int) $actualTimeIn->format('G') * 60 + (int) $actualTimeIn->format('i');
        $scheduledMinutesFromMidnight = (int) $scheduledStart->format('G') * 60 + (int) $scheduledStart->format('i');

        $graceCutoffMinutes = $scheduledMinutesFromMidnight + $graceMinutes;
        if ($actualMinutesFromMidnight <= $graceCutoffMinutes) {
            return [
                'status' => 'on_time',
                'late_minutes' => 0,
                'late_label' => 'Present',
            ];
        }

        $rawMinutesLate = $actualMinutesFromMidnight - $scheduledMinutesFromMidnight;

        $halfDayThreshold = self::getHalfDayRegularCapMinutes($daySchedule);
        if ($rawMinutesLate >= $halfDayThreshold) {
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
     * Required working minutes for the day. Delegates to ScheduleComputationService
     * for consistent multi-break and shift-type support.
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array  $daySchedule
     */
    public static function getRequiredWorkingMinutes(string $dateKey, array $daySchedule, ?string $tz = null): int
    {
        $in = $daySchedule['in'] ?? null;
        $out = $daySchedule['out'] ?? null;
        if (($in === null || $in === '') && ($daySchedule['shift_type'] ?? 'fixed') !== 'split') {
            return 0;
        }

        $explicitPaid = $daySchedule['expected_paid_minutes'] ?? null;
        if ($explicitPaid !== null && (int) $explicitPaid > 0) {
            return (int) $explicitPaid;
        }

        return app(ScheduleComputationService::class)->requiredWorkingMinutes($dateKey, $daySchedule, $tz);
    }

    /**
     * Net worked minutes between timeIn and timeOut, subtracting overlap with unpaid breaks.
     * Delegates to ScheduleComputationService for multi-break support.
     */
    public static function getNetWorkedMinutes(
        Carbon $timeIn,
        Carbon $timeOut,
        array $daySchedule,
        string $dateKey,
        ?string $tz = null
    ): int {
        $daySchedule = self::resolveFlexibleShiftForActualTimes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        return app(ScheduleComputationService::class)->netWorkedMinutes(
            $timeIn,
            $timeOut,
            $daySchedule,
            $dateKey,
            $tz,
            false,
        );
    }

    /**
     * Schedule-clipped net worked minutes: clips clock-in to schedule start so
     * pre-shift time does not count toward the regular hour requirement.
     * Delegates to ScheduleComputationService for multi-break support.
     */
    public static function getScheduleClippedNetWorkedMinutes(
        Carbon $timeIn,
        Carbon $timeOut,
        array $daySchedule,
        string $dateKey,
        ?string $tz = null
    ): int {
        $daySchedule = self::resolveFlexibleShiftForActualTimes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        return app(ScheduleComputationService::class)->netWorkedMinutes(
            $timeIn,
            $timeOut,
            $daySchedule,
            $dateKey,
            $tz,
            true,
        );
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

        $daySchedule = self::resolveFlexibleShiftForActualTimes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);
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

        $netWorked = self::getScheduleClippedNetWorkedMinutes($timeIn, $timeOut, $daySchedule, $dateKey, $tz);

        return max(0, $requiredMinutes - $netWorked);
    }

    /**
     * Raw clock-based OT minutes (pre-shift before scheduled start + post-shift after end + buffer).
     * Used by dashboard, reports, and {@see AttendanceStatusResolver}.
     *
     * @return array{total_minutes: int, pre_minutes: int, post_minutes: int}
     */
    public static function computeRawOvertimeBreakdown(
        string $dateKey,
        array $daySchedule,
        Carbon $timeIn,
        Carbon $timeOut,
        ?string $tz = null,
    ): array {
        $daySchedule = self::resolveFlexibleShiftForActualTimes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);
        $preMinutes = 0;
        $postMinutes = 0;

        if (empty($daySchedule['in']) || empty($daySchedule['out'])) {
            return ['total_minutes' => 0, 'pre_minutes' => 0, 'post_minutes' => 0];
        }

        $scheduledStart = self::getScheduledStartForDate($dateKey, $daySchedule, $tz);
        if ($scheduledStart && $timeIn->lessThan($scheduledStart)) {
            $preMinutes = (int) $timeIn->diffInMinutes($scheduledStart);
        }

        $scheduledEnd = self::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if ($scheduledEnd) {
            $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
                ? (int) $daySchedule['overtime_buffer_minutes']
                : (int) config('attendance.overtime_buffer_minutes', 15);
            $postShiftOtStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
            if ($timeOut->greaterThan($postShiftOtStart)) {
                $postMinutes = (int) $postShiftOtStart->diffInMinutes($timeOut);
            }
        }

        return [
            'total_minutes' => $preMinutes + $postMinutes,
            'pre_minutes' => $preMinutes,
            'post_minutes' => $postMinutes,
        ];
    }

    private static function resolveFlexibleShiftForActualTimes(
        string $dateKey,
        array $daySchedule,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        ?string $tz = null,
    ): array {
        if (($daySchedule['shift_type'] ?? 'fixed') !== 'flexible'
            || empty($daySchedule['flexible_shift_options'])
            || ! is_array($daySchedule['flexible_shift_options'])) {
            return $daySchedule;
        }

        if (in_array(($daySchedule['match_source'] ?? null), ['automatic', 'schedule_adjustment'], true)) {
            return $daySchedule;
        }

        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'UTC'));

        return app(ScheduleComputationService::class)
            ->resolveFlexibleShiftForAttendance($dateKey, $daySchedule, $timeIn, $timeOut, $tz)['schedule'];
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

    /**
     * Find the first non-rest day schedule entry that has break_start/break_end/breaks defined.
     * Used by modules that need break-deduction context even on rest days (rest day worked).
     */
    public static function firstScheduleWithBreaks(?array $effectiveSchedule): ?array
    {
        if (! is_array($effectiveSchedule)) {
            return null;
        }
        foreach ($effectiveSchedule as $dayKey => $schedule) {
            if (is_array($schedule) && (
                ! empty($schedule['break_start']) ||
                ! empty($schedule['break_end']) ||
                ! empty($schedule['breaks'])
            )) {
                return $schedule;
            }
        }
        return null;
    }

    /**
     * Find the first workday schedule (non-rest day) with in/out defined.
     * Used as a reference schedule for rest day attendance computation,
     * so rest days use the same late/undertime/OT detection as workdays.
     */
    public static function firstWorkdaySchedule(?array $effectiveSchedule): ?array
    {
        if (! is_array($effectiveSchedule)) {
            return null;
        }
        foreach ($effectiveSchedule as $dayKey => $schedule) {
            if (is_array($schedule) && ! empty($schedule['in']) && ! empty($schedule['out'])) {
                return $schedule;
            }
        }
        return null;
    }
}
