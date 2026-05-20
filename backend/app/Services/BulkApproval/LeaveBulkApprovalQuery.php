<?php

namespace App\Services\BulkApproval;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\LeaveApprovalService;
use Illuminate\Database\Eloquent\Builder;

class LeaveBulkApprovalQuery
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly LeaveApprovalService $leaveApprovalService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function baseQuery(User $actor, array $filters): Builder
    {
        $query = LeaveRequest::query()
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ]);

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && in_array($status, [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_APPROVED,
            LeaveRequest::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        $fromDate = $filters['date_from'] ?? $filters['from_date'] ?? null;
        $toDate = $filters['date_to'] ?? $filters['to_date'] ?? null;
        if (is_string($fromDate) && $fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $query->whereDate('end_date', '>=', $fromDate);
        }
        if (is_string($toDate) && $toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $query->whereDate('start_date', '<=', $toDate);
        }

        $scope = User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
        $this->dataScopeService->restrictEmployeeQuery($actor, $scope);
        $query->whereIn('user_id', $scope->select('users.id'));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    public function approvableIds(User $actor, array $filters, int $max = 2000): array
    {
        $filters = array_merge($filters, ['status' => LeaveRequest::STATUS_PENDING]);
        $query = $this->baseQuery($actor, $filters);
        $query->where('status', LeaveRequest::STATUS_PENDING);

        $ids = [];
        $query->select('id')->orderBy('id')->chunkById(200, function ($rows) use ($actor, &$ids, $max) {
            $items = LeaveRequest::query()
                ->with([
                    'user',
                    'filedBy',
                    'firstApprover',
                    'secondApprover',
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
                ])
                ->whereIn('id', $rows->pluck('id'))
                ->get();

            foreach ($items as $leave) {
                if (count($ids) >= $max) {
                    return false;
                }
                if ($this->leaveApprovalService->canApprove($actor, $leave)) {
                    $ids[] = (int) $leave->id;
                }
            }

            return count($ids) < $max;
        });

        return $ids;
    }
}
