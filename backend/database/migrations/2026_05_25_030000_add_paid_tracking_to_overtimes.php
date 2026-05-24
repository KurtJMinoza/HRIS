<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            if (! Schema::hasColumn('overtimes', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('locked_at');
            }
            if (! Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
                $table->foreignId('paid_payroll_run_id')
                    ->nullable()
                    ->after('voided_at')
                    ->constrained('payroll_batch_runs')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('overtimes', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('paid_payroll_run_id');
            }
            if (! Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
                return;
            }
            $table->index(['user_id', 'date', 'status', 'paid_payroll_run_id'], 'overtimes_payroll_paid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            if (Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
                $table->dropForeign(['paid_payroll_run_id']);
                $table->dropIndex('overtimes_payroll_paid_idx');
                $table->dropColumn(['paid_at', 'paid_payroll_run_id']);
            }
            if (Schema::hasColumn('overtimes', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
        });
    }
};
