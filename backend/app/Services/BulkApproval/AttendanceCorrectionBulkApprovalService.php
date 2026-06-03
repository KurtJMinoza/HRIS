<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Jobs\AttendanceCorrectionBulkFollowUpJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionApproval;
use App\Models\AttendanceCorrectionAudit;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\PresenceFilingAttendanceLogSyncService;
use App\Services\PresenceFilingService;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceCorrectionBulkApprovalService
{
    private const MODULE = OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly PresenceFilingService $presenceFilingService,
        private readonly PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
    ) {}

    /**
     * @param  int[]  $ids
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[]}
     */
    public function approveMany(User $actor, array $ids, ?string $notes): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return $this->emptyResult();
        }

        $corrections = AttendanceCorrection::query()
            ->with(['user', 'filedBy'])
            ->whereIn('id', $ids)
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->get()
            ->keyBy('id');

        $pendingRecords = OrgApprovalRecord::query()
            ->where('module_type', self::MODULE)
            ->whereIn('request_id', $ids)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->get()
            ->unique('request_id')
            ->keyBy('request_id');

        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $tz = $this->presenceFilingService->attendanceTimezone();
        $now = now();

        /** @var list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}> $finalItems */
        $finalItems = [];
        /** @var list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}> $firstStepItems */
        $firstStepItems = [];
        $failedItems = [];
        $skipped = 0;

        foreach ($ids as $id) {
            $correction = $corrections->get($id);
            if ($correction === null) {
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => 'Attendance correction was not found or is no longer pending.',
                ];
                continue;
            }

            $employee = $correction->user;
            if (! $employee instanceof User) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'Employee was not found.'];
                continue;
            }

            try {
                $this->dataScopeService->ensureCorrectionSubjectAccessible($actor, $employee);
            } catch (\Throwable) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve this request.'];
                continue;
            }

            $pending = $pendingRecords->get($id);
            if (! $pending instanceof OrgApprovalRecord) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'No pending approval step found.'];
                continue;
            }

            $authorization = $this->approvalWorkflowService->authorizePendingRecord(
                $actor,
                $pending,
                $employee,
                self::MODULE,
            );
            if (! $authorization['allowed']) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => 'You are not authorized to approve at this stage.'];
                continue;
            }

            try {
                $d = Carbon::parse($correction->date->toDateString())->startOfDay();
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $d, $d);
            } catch (\RuntimeException $e) {
                $skipped++;
                $failedItems[] = ['request_id' => $id, 'reason' => $e->getMessage()];
                continue;
            }

            $item = ['correction' => $correction, 'employee' => $employee, 'pending' => $pending];
            if ($pending->approver_role === HrRole::AdminHr->value) {
                if (! $correction->hasRequiredTimesForFinalApproval()) {
                    $skipped++;
                    $failedItems[] = [
                        'request_id' => $id,
                        'reason' => 'Required clock-in/out times are missing or invalid for final approval.',
                    ];
                    continue;
                }
                $finalItems[] = $item;
            } else {
                $firstStepItems[] = $item;
            }
        }

        $approvedIds = [];
        $payrollDates = [];

        if ($finalItems !== []) {
            $approvedIds = array_merge(
                $approvedIds,
                $this->approveFinalBatch($actor, $finalItems, $notes, $roleLabel, $tz, $now, $payrollDates),
            );
        }

        if ($firstStepItems !== []) {
            $approvedIds = array_merge(
                $approvedIds,
                $this->approveFirstStepBatch($actor, $firstStepItems, $notes, $roleLabel, $now),
            );
        }

        $approvedIds = array_values(array_unique($approvedIds));
        $approved = count($approvedIds);

        if ($approved > 0) {
            foreach ($approvedIds as $correctionId) {
                ReviewRequestCache::forget('attendance_correction', $correctionId);
            }
            AttendanceCorrectionModuleCache::flush();

            $actorId = (int) $actor->id;
            $dateKeys = array_values(array_unique($payrollDates));
            app()->terminating(static function () use ($approvedIds, $actorId, $dateKeys, $notes): void {
                (new AttendanceCorrectionBulkFollowUpJob($approvedIds, $actorId, $dateKeys, $notes))->handle(
                    app(\App\Services\NotificationService::class),
                    app(\App\Services\OvertimeService::class),
                );
            });
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
            'failed' => 0,
            'failed_items' => $failedItems,
            'approved_ids' => $approvedIds,
        ];
    }

    /**
     * @param  list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}>  $items
     * @param  string[]  $payrollDates
     * @return int[]
     */
    private function approveFinalBatch(
        User $actor,
        array $items,
        ?string $notes,
        string $roleLabel,
        string $tz,
        Carbon $now,
        array &$payrollDates,
    ): array {
        $correctionIds = [];
        $auditRows = [];
        $approvalRows = [];
        $approverName = $actor->display_name ?? $actor->name;

        DB::transaction(function () use ($items, $actor, $notes, $roleLabel, $tz, $now, &$correctionIds, &$auditRows, &$approvalRows, &$payrollDates, $approverName) {
            $pendingIds = array_map(static fn (array $item): int => (int) $item['pending']->id, $items);
            OrgApprovalRecord::query()
                ->whereIn('id', $pendingIds)
                ->update([
                    'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                    'remarks' => $notes,
                    'approved_at' => $now,
                    'approver_id' => $actor->id,
                    'approver_name' => $approverName,
                    'updated_at' => $now,
                ]);

            foreach ($items as $item) {
                $correction = $item['correction'];
                $employee = $item['employee'];
                $dateKey = $correction->date->toDateString();
                $payrollDates[] = $dateKey;

                $timeIn = $correction->time_in ? $correction->time_in->copy()->timezone($tz) : null;
                $timeOut = $correction->time_out ? $correction->time_out->copy()->timezone($tz) : null;
                $previousIn = $correction->time_in;
                $previousOut = $correction->time_out;
                $finalNote = $notes ?? 'Final approval (Admin HR).';

                $correction->time_in = $timeIn?->copy()->setTimezone('UTC');
                $correction->time_out = $timeOut?->copy()->setTimezone('UTC');
                $correction->pending_approval = false;
                $correction->approved = true;
                $correction->approved_by = $actor->id;
                $correction->approved_at = $now;
                $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_APPROVED;
                $correction->second_approver_id = $actor->id;
                $correction->second_approved_at = $now;
                $correction->save();

                $correctionIds[] = (int) $correction->id;

                $auditRows[] = [
                    'attendance_correction_id' => $correction->id,
                    'admin_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'date' => $dateKey,
                    'previous_time_in' => $previousIn,
                    'previous_time_out' => $previousOut,
                    'new_time_in' => $correction->time_in,
                    'new_time_out' => $correction->time_out,
                    'reason' => $finalNote,
                    'action' => 'approve_final',
                    'approver_role' => $roleLabel,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $approvalRows[] = [
                    'attendance_correction_id' => $correction->id,
                    'approver_id' => $actor->id,
                    'level' => 2,
                    'status' => 'approved',
                    'notes' => $finalNote,
                    'acted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($auditRows !== []) {
                AttendanceCorrectionAudit::query()->insert($auditRows);
            }
            if ($approvalRows !== []) {
                AttendanceCorrectionApproval::query()->insert($approvalRows);
            }
        });

        $freshCorrections = AttendanceCorrection::query()
            ->with('user')
            ->whereIn('id', $correctionIds)
            ->get();

        $syncResults = $this->attendanceLogSyncService->syncApprovedCorrectionsBatch(
            $freshCorrections,
            $actor,
            $roleLabel,
        );

        foreach ($freshCorrections as $correction) {
            $sync = $syncResults[(int) $correction->id] ?? null;
            if ($sync === null) {
                continue;
            }
            $correction->is_incomplete_record = ! (($sync['applied_time_in'] ?? null) && ($sync['applied_time_out'] ?? null));
            $correction->attendance_logs_synced_at = $now;
            $correction->attendance_logs_synced_by = $actor->id;
            $correction->save();
        }

        return $correctionIds;
    }

    /**
     * @param  list<array{correction: AttendanceCorrection, employee: User, pending: OrgApprovalRecord}>  $items
     * @return int[]
     */
    private function approveFirstStepBatch(
        User $actor,
        array $items,
        ?string $notes,
        string $roleLabel,
        Carbon $now,
    ): array {
        $approvedIds = [];
        $approverName = $actor->display_name ?? $actor->name;

        foreach ($items as $item) {
            $correction = $item['correction'];
            $employee = $item['employee'];
            $pending = $item['pending'];
            $dateKey = $correction->date->toDateString();

            DB::transaction(function () use ($actor, $correction, $employee, $pending, $notes, $roleLabel, $now, $approverName, $dateKey) {
                OrgApprovalRecord::query()
                    ->whereKey($pending->id)
                    ->update([
                        'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
                        'remarks' => $notes,
                        'approved_at' => $now,
                        'approver_id' => $actor->id,
                        'approver_name' => $approverName,
                        'updated_at' => $now,
                    ]);

                if ($correction->first_approver_id === null) {
                    $correction->first_approver_id = $actor->id;
                }

                $nextPending = OrgApprovalRecord::query()
                    ->where('module_type', self::MODULE)
                    ->where('request_id', $correction->id)
                    ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                    ->orderBy('sequence_order')
                    ->first();

                if ($nextPending?->approver_role === HrRole::AdminHr->value) {
                    $correction->first_approved_at = $now;
                    $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_PENDING_SECOND;
                } else {
                    $correction->approval_stage = AttendanceCorrectionApprovalService::STAGE_PENDING_FIRST;
                }
                $correction->save();

                AttendanceCorrectionAudit::query()->insert([
                    'attendance_correction_id' => $correction->id,
                    'admin_id' => $actor->id,
                    'employee_id' => $employee->id,
                    'date' => $dateKey,
                    'previous_time_in' => $correction->time_in,
                    'previous_time_out' => $correction->time_out,
                    'new_time_in' => $correction->time_in,
                    'new_time_out' => $correction->time_out,
                    'reason' => $notes ?? 'First approval.',
                    'action' => 'approve_first',
                    'approver_role' => $roleLabel,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                AttendanceCorrectionApproval::query()->insert([
                    'attendance_correction_id' => $correction->id,
                    'approver_id' => $actor->id,
                    'level' => (int) ($pending->sequence_order ?? 1),
                    'status' => 'approved',
                    'notes' => $notes,
                    'acted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            $approvedIds[] = (int) $correction->id;
        }

        return $approvedIds;
    }

    /**
     * @return array{approved: int, skipped: int, failed: int, failed_items: array<int, array{request_id: int, reason: string}>, approved_ids: int[]}
     */
    private function emptyResult(): array
    {
        return [
            'approved' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failed_items' => [],
            'approved_ids' => [],
        ];
    }
}
