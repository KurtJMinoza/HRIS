<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_government_deduction_settings')) {
            return;
        }

        Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_government_deduction_settings', 'applies_to_regular_payroll')) {
                $table->boolean('applies_to_regular_payroll')->default(true)->after('deduct_withholding_tax');
            }
            if (! Schema::hasColumn('employee_government_deduction_settings', 'applies_to_execom_payroll')) {
                $table->boolean('applies_to_execom_payroll')->default(true)->after('applies_to_regular_payroll');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_government_deduction_settings')) {
            return;
        }

        Schema::table('employee_government_deduction_settings', function (Blueprint $table) {
            if (Schema::hasColumn('employee_government_deduction_settings', 'applies_to_execom_payroll')) {
                $table->dropColumn('applies_to_execom_payroll');
            }
            if (Schema::hasColumn('employee_government_deduction_settings', 'applies_to_regular_payroll')) {
                $table->dropColumn('applies_to_regular_payroll');
            }
        });
    }
};
