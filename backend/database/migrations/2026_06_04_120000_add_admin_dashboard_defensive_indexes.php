<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('user_admin_activity_logs', ['created_at'], 'uaal_created_dashboard_idx');
        $this->addIndex('user_admin_activity_logs', ['actor_user_id', 'created_at'], 'uaal_actor_created_dashboard_idx');
        $this->addIndex('user_admin_activity_logs', ['subject_user_id', 'created_at'], 'uaal_subject_created_dashboard_idx');

        if (Schema::hasTable('org_approval_records')) {
            $this->addIndex(
                'org_approval_records',
                ['approver_id', 'approval_status', 'module_type'],
                'oar_approver_status_module_dashboard_idx'
            );
        }

        if (Schema::hasTable('attendance_logs') && Schema::hasColumn('attendance_logs', 'company_id')) {
            $this->addIndex('attendance_logs', ['company_id', 'verified_at'], 'al_company_verified_dashboard_idx');
        }
    }

    public function down(): void
    {
        foreach ([
            ['user_admin_activity_logs', 'uaal_created_dashboard_idx'],
            ['user_admin_activity_logs', 'uaal_actor_created_dashboard_idx'],
            ['user_admin_activity_logs', 'uaal_subject_created_dashboard_idx'],
            ['org_approval_records', 'oar_approver_status_module_dashboard_idx'],
            ['attendance_logs', 'al_company_verified_dashboard_idx'],
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
