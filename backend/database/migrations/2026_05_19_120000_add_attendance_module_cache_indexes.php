<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for Admin / Employee attendance hot paths (attendance_logs-based).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table) {
                if (! $this->indexExists('attendance_corrections', 'ac_user_date_status_idx')) {
                    $cols = ['user_id', 'date'];
                    if (Schema::hasColumn('attendance_corrections', 'approved')) {
                        $cols[] = 'approved';
                    }
                    $table->index($cols, 'ac_user_date_status_idx');
                }
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (! $this->indexExists('leave_requests', 'lr_user_start_status_idx')) {
                    $table->index(['user_id', 'start_date', 'status'], 'lr_user_start_status_idx');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'company_id') && ! $this->indexExists('users', 'users_company_active_idx')) {
                    $cols = ['company_id'];
                    if (Schema::hasColumn('users', 'is_active')) {
                        $cols[] = 'is_active';
                    }
                    $table->index($cols, 'users_company_active_idx');
                }
                if (Schema::hasColumn('users', 'branch_id') && ! $this->indexExists('users', 'users_branch_active_idx')) {
                    $cols = ['branch_id'];
                    if (Schema::hasColumn('users', 'is_active')) {
                        $cols[] = 'is_active';
                    }
                    $table->index($cols, 'users_branch_active_idx');
                }
                if (Schema::hasColumn('users', 'department_id') && ! $this->indexExists('users', 'users_department_active_idx')) {
                    $cols = ['department_id'];
                    if (Schema::hasColumn('users', 'is_active')) {
                        $cols[] = 'is_active';
                    }
                    $table->index($cols, 'users_department_active_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table) {
                $table->dropIndex('ac_user_date_status_idx');
            });
        }
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropIndex('lr_user_start_status_idx');
            });
        }
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_company_active_idx');
                $table->dropIndex('users_branch_active_idx');
                $table->dropIndex('users_department_active_idx');
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
