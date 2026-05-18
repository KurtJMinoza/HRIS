<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addPayrollBatchProgressColumns();
        $this->addPayrollHotPathIndexes();
    }

    public function down(): void
    {
        foreach ([
            ['users', 'rpq_users_company_active_idx'],
            ['users', 'rpq_users_branch_active_idx'],
            ['users', 'rpq_users_department_active_idx'],
            ['users', 'rpq_users_employment_active_idx'],
            ['users', 'rpq_users_org_active_idx'],
            ['attendance', 'rpq_att_employee_date_idx'],
            ['attendance', 'rpq_att_employee_status_date_idx'],
            ['attendances', 'rpq_attendances_employee_date_idx'],
            ['attendances', 'rpq_attendances_employee_status_date_idx'],
            ['attendance_logs', 'rpq_att_logs_user_verified_idx'],
            ['employee_pay_components', 'rpq_epc_employee_idx'],
            ['employee_pay_components', 'rpq_epc_employee_component_idx'],
            ['employee_compensation_components', 'rpq_ecc_user_idx'],
            ['employee_compensation_components', 'rpq_ecc_user_component_idx'],
            ['payroll_batch_runs', 'rpq_pbr_company_cycle_idx'],
            ['payroll_batch_runs', 'rpq_pbr_status_idx'],
            ['payroll_batch_runs', 'rpq_pbr_status_company_cycle_idx'],
            ['payrolls', 'rpq_payrolls_batch_idx'],
            ['payrolls', 'rpq_payrolls_employee_idx'],
            ['payrolls', 'rpq_payrolls_batch_employee_idx'],
            ['payroll_periods', 'rpq_periods_user_idx'],
            ['payroll_periods', 'rpq_periods_user_cycle_idx'],
            ['payslips', 'rpq_payslips_period_idx'],
            ['payslips', 'rpq_payslips_user_period_idx'],
            ['payslips', 'rpq_payslips_company_period_idx'],
            ['payslips', 'rpq_payslips_status_period_idx'],
            ['leave_requests', 'rpq_leave_user_start_status_idx'],
            ['leave_requests', 'rpq_leave_user_status_dates_idx'],
            ['leaves', 'rpq_leaves_employee_date_status_idx'],
            ['overtimes', 'rpq_overtime_user_date_status_idx'],
            ['overtime', 'rpq_overtime_employee_date_status_idx'],
            ['deduction_schedule_settings', 'rpq_dss_company_key_idx'],
            ['statutory_contributions', 'rpq_stat_company_code_effective_idx'],
            ['working_schedules', 'rpq_working_schedules_active_idx'],
        ] as [$table, $index]) {
            $this->dropIndexIfExists($table, $index);
        }

        if (Schema::hasTable('payroll_batch_runs')) {
            Schema::table('payroll_batch_runs', function (Blueprint $table) {
                foreach (['failed_employees', 'processed_employees', 'total_employees'] as $column) {
                    if (Schema::hasColumn('payroll_batch_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addPayrollBatchProgressColumns(): void
    {
        if (! Schema::hasTable('payroll_batch_runs')) {
            return;
        }

        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_batch_runs', 'total_employees')) {
                $table->unsignedInteger('total_employees')->default(0)->after('employee_count');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'processed_employees')) {
                $table->unsignedInteger('processed_employees')->default(0)->after('total_employees');
            }
            if (! Schema::hasColumn('payroll_batch_runs', 'failed_employees')) {
                $table->unsignedInteger('failed_employees')->default(0)->after('processed_employees');
            }
        });

        DB::table('payroll_batch_runs')
            ->where(function ($query) {
                $query->whereNull('total_employees')->orWhere('total_employees', 0);
            })
            ->update([
                'total_employees' => DB::raw('employee_count'),
            ]);
    }

    private function addPayrollHotPathIndexes(): void
    {
        $this->addIndexIfMissing('users', ['company_id', 'is_active'], 'rpq_users_company_active_idx');
        $this->addIndexIfMissing('users', ['branch_id', 'is_active'], 'rpq_users_branch_active_idx');
        $this->addIndexIfMissing('users', ['department_id', 'is_active'], 'rpq_users_department_active_idx');
        $this->addIndexIfMissing('users', ['employment_status', 'is_active'], 'rpq_users_employment_active_idx');
        $this->addIndexIfMissing('users', ['company_id', 'branch_id', 'department_id', 'is_active'], 'rpq_users_org_active_idx');

        $this->addIndexIfMissing('attendance', ['employee_id', 'attendance_date'], 'rpq_att_employee_date_idx');
        $this->addIndexIfMissing('attendance', ['employee_id', 'status', 'attendance_date'], 'rpq_att_employee_status_date_idx');
        $this->addIndexIfMissing('attendances', ['employee_id', 'attendance_date'], 'rpq_attendances_employee_date_idx');
        $this->addIndexIfMissing('attendances', ['employee_id', 'status', 'attendance_date'], 'rpq_attendances_employee_status_date_idx');
        $this->addIndexIfMissing('attendance_logs', ['user_id', 'verified_at'], 'rpq_att_logs_user_verified_idx');

        $this->addIndexIfMissing('employee_pay_components', ['employee_id'], 'rpq_epc_employee_idx');
        $this->addIndexIfMissing('employee_pay_components', ['employee_id', 'pay_component_id'], 'rpq_epc_employee_component_idx');
        $this->addIndexIfMissing('employee_compensation_components', ['user_id'], 'rpq_ecc_user_idx');
        $this->addIndexIfMissing('employee_compensation_components', ['user_id', 'pay_component_id'], 'rpq_ecc_user_component_idx');

        $this->addIndexIfMissing('payroll_batch_runs', ['company_id', 'pay_cycle_id'], 'rpq_pbr_company_cycle_idx');
        $this->addIndexIfMissing('payroll_batch_runs', ['status'], 'rpq_pbr_status_idx');
        $this->addIndexIfMissing('payroll_batch_runs', ['status', 'company_id', 'pay_cycle_id'], 'rpq_pbr_status_company_cycle_idx');

        $this->addIndexIfMissing('payrolls', ['payroll_batch_id'], 'rpq_payrolls_batch_idx');
        $this->addIndexIfMissing('payrolls', ['employee_id'], 'rpq_payrolls_employee_idx');
        $this->addIndexIfMissing('payrolls', ['payroll_batch_id', 'employee_id'], 'rpq_payrolls_batch_employee_idx');
        $this->addIndexIfMissing('payroll_periods', ['user_id'], 'rpq_periods_user_idx');
        $this->addIndexIfMissing('payroll_periods', ['user_id', 'pay_cycle_id'], 'rpq_periods_user_cycle_idx');

        $this->addIndexIfMissing('payslips', ['payroll_period_id'], 'rpq_payslips_period_idx');
        $this->addIndexIfMissing('payslips', ['user_id', 'payroll_period_id'], 'rpq_payslips_user_period_idx');
        $this->addIndexIfMissing('payslips', ['company_id', 'pay_period_start', 'pay_period_end'], 'rpq_payslips_company_period_idx');
        $this->addIndexIfMissing('payslips', ['status', 'pay_period_start', 'pay_period_end'], 'rpq_payslips_status_period_idx');

        $this->addIndexIfMissing('leave_requests', ['user_id', 'start_date', 'status'], 'rpq_leave_user_start_status_idx');
        $this->addIndexIfMissing('leave_requests', ['user_id', 'status', 'start_date', 'end_date'], 'rpq_leave_user_status_dates_idx');
        $this->addIndexIfMissing('leaves', ['employee_id', 'leave_date', 'status'], 'rpq_leaves_employee_date_status_idx');

        $this->addIndexIfMissing('overtimes', ['user_id', 'date', 'status'], 'rpq_overtime_user_date_status_idx');
        $this->addIndexIfMissing('overtime', ['employee_id', 'overtime_date', 'status'], 'rpq_overtime_employee_date_status_idx');

        $this->addIndexIfMissing('deduction_schedule_settings', ['company_id', 'deduction_key'], 'rpq_dss_company_key_idx');
        $this->addIndexIfMissing('statutory_contributions', ['company_id', 'code', 'effective_from'], 'rpq_stat_company_code_effective_idx');
        $this->addIndexIfMissing('working_schedules', ['is_active'], 'rpq_working_schedules_active_idx');
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

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexNameExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
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

        return $rows !== [];
    }
};
