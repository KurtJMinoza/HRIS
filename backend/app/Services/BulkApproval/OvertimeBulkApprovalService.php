<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Support\HrApprovalStages;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OvertimeBulkApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * @param  int[]  $ids
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[], fallback_ids: int[]}
     */
    public function approveFinalAdminHr(User $actor, array $ids, ?string $remarks): array
    {
        if (! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return $this->emptyResult($ids);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->emptyResult();
        }

        $records = Overtime::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('status', Overtime::STATUS_PENDING)
            ->where('pending_approval', true)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');

        $pendingRecords = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $approvedIds = [];
        $fallbackIds = [];
        $failedItems = [];
        $skipped = 0;
        $approvalRecordIds = [];
        $upsertRows = [];
        $auditRows = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $approverName = $actor->display_name ?? $actor->name;

        foreach ($ids as $id) {
            $overtime = $records->get($id);
            if (! $overtime instanceof Overtime) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Overtime request is not pending or was not found.'];
                continue;
            }

            $pending = $pendingRecords->get($id);
            if (! $pending instanceof OrgApprovalRecord) {
                $fallbackIds[] = $id;
                continue;
            }

            if ($pending->approver_role !== HrRole::AdminHr->value) {
                $fallbackIds[] = $id;
                continue;
            }

            if (! $overtime->user instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];
                continue;
            }

            $approvedIds[] = $id;
            $approvalRecordIds[] = (int) $pending->id;
            $upsertRows[] = [
                'id' => $id,
                'status' => Overtime::STATUS_APPROVED,
                'pending_approval' => false,
                'approval_stage' => HrApprovalStages::APPROVED,
                'second_approver_id' => $actor->id,
                'second_approved_at' => $now,
                'approved_by' => $actor->id,
                'approved_at' => $now,
                'approved_ot_start' => $this->timeValue($overtime->schedule_end),
                'approved_ot_end' => $this->timeValue($overtime->expected_end_time),
                'approved_ot_hours' => round((float) ($overtime->computed_hours ?? 0), 2),
                'remarks' => $remarks ?: $overtime->remarks,
                'locked_at' => $now,
                'updated_by' => $actor->id,
                'updated_at' => $now,
            ];
            $auditRows[] = [
                'overtime_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $overtime->user_id,
                'action' => 'approve_final',
                'details' => $remarks,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($approvedIds !== []) {
            DB::transaction(function () use ($approvalRecordIds, $actor, $remarks, $now, $approverName, $upsertRows, $auditRows): void {
                OrgApprovalRecord::query()
                    ->whereIn('id', $approvalRecordIds)
                    ->update([
                        'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                        'remarks' => $remarks,
                        'approved_at' => $now,
                        'approver_id' => $actor->id,
                        'approver_name' => $approverName,
                        'updated_at' => $now,
                    ]);

                DB::table('overtimes')->upsert(
                    $upsertRows,
                    ['id'],
                    [
                        'status',
                        'pending_approval',
                        'approval_stage',
                        'second_approver_id',
                        'second_approved_at',
                        'approved_by',
                        'approved_at',
                        'approved_ot_start',
                        'approved_ot_end',
                        'approved_ot_hours',
                        'remarks',
                        'locked_at',
                        'updated_by',
                        'updated_at',
                    ],
                );

                if ($auditRows !== []) {
                    OvertimeApprovalAudit::query()->insert($auditRows);
                }
            });
        }

        return [
            'approved' => count($approvedIds),
            'skipped' => $skipped,
            'failed' => 0,
            'failed_items' => $failedItems,
            'approved_ids' => $approvedIds,
            'fallback_ids' => $fallbackIds,
        ];
    }

    private function timeValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('H:i:s');
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    /**
     * @param  int[]  $fallbackIds
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[], fallback_ids: int[]}
     */
    private function emptyResult(array $fallbackIds = []): array
    {
        return [
            'approved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_items' => [],
            'approved_ids' => [],
            'fallback_ids' => $fallbackIds,
        ];
    }
}
