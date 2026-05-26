<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('payroll_batch_run_id')->nullable()->constrained('payroll_batch_runs')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->string('status', 32)->default('draft');
            $table->decimal('gross_pay', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('net_pay', 16, 2)->default(0);
            $table->timestamps();

            $table->unique(['payslip_id', 'status'], 'payroll_employees_payslip_status_unique');
            $table->index(['payroll_batch_run_id', 'status'], 'payroll_employees_batch_status_idx');
            $table->index(['user_id', 'pay_period_start', 'pay_period_end'], 'payroll_employees_user_period_idx');
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_employee_id')->constrained('payroll_employees')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('line_key', 120)->nullable();
            $table->string('component_code', 120)->nullable();
            $table->string('component_name', 255)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('type', 32);
            $table->string('category', 64)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('units', 255)->nullable();
            $table->string('schedule', 64)->nullable();
            $table->string('calculation_standard', 64)->nullable();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['payslip_id', 'status', 'type'], 'payroll_lines_payslip_status_type_idx');
            $table->index(['payroll_employee_id', 'status'], 'payroll_lines_employee_status_idx');
            $table->index(['component_code', 'status'], 'payroll_lines_component_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_employees');
    }
};
