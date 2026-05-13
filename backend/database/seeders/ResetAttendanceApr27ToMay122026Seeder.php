<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * One-off destructive reset:
 * - Deletes all rows in attendance_logs.
 * - Re-seeds clock_in / clock_out for every roster-eligible active user from 2026-04-27 through 2026-05-12 (inclusive).
 *
 * Rules:
 * - Skip Sundays and any day the employee's schedule marks as rest (null / missing shift).
 * - If the employee has no schedule, behave like Mon-Sat defaults (still skip Sundays only).
 * - Clock-in (local Manila): random between 07:00 and 08:06, inclusive (never after 08:06).
 * - Clock-out (local): either exactly 17:00:00, or random 17:00–18:30 (never before 17:00).
 *
 * Rows use {@see AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION} with `verified_at`, `created_at`, and `updated_at`
 * aligned per punch so the login kiosk Recent Activity (`GET /api/attendance/kiosk/recent`) lists them correctly.
 */
class ResetAttendanceApr27ToMay122026Seeder extends Seeder
{
    private const RANGE_START_YEAR = 2026;

    private const RANGE_START_MONTH = 4;

    private const RANGE_START_DAY = 27;

    private const RANGE_END_YEAR = 2026;

    private const RANGE_END_MONTH = 5;

    private const RANGE_END_DAY = 12;

    /** Minute-of-day bounds for randomized clock-in (local). */
    private const CLOCK_IN_EARLIEST_MIN = 7 * 60; // 07:00

    private const CLOCK_IN_LATEST_MIN = 8 * 60 + 6; // 08:06

    public function run(): void
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        $start = Carbon::create(self::RANGE_START_YEAR, self::RANGE_START_MONTH, self::RANGE_START_DAY, 0, 0, 0, $tz)->startOfDay();
        $end = Carbon::create(self::RANGE_END_YEAR, self::RANGE_END_MONTH, self::RANGE_END_DAY, 0, 0, 0, $tz)->startOfDay();

        // Full wipe (portable vs TRUNCATE + FK toggles across drivers).
        AttendanceLog::query()->delete();

        User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($start, $end, $tz) {
                foreach ($users as $user) {
                    $this->seedEmployeeRange($user, $start, $end, $tz);
                }
            });

        $this->command?->info(sprintf(
            'Deleted all attendance_logs and seeded punches %s → %s (%s) for roster-eligible active users.',
            $start->toDateString(),
            $end->toDateString(),
            $tz
        ));
    }

    private function seedEmployeeRange(User $user, Carbon $start, Carbon $end, string $tz): void
    {
        $schedule = EmployeeScheduleResolver::resolve($user);
        $hasSchedule = is_array($schedule) && $schedule !== [];

        $rows = [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if ($this->isRestDayForUser($cursor, $hasSchedule, $schedule)) {
                $cursor->addDay();

                continue;
            }

            $dateKey = $cursor->toDateString();

            $inLocal = $this->randomClockInLocal($dateKey, $tz);
            $outLocal = $this->randomClockOutLocal($dateKey, $tz);

            if ($outLocal->lessThanOrEqualTo($inLocal)) {
                $outLocal = Carbon::parse($dateKey.' 17:05:00', $tz);
            }

            $inUtc = $inLocal->copy()->utc()->toDateTimeString();
            $outUtc = $outLocal->copy()->utc()->toDateTimeString();

            $rows[] = [
                'user_id' => $user->id,
                'type' => AttendanceLog::TYPE_CLOCK_IN,
                'verified_at' => $inUtc,
                'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                'created_at' => $inUtc,
                'updated_at' => $inUtc,
            ];

            $rows[] = [
                'user_id' => $user->id,
                'type' => AttendanceLog::TYPE_CLOCK_OUT,
                'verified_at' => $outUtc,
                'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                'created_at' => $outUtc,
                'updated_at' => $outUtc,
            ];

            $cursor->addDay();
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                AttendanceLog::query()->insert($chunk);
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $schedule
     */
    private function isRestDayForUser(Carbon $cursor, bool $hasSchedule, ?array $schedule): bool
    {
        if ((int) $cursor->dayOfWeek === Carbon::SUNDAY) {
            return true;
        }

        if (! $hasSchedule) {
            return false;
        }

        $dayKey = EmployeeScheduleResolver::dayKeyForDate($cursor);
        $dayCfg = $schedule[$dayKey] ?? null;

        if (! is_array($dayCfg)) {
            return true;
        }

        $inRaw = trim((string) ($dayCfg['in'] ?? ''));
        $outRaw = trim((string) ($dayCfg['out'] ?? ''));

        return $inRaw === '' || $outRaw === '';
    }

    private function randomClockInLocal(string $dateKey, string $tz): Carbon
    {
        $minuteOfDay = random_int(self::CLOCK_IN_EARLIEST_MIN, self::CLOCK_IN_LATEST_MIN);
        $hour = intdiv($minuteOfDay, 60);
        $minute = $minuteOfDay % 60;
        $second = random_int(0, 59);

        return Carbon::parse(
            sprintf('%s %02d:%02d:%02d', $dateKey, $hour, $minute, $second),
            $tz
        );
    }

    private function randomClockOutLocal(string $dateKey, string $tz): Carbon
    {
        if (random_int(0, 1) === 0) {
            return Carbon::parse($dateKey.' 17:00:00', $tz);
        }

        $extraMinutes = random_int(0, 90);

        return Carbon::parse($dateKey.' 17:00:00', $tz)->addMinutes($extraMinutes)->addSeconds(random_int(0, 59));
    }
}
