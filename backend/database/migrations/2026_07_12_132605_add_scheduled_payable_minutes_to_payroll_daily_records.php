<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_daily_records', 'scheduled_payable_minutes')) {
                $table->integer('scheduled_payable_minutes')->nullable()->after('worked_minutes')->comment('Scheduled payable minutes for this date from assigned shift');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_daily_records', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_daily_records', 'scheduled_payable_minutes')) {
                $table->dropColumn('scheduled_payable_minutes');
            }
        });
    }
};
