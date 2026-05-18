<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // App schema uses payroll_periods/users where the product language says payrolls/employees.
        $this->addIndex('payroll_periods', ['user_id', 'pay_cycle_id'], 'pg_payroll_user_cycle_idx');
        $this->addIndex('payroll_periods', ['status', 'pay_cycle_id'], 'pg_payroll_status_cycle_idx');
        $this->addIndex('payroll_periods', ['pay_date'], 'pg_payroll_pay_date_idx');
        $this->addIndex('payroll_batch_runs', ['company_id', 'pay_cycle_id'], 'pg_pbr_company_cycle_idx');

        $this->addIndex('payslips', ['user_id', 'payroll_period_id'], 'pg_payslips_user_period_idx');
        $this->addIndex('payslips', ['payroll_period_id'], 'pg_payslips_period_idx');
        $this->addUnique('payslips', ['user_id', 'payroll_period_id'], 'pg_payslips_user_period_unique');

        $this->addIndex('users', ['company_id', 'department_id', 'branch_id'], 'pg_users_company_dept_branch_idx');
        $this->addIndex('users', ['employment_status'], 'pg_users_employment_status_idx');
        $this->addIndex('users', ['is_active'], 'pg_users_is_active_idx');

        $this->addIndex('attendances', ['employee_id', 'attendance_date'], 'pg_att_emp_date_idx');
        $this->addIndex('attendances', ['employee_id', 'status', 'attendance_date'], 'pg_att_emp_status_date_idx');
        $this->addIndex('attendance', ['employee_id', 'attendance_date'], 'pg_attendance_emp_date_idx');
        $this->addIndex('attendance', ['employee_id', 'status', 'attendance_date'], 'pg_attendance_emp_status_date_idx');
        $this->addIndex('attendance_logs', ['user_id', 'verified_at'], 'pg_al_user_verified_idx');

        $this->addIndex('pay_components', ['id'], 'pg_pay_components_id_idx');
        $this->addIndex('employee_compensation_components', ['user_id'], 'pg_ecc_user_idx');
        $this->addIndex('employee_compensation_components', ['pay_component_id'], 'pg_ecc_component_idx');
        $this->addIndex('employee_compensation_components', ['user_id', 'pay_component_id'], 'pg_ecc_user_component_idx');

        $this->addIndex('pay_employee_deductions', ['user_id'], 'pg_ped_user_idx');
        $this->addIndex('pay_employee_deductions', ['user_id', 'payroll_period_id'], 'pg_ped_user_period_idx');
        $this->addIndex('employee_statutory_contributions', ['employee_id', 'payroll_period_id'], 'pg_esc_employee_period_idx');
        $this->addIndex('employee_statutory_contributions', ['user_id', 'payroll_period_id'], 'pg_esc_user_period_idx');

        $this->addIndex('leave_requests', ['user_id', 'start_date', 'status'], 'pg_lr_user_start_status_idx');
        $this->addIndex('leave_requests', ['user_id', 'status', 'start_date', 'end_date'], 'pg_lr_user_status_dates_idx');
        $this->addIndex('overtimes', ['user_id', 'date', 'status'], 'pg_ot_user_date_status_idx');
    }

    public function down(): void
    {
        foreach ([
            ['payroll_periods', 'pg_payroll_user_cycle_idx'],
            ['payroll_periods', 'pg_payroll_status_cycle_idx'],
            ['payroll_periods', 'pg_payroll_pay_date_idx'],
            ['payroll_batch_runs', 'pg_pbr_company_cycle_idx'],
            ['payslips', 'pg_payslips_user_period_unique'],
            ['payslips', 'pg_payslips_period_idx'],
            ['payslips', 'pg_payslips_user_period_idx'],
            ['users', 'pg_users_company_dept_branch_idx'],
            ['users', 'pg_users_employment_status_idx'],
            ['users', 'pg_users_is_active_idx'],
            ['attendances', 'pg_att_emp_date_idx'],
            ['attendances', 'pg_att_emp_status_date_idx'],
            ['attendance', 'pg_attendance_emp_date_idx'],
            ['attendance', 'pg_attendance_emp_status_date_idx'],
            ['attendance_logs', 'pg_al_user_verified_idx'],
            ['pay_components', 'pg_pay_components_id_idx'],
            ['employee_compensation_components', 'pg_ecc_user_idx'],
            ['employee_compensation_components', 'pg_ecc_component_idx'],
            ['employee_compensation_components', 'pg_ecc_user_component_idx'],
            ['pay_employee_deductions', 'pg_ped_user_idx'],
            ['pay_employee_deductions', 'pg_ped_user_period_idx'],
            ['employee_statutory_contributions', 'pg_esc_employee_period_idx'],
            ['employee_statutory_contributions', 'pg_esc_user_period_idx'],
            ['leave_requests', 'pg_lr_user_start_status_idx'],
            ['leave_requests', 'pg_lr_user_status_dates_idx'],
            ['overtimes', 'pg_ot_user_date_status_idx'],
        ] as [$table, $index]) {
            $this->dropIndex($table, $index);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! $this->canAdd($tableName, $columns, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function addUnique(string $tableName, array $columns, string $indexName): void
    {
        if (! $this->canAdd($tableName, $columns, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->unique($columns, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function canAdd(string $tableName, array $columns, string $indexName): bool
    {
        if (! Schema::hasTable($tableName) || $this->indexExists($tableName, $indexName)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return false;
            }
        }

        return true;
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
