<?php

namespace App\Listeners;

use App\Events\AttendanceClockEvent;
use App\Events\AttendanceCorrectionUpdated;
use App\Events\LeaveApprovalUpdated;
use App\Events\OvertimeApprovalUpdated;
use App\Events\PayrollStatusUpdated;
use App\Events\PayslipAvailable;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\Log;

class EmailNotificationListener
{
    public function __construct(
        private readonly EmailNotificationService $service,
    ) {}

    public function handleAttendanceClock(AttendanceClockEvent $event): void
    {
        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $action = $event->payload['action'] ?? $event->payload['type'] ?? null;
        $notificationKey = $action === 'clock_out' ? 'attendance_clock_out' : 'attendance_clock_in';

        $this->service->send($notificationKey, ['employee' => $user], [
            'employee_name' => $user->display_name ?? $user->name ?? 'Employee',
            'date' => $event->payload['date'] ?? now()->toDateString(),
            'time' => $event->payload['time'] ?? now()->format('h:i A'),
            'action_url' => config('app.frontend_url', config('app.url')),
        ]);
    }

    public function handleLeaveApproval(LeaveApprovalUpdated $event): void
    {
        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $status = $event->payload['status'] ?? null;
        $notificationKey = match ($status) {
            'approved' => 'leave_approved',
            'rejected' => 'leave_rejected',
            default => 'leave_needs_approval',
        };

        $context = ['employee' => $user];
        if ($notificationKey === 'leave_needs_approval') {
            $context['approver_id'] = $event->payload['pending_approver_id']
                ?? $event->payload['first_approver_id']
                ?? $event->payload['second_approver_id']
                ?? null;
        }

        $this->service->send($notificationKey, $context, [
            'employee_name' => $user->name,
            'request_type' => 'Leave',
            'leave_type' => $event->payload['leave_type'] ?? $event->payload['type'] ?? 'Leave',
            'start_date' => $event->payload['start_date'] ?? '',
            'end_date' => $event->payload['end_date'] ?? '',
            'status' => $status ?? 'pending',
            'approver_name' => $event->payload['approver_name'] ?? '',
            'action_url' => config('app.frontend_url', config('app.url')),
            'company_name' => config('app.name', 'HRIS'),
        ]);
    }

    public function handleOvertimeApproval(OvertimeApprovalUpdated $event): void
    {
        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $status = $event->payload['status'] ?? null;
        $notificationKey = match ($status) {
            'approved' => 'overtime_approved',
            'rejected' => 'overtime_rejected',
            default => 'overtime_needs_approval',
        };

        $context = ['employee' => $user];
        if ($notificationKey === 'overtime_needs_approval') {
            $context['approver_id'] = $event->payload['pending_approver_id']
                ?? $event->payload['first_approver_id']
                ?? $event->payload['second_approver_id']
                ?? null;
        }

        $this->service->send($notificationKey, $context, [
            'employee_name' => $user->name,
            'request_type' => 'Overtime',
            'date' => $event->payload['date'] ?? '',
            'hours' => $event->payload['hours'] ?? '',
            'status' => $status ?? 'pending',
            'approver_name' => $event->payload['approver_name'] ?? '',
            'action_url' => config('app.frontend_url', config('app.url')),
            'company_name' => config('app.name', 'HRIS'),
        ]);
    }

    public function handleAttendanceCorrection(AttendanceCorrectionUpdated $event): void
    {
        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $status = $event->payload['status'] ?? null;
        $notificationKey = match ($status) {
            'approved' => 'attendance_correction_approved',
            'rejected' => 'attendance_correction_rejected',
            default => 'attendance_correction_needs_approval',
        };

        $context = ['employee' => $user];
        if ($notificationKey === 'attendance_correction_needs_approval') {
            $context['approver_id'] = $event->payload['pending_approver_id']
                ?? $event->payload['first_approver_id']
                ?? $event->payload['second_approver_id']
                ?? null;
        }

        $this->service->send($notificationKey, $context, [
            'employee_name' => $user->name,
            'request_type' => 'Attendance Correction',
            'date' => $event->payload['date'] ?? '',
            'status' => $status ?? 'pending',
            'approver_name' => $event->payload['approver_name'] ?? '',
            'action_url' => config('app.frontend_url', config('app.url')),
            'company_name' => config('app.name', 'HRIS'),
        ]);
    }

    public function handlePayslipAvailable(PayslipAvailable $event): void
    {
        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $this->service->send('payslip_available', ['employee' => $user], [
            'employee_name' => $user->name,
            'pay_period' => $event->payload['pay_period'] ?? $event->payload['period'] ?? '',
            'date' => $event->payload['date'] ?? now()->toDateString(),
            'action_url' => config('app.frontend_url', config('app.url')),
            'company_name' => config('app.name', 'HRIS'),
        ]);
    }

    public function handlePayrollStatus(PayrollStatusUpdated $event): void
    {
        $status = $event->payload['status'] ?? null;
        if ($status !== 'finalized') {
            return;
        }

        $user = $this->loadUser($event->userId);
        if (! $user) {
            return;
        }

        $this->service->send('payroll_finalized', ['employee' => $user], [
            'employee_name' => $user->name,
            'pay_period' => $event->payload['pay_period'] ?? $event->payload['period'] ?? '',
            'date' => $event->payload['date'] ?? now()->toDateString(),
            'action_url' => config('app.frontend_url', config('app.url')),
            'company_name' => config('app.name', 'HRIS'),
        ]);
    }

    private function loadUser(int $userId): ?User
    {
        $user = User::query()
            ->select(['id', 'email', 'first_name', 'middle_name', 'last_name', 'suffix', 'name', 'branch_id'])
            ->find($userId);

        if (! $user) {
            Log::warning('email_notification_listener: user not found', ['user_id' => $userId]);
        }

        return $user;
    }
}
