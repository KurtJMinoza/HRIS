<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central email trigger service.
 *
 * Wrap every trigger in DB::afterCommit so emails are only queued
 * after the surrounding transaction has committed successfully.
 */
class EmailTriggerService
{
    public function __construct(private readonly EmailNotificationService $emailService) {}

    // ─── Attendance Correction ───────────────────────────────────────────────

    /**
     * Correction submitted — notify the current (first) approver.
     */
    public function correctionFiled(AttendanceCorrection $correction): void
    {
        $approverId = $this->currentApproverIdForModel(
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
        ) ?? $correction->first_approver_id ?? $correction->second_approver_id;

        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $correction->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($correction, $employee, $approverId): void {
            $this->emailService->send(
                'attendance_correction_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $correction->date?->toDateString() ?? now()->toDateString(),
                    'action_url' => $this->fe('/admin/attendance/corrections?review_id='.$correction->id),
                ],
                $correction,
            );
        });
    }

    /**
     * Correction approved at intermediate step — notify the next approver.
     */
    public function correctionNeedsNextApproval(AttendanceCorrection $correction, OrgApprovalRecord $nextPending): void
    {
        $approverId = $nextPending->approver_id;
        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $correction->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($correction, $employee, $approverId): void {
            $this->emailService->send(
                'attendance_correction_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $correction->date?->toDateString() ?? now()->toDateString(),
                    'action_url' => $this->fe('/admin/attendance/corrections?review_id='.$correction->id),
                ],
                $correction,
            );
        });
    }

    /**
     * Correction finally approved — notify the requester.
     */
    public function correctionFinalApproved(AttendanceCorrection $correction): void
    {
        $employee = $this->loadUser((int) $correction->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveFinalApproverName(
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            [
                $correction->final_approved_by,
                $correction->approved_by,
                $correction->second_approver_id,
                $correction->first_approver_id,
            ],
        );

        $this->afterCommit(function () use ($correction, $employee, $approverName): void {
            $this->emailService->send(
                'attendance_correction_approved',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $correction->date?->toDateString() ?? now()->toDateString(),
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/correction-requests?request_id='.$correction->id),
                ],
                $correction,
            );
        });
    }

    /**
     * Correction rejected — notify the requester.
     */
    public function correctionRejected(AttendanceCorrection $correction): void
    {
        $employee = $this->loadUser((int) $correction->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveActorName($correction->rejected_by);

        $this->afterCommit(function () use ($correction, $employee, $approverName): void {
            $this->emailService->send(
                'attendance_correction_rejected',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $correction->date?->toDateString() ?? now()->toDateString(),
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/correction-requests?request_id='.$correction->id),
                ],
                $correction,
            );
        });
    }

    // ─── Leave ───────────────────────────────────────────────────────────────

    /**
     * Leave request filed — notify the current approver.
     */
    public function leaveFiled(LeaveRequest $leave): void
    {
        $approverId = $this->currentApproverIdForModel(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
        );

        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $leave->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($leave, $employee, $approverId): void {
            $this->emailService->send(
                'leave_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'leave_type' => ucfirst(str_replace('_', ' ', (string) ($leave->type ?? 'leave'))),
                    'start_date' => $leave->start_date ?? '',
                    'end_date' => $leave->end_date ?? '',
                    'action_url' => $this->fe('/admin/leave?review_id='.$leave->id),
                ],
                $leave,
            );
        });
    }

    /**
     * Leave approved at intermediate step — notify the next approver.
     */
    public function leaveNeedsNextApproval(LeaveRequest $leave, OrgApprovalRecord $nextPending): void
    {
        $approverId = $nextPending->approver_id;
        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $leave->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($leave, $employee, $approverId): void {
            $this->emailService->send(
                'leave_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'leave_type' => ucfirst(str_replace('_', ' ', (string) ($leave->type ?? 'leave'))),
                    'start_date' => $leave->start_date ?? '',
                    'end_date' => $leave->end_date ?? '',
                    'action_url' => $this->fe('/admin/leave?review_id='.$leave->id),
                ],
                $leave,
            );
        });
    }

    /**
     * Leave finally approved — notify the requester.
     */
    public function leaveFinalApproved(LeaveRequest $leave): void
    {
        $employee = $this->loadUser((int) $leave->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveFinalApproverName(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            [$leave->second_approver_id, $leave->reviewed_by],
        );

        $this->afterCommit(function () use ($leave, $employee, $approverName): void {
            $this->emailService->send(
                'leave_approved',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'leave_type' => ucfirst(str_replace('_', ' ', (string) ($leave->type ?? 'leave'))),
                    'start_date' => $leave->start_date ?? '',
                    'end_date' => $leave->end_date ?? '',
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/requests?request_id='.$leave->id),
                ],
                $leave,
            );
        });
    }

    /**
     * Leave rejected — notify the requester.
     */
    public function leaveRejected(LeaveRequest $leave): void
    {
        $employee = $this->loadUser((int) $leave->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveActorName($leave->rejected_by);

        $this->afterCommit(function () use ($leave, $employee, $approverName): void {
            $this->emailService->send(
                'leave_rejected',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'leave_type' => ucfirst(str_replace('_', ' ', (string) ($leave->type ?? 'leave'))),
                    'start_date' => $leave->start_date ?? '',
                    'end_date' => $leave->end_date ?? '',
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/requests?request_id='.$leave->id),
                ],
                $leave,
            );
        });
    }

    // ─── Overtime ────────────────────────────────────────────────────────────

    /**
     * Overtime filed — notify the current approver.
     */
    public function overtimeFiled(Overtime $overtime): void
    {
        $approverId = $this->currentApproverIdForModel(
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
        );

        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $overtime->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($overtime, $employee, $approverId): void {
            $this->emailService->send(
                'overtime_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $overtime->date?->toDateString() ?? now()->toDateString(),
                    'hours' => (string) round((float) ($overtime->computed_hours ?? 0), 2),
                    'action_url' => $this->fe('/admin/overtime?review_id='.$overtime->id),
                ],
                $overtime,
            );
        });
    }

    /**
     * Overtime approved at intermediate step — notify the next approver.
     */
    public function overtimeNeedsNextApproval(Overtime $overtime, OrgApprovalRecord $nextPending): void
    {
        $approverId = $nextPending->approver_id;
        if (! $approverId) {
            return;
        }

        $employee = $this->loadUser((int) $overtime->user_id);
        if (! $employee) {
            return;
        }

        $this->afterCommit(function () use ($overtime, $employee, $approverId): void {
            $this->emailService->send(
                'overtime_needs_approval',
                ['employee' => $employee, 'approver_id' => (int) $approverId],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $overtime->date?->toDateString() ?? now()->toDateString(),
                    'hours' => (string) round((float) ($overtime->computed_hours ?? 0), 2),
                    'action_url' => $this->fe('/admin/overtime?review_id='.$overtime->id),
                ],
                $overtime,
            );
        });
    }

    /**
     * Overtime finally approved — notify the requester.
     */
    public function overtimeFinalApproved(Overtime $overtime): void
    {
        $employee = $this->loadUser((int) $overtime->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveFinalApproverName(
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            [$overtime->approved_by, $overtime->second_approver_id],
        );

        $this->afterCommit(function () use ($overtime, $employee, $approverName): void {
            $this->emailService->send(
                'overtime_approved',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $overtime->date?->toDateString() ?? now()->toDateString(),
                    'hours' => (string) round((float) ($overtime->computed_hours ?? 0), 2),
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/overtime?request_id='.$overtime->id),
                ],
                $overtime,
            );
        });
    }

    /**
     * Overtime rejected — notify the requester.
     */
    public function overtimeRejected(Overtime $overtime): void
    {
        $employee = $this->loadUser((int) $overtime->user_id);
        if (! $employee) {
            return;
        }

        $approverName = $this->resolveActorName($overtime->rejected_by);

        $this->afterCommit(function () use ($overtime, $employee, $approverName): void {
            $this->emailService->send(
                'overtime_rejected',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'date' => $overtime->date?->toDateString() ?? now()->toDateString(),
                    'hours' => (string) round((float) ($overtime->computed_hours ?? 0), 2),
                    'approver_name' => $approverName,
                    'action_url' => $this->fe('/employee/overtime?request_id='.$overtime->id),
                ],
                $overtime,
            );
        });
    }

    // ─── Payroll / Payslip ───────────────────────────────────────────────────

    /**
     * Payslip generated/available — notify the employee.
     */
    public function payslipAvailable(User $employee, Model $payslip, string $payPeriod): void
    {
        if (! $employee->email) {
            return;
        }

        $this->afterCommit(function () use ($employee, $payslip, $payPeriod): void {
            $this->emailService->send(
                'payslip_available',
                ['employee' => $employee],
                [
                    'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                    'pay_period' => $payPeriod,
                    'action_url' => $this->fe('/employee/payslips/view/'.$payslip->getKey()),
                ],
                $payslip,
            );
        });
    }

    /**
     * Payroll run finalized — notify all employees included in the run.
     * Called from within a job (not inside a DB transaction), so afterCommit fires immediately.
     */
    public function payrollFinalized(int $batchRunId, string $payPeriod): void
    {
        \App\Models\Payslip::query()
            ->where('batch_run_id', $batchRunId)
            ->with('employee:id,email,first_name,middle_name,last_name,suffix,name')
            ->chunkById(50, function ($payslips) use ($payPeriod): void {
                foreach ($payslips as $payslip) {
                    $employee = $payslip->employee;
                    if (! $employee instanceof User || ! $employee->email) {
                        continue;
                    }

                    $this->emailService->send(
                        'payroll_finalized',
                        ['employee' => $employee],
                        [
                            'employee_name' => $employee->display_name ?? $employee->name ?? 'Employee',
                            'pay_period' => $payPeriod,
                            'action_url' => $this->fe('/employee/payslips/view/'.$payslip->getKey()),
                        ],
                        $payslip,
                    );
                }
            });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function currentApproverIdForModel(Model $model, string $module): ?int
    {
        return (int) OrgApprovalRecord::query()
            ->where('module_type', $module)
            ->where('request_id', $model->getKey())
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->orderBy('sequence_order')
            ->value('approver_id') ?: null;
    }

    private function loadUser(int $userId): ?User
    {
        return User::query()
            ->select(['id', 'email', 'first_name', 'middle_name', 'last_name', 'suffix', 'name'])
            ->find($userId);
    }

    private function resolveActorName(mixed $userId): string
    {
        $id = is_numeric($userId) ? (int) $userId : 0;
        if ($id <= 0) {
            return 'HR Administrator';
        }

        $user = $this->loadUser($id);

        return $user?->display_name ?? $user?->name ?? 'HR Administrator';
    }

    /**
     * @param  array<int|string|null>  $candidateApproverIds
     */
    private function resolveFinalApproverName(Model $model, string $module, array $candidateApproverIds): string
    {
        foreach ($candidateApproverIds as $candidateId) {
            $id = is_numeric($candidateId) ? (int) $candidateId : 0;
            if ($id > 0) {
                return $this->resolveActorName($id);
            }
        }

        $record = OrgApprovalRecord::query()
            ->where('module_type', $module)
            ->where('request_id', $model->getKey())
            ->where('approval_status', OrgApprovalRecord::STATUS_APPROVED)
            ->orderByDesc('sequence_order')
            ->first(['approver_id', 'approver_name']);

        if ($record instanceof OrgApprovalRecord) {
            if (filled($record->approver_name)) {
                return (string) $record->approver_name;
            }

            return $this->resolveActorName($record->approver_id);
        }

        return 'HR Administrator';
    }

    private function afterCommit(callable $callback): void
    {
        try {
            DB::afterCommit($callback);
        } catch (\Throwable $e) {
            Log::error('EmailTriggerService::afterCommit failed', ['error' => $e->getMessage()]);
        }
    }

    private function fe(string $path): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url', '')), '/').$path;
    }
}
