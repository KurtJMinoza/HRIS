<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LeaveCreditService;
use App\Support\EmployeeProfileCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Post-save heavy recomputation for admin employee profile updates.
 *
 * HTTP must not run this synchronously: payroll summary/deduction/YTD/leave recomputation can be expensive.
 * Dispatched from {@see \App\Http\Controllers\Admin\EmployeeController::update} after the basic row save.
 *
 *   php artisan queue:work database --queue=default --timeout=0
 */
class UpdateEmployeeProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public int $userId,
        public bool $leaveCreditRelevantFieldsTouched = false,
        public bool $employmentFieldsTouched = false,
    ) {}

    public function handle(
        LeaveCreditService $leaveCreditService,
    ): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $user = User::query()->find($this->userId);
        if (! $user) {
            Log::warning('UpdateEmployeeProfileJob skipped: user not found', ['user_id' => $this->userId]);

            return;
        }

        Log::info('UpdateEmployeeProfileJob started', [
            'user_id' => $this->userId,
            'leave_credit_relevant' => $this->leaveCreditRelevantFieldsTouched,
            'employment_fields_touched' => $this->employmentFieldsTouched,
        ]);

        EmployeeProfileCache::invalidate($this->userId);
        $leaveCreditService->forgetSummaryCacheForUser($this->userId);

        if ($this->leaveCreditRelevantFieldsTouched) {
            $leaveCreditService->ensureAnnualRechargeForUser($user);
            if ($this->employmentFieldsTouched) {
                $leaveCreditService->grantAnnualAllocationOnRegularizationIfEligible($user);
            }
        }

        ComputeCompensationSummaryJob::dispatch($this->userId)->onQueue('default');

        Log::info('UpdateEmployeeProfileJob completed', ['user_id' => $this->userId]);
    }
}
