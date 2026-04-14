<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_daily_records', 'holiday_name')) {
                $table->string('holiday_name', 255)->nullable()->after('holiday_type');
            }
            if (! Schema::hasColumn('payroll_daily_records', 'conditions')) {
                $table->json('conditions')->nullable()->after('breakdown');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            $table->dropColumn(['holiday_name', 'conditions']);
        });
    }
};
