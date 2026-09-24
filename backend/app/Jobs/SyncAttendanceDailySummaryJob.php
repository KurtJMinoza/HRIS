<?php

namespace App\Jobs;

use App\Services\AttendanceSummarySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Upserts one employee+date row into attendance_daily_summaries after a punch or correction.
 */
class SyncAttendanceDailySummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public readonly int $employeeId,
        public readonly string $date,
    ) {
        $this->onQueue('attendance-corrections');
    }

    public function handle(AttendanceSummarySyncService $syncService): void
    {
        $syncService->syncEmployeeDate($this->employeeId, $this->date);
    }
}
