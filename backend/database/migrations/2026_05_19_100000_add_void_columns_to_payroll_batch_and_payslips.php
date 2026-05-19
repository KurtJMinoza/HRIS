<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Void finalized payroll batches without reverting to draft or destroying snapshots.
 * Adds period_slot on payslips so voided rows can be archived while new drafts reuse the same pay window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('finalized_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable()->after('voided_by_user_id');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->unsignedBigInteger('period_slot')->default(0)->after('pay_period_end');
            $table->timestamp('voided_at')->nullable()->after('finalized_by_user_id');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique('payslips_user_period_unique');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'pay_period_start', 'pay_period_end', 'period_slot'],
                'payslips_user_period_slot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique('payslips_user_period_slot_unique');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->unique(['user_id', 'pay_period_start', 'pay_period_end'], 'payslips_user_period_unique');
            $table->dropColumn(['period_slot', 'voided_at']);
        });

        Schema::table('payroll_batch_runs', function (Blueprint $table) {
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn(['voided_at', 'voided_by_user_id', 'void_reason']);
        });
    }
};
