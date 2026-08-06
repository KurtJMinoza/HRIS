<?php

namespace App\Jobs;

use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\AttendanceCacheService;
use App\Services\EmployeeDashboardCacheService;
use App\Services\HrRoleResolver;
use App\Services\EmailTriggerService;
use App\Services\NotificationService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeService;
use App\Services\PayrollDailyRecordSyncService;
use App\Services\PresenceFilingAttendanceLogSyncService;
use App\Support\AdminDashboardCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Deferred notifications, overtime sync, and payroll recompute after fast bulk approval.
 */
class AttendanceCorrectionBulkFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * @param  int[]  $approvedCorrectionIds
     */
    public function __construct(
        private readonly array $approvedCorrectionIds,
        private readonly int $actorId,
    ) {
        $this->onConnection('redis');
        $this->onQueue('attendance-corrections');
    }

    public function handle(
        NotificationService $notificationService,
        OvertimeService $overtimeService,
        PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
        PayrollDailyRecordSyncService $payrollDailyRecordSyncService,
        HrRoleResolver $hrRoleResolver,
        EmailTriggerService $emailTrigger,
    ): void {
        $actor = User::query()->find($this->actorId);
        if (! $actor) {
            return;
        }

        $corrections = AttendanceCorrection::query()
            ->with(['user.workingSchedule', 'filedBy'])
            ->whereIn('id', $this->approvedCorrectionIds)
            ->get();

        $notificationService->markRelatedReadForEntities(
            (int) $actor->id,
            'attendance_correction',
            $this->approvedCorrectionIds,
            'attendance_correction.needs_approval',
        );

        $roleLabel = $hrRoleResolver->resolve($actor)->badgeLabel();
        $finalCorrections = $corrections
            ->filter(fn (AttendanceCorrection $correction): bool => (bool) $correction->approved)
            ->unique(fn (AttendanceCorrection $correction): string => $correction->user_id.'|'.$correction->date?->toDateString())
            ->values();
        $syncResults = $attendanceLogSyncService->syncApprovedCorrectionsBatch($finalCorrections, $actor, $roleLabel);
        $now = now();
        $syncRows = [];
        foreach ($finalCorrections as $correction) {
            $sync = $syncResults[(int) $correction->id] ?? null;
            if ($sync === null) {
                continue;
            }
            $syncRows[] = [
                'id' => (int) $correction->id,
                'is_incomplete_record' => ! (($sync['applied_time_in'] ?? null) && ($sync['applied_time_out'] ?? null)),
                'attendance_logs_synced_at' => $now,
                'attendance_logs_synced_by' => (int) $actor->id,
                'updated_at' => $now,
            ];
        }
        if ($syncRows !== []) {
            DB::table('attendance_corrections')->upsert(
                $syncRows,
                ['id'],
                ['is_incomplete_record', 'attendance_logs_synced_at', 'attendance_logs_synced_by', 'updated_at'],
            );
        }

        $overtimeSyncKeys = [];
        $affectedAttendanceRows = [];
        $affectedEmployeeIds = [];
        $affectedCompanyIds = [];
        $affectedPayrollPairs = [];
        $pendingCorrectionIds = $corrections
            ->filter(fn (AttendanceCorrection $correction): bool => ! $correction->approved && (bool) $correction->pending_approval)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $nextPendingByCorrection = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
            ->whereIn('request_id', $pendingCorrectionIds)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

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
                $affectedAttendanceRows[] = [
                    'employee_id' => (int) $employee->id,
                    'date' => $dateKey,
                ];
                $affectedEmployeeIds[(int) $employee->id] = true;
                $companyId = (int) ($employee->getEffectiveCompanyId() ?? $employee->company_id ?? 0);
                if ($companyId > 0) {
                    $affectedCompanyIds[$companyId] = true;
                }
                $pairKey = $employee->id.'|'.$dateKey;
                $overtimeSyncKeys[$pairKey] = [$employee, $dateKey, $correction->time_out];
                $affectedPayrollPairs[$pairKey] = [$employee, $dateKey];
                $notificationService->notifyRequester(
                    $employee,
                    $correction,
                    'attendance_correction',
                    'attendance_correction.approved',
                    'Attendance correction approved',
                    'Your attendance correction was approved and applied.',
                    '/employee/correction-requests?request_id='.$correction->id,
                );
                $emailTrigger->correctionFinalApproved($correction);

                continue;
            }

            if (! $correction->pending_approval) {
                continue;
            }

            $nextPending = $nextPendingByCorrection->get($correction->id);

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
                $emailTrigger->correctionNeedsNextApproval($correction, $nextPending);
            }
        }

        AttendanceCacheService::invalidateMany($affectedAttendanceRows);
        foreach (array_keys($affectedEmployeeIds) as $employeeId) {
            EmployeeDashboardCacheService::invalidate((int) $employeeId);
        }
        foreach (array_keys($affectedCompanyIds) as $companyId) {
            AdminDashboardCache::invalidateCompany((int) $companyId, ['attendance', 'summary']);
        }

        foreach ($overtimeSyncKeys as [$employee, $dateKey, $timeOut]) {
            $overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $timeOut, $actor);
        }

        foreach ($affectedPayrollPairs as [$employee, $dateKey]) {
            $payrollDailyRecordSyncService->syncDayForUser($employee, $dateKey);
        }

        /** @var array<int, array{employee: \App\Models\User, dates: list<string>}> $draftRefreshByEmployee */
        $draftRefreshByEmployee = [];
        foreach ($affectedPayrollPairs as [$employee, $dateKey]) {
            $uid = (int) $employee->id;
            $draftRefreshByEmployee[$uid] ??= ['employee' => $employee, 'dates' => []];
            $draftRefreshByEmployee[$uid]['dates'][] = $dateKey;
        }
        $payslipService = app(\App\Services\PayslipService::class);
        foreach ($draftRefreshByEmployee as $bundle) {
            $payslipService->refreshDraftPayslipsCoveringDates($bundle['employee'], $bundle['dates']);
        }
    }
}
