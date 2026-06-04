<?php

namespace App\Jobs;

use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\HrRoleResolver;
use App\Services\NotificationService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeService;
use App\Services\PresenceFilingAttendanceLogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deferred notifications, overtime sync, and payroll recompute after fast bulk approval.
 */
class AttendanceCorrectionBulkFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int[]  $approvedCorrectionIds
     * @param  string[]  $payrollDateKeys
     */
    public function __construct(
        private readonly array $approvedCorrectionIds,
        private readonly int $actorId,
        private readonly array $payrollDateKeys,
        private readonly ?string $remarks = null,
    ) {}

    public function handle(
        NotificationService $notificationService,
        OvertimeService $overtimeService,
        PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
        HrRoleResolver $hrRoleResolver,
    ): void {
        $actor = User::query()->find($this->actorId);
        if (! $actor) {
            return;
        }

        $corrections = AttendanceCorrection::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $this->approvedCorrectionIds)
            ->get();

        $roleLabel = $hrRoleResolver->resolve($actor)->badgeLabel();
        $syncResults = $attendanceLogSyncService->syncApprovedCorrectionsBatch($corrections, $actor, $roleLabel);
        $now = now();
        foreach ($corrections as $correction) {
            $sync = $syncResults[(int) $correction->id] ?? null;
            if ($sync === null) {
                continue;
            }
            $correction->is_incomplete_record = ! (($sync['applied_time_in'] ?? null) && ($sync['applied_time_out'] ?? null));
            $correction->attendance_logs_synced_at = $now;
            $correction->attendance_logs_synced_by = $actor->id;
            $correction->save();
        }

        $overtimeSyncKeys = [];

        foreach ($corrections as $correction) {
            $employee = $correction->user;
            if (! $employee instanceof User) {
                continue;
            }

            $dateKey = $correction->date?->toDateString();
            if ($dateKey === null) {
                continue;
            }

            if ($correction->approved) {
                $overtimeSyncKeys[$employee->id.'|'.$dateKey] = [$employee, $dateKey, $correction->time_out];
                $notificationService->notifyRequester(
                    $employee,
                    $correction,
                    'attendance_correction',
                    'attendance_correction.approved',
                    'Attendance correction approved',
                    'Your attendance correction was approved and applied.',
                    '/employee/correction-requests?request_id='.$correction->id,
                );
                continue;
            }

            if (! $correction->pending_approval) {
                continue;
            }

            $nextPending = OrgApprovalRecord::query()
                ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
                ->where('request_id', $correction->id)
                ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                ->orderBy('sequence_order')
                ->first();

            if ($nextPending instanceof OrgApprovalRecord) {
                $notificationService->notifyApprovalRecord(
                    $nextPending,
                    $correction,
                    'attendance_correction',
                    'attendance_correction.needs_approval',
                    'Attendance correction needs approval',
                    ($employee->display_name ?? $employee->name ?? 'An employee').' needs the next attendance correction approval step.',
                    '/admin/attendance/corrections?review_id='.$correction->id,
                );
            }
        }

        foreach ($overtimeSyncKeys as [$employee, $dateKey, $timeOut]) {
            $overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $timeOut, $actor);
        }

        foreach (array_values(array_unique($this->payrollDateKeys)) as $dateKey) {
            if (is_string($dateKey) && $dateKey !== '') {
                ProcessDailyPayrollJob::dispatchSync($dateKey);
            }
        }
    }
}
