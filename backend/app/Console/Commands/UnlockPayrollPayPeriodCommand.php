<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\PayrollPeriodOrphanLockService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Demotes finalized payslips and unlocks payroll_period rows for a company pay window.
 * Same effect as POST /api/admin/payroll/unlock-period (use when API access is inconvenient).
 */
class UnlockPayrollPayPeriodCommand extends Command
{
    protected $signature = 'payroll:unlock-period
        {--company= : Company id (required)}
        {--start= : Pay period start (Y-m-d)}
        {--end= : Pay period end (Y-m-d)}
        {--employees= : Optional comma-separated employee user ids}
        {--no-reset-failed-batches : Do not set payroll_batch_runs (failed) back to draft}
        {--force : Required; performs the update (without it, only prints what would run)}';

    protected $description = 'Force-unlock a pay window: draft finalized payslips + unlock payroll_period rows (recovery after bad data deletes)';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $start = $this->option('start');
        $end = $this->option('end');

        if ($companyId <= 0 || ! $start || ! $end) {
            $this->error('Required: --company=ID --start=Y-m-d --end=Y-m-d');

            return self::FAILURE;
        }

        if (! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Company id {$companyId} not found.");

            return self::FAILURE;
        }

        $employeeIds = null;
        $rawEmployees = $this->option('employees');
        if (is_string($rawEmployees) && $rawEmployees !== '') {
            $employeeIds = array_values(array_filter(array_map('intval', explode(',', $rawEmployees))));
            if ($employeeIds !== []) {
                $foreign = User::query()
                    ->whereIn('id', $employeeIds)
                    ->where('company_id', '!=', $companyId)
                    ->exists();
                if ($foreign) {
                    $this->error('Each --employees id must belong to the given company.');

                    return self::FAILURE;
                }
            }
        }

        $ps = Carbon::parse((string) $start)->startOfDay();
        $pe = Carbon::parse((string) $end)->startOfDay();

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to demote payslips and unlock payroll_period rows.');
            $this->line("  company={$companyId}  {$ps->toDateString()} … {$pe->toDateString()}"
                .($employeeIds !== null ? '  employees='.implode(',', $employeeIds) : '  (all employees in company)'));

            return self::FAILURE;
        }

        $resetFailed = ! $this->option('no-reset-failed-batches');

        $stats = PayrollPeriodOrphanLockService::forceUnlockPeriod(
            $companyId,
            $ps,
            $pe,
            $employeeIds,
            0,
            $resetFailed
        );

        $this->info('Done.');
        $this->line('  payslips demoted to draft: '.$stats['payslips_demoted']);
        $this->line('  payroll_periods unlocked: '.$stats['payroll_periods_unlocked']);
        $this->line('  failed batch runs reset to draft: '.$stats['failed_batch_runs_reset_to_draft']);

        return self::SUCCESS;
    }
}
