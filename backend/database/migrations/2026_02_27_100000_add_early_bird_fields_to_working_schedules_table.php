<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedules', function (Blueprint $table) {
            $table->unsignedInteger('early_timein_minutes')->default(60)->after('grace_period_minutes');
            $table->unsignedInteger('late_allowance_minutes')->nullable()->after('early_timein_minutes');
            $table->unsignedInteger('early_timeout_minutes')->nullable()->after('late_allowance_minutes');
            $table->unsignedInteger('overtime_buffer_minutes')->default(15)->after('early_timeout_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('working_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'early_timein_minutes',
                'late_allowance_minutes',
                'early_timeout_minutes',
                'overtime_buffer_minutes',
            ]);
        });
    }
};
