<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

class OvertimeService
{
    private function attendanceTimezone(): string
    {
        return (string) config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * @return array{schedule_end: Carbon, actual_rendered_minutes: int}|null
     */
    private function renderedOvertimeContext(User $user, string $dateKey, ?Carbon $actualClockOut): ?array
    {
        if ($actualClockOut === null) {
            return null;
        }

        $tz = $this->attendanceTimezone();
        $user->loadMissing('workingSchedule');
        $schedule = EmployeeScheduleResolver::resolve($user);
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }

        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz));
        $daySchedule = $schedule[$dayKey] ?? null;
        if (! is_array($daySchedule) || empty($daySchedule['out'])) {
            return null;
        }

        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd instanceof Carbon) {
            return null;
        }

        $actualOut = $actualClockOut->copy()->timezone($tz);
        if (! empty($daySchedule['in'])) {
            $scheduledStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $tz);
            if ($scheduledStart instanceof Carbon && $scheduledEnd->lessThanOrEqualTo($scheduledStart)) {
                $scheduledEnd->addDay();
            }
            if ($scheduledStart instanceof Carbon && $actualOut->lessThanOrEqualTo($scheduledStart) && $scheduledEnd->toDateString() !== $scheduledStart->toDateString()) {
                $actualOut->addDay();
            }
        }

        $minutes = $actualOut->greaterThan($scheduledEnd)
            ? (int) $scheduledEnd->diffInMinutes($actualOut)
            : 0;

        return [
            'schedule_end' => $scheduledEnd,
            'actual_rendered_minutes' => max(0, $minutes),
        ];
    }

    private function approvedWindowStart(Overtime $overtime, string $dateKey, string $tz): ?Carbon
    {
        $value = $overtime->approved_ot_start ?? $overtime->schedule_end;
        if ($value === null) {
            return null;
        }

        $time = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->format('H:i:s')
            : trim((string) $value);
        if ($time === '' || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) !== 1) {
            return null;
        }

        return Carbon::parse($dateKey.' '.$time, $tz);
    }

    private function approvedWindowEnd(Overtime $overtime, string $dateKey, string $tz, Carbon $start): Carbon
    {
        $value = $overtime->approved_ot_end ?? $overtime->expected_end_time ?? $overtime->time_out;
        if ($value !== null) {
            $time = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)->format('H:i:s')
                : trim((string) $value);
            if ($time !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time) === 1) {
                $end = Carbon::parse($dateKey.' '.$time, $tz);
                if (! $end->greaterThan($start)) {
                    $end->addDay();
                }

                return $end;
            }
        }

        $minutes = (int) round(max(0.0, (float) ($overtime->approved_ot_hours ?? $overtime->computed_hours ?? 0)) * 60);

        return $start->copy()->addMinutes($minutes);
    }

    private function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $start = $aStart->greaterThan($bStart) ? $aStart : $bStart;
        $end = $aEnd->lessThan($bEnd) ? $aEnd : $bEnd;

        return $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : 0;
    }

    private function reductionReason(int $approvedMinutes, int $actualRenderedMinutes, bool $hasClockOut): ?string
    {
        if (! $hasClockOut) {
            return $approvedMinutes > 0 ? 'Pending clock out' : null;
        }
        if ($approvedMinutes > 0 && $actualRenderedMinutes < $approvedMinutes) {
            return 'Clocked out before approved OT end';
        }
        if ($actualRenderedMinutes > $approvedMinutes) {
            return 'Rendered OT exceeded approved OT window';
        }

        return null;
    }

    public function syncActualClockOutToFiledOvertime(User $user, string $dateKey, ?Carbon $actualClockOut, ?User $admin = null): ?Overtime
    {
        $records = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return null;
        }

        $tz = $this->attendanceTimezone();
        $context = $this->renderedOvertimeContext($user, $dateKey, $actualClockOut);
        $actualRenderedMinutes = (int) ($context['actual_rendered_minutes'] ?? 0);
        $hasClockOut = $actualClockOut !== null;
        $actualStart = $context['schedule_end'] ?? null;
        $actualEnd = $actualStart instanceof Carbon
            ? $actualStart->copy()->addMinutes($actualRenderedMinutes)
            : null;

        $approvedRecords = $records
            ->filter(fn (Overtime $ot): bool => $ot->status === Overtime::STATUS_APPROVED)
            ->values();
        $approvedTotalMinutes = 0;
        foreach ($approvedRecords as $approved) {
            $approvedTotalMinutes += (int) round(max(0.0, (float) ($approved->approved_ot_hours ?? $approved->computed_hours ?? 0)) * 60);
        }
        $unapprovedMinutes = $hasClockOut && ($approvedTotalMinutes > 0 || $actualRenderedMinutes > 0)
            ? abs($actualRenderedMinutes - $approvedTotalMinutes)
            : 0;
        $reason = $this->reductionReason($approvedTotalMinutes, $actualRenderedMinutes, $hasClockOut);

        $last = null;
        foreach ($records as $overtime) {
            if ($overtime->status === Overtime::STATUS_REJECTED) {
                $last = $overtime;
                continue;
            }

            $approvedHours = $overtime->status === Overtime::STATUS_APPROVED
                ? round(max(0.0, (float) ($overtime->approved_ot_hours ?? $overtime->computed_hours ?? 0)), 2)
                : null;
            $payableMinutes = 0;
            if ($overtime->status === Overtime::STATUS_APPROVED && $actualStart instanceof Carbon && $actualEnd instanceof Carbon && $actualRenderedMinutes > 0) {
                $windowStart = $this->approvedWindowStart($overtime, $dateKey, $tz);
                if ($windowStart instanceof Carbon) {
                    $windowEnd = $this->approvedWindowEnd($overtime, $dateKey, $tz, $windowStart);
                    $payableMinutes = $this->overlapMinutes($windowStart, $windowEnd, $actualStart, $actualEnd);
                }
            }

            $payload = [
                'time_out' => $actualClockOut?->copy()->timezone($tz)->format('H:i:s'),
                'actual_rendered_ot_hours' => round($actualRenderedMinutes / 60, 2),
                'payable_ot_hours' => round($payableMinutes / 60, 2),
                'unapproved_ot_hours' => round($unapprovedMinutes / 60, 2),
                'overtime_reduction_reason' => $reason,
            ];
            if ($approvedHours !== null) {
                $payload['approved_ot_start'] = ($overtime->approved_ot_start ?? $overtime->schedule_end)?->format('H:i:s');
                $payload['approved_ot_end'] = ($overtime->approved_ot_end ?? $overtime->expected_end_time)?->format('H:i:s');
                $payload['approved_ot_hours'] = $approvedHours;
            }
            if ($admin) {
                $payload['updated_by'] = $admin->id;
            }

            $overtime->fill($payload);
            $overtime->save();
            $last = $overtime;
        }

        return $last;
    }

    /**
     * Compute overtime for a given user and date based on attendance logs and schedule.
     *
     * OT = any work minutes after the scheduled shift end. No buffer is applied;
     * approved OT duration is the source of truth for payroll.
     *
     * @return array{
     *   date: string,
     *   schedule_end: \Carbon\CarbonInterface,
     *   time_out: \Carbon\CarbonInterface,
     *   minutes: int,
     *   hours: float
     * }|null
     */
    public function computeOvertimeForDate(User $user, Carbon $date): ?array
    {
        $dateKey = $date->toDateString();

        $hasApprovedLeave = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->exists();

        if ($hasApprovedLeave) {
            return null;
        }

        $user->loadMissing('workingSchedule');
        $schedule = EmployeeScheduleResolver::resolve($user);
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }

        $dayKey = EmployeeScheduleResolver::dayKeyForDate($date);
        $daySchedule = $schedule[$dayKey] ?? null;

        if (! is_array($daySchedule) || empty($daySchedule['out'])) {
            return null;
        }

        $logs = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', $dateKey)
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) {
            return null;
        }

        $hasClockIn = false;
        $lastClockOut = null;

        foreach ($logs as $log) {
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $hasClockIn = true;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $lastClockOut = $log->created_at;
            }
        }

        if (! $hasClockIn || ! $lastClockOut) {
            return null;
        }

        $scheduledEnd = Carbon::parse($dateKey.' '.$daySchedule['out']);

        if ($lastClockOut->lessThanOrEqualTo($scheduledEnd)) {
            return null;
        }

        $minutes = (int) $scheduledEnd->diffInMinutes($lastClockOut);
        if ($minutes <= 0) {
            return null;
        }

        $hours = round($minutes / 60, 2);

        return [
            'date' => $dateKey,
            'schedule_end' => $scheduledEnd,
            'time_out' => $lastClockOut,
            'minutes' => $minutes,
            'hours' => $hours,
        ];
    }

    /**
     * Sync actual clock-out data onto an existing (employee-filed) overtime record.
     *
     * Per the Enhanced Attendance Logic spec:
     * - OT records are ONLY created when an employee explicitly files an OT request.
     * - This method updates already-filed records with actual clock-out times for audit.
     * - If no OT record exists for this date, nothing is created (detection is passive).
     * - Approved records: actual time_out is recorded but approved hours are preserved.
     * - Rejected records: left untouched.
     * - Pending records: actual time_out and computed values are synced.
     */
    public function syncClockOutToFiledOvertime(User $user, AttendanceLog $clockOutLog, ?User $admin = null): ?Overtime
    {
        $date = $clockOutLog->created_at->copy();
        $dateKey = $date->toDateString();

        return $this->syncActualClockOutToFiledOvertime($user, $dateKey, $clockOutLog->created_at, $admin);
    }

    /**
     * @deprecated Use syncClockOutToFiledOvertime() instead. Kept for backward compatibility.
     */
    public function createOrUpdateFromClockOut(User $user, AttendanceLog $clockOutLog, ?User $admin = null): ?Overtime
    {
        return $this->syncClockOutToFiledOvertime($user, $clockOutLog, $admin);
    }
}
