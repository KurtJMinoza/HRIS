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
 * Warms {@see PayrollCalculatorService::buildEmployeeCompensationSummary} in the background so the
 * next HR profile / salary tab load is fast (cached) instead of recomputing during HTTP.
 *
 * Dispatched with {@see UpdateEmployeeProfileJob} after admin employee salary-related saves.
 * Runs on the default Redis queue:
 *   php artisan queue:work redis --queue=default --timeout=120 --sleep=1 --tries=2
 */
class ComputeEmployeeCompensationJob implements ShouldQueue
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

        $user = User::query()->find($this->userId);
        if (! $user) {
            Log::warning('ComputeEmployeeCompensationJob skipped: user not found', ['user_id' => $this->userId]);

            return;
        }

        Log::info('ComputeEmployeeCompensationJob started', ['user_id' => $this->userId]);

        $payrollCalculator->buildEmployeeCompensationSummary($user, [
            'as_of_date' => now()->toDateString(),
            'proration_factor' => 1,
            'include_deduction_schedule_catalog' => false,
            'cache' => true,
        ]);

        Log::info('ComputeEmployeeCompensationJob completed', ['user_id' => $this->userId]);
    }
}
