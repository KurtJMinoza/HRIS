<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\LeaveApprovalAudit;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\LeaveCreditService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\HrApprovalStages;
use App\Support\LeaveScheduleSupport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveBulkApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * @param  int[]  $ids
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[], fallback_ids: int[]}
     */
    public function approveFinalAdminHr(
        User $actor,
        array $ids,
        ?string $notes,
        bool $forceCredits = false,
        bool $bypassRestDays = false,
        ?string $restDayBypassReason = null,
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->emptyResult();
        }

        $leaves = LeaveRequest::query()
            ->with(['user.workingSchedule', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->where('pending_approval', true)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');

        $pendingRecordGroups = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->groupBy('request_id');
        $pendingRecords = $pendingRecordGroups
            ->map(fn ($records) => $records->first())
            ->keyBy('request_id');

        $approvedIds = [];
        $finalApprovedIds = [];
        $failedItems = [];
        $skipped = 0;
        $approvalRecordIds = [];
        $auditRows = [];
        $firstStepRows = [];
        $bypassIds = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $approverName = $actor->display_name ?? $actor->name;
        $bypassReason = trim((string) $restDayBypassReason);
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $scopedEmployees = is_array($scopedEmployeeIds)
            ? array_fill_keys(array_map('intval', $scopedEmployeeIds), true)
            : null;
        $lockedWindows = $this->payrollPeriodMutationGuard->lockedWindowErrors(
            $leaves->map(fn (LeaveRequest $leave): array => [
                'key' => (int) $leave->id,
                'user_id' => (int) $leave->user_id,
                'from' => Carbon::parse($leave->start_date)->startOfDay(),
                'to' => Carbon::parse($leave->end_date)->startOfDay(),
            ])->values()->all(),
        );

        foreach ($ids as $id) {
            $leave = $leaves->get($id);
            if (! $leave instanceof LeaveRequest) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Leave request is not pending or was not found.'];

                continue;
            }

            $pending = $pendingRecords->get($id);
            if (! $pending instanceof OrgApprovalRecord) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'No pending approval step was found.'];

                continue;
            }

            $employee = $leave->user;
            if (! $employee instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];

                continue;
            }
            if (is_array($scopedEmployees) && ! isset($scopedEmployees[(int) $employee->id])) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'This request is outside your approval scope.'];

                continue;
            }

            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $pending,
                $employee,
                OrgApprovalWorkflowService::MODULE_LEAVE,
            );
            if (! $authorization['allowed']) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve at this stage.'];

                continue;
            }

            if (isset($lockedWindows[$id])) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => $lockedWindows[$id]];

                continue;
            }

            $restDay = $leave->rest_day_bypass ? null : LeaveScheduleSupport::firstRestDayInRange(
                $employee,
                $leave->start_date->toDateString(),
                $leave->end_date->toDateString(),
            );
            if ($restDay !== null) {
                if (! $bypassRestDays || strlen($bypassReason) < 10) {
                    $skipped++;
                    $failedItems[] = [
                        'request_id' => $id,
                        'reason' => LeaveScheduleSupport::formatRestDayViolationMessage($restDay)
                            .' HR administrators may approve with a documented rest-day override.',
                    ];

                    continue;
                }
                $bypassIds[] = $id;
            }

            $approvedIds[] = $id;
            $approvalRecordIds[] = (int) $pending->id;
            $isFinal = $pending->approver_role === HrRole::AdminHr->value;
            if ($isFinal) {
                $finalApprovedIds[] = $id;
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
                    'first_approver_id' => $leave->first_approver_id ?: $actor->id,
                    'first_approved_at' => $nextPending->approver_role === HrRole::AdminHr->value
                        ? $now
                        : $leave->first_approved_at,
                    'approval_stage' => $nextPending->approver_role === HrRole::AdminHr->value
                        ? HrApprovalStages::PENDING_SECOND
                        : HrApprovalStages::PENDING_FIRST,
                    'updated_at' => $now,
                ];
            }
            $finalDetails = $notes;
            if (in_array($id, $bypassIds, true)) {
                $finalDetails = trim(($finalDetails ? $finalDetails.' — ' : '').'Rest-day approval override: '.$bypassReason);
            }
            $auditRows[] = [
                'leave_request_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $leave->user_id,
                'action' => $isFinal ? 'approve_final' : 'approve_first',
                'details' => $finalDetails,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($approvedIds !== []) {
            DB::transaction(function () use ($finalApprovedIds, $firstStepRows, $approvalRecordIds, $actor, $notes, $now, $approverName, $auditRows, $bypassIds, $bypassReason): void {
                OrgApprovalRecord::query()
                    ->whereIn('id', $approvalRecordIds)
                    ->update([
                        'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                        'remarks' => $notes,
                        'approved_at' => $now,
                        'approver_id' => $actor->id,
                        'approver_name' => $approverName,
                        'updated_at' => $now,
                    ]);

                $leaveUpdate = [
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'pending_approval' => false,
                    'approval_stage' => HrApprovalStages::APPROVED,
                    'second_approver_id' => $actor->id,
                    'second_approved_at' => $now,
                    'reviewed_at' => $now,
                    'reviewed_by' => $actor->id,
                    'updated_at' => $now,
                ];
                if ($notes !== null && $notes !== '') {
                    $leaveUpdate['notes'] = $notes;
                }

                if ($finalApprovedIds !== []) {
                    LeaveRequest::query()
                        ->whereIn('id', $finalApprovedIds)
                        ->update($leaveUpdate);
                }

                if ($firstStepRows !== []) {
                    DB::table('leave_requests')->upsert(
                        $firstStepRows,
                        ['id'],
                        ['first_approver_id', 'first_approved_at', 'approval_stage', 'updated_at'],
                    );
                }

                if ($bypassIds !== []) {
                    LeaveRequest::query()
                        ->whereIn('id', $bypassIds)
                        ->update([
                            'rest_day_bypass' => true,
                            'rest_day_bypass_reason' => $bypassReason,
                            'rest_day_bypass_by' => $actor->id,
                            'rest_day_bypass_at' => $now,
                            'updated_at' => $now,
                        ]);
                }

                if ($auditRows !== []) {
                    LeaveApprovalAudit::query()->insert($auditRows);
                }
            });
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
    public function rejectMany(User $actor, array $ids, string $reason): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $leaves = LeaveRequest::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->where('pending_approval', true)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');
        $pending = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $rejectedIds = [];
        $recordIds = [];
        $auditRows = [];
        $failedItems = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $scopedEmployees = is_array($scopedEmployeeIds)
            ? array_fill_keys(array_map('intval', $scopedEmployeeIds), true)
            : null;

        foreach ($ids as $id) {
            $leave = $leaves->get($id);
            $record = $pending->get($id);
            if (! $leave instanceof LeaveRequest || ! $leave->user instanceof User || ! $record instanceof OrgApprovalRecord) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'Leave request is no longer pending or was not found.'];

                continue;
            }
            if (is_array($scopedEmployees) && ! isset($scopedEmployees[(int) $leave->user_id])) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'This request is outside your approval scope.'];

                continue;
            }

            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $record,
                $leave->user,
                OrgApprovalWorkflowService::MODULE_LEAVE,
            );
            if (! $authorization['allowed']) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to reject at this stage.'];

                continue;
            }

            $rejectedIds[] = $id;
            $recordIds[] = (int) $record->id;
            $auditRows[] = [
                'leave_request_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $leave->user_id,
                'action' => 'reject',
                'details' => $reason,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rejectedIds !== []) {
            DB::transaction(function () use ($recordIds, $rejectedIds, $actor, $reason, $now, $auditRows): void {
                OrgApprovalRecord::query()->whereIn('id', $recordIds)->update([
                    'approval_status' => OrgApprovalRecord::STATUS_REJECTED,
                    'remarks' => $reason,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $actor->display_name ?? $actor->name,
                    'updated_at' => $now,
                ]);
                LeaveRequest::query()->whereIn('id', $rejectedIds)->update([
                    'status' => LeaveRequest::STATUS_REJECTED,
                    'pending_approval' => false,
                    'approval_stage' => HrApprovalStages::REJECTED,
                    'rejected_at' => $now,
                    'rejected_by' => $actor->id,
                    'rejection_note' => $reason,
                    'reviewed_at' => $now,
                    'reviewed_by' => $actor->id,
                    'updated_at' => $now,
                ]);
                LeaveApprovalAudit::query()->insert($auditRows);
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

    public static function deductCreditsAfterFastApproval(array $leaveIds, int $actorId, bool $forceCredits): void
    {
        $actor = User::query()->find($actorId);
        $service = app(LeaveCreditService::class);
        LeaveRequest::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $leaveIds))))
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->orderBy('id')
            ->chunkById(100, function ($leaves) use ($service, $actor, $forceCredits): void {
                foreach ($leaves as $leave) {
                    $service->deductForFinalApproval($leave, $actor, $forceCredits);
                }
            });
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
