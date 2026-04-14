<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkingSchedule;

/**
 * Detects schedule overlaps for payroll-safe assignment.
 * Rule: conflict exists if new.start < existing.end AND new.end > existing.start.
 * Handles overnight shifts (e.g. 22:00–06:00).
 */
class ScheduleOverlapService
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Convert "H:i" to minutes since midnight. Overnight: time_out < time_in.
     */
    public static function timeToMinutes(string $time): int
    {
        $parts = array_map('intval', explode(':', trim($time)));
        $h = $parts[0] ?? 0;
        $m = $parts[1] ?? 0;

        return $h * 60 + $m;
    }

    /**
     * Check if two time ranges overlap.
     * Overnight: if end <= start, end is next day (add 24*60).
     */
    public static function rangesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        $sA = self::timeToMinutes($startA);
        $eA = self::timeToMinutes($endA);
        $sB = self::timeToMinutes($startB);
        $eB = self::timeToMinutes($endB);

        if ($eA <= $sA) {
            $eA += 24 * 60;
        }
        if ($eB <= $sB) {
            $eB += 24 * 60;
        }

        return $sA < $eB && $eA > $sB;
    }

    /**
     * Get effective time range from WorkingSchedule for a given day.
     */
    public static function getScheduleRangeForDay(WorkingSchedule $schedule, string $dayKey): ?array
    {
        $restDays = $schedule->rest_days ?? [];
        if (in_array($dayKey, $restDays, true)) {
            return null;
        }

        return [
            'in' => $schedule->time_in,
            'out' => $schedule->time_out,
        ];
    }

    /**
     * Get effective time range from schedule JSON for a given day.
     */
    public static function getCustomScheduleRangeForDay(?array $schedule, string $dayKey): ?array
    {
        if (! is_array($schedule) || empty($schedule[$dayKey])) {
            return null;
        }
        $day = $schedule[$dayKey];
        if (! is_array($day) || empty($day['in']) || empty($day['out'])) {
            return null;
        }

        return [
            'in' => $day['in'],
            'out' => $day['out'],
        ];
    }

    /**
     * Check if new schedule overlaps with employee's current schedule on any working day.
     */
    public static function employeeHasOverlap(User $employee, WorkingSchedule $newSchedule): bool
    {
        $currentWs = $employee->working_schedule_id
            ? WorkingSchedule::find($employee->working_schedule_id)
            : null;
        $customSchedule = is_array($employee->schedule) && ! empty($employee->schedule)
            ? $employee->schedule
            : null;

        if (! $currentWs && ! $customSchedule) {
            return false;
        }

        foreach (self::DAY_KEYS as $day) {
            $newRange = self::getScheduleRangeForDay($newSchedule, $day);
            if (! $newRange) {
                continue;
            }

            $existingRange = null;
            if ($currentWs) {
                $existingRange = self::getScheduleRangeForDay($currentWs, $day);
            }
            if (! $existingRange && $customSchedule) {
                $existingRange = self::getCustomScheduleRangeForDay($customSchedule, $day);
            }

            if ($existingRange && self::rangesOverlap(
                $newRange['in'],
                $newRange['out'],
                $existingRange['in'],
                $existingRange['out']
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get assignment status for an employee when assigning a schedule.
     * Returns: 'available' | 'assigned' | 'conflict' | 'same_shift'
     */
    public static function getAssignmentStatus(User $employee, WorkingSchedule $assignSchedule): string
    {
        $currentWs = $employee->working_schedule_id
            ? WorkingSchedule::find($employee->working_schedule_id)
            : null;
        $hasCustomSchedule = is_array($employee->schedule) && ! empty($employee->schedule);

        if (! $currentWs && ! $hasCustomSchedule) {
            return 'available';
        }

        if ($currentWs && (int) $currentWs->id === (int) $assignSchedule->id) {
            return 'same_shift';
        }

        if (self::employeeHasOverlap($employee, $assignSchedule)) {
            return 'conflict';
        }

        return 'assigned';
    }
}
