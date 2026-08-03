<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')
            || ! Schema::hasColumn('execom_payroll_settings', 'apply_government_deductions')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->dropColumn('apply_government_deductions');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')
            || Schema::hasColumn('execom_payroll_settings', 'apply_government_deductions')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->boolean('apply_government_deductions')->default(true)->after('company_id');
        });
    }
};
