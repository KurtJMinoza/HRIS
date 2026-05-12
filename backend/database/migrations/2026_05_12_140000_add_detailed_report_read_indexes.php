<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional covering indexes for Reports → detailed(): attendance_corrections and leave overlap scans.
 *
 * Run EXPLAIN on:
 * - SELECT ... FROM attendance_corrections WHERE user_id IN (...) AND date BETWEEN ...
 * - SELECT ... FROM leave_requests WHERE user_id IN (...) AND start_date <= ? AND end_date >= ?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if ($this->indexExists('attendance_corrections', 'ac_user_id_date_idx')) {
                return;
            }
            $table->index(['user_id', 'date'], 'ac_user_id_date_idx');
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if ($this->indexExists('leave_requests', 'leave_requests_user_start_end_idx')) {
                return;
            }
            $table->index(['user_id', 'start_date', 'end_date'], 'leave_requests_user_start_end_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_user_start_end_idx');
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            $table->dropIndex('ac_user_id_date_idx');
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
