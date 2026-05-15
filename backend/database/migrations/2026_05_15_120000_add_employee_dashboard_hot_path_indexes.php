<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('attendances', ['employee_id', 'attendance_date'], 'att_emp_date_idx');
        $this->addIndex('attendances', ['employee_id', 'status', 'attendance_date'], 'att_emp_status_date_idx');
        $this->addIndex('attendance', ['employee_id', 'attendance_date'], 'attendance_emp_date_idx');
        $this->addIndex('attendance', ['employee_id', 'status', 'attendance_date'], 'attendance_emp_status_date_idx');

        $this->addIndex('attendance_logs', ['user_id', 'verified_at'], 'ed_al_user_verified_idx');
        $this->addIndex('schedule_requests', ['user_id', 'filed_at'], 'ed_sr_user_filed_idx');
        $this->addIndex('leave_requests', ['user_id', 'status', 'start_date', 'end_date'], 'ed_lr_user_status_dates_idx');
        $this->addIndex('overtimes', ['user_id', 'status', 'date'], 'ed_ot_user_status_date_idx');
        $this->addIndex('notifications', ['user_id', 'read_at', 'created_at'], 'ed_notifications_user_read_created_idx');
    }

    public function down(): void
    {
        $this->dropIndex('notifications', 'ed_notifications_user_read_created_idx');
        $this->dropIndex('overtimes', 'ed_ot_user_status_date_idx');
        $this->dropIndex('leave_requests', 'ed_lr_user_status_dates_idx');
        $this->dropIndex('schedule_requests', 'ed_sr_user_filed_idx');
        $this->dropIndex('attendance_logs', 'ed_al_user_verified_idx');
        $this->dropIndex('attendance', 'attendance_emp_status_date_idx');
        $this->dropIndex('attendance', 'attendance_emp_date_idx');
        $this->dropIndex('attendances', 'att_emp_status_date_idx');
        $this->dropIndex('attendances', 'att_emp_date_idx');
    }

    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || $this->indexExists($tableName, $indexName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndex(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! $this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $conn = Schema::getConnection();
        if ($conn->getDriverName() === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $indexName]);

            return count($rows) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $tableName, $indexName]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
