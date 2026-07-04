<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;

/**
 * Single source of truth for daily attendance summaries.
 *
 * Every module (Dashboard, Attendance Monitoring, Reports, Payroll) MUST
 * read from this service so values are consistent everywhere.
 *
 * The service computes and returns a standardized array with all fields
 * needed for display: status, worked hours, late, undertime, approved OT,
 * unapproved OT, payroll impact, etc.
 */
class AttendanceDailySummaryService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly AttendanceStatusResolver $statusResolver,
        private readonly AttendanceRollupService $attendanceRollup,
        private readonly OvertimePayrollService $overtimePayroll,
        private readonly PayrollComputationService $payrollComputation,
    ) {}

    /**
     * Compute a single immutable daily attendance summary for employee + date.
     *
     * @param  User  $user  The employee
     * @param  string  $dateKey  Y-m-d date string
     * @param  string  $todayDate  Y-m-d of "today" in attendance timezone
     * @param  Carbon  $nowTz  Current moment in attendance timezone
     * @param  array|null  $effectiveSchedule  Full per-day schedule map; resolved if null
     * @param  array|null  $preloadedLogs  Pre-loaded attendance logs for this date (or null to skip)
     * @param  AttendanceCorrection|null  $correction  Pre-loaded correction (or null)
     * @param  LeaveRequest|null  $leave  Pre-loaded approved leave (or null)
     * @param  array|null  $holiday  Pre-loaded holiday array ['name', 'type', 'date'] (or null)
     * @param  array|null  $otRecords  Pre-loaded Overtime records for this date (or null)
     * @return array Standardized daily summary
     */
    public function computeForDate(
        User $user,
        string $dateKey,
        string $todayDate,
        Carbon $nowTz,
        ?array $effectiveSchedule = null,
        ?array $preloadedLogs = null,
        ?AttendanceCorrection $correction = null,
        ?LeaveRequest $leave = null,
        ?array $holiday = null,
        ?array $otRecords = null,
    ): array {
        $tz = $this->attendanceTimezone();

        if ($effectiveSchedule === null) {
            $effectiveSchedule = $this->resolveEffectiveSchedule($user);
        }

        $dayKey = self::DAY_KEYS[(int) Carbon::parse($dateKey)->format('w')];
        $daySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey])
            ? $effectiveSchedule[$dayKey]
            : null;
        $scheduleAssigned = is_array($effectiveSchedule) && $effectiveSchedule !== [];
        $isRestDay = $this->attendanceRollup->isScheduledRestDay($effectiveSchedule, $daySchedule);
        $isFuture = $dateKey > $todayDate;

        // Resolve effective clock times from logs + correction
        [$effectiveTimeIn, $effectiveTimeOut, $hasTimeIn, $hasTimeOut] = $this->resolveClockTimes(
            $preloadedLogs,
            $correction,
            $tz
        );

        // OT computation
        $otRecords = $otRecords ?? [];
        $approvedOtRecords = array_values(array_filter(
            $otRecords,
            fn ($o) => $o->status === Overtime::STATUS_APPROVED
        ));
        $approvedOtHours = $this->sumOvertimeHours($approvedOtRecords);

        $scheduleForOt = is_array($daySchedule) ? $daySchedule : (
            $isRestDay ? AttendanceStatusService::firstWorkdaySchedule($effectiveSchedule) : null
        );
        $rawOtMinutes = 0;
        if (
            $effectiveTimeIn instanceof Carbon
            && $effectiveTimeOut instanceof Carbon
            && is_array($scheduleForOt)
            && ! empty($scheduleForOt['in'])
            && ! empty($scheduleForOt['out'])
        ) {
            $rawOtMinutes = AttendanceStatusService::computeRawOvertimeBreakdown(
                $dateKey,
                $scheduleForOt,
                $effectiveTimeIn,
                $effectiveTimeOut,
                $tz
            )['total_minutes'];
        }

        $actualRenderedOtHours = $rawOtMinutes > 0 ? round($rawOtMinutes / 60, 2) : 0.0;
        $payableOtMinutes = $approvedOtHours > 0
            ? $this->overtimePayroll->resolvePayableOtMinutes($rawOtMinutes, (int) round($approvedOtHours * 60))
            : 0;
        $payableOtHours = round($payableOtMinutes / 60, 2);

        $overtimeContext = [
            'approved_ot_hours' => $approvedOtHours,
            'payable_ot_hours' => $payableOtHours,
        ];

        // Status resolution via the shared resolver
        $resolved = $this->statusResolver->resolve(
            dateKey: $dateKey,
            todayDate: $todayDate,
            nowTz: $nowTz,
            effectiveSchedule: $effectiveSchedule,
            daySchedule: $daySchedule,
            dayLogs: $preloadedLogs,
            correction: $correction,
            holiday: $holiday,
            leave: $leave,
            isRestDay: $isRestDay,
            isFuture: $isFuture,
            overtimeContext: $overtimeContext,
        );

        $status = $resolved['status'];
        $isRestDayWorked = (bool) ($resolved['is_rest_day_worked'] ?? false);

        // Net worked minutes: recompute with full break deduction
        $effectiveWorkedMinutes = $resolved['effective_worked_minutes'];
        if ($hasTimeIn && $hasTimeOut && $effectiveTimeIn && $effectiveTimeOut) {
            $in = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
            $out = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
            if ($out->greaterThan($in)) {
                $scheduleForBreak = is_array($daySchedule) ? $daySchedule : (
                    $isRestDayWorked ? AttendanceStatusService::firstWorkdaySchedule($effectiveSchedule) : null
                );
                if ($scheduleForBreak !== null) {
                    $effectiveWorkedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                        $in, $out, $scheduleForBreak, $dateKey, $tz
                    );
                } else {
                    $effectiveWorkedMinutes = (int) $in->diffInMinutes($out);
                }
            }
        }

        $lateMinutes = (int) ($resolved['late_minutes'] ?? 0);
        $lateLabel = $resolved['late_label'] ?? null;
        $undertimeMinutes = (int) ($resolved['undertime_minutes'] ?? 0);
        $rawOtMinutes = (int) ($resolved['overtime_minutes'] ?? $rawOtMinutes);

        // Unapproved OT
        $unapprovedOtHours = ($approvedOtHours > 0.0001 || $actualRenderedOtHours > 0.0001)
            ? abs(round($actualRenderedOtHours - $approvedOtHours, 2))
            : 0.0;
        if ($actualRenderedOtHours <= 0.0001 && $approvedOtHours > 0) {
            $unapprovedOtHours = 0.0;
        }

        // Payroll impact via the SAME service as Attendance Monitoring
        $payrollImpactHours = null;
        // Payroll impact: include eligible unworked holidays even without clock logs.
        if (! $isFuture && ($hasTimeIn || $hasTimeOut || $leave !== null || $holiday !== null)) {
            $payrollImpactMinutes = $this->payrollComputation->payrollImpactMinutesForAttendanceDisplay(
                $user,
                $dateKey,
                $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : null,
                $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : null,
                $tz
            );
            $payrollImpactHours = round($payrollImpactMinutes / 60, 2);
        }

        // OT segment breakdown for display
        $rawPreOtSegment = null;
        $rawPostOtSegment = null;
        if (
            $rawOtMinutes > 0
            && $effectiveTimeIn instanceof Carbon
            && $effectiveTimeOut instanceof Carbon
            && is_array($scheduleForOt)
            && ! empty($scheduleForOt['in'])
            && ! empty($scheduleForOt['out'])
        ) {
            $otBreakdown = AttendanceStatusService::computeRawOvertimeBreakdown(
                $dateKey, $scheduleForOt, $effectiveTimeIn, $effectiveTimeOut, $tz
            );
            $scheduledStartForOt = AttendanceStatusService::getScheduledStartForDate($dateKey, $scheduleForOt, $tz);
            $scheduledEndForOt = AttendanceStatusService::getScheduledEndForDate($dateKey, $scheduleForOt, $tz);

            if ($scheduledStartForOt && $otBreakdown['pre_minutes'] > 0) {
                $rawPreOtSegment = [
                    'kind' => 'pre_shift',
                    'start' => $this->formatTimeInTz($effectiveTimeIn, $tz),
                    'end' => $this->formatTimeInTz($scheduledStartForOt, $tz),
                    'minutes' => $otBreakdown['pre_minutes'],
                    'hours' => round($otBreakdown['pre_minutes'] / 60, 2),
                ];
            }

            if ($scheduledEndForOt && $otBreakdown['post_minutes'] > 0) {
                $overtimeBuffer = isset($scheduleForOt['overtime_buffer_minutes'])
                    ? (int) $scheduleForOt['overtime_buffer_minutes']
                    : (int) config('attendance.overtime_buffer_minutes', 15);
                $postShiftOtStart = $scheduledEndForOt->copy()->addMinutes($overtimeBuffer);
                $rawPostOtSegment = [
                    'kind' => 'post_shift',
                    'start' => $this->formatTimeInTz($postShiftOtStart, $tz),
                    'end' => $this->formatTimeInTz($effectiveTimeOut, $tz),
                    'minutes' => $otBreakdown['post_minutes'],
                    'hours' => round($otBreakdown['post_minutes'] / 60, 2),
                ];
            }
        }

        $isHalfDay = $status === 'halfday' || $status === 'half_day';
        $isPresent = in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime', 'incomplete', 'clocked_in'], true);
        $isAbsent = $status === 'absent';
        $isLeave = $status === 'leave';
        $isRestDayStatus = in_array($status, ['rest', 'rest_day', 'no_schedule_rest'], true) || $isRestDay;
        $isHoliday = $status === 'holiday';

        $statusLabel = $resolved['status_label'] ?? AttendanceStatusResolver::statusLabel($status);
        if ($isRestDayWorked) {
            $statusLabel = match ($status) {
                'late' => 'Rest Day Worked Late',
                'halfday', 'half_day' => 'Rest Day Worked Half Day',
                'undertime' => 'Rest Day Worked Undertime',
                default => 'Rest Day Worked',
            };
        }

        return [
            'date' => $dateKey,
            'status' => in_array($status, ['rest', 'rest_day', 'no_schedule_rest'], true) ? 'rest' : $status,
            'status_label' => $statusLabel,
            'status_code' => $resolved['status_code'] ?? $status,
            'display_badge' => $statusLabel,

            'time_in' => $this->formatTimeInTz($effectiveTimeIn, $tz),
            'time_out' => $this->formatTimeInTz($effectiveTimeOut, $tz),
            'formatted_time_in' => $this->formatTimeForDisplay($effectiveTimeIn, $tz),
            'formatted_time_out' => $this->formatTimeForDisplay($effectiveTimeOut, $tz),

            'worked_minutes' => $effectiveWorkedMinutes,
            'worked_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
            'total_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
            'total_rendered_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,

            'late_minutes' => $lateMinutes,
            'late_label' => $lateLabel,
            'undertime_minutes' => $undertimeMinutes,

            'approved_ot_minutes' => $approvedOtHours > 0.0001 ? (int) round($approvedOtHours * 60) : 0,
            'approved_ot_hours' => $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : null,
            'unapproved_ot_minutes' => $unapprovedOtHours > 0.0001 ? (int) round($unapprovedOtHours * 60) : 0,
            'unapproved_ot_hours' => $unapprovedOtHours > 0.0001 ? round($unapprovedOtHours, 2) : null,
            'payable_ot_hours' => $payableOtHours > 0 ? $payableOtHours : null,

            'overtime_minutes' => $rawOtMinutes > 0 ? $rawOtMinutes : null,
            'raw_overtime_minutes' => $rawOtMinutes > 0 ? $rawOtMinutes : null,
            'raw_overtime_hours' => $rawOtMinutes > 0 ? round($rawOtMinutes / 60, 2) : null,
            'raw_pre_ot' => $rawPreOtSegment,
            'raw_post_ot' => $rawPostOtSegment,
            'actual_rendered_overtime_hours' => ($rawOtMinutes > 0 || $approvedOtHours > 0) ? $actualRenderedOtHours : null,
            'rendered_overtime_hours' => ($rawOtMinutes > 0 || $approvedOtHours > 0) ? $actualRenderedOtHours : null,
            'approved_overtime_hours' => $approvedOtHours > 0 ? round($approvedOtHours, 2) : null,
            'payable_overtime_hours' => $payableOtHours > 0 ? $payableOtHours : null,
            'unapproved_overtime_hours' => $unapprovedOtHours > 0.0001 ? round($unapprovedOtHours, 2) : null,

            'payroll_impact_hours' => $payrollImpactHours,

            'is_half_day' => $isHalfDay,
            'is_present' => $isPresent,
            'is_absent' => $isAbsent,
            'is_leave' => $isLeave,
            'is_rest_day' => $isRestDayStatus || $isRestDayWorked,
            'is_rest_day_worked' => $isRestDayWorked,
            'is_holiday' => $isHoliday,

            'presence_label' => $resolved['presence_label'],
            'presence_issue' => $resolved['presence_issue'],

            'schedule_in' => is_array($daySchedule) ? ($daySchedule['in'] ?? null) : null,
            'schedule_out' => is_array($daySchedule) ? ($daySchedule['out'] ?? null) : null,
        ];
    }

    /**
     * Resolve effective schedule from user model, same as Attendance Monitoring.
     */
    public function resolveEffectiveSchedule(User $user): ?array
    {
        $schedule = $user->schedule;
        if (is_array($schedule) && $schedule !== []) {
            return $schedule;
        }

        if ($user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                return $derived;
            }
        }

        return null;
    }

    /**
     * Resolve clock-in/clock-out from device logs + approved corrections.
     *
     * @param  list<AttendanceLog>|null  $dayLogs
     * @return array{0: ?Carbon, 1: ?Carbon, 2: bool, 3: bool}
     */
    private function resolveClockTimes(?array $dayLogs, ?AttendanceCorrection $correction, string $tz): array
    {
        $timeIn = null;
        $timeOut = null;

        if ($dayLogs !== null) {
            foreach ($dayLogs as $log) {
                if (! $log instanceof AttendanceLog) {
                    continue;
                }
                $rawStamp = $log->verified_at ?? $log->created_at;
                if ($rawStamp === null) {
                    continue;
                }
                $stamp = ($rawStamp instanceof Carbon ? $rawStamp->copy() : Carbon::parse($rawStamp))
                    ->timezone($tz);

                if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                    if ($timeIn === null) {
                        $timeIn = $stamp;
                    }
                } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                    $timeOut = $stamp;
                }
            }
        }

        if ($correction && $correction->approved && $correction->pending_approval !== true) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in instanceof Carbon
                    ? $correction->time_in->copy()->timezone($tz)
                    : Carbon::parse($correction->time_in)->timezone($tz);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out instanceof Carbon
                    ? $correction->time_out->copy()->timezone($tz)
                    : Carbon::parse($correction->time_out)->timezone($tz);
            }
        }

        // Truncate seconds to match the Attendance Module's H:i precision.
        // The Attendance Module formats times as H:i (no seconds) then re-parses them,
        // effectively zeroing seconds. Without this, second-level punch drift causes
        // payroll impact and undertime to differ by fractions of a minute.
        if ($timeIn !== null) {
            $timeIn->second(0);
        }
        if ($timeOut !== null) {
            $timeOut->second(0);
        }

        return [$timeIn, $timeOut, $timeIn !== null, $timeOut !== null];
    }

    private function sumOvertimeHours(array $otRecords): float
    {
        $total = 0.0;
        foreach ($otRecords as $ot) {
            $total += (float) ($ot->approved_ot_hours ?? $ot->computed_hours ?? 0);
        }

        return $total;
    }

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'UTC'));
    }

    private function formatTimeInTz(mixed $carbon, string $tz): ?string
    {
        if ($carbon === null) {
            return null;
        }
        $c = $carbon instanceof Carbon ? $carbon : Carbon::parse($carbon);

        return $c->timezone($tz)->format('H:i:s');
    }

    private function formatTimeForDisplay(mixed $carbon, string $tz): ?string
    {
        if ($carbon === null) {
            return null;
        }
        $c = $carbon instanceof Carbon ? $carbon : Carbon::parse($carbon);

        return $c->timezone($tz)->format('g:i A');
    }

    private function buildScheduleFromWorkingSchedule(?\App\Models\WorkingSchedule $schedule): ?array
    {
        if (! $schedule || ! $schedule->time_in || ! $schedule->time_out) {
            return null;
        }

        $restDays = is_array($schedule->rest_days) ? $schedule->rest_days : [];

        $breaks = [];
        foreach ($schedule->getAllBreaks() as $b) {
            $breaks[] = [
                'start' => $b['start'],
                'end' => $b['end'],
                'is_paid' => $b['is_paid'] ?? false,
            ];
        }

        $dayConfig = [];
        foreach (self::DAY_KEYS as $dayKey) {
            if (in_array($dayKey, $restDays, true)) {
                $dayConfig[$dayKey] = null;
                continue;
            }

            $dayConfig[$dayKey] = [
                'in' => $schedule->time_in,
                'out' => $schedule->time_out,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'breaks' => $breaks,
                'work_blocks' => $schedule->getWorkBlocks(),
                'shift_type' => $schedule->shift_type ?? 'fixed',
                'crosses_midnight' => (bool) ($schedule->crosses_midnight ?? false),
                'expected_paid_minutes' => $schedule->expected_paid_minutes,
                'half_day_threshold_minutes' => $schedule->effective_half_day_threshold,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
                'rest_days' => $restDays,
                'flexible_required_minutes' => $schedule->flexible_required_minutes,
            ];
        }

        return $dayConfig;
    }

    private function firstScheduleWithBreaks(?array $effectiveSchedule): ?array
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
}
