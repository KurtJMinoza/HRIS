<?php

use App\Jobs\ProcessDailyPayrollJob;
use App\Jobs\ProcessEmployeeStatusTransitionsJob;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Services\DeductionApplicationService;
use App\Services\LeaveCreditService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sms:test {phone : Philippine number e.g. +639123456789 or 09123456789}', function (string $phone) {
    $url = config('services.sms.url');
    $key = config('services.sms.api_key');
    if (empty($url) || empty($key)) {
        $this->error('SMS not configured. Set SMS_API_URL and SMS_API_KEY in .env');

        return 1;
    }
    $normalized = SmsService::normalizePhone($phone);
    if ($normalized === null) {
        $this->error('Invalid Philippine number. Use +639XXXXXXXXX or 09XXXXXXXXX.');

        return 1;
    }
    $this->info("Sending test SMS to {$normalized}...");
    $service = app(SmsService::class);
    $result = $service->sendWithResult($normalized, 'HR Test: SMS is working.');
    $this->table(
        ['Key', 'Value'],
        [
            ['Success', $result['success'] ? 'yes' : 'no'],
            ['HTTP status', (string) ($result['http_status'] ?? 'N/A')],
            ['Response body', $result['response_body'] ?? 'N/A'],
            ['Error', $result['error_message'] ?? '—'],
        ]
    );

    return $result['success'] ? 0 : 1;
})->purpose('Send a test SMS and print API response (debug)');

// GAP 5: Daily payroll – run at 11:59 PM (processes yesterday after shift cutoff)
Schedule::call(function () {
    $targetDate = Carbon::yesterday(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    ProcessDailyPayrollJob::dispatchSync($targetDate);
})->dailyAt('23:59')->timezone(config('attendance.timezone', 'Asia/Manila'));

// Employee status transitions – run daily at 1:00 AM
Schedule::call(function () {
    $today = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    ProcessEmployeeStatusTransitionsJob::dispatchSync($today);
})->dailyAt('01:00')->timezone(config('attendance.timezone', 'Asia/Manila'));

// Leave credits: annual January 1 recharge (safe to run daily; only users still on a prior year are updated)
Schedule::call(function () {
    app(LeaveCreditService::class)->rechargeAllUsersDueForNewYear(null);
})->dailyAt('00:05')->timezone(config('attendance.timezone', 'Asia/Manila'));

// Apply employee working schedules when an approved change reaches its effective date
Schedule::call(function () {
    Artisan::call('schedule:apply-pending');
})->dailyAt('00:10')->timezone(config('attendance.timezone', 'Asia/Manila'));

Artisan::command('payroll:process {date? : Date to process (Y-m-d). Default: yesterday}', function (?string $date = null) {
    $dateKey = $date ?? Carbon::yesterday(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    $this->info("Processing daily payroll for {$dateKey}...");
    ProcessDailyPayrollJob::dispatchSync($dateKey);
    $this->info('Done.');
})->purpose('Process daily payroll for a date (manual trigger / backfill)');

// Amortized loans: apply paid status when a payroll period row already exists for the installment cut-off (does not run payroll).
Artisan::command('loans:process-due-installments {date? : Reference date (Y-m-d); installments with due_date on or before this date may be settled} {--user= : Optional employee user id}', function (?string $date = null) {
    $dateKey = $date ?? Carbon::now(config('attendance.timezone', 'Asia/Manila'))->toDateString();
    $userId = $this->option('user') !== null && $this->option('user') !== '' ? (int) $this->option('user') : null;
    $n = app(DeductionApplicationService::class)->autoProcessDueInstallments(Carbon::parse($dateKey), $userId);
    $this->info("Settled {$n} installment(s) (amortized, payroll period must exist for cut-off).");

    return 0;
})->purpose('Catch up loan installments marked paid when payroll was saved for the matching cut-off');

Artisan::command('leave:reset-annual-credits {--force : Run without confirmation}', function () {
    if (! $this->option('force')) {
        $this->warn('Recharges leave credits for users whose last reset is before the current calendar year (January 1 policy).');
        if (! $this->confirm('Continue?', false)) {
            return 0;
        }
    }
    $n = app(LeaveCreditService::class)->rechargeAllUsersDueForNewYear(null);
    $this->info("Recharged {$n} user(s).");

    return 0;
})->purpose('Apply annual leave credit recharge for the new calendar year (writes audit rows)');

Artisan::command('payroll:cleanup-orphaned-assignments', function () {
    if (! Schema::hasTable('employee_compensation_components')) {
        $this->warn('employee_compensation_components table is missing.');

        return 0;
    }

    $deactivated = 0;

    if (Schema::hasTable('pay_components') && Schema::hasColumn('pay_components', 'deleted_at')) {
        $trashedIds = PayComponent::onlyTrashed()->pluck('id');
        if ($trashedIds->isNotEmpty()) {
            $deactivated += EmployeeCompensationComponent::query()
                ->whereIn('pay_component_id', $trashedIds->all())
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }
    }

    $missingParentIds = DB::table('employee_compensation_components as e')
        ->leftJoin('pay_components as p', 'e.pay_component_id', '=', 'p.id')
        ->whereNotNull('e.pay_component_id')
        ->whereNull('p.id')
        ->pluck('e.id');

    if ($missingParentIds->isNotEmpty()) {
        $deactivated += EmployeeCompensationComponent::query()
            ->whereIn('id', $missingParentIds->all())
            ->update(['is_active' => false]);
    }

    $orphanNonCustom = EmployeeCompensationComponent::query()
        ->whereNull('pay_component_id')
        ->where('is_custom', false)
        ->update(['is_active' => false]);

    $this->info("Deactivated {$deactivated} assignment row(s) tied to removed or soft-deleted pay components.");
    $this->info("Deactivated {$orphanNonCustom} non-custom row(s) with no pay_component_id (legacy orphans).");

    return 0;
})->purpose('Deactivate orphaned employee_compensation_components (safe to run anytime)');
