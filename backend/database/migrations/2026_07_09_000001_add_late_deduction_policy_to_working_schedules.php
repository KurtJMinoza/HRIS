<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('working_schedules', function (Blueprint $table) {
            $table->string('late_deduction_policy', 30)
                ->default('BRACKET')
                ->after('grace_period_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('working_schedules', function (Blueprint $table) {
            $table->dropColumn('late_deduction_policy');
        });
    }
};