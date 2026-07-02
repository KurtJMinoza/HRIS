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
        Schema::table('holiday_pay_policy_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'regular_unworked_holiday_selection_mode')) {
                $table->string('regular_unworked_holiday_selection_mode', 40)->nullable()->after('regular_unworked_policy');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'regular_unworked_holiday_ids')) {
                $table->json('regular_unworked_holiday_ids')->nullable()->after('regular_unworked_holiday_selection_mode');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'regular_unworked_employment_type_mode')) {
                $table->string('regular_unworked_employment_type_mode', 40)->nullable()->after('regular_unworked_employment_types');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'special_unworked_holiday_selection_mode')) {
                $table->string('special_unworked_holiday_selection_mode', 40)->nullable()->after('special_unworked_policy');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'special_unworked_holiday_ids')) {
                $table->json('special_unworked_holiday_ids')->nullable()->after('special_unworked_holiday_selection_mode');
            }
            if (! Schema::hasColumn('holiday_pay_policy_settings', 'special_unworked_employment_type_mode')) {
                $table->string('special_unworked_employment_type_mode', 40)->nullable()->after('special_unworked_employment_types');
            }
        });
    }

    public function down(): void
    {
        Schema::table('holiday_pay_policy_settings', function (Blueprint $table) {
            foreach ([
                'regular_unworked_holiday_selection_mode',
                'regular_unworked_holiday_ids',
                'regular_unworked_employment_type_mode',
                'special_unworked_holiday_selection_mode',
                'special_unworked_holiday_ids',
                'special_unworked_employment_type_mode',
            ] as $column) {
                if (Schema::hasColumn('holiday_pay_policy_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
