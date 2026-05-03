<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off: clock-in 7:51 AM and clock-out 5:01 PM on 2026-04-30 for employee Alren (first_name).
 * Replaces any existing attendance_logs for that user on that local calendar day.
 */
class AlrenApril30AttendanceSeeder extends Seeder
{
    private const LOCAL_DATE = '2026-04-30';

    public function run(): void
    {
        $user = User::query()
            ->whereRaw('LOWER(TRIM(first_name)) = ?', ['alren'])
            ->first();

        if (! $user instanceof User) {
            $this->command?->warn('Employee Alren was not found (first_name case-insensitive match).');

            return;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $dayStartUtc = Carbon::parse(self::LOCAL_DATE, $tz)->startOfDay()->utc();
        $dayEndUtc = Carbon::parse(self::LOCAL_DATE, $tz)->endOfDay()->utc();

        $clockInUtc = Carbon::parse(self::LOCAL_DATE.' 07:51:00', $tz)->utc();
        $clockOutUtc = Carbon::parse(self::LOCAL_DATE.' 17:01:00', $tz)->utc();

        DB::transaction(function () use ($user, $dayStartUtc, $dayEndUtc, $clockInUtc, $clockOutUtc): void {
            AttendanceLog::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($dayStartUtc, $dayEndUtc): void {
                    $q->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
                        ->orWhereBetween('created_at', [$dayStartUtc, $dayEndUtc]);
                })
                ->delete();

            $stampIn = $clockInUtc->toDateTimeString();
            $stampOut = $clockOutUtc->toDateTimeString();

            AttendanceLog::query()->insert([
                [
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_IN,
                    'verified_at' => $stampIn,
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                    'created_at' => $stampIn,
                    'updated_at' => $stampIn,
                ],
                [
                    'user_id' => $user->id,
                    'type' => AttendanceLog::TYPE_CLOCK_OUT,
                    'verified_at' => $stampOut,
                    'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
                    'created_at' => $stampOut,
                    'updated_at' => $stampOut,
                ],
            ]);
        });

        $this->command?->info(sprintf(
            'Seeded %s attendance for user id %d (%s): IN 7:51 AM, OUT 5:01 PM (%s).',
            self::LOCAL_DATE,
            $user->id,
            $user->name,
            $tz
        ));
    }
}
