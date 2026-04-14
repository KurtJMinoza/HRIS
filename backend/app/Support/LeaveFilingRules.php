<?php

namespace App\Support;

use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Leave filing for any role (employee, org heads, HR): earliest start is tomorrow (attendance TZ);
 * cannot cover dates where the leave subject already completed attendance (clock-in + clock-out),
 * including approved manual corrections; cannot overlap existing pending or approved leave for that user.
 */
final class LeaveFilingRules
{
    public static function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Leave start must be strictly after "today" in the attendance timezone (earliest: tomorrow).
     *
     * @throws ValidationException
     */
    public static function assertLeaveStartsAfterToday(string $startDateYmd): void
    {
        $tz = self::attendanceTimezone();
        $today = Carbon::now($tz)->startOfDay();
        $start = Carbon::parse($startDateYmd, $tz)->startOfDay();

        if ($start->lessThanOrEqualTo($today)) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'Leave can only be filed for future dates. The earliest start date is tomorrow.',
                ],
            ]);
        }
    }

    /**
     * No calendar day in the inclusive range may already have completed attendance (clock-in and clock-out).
     *
     * @throws ValidationException
     */
    public static function assertRangeHasNoCompletedAttendance(int $userId, string $startDateYmd, string $endDateYmd): void
    {
        $tz = self::attendanceTimezone();
        $start = Carbon::parse($startDateYmd, $tz)->startOfDay();
        $end = Carbon::parse($endDateYmd, $tz)->startOfDay();

        if ($end->lessThan($start)) {
            return;
        }

        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $dateKey = $cursor->toDateString();
            if (OvertimeFilingRules::pastDateHasCompletedAttendance($userId, $dateKey, $tz)) {
                throw ValidationException::withMessages([
                    'start_date' => [
                        sprintf(
                            'Leave cannot include %s: that day already has complete attendance (clock-in and clock-out), including from approved corrections. Choose other dates.',
                            $cursor->format('M j, Y')
                        ),
                    ],
                ]);
            }
            $cursor->addDay();
        }
    }

    /**
     * Block duplicate filings: no overlap with existing pending or approved leave for the same user.
     *
     * @throws ValidationException
     */
    public static function assertNoOverlappingPendingOrApprovedLeave(int $userId, string $startDateYmd, string $endDateYmd): void
    {
        $overlap = LeaveRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
            ->whereDate('start_date', '<=', $endDateYmd)
            ->whereDate('end_date', '>=', $startDateYmd)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'These dates overlap an existing pending or approved leave. Choose different dates or wait until the other request is resolved.',
                ],
            ]);
        }
    }
}
