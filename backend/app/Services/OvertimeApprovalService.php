<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Overtime;
use App\Models\User;
use App\Support\HrApprovalStages;

class OvertimeApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly HrApprovalChainResolver $chainResolver,
    ) {}

    public function getApprovalChain(User $employee): ?array
    {
        return $this->chainResolver->getApprovalChain($employee);
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

        // Never approve your own overtime request (line or HR final).
        if ((int) $actor->id === (int) $overtime->user_id) {
            return false;
        }

        $actorRole = $this->roleResolver->resolve($actor);
        $stage = $overtime->approval_stage ?? HrApprovalStages::PENDING_FIRST;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return $this->chainResolver->isFirstLevelApprover($actor, $employee);
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($actorRole !== HrRole::AdminHr) {
                return false;
            }

            return (int) $actor->id !== (int) $overtime->user_id;
        }

        return false;
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

        $chain = $this->getApprovalChain($employee);
        if ($chain === null) {
            return [];
        }

        $stage = $overtime->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $rejected = $overtime->rejected_at !== null;
        $finalOk = $overtime->status === Overtime::STATUS_APPROVED;

        $submitter = $overtime->relationLoaded('filedBy') && $overtime->filedBy
            ? $overtime->filedBy
            : null;
        $submitterName = $submitter?->name ?? $employee->name;
        $submitterForPhoto = $submitter ?? $employee;

        $filedAt = $overtime->filed_at ?? $overtime->created_at;

        $steps = [];
        $steps[] = [
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'approver_role_label' => null,
            'submitter_name' => $submitterName,
            'approver_name' => null,
            'profile_image_url' => $submitterForPhoto->profile_image_url,
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
            if ($rejected && ! $overtime->first_approved_at) {
                $interStatus = 'rejected';
            } elseif ($overtime->first_approved_at) {
                $interStatus = 'completed';
            } elseif ($stage === HrApprovalStages::PENDING_FIRST) {
                $interStatus = 'current';
            } else {
                $interStatus = 'pending';
            }
            $firstApprover = $overtime->first_approver_id
                ? $overtime->firstApprover
                : $this->chainResolver->resolveFirstLevelApprover($employee);
            $steps[] = [
                'key' => 'line_approval',
                'label' => $label.' approval',
                'status' => $interStatus,
                'approver_role_label' => $roleLabel,
                'submitter_name' => null,
                'approver_name' => $firstApprover?->name,
                'profile_image_url' => $firstApprover?->profile_image_url,
                'acted_at' => $overtime->first_approved_at?->toIso8601String(),
                'remarks' => null,
            ];
        }

        if ($finalOk) {
            $hrStatus = 'completed';
        } elseif ($rejected && $overtime->first_approved_at) {
            $hrStatus = 'rejected';
        } elseif ($rejected && ! $overtime->first_approved_at) {
            $hrStatus = 'skipped';
        } elseif ($stage === HrApprovalStages::PENDING_SECOND && ! $rejected) {
            $hrStatus = 'current';
        } else {
            $hrStatus = 'pending';
        }

        $secondApprover = $overtime->second_approver_id
            ? $overtime->secondApprover
            : $this->chainResolver->resolveHrApprover();

        $steps[] = [
            'key' => 'hr_final',
            'label' => 'Admin (HR) final approval',
            'status' => $hrStatus,
            'approver_role_label' => 'Admin (HR)',
            'submitter_name' => null,
            'approver_name' => $secondApprover?->name,
            'profile_image_url' => $secondApprover?->profile_image_url,
            'acted_at' => $overtime->second_approved_at?->toIso8601String(),
            'remarks' => null,
        ];

        return $steps;
    }

    public function deriveDisplayStatusLabel(Overtime $overtime): string
    {
        if ($overtime->rejected_at || $overtime->status === Overtime::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($overtime->status === Overtime::STATUS_APPROVED) {
            return 'HR Approved';
        }

        $stage = $overtime->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $empRole = $overtime->user ? $this->roleResolver->resolveForApprovalSubject($overtime->user) : HrRole::Employee;

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
            if ($overtime->first_approved_at) {
                return match ($empRole) {
                    HrRole::Employee => 'Department Head approved · Pending HR (final)',
                    HrRole::DepartmentHead => 'Branch Head approved · Pending HR (final)',
                    HrRole::BranchHead => 'Company Head approved · Pending HR (final)',
                    HrRole::CompanyHead => 'Pending HR Approval',
                    HrRole::AdminHr => 'Pending HR Approval',
                };
            }

            return 'Pending HR Approval';
        }

        return 'Pending';
    }
}
