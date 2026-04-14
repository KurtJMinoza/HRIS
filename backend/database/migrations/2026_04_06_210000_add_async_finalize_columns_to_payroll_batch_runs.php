<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_batch_runs', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('department_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'payroll_period_id')) {
                $table->foreignId('payroll_period_id')->nullable()->after('pay_cycle_id')->constrained('payroll_periods')->nullOnDelete();
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'is_final_pay')) {
                $table->boolean('is_final_pay')->default(false)->after('payroll_period_id');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'password_protect')) {
                $table->boolean('password_protect')->default(false)->after('is_final_pay');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'error_message')) {
                $table->text('error_message')->nullable()->after('employee_count');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'queued_at')) {
                $table->timestamp('queued_at')->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('queued_at');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            foreach (['completed_at', 'started_at', 'queued_at', 'error_message', 'password_protect', 'is_final_pay'] as $column) {
                if (Schema::hasColumn('payroll_batch_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('payroll_batch_runs', 'payroll_period_id')) {
                $table->dropConstrainedForeignId('payroll_period_id');
            }
            if (Schema::hasColumn('payroll_batch_runs', 'employee_id')) {
                $table->dropConstrainedForeignId('employee_id');
            }
        });
    }
};
