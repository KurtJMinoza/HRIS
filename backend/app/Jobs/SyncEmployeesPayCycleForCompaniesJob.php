<?php

namespace App\Jobs;

use App\Services\PayCycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a pay cycle is created/updated or company defaults change, align employees.pay_cycle_id
 * with companies.default_pay_cycle_id for all touched companies.
 */
class SyncEmployeesPayCycleForCompaniesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $companyIds
     */
    public function __construct(
        public array $companyIds,
    ) {}

    public function handle(PayCycleService $payCycleService): void
    {
        $payCycleService->applyDefaultPayCyclesToEmployeesForCompanies($this->companyIds);
    }
}
