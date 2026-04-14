<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_breakdowns', function (Blueprint $table) {
            $table->index(['date', 'payroll_period_id'], 'pb_date_period_idx');
            $table->index(['payroll_period_id', 'date'], 'pb_period_date_idx');
        });

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->index(['user_id', 'id'], 'pp_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropIndex('pp_user_id_idx');
        });

        Schema::table('payroll_breakdowns', function (Blueprint $table) {
            $table->dropIndex('pb_period_date_idx');
            $table->dropIndex('pb_date_period_idx');
        });
    }
};
