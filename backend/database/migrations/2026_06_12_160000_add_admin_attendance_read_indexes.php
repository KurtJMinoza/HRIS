<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional indexes for Admin Attendance hot read paths.
 *
 * Note: users is intentionally omitted — many deployments already sit at MySQL's
 * 64-index limit on that table; scoped employee queries use existing indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if ($this->indexExists('attendance_corrections', 'attendance_corrections_user_date_status_index')) {
                return;
            }
            $table->index(['user_id', 'date', 'status'], 'attendance_corrections_user_date_status_index');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if ($this->indexExists('attendance_logs', 'attendance_logs_user_type_verified_at_index')) {
                return;
            }
            $table->index(['user_id', 'type', 'verified_at'], 'attendance_logs_user_type_verified_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if ($this->indexExists('attendance_corrections', 'attendance_corrections_user_date_status_index')) {
                $table->dropIndex('attendance_corrections_user_date_status_index');
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            if ($this->indexExists('attendance_logs', 'attendance_logs_user_type_verified_at_index')) {
                $table->dropIndex('attendance_logs_user_type_verified_at_index');
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $name]);

            return count($rows) > 0;
        }

        $database = $conn->getDatabaseName();
        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $name]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
