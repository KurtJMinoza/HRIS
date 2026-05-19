<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\HrApprovalStages;

class LeaveApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly HrApprovalChainResolver $chainResolver,
    ) {}

    public function getApprovalChain(User $employee): ?array
    {
        return $this->chainResolver->getApprovalChain($employee);
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

        $actorRole = $this->roleResolver->resolve($actor);
        $stage = $leave->approval_stage ?? HrApprovalStages::PENDING_FIRST;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return $this->chainResolver->isFirstLevelApprover($actor, $employee);
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($actorRole !== HrRole::AdminHr) {
                return false;
            }

            return (int) $actor->id !== (int) $leave->user_id;
        }

        return false;
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

        $chain = $this->getApprovalChain($employee);
        if ($chain === null) {
            return [];
        }

        $stage = $leave->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $rejected = $leave->rejected_at !== null;
        $finalOk = $leave->status === LeaveRequest::STATUS_APPROVED;

        $submitter = $leave->relationLoaded('filedBy') && $leave->filedBy ? $leave->filedBy : $employee;
        $submitterName = $submitter->name;

        $filedAt = $leave->filed_at ?? $leave->created_at;

        $steps = [];
        $steps[] = [
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'approver_role_label' => null,
            'submitter_name' => $submitterName,
            'approver_name' => null,
            'profile_image_url' => $submitter->profile_image_url,
            'acted_at' => $filedAt?->toIso8601String(),
            'remarks' => null,
        ];

        $hasIntermediate = count($chain) >= 2;
        if ($hasIntermediate) {
            $interRole = $chain[0];
            $label = match ($interRole) {
                HrRole::DepartmentHead => 'Department Head',
                HrRole::BranchHead => 'Branch Head',
                HrRole::CompanyHead => 'Company Head',
                default => $interRole->badgeLabel(),
            };
            $roleLabel = $label;
            if ($rejected && ! $leave->first_approved_at) {
                $interStatus = 'rejected';
            } elseif ($leave->first_approved_at) {
                $interStatus = 'completed';
            } elseif ($stage === HrApprovalStages::PENDING_FIRST) {
                $interStatus = 'current';
            } else {
                $interStatus = 'pending';
            }
            $firstApprover = $leave->first_approver_id
                ? $leave->firstApprover
                : $this->chainResolver->resolveFirstLevelApprover($employee);
            $steps[] = [
                'key' => 'line_approval',
                'label' => $label.' approval',
                'status' => $interStatus,
                'approver_role_label' => $roleLabel,
                'submitter_name' => null,
                'approver_name' => $firstApprover?->display_name,
                'profile_image_url' => $firstApprover?->profile_image_url,
                'acted_at' => $leave->first_approved_at?->toIso8601String(),
                'remarks' => null,
            ];
        }

        if ($finalOk) {
            $hrStatus = 'completed';
        } elseif ($rejected && $leave->first_approved_at) {
            $hrStatus = 'rejected';
        } elseif ($rejected && ! $leave->first_approved_at) {
            $hrStatus = 'skipped';
        } elseif ($stage === HrApprovalStages::PENDING_SECOND && ! $rejected) {
            $hrStatus = 'current';
        } else {
            $hrStatus = 'pending';
        }

        $secondApprover = $leave->second_approver_id
            ? $leave->secondApprover
            : $this->chainResolver->resolveHrApprover();

        $steps[] = [
            'key' => 'hr_final',
            'label' => 'Admin (HR) final approval',
            'status' => $hrStatus,
            'approver_role_label' => 'Admin (HR)',
            'submitter_name' => null,
            'approver_name' => $secondApprover?->display_name,
            'profile_image_url' => $secondApprover?->profile_image_url,
            'acted_at' => $leave->second_approved_at?->toIso8601String(),
            'remarks' => null,
        ];

        return $steps;
    }

    public function deriveDisplayStatusLabel(LeaveRequest $leave): string
    {
        if ($leave->rejected_at || $leave->status === LeaveRequest::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($leave->status === LeaveRequest::STATUS_APPROVED) {
            return 'HR Approved';
        }

        $stage = $leave->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $empRole = $leave->user ? $this->roleResolver->resolveForApprovalSubject($leave->user) : HrRole::Employee;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return match ($empRole) {
                HrRole::Employee => 'Pending Department Head Approval',
                HrRole::DepartmentHead => 'Pending Branch Head Approval',
                HrRole::BranchHead => 'Pending Company Head Approval',
                HrRole::CompanyHead => 'Pending HR Approval',
                HrRole::AdminHr => 'Pending HR Approval',
            };
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($leave->first_approved_at !== null && $empRole === HrRole::Employee) {
                return 'Department Head Approved — Pending HR Approval';
            }

            return 'Pending HR Approval';
        }

        return 'Pending';
    }
}
