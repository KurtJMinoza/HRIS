<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('overtime_approval_audits')) {
            Schema::table('overtime_approval_audits', function (Blueprint $table): void {
                $table->index(['overtime_id', 'created_at'], 'overtime_audit_request_created_idx');
                $table->index(['actor_id', 'action'], 'overtime_audit_actor_action_idx');
            });
        }

        if (Schema::hasTable('attendance_correction_approvals')) {
            Schema::table('attendance_correction_approvals', function (Blueprint $table): void {
                $table->index(['attendance_correction_id', 'status', 'level'], 'attendance_corr_approval_req_status_level_idx');
                $table->index(['approver_id', 'status'], 'attendance_corr_approval_approver_status_idx');
            });
        }

        if (Schema::hasTable('attendance_correction_audits')) {
            Schema::table('attendance_correction_audits', function (Blueprint $table): void {
                $table->index(['attendance_correction_id', 'created_at'], 'attendance_corr_audit_req_created_idx');
                $table->index(['admin_id', 'action'], 'attendance_corr_audit_admin_action_idx');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'overtime_approval_audits' => [
                'overtime_audit_request_created_idx',
                'overtime_audit_actor_action_idx',
            ],
            'attendance_correction_approvals' => [
                'attendance_corr_approval_req_status_level_idx',
                'attendance_corr_approval_approver_status_idx',
            ],
            'attendance_correction_audits' => [
                'attendance_corr_audit_req_created_idx',
                'attendance_corr_audit_admin_action_idx',
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
