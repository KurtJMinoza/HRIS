<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_tax_info')) {
            Schema::table('employee_tax_info', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_tax_info', 'is_mwe')) {
                    $table->boolean('is_mwe')->default(false)->after('dependents');
                }
                if (! Schema::hasColumn('employee_tax_info', 'mwe_monthly_ceiling')) {
                    $table->decimal('mwe_monthly_ceiling', 12, 2)->nullable()->after('is_mwe');
                }
                if (! Schema::hasColumn('employee_tax_info', 'is_senior_citizen')) {
                    $table->boolean('is_senior_citizen')->default(false)->after('mwe_monthly_ceiling');
                }
                if (! Schema::hasColumn('employee_tax_info', 'is_pwd')) {
                    $table->boolean('is_pwd')->default(false)->after('is_senior_citizen');
                }
                if (! Schema::hasColumn('employee_tax_info', 'is_solo_parent')) {
                    $table->boolean('is_solo_parent')->default(false)->after('is_pwd');
                }
                if (! Schema::hasColumn('employee_tax_info', 'tax_regime')) {
                    $table->string('tax_regime', 32)->default('standard_train')->after('is_solo_parent');
                }
                if (! Schema::hasColumn('employee_tax_info', 'additional_exemption_amount')) {
                    $table->decimal('additional_exemption_amount', 12, 2)->nullable()->after('tax_regime');
                }
            });
        }

        if (Schema::hasTable('companies') && ! Schema::hasColumn('companies', 'payroll_settings')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->json('payroll_settings')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_tax_info')) {
            Schema::table('employee_tax_info', function (Blueprint $table) {
                foreach ([
                    'is_mwe',
                    'mwe_monthly_ceiling',
                    'is_senior_citizen',
                    'is_pwd',
                    'is_solo_parent',
                    'tax_regime',
                    'additional_exemption_amount',
                ] as $col) {
                    if (Schema::hasColumn('employee_tax_info', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'payroll_settings')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('payroll_settings');
            });
        }
    }
};
