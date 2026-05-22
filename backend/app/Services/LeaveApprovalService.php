<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveApprovalService
{
    public function __construct(
        private readonly HrApprovalChainResolver $chainResolver,
        private readonly OrgApprovalWorkflowService $workflowService,
    ) {}

    public function getApprovalChain(User $employee): ?array
    {
        return $this->chainResolver->getApprovalChain(
            $employee,
            true,
            OrgApprovalWorkflowService::MODULE_LEAVE,
        );
    }

    public function canApprove(User $actor, LeaveRequest $leave): bool
    {
        if (! $leave->pending_approval || $leave->status !== LeaveRequest::STATUS_PENDING || $leave->rejected_at) {
            return false;
        }

        $employee = $leave->user;
        if (! $employee) {
            return false;
        }

        $requestor = $leave->relationLoaded('filedBy') ? $leave->filedBy : null;

        return $this->workflowService->canAct(
            $actor,
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $employee,
            $requestor,
            true,
        );
    }

    public function canReject(User $actor, LeaveRequest $leave): bool
    {
        if (! $leave->pending_approval || $leave->status !== LeaveRequest::STATUS_PENDING || $leave->rejected_at) {
            return false;
        }

        return $this->canApprove($actor, $leave);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildApprovalProgress(LeaveRequest $leave): array
    {
        $employee = $leave->user;
        if (! $employee) {
            return [];
        }

        $submitter = $leave->relationLoaded('filedBy') && $leave->filedBy ? $leave->filedBy : $employee;

        return $this->workflowService->buildApprovalProgress(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $employee,
            $submitter,
            $leave->filed_at ?? $leave->created_at,
            $leave->status === LeaveRequest::STATUS_APPROVED,
            $leave->rejected_at !== null || $leave->status === LeaveRequest::STATUS_REJECTED,
            $submitter,
        );
    }

    public function deriveDisplayStatusLabel(LeaveRequest $leave): string
    {
        if ($leave->rejected_at || $leave->status === LeaveRequest::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($leave->status === LeaveRequest::STATUS_APPROVED) {
            return 'HR Approved';
        }

        if ($leave->user) {
            $label = $this->workflowService->currentPendingLabel(
                $leave,
                OrgApprovalWorkflowService::MODULE_LEAVE,
                $leave->user,
                $leave->relationLoaded('filedBy') ? $leave->filedBy : null,
            );
            if ($label) {
                return 'Pending '.$label.' Approval';
            }
        }

        return 'Pending';
    }
}
