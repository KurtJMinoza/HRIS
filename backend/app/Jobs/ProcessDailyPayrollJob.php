<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PayrollDailyRecordSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GAP 5: Daily Payroll Pipeline.
 *
 * Flow:
 * FOR each employee with attendance on target_date:
 *   → TimeSegmentationService (segment hours)
 *   → PayrollRulesEngine (resolve rule)
 *   → PayrollComputationService (compute pay)
 *   → Store in payroll_daily_records
 *
 * Schedule: Run daily at 11:59 PM OR after shift cutoff.
 */
class ProcessDailyPayrollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $targetDate
    ) {}

    public function handle(PayrollDailyRecordSyncService $sync): void
    {
        $dateKey = $this->targetDate;

        $employees = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->with('workingSchedule')
            ->get();

        foreach ($employees as $user) {
            $sync->syncDayForUser($user, $dateKey);
        }
    }
}
