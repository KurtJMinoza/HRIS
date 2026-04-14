<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEmployeeStatusTransitionsJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class EvaluateEmployeeStatusCommand extends Command
{
    protected $signature = 'employee:evaluate-status {date? : Date to evaluate (Y-m-d). Default: today}';

    protected $description = 'Evaluate and process employee status transitions (regularization)';

    public function handle(): int
    {
        $dateInput = $this->argument('date');
        $tz = config('attendance.timezone', 'Asia/Manila');

        $date = $dateInput
            ? Carbon::parse($dateInput, $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        $this->info("Evaluating employee status transitions for {$date}...");

        ProcessEmployeeStatusTransitionsJob::dispatchSync($date);

        $this->info('Done.');

        return 0;
    }
}
