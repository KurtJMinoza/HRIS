<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\Carbon;

/**
 * Resolves an employee's per-day schedule from legacy JSON or WorkingSchedule (admin module).
 */
final class EmployeeScheduleResolver
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    public static function buildFromWorkingSchedule(?WorkingSchedule $workingSchedule): ?array
    {
        if (! $workingSchedule) {
            return null;
        }

        $restDays = $workingSchedule->rest_days ?? [];
        $dayConfig = [];

        foreach (self::DAY_KEYS as $key) {
            if (in_array($key, $restDays, true)) {
                $dayConfig[$key] = null;

                continue;
            }

            $dayConfig[$key] = [
                'in' => $workingSchedule->time_in,
                'out' => $workingSchedule->time_out,
                'break_start' => $workingSchedule->break_start,
                'break_end' => $workingSchedule->break_end,
                'grace_period_minutes' => $workingSchedule->grace_period_minutes,
                'early_timein_minutes' => $workingSchedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $workingSchedule->late_allowance_minutes,
                'early_timeout_minutes' => $workingSchedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $workingSchedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $dayConfig;
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    public static function resolve(User $user): ?array
    {
        if ($user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
            $fromTemplate = self::buildFromWorkingSchedule($user->workingSchedule);
            if ($fromTemplate !== null) {
                return $fromTemplate;
            }
            // Orphan FK or missing row: fall back to legacy JSON so rate math is not stuck on config fallback (22).
        }

        $schedule = $user->schedule;
        if (is_array($schedule) && $schedule !== []) {
            return $schedule;
        }

        return null;
    }

    public static function dayKeyForDate(Carbon $date): string
    {
        // format('w') is 0–6; clamp defensively so a bad/edge datetime never yields an out-of-range index (e.g. -1).
        $w = max(0, min(6, (int) $date->format('w')));

        return self::DAY_KEYS[$w];
    }
}
