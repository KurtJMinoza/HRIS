<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addLeaveRequestsIndexes();
        $this->addAttendanceLogsIndexes();
        $this->addPayslipsIndexes();
        $this->addPayrollBatchRunsIndexes();
        $this->addAttendanceCorrectionsIndexes();
        $this->addOvertimesIndexes();
    }

    public function down(): void
    {
        $this->dropIndexIfExists('leave_requests', 'lr_user_status_overlap_idx');
        $this->dropIndexIfExists('leave_requests', 'lr_status_overlap_user_idx');
        $this->dropIndexIfExists('attendance_logs', 'al_type_created_user_idx');
        $this->dropIndexIfExists('payslips', 'ps_company_period_range_idx');
        $this->dropIndexIfExists('payroll_batch_runs', 'pbr_company_period_status_idx');
        $this->dropIndexIfExists('attendance_corrections', 'ac_date_approved_user_idx');
        $this->dropIndexIfExists('overtimes', 'ot_status_date_user_idx');
    }

    private function addLeaveRequestsIndexes(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }
        Schema::table('leave_requests', function (Blueprint $table) {
            if (
                Schema::hasColumn('leave_requests', 'user_id') &&
                Schema::hasColumn('leave_requests', 'status') &&
                Schema::hasColumn('leave_requests', 'start_date') &&
                Schema::hasColumn('leave_requests', 'end_date') &&
                ! $this->indexExists('leave_requests', 'lr_user_status_overlap_idx')
            ) {
                $table->index(['user_id', 'status', 'start_date', 'end_date'], 'lr_user_status_overlap_idx');
            }
            if (
                Schema::hasColumn('leave_requests', 'status') &&
                Schema::hasColumn('leave_requests', 'start_date') &&
                Schema::hasColumn('leave_requests', 'end_date') &&
                Schema::hasColumn('leave_requests', 'user_id') &&
                ! $this->indexExists('leave_requests', 'lr_status_overlap_user_idx')
            ) {
                $table->index(['status', 'start_date', 'end_date', 'user_id'], 'lr_status_overlap_user_idx');
            }
        });
    }

    private function addAttendanceLogsIndexes(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (
                Schema::hasColumn('attendance_logs', 'type') &&
                Schema::hasColumn('attendance_logs', 'created_at') &&
                Schema::hasColumn('attendance_logs', 'user_id') &&
                ! $this->indexExists('attendance_logs', 'al_type_created_user_idx')
            ) {
                $table->index(['type', 'created_at', 'user_id'], 'al_type_created_user_idx');
            }
        });
    }

    private function addPayslipsIndexes(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }
        Schema::table('payslips', function (Blueprint $table) {
            if (
                Schema::hasColumn('payslips', 'company_id') &&
                Schema::hasColumn('payslips', 'pay_period_start') &&
                Schema::hasColumn('payslips', 'pay_period_end') &&
                ! $this->indexExists('payslips', 'ps_company_period_range_idx')
            ) {
                $table->index(['company_id', 'pay_period_start', 'pay_period_end'], 'ps_company_period_range_idx');
            }
        });
    }

    private function addPayrollBatchRunsIndexes(): void
    {
        if (! Schema::hasTable('payroll_batch_runs')) {
            return;
        }
        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            if (
                Schema::hasColumn('payroll_batch_runs', 'company_id') &&
                Schema::hasColumn('payroll_batch_runs', 'pay_period_start') &&
                Schema::hasColumn('payroll_batch_runs', 'pay_period_end') &&
                Schema::hasColumn('payroll_batch_runs', 'status') &&
                ! $this->indexExists('payroll_batch_runs', 'pbr_company_period_status_idx')
            ) {
                $table->index(['company_id', 'pay_period_start', 'pay_period_end', 'status'], 'pbr_company_period_status_idx');
            }
        });
    }

    private function addAttendanceCorrectionsIndexes(): void
    {
        if (! Schema::hasTable('attendance_corrections')) {
            return;
        }
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (
                Schema::hasColumn('attendance_corrections', 'date') &&
                Schema::hasColumn('attendance_corrections', 'approved') &&
                Schema::hasColumn('attendance_corrections', 'user_id') &&
                ! $this->indexExists('attendance_corrections', 'ac_date_approved_user_idx')
            ) {
                $table->index(['date', 'approved', 'user_id'], 'ac_date_approved_user_idx');
            }
        });
    }

    private function addOvertimesIndexes(): void
    {
        if (! Schema::hasTable('overtimes')) {
            return;
        }
        Schema::table('overtimes', function (Blueprint $table) {
            if (
                Schema::hasColumn('overtimes', 'status') &&
                Schema::hasColumn('overtimes', 'date') &&
                Schema::hasColumn('overtimes', 'user_id') &&
                ! $this->indexExists('overtimes', 'ot_status_date_user_idx')
            ) {
                $table->index(['status', 'date', 'user_id'], 'ot_status_date_user_idx');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $rows !== [];
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $indexName)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }
};
