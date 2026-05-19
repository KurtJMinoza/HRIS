<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting indexes for Reports → detailed() bulk reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payslips')) {
            Schema::table('payslips', function (Blueprint $table) {
                if (! $this->indexExists('payslips', 'payslips_user_pay_period_idx')) {
                    $cols = ['user_id'];
                    if (Schema::hasColumn('payslips', 'payroll_period_id')) {
                        $cols[] = 'payroll_period_id';
                    }
                    if (Schema::hasColumn('payslips', 'period_start')) {
                        $cols[] = 'period_start';
                    }
                    $table->index($cols, 'payslips_user_pay_period_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payslips')) {
            Schema::table('payslips', function (Blueprint $table) {
                $table->dropIndex('payslips_user_pay_period_idx');
            });
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $name]);

            return count($rows) > 0;
        }

        $database = $conn->getDatabaseName();
        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $name]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
