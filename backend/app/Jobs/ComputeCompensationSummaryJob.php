<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PayrollCalculatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dedicated compensation summary warm-up job.
 *
 * This keeps heavy summary/YTD calculations off request threads and allows
 * profile update jobs to finish quickly while compensation cache is refreshed in background.
 */
class ComputeCompensationSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public int $userId) {}

    public function handle(PayrollCalculatorService $payrollCalculator): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $startedAt = microtime(true);
        $user = User::query()->find($this->userId);
        if (! $user) {
            Log::warning('ComputeCompensationSummaryJob skipped: user not found', ['user_id' => $this->userId]);

            return;
        }

        $payrollCalculator->buildEmployeeCompensationSummary($user, [
            'as_of_date' => now()->toDateString(),
            'proration_factor' => 1,
            'include_deduction_schedule_catalog' => false,
            'cache' => true,
        ]);

        Log::info('ComputeCompensationSummaryJob completed', [
            'user_id' => $this->userId,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
        ]);
    }
}
