<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue/list indexes for pending approver visibility and org-scoped admin lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(['status', 'pending_approval', 'approval_stage'], 'leave_status_pending_stage_idx');
                $table->index(['first_approver_id', 'status', 'pending_approval'], 'leave_first_approver_queue_idx');
                $table->index(['second_approver_id', 'status', 'pending_approval'], 'leave_second_approver_queue_idx');
                $table->index(['company_id', 'status', 'created_at'], 'leave_company_status_created_idx');
            });
        }

        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table): void {
                $table->index(['status', 'pending_approval', 'approval_stage'], 'ot_status_pending_stage_idx');
                $table->index(['first_approver_id', 'status', 'pending_approval'], 'ot_first_approver_queue_idx');
                $table->index(['second_approver_id', 'status', 'pending_approval'], 'ot_second_approver_queue_idx');
                $table->index(['company_id', 'status', 'created_at'], 'ot_company_status_created_idx');
            });
        }

        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                $table->index(['pending_approval', 'approved', 'filed_at'], 'attendance_corr_pending_filed_idx');
                $table->index(['approval_stage', 'pending_approval'], 'attendance_corr_stage_pending_idx');
            });
        }
    }

    public function down(): void
    {
        $drops = [
            'leave_requests' => [
                'leave_status_pending_stage_idx',
                'leave_first_approver_queue_idx',
                'leave_second_approver_queue_idx',
                'leave_company_status_created_idx',
            ],
            'overtimes' => [
                'ot_status_pending_stage_idx',
                'ot_first_approver_queue_idx',
                'ot_second_approver_queue_idx',
                'ot_company_status_created_idx',
            ],
            'attendance_corrections' => [
                'attendance_corr_pending_filed_idx',
                'attendance_corr_stage_pending_idx',
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
