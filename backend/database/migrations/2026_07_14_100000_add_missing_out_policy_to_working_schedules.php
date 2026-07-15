<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('working_schedules', 'missing_out_grace_period_minutes')) {
                $table->unsignedSmallInteger('missing_out_grace_period_minutes')->nullable()->after('overtime_buffer_minutes');
            }
            if (! Schema::hasColumn('working_schedules', 'missing_out_payroll_impact')) {
                $table->string('missing_out_payroll_impact', 40)->nullable()->after('missing_out_grace_period_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('working_schedules', function (Blueprint $table): void {
            if (Schema::hasColumn('working_schedules', 'missing_out_payroll_impact')) {
                $table->dropColumn('missing_out_payroll_impact');
            }
            if (Schema::hasColumn('working_schedules', 'missing_out_grace_period_minutes')) {
                $table->dropColumn('missing_out_grace_period_minutes');
            }
        });
    }
};
