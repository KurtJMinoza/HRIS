<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payslip register: one row per employee per pay period after HR generates PDF.
 * Integrates with {@see \App\Services\PayrollComputationService} snapshots and stored PDFs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->nullable()->constrained('payroll_periods')->nullOnDelete();
            $table->foreignId('pay_cycle_id')->nullable()->constrained('pay_cycles')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->date('pay_date')->nullable();
            $table->string('cycle_label')->nullable();
            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->decimal('ytd_gross', 14, 2)->nullable();
            $table->decimal('ytd_deductions', 14, 2)->nullable();
            $table->decimal('ytd_tax', 14, 2)->nullable();
            $table->decimal('taxable_total_this_period', 14, 2)->nullable();
            $table->decimal('non_taxable_total_this_period', 14, 2)->nullable();
            $table->boolean('is_final_pay')->default(false);
            $table->json('snapshot')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('status', 32)->default('generated');
            $table->timestamp('emailed_at')->nullable();
            $table->boolean('pdf_password_protected')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'pay_period_start', 'pay_period_end'], 'payslips_user_period_unique');
            $table->index(['company_id', 'pay_period_end']);
            $table->index(['user_id', 'pay_period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
