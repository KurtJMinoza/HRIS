<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')) {
            return;
        }

        // ponytail: WIP briefly used allow_leave_pay; rename/replace to allow_paid_leave (spec name).
        if (Schema::hasColumn('execom_payroll_settings', 'allow_leave_pay')
            && ! Schema::hasColumn('execom_payroll_settings', 'allow_paid_leave')) {
            Schema::table('execom_payroll_settings', function (Blueprint $table): void {
                $table->renameColumn('allow_leave_pay', 'allow_paid_leave');
            });

            return;
        }

        if (Schema::hasColumn('execom_payroll_settings', 'allow_paid_leave')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->boolean('allow_paid_leave')->default(true)->after('allow_holiday_pay');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('execom_payroll_settings')
            || ! Schema::hasColumn('execom_payroll_settings', 'allow_paid_leave')) {
            return;
        }

        Schema::table('execom_payroll_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_paid_leave');
        });
    }
};
