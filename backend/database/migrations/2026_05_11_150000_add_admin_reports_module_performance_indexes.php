<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional indexes for Admin → Reports detailed/summary preloads.
 *
 * Primary tables: attendance_logs, attendance_corrections, leave_requests.
 * (attendance is stored in attendance_logs in this app.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! $this->indexExists('attendance_logs', 'al_verified_at_user_id_idx')) {
                $table->index(['verified_at', 'user_id'], 'al_verified_at_user_id_idx');
            }
        });

        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (! $this->indexExists('attendance_corrections', 'ac_user_date_approved_idx')) {
                $table->index(['user_id', 'date', 'approved'], 'ac_user_date_approved_idx');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (! $this->indexExists('leave_requests', 'lr_user_start_end_idx')) {
                $table->index(['user_id', 'start_date', 'end_date'], 'lr_user_start_end_idx');
            }
        });
    }

    public function down(): void
    {
        if ($this->indexExists('attendance_logs', 'al_verified_at_user_id_idx')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                $table->dropIndex('al_verified_at_user_id_idx');
            });
        }

        if ($this->indexExists('attendance_corrections', 'ac_user_date_approved_idx')) {
            Schema::table('attendance_corrections', function (Blueprint $table) {
                $table->dropIndex('ac_user_date_approved_idx');
            });
        }

        if ($this->indexExists('leave_requests', 'lr_user_start_end_idx')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropIndex('lr_user_start_end_idx');
            });
        }
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
