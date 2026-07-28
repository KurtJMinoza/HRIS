<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Jobs\AttendanceCorrectionBulkFollowUpJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionApproval;
use App\Models\AttendanceCorrectionAudit;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\AttendanceCorrectionStatusService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\PayrollFreezeService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\RequestModuleCacheInvalidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceCorrectionBulkApprovalService
{
    private const MODULE = OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly AttendanceCorrectionStatusService $correctionStatusService,
    ) {}

    /**
     * @param  int[]  $ids
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[]}
     */
    public function approveMany(User $actor, array $ids, ?string $notes): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->emptyResult();
        }

        $corrections = AttendanceCorrection::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');

        $pendingRecords = OrgApprovalRecord::query()
            ->where('module_type', self::MODULE)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $now = now();
        $lockedWindows = $this->payrollPeriodMutationGuard->lockedWindowErrors(
            $corrections->map(fn (AttendanceCorrection $correction): array => [
                'key' => (int) $correction->id,
                'user_id' => (int) $correction->user_id,
                'from' => Carbon::parse($correction->date->toDateString())->startOfDay(),
                'to' => Carbon::parse($correction->date->toDateString())->startOfDay(),
            ])->values()->all(),
            reconcileOrphans: false,
        );
        $scopedEmployeeIds = $actor->isAdmin()
            ? null
            : $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $accessibleEmployeeIds = is_array($scopedEmployeeIds)
            ? array_fill_keys([...array_map('intval', $scopedEmployeeIds), (int) $actor->id], true)
            : null;

        /** @var list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}> $finalItems */
        $finalItems = [];
        /** @var list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}> $firstStepItems */
        $firstStepItems = [];
        $failedItems = [];
        $skipped = 0;

        foreach ($ids as $id) {
            $correction = $corrections->get($id);
            if ($correction === null) {
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => 'Attendance correction was not found or is no longer pending.',
                ];

                continue;
            }

            $employee = $correction->user;
            if (! $employee instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];

                continue;
            }

            if (is_array($accessibleEmployeeIds)
                && (! $employee->isRosterEligible() || ! isset($accessibleEmployeeIds[(int) $employee->id]))
            ) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve this request.'];

                continue;
            }

            $pending = $pendingRecords->get($id);
            if (! $pending instanceof OrgApprovalRecord) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'No pending approval step found.'];

                continue;
            }

            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $pending,
                $employee,
                self::MODULE,
            );
            if (! $authorization['allowed']) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve at this stage.'];

                continue;
            }

            if (isset($lockedWindows[$id])) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => PayrollFreezeService::APPROVAL_LOCK_MESSAGE];

                continue;
            }

            $item = ['correction' => $correction, 'employee' => $employee, 'pending' => $pending];
            if ($pending->approver_role === HrRole::AdminHr->value) {
                if (! $correction->hasRequiredTimesForFinalApproval()) {
                    $skipped++;
                    $failedItems[] = [
                        'request_id' => $id,
                        'reason' => 'Required clock-in/out times are missing or invalid for final approval.',
                    ];

                    continue;
                }
                $finalItems[] = $item;
            } else {
                $firstStepItems[] = $item;
            }
        }

        $approvedIds = [];

        if ($finalItems !== []) {
            $approvedIds = array_merge(
                $approvedIds,
                $this->approveFinalBatch($actor, $finalItems, $notes, $roleLabel, $now),
            );
        }

        if ($firstStepItems !== []) {
            $approvedIds = array_merge(
                $approvedIds,
                $this->approveFirstStepBatch($actor, $firstStepItems, $notes, $roleLabel, $now),
            );
        }

        $approvedIds = array_values(array_unique($approvedIds));
        $approved = count($approvedIds);

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'failed' => 0,
            'failed_items' => $failedItems,
            'approved_ids' => $approvedIds,
        ];
    }

    /**
     * @param  int[]  $approvedIds
     */
    public function finalizeApprovals(User $actor, array $approvedIds): void
    {
        if ($approvedIds === []) {
            return;
        }

        AttendanceCorrectionModuleCache::flush();
        RequestModuleCacheInvalidator::afterBulk('attendance_correction', $approvedIds, $actor);
        AttendanceCorrectionBulkFollowUpJob::dispatch(
            array_values(array_unique(array_map('intval', $approvedIds))),
            (int) $actor->id,
        );
    }

    /**
     * @param  list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}>  $items
     * @return int[]
     */
    private function approveFinalBatch(
        User $actor,
        array $items,
        ?string $notes,
        string $roleLabel,
        Carbon $now,
    ): array {
        $correctionIds = [];
        $auditRows = [];
        $approvalRows = [];
        $approverName = $actor->display_name ?? $actor->name;

        DB::transaction(function () use ($items, $actor, $notes, $roleLabel, $now, &$correctionIds, &$auditRows, &$approvalRows, $approverName) {
            $pendingIds = array_map(static fn (array $item): int => (int) $item['pending']->id, $items);
            OrgApprovalRecord::query()
                ->whereIn('id', $pendingIds)
                ->update([
                    'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                    'remarks' => $notes,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $approverName,
                    'updated_at' => $now,
                ]);

            foreach ($items as $item) {
                $correction = $item['correction'];
                $employee = $item['employee'];
                $dateKey = $correction->date->toDateString();

                $previousIn = $correction->time_in;
                $previousOut = $correction->time_out;
                $finalNote = $notes ?? 'Final approval (Admin HR).';

                $correctionIds[] = (int) $correction->id;

                $auditRows[] = [
                    'attendance_correction_id' => $correction->id,
                    'admin_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'date' => $dateKey,
                    'previous_time_in' => $previousIn,
                    'previous_time_out' => $previousOut,
                    'new_time_in' => $correction->time_in,
                    'new_time_out' => $correction->time_out,
                    'reason' => $finalNote,
                    'action' => 'approve_final',
                    'approver_role' => $roleLabel,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $approvalRows[] = [
                    'attendance_correction_id' => $correction->id,
                    'approver_id' => $actor->id,
                    'level' => 2,
                    'status' => 'approved',
                    'notes' => $finalNote,
                    'acted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($correctionIds !== []) {
                AttendanceCorrection::query()
                    ->whereIn('id', $correctionIds)
                    ->update([
                        'pending_approval' => false,
                        'approved' => true,
                        'approved_by' => $actor->id,
                        'approved_at' => $now,
                        'approval_stage' => AttendanceCorrectionApprovalService::STAGE_APPROVED,
                        'status' => AttendanceCorrectionStatusService::STATUS_APPROVED,
                        'final_approved_by' => $actor->id,
                        'second_approver_id' => $actor->id,
                        'second_approved_at' => $now,
                        'updated_at' => $now,
                    ]);

                OrgApprovalRecord::query()
                    ->where('module_type', self::MODULE)
                    ->whereIn('request_id', $correctionIds)
                    ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                    ->update([
                        'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                        'remarks' => $notes,
                        'approved_at' => $now,
                        'approver_id' => $actor->id,
                        'approver_name' => $approverName,
                        'updated_at' => $now,
                    ]);
            }

            if ($auditRows !== []) {
                AttendanceCorrectionAudit::query()->insert($auditRows);
            }
            if ($approvalRows !== []) {
                AttendanceCorrectionApproval::query()->insert($approvalRows);
            }
        });

        return $correctionIds;
    }

    /**
     * @param  list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}>  $items
     * @return int[]
     */
    private function approveFirstStepBatch(
        User $actor,
        array $items,
        ?string $notes,
        string $roleLabel,
        Carbon $now,
    ): array {
        $approvedIds = array_map(static fn (array $item): int => (int) $item['correction']->id, $items);
        $approverName = $actor->display_name ?? $actor->name;
        $pendingIds = array_map(static fn (array $item): int => (int) $item['pending']->id, $items);
        $allPending = OrgApprovalRecord::query()
            ->where('module_type', self::MODULE)
            ->whereIn('request_id', $approvedIds)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->groupBy('request_id');
        $correctionRows = [];
        $auditRows = [];
        $approvalRows = [];

        foreach ($items as $item) {
            $correction = $item['correction'];
            $employee = $item['employee'];
            $pending = $item['pending'];
            $dateKey = $correction->date->toDateString();
            $nextPending = $allPending->get($correction->id)?->first(
                fn (OrgApprovalRecord $record): bool => (int) $record->id !== (int) $pending->id,
            );
            // No remaining pending steps after this approval — treat as final.
            if ($nextPending === null) {
                $correctionRows[] = [
                    'id' => (int) $correction->id,
                    'pending_approval' => false,
                    'approved' => true,
                    'approved_by' => $actor->id,
                    'approved_at' => $now,
                    'status' => AttendanceCorrectionStatusService::STATUS_APPROVED,
                    'approval_stage' => AttendanceCorrectionApprovalService::STAGE_APPROVED,
                    'final_approved_by' => $actor->id,
                    'second_approver_id' => $correction->second_approver_id ?: $actor->id,
                    'second_approved_at' => $correction->second_approved_at ?: $now,
                    'first_approver_id' => $correction->first_approver_id ?: $actor->id,
                    'first_approved_at' => $correction->first_approved_at ?: $now,
                    'updated_at' => $now,
                ];
            } else {
                $correctionRows[] = [
                    'id' => (int) $correction->id,
                    'pending_approval' => true,
                    'approved' => false,
                    'status' => AttendanceCorrectionStatusService::STATUS_PENDING,
                    'approval_stage' => $nextPending->approver_role === HrRole::AdminHr->value
                        ? \App\Support\HrApprovalStages::PENDING_SECOND
                        : \App\Support\HrApprovalStages::PENDING_FIRST,
                    'first_approver_id' => $correction->first_approver_id ?: $actor->id,
                    'first_approved_at' => $correction->first_approved_at ?: $now,
                    'updated_at' => $now,
                ];
            }
            $auditRows[] = [
                'attendance_correction_id' => $correction->id,
                'admin_id' => $actor->id,
                'employee_id' => $employee->id,
                'date' => $dateKey,
                'previous_time_in' => $correction->time_in,
                'previous_time_out' => $correction->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $notes ?? ($nextPending === null ? 'Final approval.' : 'First approval.'),
                'action' => $nextPending === null ? 'approve_final' : 'approve_first',
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $approvalRows[] = [
                'attendance_correction_id' => $correction->id,
                'approver_id' => $actor->id,
                'level' => (int) ($pending->sequence_order ?? 1),
                'status' => 'approved',
                'notes' => $notes,
                'acted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($pendingIds, $actor, $notes, $now, $approverName, $correctionRows, $auditRows, $approvalRows): void {
            OrgApprovalRecord::query()->whereIn('id', $pendingIds)->update([
                'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                'remarks' => $notes,
                'approved_at' => $now,
                'approver_id' => $actor->id,
                'approver_name' => $approverName,
                'updated_at' => $now,
            ]);
            // UPDATE-only: upsert INSERT path lacks required columns like user_id under MySQL strict mode.
            foreach ($correctionRows as $row) {
                $id = (int) $row['id'];
                unset($row['id']);
                DB::table('attendance_corrections')->where('id', $id)->update($row);
            }
            AttendanceCorrectionAudit::query()->insert($auditRows);
            AttendanceCorrectionApproval::query()->insert($approvalRows);
        });

        return $approvedIds;
    }

    /**
     * @param  int[]  $ids
     * @return array{rejected: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, rejected_ids: int[]}
     */
    public function rejectMany(User $actor, array $ids, string $reason): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $corrections = AttendanceCorrection::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');
        $pending = OrgApprovalRecord::query()
            ->where('module_type', self::MODULE)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $rejectedIds = [];
        $recordIds = [];
        $auditRows = [];
        $approvalRows = [];
        $failedItems = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $scopedEmployeeIds = $actor->isAdmin()
            ? null
            : $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        $accessibleEmployeeIds = is_array($scopedEmployeeIds)
            ? array_fill_keys([...array_map('intval', $scopedEmployeeIds), (int) $actor->id], true)
            : null;

        foreach ($ids as $id) {
            $correction = $corrections->get($id);
            $record = $pending->get($id);
            if (! $correction instanceof AttendanceCorrection || ! $correction->user instanceof User || ! $record instanceof OrgApprovalRecord) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'Attendance correction is no longer pending or was not found.'];

                continue;
            }
            if (is_array($accessibleEmployeeIds)
                && (! $correction->user->isRosterEligible() || ! isset($accessibleEmployeeIds[(int) $correction->user->id]))
            ) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to reject this request.'];

                continue;
            }
            $authorization = $this->approvalWorkflowService->authorizePendingRecord($actor, $record, $correction->user, self::MODULE);
            if (! $authorization['allowed']) {
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to reject at this stage.'];

                continue;
            }

            $rejectedIds[] = $id;
            $recordIds[] = (int) $record->id;
            $auditRows[] = [
                'attendance_correction_id' => $id,
                'admin_id' => $actor->id,
                'employee_id' => $correction->user_id,
                'date' => $correction->date->toDateString(),
                'previous_time_in' => $correction->time_in,
                'previous_time_out' => $correction->time_out,
                'new_time_in' => $correction->time_in,
                'new_time_out' => $correction->time_out,
                'reason' => $reason,
                'action' => 'reject',
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $approvalRows[] = [
                'attendance_correction_id' => $id,
                'approver_id' => $actor->id,
                'level' => (int) ($record->sequence_order ?? 1),
                'status' => 'rejected',
                'notes' => $reason,
                'acted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rejectedIds !== []) {
            DB::transaction(function () use ($recordIds, $rejectedIds, $actor, $reason, $now, $auditRows, $approvalRows): void {
                OrgApprovalRecord::query()->whereIn('id', $recordIds)->update([
                    'approval_status' => OrgApprovalRecord::STATUS_REJECTED,
                    'remarks' => $reason,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $actor->display_name ?? $actor->name,
                    'updated_at' => $now,
                ]);
                OrgApprovalRecord::query()
                    ->where('module_type', self::MODULE)
                    ->whereIn('request_id', $rejectedIds)
                    ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                    ->update([
                        'approval_status' => OrgApprovalRecord::STATUS_REJECTED,
                        'remarks' => $reason,
                        'approved_at' => $now,
                        'approver_id' => $actor->id,
                        'approver_name' => $actor->display_name ?? $actor->name,
                        'updated_at' => $now,
                    ]);
                AttendanceCorrection::query()->whereIn('id', $rejectedIds)->update([
                    'pending_approval' => false,
                    'approved' => false,
                    'rejected_at' => $now,
                    'rejected_by' => $actor->id,
                    'rejection_note' => $reason,
                    'approval_stage' => \App\Support\HrApprovalStages::REJECTED,
                    'status' => AttendanceCorrectionStatusService::STATUS_REJECTED,
                    'final_approved_by' => null,
                    'updated_at' => $now,
                ]);
                AttendanceCorrectionAudit::query()->insert($auditRows);
                AttendanceCorrectionApproval::query()->insert($approvalRows);
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

    /**
     * @param  int[]  $rejectedIds
     */
    public function finalizeRejections(User $actor, array $rejectedIds): void
    {
        if ($rejectedIds === []) {
            return;
        }

        AttendanceCorrectionModuleCache::flush();
        RequestModuleCacheInvalidator::afterBulk('attendance_correction', $rejectedIds, $actor);
    }

    /**
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[]}
     */
    private function emptyResult(): array
    {
        return [
            'approved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_items' => [],
            'approved_ids' => [],
        ];
    }
}
