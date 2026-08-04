<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employment_payroll_settings')) {
            return;
        }

        Schema::create('employment_payroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_type', 50);
            $table->boolean('apply_custom_deductions')->default(true);
            $table->boolean('apply_allowances')->default(true);
            $table->boolean('allow_paid_leave')->default(true);
            $table->boolean('allow_overtime')->default(false);
            $table->boolean('allow_holiday_pay')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'employment_type'], 'employment_payroll_settings_company_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_payroll_settings');
    }
};
