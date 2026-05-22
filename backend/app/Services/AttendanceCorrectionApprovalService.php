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
        private readonly OrgApprovalWorkflowService $workflowService,
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
        return $this->chainResolver->getApprovalChain(
            $employee,
            true,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
        );
    }

    public function initialApprovalStage(User $employee): string
    {
        return $this->chainResolver->initialApprovalStage(
            $employee,
            true,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
        );
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
        return $this->chainResolver->resolveRoutingDecision(
            $employee,
            true,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
        );
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

        $requestor = $correction->relationLoaded('filedBy') ? $correction->filedBy : null;

        return $this->workflowService->canAct(
            $actor,
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            $employee,
            $requestor,
        );
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

        $requestor = $correction->relationLoaded('filedBy') ? $correction->filedBy : null;

        return $this->workflowService->buildApprovalProgress(
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            $employee,
            $requestor,
            $correction->filed_at,
            (bool) $correction->approved,
            $correction->rejected_at !== null,
            $requestor,
        );
    }

    /**
     * Pending corrections the actor may act on (approve at current stage).
     */
    public function getPendingForApprover(User $actor): \Illuminate\Database\Eloquent\Collection
    {
        $displayWith = [
            'user.departmentRelation.branch',
            'user.division',
            'user.sectionUnit',
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

        $query = AttendanceCorrection::query()
            ->with($displayWith)
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->orderByDesc('filed_at');

        return $query->get()->filter(fn ($c) => $this->canApprove($actor, $c));
    }
}
