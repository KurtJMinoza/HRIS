<?php

namespace App\Jobs;

use App\Services\PayrollDailyLogProjectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshPayrollDailyLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        private readonly string $fromDate,
        private readonly string $toDate
    ) {}

    public function handle(PayrollDailyLogProjectorService $projector): void
    {
        try {
            $count = $projector->rebuildForRange($this->fromDate, $this->toDate);
            Log::info('Payroll daily logs refresh completed', [
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
                'rows_upserted' => $count,
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error('Payroll daily logs refresh failed', [
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
