<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('payroll_batch_runs', ['company_id'], 'ppl_pbr_company_idx');
        $this->addIndexIfMissing('payroll_batch_runs', ['status'], 'ppl_pbr_status_idx');
        $this->addIndexIfMissing('payroll_batch_runs', ['payroll_period_id'], 'ppl_pbr_period_idx');
        $this->addIndexIfMissing('payroll_batch_runs', ['company_id', 'status', 'created_at'], 'ppl_pbr_company_status_created_idx');

        $this->addIndexIfMissing('payslips', ['payroll_batch_run_id'], 'ppl_payslips_run_idx');
        $this->addIndexIfMissing('payslips', ['company_id'], 'ppl_payslips_company_idx');
        $this->addIndexIfMissing('payslips', ['user_id'], 'ppl_payslips_user_idx');
        $this->addIndexIfMissing('payslips', ['status'], 'ppl_payslips_status_idx');
        $this->addIndexIfMissing('payslips', ['voided_at'], 'ppl_payslips_voided_idx');
        $this->addIndexIfMissing('payslips', ['payroll_batch_run_id', 'user_id', 'status', 'voided_at'], 'ppl_payslips_run_user_status_idx');

        $this->addIndexIfMissing('payroll_breakdowns', ['payroll_period_id'], 'ppl_breakdowns_period_idx');
        $this->addIndexIfMissing('payroll_breakdowns', ['status'], 'ppl_breakdowns_status_idx');
        $this->addIndexIfMissing('payroll_breakdowns', ['date'], 'ppl_breakdowns_date_idx');

        $this->addIndexIfMissing('users', ['company_id'], 'ppl_users_company_idx');
        $this->addIndexIfMissing('users', ['department_id'], 'ppl_users_department_idx');
        $this->addIndexIfMissing('users', ['status'], 'ppl_users_status_idx');

        $this->addIndexIfMissing('employee_organization_assignments', ['employee_id'], 'ppl_eoa_employee_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['company_id'], 'ppl_eoa_company_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['include_in_payroll'], 'ppl_eoa_include_payroll_idx');
        $this->addIndexIfMissing('employee_organization_assignments', ['is_active'], 'ppl_eoa_active_idx');

        $this->addIndexIfMissing('attendance', ['employee_id'], 'ppl_att_employee_idx');
        $this->addIndexIfMissing('attendance', ['date'], 'ppl_att_date_idx');
        $this->addIndexIfMissing('attendance', ['employee_id', 'date'], 'ppl_att_employee_date_idx');

        $this->addIndexIfMissing('overtime_requests', ['employee_id'], 'ppl_ot_employee_idx');
        $this->addIndexIfMissing('overtime_requests', ['date'], 'ppl_ot_date_idx');
        $this->addIndexIfMissing('overtime_requests', ['status'], 'ppl_ot_status_idx');
        $this->addIndexIfMissing('overtime_requests', ['employee_id', 'date', 'status'], 'ppl_ot_employee_date_status_idx');

        $this->addIndexIfMissing('leave_requests', ['employee_id'], 'ppl_leave_employee_idx');
        $this->addIndexIfMissing('leave_requests', ['start_date'], 'ppl_leave_start_idx');
        $this->addIndexIfMissing('leave_requests', ['end_date'], 'ppl_leave_end_idx');
        $this->addIndexIfMissing('leave_requests', ['status'], 'ppl_leave_status_idx');
        $this->addIndexIfMissing('leave_requests', ['employee_id', 'start_date', 'end_date', 'status'], 'ppl_leave_employee_dates_status_idx');
    }

    public function down(): void
    {
        foreach ([
            ['payroll_batch_runs', 'ppl_pbr_company_idx'],
            ['payroll_batch_runs', 'ppl_pbr_status_idx'],
            ['payroll_batch_runs', 'ppl_pbr_period_idx'],
            ['payroll_batch_runs', 'ppl_pbr_company_status_created_idx'],
            ['payslips', 'ppl_payslips_run_idx'],
            ['payslips', 'ppl_payslips_company_idx'],
            ['payslips', 'ppl_payslips_user_idx'],
            ['payslips', 'ppl_payslips_status_idx'],
            ['payslips', 'ppl_payslips_voided_idx'],
            ['payslips', 'ppl_payslips_run_user_status_idx'],
            ['payroll_breakdowns', 'ppl_breakdowns_period_idx'],
            ['payroll_breakdowns', 'ppl_breakdowns_status_idx'],
            ['payroll_breakdowns', 'ppl_breakdowns_date_idx'],
            ['users', 'ppl_users_company_idx'],
            ['users', 'ppl_users_department_idx'],
            ['users', 'ppl_users_status_idx'],
            ['employee_organization_assignments', 'ppl_eoa_employee_idx'],
            ['employee_organization_assignments', 'ppl_eoa_company_idx'],
            ['employee_organization_assignments', 'ppl_eoa_include_payroll_idx'],
            ['employee_organization_assignments', 'ppl_eoa_active_idx'],
            ['attendance', 'ppl_att_employee_idx'],
            ['attendance', 'ppl_att_date_idx'],
            ['attendance', 'ppl_att_employee_date_idx'],
            ['overtime_requests', 'ppl_ot_employee_idx'],
            ['overtime_requests', 'ppl_ot_date_idx'],
            ['overtime_requests', 'ppl_ot_status_idx'],
            ['overtime_requests', 'ppl_ot_employee_date_status_idx'],
            ['leave_requests', 'ppl_leave_employee_idx'],
            ['leave_requests', 'ppl_leave_start_idx'],
            ['leave_requests', 'ppl_leave_end_idx'],
            ['leave_requests', 'ppl_leave_status_idx'],
            ['leave_requests', 'ppl_leave_employee_dates_status_idx'],
        ] as [$table, $index]) {
            $this->dropIndexIfExists($table, $index);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexNameExists($tableName, $indexName) || $this->indexColumnsExist($tableName, $columns)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexNameExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    private function indexNameExists(string $tableName, string $indexName): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND tbl_name = ? AND name = ?', ['index', $tableName, $indexName]);

            return count($rows) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexColumnsExist(string $tableName, array $columns): bool
    {
        $conn = Schema::getConnection();
        $wanted = implode(',', $columns);

        if ($conn->getDriverName() === 'sqlite') {
            $indexes = $conn->select('PRAGMA index_list('.$tableName.')');
            foreach ($indexes as $idx) {
                $name = (string) ($idx->name ?? '');
                if ($name === '') {
                    continue;
                }
                $info = $conn->select('PRAGMA index_info('.$name.')');
                $existing = implode(',', array_map(static fn ($row) => (string) ($row->name ?? ''), $info));
                if ($existing === $wanted) {
                    return true;
                }
            }

            return false;
        }

        $rows = $conn->select(
            'SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ",") AS cols
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name <> "PRIMARY"
             GROUP BY index_name
             HAVING cols = ?
             LIMIT 1',
            [$conn->getDatabaseName(), $tableName, $wanted]
        );

        return count($rows) > 0;
    }
};
