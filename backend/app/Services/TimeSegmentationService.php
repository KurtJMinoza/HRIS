<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Time Segmentation — regular vs rendered OT vs ND (10PM–6AM).
 *
 * Default OT basis (config `payroll.ot_basis` = schedule_end):
 * - Rendered OT = net work minutes at/after scheduled shift end (not "net minus 8 hours").
 * - Regular bucket = net work between effective start/end only: max(timeIn, scheduleStart) through
 *   min(timeOut, scheduleEnd), minus break — early clock-in before schedule start does not add to regular.
 *
 * Legacy `eight_hour_net`: OT = max(0, net − 8h) when no schedule end or when explicitly configured.
 *
 * Pure classification — no pay computation.
 */
class TimeSegmentationService
{
    private const REGULAR_HOURS_THRESHOLD = 8;

    private const ND_START_HOUR = 22; // 10:00 PM

    private const ND_END_HOUR = 6;    // 6:00 AM

    /**
     * Segment worked time into regular, overtime, and night differential hours.
     *
     * @param  array<string, mixed>|null  $daySchedule  Per-day schedule (break_start, break_end) when known
     * @param  string|null  $dateKey  Y-m-d for resolving break window in attendance TZ
     * @return array{
     *   regular_minutes: int,
     *   overtime_minutes: int,
     *   night_minutes: int,
     *   total_minutes: int,
     *   regular_hours: float,
     *   overtime_hours: float,
     *   night_hours: float,
     *   nd_regular_minutes: int,
     *   nd_overtime_minutes: int,
     *   nd_regular_hours: float,
     *   nd_overtime_hours: float,
     *   total_hours: float
     * }
     */
    /**
     * @param  int|null  $ndStartHour  Override ND window start (22 = 10PM). When null, uses config.
     * @param  int|null  $ndEndHour  Override ND window end (6 = 6AM). When null, uses config.
     */
    public function segment(
        Carbon $timeIn,
        Carbon $timeOut,
        ?string $tz = null,
        ?array $daySchedule = null,
        ?string $dateKey = null,
        ?int $ndStartHour = null,
        ?int $ndEndHour = null
    ): array {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        [$ndStart, $ndEnd] = $this->resolveNdWindow($ndStartHour, $ndEndHour);

        $in = $timeIn->copy()->timezone($tz);
        $out = $timeOut->copy()->timezone($tz);

        $basis = (string) config('payroll.ot_basis', 'schedule_end');
        $scheduledEnd = null;
        $scheduledStart = null;
        if ($basis === 'schedule_end' && $dateKey && is_array($daySchedule) && trim((string) ($daySchedule['out'] ?? '')) !== '') {
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
            if (trim((string) ($daySchedule['in'] ?? '')) !== '') {
                $scheduledStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $tz);
            }
        }

        $breakWindow = $this->resolveBreakWindow($daySchedule, $dateKey, $tz);

        if ($scheduledEnd !== null && $basis === 'schedule_end') {
            if ($breakWindow !== null) {
                return $this->segmentByScheduleEndWithBreak($in, $out, $tz, $breakWindow[0], $breakWindow[1], $scheduledEnd, $scheduledStart, $ndStart, $ndEnd);
            }

            return $this->segmentByScheduleEndGross($in, $out, $tz, $scheduledEnd, $scheduledStart, $ndStart, $ndEnd);
        }

        if ($breakWindow !== null) {
            return $this->segmentExcludingBreak($in, $out, $tz, $breakWindow[0], $breakWindow[1], $ndStart, $ndEnd);
        }

        return $this->segmentGross($in, $out, $tz, $ndStart, $ndEnd);
    }

    private function resolveNdWindow(?int $ndStartHour, ?int $ndEndHour): array
    {
        $ndConfig = config('payroll.night_differential', []);

        return [
            $ndStartHour ?? (int) ($ndConfig['start_hour'] ?? self::ND_START_HOUR),
            $ndEndHour ?? (int) ($ndConfig['end_hour'] ?? self::ND_END_HOUR),
        ];
    }

    /**
     * OT = work at/after scheduled end; no meal break in path.
     */
    private function segmentByScheduleEndGross(Carbon $in, Carbon $out, string $tz, Carbon $scheduledEnd, ?Carbon $scheduledStart = null, int $ndStart = 22, int $ndEnd = 6): array
    {
        $totalMinutes = max(0, (int) $in->diffInMinutes($out));

        $regularMinutes = 0;
        $overtimeMinutes = 0;
        $nightMinutes = 0;
        $ndRegularMinutes = 0;
        $ndOvertimeMinutes = 0;
        $stepMinutes = 1;
        $cursor = $in->copy();

        for ($m = 0; $m < $totalMinutes; $m += $stepMinutes) {
            $minStep = min($stepMinutes, $totalMinutes - $m);
            if ($scheduledStart !== null && $cursor->lessThan($scheduledStart)) {
                $cursor->addMinutes($minStep);

                continue;
            }
            $isOtMinute = $cursor->greaterThanOrEqualTo($scheduledEnd);
            $hour = (int) $cursor->format('G');
            $isNight = $hour >= $ndStart || $hour < $ndEnd;
            if ($isNight) {
                $nightMinutes += $minStep;
                if ($isOtMinute) {
                    $ndOvertimeMinutes += $minStep;
                } else {
                    $ndRegularMinutes += $minStep;
                }
            }
            if ($isOtMinute) {
                $overtimeMinutes += $minStep;
            } else {
                $regularMinutes += $minStep;
            }
            $cursor->addMinutes($minStep);
        }

        return $this->buildResult(
            $regularMinutes,
            $overtimeMinutes,
            $nightMinutes,
            $ndRegularMinutes,
            $ndOvertimeMinutes,
            $regularMinutes + $overtimeMinutes
        );
    }

    /**
     * OT = net work at/after scheduled end; meal break excluded from work minutes.
     */
    private function segmentByScheduleEndWithBreak(
        Carbon $in,
        Carbon $out,
        string $tz,
        Carbon $breakStart,
        Carbon $breakEnd,
        Carbon $scheduledEnd,
        ?Carbon $scheduledStart = null,
        int $ndStart = 22,
        int $ndEnd = 6
    ): array {
        $grossMinutes = max(0, (int) $in->diffInMinutes($out));

        $regularMinutes = 0;
        $overtimeMinutes = 0;
        $nightMinutes = 0;
        $ndRegularMinutes = 0;
        $ndOvertimeMinutes = 0;

        for ($m = 0; $m < $grossMinutes; $m++) {
            $cursor = $in->copy()->addMinutes($m);
            if ($scheduledStart !== null && $cursor->lessThan($scheduledStart)) {
                continue;
            }
            if ($this->clockMinuteOverlapsBreak($cursor, $breakStart, $breakEnd)) {
                continue;
            }
            $isOtMinute = $cursor->greaterThanOrEqualTo($scheduledEnd);
            $hour = (int) $cursor->format('G');
            $isNight = $hour >= $ndStart || $hour < $ndEnd;
            if ($isNight) {
                $nightMinutes++;
                if ($isOtMinute) {
                    $ndOvertimeMinutes++;
                } else {
                    $ndRegularMinutes++;
                }
            }
            if ($isOtMinute) {
                $overtimeMinutes++;
            } else {
                $regularMinutes++;
            }
        }

        $netWorkMinutes = $regularMinutes + $overtimeMinutes;

        return $this->buildResult(
            $regularMinutes,
            $overtimeMinutes,
            $nightMinutes,
            $ndRegularMinutes,
            $ndOvertimeMinutes,
            $netWorkMinutes
        );
    }

    /**
     * Gross span only (no schedule / no valid break): every minute from time-in to time-out counts as work.
     */
    private function segmentGross(Carbon $in, Carbon $out, string $tz, int $ndStart = 22, int $ndEnd = 6): array
    {
        $totalMinutes = max(0, (int) $in->diffInMinutes($out));

        $threshold = (int) (config('payroll.regular_hours_threshold', self::REGULAR_HOURS_THRESHOLD));
        $regularThresholdMinutes = $threshold * 60;
        $regularMinutes = min($totalMinutes, $regularThresholdMinutes);
        $overtimeMinutes = max(0, $totalMinutes - $regularThresholdMinutes);

        $nightMinutes = 0;
        $ndRegularMinutes = 0;
        $ndOvertimeMinutes = 0;
        $cursor = $in->copy();
        $stepMinutes = 1;

        for ($m = 0; $m < $totalMinutes; $m += $stepMinutes) {
            $minStep = min($stepMinutes, $totalMinutes - $m);
            $hour = (int) $cursor->format('G');
            $isNight = $hour >= $ndStart || $hour < $ndEnd;
            if ($isNight) {
                $nightMinutes += $minStep;
                if ($m < $regularThresholdMinutes) {
                    $ndRegularMinutes += $minStep;
                } else {
                    $ndOvertimeMinutes += $minStep;
                }
            }
            $cursor->addMinutes($minStep);
        }

        return $this->buildResult(
            $regularMinutes,
            $overtimeMinutes,
            $nightMinutes,
            $ndRegularMinutes,
            $ndOvertimeMinutes,
            $totalMinutes
        );
    }

    /**
     * Skip minutes that fall inside the scheduled meal break; first 8 *net* work hours = regular.
     */
    private function segmentExcludingBreak(Carbon $in, Carbon $out, string $tz, Carbon $breakStart, Carbon $breakEnd, int $ndStart = 22, int $ndEnd = 6): array
    {
        $grossMinutes = max(0, (int) $in->diffInMinutes($out));

        $threshold = (int) (config('payroll.regular_hours_threshold', self::REGULAR_HOURS_THRESHOLD));
        $regularThresholdMinutes = $threshold * 60;

        $nightMinutes = 0;
        $ndRegularMinutes = 0;
        $ndOvertimeMinutes = 0;
        $workIndex = 0;

        for ($m = 0; $m < $grossMinutes; $m++) {
            $cursor = $in->copy()->addMinutes($m);
            if ($this->clockMinuteOverlapsBreak($cursor, $breakStart, $breakEnd)) {
                continue;
            }

            $hour = (int) $cursor->format('G');
            $isNight = $hour >= $ndStart || $hour < $ndEnd;
            if ($isNight) {
                $nightMinutes++;
                if ($workIndex < $regularThresholdMinutes) {
                    $ndRegularMinutes++;
                } else {
                    $ndOvertimeMinutes++;
                }
            }
            $workIndex++;
        }

        $netWorkMinutes = $workIndex;
        $regularMinutes = min($netWorkMinutes, $regularThresholdMinutes);
        $overtimeMinutes = max(0, $netWorkMinutes - $regularThresholdMinutes);

        return $this->buildResult(
            $regularMinutes,
            $overtimeMinutes,
            $nightMinutes,
            $ndRegularMinutes,
            $ndOvertimeMinutes,
            $netWorkMinutes
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function resolveBreakWindow(?array $daySchedule, ?string $dateKey, string $tz): ?array
    {
        if ($daySchedule === null || $dateKey === null || $dateKey === '') {
            return null;
        }

        $breakStart = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEnd = trim((string) ($daySchedule['break_end'] ?? ''));

        if ($breakStart === '' || $breakEnd === ''
            || ! preg_match('/^\d{1,2}:\d{2}/', $breakStart)
            || ! preg_match('/^\d{1,2}:\d{2}/', $breakEnd)) {
            return null;
        }

        $breakStartC = Carbon::parse($dateKey.' '.substr($breakStart, 0, 5), $tz);
        $breakEndC = Carbon::parse($dateKey.' '.substr($breakEnd, 0, 5), $tz);
        if ($breakEndC->lessThanOrEqualTo($breakStartC)) {
            $breakEndC->addDay();
        }

        return [$breakStartC, $breakEndC];
    }

    /**
     * True if the one-minute window [cursor, cursor+1) overlaps the break interval.
     */
    private function clockMinuteOverlapsBreak(Carbon $cursor, Carbon $breakStart, Carbon $breakEnd): bool
    {
        $minuteEnd = $cursor->copy()->addMinute();

        return $cursor->lessThan($breakEnd) && $minuteEnd->greaterThan($breakStart);
    }

    private function buildResult(
        int $regularMinutes,
        int $overtimeMinutes,
        int $nightMinutes,
        int $ndRegularMinutes,
        int $ndOvertimeMinutes,
        int $totalWorkMinutes
    ): array {
        return [
            'regular_minutes' => $regularMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'night_minutes' => $nightMinutes,
            'nd_regular_minutes' => $ndRegularMinutes,
            'nd_overtime_minutes' => $ndOvertimeMinutes,
            'total_minutes' => $totalWorkMinutes,
            'regular_hours' => round($regularMinutes / 60, 2),
            'overtime_hours' => round($overtimeMinutes / 60, 2),
            'night_hours' => round($nightMinutes / 60, 2),
            'nd_regular_hours' => round($ndRegularMinutes / 60, 2),
            'nd_overtime_hours' => round($ndOvertimeMinutes / 60, 2),
            'total_hours' => round($totalWorkMinutes / 60, 2),
        ];
    }

    /**
     * Segment when no time in/out (absent, leave, etc.) – returns zeros.
     */
    public function segmentEmpty(): array
    {
        return [
            'regular_minutes' => 0,
            'overtime_minutes' => 0,
            'night_minutes' => 0,
            'nd_regular_minutes' => 0,
            'nd_overtime_minutes' => 0,
            'total_minutes' => 0,
            'regular_hours' => 0.0,
            'overtime_hours' => 0.0,
            'night_hours' => 0.0,
            'nd_regular_hours' => 0.0,
            'nd_overtime_hours' => 0.0,
            'total_hours' => 0.0,
        ];
    }
}
