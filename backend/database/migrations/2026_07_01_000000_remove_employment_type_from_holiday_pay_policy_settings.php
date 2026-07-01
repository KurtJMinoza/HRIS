<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holiday_pay_policy_settings')) {
            return;
        }

        Schema::table('holiday_pay_policy_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'regular_unworked_policy')) {
                $table->string('regular_unworked_policy', 40)->nullable();
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'regular_unworked_employment_types')) {
                $table->json('regular_unworked_employment_types')->nullable();
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'special_unworked_policy')) {
                $table->string('special_unworked_policy', 40)->nullable();
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'special_unworked_employment_types')) {
                $table->json('special_unworked_employment_types')->nullable();
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'rest_day_lookup_enabled')) {
                $table->boolean('rest_day_lookup_enabled')->default(true);
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'paid_leave_qualifies')) {
                $table->boolean('paid_leave_qualifies')->default(true);
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'successive_holiday_rule_enabled')) {
                $table->boolean('successive_holiday_rule_enabled')->default(true);
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('holiday_pay_policy_settings')) {
            return;
        }

        Schema::table('holiday_pay_policy_settings', function (Blueprint $table): void {
            foreach ([
                'regular_unworked_policy',
                'regular_unworked_employment_types',
                'special_unworked_policy',
                'special_unworked_employment_types',
                'rest_day_lookup_enabled',
                'paid_leave_qualifies',
                'successive_holiday_rule_enabled',
                'is_active',
            ] as $column) {
                if (Schema::hasColumn('holiday_pay_policy_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
