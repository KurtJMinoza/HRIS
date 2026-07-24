<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_geofence_employee')) {
            Schema::dropIfExists('branch_geofence_employee');
        }

        if (Schema::hasTable('employee_geofence_assignments')) {
            return;
        }

        Schema::create('employee_geofence_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('geofence_id')->nullable()->constrained('branch_geofences')->nullOnDelete();
            $table->string('assignment_type', 32)->default('permanent');
            $table->string('validation_mode', 32)->default('any_assigned_geofence');
            $table->boolean('is_primary')->default(false);
            $table->date('effective_start_date');
            $table->date('effective_end_date')->nullable();
            $table->boolean('clock_in_applies')->default(true);
            $table->boolean('clock_out_applies')->default(true);
            $table->string('status', 32)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status', 'effective_start_date'], 'ega_employee_status_start_idx');
            $table->index(['employee_id', 'geofence_id', 'status'], 'ega_employee_geofence_status_idx');
            $table->index(['effective_start_date', 'effective_end_date'], 'ega_effective_dates_idx');
        });

        Schema::create('employee_geofence_assignment_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 64);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'created_at'], 'egaa_employee_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_geofence_assignment_audits');
        Schema::dropIfExists('employee_geofence_assignments');
    }
};
