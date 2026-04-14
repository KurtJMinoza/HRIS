<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_breakdowns', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_breakdowns', 'regular_pay')) {
                $table->decimal('regular_pay', 14, 2)->default(0)->after('undertime_deduction_minutes');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'ot_pay')) {
                $table->decimal('ot_pay', 14, 2)->default(0)->after('regular_pay');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'nd_pay')) {
                $table->decimal('nd_pay', 14, 2)->default(0)->after('ot_pay');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'holiday_premium_pay')) {
                $table->decimal('holiday_premium_pay', 14, 2)->default(0)->after('nd_pay');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'approved_ot_minutes')) {
                $table->integer('approved_ot_minutes')->default(0)->after('holiday_premium_pay');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'unapproved_ot_minutes')) {
                $table->integer('unapproved_ot_minutes')->default(0)->after('approved_ot_minutes');
            }
            if (! Schema::hasColumn('payroll_breakdowns', 'rule_code')) {
                $table->string('rule_code', 10)->nullable()->after('holiday_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payroll_breakdowns', function (Blueprint $table) {
            $cols = ['regular_pay', 'ot_pay', 'nd_pay', 'holiday_premium_pay', 'approved_ot_minutes', 'unapproved_ot_minutes', 'rule_code'];
            $existing = array_filter($cols, fn ($c) => Schema::hasColumn('payroll_breakdowns', $c));
            if (! empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
