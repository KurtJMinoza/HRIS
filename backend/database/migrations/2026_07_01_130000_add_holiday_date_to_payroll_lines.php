<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_lines')) {
            return;
        }

        if (! Schema::hasColumn('payroll_lines', 'holiday_date')) {
            Schema::table('payroll_lines', function (Blueprint $table): void {
                $table->date('holiday_date')->nullable()->after('holiday_id');
            });
        }

        if (Schema::hasColumn('payroll_lines', 'holiday_id')) {
            Schema::table('payroll_lines', function (Blueprint $table): void {
                try {
                    $table->dropUnique('payroll_lines_holiday_component_unique');
                } catch (\Throwable) {
                    // ponytail: index name may differ on older installs
                }
            });

            Schema::table('payroll_lines', function (Blueprint $table): void {
                $table->unique(
                    ['payroll_employee_id', 'status', 'holiday_id', 'holiday_date', 'component_code'],
                    'payroll_lines_holiday_component_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_lines')) {
            return;
        }

        Schema::table('payroll_lines', function (Blueprint $table): void {
            try {
                $table->dropUnique('payroll_lines_holiday_component_unique');
            } catch (\Throwable) {
            }
        });

        if (Schema::hasColumn('payroll_lines', 'holiday_date')) {
            Schema::table('payroll_lines', function (Blueprint $table): void {
                $table->dropColumn('holiday_date');
            });
        }

        if (Schema::hasColumn('payroll_lines', 'holiday_id')) {
            Schema::table('payroll_lines', function (Blueprint $table): void {
                $table->unique(
                    ['payroll_employee_id', 'status', 'holiday_id', 'component_code'],
                    'payroll_lines_holiday_component_unique'
                );
            });
        }
    }
};
