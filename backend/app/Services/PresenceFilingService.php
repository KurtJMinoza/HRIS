<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\Carbon;

/**
 * Employee presence filing (no punch) → pending correction → approval.
 * Approved corrections feed {@see AttendanceSessionService} like other manual attendance.
 */
class PresenceFilingService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const REASON_FORGOT_PUNCH = 'forgot_punch';

    public const REASON_SYSTEM_ISSUE = 'system_issue';

    public const REASON_FIELD_WORK = 'field_work';

    public const REASON_MANUAL_OVERRIDE = 'manual_override';

    public const REASON_OTHERS = 'others';

    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_FORGOT_PUNCH => 'Forgot to clock in/out',
            self::REASON_SYSTEM_ISSUE => 'System issue',
            self::REASON_FIELD_WORK => 'Field work',
            self::REASON_MANUAL_OVERRIDE => 'Manual override',
            self::REASON_OTHERS => 'Others',
        ];
    }

    public function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * @return array<string, mixed>|null day schedule array or null if rest / no schedule
     */
    public function dayScheduleForUser(User $employee, string $dateKey): ?array
    {
        $tz = $this->attendanceTimezone();
        $dateCarbon = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayKey = self::DAY_KEYS[(int) $dateCarbon->format('w')];

        $schedule = $employee->schedule;
        if ((! is_array($schedule) || $schedule === []) && $employee->working_schedule_id !== null) {
            $employee->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($employee->workingSchedule);
            if ($derived !== null) {
                $schedule = $derived;
            }
        }
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }
        $day = $schedule[$dayKey] ?? null;

        return is_array($day) && ! empty($day['in']) ? $day : null;
    }

    /**
     * Schedule-aligned in/out Carbons for a regular workday (no OT padding).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}|null
     */
    public function resolveScheduleRegularPunches(User $employee, string $dateKey): ?array
    {
        $daySchedule = $this->dayScheduleForUser($employee, $dateKey);
        if ($daySchedule === null) {
            return null;
        }
        $tz = $this->attendanceTimezone();
        $timeInStr = trim((string) ($daySchedule['in'] ?? ''));
        $timeOutStr = trim((string) ($daySchedule['out'] ?? ''));
        if ($timeInStr === '' || $timeOutStr === '') {
            return null;
        }
        $timeIn = Carbon::parse($dateKey.' '.substr($timeInStr, 0, 5), $tz);
        $timeOut = Carbon::parse($dateKey.' '.substr($timeOutStr, 0, 5), $tz);
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = $timeOut->copy()->addDay();
        }

        return [$timeIn, $timeOut];
    }

    /**
     * Whether the employee may file a presence request for this calendar date (today only for self-service).
     *
     * @return array{ok: bool, message?: string}
     */
    public function employeeCanFile(User $employee, string $dateKey): array
    {
        $tz = $this->attendanceTimezone();
        $today = Carbon::now($tz)->toDateString();
        if ($dateKey !== $today) {
            return ['ok' => false, 'message' => 'Presence filing is only available for today.'];
        }

        if ($this->dayScheduleForUser($employee, $dateKey) === null) {
            return ['ok' => false, 'message' => 'You are not scheduled to work on this date.'];
        }

        $blockingLeave = LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->whereNotIn('type', ['half_day', 'undertime'])
            ->exists();
        if ($blockingLeave) {
            return ['ok' => false, 'message' => 'You have approved leave covering this date.'];
        }

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $hasIn = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        $correction = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->first();

        if ($correction && $correction->approved) {
            return ['ok' => false, 'message' => 'Attendance for this date is already finalized.'];
        }

        if ($hasIn && $hasOut) {
            return ['ok' => false, 'message' => 'Clock-in and clock-out are already recorded for today.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $restDays = $schedule->rest_days ?? [];
        $baseDayConfig = [];

        foreach (self::DAY_KEYS as $dayKey) {
            if (in_array($dayKey, $restDays, true)) {
                $baseDayConfig[$dayKey] = null;

                continue;
            }

            $baseDayConfig[$dayKey] = [
                'in' => $schedule->time_in,
                'out' => $schedule->time_out,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $baseDayConfig;
    }
}
