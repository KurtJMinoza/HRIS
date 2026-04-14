<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->float('overtime_hours')->nullable()->after('verified_at');
            $table->float('night_hours')->nullable()->after('overtime_hours');
            $table->string('premium_type', 50)->nullable()->after('night_hours'); // ordinary, rest_day, special_holiday, regular_holiday, etc.
            $table->json('calculated_pay_factor')->nullable()->after('premium_type'); // first_8_multiplier, ot_multiplier, nd_applied_multiplier
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['overtime_hours', 'night_hours', 'premium_type', 'calculated_pay_factor']);
        });
    }
};
