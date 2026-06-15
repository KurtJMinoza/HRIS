<?php

namespace App\Jobs;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Services\EmployeeDashboardCacheService;
use App\Services\NotificationService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmployeeFilingPostProcessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private readonly string $module,
        private readonly int $requestId,
        private readonly int $employeeId,
    ) {
        $this->onConnection('redis');
        $this->onQueue('employee-filing-post-process');
    }

    public function handle(NotificationService $notifications): void
    {
        EmployeeDashboardCacheService::invalidate($this->employeeId);

        $employee = User::query()->find($this->employeeId);
        if (! $employee instanceof User) {
            return;
        }

        if ($this->module === OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION) {
            $correction = AttendanceCorrection::query()->find($this->requestId);
            if (! $correction instanceof AttendanceCorrection) {
                return;
            }

            $approverId = $correction->first_approver_id ?: $correction->second_approver_id;
            if ($approverId) {
                $notifications->notifyUser((int) $approverId, [
                    'type' => 'attendance_correction.needs_approval',
                    'title' => 'Attendance correction needs approval',
                    'message' => ($employee->display_name ?? $employee->name ?? 'An employee').' submitted an attendance correction.',
                    'module' => 'attendance_correction',
                    'entity_id' => $correction->id,
                    'entity_type' => AttendanceCorrection::class,
                    'action_url' => '/admin/attendance/corrections?review_id='.$correction->id,
                    'company_id' => $employee->getEffectiveCompanyId(),
                    'department_id' => $employee->department_id,
                ]);
            }

            return;
        }

        $request = $this->module === OrgApprovalWorkflowService::MODULE_LEAVE
            ? LeaveRequest::query()->find($this->requestId)
            : Overtime::query()->find($this->requestId);
        if ($request === null) {
            return;
        }

        $isLeave = $this->module === OrgApprovalWorkflowService::MODULE_LEAVE;
        $notifications->notifyCurrentApprover(
            $request,
            $this->module,
            $isLeave ? 'leave' : 'overtime',
            $isLeave ? 'leave.needs_approval' : 'overtime.needs_approval',
            $isLeave ? 'Leave request needs approval' : 'Overtime request needs approval',
            ($employee->display_name ?? $employee->name ?? 'An employee').' filed '.($isLeave ? 'a leave' : 'an overtime').' request.',
            ($isLeave ? '/admin/leave' : '/admin/overtime').'?review_id='.$this->requestId,
        );
    }
}
