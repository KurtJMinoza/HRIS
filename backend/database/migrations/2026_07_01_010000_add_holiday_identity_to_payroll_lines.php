<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('holiday_id')->nullable()->after('source_id');
            $table->unique(
                ['payroll_employee_id', 'status', 'holiday_id', 'component_code'],
                'payroll_lines_holiday_component_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->dropUnique('payroll_lines_holiday_component_unique');
            $table->dropColumn('holiday_id');
        });
    }
};
