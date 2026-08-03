<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')
            || ! Schema::hasColumn('execom_payroll_settings', 'auto_present_attendance_reports')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->dropColumn('auto_present_attendance_reports');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')
            || Schema::hasColumn('execom_payroll_settings', 'auto_present_attendance_reports')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->boolean('auto_present_attendance_reports')->default(true)->after('allow_holiday_pay');
        });
    }
};
