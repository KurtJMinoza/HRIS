<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('org_approval_records', ['module_type', 'request_id', 'approval_status', 'sequence_order'], 'oar_module_request_status_seq_idx');
        $this->addIndex('org_approval_records', ['module_type', 'approver_id', 'approval_status', 'sequence_order'], 'oar_module_approver_status_seq_idx');
        $this->addIndex('org_approval_records', ['module_type', 'approver_role', 'approval_status', 'sequence_order'], 'oar_module_role_status_seq_idx');

        $this->addIndex('leave_requests', ['company_id', 'status', 'created_at'], 'lr_bulk_company_status_created_idx');
        $this->addIndex('leave_requests', ['user_id', 'status', 'start_date', 'end_date'], 'lr_bulk_user_status_dates_idx');
        $this->addIndex('leave_requests', ['pending_approval', 'status', 'created_at'], 'lr_bulk_pending_status_created_idx');

        $this->addIndex('overtimes', ['company_id', 'status', 'date', 'created_at'], 'ot_bulk_company_status_date_idx');
        $this->addIndex('overtimes', ['user_id', 'status', 'date'], 'ot_bulk_user_status_date_idx');
        $this->addIndex('overtimes', ['pending_approval', 'status', 'date'], 'ot_bulk_pending_status_date_idx');

        $this->addIndex('attendance_corrections', ['company_id', 'pending_approval', 'approved', 'date'], 'ac_bulk_company_pending_date_idx');
        $this->addIndex('attendance_corrections', ['user_id', 'pending_approval', 'approved', 'date'], 'ac_bulk_user_pending_date_idx');
        $this->addIndex('attendance_corrections', ['pending_approval', 'approved', 'rejected_at', 'filed_at'], 'ac_bulk_pending_state_filed_idx');

        $this->addIndex('users', ['company_id', 'department_id', 'branch_id'], 'users_bulk_company_dept_branch_idx');
        $this->addIndex('users', ['first_name', 'last_name'], 'users_bulk_first_last_idx');
    }

    public function down(): void
    {
        foreach ([
            ['org_approval_records', 'oar_module_request_status_seq_idx'],
            ['org_approval_records', 'oar_module_approver_status_seq_idx'],
            ['org_approval_records', 'oar_module_role_status_seq_idx'],
            ['leave_requests', 'lr_bulk_company_status_created_idx'],
            ['leave_requests', 'lr_bulk_user_status_dates_idx'],
            ['leave_requests', 'lr_bulk_pending_status_created_idx'],
            ['overtimes', 'ot_bulk_company_status_date_idx'],
            ['overtimes', 'ot_bulk_user_status_date_idx'],
            ['overtimes', 'ot_bulk_pending_status_date_idx'],
            ['attendance_corrections', 'ac_bulk_company_pending_date_idx'],
            ['attendance_corrections', 'ac_bulk_user_pending_date_idx'],
            ['attendance_corrections', 'ac_bulk_pending_state_filed_idx'],
            ['users', 'users_bulk_company_dept_branch_idx'],
            ['users', 'users_bulk_first_last_idx'],
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
