<?php

namespace App\Console\Commands;

use App\Services\PremiumPayCalculatorService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecomputePremiumCommand extends Command
{
    protected $signature = 'attendance:recompute-premium
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--days=7 : Number of days to process when from/to not set (default: last 7)}';

    protected $description = 'Recompute premium pay values (OT, ND, multipliers) for attendance clock-out logs';

    public function handle(PremiumPayCalculatorService $calculator): int
    {
        $fromStr = $this->option('from');
        $toStr = $this->option('to');

        if ($fromStr && $toStr) {
            $from = Carbon::parse($fromStr)->startOfDay();
            $to = Carbon::parse($toStr)->endOfDay();
        } elseif ($fromStr) {
            $from = Carbon::parse($fromStr)->startOfDay();
            $to = $from->copy()->endOfDay();
        } elseif ($toStr) {
            $to = Carbon::parse($toStr)->endOfDay();
            $from = $to->copy()->startOfDay();
        } else {
            $days = (int) $this->option('days');
            $to = Carbon::now()->endOfDay();
            $from = Carbon::now()->subDays($days)->startOfDay();
        }

        $this->info("Recomputing premium for {$from->toDateString()} to {$to->toDateString()}...");

        $count = $calculator->recomputeForRange($from, $to);

        $this->info("Processed {$count} clock-out logs.");

        return self::SUCCESS;
    }
}
