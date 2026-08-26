<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class OvertimeService
{
    public function __construct(
        private readonly ScheduleComputationService $scheduleComputation,
    ) {}

    private function attendanceTimezone(): string
    {
        return (string) config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Filed OT quantities from start/end, excluding unpaid schedule breaks (fixed or matched flexible shift).
     *
     * @return array{
     *   schedule_end: Carbon,
     *   expected_end: Carbon,
     *   computed_minutes: int,
     *   computed_hours: float,
     *   break_minutes_deducted: int
     * }
     */
    public function computeFiledOvertimeQuantities(
        User $user,
        string $dateYmd,
        string $startTimeHmi,
        string $endTimeHmi,
    ): array {
        $tz = $this->attendanceTimezone();
        $start = Carbon::parse($dateYmd.' '.$startTimeHmi, $tz);
        $end = Carbon::parse($dateYmd.' '.$endTimeHmi, $tz);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $rawMinutes = (int) $start->diffInMinutes($end);
        if ($rawMinutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => ['End time must be later than start time.'],
            ]);
        }

        $daySchedule = $this->resolveDayScheduleForOvertimeWindow($user, $dateYmd, $start, $end, $tz);
        $breakMinutes = is_array($daySchedule) && $daySchedule !== []
            ? $this->scheduleComputation->totalUnpaidBreakOverlapMinutes($dateYmd, $daySchedule, $start, $end, $tz)
            : 0;
        $computedMinutes = max(0, $rawMinutes - $breakMinutes);

        if ($computedMinutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => ['The selected OT window falls entirely within an unpaid break on your schedule. Adjust the start or end time.'],
            ]);
        }

        return [
            'schedule_end' => $start,
            'expected_end' => $end,
            'computed_minutes' => $computedMinutes,
            'computed_hours' => round($computedMinutes / 60, 2),
            'break_minutes_deducted' => $breakMinutes,
        ];
    }

    /**
     * Recompute stored hours for pending OT filings using current schedule break rules.
     *
     * @return array{scanned: int, updated: int, skipped: int}
     */
    public function syncPendingOvertimeQuantities(?int $onlyUserId = null, ?int $onlyOvertimeId = null): array
    {
        $query = Overtime::query()
            ->where('status', Overtime::STATUS_PENDING)
            ->whereNotNull('schedule_end')
            ->whereNotNull('expected_end_time')
            ->with(['user.workingSchedule']);

        if ($onlyUserId !== null && $onlyUserId > 0) {
            $query->where('user_id', $onlyUserId);
        }
        if ($onlyOvertimeId !== null && $onlyOvertimeId > 0) {
            $query->whereKey($onlyOvertimeId);
        }

        $scanned = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($query->orderBy('id')->cursor() as $overtime) {
            $scanned++;
            $user = $overtime->user;
            if (! $user instanceof User) {
                $skipped++;
                continue;
            }

            $dateYmd = $overtime->date?->toDateString();
            if ($dateYmd === null) {
                $skipped++;
                continue;
            }

            $startTime = $overtime->schedule_end instanceof \DateTimeInterface
                ? Carbon::instance($overtime->schedule_end)->format('H:i')
                : trim((string) $overtime->schedule_end);
            $endTime = $overtime->expected_end_time instanceof \DateTimeInterface
                ? Carbon::instance($overtime->expected_end_time)->format('H:i')
                : trim((string) $overtime->expected_end_time);
            if ($startTime === '' || $endTime === '') {
                $skipped++;
                continue;
            }

            try {
                $computed = $this->computeFiledOvertimeQuantities($user, $dateYmd, $startTime, $endTime);
            } catch (ValidationException) {
                $skipped++;
                continue;
            }

            $oldMinutes = (int) ($overtime->computed_minutes ?? 0);
            $newMinutes = (int) $computed['computed_minutes'];
            if ($oldMinutes === $newMinutes) {
                continue;
            }

            $overtime->fill([
                'computed_minutes' => $newMinutes,
                'computed_hours' => $computed['computed_hours'],
                'approved_ot_hours' => null,
                'actual_rendered_ot_hours' => 0,
                'payable_ot_hours' => 0,
                'unapproved_ot_hours' => 0,
                'overtime_reduction_reason' => null,
            ]);
            $overtime->save();
            $updated++;
        }

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDayScheduleForOvertimeWindow(
        User $user,
        string $dateYmd,
        Carbon $windowStart,
        Carbon $windowEnd,
        string $tz,
    ): ?array {
        $user->loadMissing('workingSchedule');
        $schedule = EmployeeScheduleResolver::resolveForDate($user, $dateYmd);
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }

        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateYmd, $tz));
        $daySchedule = $schedule[$dayKey] ?? null;
        if (! is_array($daySchedule) || $daySchedule === []) {
            // Rest days have no day row; use the next working day's shift template for break windows.
            $daySchedule = EmployeeScheduleResolver::referenceWorkingDaySchedule($schedule, $dayKey);
        }
        if (! is_array($daySchedule) || $daySchedule === []) {
            return null;
        }

        return $this->scheduleComputation->resolveFlexibleShiftForAttendance(
            $dateYmd,
            $daySchedule,
            $windowStart,
            $windowEnd,
            $tz,
        )['schedule'];
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
        $schedule = EmployeeScheduleResolver::resolveForDate($user, $dateKey);
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

        /** @var OvertimePayrollService $overtimePayroll */
        $overtimePayroll = app(OvertimePayrollService::class);
        $totalPayableMinutes = $approvedTotalMinutes > 0
            ? $overtimePayroll->resolvePayableOtMinutes($actualRenderedMinutes, $approvedTotalMinutes)
            : 0;

        $last = null;
        foreach ($records as $overtime) {
            if ($overtime->status === Overtime::STATUS_REJECTED) {
                $last = $overtime;
                continue;
            }

            $approvedHours = $overtime->status === Overtime::STATUS_APPROVED
                ? round(max(0.0, (float) ($overtime->approved_ot_hours ?? $overtime->computed_hours ?? 0)), 2)
                : null;
            $recordApprovedMinutes = $approvedHours !== null
                ? (int) round($approvedHours * 60)
                : 0;
            $payableMinutes = ($overtime->status === Overtime::STATUS_APPROVED && $approvedTotalMinutes > 0)
                ? (int) round($totalPayableMinutes * ($recordApprovedMinutes / $approvedTotalMinutes))
                : 0;

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
        $schedule = EmployeeScheduleResolver::resolveForDate($user, $dateKey);
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
