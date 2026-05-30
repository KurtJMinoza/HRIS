<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                $table->index(['user_id'], 'correction_speed_employee_idx');
                if (Schema::hasColumn('attendance_corrections', 'company_id')) {
                    $table->index(['company_id'], 'correction_speed_company_idx');
                }
                $table->index(['pending_approval', 'approved', 'rejected_at'], 'correction_speed_status_idx');
                $table->index(['first_approver_id', 'pending_approval'], 'correction_speed_first_approver_idx');
                $table->index(['second_approver_id', 'pending_approval'], 'correction_speed_current_approver_idx');
                $table->index(['date'], 'correction_speed_date_idx');
                $table->index(['created_at'], 'correction_speed_created_idx');
                $table->index(['filed_at'], 'correction_speed_filed_idx');
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'verified_at'], 'attendance_logs_speed_user_date_idx');
            });
        }

        if (Schema::hasTable('org_approval_records')) {
            Schema::table('org_approval_records', function (Blueprint $table): void {
                $table->index(['module_type', 'request_id', 'approver_id', 'approval_status'], 'approval_records_correction_speed_idx');
                $table->index(['module_type', 'approval_status', 'approver_role', 'request_id'], 'approval_records_correction_role_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                foreach ([
                    'correction_speed_employee_idx',
                    'correction_speed_status_idx',
                    'correction_speed_first_approver_idx',
                    'correction_speed_current_approver_idx',
                    'correction_speed_date_idx',
                    'correction_speed_created_idx',
                    'correction_speed_filed_idx',
                ] as $index) {
                    $table->dropIndex($index);
                }
                if (Schema::hasColumn('attendance_corrections', 'company_id')) {
                    $table->dropIndex('correction_speed_company_idx');
                }
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->dropIndex('attendance_logs_speed_user_date_idx');
            });
        }

        if (Schema::hasTable('org_approval_records')) {
            Schema::table('org_approval_records', function (Blueprint $table): void {
                $table->dropIndex('approval_records_correction_speed_idx');
                $table->dropIndex('approval_records_correction_role_idx');
            });
        }
    }
};
