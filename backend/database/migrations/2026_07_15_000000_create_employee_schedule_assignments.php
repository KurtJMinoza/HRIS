<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_schedule_assignments')) {
            Schema::create('employee_schedule_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('schedule_template_id')->nullable()->constrained('working_schedules')->nullOnDelete();
                $table->unsignedBigInteger('assignment_snapshot_id')->nullable();
                $table->date('effective_start_date');
                $table->date('effective_end_date')->nullable();
                $table->string('assignment_type', 40)->default('template');
                $table->string('source_scope_type', 40)->default('employee');
                $table->unsignedBigInteger('source_scope_id')->nullable();
                $table->string('assignment_status', 30)->default('active');
                $table->boolean('is_adjustment')->default(false);
                $table->text('adjustment_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['employee_id', 'effective_start_date'], 'esa_employee_start_idx');
                $table->index(['employee_id', 'effective_end_date'], 'esa_employee_end_idx');
                $table->unique(['employee_id', 'effective_start_date', 'assignment_status'], 'esa_employee_start_status_unique');
            });
        }

        if (! Schema::hasTable('schedule_assignment_snapshots')) {
            Schema::create('schedule_assignment_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_schedule_assignment_id')->nullable();
                $table->string('schedule_name');
                $table->string('schedule_type', 40)->default('fixed');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->boolean('crosses_midnight')->default(false);
                $table->unsignedInteger('scheduled_minutes')->nullable();
                $table->unsignedInteger('paid_minutes')->nullable();
                $table->unsignedInteger('grace_period_minutes')->default(0);
                $table->json('late_deduction_policy')->nullable();
                $table->json('half_day_policy')->nullable();
                $table->json('workweek_days')->nullable();
                $table->json('rest_days')->nullable();
                $table->json('break_rules')->nullable();
                $table->json('overtime_rules')->nullable();
                $table->json('night_differential_rules')->nullable();
                $table->json('schedule_payload')->nullable();
                $table->timestamps();

                $table->foreign('employee_schedule_assignment_id', 'sas_assignment_fk')
                    ->references('id')
                    ->on('employee_schedule_assignments')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_assignment_snapshots');
        Schema::dropIfExists('employee_schedule_assignments');
    }
};
