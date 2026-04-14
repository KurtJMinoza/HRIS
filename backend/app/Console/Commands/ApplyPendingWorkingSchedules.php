<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ApplyPendingWorkingSchedules extends Command
{
    protected $signature = 'schedule:apply-pending {--date= : Treat as "today" for comparison (Y-m-d), default: now in attendance timezone}';

    protected $description = 'Apply approved future schedule changes when the effective date is reached.';

    public function handle(UserScheduleAssignmentService $assignmentService): int
    {
        $tz = config('attendance.timezone', 'Asia/Manila');
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        $users = User::query()
            ->whereNotNull('pending_working_schedule_id')
            ->whereNotNull('pending_schedule_effective_from')
            ->whereDate('pending_schedule_effective_from', '<=', $today->toDateString())
            ->with('pendingWorkingSchedule')
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $schedule = $user->pendingWorkingSchedule;
            if (! $schedule) {
                $user->forceFill([
                    'pending_working_schedule_id' => null,
                    'pending_schedule_effective_from' => null,
                ])->save();

                continue;
            }

            $assignmentService->assign($user, $schedule);
            $user->forceFill([
                'pending_working_schedule_id' => null,
                'pending_schedule_effective_from' => null,
            ])->save();
            $count++;
        }

        $this->info("Applied {$count} pending schedule change(s) as of {$today->toDateString()}.");

        return self::SUCCESS;
    }
}
