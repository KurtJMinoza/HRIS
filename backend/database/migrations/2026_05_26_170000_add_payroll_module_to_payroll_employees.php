<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_employees') || Schema::hasColumn('payroll_employees', 'payroll_module')) {
            return;
        }

        Schema::table('payroll_employees', function (Blueprint $table): void {
            $table->string('payroll_module', 30)->default('standard')->after('id');
            $table->index(['payroll_module'], 'payroll_employees_payroll_module_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_employees') || ! Schema::hasColumn('payroll_employees', 'payroll_module')) {
            return;
        }

        Schema::table('payroll_employees', function (Blueprint $table): void {
            try {
                $table->dropIndex('payroll_employees_payroll_module_idx');
            } catch (Throwable) {
                // Guarded for partially migrated local databases.
            }
            $table->dropColumn('payroll_module');
        });
    }
};
