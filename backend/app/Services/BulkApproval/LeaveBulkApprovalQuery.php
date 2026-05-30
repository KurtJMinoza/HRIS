<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\LeaveApprovalService;
use App\Services\OrgApprovalWorkflowService;
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
            $query->where('end_date', '>=', $fromDate);
        }
        if (is_string($toDate) && $toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $query->where('start_date', '<=', $toDate);
        }

        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'general');
        if ($scopedEmployeeIds !== null) {
            $query->whereIn('user_id', $scopedEmployeeIds);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    public function approvableIds(User $actor, array $filters, int $max = 2000): array
    {
        if ((string) ($actor->hr_role ?? '') === HrRole::AdminHr->value) {
            return $this->fastAdminHrApprovableIds($actor, $filters, $max);
        }

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

    /**
     * @param  array<string, mixed>  $filters
     */
    public function approvableCount(User $actor, array $filters): int
    {
        if ((string) ($actor->hr_role ?? '') === HrRole::AdminHr->value) {
            return (int) $this->fastAdminHrApprovableQuery($actor, $filters)
                ->count('leave_requests.id');
        }

        return count($this->approvableIds($actor, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    private function fastAdminHrApprovableIds(User $actor, array $filters, int $max): array
    {
        return $this->fastAdminHrApprovableQuery($actor, $filters)
            ->reorder()
            ->orderBy('leave_requests.id')
            ->limit($max)
            ->pluck('leave_requests.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * SQL-only path for Admin HR final approval previews and select-all.
     *
     * @param  array<string, mixed>  $filters
     */
    private function fastAdminHrApprovableQuery(User $actor, array $filters): Builder
    {
        $query = $this->baseQuery($actor, array_merge($filters, ['status' => LeaveRequest::STATUS_PENDING]));
        $query->setEagerLoads([]);
        $query->reorder();
        $query
            ->select('leave_requests.id')
            ->join('org_approval_records as current_approval', function ($join): void {
                $join->on('current_approval.request_id', '=', 'leave_requests.id')
                    ->where('current_approval.module_type', '=', OrgApprovalWorkflowService::MODULE_LEAVE)
                    ->where('current_approval.approval_status', '=', OrgApprovalRecord::STATUS_PENDING)
                    ->where('current_approval.approver_role', '=', HrRole::AdminHr->value);
            })
            ->where('leave_requests.status', LeaveRequest::STATUS_PENDING)
            ->where('leave_requests.pending_approval', true)
            ->whereNull('leave_requests.rejected_at')
            ->distinct();

        return $query;
    }
}
