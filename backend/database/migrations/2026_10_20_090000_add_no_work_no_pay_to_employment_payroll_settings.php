<?php

use App\Services\EmploymentPayrollPolicyResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employment_payroll_settings')) {
            return;
        }

        if (Schema::hasColumn('employment_payroll_settings', 'no_work_no_pay')) {
            return;
        }

        Schema::table('employment_payroll_settings', function (Blueprint $table): void {
            $table->boolean('no_work_no_pay')->default(false)->after('allow_holiday_pay');
        });

        // Cached employment payroll policies are keyed by company + employment type and do not
        // include the new flag, so drop them — the first payroll run after the deploy must already
        // see the extra setting.
        try {
            app(EmploymentPayrollPolicyResolver::class)->forget(null);
        } catch (\Throwable) {
            // Cache stores vary by environment; saving the settings also forgets this cache.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('employment_payroll_settings')) {
            return;
        }

        if (! Schema::hasColumn('employment_payroll_settings', 'no_work_no_pay')) {
            return;
        }

        Schema::table('employment_payroll_settings', function (Blueprint $table): void {
            $table->dropColumn('no_work_no_pay');
        });

        try {
            app(EmploymentPayrollPolicyResolver::class)->forget(null);
        } catch (\Throwable) {
            // Cache stores vary by environment.
        }
    }
};
