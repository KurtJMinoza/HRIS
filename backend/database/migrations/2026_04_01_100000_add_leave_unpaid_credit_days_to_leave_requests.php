<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid vs unpaid split for credit-consuming leave (vacation, sick, etc.).
 * leave_credits_charged = working-day units paid from the annual pool; leave_unpaid_credit_days = remainder (unpaid).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'leave_unpaid_credit_days')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->unsignedInteger('leave_unpaid_credit_days')->nullable()->after('leave_credits_charged');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_requests') && Schema::hasColumn('leave_requests', 'leave_unpaid_credit_days')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropColumn('leave_unpaid_credit_days');
            });
        }
    }
};
