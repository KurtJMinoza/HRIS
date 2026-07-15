<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\Carbon;

class UserScheduleAssignmentService
{
    public function __construct(
        private readonly EmployeeScheduleAdjustmentService $adjustments,
    ) {}

    public function assign(User $user, ?WorkingSchedule $schedule, ?Carbon $effectiveDate = null, ?int $actorId = null, ?string $reason = null): void
    {
        if (! $schedule) {
            $user->forceFill([
                'working_schedule_id' => null,
                'pending_working_schedule_id' => null,
                'pending_schedule_effective_from' => null,
                'schedule' => null,
            ])->save();

            return;
        }

        $this->adjustments->apply([
            'employee_ids' => [(int) $user->id],
            'schedule_template_id' => (int) $schedule->id,
            'effective_start_date' => ($effectiveDate ?? Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila'))))->toDateString(),
            'effective_end_date' => null,
            'source_scope_type' => 'employee',
            'source_scope_id' => (int) $user->id,
            'is_adjustment' => true,
            'adjustment_reason' => $reason ?? 'Schedule assignment',
            'created_by' => $actorId,
            'replace_overlaps' => true,
        ]);
    }
}
