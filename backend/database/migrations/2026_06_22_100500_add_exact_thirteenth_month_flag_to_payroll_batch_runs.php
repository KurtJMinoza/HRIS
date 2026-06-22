<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_batch_runs','include_13th_month_pay')) {
            Schema::table('payroll_batch_runs',fn(Blueprint $table)=>$table->boolean('include_13th_month_pay')->default(false)->after('include_thirteenth_month'));
        }
        DB::table('payroll_batch_runs')->where('include_thirteenth_month',true)->update(['include_13th_month_pay'=>true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_batch_runs','include_13th_month_pay')) {
            Schema::table('payroll_batch_runs',fn(Blueprint $table)=>$table->dropColumn('include_13th_month_pay'));
        }
    }
};
