<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table): void {
                $table->index(['user_id', 'start_date', 'end_date', 'status'], 'leave_employee_dates_status_idx');
            });
        }

        if (Schema::hasTable('overtimes')) {
            Schema::table('overtimes', function (Blueprint $table): void {
                $table->index(['user_id', 'status', 'date', 'created_at'], 'overtime_employee_status_date_created_idx');
            });
        }

        if (Schema::hasTable('attendance_corrections')) {
            Schema::table('attendance_corrections', function (Blueprint $table): void {
                $table->index(['user_id', 'status', 'date', 'created_at'], 'correction_employee_status_date_created_idx');
            });
        }

        if (Schema::hasTable('attendance_logs')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'type', 'verified_at'], 'attendance_employee_type_verified_idx');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'leave_requests' => ['leave_employee_dates_status_idx'],
            'overtimes' => ['overtime_employee_status_date_created_idx'],
            'attendance_corrections' => ['correction_employee_status_date_created_idx'],
            'attendance_logs' => ['attendance_employee_type_verified_idx'],
        ] as $tableName => $indexes) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }
            });
        }
    }
};
