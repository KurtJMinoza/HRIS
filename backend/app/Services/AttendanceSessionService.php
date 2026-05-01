<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Overtime;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

/**
 * Resolves clock-in / clock-out for a calendar day in the attendance timezone.
 * Used by payroll, premium reports, and the rules engine so numbers stay aligned.
 *
 * Order matches Admin → Reports detailed rows:
 * 1) Device logs (clock-in on dateKey, then clock-out after that in).
 * 2) Overlay approved manual attendance (correction) for time_in / time_out when set.
 * 3) If still no clock-out, use approved overtime expected end (virtual punch-out).
 */
class AttendanceSessionService
{
    public function getTimesForDate(User $user, string $dateKey, ?string $tz = null): array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        $correction = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->where(function ($q) {
                $q->where('pending_approval', false)->orWhereNull('pending_approval');
            })
            ->whereNull('rejected_at')
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->first();

        $timeIn = null;
        $timeOut = null;

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $clockIn = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->orderBy('verified_at')
            ->first();

        if (! $clockIn) {
            $prevDayStart = $dayStart->copy()->subDay()->startOfDay();
            $prevDayEnd = $dayEnd->copy()->subDay()->endOfDay();
            $clockIn = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereBetween('verified_at', [$prevDayStart->setTimezone('UTC'), $prevDayEnd->setTimezone('UTC')])
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->orderBy('verified_at')
                ->first();
        }

        if ($clockIn) {
            $candidateIn = $clockIn->verified_at->copy()->timezone($tz);
            if ($candidateIn->toDateString() === $dateKey) {
                $timeIn = $candidateIn;
                $clockOut = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->where('verified_at', '>=', $timeIn->copy()->setTimezone('UTC'))
                    ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                    ->orderBy('verified_at')
                    ->first();
                if ($clockOut) {
                    $timeOut = $clockOut->verified_at->copy()->timezone($tz);
                }
            }
        }

        if ($correction) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in->copy()->timezone($tz);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out->copy()->timezone($tz);
            }
        }

        if ($timeIn !== null && $timeOut === null) {
            // Missing-in corrections often provide only the approved time-in while the actual
            // device clock-out remains in attendance_logs. Payroll must merge those sources so
            // undertime days (e.g. 08:00 correction + 09:42 clock-out) pay actual worked time.
            $clockOut = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->where('verified_at', '>=', $timeIn->copy()->setTimezone('UTC'))
                ->where('verified_at', '<=', Carbon::parse($dateKey, $tz)->addDay()->endOfDay()->setTimezone('UTC'))
                ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                ->orderBy('verified_at')
                ->first();
            if ($clockOut) {
                $timeOut = $clockOut->verified_at->copy()->timezone($tz);
            }
        }

        if ($timeIn !== null && $timeOut === null) {
            $timeOut = $this->virtualTimeOutFromApprovedOvertime($user, $dateKey, $tz);
        }

        if ($timeIn === null || $timeOut === null) {
            return [null, null];
        }

        return [$timeIn, $timeOut];
    }

    /**
     * When there is no clock-out log and no manual time_out, mirror Admin Reports: use approved OT expected end.
     */
    private function virtualTimeOutFromApprovedOvertime(User $user, string $dateKey, string $tz): ?Carbon
    {
        $ot = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->where('status', Overtime::STATUS_APPROVED)
            ->first();

        if (! $ot) {
            return null;
        }

        $user->loadMissing('workingSchedule');
        $effectiveSchedule = EmployeeScheduleResolver::resolve($user);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey, $tz));
        $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey])
            ? $effectiveSchedule[$dayKey]
            : null;

        return AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
            $ot,
            $dateKey,
            is_array($todaySchedule) ? $todaySchedule : null,
            $tz
        );
    }
}
