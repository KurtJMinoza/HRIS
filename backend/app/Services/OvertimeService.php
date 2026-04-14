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
     * Create or update an overtime record when a clock-out is recorded.
     *
     * - Uses computeOvertimeForDate to determine OT minutes/hours.
     * - Ensures a single overtime row per user per date.
     * - Does not overwrite approved/rejected records.
     */
    public function createOrUpdateFromClockOut(User $user, AttendanceLog $clockOutLog, ?User $admin = null): ?Overtime
    {
        $date = $clockOutLog->created_at->copy();
        $data = $this->computeOvertimeForDate($user, $date);
        $dateKey = $date->toDateString();

        $existing = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->first();

        // If there is no computed overtime from actual logs, sync actuals on DB rows but keep status.
        if ($data === null) {
            if ($existing && $existing->status === Overtime::STATUS_PENDING) {
                $existing->fill([
                    'schedule_end' => null,
                    'time_out' => null,
                    'computed_minutes' => 0,
                    'computed_hours' => 0,
                ]);
                if ($admin) {
                    $existing->updated_by = $admin->id;
                }
                $existing->save();
            } elseif ($existing && $existing->status === Overtime::STATUS_APPROVED) {
                $lastOut = $clockOutLog->created_at->timezone(config('attendance.timezone', config('app.timezone', 'Asia/Manila')));
                $existing->fill([
                    'time_out' => $lastOut->format('H:i:s'),
                ]);
                if ($admin) {
                    $existing->updated_by = $admin->id;
                }
                $existing->save();
            }

            return $existing;
        }

        // Approved: record actual clock-out time for audit but preserve the approved
        // computed_minutes/computed_hours — those are the approved duration and must not
        // be overwritten by the buffer-affected rendered calculation.
        if ($existing && $existing->status === Overtime::STATUS_APPROVED) {
            $payloadApproved = [
                'schedule_end' => $data['schedule_end']->format('H:i:s'),
                'time_out' => $data['time_out']->format('H:i:s'),
            ];
            if ($admin) {
                $payloadApproved['updated_by'] = $admin->id;
            }
            $existing->fill($payloadApproved);
            $existing->save();

            return $existing;
        }

        if ($existing && $existing->status === Overtime::STATUS_REJECTED) {
            return $existing;
        }

        $payload = [
            'schedule_end' => $data['schedule_end']->format('H:i:s'),
            'time_out' => $data['time_out']->format('H:i:s'),
            'computed_minutes' => $data['minutes'],
            'computed_hours' => $data['hours'],
            'ot_type' => $existing?->ot_type ?? 'regular',
            'status' => $existing?->status ?? Overtime::STATUS_PENDING,
        ];

        if (! $existing) {
            $payload['created_by'] = $admin?->id;
        } elseif ($admin) {
            $payload['updated_by'] = $admin->id;
        }

        return Overtime::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => $data['date'],
            ],
            $payload
        );
    }
}
