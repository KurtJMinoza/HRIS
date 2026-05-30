<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('users', ['company_id', 'deleted_at'], 'users_company_deleted_dashboard_idx');
        $this->addIndex('users', ['department_id', 'deleted_at'], 'users_department_deleted_dashboard_idx');
        $this->addIndex('users', ['status', 'deleted_at'], 'users_status_deleted_dashboard_idx');
        $this->addIndex('users', ['employment_status', 'deleted_at'], 'users_employment_deleted_dashboard_idx');
        $this->addIndex('users', ['created_at'], 'users_created_dashboard_idx');

        $this->addIndex('attendance_logs', ['user_id', 'type', 'verified_at'], 'al_user_type_verified_dashboard_idx');
        $this->addIndex('attendance_logs', ['user_id', 'type', 'created_at'], 'al_user_type_created_dashboard_idx');

        $this->addIndex('leave_requests', ['company_id', 'status', 'created_at'], 'lr_company_status_created_dashboard_idx');
        $this->addIndex('leave_requests', ['user_id', 'status', 'start_date', 'end_date'], 'lr_user_status_dates_dashboard_idx');
        $this->addIndex('leave_requests', ['first_approver_id', 'status', 'created_at'], 'lr_first_approver_status_dashboard_idx');
        $this->addIndex('leave_requests', ['second_approver_id', 'status', 'created_at'], 'lr_second_approver_status_dashboard_idx');

        $this->addIndex('overtimes', ['company_id', 'status', 'date'], 'ot_company_status_date_dashboard_idx');
        $this->addIndex('overtimes', ['user_id', 'status', 'date'], 'ot_user_status_date_dashboard_idx');
        $this->addIndex('overtimes', ['first_approver_id', 'status', 'created_at'], 'ot_first_approver_status_dashboard_idx');
        $this->addIndex('overtimes', ['second_approver_id', 'status', 'created_at'], 'ot_second_approver_status_dashboard_idx');

        $this->addIndex('attendance_corrections', ['user_id', 'pending_approval', 'approved', 'date'], 'ac_user_pending_approved_date_dashboard_idx');
        $this->addIndex('attendance_corrections', ['first_approver_id', 'pending_approval', 'date'], 'ac_first_approver_pending_dashboard_idx');
        $this->addIndex('attendance_corrections', ['second_approver_id', 'pending_approval', 'date'], 'ac_second_approver_pending_dashboard_idx');

        $this->addIndex('payroll_batch_runs', ['company_id', 'status', 'payroll_module', 'payroll_period_id', 'created_at'], 'pbr_company_status_module_period_dashboard_idx');
        $this->addIndex('payroll_employees', ['payroll_batch_run_id', 'company_id', 'payroll_module', 'status', 'user_id'], 'pe_run_company_module_status_user_dashboard_idx');
    }

    public function down(): void
    {
        foreach ([
            ['users', 'users_company_deleted_dashboard_idx'],
            ['users', 'users_department_deleted_dashboard_idx'],
            ['users', 'users_status_deleted_dashboard_idx'],
            ['users', 'users_employment_deleted_dashboard_idx'],
            ['users', 'users_created_dashboard_idx'],
            ['attendance_logs', 'al_user_type_verified_dashboard_idx'],
            ['attendance_logs', 'al_user_type_created_dashboard_idx'],
            ['leave_requests', 'lr_company_status_created_dashboard_idx'],
            ['leave_requests', 'lr_user_status_dates_dashboard_idx'],
            ['leave_requests', 'lr_first_approver_status_dashboard_idx'],
            ['leave_requests', 'lr_second_approver_status_dashboard_idx'],
            ['overtimes', 'ot_company_status_date_dashboard_idx'],
            ['overtimes', 'ot_user_status_date_dashboard_idx'],
            ['overtimes', 'ot_first_approver_status_dashboard_idx'],
            ['overtimes', 'ot_second_approver_status_dashboard_idx'],
            ['attendance_corrections', 'ac_user_pending_approved_date_dashboard_idx'],
            ['attendance_corrections', 'ac_first_approver_pending_dashboard_idx'],
            ['attendance_corrections', 'ac_second_approver_pending_dashboard_idx'],
            ['payroll_batch_runs', 'pbr_company_status_module_period_dashboard_idx'],
            ['payroll_employees', 'pe_run_company_module_status_user_dashboard_idx'],
        ] as [$table, $index]) {
            $this->dropIndexIfExists($table, $index);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, array $columns, string $index): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $index): void {
            $table->index($columns, $index);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index])) > 0;
        } catch (Throwable) {
            return false;
        }
    }
};
