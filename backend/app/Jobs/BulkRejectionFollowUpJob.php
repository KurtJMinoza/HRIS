<?php

namespace App\Jobs;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulkRejectionFollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int[]  $requestIds
     */
    public function __construct(
        private readonly string $module,
        private readonly array $requestIds,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        [$modelClass, $type, $title, $message, $urlPrefix] = match ($this->module) {
            'leave' => [
                LeaveRequest::class,
                'leave.rejected',
                'Leave request rejected',
                'Your leave request was rejected.',
                '/employee/requests?request_id=',
            ],
            'overtime' => [
                Overtime::class,
                'overtime.rejected',
                'Overtime request rejected',
                'Your overtime request was rejected.',
                '/employee/overtime?request_id=',
            ],
            default => [
                AttendanceCorrection::class,
                'attendance_correction.rejected',
                'Attendance correction rejected',
                'Your attendance correction was rejected.',
                '/employee/correction-requests?request_id=',
            ],
        };

        $records = $modelClass::query()
            ->with('user')
            ->whereIn('id', array_values(array_unique(array_map('intval', $this->requestIds))))
            ->get();

        foreach ($records as $record) {
            if (! $record->user instanceof User) {
                continue;
            }
            $notificationService->notifyRequester(
                $record->user,
                $record,
                $this->module,
                $type,
                $title,
                $message,
                $urlPrefix.$record->id,
                'high',
            );
        }
    }
}
