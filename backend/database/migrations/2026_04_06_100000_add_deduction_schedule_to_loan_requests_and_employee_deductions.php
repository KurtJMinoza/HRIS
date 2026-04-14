<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee-chosen semi-monthly loan withholding (15th / end-of-month / split).
 * {@see LoanAmortizationService::generateSchedule} and {@see DeductionScheduleService::summarizeForPayrollComputation}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pay_loan_requests') && ! Schema::hasColumn('pay_loan_requests', 'deduction_schedule')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                if (Schema::hasColumn('pay_loan_requests', 'term_months')) {
                    $table->string('deduction_schedule', 16)->nullable()->after('term_months');
                } else {
                    $table->string('deduction_schedule', 16)->nullable();
                }
            });
        }

        if (Schema::hasTable('pay_employee_deductions') && ! Schema::hasColumn('pay_employee_deductions', 'deduction_schedule')) {
            Schema::table('pay_employee_deductions', function (Blueprint $table) {
                $table->string('deduction_schedule', 16)->nullable()->after('loan_request_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pay_loan_requests') && Schema::hasColumn('pay_loan_requests', 'deduction_schedule')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                $table->dropColumn('deduction_schedule');
            });
        }

        if (Schema::hasTable('pay_employee_deductions') && Schema::hasColumn('pay_employee_deductions', 'deduction_schedule')) {
            Schema::table('pay_employee_deductions', function (Blueprint $table) {
                $table->dropColumn('deduction_schedule');
            });
        }
    }
};
