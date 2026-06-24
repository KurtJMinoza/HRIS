<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table): void {
                $this->index($table, 'overtimes', ['user_id', 'status'], 'ot_emp_status_idx');
                $this->index($table, 'overtimes', ['user_id', 'date'], 'ot_emp_date_idx');
                $this->index($table, 'overtimes', ['user_id', 'created_at'], 'ot_emp_created_idx');
                $this->index($table, 'overtimes', ['company_id', 'status'], 'ot_company_status_idx');
                $this->index($table, 'overtimes', ['second_approver_id', 'status'], 'ot_current_approver_status_idx');
            });
        }

        if (Schema::hasTable('overtime_approval_audits')) {
            Schema::table('overtime_approval_audits', function (Blueprint $table): void {
                $this->index($table, 'overtime_approval_audits', ['overtime_id'], 'ot_audits_request_idx');
                $this->index($table, 'overtime_approval_audits', ['actor_id'], 'ot_audits_actor_idx');
                $this->index($table, 'overtime_approval_audits', ['action'], 'ot_audits_action_idx');
                $this->index($table, 'overtime_approval_audits', ['created_at'], 'ot_audits_created_idx');
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $this->index($table, 'attendance_logs', ['user_id', 'verified_at'], 'al_user_verified_ot_idx');
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexes('overtimes', [
            'ot_emp_status_idx',
            'ot_emp_date_idx',
            'ot_emp_created_idx',
            'ot_company_status_idx',
            'ot_current_approver_status_idx',
        ]);

        $this->dropIndexes('overtime_approval_audits', [
            'ot_audits_request_idx',
            'ot_audits_actor_idx',
            'ot_audits_action_idx',
            'ot_audits_created_idx',
        ]);

        $this->dropIndexes('attendance_logs', [
            'al_user_verified_ot_idx',
        ]);
    }

    private function index(Blueprint $table, string $tableName, array $columns, string $name): void
    {
        if (! $this->indexExists($tableName, $name)) {
            $table->index($columns, $name);
        }
    }

    private function dropIndexes(string $tableName, array $indexes): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $indexes): void {
            foreach ($indexes as $index) {
                if ($this->indexExists($tableName, $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $database = DB::getDatabaseName();
            $rows = DB::select(
                'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                [$database, $table, $index],
            );

            return $rows !== [];
        } catch (Throwable) {
            return false;
        }
    }
};
