<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table): void {
            if (! $this->indexExists('payslips', 'payslips_bulk_delivery_idx')) {
                $table->index(
                    ['payroll_batch_run_id', 'payroll_module', 'status', 'period_slot', 'voided_at'],
                    'payslips_bulk_delivery_idx'
                );
            }

            if (! $this->indexExists('payslips', 'payslips_sent_status_idx')) {
                $table->index(['is_sent', 'delivered_at', 'status'], 'payslips_sent_status_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table): void {
            if ($this->indexExists('payslips', 'payslips_sent_status_idx')) {
                $table->dropIndex('payslips_sent_status_idx');
            }
            if ($this->indexExists('payslips', 'payslips_bulk_delivery_idx')) {
                $table->dropIndex('payslips_bulk_delivery_idx');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index])) > 0;
            }

            foreach (Schema::getIndexes($table) as $candidate) {
                if (($candidate['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
