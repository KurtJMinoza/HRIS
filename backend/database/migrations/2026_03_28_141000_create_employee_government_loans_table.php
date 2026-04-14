<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SSS and Pag-IBIG loan amortizations deducted from payroll (Salary Loan, Calamity, Housing, MPL, etc.).
     * Amounts are stored monthly; integration with payslip netting is done in payroll computation.
     */
    public function up(): void
    {
        Schema::create('employee_government_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agency', 16); // SSS | PAGIBIG
            $table->string('loan_kind', 64); // salary_loan, calamity, housing, mpl, short_term, etc.
            $table->string('reference_no')->nullable();
            $table->decimal('monthly_amortization', 14, 2)->default(0);
            $table->decimal('outstanding_balance', 16, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'agency', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_government_loans');
    }
};
