<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Rest / working days from {@see EmployeeScheduleResolver} for leave filing and credit billing.
 * If no schedule is on file, a configurable default week is used (typically Mon–Sat work, Sunday rest).
 */
final class LeaveScheduleSupport
{
    /** @var array<int, string> */
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public static function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * True when the user has JSON schedule or a WorkingSchedule-derived template from HR.
     */
    public static function hasAssignedWorkSchedule(User $user): bool
    {
        $resolved = EmployeeScheduleResolver::resolve($user);

        return $resolved !== null && $resolved !== [];
    }

    /**
     * When no HR schedule exists, leave rules use {@see defaultScheduleWhenMissing()}.
     */
    public static function isUsingDefaultScheduleFallback(User $user): bool
    {
        return ! self::hasAssignedWorkSchedule($user);
    }

    /**
     * Effective per-day schedule for leave/rest checks: assigned template or company default.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public static function resolveEffectiveScheduleForLeave(User $user): array
    {
        $resolved = EmployeeScheduleResolver::resolve($user);
        if ($resolved !== null && $resolved !== []) {
            return $resolved;
        }

        return self::defaultScheduleWhenMissing();
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private static function defaultScheduleWhenMissing(): array
    {
        $restKey = strtolower((string) config('leave.default_rest_day_key', 'sun'));
        if (! in_array($restKey, self::DAY_KEYS, true)) {
            $restKey = 'sun';
        }
        $dayConfig = [];
        foreach (self::DAY_KEYS as $key) {
            if ($key === $restKey) {
                $dayConfig[$key] = null;
            } else {
                $dayConfig[$key] = [
                    'in' => '09:00',
                    'out' => '18:00',
                ];
            }
        }

        return $dayConfig;
    }

    /**
     * True when the weekday has no shift start (`in`) — scheduled rest / off.
     */
    public static function isRestDayForUser(User $user, Carbon $date): bool
    {
        $schedule = self::resolveEffectiveScheduleForLeave($user);
        $key = EmployeeScheduleResolver::dayKeyForDate($date);
        $day = $schedule[$key] ?? null;
        if (! is_array($day)) {
            return true;
        }
        $in = trim((string) ($day['in'] ?? ''));

        return $in === '';
    }

    public static function isWorkingDay(User $user, Carbon|string $date): bool
    {
        $tz = self::attendanceTimezone();
        $d = $date instanceof Carbon
            ? $date->copy()->timezone($tz)->startOfDay()
            : Carbon::parse((string) $date, $tz)->startOfDay();

        return ! self::isRestDayForUser($user, $d);
    }

    public static function weekdayLabelFromDate(Carbon $date): string
    {
        $key = EmployeeScheduleResolver::dayKeyForDate($date);

        return self::weekdayLabel($key);
    }

    /**
     * Human-readable list e.g. "Sunday, Saturday" from template schedule keys.
     *
     * @param  array<int, string>|null  $keys
     */
    public static function formatRestDaysLabels(?array $keys): ?string
    {
        if ($keys === null || $keys === []) {
            return null;
        }
        $labels = [];
        foreach ($keys as $k) {
            $labels[] = self::weekdayLabel((string) $k);
        }

        return implode(', ', $labels);
    }

    public static function weekdayLabel(string $dayKey): string
    {
        return match (strtolower($dayKey)) {
            'sun' => 'Sunday',
            'mon' => 'Monday',
            'tue' => 'Tuesday',
            'wed' => 'Wednesday',
            'thu' => 'Thursday',
            'fri' => 'Friday',
            'sat' => 'Saturday',
            default => ucfirst($dayKey),
        };
    }

    /**
     * First date in [start, end] that is a scheduled rest day, or null.
     */
    public static function firstRestDayInRange(User $user, string $startYmd, string $endYmd): ?Carbon
    {
        $tz = self::attendanceTimezone();
        $start = Carbon::parse($startYmd, $tz)->startOfDay();
        $end = Carbon::parse($endYmd, $tz)->startOfDay();
        if ($end->lessThan($start)) {
            return null;
        }
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (self::isRestDayForUser($user, $cursor)) {
                return $cursor->copy();
            }
            $cursor->addDay();
        }

        return null;
    }

    public static function formatRestDayViolationMessage(Carbon $badDate): string
    {
        $formatted = $badDate->copy()->timezone(self::attendanceTimezone())->format('M j, Y');

        return sprintf(
            'You cannot file leave on %s because it is your scheduled rest day according to your work schedule.',
            $formatted
        );
    }

    /**
     * @throws ValidationException
     */
    public static function assertRangeHasNoRestDays(User $user, string $startYmd, string $endYmd): void
    {
        $bad = self::firstRestDayInRange($user, $startYmd, $endYmd);
        if ($bad === null) {
            return;
        }

        Log::info('leave.rest_day_validation_failed', [
            'user_id' => $user->id,
            'start_date' => $startYmd,
            'end_date' => $endYmd,
            'blocked_date' => $bad->toDateString(),
            'using_default_schedule' => self::isUsingDefaultScheduleFallback($user),
        ]);

        throw ValidationException::withMessages([
            'start_date' => [self::formatRestDayViolationMessage($bad)],
        ]);
    }

    /**
     * Billable scheduled working days in inclusive range (for credit-consuming leave).
     */
    public static function countWorkingDaysInclusive(User $user, string $startYmd, string $endYmd): int
    {
        return count(self::listWorkingDateStringsInRangeOrdered($user, $startYmd, $endYmd));
    }

    /**
     * Scheduled working dates from start to end (inclusive), chronological order.
     * Used to map paid-leave pool units to calendar days (first N days = paid, remainder = unpaid).
     *
     * @return array<int, string> Date strings Y-m-d
     */
    public static function listWorkingDateStringsInRangeOrdered(User $user, string $startYmd, string $endYmd): array
    {
        $tz = self::attendanceTimezone();
        $start = Carbon::parse($startYmd, $tz)->startOfDay();
        $end = Carbon::parse($endYmd, $tz)->startOfDay();
        if ($end->lessThan($start)) {
            return [];
        }
        $dates = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (! self::isRestDayForUser($user, $cursor)) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array{
     *   valid: bool,
     *   rest_day_hits: array<int, array{date: string, weekday_label: string}>,
     *   working_days_in_range: int,
     *   has_schedule: bool,
     *   using_default_schedule: bool,
     *   schedule_warning: string|null
     * }
     */
    public static function summarizeRangeForUser(User $user, string $startYmd, string $endYmd): array
    {
        $hasAssigned = self::hasAssignedWorkSchedule($user);
        $usingDefault = ! $hasAssigned;
        $scheduleWarning = $usingDefault ? trim((string) config('leave.schedule_missing_warning')) : null;
        if ($scheduleWarning === '') {
            $scheduleWarning = null;
        }

        $tz = self::attendanceTimezone();
        $start = Carbon::parse($startYmd, $tz)->startOfDay();
        $end = Carbon::parse($endYmd, $tz)->startOfDay();
        if ($end->lessThan($start)) {
            return [
                'valid' => false,
                'rest_day_hits' => [],
                'working_days_in_range' => 0,
                'has_schedule' => $hasAssigned,
                'using_default_schedule' => $usingDefault,
                'schedule_warning' => $scheduleWarning,
            ];
        }

        $hits = [];
        $working = 0;
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            if (self::isRestDayForUser($user, $cursor)) {
                $hits[] = [
                    'date' => $cursor->toDateString(),
                    'weekday_label' => self::weekdayLabelFromDate($cursor),
                ];
            } else {
                $working++;
            }
            $cursor->addDay();
        }

        return [
            'valid' => count($hits) === 0,
            'rest_day_hits' => $hits,
            'working_days_in_range' => $working,
            'has_schedule' => $hasAssigned,
            'using_default_schedule' => $usingDefault,
            'schedule_warning' => $scheduleWarning,
        ];
    }
}
