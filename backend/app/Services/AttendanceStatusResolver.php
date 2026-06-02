<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\Carbon;

/**
 * Single source of truth for calendar/day attendance status.
 *
 * Priority (after holiday / leave / rest):
 * Missing clock-out → Absent → Undertime → Late → Present with approved/payable OT → Present.
 *
 * {@see STATUS_PRESENT_WITH_OT} requires approved_ot_hours or payable_ot_hours from an approved OT request.
 * Raw/rendered/unapproved OT minutes alone never change the status badge.
 */
class AttendanceStatusResolver
{
    public const STATUS_HOLIDAY = 'holiday';
    public const STATUS_LEAVE = 'leave';
    public const STATUS_REST = 'rest';
    public const STATUS_PRESENT = 'present';
    public const STATUS_PRESENT_WITH_OT = 'present_with_ot';
    public const STATUS_LATE = 'late';
    public const STATUS_HALFDAY = 'halfday';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_UNDERTIME = 'undertime';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_CLOCKED_IN = 'clocked_in';
    public const STATUS_UPCOMING = 'upcoming';

    public const REST_DAY_LABEL = 'Rest Day';

    public function __construct(
        private readonly AttendancePresenceDisplayService $presenceDisplay,
    ) {}

    /**
     * Resolve calendar day status string for a given date, schedule, logs, corrections, leave, holiday.
     *
     * @return array{
     *     status: string,
     *     presence_label: ?string,
     *     presence_issue: string,
     *     effective_time_in: mixed,
     *     effective_time_out: mixed,
     *     effective_worked_minutes: ?int,
     *     has_time_in: bool,
     *     has_time_out: bool,
     *     late_minutes: int,
     *     late_label: ?string,
     *     undertime_minutes: int,
     *     overtime_minutes: int,
     *     status_label: string,
     *     status_code: string,
     *     approved_ot_hours: float,
     *     payable_ot_hours: float,
     * }
     */
    public function resolve(
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $effectiveSchedule,
        ?array $daySchedule,
        ?array $dayLogs,
        ?AttendanceCorrection $correction,
        ?array $holiday,
        ?LeaveRequest $leave,
        bool $isRestDay,
        bool $isFuture,
        ?array $overtimeContext = null,
    ): array {
        $timeIn = null;
        $timeOut = null;
        $workedMinutes = null;
        $hasTimeIn = false;
        $hasTimeOut = false;

        if ($dayLogs !== null) {
            foreach ($dayLogs as $log) {
                $type = $log instanceof AttendanceLog ? $log->type : ($log['type'] ?? '');
                $rawStamp = $log instanceof AttendanceLog
                    ? ($log->verified_at ?? $log->created_at)
                    : ($log['verified_at'] ?? $log['created_at'] ?? null);
                if ($rawStamp === null || $rawStamp === '') {
                    continue;
                }
                $stamp = $rawStamp instanceof Carbon
                    ? $rawStamp->copy()->timezone($nowTz->getTimezone())
                    : Carbon::parse($rawStamp, $nowTz->getTimezone())->timezone($nowTz->getTimezone());

                if ($type === AttendanceLog::TYPE_CLOCK_IN) {
                    if ($timeIn === null) {
                        $timeIn = $stamp;
                    }
                } elseif ($type === AttendanceLog::TYPE_CLOCK_OUT) {
                    $timeOut = $stamp;
                }
            }
            $hasTimeIn = $timeIn !== null;
            $hasTimeOut = $timeOut !== null;
        }

        $effectiveTimeIn = $timeIn;
        $effectiveTimeOut = $timeOut;
        $effectiveWorkedMinutes = $workedMinutes;

        if ($correction && $correction->approved) {
            if ($correction->time_in) {
                $effectiveTimeIn = $correction->time_in;
                $hasTimeIn = true;
            }
            if ($correction->time_out) {
                $effectiveTimeOut = $correction->time_out;
                $hasTimeOut = true;
            }
        }

        if ($hasTimeIn && $hasTimeOut && $effectiveTimeIn && $effectiveTimeOut) {
            $in = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
            $out = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
            if ($out->greaterThan($in)) {
                $effectiveWorkedMinutes = is_array($daySchedule)
                    ? AttendanceStatusService::getNetWorkedMinutes($in, $out, $daySchedule, $dateKey, $nowTz->getTimezone()->getName())
                    : (int) $in->diffInMinutes($out);
            }
        }

        $metrics = $this->computeWorkdayMetrics(
            $dateKey,
            $nowTz,
            $daySchedule,
            $effectiveTimeIn,
            $effectiveTimeOut,
            $hasTimeIn,
            $hasTimeOut,
        );

        // Priority 1: Holiday
        if ($holiday !== null) {
            return $this->buildResult(
                status: self::STATUS_HOLIDAY,
                dateKey: $dateKey,
                todayDate: $todayDate,
                nowTz: $nowTz,
                daySchedule: $daySchedule,
                effectiveTimeIn: $effectiveTimeIn,
                effectiveTimeOut: $effectiveTimeOut,
                effectiveWorkedMinutes: $effectiveWorkedMinutes,
                hasTimeIn: $hasTimeIn,
                hasTimeOut: $hasTimeOut,
                correction: $correction,
                isFuture: $isFuture,
                metrics: $metrics,
                overtimeContext: $overtimeContext,
            );
        }

        // Priority 2: Approved Leave (overrides rest day)
        if ($leave) {
            return $this->buildResult(
                status: self::STATUS_LEAVE,
                dateKey: $dateKey,
                todayDate: $todayDate,
                nowTz: $nowTz,
                daySchedule: $daySchedule,
                effectiveTimeIn: $effectiveTimeIn,
                effectiveTimeOut: $effectiveTimeOut,
                effectiveWorkedMinutes: $effectiveWorkedMinutes,
                hasTimeIn: $hasTimeIn,
                hasTimeOut: $hasTimeOut,
                correction: $correction,
                isFuture: $isFuture,
                metrics: $metrics,
                overtimeContext: $overtimeContext,
            );
        }

        // Priority 3: Rest Day
        if ($isRestDay) {
            return $this->buildResult(
                status: self::STATUS_REST,
                dateKey: $dateKey,
                todayDate: $todayDate,
                nowTz: $nowTz,
                daySchedule: $daySchedule,
                effectiveTimeIn: null,
                effectiveTimeOut: null,
                effectiveWorkedMinutes: null,
                hasTimeIn: false,
                hasTimeOut: false,
                correction: null,
                isFuture: $isFuture,
                metrics: $this->emptyMetrics(),
                overtimeContext: $overtimeContext,
            );
        }

        $status = $this->resolveAttendanceStatus(
            dateKey: $dateKey,
            todayDate: $todayDate,
            nowTz: $nowTz,
            daySchedule: $daySchedule,
            effectiveTimeIn: $effectiveTimeIn,
            effectiveTimeOut: $effectiveTimeOut,
            hasTimeIn: $hasTimeIn,
            hasTimeOut: $hasTimeOut,
            isFuture: $isFuture,
            metrics: $metrics,
            overtimeContext: $overtimeContext,
        );

        return $this->buildResult(
            status: $status,
            dateKey: $dateKey,
            todayDate: $todayDate,
            nowTz: $nowTz,
            daySchedule: $daySchedule,
            effectiveTimeIn: $effectiveTimeIn,
            effectiveTimeOut: $effectiveTimeOut,
            effectiveWorkedMinutes: $effectiveWorkedMinutes,
            hasTimeIn: $hasTimeIn,
            hasTimeOut: $hasTimeOut,
            correction: $correction,
            isFuture: $isFuture,
            metrics: $metrics,
            overtimeContext: $overtimeContext,
        );
    }

    /**
     * Approved or payable OT from an approved request — required for {@see STATUS_PRESENT_WITH_OT}.
     *
     * @param  array{approved_ot_hours?: float|int|null, payable_ot_hours?: float|int|null}|null  $overtimeContext
     */
    public static function hasApprovedOrPayableOvertime(?array $overtimeContext): bool
    {
        if ($overtimeContext === null) {
            return false;
        }

        $approved = (float) ($overtimeContext['approved_ot_hours'] ?? 0);
        $payable = (float) ($overtimeContext['payable_ot_hours'] ?? 0);

        return $approved > 0.0001 || $payable > 0.0001;
    }

    /**
     * @return array{late_minutes: int, late_label: ?string, undertime_minutes: int, overtime_minutes: int, clock_in_status: string}
     */
    public function computeWorkdayMetrics(
        string $dateKey,
        Carbon $nowTz,
        ?array $daySchedule,
        mixed $effectiveTimeIn,
        mixed $effectiveTimeOut,
        bool $hasTimeIn,
        bool $hasTimeOut,
    ): array {
        $empty = $this->emptyMetrics();

        if (! is_array($daySchedule) || empty($daySchedule['in']) || ! $hasTimeIn || ! $effectiveTimeIn) {
            return $empty;
        }

        $tz = $nowTz->getTimezone()->getName();
        $timeInCarbon = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
        $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $timeInCarbon);

        $metrics = [
            'late_minutes' => (int) ($clockInResult['late_minutes'] ?? 0),
            'late_label' => $clockInResult['late_label'] ?? null,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'clock_in_status' => (string) ($clockInResult['status'] ?? 'present'),
        ];

        if ($hasTimeIn && $hasTimeOut && $effectiveTimeOut) {
            $outCarbon = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
            $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
            $metrics['undertime_minutes'] = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                $dateKey,
                $daySchedule,
                $timeInCarbon,
                $outCarbon,
                $tz,
                $earlyTimeout,
            );

            if (! empty($daySchedule['out'])) {
                $otBreakdown = AttendanceStatusService::computeRawOvertimeBreakdown(
                    $dateKey,
                    $daySchedule,
                    $timeInCarbon,
                    $outCarbon,
                    $tz,
                );
                $metrics['overtime_minutes'] = (int) $otBreakdown['total_minutes'];
            }
        }

        return $metrics;
    }

    /**
     * @param  array{late_minutes: int, late_label: ?string, undertime_minutes: int, overtime_minutes: int, clock_in_status: string}  $metrics
     */
    private function resolveAttendanceStatus(
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $daySchedule,
        mixed $effectiveTimeIn,
        mixed $effectiveTimeOut,
        bool $hasTimeIn,
        bool $hasTimeOut,
        bool $isFuture,
        array $metrics,
        ?array $overtimeContext,
    ): string {
        if (! $hasTimeIn && ! $hasTimeOut) {
            if ($isFuture) {
                return self::STATUS_UPCOMING;
            }
            $pastCutoff = ! ($dateKey === $todayDate) || AttendanceStatusService::isPastAbsentCutoff($dateKey, $nowTz);
            if ($pastCutoff) {
                return self::STATUS_ABSENT;
            }

            return '—';
        }

        if (! $hasTimeIn && $hasTimeOut) {
            return self::STATUS_INCOMPLETE;
        }

        if (! $daySchedule || empty($daySchedule['in'])) {
            return $hasTimeIn ? self::STATUS_PRESENT : self::STATUS_ABSENT;
        }

        if ($metrics['clock_in_status'] === 'half_day') {
            return self::STATUS_HALFDAY;
        }

        // Priority 4: Missing clock-out (past shift end)
        if ($hasTimeIn && ! $hasTimeOut && ! empty($daySchedule['out'])) {
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $nowTz->getTimezone()->getName());
            if ($scheduledEnd) {
                $pastShiftEnd = $dateKey < $todayDate || ($dateKey === $todayDate && $nowTz->greaterThan($scheduledEnd));
                if ($pastShiftEnd) {
                    return self::STATUS_INCOMPLETE;
                }
            }

            return self::STATUS_CLOCKED_IN;
        }

        // Priority 6–9: undertime, late, approved OT, present (both punches required below)
        if ($hasTimeIn && $hasTimeOut) {
            if ($metrics['undertime_minutes'] > 0) {
                return self::STATUS_UNDERTIME;
            }

            if ($metrics['clock_in_status'] === 'late') {
                return self::STATUS_LATE;
            }

            if (self::hasApprovedOrPayableOvertime($overtimeContext)) {
                return self::STATUS_PRESENT_WITH_OT;
            }

            return self::STATUS_PRESENT;
        }

        return $metrics['clock_in_status'] === 'late' ? self::STATUS_LATE : self::STATUS_PRESENT;
    }

    /**
     * @param  array{late_minutes: int, late_label: ?string, undertime_minutes: int, overtime_minutes: int, clock_in_status: string}  $metrics
     */
    private function buildResult(
        string $status,
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $daySchedule,
        mixed $effectiveTimeIn,
        mixed $effectiveTimeOut,
        ?int $effectiveWorkedMinutes,
        bool $hasTimeIn,
        bool $hasTimeOut,
        ?AttendanceCorrection $correction,
        bool $isFuture,
        array $metrics,
        ?array $overtimeContext = null,
    ): array {
        $qualified = $this->presenceDisplay->qualify(
            $dateKey, $todayDate, $nowTz, $daySchedule,
            $status, $effectiveTimeIn, $effectiveTimeOut,
            $correction, $isFuture,
        );

        $statusCode = $qualified['status'];
        $approvedOtHours = (float) ($overtimeContext['approved_ot_hours'] ?? 0);
        $payableOtHours = (float) ($overtimeContext['payable_ot_hours'] ?? 0);

        return [
            'status' => $statusCode,
            'status_code' => $statusCode,
            'status_label' => self::statusLabel($statusCode),
            'display_badge' => self::statusLabel($statusCode),
            'presence_label' => $qualified['presence_label'],
            'presence_issue' => $qualified['presence_issue'],
            'effective_time_in' => $effectiveTimeIn,
            'effective_time_out' => $effectiveTimeOut,
            'effective_worked_minutes' => $effectiveWorkedMinutes,
            'has_time_in' => $hasTimeIn,
            'has_time_out' => $hasTimeOut,
            'late_minutes' => $metrics['late_minutes'],
            'late_label' => $metrics['late_label'],
            'undertime_minutes' => $metrics['undertime_minutes'],
            'overtime_minutes' => $metrics['overtime_minutes'],
            'approved_ot_hours' => $approvedOtHours,
            'payable_ot_hours' => $payableOtHours,
            'payroll_impact_hours' => $payableOtHours > 0 ? $payableOtHours : $approvedOtHours,
        ];
    }

    /**
     * @return array{late_minutes: int, late_label: ?string, undertime_minutes: int, overtime_minutes: int, clock_in_status: string}
     */
    private function emptyMetrics(): array
    {
        return [
            'late_minutes' => 0,
            'late_label' => null,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'clock_in_status' => 'present',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_REST => self::REST_DAY_LABEL,
            'rest_day' => self::REST_DAY_LABEL,
            'no_schedule_rest' => self::REST_DAY_LABEL,
            self::STATUS_HOLIDAY => 'Holiday',
            self::STATUS_LEAVE => 'Leave',
            self::STATUS_PRESENT => 'Present',
            self::STATUS_PRESENT_WITH_OT => 'Present with OT',
            self::STATUS_LATE => 'Late',
            self::STATUS_HALFDAY => 'Half Day',
            self::STATUS_ABSENT => 'Absent',
            self::STATUS_UNDERTIME => 'Undertime',
            self::STATUS_INCOMPLETE => 'Incomplete',
            self::STATUS_CLOCKED_IN => 'Clocked In',
            default => $status,
        };
    }
}
