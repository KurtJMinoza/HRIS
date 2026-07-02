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
            foreach ([
                'regular_unworked_attendance_rule',
                'special_unworked_attendance_rule',
            ] as $column) {
                if (Schema::hasColumn('holiday_pay_policy_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('holiday_pay_policy_settings')) {
            return;
        }

        foreach ([
            'regular_unworked_attendance_rule',
            'special_unworked_attendance_rule',
        ] as $column) {
            if (! Schema::hasColumn('holiday_pay_policy_settings', $column)) {
                Schema::table('holiday_pay_policy_settings', function (Blueprint $table) use ($column): void {
                    $table->string($column, 40)->nullable();
                });
            }
        }
    }
};
