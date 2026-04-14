<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_daily_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('date');
            $table->string('review_status', 24)->default('valid');
            $table->boolean('is_rest_day')->default(false);
            $table->string('holiday_type', 30)->nullable();
            $table->string('holiday_name', 255)->nullable();
            $table->integer('regular_day_minutes')->default(0);
            $table->integer('regular_night_minutes')->default(0);
            $table->integer('ot_day_minutes')->default(0);
            $table->integer('ot_night_minutes')->default(0);
            $table->integer('approved_ot_minutes')->default(0);
            $table->integer('unapproved_ot_minutes')->default(0);
            $table->integer('late_deduction_minutes')->default(0);
            $table->decimal('total_pay', 14, 2)->default(0);
            $table->json('conditions')->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date'], 'pdl_user_date_unique');
            $table->index(['date', 'review_status'], 'pdl_date_status_idx');
            $table->index(['company_id', 'date'], 'pdl_company_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_daily_logs');
    }
};
