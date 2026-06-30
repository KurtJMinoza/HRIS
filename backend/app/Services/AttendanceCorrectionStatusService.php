<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Support\AdminDashboardCache;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\DashboardPendingCountsCache;
use App\Support\HrApprovalStages;
use App\Support\ReviewRequestCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceCorrectionStatusService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public function markHrFinalApproved(AttendanceCorrection $correction, User $actor, ?\DateTimeInterface $at = null): void
    {
        $now = $at ?? now();
        $correction->pending_approval = false;
        $correction->approved = true;
        $correction->approved_by = $actor->id;
        $correction->approved_at = $now;
        $correction->approval_stage = HrApprovalStages::APPROVED;
        $correction->status = self::STATUS_APPROVED;
        $correction->final_approved_by = $actor->id;
        if ($correction->second_approver_id === null) {
            $correction->second_approver_id = $actor->id;
        }
        if ($correction->second_approved_at === null) {
            $correction->second_approved_at = $now;
        }
        $correction->save();

        $this->closeAllPendingApprovalRecords((int) $correction->id, $actor, $now);
    }

    public function markFirstStepApproved(AttendanceCorrection $correction, User $actor, bool $awaitingHr, ?\DateTimeInterface $at = null): void
    {
        $now = $at ?? now();
        $correction->pending_approval = true;
        $correction->approved = false;
        $correction->status = self::STATUS_PENDING;
        $correction->approval_stage = $awaitingHr
            ? HrApprovalStages::PENDING_SECOND
            : HrApprovalStages::PENDING_FIRST;
        if ($correction->first_approver_id === null) {
            $correction->first_approver_id = $actor->id;
        }
        if ($correction->first_approved_at === null) {
            $correction->first_approved_at = $now;
        }
        $correction->save();
    }

    public function markRejected(AttendanceCorrection $correction, User $actor, string $note, ?\DateTimeInterface $at = null): void
    {
        $now = $at ?? now();
        $correction->pending_approval = false;
        $correction->approved = false;
        $correction->rejected_at = $now;
        $correction->rejected_by = $actor->id;
        $correction->rejection_note = $note;
        $correction->approval_stage = HrApprovalStages::REJECTED;
        $correction->status = self::STATUS_REJECTED;
        $correction->final_approved_by = null;
        $correction->save();

        $this->closeAllPendingApprovalRecords((int) $correction->id, $actor, $now, OrgApprovalRecord::STATUS_REJECTED);
    }

    public function markCancelled(AttendanceCorrection $correction): void
    {
        $correction->pending_approval = false;
        $correction->approved = false;
        $correction->status = self::STATUS_CANCELLED;
        $correction->save();
    }

    public function closeAllPendingApprovalRecords(
        int $correctionId,
        User $actor,
        ?\DateTimeInterface $at = null,
        string $terminalStatus = OrgApprovalRecord::STATUS_APPROVED,
    ): void {
        $now = $at ?? now();
        $approverName = $actor->display_name ?? $actor->name;

        OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->where('request_id', $correctionId)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->update([
                'approval_status' => $terminalStatus,
                'approved_at' => $now,
                'approver_id' => $actor->id,
                'approver_name' => $approverName,
                'updated_at' => $now,
            ]);
    }

    public function displayStatusLabel(AttendanceCorrection $correction): string
    {
        return match ($this->resolvedStatus($correction)) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    public function resolvedStatus(AttendanceCorrection $correction): string
    {
        $stored = is_string($correction->status) ? trim($correction->status) : '';
        if (in_array($stored, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return $stored;
        }

        if ($correction->rejected_at !== null) {
            return self::STATUS_REJECTED;
        }
        if ($this->isHrApproved($correction)) {
            return self::STATUS_APPROVED;
        }
        if (! $correction->pending_approval && ! $correction->approved && $correction->rejected_at === null) {
            return self::STATUS_CANCELLED;
        }

        return self::STATUS_PENDING;
    }

    public function isHrApproved(AttendanceCorrection $correction): bool
    {
        if ($correction->status === self::STATUS_APPROVED) {
            return true;
        }

        return (bool) $correction->approved
            || $correction->approval_stage === HrApprovalStages::APPROVED
            || $correction->second_approved_at !== null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<AttendanceCorrection>  $query
     * @return array{total: int, pending: int, approved: int, rejected: int, cancelled: int}
     */
    public function aggregateStatusCounts($query): array
    {
        $rows = (clone $query)
            ->cloneWithout(['columns', 'orders', 'limit', 'offset', 'groups', 'havings'])
            ->cloneWithoutBindings(['select', 'order', 'group', 'having'])
            ->reorder()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = $rows->sum(static fn ($count): int => (int) $count);

        return [
            'total' => (int) $total,
            'pending' => (int) ($rows[self::STATUS_PENDING] ?? 0),
            'approved' => (int) ($rows[self::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($rows[self::STATUS_REJECTED] ?? 0),
            'cancelled' => (int) ($rows[self::STATUS_CANCELLED] ?? 0),
        ];
    }

    public function invalidateCaches(?User $actor = null, ?int $companyId = null, ?int $correctionId = null, bool $bumpModuleVersion = false): void
    {
        if ($bumpModuleVersion) {
            AttendanceCorrectionModuleCache::flush();
        }

        if ($correctionId !== null) {
            ReviewRequestCache::forget('attendance_correction', $correctionId);
        }

        DashboardPendingCountsCache::forgetForActor($actor);

        $companyId = $companyId ?? ($actor?->getEffectiveCompanyId() ?? $actor?->company_id);
        if ($companyId !== null && (int) $companyId > 0) {
            AdminDashboardCache::invalidateForUserCompany((int) $companyId, ['attendance', 'requests', 'summary']);
        }

        if ($actor !== null) {
            try {
                Cache::forget('sidebar:user:'.(int) $actor->id);
                Cache::forget('notification_counts:user:'.(int) $actor->id);
                Cache::forget('notification_module_counts:user:'.(int) $actor->id);
            } catch (\Throwable) {
            }
        }
    }

    public function logAfterApproval(
        AttendanceCorrection $correction,
        string $oldStatus,
        string $newStatus,
        ?int $pendingStepCount = null,
    ): void {
        $pendingSteps = $pendingStepCount ?? OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->where('request_id', $correction->id)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->count();

        $counts = $this->aggregateStatusCounts(
            AttendanceCorrection::query()->whereKey($correction->id)
        );

        Log::info('attendance_correction.approval_sync', [
            'correction_id' => (int) $correction->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'approval_stage' => $correction->approval_stage,
            'pending_approval_steps' => $pendingSteps,
            'current_stage' => $correction->approval_stage,
            'cache_cleared' => true,
            'counts_after_update' => $counts,
        ]);
    }
}
