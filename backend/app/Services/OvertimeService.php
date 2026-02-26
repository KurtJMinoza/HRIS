<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;

class OvertimeService
{
    /**
     * Compute overtime for a given user and date based on attendance logs and schedule.
     *
     * Rule:
     * - No OT if:
     *   - No schedule with an out time
     *   - No clock-in
     *   - No clock-out
     *   - Employee is on approved leave covering this date
     * - Otherwise:
     *   - OT starts 1 hour after scheduled end.
     *   - Only minutes after that 1-hour mark are counted as overtime.
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

        // Block overtime when there is an approved leave covering this date.
        $hasApprovedLeave = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->exists();

        if ($hasApprovedLeave) {
            return null;
        }

        $schedule = $user->schedule;
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }

        $dayKey = AttendanceControllerDayKeys::forDate($date);
        $daySchedule = $schedule[$dayKey] ?? null;

        if (! is_array($daySchedule) || empty($daySchedule['out'])) {
            return null;
        }

        // Require at least one clock-in and one clock-out for the date.
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

        // Scheduled end for the day (per schedule; supports night shift).
        $scheduledEnd = Carbon::parse($dateKey . ' ' . $daySchedule['out']);

        $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
            ? (int) $daySchedule['overtime_buffer_minutes']
            : (int) config('attendance.overtime_buffer_minutes', 15);
        $overtimeStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);

        if ($lastClockOut->lessThanOrEqualTo($overtimeStart)) {
            return null;
        }

        $minutes = (int) $overtimeStart->diffInMinutes($lastClockOut);
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

        // If there is no overtime for this date, we may need to clear a pending record,
        // but we keep approved/rejected records untouched.
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
            }

            return $existing;
        }

        // Do not override a finalized (approved/rejected) overtime record.
        if ($existing && $existing->status !== Overtime::STATUS_PENDING) {
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

/**
 * Small helper to avoid duplicating the DAY_KEYS constant in multiple services.
 */
final class AttendanceControllerDayKeys
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public static function forDate(Carbon $date): string
    {
        return self::DAY_KEYS[(int) $date->format('w')];
    }
}

