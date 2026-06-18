<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmailTriggerService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Support\HrApprovalStages;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OvertimeBulkApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly DataScopeService $dataScopeService,
        private readonly EmailTriggerService $emailTrigger,
    ) {}

    /**
     * @param  int[]  $ids
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[], fallback_ids: int[]}
     */
    public function approveFinalAdminHr(User $actor, array $ids, ?string $remarks): array
    {
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

        $pendingRecordGroups = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->groupBy('request_id');
        $pendingRecords = $pendingRecordGroups
            ->map(fn ($records) => $records->first())
            ->keyBy('request_id');

        $approvedIds = [];
        $failedItems = [];
        $skipped = 0;
        $approvalRecordIds = [];
        $upsertRows = [];
        $firstStepRows = [];
        $auditRows = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $approverName = $actor->display_name ?? $actor->name;
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $scopedEmployees = is_array($scopedEmployeeIds)
            ? array_fill_keys(array_map('intval', $scopedEmployeeIds), true)
            : null;

        foreach ($ids as $id) {
            $overtime = $records->get($id);
            if (! $overtime instanceof Overtime) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Overtime request is not pending or was not found.'];

                continue;
            }

            $pending = $pendingRecords->get($id);
            if (! $pending instanceof OrgApprovalRecord) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'No pending approval step was found.'];

                continue;
            }

            if (! $overtime->user instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];

                continue;
            }
            if (is_array($scopedEmployees) && ! isset($scopedEmployees[(int) $overtime->user_id])) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'This request is outside your approval scope.'];

                continue;
            }

            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $pending,
                $overtime->user,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
            );
            if (! $authorization['allowed']) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve at this stage.'];

                continue;
            }

            $approvedIds[] = $id;
            $approvalRecordIds[] = (int) $pending->id;
            $isFinal = $pending->approver_role === HrRole::AdminHr->value;
            if ($isFinal) {
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
            } else {
                $nextPending = $pendingRecordGroups->get($id)?->first(
                    fn (OrgApprovalRecord $record): bool => (int) $record->id !== (int) $pending->id,
                );
                if (! $nextPending instanceof OrgApprovalRecord) {
                    array_pop($approvedIds);
                    array_pop($approvalRecordIds);
                    $skipped++;
                    $failedItems[] = ['request_id' => $id, 'reason' => 'The next approval step could not be resolved.'];

                    continue;
                }
                $firstStepRows[] = [
                    'id' => $id,
                    'first_approver_id' => $overtime->first_approver_id ?: $actor->id,
                    'first_approved_at' => $nextPending->approver_role === HrRole::AdminHr->value
                        ? $now
                        : $overtime->first_approved_at,
                    'approval_stage' => $nextPending->approver_role === HrRole::AdminHr->value
                        ? HrApprovalStages::PENDING_SECOND
                        : HrApprovalStages::PENDING_FIRST,
                    'remarks' => $remarks ?: $overtime->remarks,
                    'updated_by' => $actor->id,
                    'updated_at' => $now,
                ];
            }
            $auditRows[] = [
                'overtime_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $overtime->user_id,
                'action' => $isFinal ? 'approve_final' : 'approve_first',
                'details' => $remarks,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($approvedIds !== []) {
            DB::transaction(function () use ($approvalRecordIds, $actor, $remarks, $now, $approverName, $upsertRows, $firstStepRows, $auditRows): void {
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

                if ($upsertRows !== []) {
                    foreach ($upsertRows as $row) {
                        $id = $row['id'];
                        unset($row['id']);
                        Overtime::query()->whereKey($id)->update($row);
                    }
                }

                if ($firstStepRows !== []) {
                    foreach ($firstStepRows as $row) {
                        $id = $row['id'];
                        unset($row['id']);
                        Overtime::query()->whereKey($id)->update($row);
                    }
                }

                if ($auditRows !== []) {
                    OvertimeApprovalAudit::query()->insert($auditRows);
                }
            });
        }

        // Dispatch email notifications after DB commit
        $upsertIds = array_column($upsertRows, 'id');
        if ($upsertIds !== []) {
            $finalOvertimes = Overtime::query()->whereIn('id', $upsertIds)->get();
            foreach ($finalOvertimes as $ot) {
                $this->emailTrigger->overtimeFinalApproved($ot);
            }
        }
        if ($firstStepRows !== []) {
            $firstStepIds = array_column($firstStepRows, 'id');
            $nextPendingRecords = OrgApprovalRecord::query()
                ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
                ->whereIn('request_id', $firstStepIds)
                ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                ->orderBy('sequence_order')
                ->get()
                ->unique('request_id')
                ->keyBy('request_id');
            $firstStepOvertimes = Overtime::query()->whereIn('id', $firstStepIds)->get()->keyBy('id');
            foreach ($firstStepIds as $otId) {
                $ot = $firstStepOvertimes->get($otId);
                $next = $nextPendingRecords->get($otId);
                if ($ot && $next) {
                    $this->emailTrigger->overtimeNeedsNextApproval($ot, $next);
                }
            }
        }

        return [
            'approved' => count($approvedIds),
            'skipped' => $skipped,
            'failed' => 0,
            'failed_items' => $failedItems,
            'approved_ids' => $approvedIds,
            'fallback_ids' => [],
        ];
    }

    /**
     * @param  int[]  $ids
     * @return array{rejected: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, rejected_ids: int[]}
     */
    public function rejectMany(User $actor, array $ids, string $remarks): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $records = Overtime::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('status', Overtime::STATUS_PENDING)
            ->where('pending_approval', true)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');
        $pending = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $rejectedIds = [];
        $approvalRecordIds = [];
        $auditRows = [];
        $failedItems = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $scopedEmployees = is_array($scopedEmployeeIds)
            ? array_fill_keys(array_map('intval', $scopedEmployeeIds), true)
            : null;

        foreach ($ids as $id) {
            $overtime = $records->get($id);
            $approval = $pending->get($id);
            if (! $overtime instanceof Overtime || ! $overtime->user instanceof User || ! $approval instanceof OrgApprovalRecord) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'Overtime request is no longer pending or was not found.'];

                continue;
            }
            if (is_array($scopedEmployees) && ! isset($scopedEmployees[(int) $overtime->user_id])) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'This request is outside your approval scope.'];

                continue;
            }
            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $approval,
                $overtime->user,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
            );
            if (! $authorization['allowed']) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to reject at this stage.'];

                continue;
            }

            $rejectedIds[] = $id;
            $approvalRecordIds[] = (int) $approval->id;
            $auditRows[] = [
                'overtime_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $overtime->user_id,
                'action' => 'reject',
                'details' => $remarks,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rejectedIds !== []) {
            DB::transaction(function () use ($approvalRecordIds, $rejectedIds, $actor, $remarks, $now, $auditRows): void {
                OrgApprovalRecord::query()->whereIn('id', $approvalRecordIds)->update([
                    'approval_status' => OrgApprovalRecord::STATUS_REJECTED,
                    'remarks' => $remarks,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $actor->display_name ?? $actor->name,
                    'updated_at' => $now,
                ]);
                Overtime::query()->whereIn('id', $rejectedIds)->update([
                    'status' => Overtime::STATUS_REJECTED,
                    'pending_approval' => false,
                    'approval_stage' => HrApprovalStages::REJECTED,
                    'rejected_at' => $now,
                    'rejected_by' => $actor->id,
                    'rejection_note' => $remarks,
                    'remarks' => $remarks,
                    'locked_at' => $now,
                    'updated_by' => $actor->id,
                    'updated_at' => $now,
                ]);
                OvertimeApprovalAudit::query()->insert($auditRows);
            });
        }

        return [
            'rejected' => count($rejectedIds),
            'skipped' => count($failedItems),
            'failed' => 0,
            'failed_items' => $failedItems,
            'rejected_ids' => $rejectedIds,
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
