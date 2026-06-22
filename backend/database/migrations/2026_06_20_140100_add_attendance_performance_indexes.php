<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! $this->indexExists('attendance_logs', 'atl_user_verified_idx')) {
                $table->index(['user_id', 'verified_at'], 'atl_user_verified_idx');
            }
        });

        Schema::table('overtimes', function (Blueprint $table) {
            if (! $this->indexExists('overtimes', 'ot_user_date_status_idx')) {
                $table->index(['user_id', 'date', 'status'], 'ot_user_date_status_idx');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (! $this->indexExists('leave_requests', 'lr_user_dates_status_idx')) {
                $table->index(['user_id', 'start_date', 'end_date', 'status'], 'lr_user_dates_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if ($this->indexExists('attendance_logs', 'atl_user_verified_idx')) {
                $table->dropIndex('atl_user_verified_idx');
            }
        });

        Schema::table('overtimes', function (Blueprint $table) {
            if ($this->indexExists('overtimes', 'ot_user_date_status_idx')) {
                $table->dropIndex('ot_user_date_status_idx');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if ($this->indexExists('leave_requests', 'lr_user_dates_status_idx')) {
                $table->dropIndex('lr_user_dates_status_idx');
            }
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $conn = Schema::getConnection();
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            return count($conn->select('SELECT name FROM sqlite_master WHERE type = ? AND name = ?', ['index', $name])) > 0;
        }

        $rows = $conn->select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$conn->getDatabaseName(), $table, $name]
        );

        return isset($rows[0]) && (int) ($rows[0]->c ?? 0) > 0;
    }
};
