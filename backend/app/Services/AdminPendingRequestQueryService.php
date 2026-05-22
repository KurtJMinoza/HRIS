<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pending / list-scope queries for admin roster modules.
 * Keeps {@see DashboardController::pendingReviewPreviews} aligned with list endpoints:
 * {@see \App\Http\Controllers\Admin\LeaveController::index},
 * {@see \App\Http\Controllers\Admin\OvertimeController::index},
 * {@see \App\Http\Controllers\PresenceFilingController::adminIndex}.
 */
class AdminPendingRequestQueryService
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * Roster subject scope for leave lists (matches LeaveController::index).
     */
    public function applyLeaveRosterScope(User $actor, Builder $query): void
    {
        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'general');
        if ($scopedEmployeeIds !== null) {
            $query->whereIn('user_id', $scopedEmployeeIds);
        }
    }

    /**
     * Roster subject scope for overtime lists (matches OvertimeController::index).
     */
    public function applyOvertimeRosterScope(User $actor, Builder $query): void
    {
        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'general');
        if ($scopedEmployeeIds !== null) {
            $query->whereIn('user_id', $scopedEmployeeIds);
        }
    }

    /**
     * Same scope + filters as LeaveController::index when status=pending (no extra predicates).
     *
     * @return Builder<LeaveRequest>
     */
    public function leaveRequestsPendingForActor(User $actor): Builder
    {
        $query = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_PENDING);

        $this->applyLeaveRosterScope($actor, $query);

        return $query;
    }

    /**
     * Same scope + filters as OvertimeController::index when status=pending and no date/dept/employee/ot_type filters.
     *
     * @return Builder<Overtime>
     */
    public function overtimesPendingForActor(User $actor): Builder
    {
        $query = Overtime::query()
            ->where('status', Overtime::STATUS_PENDING)
            ->orderByDesc('date')
            ->orderByDesc('id');

        $this->applyOvertimeRosterScope($actor, $query);

        return $query;
    }

    /**
     * Same scope + filters as PresenceFilingController::adminIndex when status=pending
     * and no from_date / to_date / issue_type / q filters.
     *
     * @return Builder<AttendanceCorrection>
     */
    public function attendanceCorrectionsPendingForActor(User $actor): Builder
    {
        $query = AttendanceCorrection::query()
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->orderByDesc('filed_at');

        $this->dataScopeService->restrictAttendanceCorrectionsQuery($actor, $query);

        return $query;
    }
}
