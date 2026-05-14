<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Support\HrApprovalStages;
use Carbon\Carbon;

class AttendanceCorrectionApprovalService
{
    public const STAGE_PENDING_FIRST = HrApprovalStages::PENDING_FIRST;

    public const STAGE_PENDING_SECOND = HrApprovalStages::PENDING_SECOND;

    public const STAGE_APPROVED = HrApprovalStages::APPROVED;

    public const STAGE_REJECTED = HrApprovalStages::REJECTED;

    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly HrApprovalChainResolver $chainResolver,
    ) {}

    /**
     * @param  mixed  $value  Carbon instance, DateTimeInterface, ISO string, or null
     */
    private function toIso8601String(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        if (is_string($value)) {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<int, HrRole>|null [first, second] approver roles; null if user cannot file.
     */
    public function getApprovalChain(User $employee): ?array
    {
        return $this->chainResolver->getApprovalChain($employee);
    }

    public function initialApprovalStage(User $employee): string
    {
        return $this->chainResolver->initialApprovalStage($employee);
    }

    /**
     * @return array{
     *   chain: array<int, \App\Enums\HrRole>|null,
     *   fallback_to_admin: bool,
     *   fallback_reasons: array<int, string>,
     *   first_level_approver: ?\App\Models\User,
     *   hr_approver: ?\App\Models\User
     * }
     */
    public function resolveRoutingDecision(User $employee): array
    {
        return $this->chainResolver->resolveRoutingDecision($employee);
    }

    public function canApprove(User $actor, AttendanceCorrection $correction): bool
    {
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return false;
        }

        $employee = $correction->user;
        if (! $employee) {
            return false;
        }

        $actorRole = $this->roleResolver->resolve($actor);
        $stage = $correction->approval_stage ?? HrApprovalStages::PENDING_FIRST;

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            return $this->chainResolver->isFirstLevelApprover($actor, $employee);
        }

        if ($stage === HrApprovalStages::PENDING_SECOND) {
            if ($actorRole !== HrRole::AdminHr) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Rejection uses the same stage gate as approval: Admin (HR) cannot reject until prior approvers
     * have completed (same rule as final approve/reject).
     */
    public function canReject(User $actor, AttendanceCorrection $correction): bool
    {
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return false;
        }

        return $this->canApprove($actor, $correction);
    }

    /**
     * Visual steps for UI: submitted → line manager (if any) → HR final.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildApprovalProgress(AttendanceCorrection $correction): array
    {
        $employee = $correction->user;
        if (! $employee) {
            return [];
        }

        $chain = $this->getApprovalChain($employee);
        if ($chain === null) {
            return [];
        }

        $stage = $correction->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $rejected = $correction->rejected_at !== null;
        $finalOk = (bool) $correction->approved;

        $submitterName = $correction->filedBy?->name ?? $employee->name;

        $photoForSubmitted = $correction->filedBy ?? $employee;

        $steps = [];
        $steps[] = [
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'approver_role_label' => null,
            'submitter_name' => $submitterName,
            'approver_name' => null,
            'profile_image_url' => $photoForSubmitted instanceof User ? $photoForSubmitted->profile_image_url : null,
            'acted_at' => $this->toIso8601String($correction->filed_at),
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
            if ($rejected && ! $correction->first_approved_at) {
                $interStatus = 'rejected';
            } elseif ($correction->first_approved_at) {
                $interStatus = 'completed';
            } elseif ($stage === HrApprovalStages::PENDING_FIRST) {
                $interStatus = 'current';
            } else {
                $interStatus = 'pending';
            }
            $firstApprover = $correction->first_approver_id
                ? $correction->firstApprover
                : $this->chainResolver->resolveFirstLevelApprover($employee);
            $steps[] = [
                'key' => 'line_approval',
                'label' => $label.' approval',
                'status' => $interStatus,
                'approver_role_label' => $roleLabel,
                'submitter_name' => null,
                'approver_name' => $firstApprover?->name,
                'profile_image_url' => $firstApprover?->profile_image_url,
                'acted_at' => $this->toIso8601String($correction->first_approved_at),
                'remarks' => null,
            ];
        }

        if ($finalOk) {
            $hrStatus = 'completed';
        } elseif ($rejected && $correction->first_approved_at) {
            $hrStatus = 'rejected';
        } elseif ($rejected && ! $correction->first_approved_at) {
            $hrStatus = 'skipped';
        } elseif ($stage === HrApprovalStages::PENDING_SECOND && ! $rejected) {
            $hrStatus = 'current';
        } else {
            $hrStatus = 'pending';
        }

        $secondApprover = $correction->second_approver_id
            ? $correction->secondApprover
            : $this->chainResolver->resolveHrApprover();

        $steps[] = [
            'key' => 'hr_final',
            'label' => 'Admin (HR) final approval',
            'status' => $hrStatus,
            'approver_role_label' => 'Admin (HR)',
            'submitter_name' => null,
            'approver_name' => $secondApprover?->name,
            'profile_image_url' => $secondApprover?->profile_image_url,
            'acted_at' => $this->toIso8601String($correction->second_approved_at),
            'remarks' => null,
        ];

        return $steps;
    }

    /**
     * Pending corrections the actor may act on (approve at current stage).
     */
    public function getPendingForApprover(User $actor): \Illuminate\Database\Eloquent\Collection
    {
        $actorRole = $this->roleResolver->resolve($actor);

        $displayWith = [
            'user.departmentRelation.branch',
            'user.branch',
            'user.company',
            'filedBy',
            'firstApprover',
            'secondApprover',
            'attendanceLogsSyncedBy',
            'rejectedBy',
            'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
            'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
        ];

        if ($actorRole === HrRole::AdminHr) {
            return AttendanceCorrection::query()
                ->with($displayWith)
                ->where('pending_approval', true)
                ->where('approved', false)
                ->whereNull('rejected_at')
                ->where('approval_stage', HrApprovalStages::PENDING_SECOND)
                ->orderByDesc('filed_at')
                ->get();
        }

        $query = AttendanceCorrection::query()
            ->with($displayWith)
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->where('approval_stage', HrApprovalStages::PENDING_FIRST)
            ->orderByDesc('filed_at');

        return $query->get()->filter(fn ($c) => $this->canApprove($actor, $c));
    }
}
