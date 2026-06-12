<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('org_approval_records')) {
            Schema::table('org_approval_records', function (Blueprint $table): void {
                $table->index(
                    ['module_type', 'request_id', 'approval_status', 'sequence_order'],
                    'approval_bulk_current_step_idx',
                );
                $table->index(
                    ['module_type', 'approver_id', 'approval_status', 'sequence_order'],
                    'approval_bulk_approver_step_idx',
                );
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(
                    ['company_id', 'user_id', 'status', 'second_approver_id', 'created_at'],
                    'leave_bulk_scope_status_idx',
                );
            });
        }

        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table): void {
                $table->index(
                    ['company_id', 'user_id', 'status', 'second_approver_id', 'date'],
                    'overtime_bulk_scope_status_idx',
                );
            });
        }

        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                $columns = ['user_id', 'status', 'second_approver_id', 'date'];
                if (Schema::hasColumn('attendance_corrections', 'company_id')) {
                    array_unshift($columns, 'company_id');
                }
                $table->index($columns, 'correction_bulk_scope_status_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'org_approval_records' => ['approval_bulk_current_step_idx', 'approval_bulk_approver_step_idx'],
            'leave_requests' => ['leave_bulk_scope_status_idx'],
            'overtimes' => ['overtime_bulk_scope_status_idx'],
            'attendance_corrections' => ['correction_bulk_scope_status_idx'],
        ] as $tableName => $indexes) {
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
