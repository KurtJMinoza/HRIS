<?php

namespace App\Services\BulkApproval;

use App\Services\BulkApproval\Contracts\ApprovableBulkQuery;
use App\Enums\HrRole;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeApprovalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class OvertimeBulkApprovalQuery implements ApprovableBulkQuery
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OvertimeApprovalService $overtimeApprovalService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function baseQuery(User $actor, array $filters): Builder
    {
        $from = null;
        $to = null;
        $fromRaw = $filters['date_from'] ?? $filters['from_date'] ?? null;
        $toRaw = $filters['date_to'] ?? $filters['to_date'] ?? null;
        if (is_string($fromRaw) && $fromRaw !== '') {
            $from = Carbon::parse($fromRaw)->startOfDay();
        }
        if (is_string($toRaw) && $toRaw !== '') {
            $to = Carbon::parse($toRaw)->endOfDay();
        }
        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }

        $department = $filters['department'] ?? null;
        if (is_string($department) && $department !== '') {
            $query->whereHas('user', fn ($q) => $q->where('department', $department));
        }

        $employeeId = $filters['employee_id'] ?? null;
        if ($employeeId !== null && $employeeId !== '') {
            $query->where('user_id', (int) $employeeId);
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $otType = $filters['ot_type'] ?? null;
        if (is_string($otType) && $otType !== '') {
            $query->where('ot_type', $otType);
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

        $filters = array_merge($filters, ['status' => Overtime::STATUS_PENDING]);
        $query = $this->baseQuery($actor, $filters);
        $query->where('status', Overtime::STATUS_PENDING);

        $ids = [];
        $query->select('id')->orderBy('id')->chunkById(200, function ($rows) use ($actor, &$ids, $max) {
            $items = Overtime::query()
                ->with([
                    'user',
                    'filedBy',
                    'firstApprover',
                    'secondApprover',
                ])
                ->whereIn('id', $rows->pluck('id'))
                ->get();

            foreach ($items as $overtime) {
                if (count($ids) >= $max) {
                    return false;
                }
                if ($this->overtimeApprovalService->canApprove($actor, $overtime)) {
                    $ids[] = (int) $overtime->id;
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
                ->count('overtimes.id');
        }

        return count($this->approvableIds($actor, $filters));
    }

    /**
     * @param  int[]  $requestedIds
     * @return int[]
     */
    public function approvableSelectedIds(User $actor, array $requestedIds, int $max = 500): array
    {
        $requestedIds = array_values(array_filter(
            array_unique(array_map('intval', $requestedIds)),
            static fn (int $id): bool => $id > 0,
        ));
        if ($requestedIds === []) {
            return [];
        }

        $items = $this->baseQuery($actor, ['status' => Overtime::STATUS_PENDING])
            ->whereIn('overtimes.id', $requestedIds)
            ->where('overtimes.status', Overtime::STATUS_PENDING)
            ->limit($max)
            ->get();

        $ids = [];
        foreach ($items as $overtime) {
            if ($this->overtimeApprovalService->canApprove($actor, $overtime)) {
                $ids[] = (int) $overtime->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    private function fastAdminHrApprovableIds(User $actor, array $filters, int $max): array
    {
        return $this->fastAdminHrApprovableQuery($actor, $filters)
            ->reorder()
            ->orderBy('overtimes.id')
            ->limit($max)
            ->pluck('overtimes.id')
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
        $query = $this->baseQuery($actor, array_merge($filters, ['status' => Overtime::STATUS_PENDING]));
        $query->setEagerLoads([]);
        $query->reorder();
        $query
            ->select('overtimes.id')
            ->join('org_approval_records as current_approval', function ($join): void {
                $join->on('current_approval.request_id', '=', 'overtimes.id')
                    ->where('current_approval.module_type', '=', OrgApprovalWorkflowService::MODULE_OVERTIME)
                    ->where('current_approval.approval_status', '=', OrgApprovalRecord::STATUS_PENDING)
                    ->where('current_approval.approver_role', '=', HrRole::AdminHr->value);
            })
            ->where('overtimes.status', Overtime::STATUS_PENDING)
            ->where('overtimes.pending_approval', true)
            ->whereNull('overtimes.rejected_at')
            ->distinct();

        return $query;
    }
}
