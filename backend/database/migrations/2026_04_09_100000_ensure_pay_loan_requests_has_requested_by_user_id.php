<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair: some databases may be missing `requested_by_user_id` if an older deploy ran code
 * before {@see 2026_04_08_100000_add_requested_by_to_loan_requests_and_request_loan_permission} was applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_loan_requests')) {
            return;
        }

        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            return;
        }

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('pay_loan_requests')->whereNull('requested_by_user_id')->update(['requested_by_user_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pay_loan_requests') || ! Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            return;
        }

        Schema::table('pay_loan_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_user_id');
        });
    }
};
