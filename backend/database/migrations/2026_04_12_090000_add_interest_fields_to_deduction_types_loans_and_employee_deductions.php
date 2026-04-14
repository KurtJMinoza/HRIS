<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_deduction_types', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_deduction_types', 'with_interest')) {
                $table->boolean('with_interest')->default(false)->after('pay_component_id');
            }
            if (! Schema::hasColumn('pay_deduction_types', 'interest_rate_percent')) {
                $table->decimal('interest_rate_percent', 8, 4)->nullable()->after('with_interest');
            }
            if (! Schema::hasColumn('pay_deduction_types', 'interest_type')) {
                $table->string('interest_type', 16)->nullable()->after('interest_rate_percent');
            }
        });

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_loan_requests', 'with_interest')) {
                $table->boolean('with_interest')->default(false)->after('term_months');
            }
            if (! Schema::hasColumn('pay_loan_requests', 'interest_rate_percent')) {
                $table->decimal('interest_rate_percent', 8, 4)->nullable()->after('with_interest');
            }
            if (! Schema::hasColumn('pay_loan_requests', 'interest_type')) {
                $table->string('interest_type', 16)->nullable()->after('interest_rate_percent');
            }
            if (! Schema::hasColumn('pay_loan_requests', 'total_repayment_amount')) {
                $table->decimal('total_repayment_amount', 14, 2)->nullable()->after('interest_type');
            }
        });

        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_employee_deductions', 'with_interest')) {
                $table->boolean('with_interest')->default(false)->after('is_amortized');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'interest_type')) {
                $table->string('interest_type', 16)->nullable()->after('interest_rate_annual');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'total_repayment_amount')) {
                $table->decimal('total_repayment_amount', 14, 2)->nullable()->after('total_loan_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            foreach (['total_repayment_amount', 'interest_type', 'with_interest'] as $col) {
                if (Schema::hasColumn('pay_employee_deductions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            foreach (['total_repayment_amount', 'interest_type', 'interest_rate_percent', 'with_interest'] as $col) {
                if (Schema::hasColumn('pay_loan_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('pay_deduction_types', function (Blueprint $table) {
            foreach (['interest_type', 'interest_rate_percent', 'with_interest'] as $col) {
                if (Schema::hasColumn('pay_deduction_types', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
