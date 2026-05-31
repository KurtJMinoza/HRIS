<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // attendance_logs: employee dashboard queries by employee/user + attendance date range.
        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table) {
                if (! $this->indexExists('attendance_logs', 'idx_attendance_logs_user_verified_type')) {
                    $columns = ['user_id'];
                    if (Schema::hasColumn('attendance_logs', 'verified_at')) {
                        $columns[] = 'verified_at';
                    }
                    if (Schema::hasColumn('attendance_logs', 'status')) {
                        $columns[] = 'status';
                    } elseif (Schema::hasColumn('attendance_logs', 'type')) {
                        $columns[] = 'type';
                    }
                    $table->index($columns, 'idx_attendance_logs_user_verified_type');
                }
            });
        }

        // attendance_corrections: employee queries by user_id + date
        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table) {
                if (! $this->indexExists('attendance_corrections', 'idx_attendance_corrections_user_status_date')) {
                    $columns = ['user_id'];
                    if (Schema::hasColumn('attendance_corrections', 'status')) {
                        $columns[] = 'status';
                    } elseif (Schema::hasColumn('attendance_corrections', 'approved')) {
                        $columns[] = 'approved';
                    }
                    $columns[] = Schema::hasColumn('attendance_corrections', 'correction_date') ? 'correction_date' : 'date';
                    $table->index($columns, 'idx_attendance_corrections_user_status_date');
                }
            });
        }

        // leave_requests: employee queries by user_id + status + date range
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (! $this->indexExists('leave_requests', 'idx_leave_requests_user_status_dates')) {
                    $table->index(['user_id', 'status', 'start_date', 'end_date'], 'idx_leave_requests_user_status_dates');
                }
            });
        }

        // overtimes: employee queries by user_id + date
        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table) {
                if (! $this->indexExists('overtimes', 'idx_overtimes_user_date_status')) {
                    $table->index(['user_id', 'date', 'status'], 'idx_overtimes_user_date_status');
                }
            });
        }

        // employee_schedules: user schedule lookup
        if (Schema::hasTable('employee_schedules')) {
            Schema::table('employee_schedules', function (Blueprint $table) {
                if (! $this->indexExists('employee_schedules', 'idx_employee_schedules_employee_effective')) {
                    $employeeColumn = Schema::hasColumn('employee_schedules', 'employee_id') ? 'employee_id' : 'user_id';
                    $columns = [$employeeColumn];
                    if (Schema::hasColumn('employee_schedules', 'effective_from')) {
                        $columns[] = 'effective_from';
                    } elseif (Schema::hasColumn('employee_schedules', 'date')) {
                        $columns[] = 'date';
                    }
                    if (Schema::hasColumn('employee_schedules', 'effective_to')) {
                        $columns[] = 'effective_to';
                    }
                    $table->index($columns, 'idx_employee_schedules_employee_effective');
                }
            });
        }

        // schedule_days: schedule configuration lookup
        if (Schema::hasTable('schedule_days')) {
            Schema::table('schedule_days', function (Blueprint $table) {
                if (! $this->indexExists('schedule_days', 'idx_schedule_days_schedule_day_rest')) {
                    $columns = ['schedule_id', 'day_of_week'];
                    if (Schema::hasColumn('schedule_days', 'is_rest_day')) {
                        $columns[] = 'is_rest_day';
                    }
                    $table->index($columns, 'idx_schedule_days_schedule_day_rest');
                }
            });
        }

        // holidays: date-range queries for employee-visible holidays
        if (Schema::hasTable('holidays')) {
            Schema::table('holidays', function (Blueprint $table) {
                if (! $this->indexExists('holidays', 'idx_holidays_date_company_scope')) {
                    $columns = ['date'];
                    if (Schema::hasColumn('holidays', 'company_id')) {
                        $columns[] = 'company_id';
                    }
                    $columns[] = Schema::hasColumn('holidays', 'scope_type') ? 'scope_type' : 'scope';
                    if (Schema::hasColumn('holidays', 'status')) {
                        $columns[] = 'status';
                    }
                    $table->index($columns, 'idx_holidays_date_company_scope');
                }
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexIfExists('attendance_logs', 'idx_attendance_logs_user_verified_type');
        $this->dropIndexIfExists('attendance_corrections', 'idx_attendance_corrections_user_status_date');
        $this->dropIndexIfExists('leave_requests', 'idx_leave_requests_user_status_dates');
        $this->dropIndexIfExists('overtimes', 'idx_overtimes_user_date_status');
        if (Schema::hasTable('employee_schedules')) {
            $this->dropIndexIfExists('employee_schedules', 'idx_employee_schedules_employee_effective');
        }
        if (Schema::hasTable('schedule_days')) {
            $this->dropIndexIfExists('schedule_days', 'idx_schedule_days_schedule_day_rest');
        }
        $this->dropIndexIfExists('holidays', 'idx_holidays_date_company_scope');
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$index])) > 0;
            }

            foreach (Schema::getIndexes($table) as $candidate) {
                if (($candidate['name'] ?? null) === $index) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
};
