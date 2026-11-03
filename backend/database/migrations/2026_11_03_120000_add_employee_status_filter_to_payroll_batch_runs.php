<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_batch_runs', 'employee_status_filter')) {
            Schema::table('payroll_batch_runs', function (Blueprint $table): void {
                // Consultant Payroll type filter: consultant, consultant_regular, consultant_probationary (null = all).
                $table->string('employee_status_filter', 40)->nullable()->after('employee_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_batch_runs', 'employee_status_filter')) {
            Schema::table('payroll_batch_runs', fn (Blueprint $table) => $table->dropColumn('employee_status_filter'));
        }
    }
};
