<?php

namespace App\Support;

use App\Models\LeaveRequest;
use Illuminate\Validation\ValidationException;

/**
 * Leave filing for any role (employee, org heads, HR): cannot overlap existing pending or approved leave for that user.
 */
final class LeaveFilingRules
{
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
