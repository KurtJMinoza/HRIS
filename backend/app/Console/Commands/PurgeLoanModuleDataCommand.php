<?php

namespace App\Console\Commands;

use App\Models\DeductionType;
use App\Models\EmployeeDeduction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes transactional data for internal loan flows (employee loans-deductions + admin compensation/deductions-loans).
 * Does not delete deduction type / pay component catalogs, and does not touch {@see \App\Models\EmployeeGovernmentLoan}.
 */
class PurgeLoanModuleDataCommand extends Command
{
    protected $signature = 'loans:purge-data {--force : Run without confirmation}';

    protected $description = 'Delete all loan requests, amortization rows, and employee loan deductions (HR loan module data)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'This permanently deletes all loan requests, amortization schedules, and active loan deductions (type=loan). Continue?'
        )) {
            $this->warn('Aborted.');

            return 1;
        }

        $counts = [
            'pay_loan_amortizations' => 0,
            'pay_loan_requests' => 0,
            'pay_employee_deductions_loan' => 0,
        ];

        DB::transaction(function () use (&$counts) {
            if (Schema::hasTable('pay_loan_amortizations')) {
                $counts['pay_loan_amortizations'] = DB::table('pay_loan_amortizations')->count();
                DB::table('pay_loan_amortizations')->delete();
            }

            if (Schema::hasTable('pay_loan_requests')) {
                $counts['pay_loan_requests'] = DB::table('pay_loan_requests')->count();
                if (Schema::hasColumn('pay_loan_requests', 'employee_deduction_id')) {
                    DB::table('pay_loan_requests')->update(['employee_deduction_id' => null]);
                }
                DB::table('pay_loan_requests')->delete();
            }

            if (Schema::hasTable('pay_employee_deductions') && Schema::hasTable('pay_deduction_types')) {
                $counts['pay_employee_deductions_loan'] = EmployeeDeduction::query()
                    ->whereHas('deductionType', fn ($q) => $q->where('type', DeductionType::TYPE_LOAN))
                    ->count();

                EmployeeDeduction::query()
                    ->whereHas('deductionType', fn ($q) => $q->where('type', DeductionType::TYPE_LOAN))
                    ->delete();
            }
        });

        $this->table(
            ['Table / scope', 'Rows removed'],
            [
                ['pay_loan_amortizations', (string) $counts['pay_loan_amortizations']],
                ['pay_loan_requests', (string) $counts['pay_loan_requests']],
                ['pay_employee_deductions (type loan)', (string) $counts['pay_employee_deductions_loan']],
            ]
        );
        $this->info('Loan module transactional data cleared. Deduction types and pay components were not deleted.');

        return 0;
    }
}
