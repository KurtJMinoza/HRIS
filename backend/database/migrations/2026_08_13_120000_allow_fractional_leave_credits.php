<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half-day leave consumes half a leave credit (0.5). Convert integer credit columns to decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'leave_credits')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('leave_credits', 8, 2)->default(14)->change();
            });
        }

        if (Schema::hasTable('leave_credit_transactions')) {
            Schema::table('leave_credit_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('leave_credit_transactions', 'delta')) {
                    $table->decimal('delta', 10, 2)->change();
                }
                if (Schema::hasColumn('leave_credit_transactions', 'balance_after')) {
                    $table->decimal('balance_after', 10, 2)->change();
                }
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (Schema::hasColumn('leave_requests', 'leave_credits_charged')) {
                    $table->decimal('leave_credits_charged', 8, 2)->nullable()->change();
                }
                if (Schema::hasColumn('leave_requests', 'leave_unpaid_credit_days')) {
                    $table->decimal('leave_unpaid_credit_days', 8, 2)->nullable()->change();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'leave_credits')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('leave_credits')->default(14)->change();
            });
        }

        if (Schema::hasTable('leave_credit_transactions')) {
            Schema::table('leave_credit_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('leave_credit_transactions', 'delta')) {
                    $table->integer('delta')->change();
                }
                if (Schema::hasColumn('leave_credit_transactions', 'balance_after')) {
                    $table->unsignedInteger('balance_after')->change();
                }
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                if (Schema::hasColumn('leave_requests', 'leave_credits_charged')) {
                    $table->unsignedInteger('leave_credits_charged')->nullable()->change();
                }
                if (Schema::hasColumn('leave_requests', 'leave_unpaid_credit_days')) {
                    $table->unsignedInteger('leave_unpaid_credit_days')->nullable()->change();
                }
            });
        }
    }
};