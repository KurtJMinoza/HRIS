<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holiday_pay_policy_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'holiday_type')) {
                $table->string('holiday_type', 32)->nullable()->after('holiday_key');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'unworked_pay_policy')) {
                $table->string('unworked_pay_policy', 40)->nullable()->after('holiday_type');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'eligible_employment_types')) {
                $table->json('eligible_employment_types')->nullable()->after('unworked_pay_policy');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'always_pay_unworked')) {
                $table->boolean('always_pay_unworked')->default(false)->after('always_pay');
            }
        });
    }

    public function down(): void
    {
        Schema::table('holiday_pay_policy_settings', function (Blueprint $table) {
            foreach (['holiday_type', 'unworked_pay_policy', 'eligible_employment_types', 'always_pay_unworked'] as $column) {
                if (Schema::hasColumn('holiday_pay_policy_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
