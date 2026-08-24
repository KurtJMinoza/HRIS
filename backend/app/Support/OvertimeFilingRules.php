<?php

namespace App\Support;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Late OT filing window and past-date attendance rules (attendance remains source of truth).
 */
final class OvertimeFilingRules
{
    public static function filingWindowDays(): int
    {
        $n = (int) config('attendance.overtime_filing_window_days', 7);

        return max(1, $n);
    }

    /**
     * Earliest calendar date employees may select when filing OT (inclusive).
     */
    public static function earliestAllowedOvertimeDate(string $tz): string
    {
        return Carbon::now($tz)->startOfDay()->subDays(self::filingWindowDays())->toDateString();
    }

    /**
     * @throws ValidationException
     */
    public static function assertDateWithinFilingWindow(string $dateYmd, string $tz): void
    {
        $today = Carbon::now($tz)->startOfDay();
        $d = Carbon::parse($dateYmd, $tz)->startOfDay();

        if ($d->greaterThan($today)) {
            throw ValidationException::withMessages([
                'date' => ['Overtime date cannot be in the future.'],
            ]);
        }

        if ($d->lessThan($today)) {
            $daysOld = (int) $d->diffInDays($today);
            if ($daysOld > self::filingWindowDays()) {
                throw ValidationException::withMessages([
                    'date' => [
                        sprintf(
                            'This date is outside the late-filing window (%d calendar days). Contact HR for older dates.',
                            self::filingWindowDays()
                        ),
                    ],
                ]);
            }
        }
    }

    /**
     * Clock-in / clock-out presence for a calendar date: merges scan logs with
     * approved manual attendance (Admin → Attendance corrections), same basis as monitoring.
     *
     * @return array{has_clock_in: bool, has_clock_out: bool, last_clock_out_at: ?\Carbon\Carbon}
     */
    public static function clockInOutPresenceForDate(int $userId, string $dateYmd, string $tz): array
    {
        // Same basis as AttendanceSessionService / payroll: verified_at punches + active approved corrections (incl. manual attendance).
        $dayStartUtc = Carbon::parse($dateYmd, $tz)->startOfDay()->setTimezone('UTC');
        $dayEndUtc = Carbon::parse($dateYmd, $tz)->endOfDay()->setTimezone('UTC');

        $logs = AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('verified_at')
            ->get();

        $hasClockIn = false;
        $hasClockOut = false;
        $lastClockOutAt = null;

        foreach ($logs as $log) {
            $stamp = $log->verified_at ?? $log->created_at;
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $hasClockIn = true;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $hasClockOut = true;
                if ($lastClockOutAt === null || $stamp->greaterThan($lastClockOutAt)) {
                    $lastClockOutAt = $stamp;
                }
            }
        }

        $correctionQuery = AttendanceCorrection::query()
            ->where('user_id', $userId)
            ->whereDate('date', $dateYmd)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at');

        if (Schema::hasColumn('attendance_corrections', 'reversed_at')) {
            $correctionQuery->whereNull('reversed_at');
        }

        $correction = $correctionQuery
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();

        if ($correction !== null) {
            if ($correction->time_in !== null) {
                $hasClockIn = true;
            }
            if ($correction->time_out !== null) {
                $hasClockOut = true;
                $corrOut = $correction->time_out;
                if ($lastClockOutAt === null || $corrOut->greaterThan($lastClockOutAt)) {
                    $lastClockOutAt = $corrOut;
                }
            }
        }

        return [
            'has_clock_in' => $hasClockIn,
            'has_clock_out' => $hasClockOut,
            'last_clock_out_at' => $lastClockOutAt,
        ];
    }

    public static function pastDateHasCompletedAttendance(int $userId, string $dateYmd, string $tz): bool
    {
        $p = self::clockInOutPresenceForDate($userId, $dateYmd, $tz);

        return $p['has_clock_in'] && $p['has_clock_out'];
    }

    /**
     * Past OT requests require a completed day (clock-in and clock-out) on that date.
     *
     * @throws ValidationException
     */
    public static function assertPastDateHasCompletedAttendance(int $userId, string $dateYmd, string $tz): void
    {
        if (! self::pastDateHasCompletedAttendance($userId, $dateYmd, $tz)) {
            throw ValidationException::withMessages([
                'date' => [
                    'For past dates, that day must have both clock-in and clock-out before you can file overtime (late filing).',
                ],
            ]);
        }
    }

    public static function userHasClockInAndOutOnDate(int $userId, string $dateYmd, string $tz): bool
    {
        return self::pastDateHasCompletedAttendance($userId, $dateYmd, $tz);
    }
}
