<?php

namespace App\Support;

use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleAssignmentSnapshot;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
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

        $workingSchedule->loadMissing('days');

        if ($workingSchedule->isFlexiblePerDay()) {
            return self::buildFlexibleFromDays($workingSchedule);
        }

        $restDays = is_array($workingSchedule->rest_days) ? $workingSchedule->rest_days : [];

        $breaks = [];
        foreach ($workingSchedule->getAllBreaks() as $b) {
            $breaks[] = [
                'start' => $b['start'],
                'end' => $b['end'],
                'is_paid' => $b['is_paid'] ?? false,
            ];
        }

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
                'breaks' => $breaks,
                'work_blocks' => $workingSchedule->getWorkBlocks(),
                'shift_type' => $workingSchedule->shift_type ?? 'fixed',
                'crosses_midnight' => (bool) ($workingSchedule->crosses_midnight ?? false),
                'expected_paid_minutes' => $workingSchedule->expected_paid_minutes,
                'half_day_threshold_minutes' => $workingSchedule->effective_half_day_threshold,
                'grace_period_minutes' => $workingSchedule->grace_period_minutes,
                'early_timein_minutes' => $workingSchedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $workingSchedule->late_allowance_minutes,
                'early_timeout_minutes' => $workingSchedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $workingSchedule->overtime_buffer_minutes ?? 15,
                'rest_days' => $restDays,
                'flexible_required_minutes' => $workingSchedule->flexible_required_minutes,
            ];
        }

        return $dayConfig;
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    private static function buildFlexibleFromDays(WorkingSchedule $workingSchedule): array
    {
        $byDay = $workingSchedule->days->keyBy('day_of_week');
        $restDays = [];

        foreach (self::DAY_KEYS as $key) {
            if (! $byDay->has($key)) {
                continue;
            }
            /** @var WorkingScheduleDay $dayRow */
            $dayRow = $byDay->get($key);
            if (! $dayRow->is_working_day) {
                $restDays[] = $key;
            }
        }

        if ($restDays === [] && is_array($workingSchedule->rest_days)) {
            $restDays = $workingSchedule->rest_days;
        }

        $dayConfig = [];
        foreach (self::DAY_KEYS as $key) {
            $dayRow = $byDay->get($key);
            if (! $dayRow instanceof WorkingScheduleDay || ! $dayRow->is_working_day) {
                $dayConfig[$key] = null;

                continue;
            }

            $dayConfig[$key] = $dayRow->toDayConfig($workingSchedule, $restDays);
        }

        return $dayConfig;
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    public static function resolve(User $user): ?array
    {
        $today = Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')));

        return self::resolveForDate($user, $today) ?? self::resolveLegacy($user);
    }

    /**
     * Resolve the immutable schedule snapshot that applies to a specific attendance/payroll date.
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    public static function resolveForDate(User|int $employee, Carbon|string $attendanceDate): ?array
    {
        $employeeId = $employee instanceof User ? (int) $employee->id : (int) $employee;
        $date = $attendanceDate instanceof Carbon
            ? $attendanceDate->toDateString()
            : Carbon::parse($attendanceDate)->toDateString();

        $assignment = EmployeeScheduleAssignment::query()
            ->active()
            ->where('employee_id', $employeeId)
            ->coveringDate($date)
            ->with('snapshot')
            ->orderByDesc('effective_start_date')
            ->orderByDesc('id')
            ->first();

        if ($assignment?->snapshot instanceof ScheduleAssignmentSnapshot) {
            $payload = $assignment->snapshot->schedule_payload;
            if (is_array($payload) && $payload !== []) {
                return $payload;
            }
        }

        if ($employee instanceof User) {
            return self::resolveLegacy($employee);
        }

        $user = User::query()->with('workingSchedule')->find($employeeId);

        return $user ? self::resolveLegacy($user) : null;
    }

    public static function assignmentForDate(User|int $employee, Carbon|string $attendanceDate): ?EmployeeScheduleAssignment
    {
        $employeeId = $employee instanceof User ? (int) $employee->id : (int) $employee;
        $date = $attendanceDate instanceof Carbon
            ? $attendanceDate->toDateString()
            : Carbon::parse($attendanceDate)->toDateString();

        return EmployeeScheduleAssignment::query()
            ->active()
            ->where('employee_id', $employeeId)
            ->coveringDate($date)
            ->with('snapshot')
            ->orderByDesc('effective_start_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    private static function resolveLegacy(User $user): ?array
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
