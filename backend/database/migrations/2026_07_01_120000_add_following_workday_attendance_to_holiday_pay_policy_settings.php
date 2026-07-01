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

        if (! Schema::hasColumn('holiday_pay_policy_settings', 'require_following_workday_attendance')) {
            Schema::table('holiday_pay_policy_settings', function (Blueprint $table): void {
                $table->boolean('require_following_workday_attendance')->nullable()->after('require_previous_workday_attendance');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('holiday_pay_policy_settings')
            && Schema::hasColumn('holiday_pay_policy_settings', 'require_following_workday_attendance')) {
            Schema::table('holiday_pay_policy_settings', function (Blueprint $table): void {
                $table->dropColumn('require_following_workday_attendance');
            });
        }
    }
};
