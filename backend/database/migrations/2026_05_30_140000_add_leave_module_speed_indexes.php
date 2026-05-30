<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(['company_id'], 'leave_speed_company_idx');
                $table->index(['department_id'], 'leave_speed_department_idx');
                $table->index(['user_id'], 'leave_speed_employee_idx');
                $table->index(['type'], 'leave_speed_type_idx');
                $table->index(['status'], 'leave_speed_status_idx');
                $table->index(['status', 'user_id', 'created_at'], 'leave_speed_status_employee_created_idx');
                $table->index(['status', 'created_at', 'id'], 'leave_speed_status_created_id_idx');
                $table->index(['status', 'end_date', 'start_date'], 'leave_speed_status_dates_idx');
                $table->index(['first_approver_id', 'status'], 'leave_speed_first_approver_idx');
                $table->index(['second_approver_id', 'status'], 'leave_speed_current_approver_idx');
                if (Schema::hasColumn('leave_requests', 'employee_id')) {
                    $table->index(['employee_id'], 'leave_speed_real_employee_idx');
                }
                if (Schema::hasColumn('leave_requests', 'leave_type_id')) {
                    $table->index(['leave_type_id'], 'leave_speed_leave_type_id_idx');
                }
                if (Schema::hasColumn('leave_requests', 'current_approver_id')) {
                    $table->index(['current_approver_id'], 'leave_speed_current_approver_id_idx');
                }
                $table->index(['created_at'], 'leave_speed_created_idx');
                $table->index(['start_date'], 'leave_speed_start_idx');
                $table->index(['end_date'], 'leave_speed_end_idx');
            });
        }

        if (Schema::hasTable('org_approval_records')) {
            Schema::table('org_approval_records', function (Blueprint $table): void {
                $table->index(['request_id', 'module_type'], 'approval_speed_request_idx');
                $table->index(['approver_id', 'module_type'], 'approval_speed_approver_idx');
                $table->index(['approval_status', 'module_type'], 'approval_speed_status_idx');
                $table->index(['request_id', 'approver_id', 'approval_status'], 'approval_speed_request_approver_status_idx');
                $table->index(['module_type', 'approval_status', 'approver_role', 'request_id'], 'approval_speed_module_status_role_request_idx');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index(['company_id'], 'users_speed_company_idx');
                $table->index(['department_id'], 'users_speed_department_idx');
                $table->index(['last_name', 'first_name'], 'users_speed_name_idx');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'leave_requests' => [
                'leave_speed_company_idx',
                'leave_speed_department_idx',
                'leave_speed_employee_idx',
                'leave_speed_type_idx',
                'leave_speed_status_idx',
                'leave_speed_status_employee_created_idx',
                'leave_speed_status_created_id_idx',
                'leave_speed_status_dates_idx',
                'leave_speed_first_approver_idx',
                'leave_speed_current_approver_idx',
                'leave_speed_created_idx',
                'leave_speed_start_idx',
                'leave_speed_end_idx',
            ],
            'org_approval_records' => [
                'approval_speed_request_idx',
                'approval_speed_approver_idx',
                'approval_speed_status_idx',
                'approval_speed_request_approver_status_idx',
                'approval_speed_module_status_role_request_idx',
            ],
            'users' => [
                'users_speed_company_idx',
                'users_speed_department_idx',
                'users_speed_name_idx',
            ],
        ];

        foreach ($drops as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }
            });
        }
    }
};
