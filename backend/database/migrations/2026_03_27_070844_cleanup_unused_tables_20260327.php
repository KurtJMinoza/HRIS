<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * SAFETY NOTES:
         * - This migration only drops tables that currently have:
         *   1) zero rows,
         *   2) no app-layer references found in models/controllers/routes/tests/config,
         *   3) no inbound foreign keys from active tables.
         * - Core HRIS tables (users, org, attendance, leave, overtime, payroll, RBAC, audits) are intentionally untouched.
         * - Back up database before running on any non-local environment.
         */
        Schema::dropIfExists('employee_status_automations');
        Schema::dropIfExists('status_transition_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Emergency rollback: recreate dropped tables using their latest known production schema.
        Schema::create('employee_status_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('current_status', 32);
            $table->string('previous_status', 32)->nullable();
            $table->string('trigger_type', 32);
            $table->json('trigger_condition')->nullable();
            $table->date('effective_date');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('employee_status_automation_rules')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'effective_date'], 'esa_employee_effective_idx');
            $table->index(['trigger_type', 'effective_date'], 'esa_trigger_effective_idx');
        });

        Schema::create('status_transition_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_employment_status_id')->nullable()->constrained('employment_statuses')->nullOnDelete();
            $table->foreignId('to_employment_status_id')->constrained('employment_statuses');
            $table->foreignId('status_transition_rule_id')->nullable()->constrained('status_transition_rules')->nullOnDelete();
            $table->string('trigger_source', 32);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'status_transition_logs_user_id_created_at_index');
        });
    }
};
