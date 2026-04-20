<?php

namespace App\Listeners;

use App\Events\ScheduleUpdated;
use App\Services\PayrollDailyRecordSyncService;
use App\Support\EmployeeProfileCache;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * After schedule template or assignment changes, refresh {@see \App\Models\PayrollDailyRecord}
 * for affected employees across a rolling window (skips dates locked by finalized payslips).
 */
class RecalculatePayrollDailyRecords implements ShouldQueue
{
    use InteractsWithQueue;

    public int $timeout = 1200;

    public function __construct(
        private readonly PayrollDailyRecordSyncService $syncService,
    ) {}

    public function handle(ScheduleUpdated $event): void
    {
        if ($event->affectedUserIds === []) {
            return;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $now = Carbon::now($tz);

        // Rolling window: previous month start → end of next month (covers semi-monthly pay cycles).
        $from = $now->copy()->startOfMonth()->subMonth();
        $to = $now->copy()->endOfMonth()->addMonthNoOverflow();

        $this->syncService->recalculateForUsersInRange(
            $event->affectedUserIds,
            $from,
            $to
        );

        // Salary-tab profile payloads (self-service + admin snapshot) are cached for performance.
        // Schedule updates must invalidate those caches immediately so working days/month and
        // schedule-derived daily/hourly rates refresh on the next fetch.
        foreach ($event->affectedUserIds as $uid) {
            EmployeeProfileCache::invalidate((int) $uid);
        }
    }
}
