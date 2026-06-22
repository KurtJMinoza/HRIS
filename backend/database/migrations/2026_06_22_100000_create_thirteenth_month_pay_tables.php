<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thirteenth_month_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('company_scope_type', 16)->default('all');
            $table->json('company_ids')->nullable();
            $table->string('coverage_type', 32);
            $table->date('coverage_start_date');
            $table->date('coverage_end_date');
            $table->string('basis_type', 16)->default('basic');
            $table->string('status', 16)->default('draft');
            $table->string('computation_status', 16)->default('pending');
            $table->unsignedInteger('total_employees')->default(0);
            $table->unsignedInteger('processed_employees')->default(0);
            $table->decimal('total_basis_amount', 16, 2)->default(0);
            $table->decimal('total_payable_amount', 16, 2)->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'coverage_start_date', 'coverage_end_date'], 'tm_config_status_coverage_idx');
        });

        Schema::create('thirteenth_month_employee_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_id')->constrained('thirteenth_month_configurations')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->decimal('basis_amount', 16, 2)->default(0);
            $table->decimal('computed_13th_month', 16, 2)->default(0);
            $table->decimal('previous_paid', 16, 2)->default(0);
            $table->decimal('payable_13th_month', 16, 2)->default(0);
            $table->string('eligibility_status', 32)->default('eligible');
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['configuration_id', 'employee_id'], 'tm_result_config_employee_unique');
            $table->index(['configuration_id', 'eligibility_status'], 'tm_result_config_eligibility_idx');
        });

        Schema::create('thirteenth_month_payslip_inclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('configuration_id')->constrained('thirteenth_month_configurations')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_batch_runs')->cascadeOnDelete();
            $table->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
            $table->string('component_code', 32)->default('13TH_MONTH_PAY');
            $table->decimal('amount', 16, 2);
            $table->timestamps();
            $table->unique(['employee_id', 'configuration_id', 'payroll_run_id', 'component_code'], 'tm_payslip_inclusion_unique');
        });

        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            $table->boolean('include_thirteenth_month')->default(false)->after('password_protect');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_batch_runs', fn (Blueprint $table) => $table->dropColumn('include_thirteenth_month'));
        Schema::dropIfExists('thirteenth_month_payslip_inclusions');
        Schema::dropIfExists('thirteenth_month_employee_results');
        Schema::dropIfExists('thirteenth_month_configurations');
    }
};
