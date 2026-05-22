<?php

namespace App\Services;

use App\Models\Overtime;
use App\Models\User;

class OvertimeApprovalService
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
            OrgApprovalWorkflowService::MODULE_OVERTIME,
        );
    }

    public function canApprove(User $actor, Overtime $overtime): bool
    {
        if (! $overtime->pending_approval || $overtime->status !== Overtime::STATUS_PENDING || $overtime->rejected_at) {
            return false;
        }

        $employee = $overtime->user;
        if (! $employee) {
            return false;
        }

        $requestor = $overtime->relationLoaded('filedBy') ? $overtime->filedBy : null;

        return $this->workflowService->canAct(
            $actor,
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $employee,
            $requestor,
            true,
        );
    }

    public function canReject(User $actor, Overtime $overtime): bool
    {
        if (! $overtime->pending_approval || $overtime->status !== Overtime::STATUS_PENDING || $overtime->rejected_at) {
            return false;
        }

        return $this->canApprove($actor, $overtime);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildApprovalProgress(Overtime $overtime): array
    {
        $employee = $overtime->user;
        if (! $employee) {
            return [];
        }

        $submitter = $overtime->relationLoaded('filedBy') && $overtime->filedBy
            ? $overtime->filedBy
            : null;

        return $this->workflowService->buildApprovalProgress(
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $employee,
            $submitter,
            $overtime->filed_at ?? $overtime->created_at,
            $overtime->status === Overtime::STATUS_APPROVED,
            $overtime->rejected_at !== null || $overtime->status === Overtime::STATUS_REJECTED,
            $submitter,
        );
    }

    public function deriveDisplayStatusLabel(Overtime $overtime): string
    {
        if ($overtime->rejected_at || $overtime->status === Overtime::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($overtime->status === Overtime::STATUS_APPROVED) {
            return 'HR Approved';
        }

        if ($overtime->user) {
            $label = $this->workflowService->currentPendingLabel(
                $overtime,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
                $overtime->user,
                $overtime->relationLoaded('filedBy') ? $overtime->filedBy : null,
            );
            if ($label) {
                return 'Pending '.$label.' Approval';
            }
        }

        return 'Pending';
    }
}
