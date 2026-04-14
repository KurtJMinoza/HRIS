<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll Ledger – audit trail, payslip source, reporting source.
     * Stores computed payroll per user per date (never compute directly from attendance).
     */
    public function up(): void
    {
        Schema::create('payroll_daily_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('regular_hours', 8, 2)->default(0);
            $table->decimal('ot_hours', 8, 2)->default(0);
            $table->decimal('nd_hours', 8, 2)->default(0);
            $table->decimal('nd_ot_hours', 8, 2)->default(0);
            $table->string('rule_code', 10)->nullable();
            $table->decimal('first8_pay', 14, 2)->default(0);
            $table->decimal('ot_pay', 14, 2)->default(0);
            $table->decimal('nd_pay', 14, 2)->default(0);
            $table->decimal('holiday_premium_pay', 14, 2)->default(0);
            $table->decimal('total_pay', 14, 2)->default(0);
            $table->boolean('is_ot_approved')->default(false);
            $table->decimal('approved_ot_hours', 8, 2)->default(0);
            $table->decimal('unapproved_ot_hours', 8, 2)->default(0);
            $table->string('holiday_type', 30)->nullable();
            $table->boolean('is_rest_day')->default(false);
            $table->integer('late_deduction_minutes')->default(0);
            $table->integer('undertime_deduction_minutes')->default(0);
            $table->json('breakdown')->nullable(); // Audit: per-component amounts
            $table->integer('worked_minutes')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_daily_records');
    }
};
