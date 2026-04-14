<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Populate synthetic attendance for all active employees for March 2–31, 2026.
 *
 * Rules:
 * - No records on Sundays.
 * - Respect the employee's assigned Schedule module working days/hours when available.
 *   - If the schedule marks the day as a rest day (null or missing shift times), skip that date.
 *   - If the employee has no schedule at all, default to 08:00–17:00 (Mon–Sat, excluding Sundays).
 * - For each present day, create:
 *   - clock_in at shift start
 *   - clock_out at shift end
 *
 * Notes:
 * - The system derives "Present" from having valid punches; attendance_logs has no explicit status column.
 * - We use verified_at so Daily Computation / Payroll reads the punches.
 */
class PopulateAttendanceMar2026Seeder extends Seeder
{
    public function run(): void
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        $start = Carbon::create(2026, 3, 2, 0, 0, 0, $tz)->startOfDay();
        $end = Carbon::create(2026, 3, 31, 0, 0, 0, $tz)->startOfDay();

        // Process employees in chunks to avoid memory spikes.
        User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($start, $end, $tz) {
                foreach ($users as $user) {
                    $this->seedEmployeeRange($user, $start, $end, $tz);
                }
            });
    }

    private function seedEmployeeRange(User $user, Carbon $start, Carbon $end, string $tz): void
    {
        // Resolve schedule once per employee (Schedule module / legacy JSON).
        $schedule = EmployeeScheduleResolver::resolve($user);
        $hasSchedule = is_array($schedule) && $schedule !== [];

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            // Hard rule: exclude Sundays.
            if ((int) $cursor->dayOfWeek === Carbon::SUNDAY) {
                $dateKey = $cursor->toDateString();
                DB::transaction(function () use ($user, $tz, $dateKey) {
                    // Ensure Sundays stay punch-free (rest day) even if old logs existed.
                    $dayStartLocal = Carbon::parse($dateKey.' 00:00:00', $tz)->startOfDay();
                    $dayEndLocal = Carbon::parse($dateKey.' 23:59:59', $tz)->endOfDay();
                    $dayStartUtc = $dayStartLocal->copy()->utc();
                    $dayEndUtc = $dayEndLocal->copy()->utc();
                    AttendanceLog::query()
                        ->where('user_id', $user->id)
                        ->whereIn('type', [AttendanceLog::TYPE_CLOCK_IN, AttendanceLog::TYPE_CLOCK_OUT])
                        ->whereBetween('verified_at', [$dayStartUtc->toDateTimeString(), $dayEndUtc->toDateTimeString()])
                        ->delete();
                });
                $cursor->addDay();

                continue;
            }

            $dayKey = EmployeeScheduleResolver::dayKeyForDate($cursor);

            // Determine shift times.
            $shiftIn = '08:00:00';
            $shiftOut = '17:00:00';

            if ($hasSchedule) {
                $dayCfg = $schedule[$dayKey] ?? null;
                if (! is_array($dayCfg)) {
                    // Scheduled rest day (or missing config) → no attendance
                    $dateKey = $cursor->toDateString();
                    DB::transaction(function () use ($user, $tz, $dateKey) {
                        $dayStartLocal = Carbon::parse($dateKey.' 00:00:00', $tz)->startOfDay();
                        $dayEndLocal = Carbon::parse($dateKey.' 23:59:59', $tz)->endOfDay();
                        $dayStartUtc = $dayStartLocal->copy()->utc();
                        $dayEndUtc = $dayEndLocal->copy()->utc();
                        AttendanceLog::query()
                            ->where('user_id', $user->id)
                            ->whereIn('type', [AttendanceLog::TYPE_CLOCK_IN, AttendanceLog::TYPE_CLOCK_OUT])
                            ->whereBetween('verified_at', [$dayStartUtc->toDateTimeString(), $dayEndUtc->toDateTimeString()])
                            ->delete();
                    });
                    $cursor->addDay();

                    continue;
                }

                $inRaw = trim((string) ($dayCfg['in'] ?? ''));
                $outRaw = trim((string) ($dayCfg['out'] ?? ''));
                if ($inRaw === '' || $outRaw === '') {
                    $dateKey = $cursor->toDateString();
                    DB::transaction(function () use ($user, $tz, $dateKey) {
                        $dayStartLocal = Carbon::parse($dateKey.' 00:00:00', $tz)->startOfDay();
                        $dayEndLocal = Carbon::parse($dateKey.' 23:59:59', $tz)->endOfDay();
                        $dayStartUtc = $dayStartLocal->copy()->utc();
                        $dayEndUtc = $dayEndLocal->copy()->utc();
                        AttendanceLog::query()
                            ->where('user_id', $user->id)
                            ->whereIn('type', [AttendanceLog::TYPE_CLOCK_IN, AttendanceLog::TYPE_CLOCK_OUT])
                            ->whereBetween('verified_at', [$dayStartUtc->toDateTimeString(), $dayEndUtc->toDateTimeString()])
                            ->delete();
                    });
                    $cursor->addDay();

                    continue;
                }

                // Normalize to H:i:s (accepts "08:00" or "08:00:00").
                $shiftIn = strlen($inRaw) === 5 ? ($inRaw.':00') : $inRaw;
                $shiftOut = strlen($outRaw) === 5 ? ($outRaw.':00') : $outRaw;
            }

            $dateKey = $cursor->toDateString();
            $timeIn = Carbon::parse($dateKey.' '.$shiftIn, $tz);
            $timeOut = Carbon::parse($dateKey.' '.$shiftOut, $tz);

            // Support overnight schedules (timeOut <= timeIn).
            if ($timeOut->lessThanOrEqualTo($timeIn)) {
                $timeOut->addDay();
            }

            DB::transaction(function () use ($user, $timeIn, $timeOut, $tz, $dateKey) {
                // Remove existing punches for the same local calendar date to keep the dataset deterministic.
                // IMPORTANT: verified_at is stored as an instant (UTC). Build UTC bounds for the local day.
                $dayStartLocal = Carbon::parse($dateKey.' 00:00:00', $tz)->startOfDay();
                $dayEndLocal = Carbon::parse($dateKey.' 23:59:59', $tz)->endOfDay();
                $dayStartUtc = $dayStartLocal->copy()->utc();
                $dayEndUtc = $dayEndLocal->copy()->utc();
                AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->whereIn('type', [AttendanceLog::TYPE_CLOCK_IN, AttendanceLog::TYPE_CLOCK_OUT])
                    ->whereBetween('verified_at', [$dayStartUtc->toDateTimeString(), $dayEndUtc->toDateTimeString()])
                    ->delete();

                AttendanceLog::query()->create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_IN,
                    // Store as UTC instant to avoid timezone shifts in reporting.
                    'verified_at' => $timeIn->copy()->utc()->toDateTimeString(),
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                ]);

                AttendanceLog::query()->create([
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_OUT,
                    'verified_at' => $timeOut->copy()->utc()->toDateTimeString(),
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                ]);
            });

            $cursor->addDay();
        }
    }
}
