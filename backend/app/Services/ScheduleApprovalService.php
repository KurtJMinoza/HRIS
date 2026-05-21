<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\ScheduleRequest;
use App\Models\User;

class ScheduleApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly HrApprovalChainResolver $chainResolver,
        private readonly OrgApprovalWorkflowService $workflowService,
    ) {}

    public function canApprove(User $actor, ScheduleRequest $request): bool
    {
        if (! $request->pending_approval || $request->status !== ScheduleRequest::STATUS_PENDING || $request->rejected_at) {
            return false;
        }

        $employee = $request->user;
        if (! $employee) {
            return false;
        }

        $requestor = $request->relationLoaded('filedBy') ? $request->filedBy : null;

        return $this->workflowService->canAct(
            $actor,
            $request,
            OrgApprovalWorkflowService::MODULE_SCHEDULE,
            $employee,
            $requestor,
            true,
        );
    }

    public function canReject(User $actor, ScheduleRequest $request): bool
    {
        return $this->canApprove($actor, $request);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildApprovalProgress(ScheduleRequest $request): array
    {
        $employee = $request->user;
        if (! $employee) {
            return [];
        }

        $submitter = $request->relationLoaded('filedBy') && $request->filedBy ? $request->filedBy : $employee;

        return $this->workflowService->buildApprovalProgress(
            $request,
            OrgApprovalWorkflowService::MODULE_SCHEDULE,
            $employee,
            $submitter,
            $request->filed_at ?? $request->created_at,
            $request->status === ScheduleRequest::STATUS_APPROVED,
            $request->rejected_at !== null || $request->status === ScheduleRequest::STATUS_REJECTED,
            $submitter,
        );
    }

    public function deriveDisplayStatusLabel(ScheduleRequest $request): string
    {
        if ($request->rejected_at || $request->status === ScheduleRequest::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($request->status === ScheduleRequest::STATUS_APPROVED) {
            return 'HR Approved';
        }

        if ($request->user) {
            $label = $this->workflowService->currentPendingLabel(
                $request,
                OrgApprovalWorkflowService::MODULE_SCHEDULE,
                $request->user,
                $request->relationLoaded('filedBy') ? $request->filedBy : null,
            );
            if ($label) {
                return 'Pending '.$label.' Approval';
            }
        }

        return 'Pending';
    }
}
