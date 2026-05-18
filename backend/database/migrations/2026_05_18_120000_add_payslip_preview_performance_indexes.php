<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payslip preview/totals queries filter by company, period dates, pay_cycle, and aggregate gross/net/deductions.
        $this->addIndex('payslips', ['company_id'], 'pp_payslips_company_idx');
        $this->addIndex('payslips', ['pay_period_start', 'pay_period_end'], 'pp_payslips_period_dates_idx');
        $this->addIndex('payslips', ['pay_cycle_id'], 'pp_payslips_pay_cycle_idx');
        $this->addIndex('payslips', ['user_id', 'pay_period_start', 'pay_period_end'], 'pp_payslips_user_period_dates_idx');

        // PayrollBatchRun lookups by batch_key in preview.
        $this->addIndex('payroll_batch_runs', ['batch_key'], 'pp_pbr_batch_key_idx');

        // Users name/employee_code/department search (LIKE queries).
        $this->addIndex('users', ['company_id', 'is_active'], 'pp_users_company_active_idx');
    }

    public function down(): void
    {
        foreach ([
            ['payslips', 'pp_payslips_company_idx'],
            ['payslips', 'pp_payslips_period_dates_idx'],
            ['payslips', 'pp_payslips_pay_cycle_idx'],
            ['payslips', 'pp_payslips_user_period_dates_idx'],
            ['payroll_batch_runs', 'pp_pbr_batch_key_idx'],
            ['users', 'pp_users_company_active_idx'],
        ] as [$table, $index]) {
            $this->dropIndex($table, $index);
        }
    }

    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }
        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $indexName]);

            return count($rows) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
