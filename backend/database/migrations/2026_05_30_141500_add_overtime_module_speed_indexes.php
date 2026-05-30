<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtimes')) {
            return;
        }

        Schema::table('overtimes', function (Blueprint $table): void {
            $table->index(['company_id'], 'overtime_speed_company_idx');
            $table->index(['department_id'], 'overtime_speed_department_idx');
            $table->index(['user_id'], 'overtime_speed_employee_idx');
            $table->index(['status'], 'overtime_speed_status_idx');
            $table->index(['first_approver_id', 'status'], 'overtime_speed_first_approver_idx');
            $table->index(['second_approver_id', 'status'], 'overtime_speed_current_approver_idx');
            $table->index(['date'], 'overtime_speed_date_idx');
            $table->index(['created_at'], 'overtime_speed_created_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('overtimes')) {
            return;
        }

        Schema::table('overtimes', function (Blueprint $table): void {
            foreach ([
                'overtime_speed_company_idx',
                'overtime_speed_department_idx',
                'overtime_speed_employee_idx',
                'overtime_speed_status_idx',
                'overtime_speed_first_approver_idx',
                'overtime_speed_current_approver_idx',
                'overtime_speed_date_idx',
                'overtime_speed_created_idx',
            ] as $index) {
                $table->dropIndex($index);
            }
        });
    }
};
