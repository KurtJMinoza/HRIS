<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use App\Support\LeaveScheduleSupport;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes attendance for all employees except Alren Namata.
 * Seeds Alren's punches for Mar 1–Apr 30 using {@see EmployeeScheduleResolver}
 * (schedule module template or legacy JSON on the user). Rest days are skipped.
 *
 * Connected tables trimmed for consistency (same date window / non‑keep employees):
 * attendance_correction_audits, attendance_corrections, overtimes, payroll_daily_records, payroll_daily_logs.
 */
class AttendanceResetKeepAlrenSeeder extends Seeder
{
    private const RANGE_YEAR = 2026;

    private const RANGE_START_MONTH = 3;

    private const RANGE_END_MONTH = 4;

    public function run(): void
    {
        $keepUser = User::query()
            ->with('workingSchedule')
            ->whereRaw('LOWER(TRIM(first_name)) = ?', ['alren'])
            ->first();

        if (! $keepUser instanceof User) {
            $this->command?->warn('Keep employee Alren was not found (first_name case-insensitive exact match expected). Aborting.');

            return;
        }

        $keepId = (int) $keepUser->id;

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $rangeStartLocal = Carbon::create(self::RANGE_YEAR, self::RANGE_START_MONTH, 1, 0, 0, 0, $tz)->startOfDay();
        $rangeEndLocal = Carbon::create(self::RANGE_YEAR, self::RANGE_END_MONTH, 30, 23, 59, 59, $tz)->endOfDay();
        $startDateOnly = $rangeStartLocal->toDateString();
        $endDateOnly = $rangeEndLocal->toDateString();

        $effectiveSchedule = EmployeeScheduleResolver::resolve($keepUser);
        if ($effectiveSchedule === null || $effectiveSchedule === []) {
            $effectiveSchedule = LeaveScheduleSupport::resolveEffectiveScheduleForLeave($keepUser->fresh(['workingSchedule']));
        }

        DB::transaction(function () use (
            $keepId,
            $effectiveSchedule,
            $tz,
            $rangeStartLocal,
            $rangeEndLocal,
            $startDateOnly,
            $endDateOnly
        ): void {
            AttendanceLog::query()->where('user_id', '!=', $keepId)->delete();
            AttendanceLog::query()->where('user_id', $keepId)->delete();

            if (Schema::hasTable('attendance_correction_audits')) {
                DB::table('attendance_correction_audits')->where('employee_id', '!=', $keepId)->delete();
                DB::table('attendance_correction_audits')->where('employee_id', '=', $keepId)
                    ->where(function ($q) use ($startDateOnly, $endDateOnly): void {
                        $q->whereNull('date')
                            ->orWhereDate('date', '<', $startDateOnly)
                            ->orWhereDate('date', '>', $endDateOnly);
                    })->delete();
            }

            if (Schema::hasTable('attendance_corrections')) {
                DB::table('attendance_corrections')->where('user_id', '!=', $keepId)->delete();
                DB::table('attendance_corrections')->where('user_id', '=', $keepId)
                    ->where(function ($q) use ($startDateOnly, $endDateOnly): void {
                        $q->whereNull('date')
                            ->orWhereDate('date', '<', $startDateOnly)
                            ->orWhereDate('date', '>', $endDateOnly);
                    })->delete();
            }

            if (Schema::hasTable('overtimes')) {
                DB::table('overtimes')->where('user_id', '!=', $keepId)->delete();
                DB::table('overtimes')->where('user_id', '=', $keepId)
                    ->where(function ($q) use ($startDateOnly, $endDateOnly): void {
                        $q->whereDate('date', '<', $startDateOnly)
                            ->orWhereDate('date', '>', $endDateOnly);
                    })->delete();
            }

            if (Schema::hasTable('payroll_daily_records')) {
                DB::table('payroll_daily_records')->where('user_id', '!=', $keepId)->delete();
                DB::table('payroll_daily_records')->where('user_id', '=', $keepId)
                    ->where(function ($q) use ($startDateOnly, $endDateOnly): void {
                        $q->whereDate('date', '<', $startDateOnly)
                            ->orWhereDate('date', '>', $endDateOnly);
                    })->delete();
            }

            if (Schema::hasTable('payroll_daily_logs')) {
                DB::table('payroll_daily_logs')->where('user_id', '!=', $keepId)->delete();
                DB::table('payroll_daily_logs')->where('user_id', '=', $keepId)
                    ->where(function ($q) use ($startDateOnly, $endDateOnly): void {
                        $q->whereDate('date', '<', $startDateOnly)
                            ->orWhereDate('date', '>', $endDateOnly);
                    })->delete();
            }

            $punchesUtc = $this->buildScheduledPunchesUtc(
                $effectiveSchedule ?? [],
                $tz,
                $rangeStartLocal->copy()->startOfDay(),
                $rangeEndLocal->copy()->startOfDay()
            );

            if ($punchesUtc === []) {
                return;
            }

            $now = now();
            $rows = [];
            foreach ($punchesUtc as $pair) {
                $rows[] = [
                    'user_id' => $keepId,
                    'type' => AttendanceLog::TYPE_CLOCK_IN,
                    'verified_at' => $pair['in_utc'],
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $rows[] = [
                    'user_id' => $keepId,
                    'type' => AttendanceLog::TYPE_CLOCK_OUT,
                    'verified_at' => $pair['out_utc'],
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                AttendanceLog::query()->insert($chunk);
            }
        });

        $this->command?->info(sprintf(
            'Attendance reset complete. Kept employee id %d (%s). Seeded punches from %s through %s (schedule‑based working days only).',
            $keepId,
            $keepUser->name,
            $startDateOnly,
            $endDateOnly
        ));
    }

    /**
     * @param  array<string, mixed>  $schedule  Per-day entries from resolver; rest days are null.
     * @return list<array{in_utc: string, out_utc: string}>
     */
    private function buildScheduledPunchesUtc(array $schedule, string $tz, Carbon $periodStartDay, Carbon $periodEndDay): array
    {
        $period = CarbonPeriod::create($periodStartDay, '1 day', $periodEndDay);
        $punches = [];

        foreach ($period as $day) {
            $key = EmployeeScheduleResolver::dayKeyForDate($day);
            $dayCfg = isset($schedule[$key]) ? $schedule[$key] : null;
            if (! is_array($dayCfg)) {
                continue;
            }

            $inRaw = trim((string) ($dayCfg['in'] ?? ''));
            $outRaw = trim((string) ($dayCfg['out'] ?? ''));
            if ($inRaw === '' || $outRaw === '') {
                continue;
            }

            $dateKey = $day->toDateString();
            try {
                $timeInLocal = Carbon::parse($dateKey.' '.$inRaw, $tz);
                $timeOutLocal = Carbon::parse($dateKey.' '.$outRaw, $tz);
            } catch (\Throwable) {
                continue;
            }

            $punches[] = [
                'in_utc' => $timeInLocal->copy()->utc()->toDateTimeString(),
                'out_utc' => $timeOutLocal->copy()->utc()->toDateTimeString(),
            ];
        }

        return $punches;
    }
}
