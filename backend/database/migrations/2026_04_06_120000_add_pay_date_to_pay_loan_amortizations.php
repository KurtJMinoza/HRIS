<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pay date = when the installment is withheld (aligned with pay cycle pay day).
 * Cut-off end stays in due_date for reference; UI "Next due" prefers pay_date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_loan_amortizations')) {
            return;
        }

        if (! Schema::hasColumn('pay_loan_amortizations', 'pay_date')) {
            Schema::table('pay_loan_amortizations', function (Blueprint $table) {
                $table->date('pay_date')->nullable()->after('due_date');
            });
        }

        if (Schema::hasColumn('pay_loan_amortizations', 'pay_date')) {
            DB::table('pay_loan_amortizations')->whereNull('pay_date')->update([
                'pay_date' => DB::raw('due_date'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pay_loan_amortizations') && Schema::hasColumn('pay_loan_amortizations', 'pay_date')) {
            Schema::table('pay_loan_amortizations', function (Blueprint $table) {
                $table->dropColumn('pay_date');
            });
        }
    }
};
