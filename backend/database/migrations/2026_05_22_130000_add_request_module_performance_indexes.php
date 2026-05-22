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
                $table->index(['status', 'created_at'], 'leave_status_created_idx');
                $table->index(['user_id', 'status', 'created_at'], 'leave_employee_status_created_idx');
                $table->index(['start_date', 'end_date'], 'leave_date_range_idx');
                $table->index(['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'], 'leave_org_scope_idx');
                $table->index(['type', 'status'], 'leave_type_status_idx');
                $table->index(['first_approver_id', 'second_approver_id', 'status'], 'leave_approver_status_idx');
            });
        }

        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table): void {
                $table->index(['status', 'date', 'id'], 'overtime_status_date_id_idx');
                $table->index(['user_id', 'status', 'date'], 'overtime_employee_status_date_idx');
                $table->index(['ot_type', 'status'], 'overtime_type_status_idx');
                $table->index(['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'], 'overtime_org_scope_idx');
                $table->index(['first_approver_id', 'second_approver_id', 'status'], 'overtime_approver_status_idx');
                $table->index(['created_at'], 'overtime_created_at_idx');
            });
        }

        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                $table->index(['pending_approval', 'approved', 'rejected_at', 'filed_at'], 'attendance_corr_status_filed_idx');
                $table->index(['user_id', 'date'], 'attendance_corr_employee_date_idx');
                $table->index(['issue_kind', 'date'], 'attendance_corr_issue_date_idx');
                $table->index(['first_approver_id', 'second_approver_id', 'pending_approval'], 'attendance_corr_approver_pending_idx');
                $table->index(['created_at'], 'attendance_corr_created_at_idx');
            });
        }

        if (Schema::hasTable('org_approval_records')) {
            Schema::table('org_approval_records', function (Blueprint $table): void {
                $table->index(['request_id', 'module_type', 'approval_status', 'sequence_order'], 'org_approval_request_module_status_seq_idx');
                $table->index(['approver_id', 'module_type', 'approval_status'], 'org_approval_approver_module_status_idx');
            });
        }

        if (Schema::hasTable('employee_organization_assignments')) {
            Schema::table('employee_organization_assignments', function (Blueprint $table): void {
                $table->index(['employee_id', 'section_unit_id', 'department_id', 'assignment_type', 'is_active'], 'employee_org_assign_hot_path_idx');
                $table->index(['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id', 'is_active'], 'employee_org_assign_scope_idx');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'leave_requests' => [
                'leave_status_created_idx',
                'leave_employee_status_created_idx',
                'leave_date_range_idx',
                'leave_org_scope_idx',
                'leave_type_status_idx',
                'leave_approver_status_idx',
            ],
            'overtimes' => [
                'overtime_status_date_id_idx',
                'overtime_employee_status_date_idx',
                'overtime_type_status_idx',
                'overtime_org_scope_idx',
                'overtime_approver_status_idx',
                'overtime_created_at_idx',
            ],
            'attendance_corrections' => [
                'attendance_corr_status_filed_idx',
                'attendance_corr_employee_date_idx',
                'attendance_corr_issue_date_idx',
                'attendance_corr_approver_pending_idx',
                'attendance_corr_created_at_idx',
            ],
            'org_approval_records' => [
                'org_approval_request_module_status_seq_idx',
                'org_approval_approver_module_status_idx',
            ],
            'employee_organization_assignments' => [
                'employee_org_assign_hot_path_idx',
                'employee_org_assign_scope_idx',
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
