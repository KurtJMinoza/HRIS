<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\Carbon;

/**
 * Resolve calendar day status with priority: Holiday → Approved Leave → Rest Day → Present → Absent.
 * Shared by EmployeeDashboard, Attendance summary, DTR, and presence filing controllers.
 */
class AttendanceStatusResolver
{
    public const STATUS_HOLIDAY = 'holiday';
    public const STATUS_LEAVE = 'leave';
    public const STATUS_REST = 'rest';
    public const STATUS_PRESENT = 'present';
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
                    : Carbon::parse($rawStamp)->timezone($nowTz->getTimezone());

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
            );
        }

        // Priority 3: Rest Day. A scheduled rest day is not absent and has no payroll penalties.
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
            );
        }

        // Priority 4: Normal attendance status (present, late, halfday, absent, etc.)
        $status = $this->resolveAttendanceStatus(
            dateKey: $dateKey,
            todayDate: $todayDate,
            nowTz: $nowTz,
            daySchedule: $daySchedule,
            effectiveTimeIn: $effectiveTimeIn,
            effectiveTimeOut: $effectiveTimeOut,
            effectiveWorkedMinutes: $effectiveWorkedMinutes,
            hasTimeIn: $hasTimeIn,
            hasTimeOut: $hasTimeOut,
            isFuture: $isFuture,
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
        );
    }

    private function resolveAttendanceStatus(
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $daySchedule,
        mixed $effectiveTimeIn,
        mixed $effectiveTimeOut,
        ?int $effectiveWorkedMinutes,
        bool $hasTimeIn,
        bool $hasTimeOut,
        bool $isFuture,
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

        $timeInCarbon = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
        $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $timeInCarbon);

        if ($clockInResult['status'] === 'half_day') {
            return self::STATUS_HALFDAY;
        }

        $status = $clockInResult['status'] === 'late' ? self::STATUS_LATE : self::STATUS_PRESENT;

        // Undertime check
        if ($hasTimeIn && $hasTimeOut && ! empty($daySchedule['out'])) {
            $outCarbon = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
            $inCarbon = $timeInCarbon;
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $nowTz->getTimezone()->getName());
            $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $nowTz->getTimezone()->getName());
            $undertimeThreshold = (int) config('attendance.undertime_threshold_minutes', 60);

            if ($scheduledEnd && $outCarbon->lessThan($scheduledEnd)) {
                $netWorked = AttendanceStatusService::getScheduleClippedNetWorkedMinutes(
                    $inCarbon, $outCarbon, $daySchedule, $dateKey, $nowTz->getTimezone()->getName()
                );
                $undertimeMinutes = max(0, $requiredMinutes - $netWorked);
                if ($undertimeMinutes > 0 || ($effectiveWorkedMinutes !== null && $effectiveWorkedMinutes < $requiredMinutes - $undertimeThreshold)) {
                    return self::STATUS_UNDERTIME;
                }
            }
        }

        // Incomplete (no clock-out but past shift end)
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

        return $status;
    }

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
    ): array {
        $qualified = $this->presenceDisplay->qualify(
            $dateKey, $todayDate, $nowTz, $daySchedule,
            $status, $effectiveTimeIn, $effectiveTimeOut,
            $correction, $isFuture,
        );

        return [
            'status' => $qualified['status'],
            'presence_label' => $qualified['presence_label'],
            'presence_issue' => $qualified['presence_issue'],
            'effective_time_in' => $effectiveTimeIn,
            'effective_time_out' => $effectiveTimeOut,
            'effective_worked_minutes' => $effectiveWorkedMinutes,
            'has_time_in' => $hasTimeIn,
            'has_time_out' => $hasTimeOut,
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
