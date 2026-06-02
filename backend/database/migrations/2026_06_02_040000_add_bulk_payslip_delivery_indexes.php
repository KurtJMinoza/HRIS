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
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT COUNT(1) AS aggregate
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index]
        );

        return (int) ($rows[0]->aggregate ?? 0) > 0;
    }
};
