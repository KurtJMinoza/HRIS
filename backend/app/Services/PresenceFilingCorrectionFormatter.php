<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionAudit;
use App\Models\User;
use Carbon\Carbon;

/**
 * JSON payload for presence filings / attendance corrections (shared by employee + admin APIs).
 */
class PresenceFilingCorrectionFormatter
{
    public function __construct(
        private readonly AttendanceCorrectionApprovalService $approvalService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * Normalize DB / JSON / string values to Carbon (avoids calling ->toIso8601String() on strings).
     */
    private function asCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function toIso8601(mixed $value): ?string
    {
        $c = $this->asCarbon($value);

        return $c?->toIso8601String();
    }

    private function toIso8601InTz(mixed $value, string $tz): ?string
    {
        $c = $this->asCarbon($value);
        if ($c === null) {
            return null;
        }

        return $c->copy()->timezone($tz)->toIso8601String();
    }

    private function dayNameForDate(mixed $date, string $tz): ?string
    {
        $c = $this->asCarbon($date);

        return $c?->timezone($tz)->format('l');
    }

    public function chainPayload(?array $chain): ?array
    {
        if ($chain === null) {
            return null;
        }

        return array_map(fn (HrRole $r) => ['role' => $r->value, 'label' => $r->badgeLabel()], $chain);
    }

    /**
     * Reload a correction with relations needed for display fields (approval progress, audits).
     */
    public function freshWithDisplayRelations(AttendanceCorrection $correction): AttendanceCorrection
    {
        return $correction->fresh()->load([
            'user',
            'filedBy',
            'firstApprover',
            'secondApprover',
            'rejectedBy',
            'attendanceLogsSyncedBy',
            'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
            'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
        ]);
    }

    /**
     * Attach per-step remarks from the audit trail (file, approve_first, approve_final / reject).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    public function mergeRemarksIntoApprovalProgress(AttendanceCorrection $correction, array $steps): array
    {
        if (! $correction->relationLoaded('audits')) {
            return $steps;
        }

        $audits = $correction->audits;
        foreach ($steps as $i => $step) {
            $key = $step['key'] ?? '';
            $remarks = null;
            if ($key === 'submitted') {
                $a = $audits->firstWhere('action', 'file');
                $remarks = $a?->reason;
            } elseif ($key === 'line_approval') {
                $a = $audits->firstWhere('action', 'approve_first');
                $remarks = $a?->reason;
            } elseif ($key === 'hr_final') {
                $a = $audits->firstWhere('action', 'approve_final');
                if (! $a) {
                    $a = $audits->firstWhere('action', 'reject');
                }
                $remarks = $a?->reason;
            }
            $steps[$i]['remarks'] = $remarks;
        }

        return $steps;
    }

    public function format(
        AttendanceCorrection $c,
        string $tz,
        bool $includeEmployee = false,
        ?User $actor = null,
        bool $includeDisplayFields = false,
    ): array {
        $employee = $c->user;
        $chain = $employee ? $this->approvalService->getApprovalChain($employee) : null;

        $row = [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'date' => $c->date?->toDateString(),
            'day_name' => $this->dayNameForDate($c->date, $tz),
            'issue_kind' => $c->issue_kind,
            'time_in' => $this->toIso8601InTz($c->time_in, $tz),
            'time_out' => $this->toIso8601InTz($c->time_out, $tz),
            'remarks' => $c->remarks,
            'reason_code' => $c->reason_code,
            'pending_approval' => (bool) $c->pending_approval,
            'approved' => (bool) $c->approved,
            'approved_by' => $c->approved_by,
            'approved_at' => $this->toIso8601($c->approved_at),
            'filed_at' => $this->toIso8601($c->filed_at),
            'filed_by' => $c->filed_by,
            'rejected_at' => $this->toIso8601($c->rejected_at),
            'rejected_by' => $c->rejected_by,
            'rejection_note' => $c->rejection_note,
            'approval_stage' => $c->approval_stage,
            'first_approver_id' => $c->first_approver_id,
            'first_approved_at' => $this->toIso8601($c->first_approved_at),
            'second_approver_id' => $c->second_approver_id,
            'second_approved_at' => $this->toIso8601($c->second_approved_at),
            'is_incomplete_record' => (bool) $c->is_incomplete_record,
            'attendance_logs_synced_at' => $this->toIso8601($c->attendance_logs_synced_at),
            'attendance_logs_synced_by' => $c->attendance_logs_synced_by,
            'status' => $this->deriveStatus($c),
            'approval_chain' => $this->chainPayload($chain),
            'last_updated' => $this->toIso8601($c->updated_at),
        ];
        if ($includeEmployee && $c->relationLoaded('user') && $c->user) {
            $row['employee_name'] = $c->user->name;
            $row['employee_code'] = $c->user->employee_code;
            $row['employee_profile_image_url'] = $c->user->profile_image_url;
            $row['employee_position'] = $c->user->position;
            $empRole = $this->hrRoleResolver->resolveForApprovalSubject($c->user);
            $row['employee_hr_role'] = $empRole->value;
            $row['employee_role_label'] = $empRole->badgeLabel();
            $deptRel = $c->user->relationLoaded('departmentRelation') ? $c->user->departmentRelation : null;
            $row['department'] = $deptRel?->name ?? (is_string($c->user->department ?? null) ? $c->user->department : null);
            if ($c->user->relationLoaded('branch') && $c->user->branch) {
                $row['branch'] = $c->user->branch->name;
            } else {
                $row['branch'] = ($deptRel && $deptRel->relationLoaded('branch') && $deptRel->branch)
                    ? $deptRel->branch->name
                    : null;
            }
            $row['company'] = ($c->user->relationLoaded('company') && $c->user->company)
                ? $c->user->company->name
                : null;
        }

        if ($includeDisplayFields) {
            $row['issue_type'] = $this->deriveIssueType($c);
            $fileAudit = $c->relationLoaded('audits')
                ? $c->audits->firstWhere('action', 'file')
                : null;
            $reqIn = $fileAudit?->new_time_in ?? $c->time_in;
            $reqOut = $fileAudit?->new_time_out ?? $c->time_out;
            $row['requested_time_in'] = $this->toIso8601InTz($reqIn, $tz);
            $row['requested_time_out'] = $this->toIso8601InTz($reqOut, $tz);
            $row['display_status'] = $this->deriveDisplayStatusLabel($c);
            $row['last_action_label'] = $this->buildLastActionLabel($c, $tz);
            $requester = ($c->relationLoaded('filedBy') && $c->filedBy) ? $c->filedBy : $employee;
            if ($requester) {
                $row['requested_by_id'] = $requester->id;
                $row['requested_by_name'] = $requester->name;
                $row['requested_by_profile_image_url'] = $requester->profile_image_url;
                $row['requested_by_position'] = $requester->position;
                $hrRole = $requester->isAdmin()
                    ? HrRole::AdminHr
                    : $this->hrRoleResolver->resolveForApprovalSubject($requester);
                $row['requested_by_hr_role'] = $hrRole->value;
                $row['requested_by_role_label'] = $hrRole->badgeLabel();
            } else {
                $row['requested_by_name'] = null;
            }
            if ($c->relationLoaded('firstApprover') && $c->firstApprover) {
                $row['first_approver_name'] = $c->firstApprover->name;
            }
            if ($c->relationLoaded('secondApprover') && $c->secondApprover) {
                $row['second_approver_name'] = $c->secondApprover->name;
            }
            if ($c->relationLoaded('attendanceLogsSyncedBy') && $c->attendanceLogsSyncedBy) {
                $row['attendance_logs_synced_by_name'] = $c->attendanceLogsSyncedBy->name;
            }
            $row['approval_progress'] = $this->mergeRemarksIntoApprovalProgress(
                $c,
                $this->approvalService->buildApprovalProgress($c)
            );
            if ($c->relationLoaded('approvals')) {
                $row['approval_events'] = $c->approvals->map(function ($ev) {
                    return [
                        'level' => $ev->level,
                        'status' => $ev->status,
                        'notes' => $ev->notes,
                        'at' => $this->toIso8601($ev->acted_at ?? $ev->created_at),
                        'approver_id' => $ev->approver_id,
                        'approver_name' => $ev->relationLoaded('approver') && $ev->approver ? $ev->approver->name : null,
                    ];
                })->values()->all();
            }
            if ($c->relationLoaded('audits')) {
                $row['approval_history'] = $c->audits->map(function (AttendanceCorrectionAudit $a) {
                    return [
                        'action' => $a->action,
                        'approver_role' => $a->approver_role,
                        'details' => $a->reason,
                        'at' => $this->toIso8601($a->created_at),
                        'actor_name' => $a->relationLoaded('admin') && $a->admin ? $a->admin->name : null,
                    ];
                })->values()->all();
            }
        }

        if ($actor !== null) {
            $canApprove = $this->approvalService->canApprove($actor, $c);
            $row['actor_can_approve'] = $canApprove;
            $row['actor_can_reject'] = $this->approvalService->canReject($actor, $c);
            $row['actor_can_delete'] = $this->canDeletePendingRequest($actor, $c);
            $actorHr = $this->hrRoleResolver->resolve($actor) === HrRole::AdminHr;
            $stage = $c->approval_stage ?? AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST;
            $row['hr_wait_message'] = null;
            $row['actor_approval_block_reason'] = null;
            if ($actorHr && $chain !== null && count($chain) >= 2 && $stage === AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST
                && $c->pending_approval && ! $c->approved && ! $c->rejected_at) {
                $row['hr_wait_message'] = sprintf(
                    'Waiting for %s approval before HR can approve or reject.',
                    match ($chain[0]) {
                        HrRole::DepartmentHead => 'Department Head',
                        HrRole::BranchHead => 'Branch Head',
                        HrRole::CompanyHead => 'Company Head',
                        default => $chain[0]->badgeLabel(),
                    }
                );
                if (! $canApprove) {
                    $row['actor_approval_block_reason'] = $row['hr_wait_message'];
                }
            } elseif (! $canApprove && $c->pending_approval && ! $c->approved && ! $c->rejected_at) {
                $row['actor_approval_block_reason'] = 'You are not assigned to the current approval step.';
            }
            $row['actor_can_add_hr_note'] = $actorHr
                && $c->pending_approval
                && ! $c->approved
                && ! $c->rejected_at;
        }

        return $row;
    }

    private function canDeletePendingRequest(User $actor, AttendanceCorrection $correction): bool
    {
        if (! $correction->pending_approval || $correction->approved || $correction->rejected_at) {
            return false;
        }

        $actorId = (int) $actor->id;

        return $actorId === (int) $correction->filed_by
            || $actorId === (int) $correction->user_id;
    }

    private function formatLocalDateTime(\DateTimeInterface|string|null $dt, string $tz): string
    {
        if ($dt === null) {
            return '—';
        }

        return Carbon::parse($dt)->timezone($tz)->format('M j, Y g:i A');
    }

    /**
     * Human-readable last step for the "Last action" column (approver + timestamp).
     */
    private function buildLastActionLabel(AttendanceCorrection $c, string $tz): ?string
    {
        if ($c->rejected_at) {
            $name = $c->relationLoaded('rejectedBy') && $c->rejectedBy ? $c->rejectedBy->name : '—';

            return sprintf('Rejected — %s — %s', $name, $this->formatLocalDateTime($c->rejected_at, $tz));
        }
        if ($c->approved && $c->second_approved_at) {
            $name = $c->relationLoaded('secondApprover') && $c->secondApprover ? $c->secondApprover->name : '—';

            return sprintf('HR Approved — %s — %s', $name, $this->formatLocalDateTime($c->second_approved_at, $tz));
        }
        if ($c->first_approved_at && $c->relationLoaded('firstApprover') && $c->firstApprover) {
            $subject = $c->user ? $this->hrRoleResolver->resolveForApprovalSubject($c->user) : HrRole::Employee;
            $step = match ($subject) {
                HrRole::Employee => 'Department Head Approved',
                HrRole::DepartmentHead => 'Branch Head Approved',
                HrRole::BranchHead => 'Company Head Approved',
                HrRole::CompanyHead => 'Approved',
                default => 'Approved',
            };

            return sprintf(
                '%s — %s — %s',
                $step,
                $c->firstApprover->name,
                $this->formatLocalDateTime($c->first_approved_at, $tz)
            );
        }
        if ($c->filed_at) {
            return sprintf('Submitted — %s', $this->formatLocalDateTime($c->filed_at, $tz));
        }

        return null;
    }

    private function deriveIssueType(AttendanceCorrection $c): string
    {
        $stored = $c->issue_kind ?? null;
        if (is_string($stored) && in_array($stored, ['missing_in', 'missing_out', 'both'], true)) {
            return $stored;
        }

        $hasIn = $c->time_in !== null;
        $hasOut = $c->time_out !== null;
        if (! $hasIn && ! $hasOut) {
            return 'both';
        }
        if (! $hasIn) {
            return 'missing_in';
        }
        if (! $hasOut) {
            return 'missing_out';
        }

        return 'complete';
    }

    private function deriveDisplayStatusLabel(AttendanceCorrection $c): string
    {
        if ($c->rejected_at) {
            return 'Rejected';
        }
        if ($c->approved) {
            return 'HR Approved';
        }
        if (! $c->pending_approval) {
            return 'Draft';
        }

        $stage = $c->approval_stage ?? AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST;
        $empRole = $c->user ? $this->hrRoleResolver->resolveForApprovalSubject($c->user) : HrRole::Employee;

        if ($stage === AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST) {
            return match ($empRole) {
                HrRole::Employee => 'Pending Department Head Approval',
                HrRole::DepartmentHead => 'Pending Branch Head Approval',
                HrRole::BranchHead => 'Pending Company Head Approval',
                HrRole::CompanyHead => 'Pending HR Approval',
                HrRole::AdminHr => 'Pending HR Approval',
            };
        }

        if ($stage === AttendanceCorrectionApprovalService::STAGE_PENDING_SECOND) {
            return 'Pending HR Approval';
        }

        return 'Pending';
    }

    private function deriveStatus(AttendanceCorrection $c): string
    {
        if ($c->rejected_at) {
            return 'rejected';
        }
        if ($c->approved) {
            return 'approved';
        }
        if ($c->pending_approval) {
            return 'pending';
        }

        return 'draft';
    }
}
