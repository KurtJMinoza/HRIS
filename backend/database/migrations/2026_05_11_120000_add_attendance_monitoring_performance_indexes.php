<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for Admin → Attendance monitoring bulk reads:
 * - attendance_logs: range scans on verified_at per user (timezone-normalized queries)
 * - leave_requests: approved leaves overlapping a date window for many users
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if ($this->indexExists('attendance_logs', 'attendance_logs_user_id_verified_at_index')) {
                return;
            }
            $table->index(['user_id', 'verified_at'], 'attendance_logs_user_id_verified_at_index');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if ($this->indexExists('leave_requests', 'leave_requests_user_status_dates_index')) {
                return;
            }
            // Supports: where user_id in (...) and status = approved and start/end overlap range
            $table->index(['user_id', 'status', 'start_date', 'end_date'], 'leave_requests_user_status_dates_index');
        });

        Schema::table('overtimes', function (Blueprint $table) {
            if ($this->indexExists('overtimes', 'overtimes_user_date_status_index')) {
                return;
            }
            // Unique (user_id, date) exists; add composite including status for approved-only scans.
            $table->index(['user_id', 'date', 'status'], 'overtimes_user_date_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('attendance_logs_user_id_verified_at_index');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_user_status_dates_index');
        });

        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropIndex('overtimes_user_date_status_index');
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
