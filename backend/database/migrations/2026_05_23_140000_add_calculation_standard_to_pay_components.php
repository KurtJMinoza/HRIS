<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_components', 'calculation_standard')) {
                $table->string('calculation_standard', 32)->nullable()->after('calculation_type');
            }
        });

        Schema::table('employee_compensation_components', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_compensation_components', 'calculation_standard')) {
                $table->string('calculation_standard', 32)->nullable()->after('calculation_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_compensation_components', function (Blueprint $table) {
            if (Schema::hasColumn('employee_compensation_components', 'calculation_standard')) {
                $table->dropColumn('calculation_standard');
            }
        });

        Schema::table('pay_components', function (Blueprint $table) {
            if (Schema::hasColumn('pay_components', 'calculation_standard')) {
                $table->dropColumn('calculation_standard');
            }
        });
    }
};
