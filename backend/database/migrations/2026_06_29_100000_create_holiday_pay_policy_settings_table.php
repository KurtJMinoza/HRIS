<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('holiday_pay_policy_settings')) {
            return;
        }

        Schema::create('holiday_pay_policy_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('holiday_id')->nullable()->constrained('holidays')->nullOnDelete();
            $table->string('holiday_key', 120)->nullable();
            $table->boolean('pay_unworked')->nullable();
            $table->boolean('require_previous_workday_attendance')->nullable();
            $table->boolean('allow_paid_leave')->nullable();
            $table->boolean('allow_official_business')->nullable();
            $table->boolean('allow_training')->nullable();
            $table->boolean('allow_travel')->nullable();
            $table->boolean('allow_rest_day_lookup')->nullable();
            $table->boolean('allow_company_nonworking_lookup')->nullable();
            $table->boolean('ignore_previous_attendance')->default(false);
            $table->boolean('always_pay')->default(false);
            $table->boolean('enable_successive_rule')->nullable();
            $table->boolean('disable_attendance_qualification')->default(false);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['policy_id', 'holiday_id']);
            $table->unique(['policy_id', 'holiday_key']);
            $table->index(['policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_pay_policy_settings');
    }
};
