<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holiday_pay_policy_settings')) {
            Schema::create('holiday_pay_policy_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('holiday_id')->nullable()->constrained('holidays')->nullOnDelete();
                $table->string('holiday_key', 120)->nullable();
                $table->string('holiday_type', 32)->nullable();
                $table->string('unworked_pay_policy', 40)->nullable();
                $table->json('eligible_employment_types')->nullable();
                $table->string('regular_unworked_policy', 40)->nullable();
                $table->json('regular_unworked_employment_types')->nullable();
                $table->string('special_unworked_policy', 40)->nullable();
                $table->json('special_unworked_employment_types')->nullable();
                $table->boolean('pay_unworked')->nullable();
                $table->boolean('require_previous_workday_attendance')->nullable();
                $table->boolean('allow_paid_leave')->nullable();
                $table->boolean('paid_leave_qualifies')->default(true);
                $table->boolean('allow_official_business')->nullable();
                $table->boolean('allow_training')->nullable();
                $table->boolean('allow_travel')->nullable();
                $table->boolean('allow_rest_day_lookup')->nullable();
                $table->boolean('allow_company_nonworking_lookup')->nullable();
                $table->boolean('ignore_previous_attendance')->default(false);
                $table->boolean('always_pay')->default(false);
                $table->boolean('always_pay_unworked')->default(false);
                $table->boolean('enable_successive_rule')->nullable();
                $table->boolean('disable_attendance_qualification')->default(false);
                $table->boolean('rest_day_lookup_enabled')->default(true);
                $table->boolean('successive_holiday_rule_enabled')->default(true);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->unique(['policy_id', 'holiday_id']);
                $table->unique(['policy_id', 'holiday_key']);
            });

            return;
        }

        $columns = [
            'eligible_employment_types' => fn (Blueprint $table) => $table->json('eligible_employment_types')->nullable(),
            'regular_unworked_policy' => fn (Blueprint $table) => $table->string('regular_unworked_policy', 40)->nullable(),
            'regular_unworked_employment_types' => fn (Blueprint $table) => $table->json('regular_unworked_employment_types')->nullable(),
            'special_unworked_policy' => fn (Blueprint $table) => $table->string('special_unworked_policy', 40)->nullable(),
            'special_unworked_employment_types' => fn (Blueprint $table) => $table->json('special_unworked_employment_types')->nullable(),
            'paid_leave_qualifies' => fn (Blueprint $table) => $table->boolean('paid_leave_qualifies')->default(true),
            'rest_day_lookup_enabled' => fn (Blueprint $table) => $table->boolean('rest_day_lookup_enabled')->default(true),
            'successive_holiday_rule_enabled' => fn (Blueprint $table) => $table->boolean('successive_holiday_rule_enabled')->default(true),
            'is_active' => fn (Blueprint $table) => $table->boolean('is_active')->default(true),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('holiday_pay_policy_settings', $name)) {
                Schema::table('holiday_pay_policy_settings', $add);
            }
        }
    }

    public function down(): void
    {
        // This is a repair migration; retain the policy table on rollback.
    }
};
