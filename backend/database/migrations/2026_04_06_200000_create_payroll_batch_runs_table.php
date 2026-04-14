<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit record when an admin finalizes a payroll batch (scope + pay period).
 * Prevents duplicate finalization for the same cut-off via {@see PayrollBatchRun::batch_key}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_batch_runs', function (Blueprint $table) {
            $table->id();
            $table->string('batch_key', 80)->unique();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->foreignId('pay_cycle_id')->nullable()->constrained('pay_cycles')->nullOnDelete();
            $table->date('reference_date')->nullable();
            $table->string('status', 32)->default('finalized');
            $table->decimal('total_gross', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('total_net', 16, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'pay_period_start', 'pay_period_end'], 'payroll_batch_runs_co_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_batch_runs');
    }
};
