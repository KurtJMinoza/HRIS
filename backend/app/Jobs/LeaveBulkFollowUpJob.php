<?php

namespace App\Jobs;

use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LeaveBulkFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int[]  $leaveIds
     */
    public function __construct(
        private readonly array $leaveIds,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $leaves = LeaveRequest::query()
            ->with('user')
            ->whereIn('id', array_values(array_unique(array_map('intval', $this->leaveIds))))
            ->get();

        foreach ($leaves as $leave) {
            $employee = $leave->user;
            if (! $employee instanceof User) {
                continue;
            }

            if ($leave->status === LeaveRequest::STATUS_APPROVED) {
                $notificationService->notifyRequester(
                    $employee,
                    $leave,
                    'leave',
                    'leave.final_approved',
                    'Leave request approved',
                    'Your leave request has been approved.',
                    '/employee/requests?request_id='.$leave->id,
                );
                continue;
            }

            if ($leave->status !== LeaveRequest::STATUS_PENDING || ! $leave->pending_approval) {
                continue;
            }

            $nextPending = OrgApprovalRecord::query()
                ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
                ->where('request_id', $leave->id)
                ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
                ->orderBy('sequence_order')
                ->first();

            if ($nextPending instanceof OrgApprovalRecord) {
                $notificationService->notifyApprovalRecord(
                    $nextPending,
                    $leave,
                    'leave',
                    'leave.needs_approval',
                    'Leave request needs approval',
                    ($employee->display_name ?? $employee->name ?? 'An employee').' needs the next leave approval step.',
                    '/admin/leave?review_id='.$leave->id,
                );
            }
        }
    }
}
