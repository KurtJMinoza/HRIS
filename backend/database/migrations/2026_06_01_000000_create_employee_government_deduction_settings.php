<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_government_deduction_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('deduct_sss')->default(true);
            $table->boolean('deduct_philhealth')->default(true);
            $table->boolean('deduct_pagibig')->default(true);
            $table->boolean('deduct_withholding_tax')->default(true);
            $table->boolean('applies_to_regular_payroll')->default(true);
            $table->boolean('applies_to_execom_payroll')->default(true);
            $table->text('exemption_reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_government_deduction_setting_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('deduction_type', 32);
            $table->boolean('old_value')->nullable();
            $table->boolean('new_value');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'deduction_type'], 'egd_audit_employee_type_idx');
            $table->index(['changed_by', 'created_at'], 'egd_audit_changed_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_government_deduction_setting_audits');
        Schema::dropIfExists('employee_government_deduction_settings');
    }
};
