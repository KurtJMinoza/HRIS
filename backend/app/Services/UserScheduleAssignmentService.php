<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkingSchedule;

class UserScheduleAssignmentService
{
    public function assign(User $user, ?WorkingSchedule $schedule): void
    {
        $user->working_schedule_id = $schedule?->id;
        // Standardize all template-driven assignments on live linkage.
        $user->schedule = null;
        $user->save();
    }
}
