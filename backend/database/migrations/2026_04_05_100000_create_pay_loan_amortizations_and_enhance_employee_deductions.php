<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_employee_deductions', 'total_loan_amount')) {
                $table->decimal('total_loan_amount', 14, 2)->nullable()->after('remaining_balance');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'is_amortized')) {
                $table->boolean('is_amortized')->default(false)->after('total_loan_amount');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'interest_rate_annual')) {
                $table->decimal('interest_rate_annual', 8, 4)->nullable()->after('is_amortized');
            }
            if (! Schema::hasColumn('pay_employee_deductions', 'next_due_date')) {
                $table->date('next_due_date')->nullable()->after('interest_rate_annual');
            }
        });

        Schema::create('pay_loan_amortizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_deduction_id')->constrained('pay_employee_deductions')->cascadeOnDelete();
            $table->foreignId('loan_request_id')->nullable()->constrained('pay_loan_requests')->nullOnDelete();
            $table->unsignedInteger('installment_number')->default(1);
            $table->date('due_date');
            $table->string('period_label', 128)->nullable();
            $table->decimal('principal', 14, 2)->default(0);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('total_installment', 14, 2)->default(0);
            $table->string('status', 24)->default('pending'); // pending, paid, skipped
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payroll_period_id')->nullable()->constrained('payroll_periods')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_deduction_id', 'status']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_loan_amortizations');

        Schema::table('pay_employee_deductions', function (Blueprint $table) {
            foreach (['next_due_date', 'interest_rate_annual', 'is_amortized', 'total_loan_amount'] as $col) {
                if (Schema::hasColumn('pay_employee_deductions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
