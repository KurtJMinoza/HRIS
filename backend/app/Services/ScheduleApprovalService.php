<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\ScheduleRequest;
use App\Models\User;
use App\Support\HrApprovalStages;

class ScheduleApprovalService
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly HrApprovalChainResolver $chainResolver,
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

        $actorRole = $this->roleResolver->resolve($actor);
        $subjectRole = $this->roleResolver->resolveForApprovalSubject($employee);
        $stage = $request->approval_stage ?? HrApprovalStages::PENDING_FIRST;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return $this->chainResolver->isFirstLevelApprover($actor, $employee);
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($actorRole !== HrRole::AdminHr) {
                return false;
            }

            return $subjectRole === HrRole::AdminHr || (int) $actor->id !== (int) $request->user_id;
        }

        return false;
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

        $chain = $this->chainResolver->getApprovalChain($employee);
        if ($chain === null) {
            return [];
        }

        $stage = $request->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $rejected = $request->rejected_at !== null;
        $finalOk = $request->status === ScheduleRequest::STATUS_APPROVED;

        $submitter = $request->relationLoaded('filedBy') && $request->filedBy ? $request->filedBy : $employee;
        $steps = [[
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'approver_role_label' => null,
            'submitter_name' => $submitter->name,
            'approver_name' => null,
            'profile_image_url' => $submitter->profile_image_url,
            'acted_at' => ($request->filed_at ?? $request->created_at)?->toIso8601String(),
            'remarks' => null,
        ]];

        $hasIntermediate = count($chain) >= 2;
        if ($hasIntermediate) {
            $interRole = $chain[0];
            $label = match ($interRole) {
                HrRole::DepartmentHead => 'Department Head',
                HrRole::BranchHead => 'Branch Head',
                HrRole::CompanyHead => 'Company Head',
                default => $interRole->badgeLabel(),
            };
            if ($rejected && ! $request->first_approved_at) {
                $interStatus = 'rejected';
            } elseif ($request->first_approved_at) {
                $interStatus = 'completed';
            } elseif ($stage === HrApprovalStages::PENDING_FIRST) {
                $interStatus = 'current';
            } else {
                $interStatus = 'pending';
            }
            $firstApprover = $request->first_approver_id
                ? $request->firstApprover
                : $this->chainResolver->resolveFirstLevelApprover($employee);
            $steps[] = [
                'key' => 'line_approval',
                'label' => $label.' approval',
                'status' => $interStatus,
                'approver_role_label' => $label,
                'submitter_name' => null,
                'approver_name' => $firstApprover?->name,
                'profile_image_url' => $firstApprover?->profile_image_url,
                'acted_at' => $request->first_approved_at?->toIso8601String(),
                'remarks' => null,
            ];
        }

        if ($finalOk) {
            $hrStatus = 'completed';
        } elseif ($rejected && $request->first_approved_at) {
            $hrStatus = 'rejected';
        } elseif ($rejected && ! $request->first_approved_at) {
            $hrStatus = 'skipped';
        } elseif ($stage === HrApprovalStages::PENDING_SECOND && ! $rejected) {
            $hrStatus = 'current';
        } else {
            $hrStatus = 'pending';
        }

        $secondApprover = $request->second_approver_id
            ? $request->secondApprover
            : $this->chainResolver->resolveHrApprover();

        $steps[] = [
            'key' => 'hr_final',
            'label' => 'Admin (HR) final approval',
            'status' => $hrStatus,
            'approver_role_label' => 'Admin (HR)',
            'submitter_name' => null,
            'approver_name' => $secondApprover?->name,
            'profile_image_url' => $secondApprover?->profile_image_url,
            'acted_at' => $request->second_approved_at?->toIso8601String(),
            'remarks' => null,
        ];

        return $steps;
    }

    public function deriveDisplayStatusLabel(ScheduleRequest $request): string
    {
        if ($request->rejected_at || $request->status === ScheduleRequest::STATUS_REJECTED) {
            return 'Rejected';
        }
        if ($request->status === ScheduleRequest::STATUS_APPROVED) {
            return 'HR Approved';
        }

        $stage = $request->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $empRole = $request->user ? $this->roleResolver->resolveForApprovalSubject($request->user) : HrRole::Employee;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return match ($empRole) {
                HrRole::Employee => 'Pending Department Head Approval',
                HrRole::DepartmentHead => 'Pending Branch Head Approval',
                HrRole::BranchHead => 'Pending Company Head Approval',
                HrRole::CompanyHead, HrRole::AdminHr => 'Pending HR Approval',
            };
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($request->first_approved_at) {
                return match ($empRole) {
                    HrRole::Employee => 'Department Head approved · Pending HR (final)',
                    HrRole::DepartmentHead => 'Branch Head approved · Pending HR (final)',
                    HrRole::BranchHead => 'Company Head approved · Pending HR (final)',
                    HrRole::CompanyHead, HrRole::AdminHr => 'Pending HR Approval',
                };
            }

            return 'Pending HR Approval';
        }

        return 'Pending';
    }
}
