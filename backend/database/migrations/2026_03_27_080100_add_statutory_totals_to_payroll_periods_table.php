<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_periods', 'basic_salary_used')) {
                $table->decimal('basic_salary_used', 14, 2)->default(0)->after('daily_rate');
            }
            if (! Schema::hasColumn('payroll_periods', 'employee_statutory_total')) {
                $table->decimal('employee_statutory_total', 14, 2)->default(0)->after('total_pay');
            }
            if (! Schema::hasColumn('payroll_periods', 'employer_statutory_total')) {
                $table->decimal('employer_statutory_total', 14, 2)->default(0)->after('employee_statutory_total');
            }
            if (! Schema::hasColumn('payroll_periods', 'net_pay')) {
                $table->decimal('net_pay', 14, 2)->default(0)->after('employer_statutory_total');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_periods')) {
            return;
        }

        Schema::table('payroll_periods', function (Blueprint $table) {
            foreach (['basic_salary_used', 'employee_statutory_total', 'employer_statutory_total', 'net_pay'] as $col) {
                if (Schema::hasColumn('payroll_periods', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
