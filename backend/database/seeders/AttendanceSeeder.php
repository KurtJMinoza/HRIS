<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

/**
 * Seed complete attendance punches for all employees from Mar 1 to May 31, 2026.
 *
 * Rules applied:
 * - Monday to Saturday only (Sundays are skipped as rest day)
 * - Time in: 08:00:00
 * - Time out: 17:00:00
 * - "Regular Day Shift" template behavior (Mon-Sat, 8:00-17:00)
 *
 * Notes:
 * - This app stores attendance as punch logs in attendance_logs.
 * - "Present" status and rendered hours (8.00) are derived from punches by
 *   attendance/payroll services; there is no direct status/rendered_hours column
 *   in attendance_logs to set here.
 */
class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $startLocal = Carbon::create(2026, 3, 1, 0, 0, 0, $tz)->startOfDay();
        $endLocal = Carbon::create(2026, 5, 31, 23, 59, 59, $tz)->endOfDay();

        // Delete existing seeded range first to keep output deterministic.
        $rangeStartUtc = $startLocal->copy()->utc()->toDateTimeString();
        $rangeEndUtc = $endLocal->copy()->utc()->toDateTimeString();

        // Pre-compute all working-day punches once, then reuse per employee.
        $workingPunches = $this->buildWorkingDayPunches($tz);
        $now = now();

        User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($employees) use ($rangeStartUtc, $rangeEndUtc, $workingPunches, $now) {
                foreach ($employees as $employee) {
                    AttendanceLog::query()
                        ->where('user_id', $employee->id)
                        ->whereIn('type', [AttendanceLog::TYPE_CLOCK_IN, AttendanceLog::TYPE_CLOCK_OUT])
                        ->whereBetween('verified_at', [$rangeStartUtc, $rangeEndUtc])
                        ->delete();

                    $rows = [];
                    foreach ($workingPunches as $punch) {
                        $rows[] = [
                            'user_id' => $employee->id,
                            'type' => AttendanceLog::TYPE_CLOCK_IN,
                            'verified_at' => $punch['in_utc'],
                            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $rows[] = [
                            'user_id' => $employee->id,
                            'type' => AttendanceLog::TYPE_CLOCK_OUT,
                            'verified_at' => $punch['out_utc'],
                            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    // One insert per employee for faster seeding.
                    AttendanceLog::query()->insert($rows);
                }
            });
    }

    /**
     * Build working-day punch datetimes (UTC) for Mar 1 - May 31, 2026.
     */
    private function buildWorkingDayPunches(string $tz): array
    {
        $start = Carbon::create(2026, 3, 1, 0, 0, 0, $tz)->startOfDay();
        $end = Carbon::create(2026, 5, 31, 0, 0, 0, $tz)->startOfDay();
        $period = CarbonPeriod::create($start, '1 day', $end);

        $punches = [];
        foreach ($period as $date) {
            // Skip Sundays (rest day).
            if ((int) $date->dayOfWeek === Carbon::SUNDAY) {
                continue;
            }

            $dateKey = $date->toDateString();
            $timeInLocal = Carbon::parse($dateKey.' 08:00:00', $tz);
            $timeOutLocal = Carbon::parse($dateKey.' 17:00:00', $tz);

            $punches[] = [
                'in_utc' => $timeInLocal->copy()->utc()->toDateTimeString(),
                'out_utc' => $timeOutLocal->copy()->utc()->toDateTimeString(),
            ];
        }

        return $punches;
    }
}

