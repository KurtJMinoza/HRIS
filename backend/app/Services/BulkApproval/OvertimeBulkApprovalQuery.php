<?php

namespace App\Services\BulkApproval;

use App\Models\Overtime;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OvertimeApprovalService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class OvertimeBulkApprovalQuery
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
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
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
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
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
}
