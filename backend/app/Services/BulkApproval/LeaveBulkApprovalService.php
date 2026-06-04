<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\LeaveApprovalAudit;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\User;
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
        if (! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return $this->emptyResult($ids);
        }

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

        $pendingRecords = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
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
        $auditRows = [];
        $bypassIds = [];
        $now = now();
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $approverName = $actor->display_name ?? $actor->name;
        $bypassReason = trim((string) $restDayBypassReason);

        foreach ($ids as $id) {
            $leave = $leaves->get($id);
            if (! $leave instanceof LeaveRequest) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Leave request is not pending or was not found.'];
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

            $employee = $leave->user;
            if (! $employee instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];
                continue;
            }

            try {
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                    (int) $leave->user_id,
                    Carbon::parse($leave->start_date)->startOfDay(),
                    Carbon::parse($leave->end_date)->startOfDay(),
                );
            } catch (\RuntimeException $e) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => $e->getMessage()];
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
            $finalDetails = $notes;
            if (in_array($id, $bypassIds, true)) {
                $finalDetails = trim(($finalDetails ? $finalDetails.' — ' : '').'Rest-day approval override: '.$bypassReason);
            }
            $auditRows[] = [
                'leave_request_id' => $id,
                'actor_id' => $actor->id,
                'employee_id' => $leave->user_id,
                'action' => 'approve_final',
                'details' => $finalDetails,
                'approver_role' => $roleLabel,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($approvedIds !== []) {
            DB::transaction(function () use ($approvedIds, $approvalRecordIds, $actor, $notes, $now, $approverName, $auditRows, $bypassIds, $bypassReason): void {
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

                LeaveRequest::query()
                    ->whereIn('id', $approvedIds)
                    ->update($leaveUpdate);

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
            'fallback_ids' => $fallbackIds,
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
