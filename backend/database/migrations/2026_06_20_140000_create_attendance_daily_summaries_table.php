<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->date('date');
            $table->string('employee_name', 200)->nullable();
            $table->string('employee_code', 50)->nullable();
            $table->string('position', 150)->nullable();
            $table->string('profile_image', 500)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->string('branch_name', 150)->nullable();
            $table->string('department_name', 150)->nullable();
            $table->string('day_name', 12)->nullable();
            $table->string('schedule_label', 80)->nullable();
            $table->string('schedule_in', 10)->nullable();
            $table->string('schedule_out', 10)->nullable();
            $table->string('time_in', 10)->nullable();
            $table->string('time_out', 10)->nullable();
            $table->string('formatted_time_in', 12)->nullable();
            $table->string('formatted_time_out', 12)->nullable();
            $table->boolean('time_out_next_day')->default(false);
            $table->decimal('total_hours', 6, 2)->nullable();
            $table->decimal('scheduled_regular_hours', 6, 2)->nullable();
            $table->integer('late_minutes')->nullable();
            $table->integer('undertime_minutes')->nullable();
            $table->integer('overtime_minutes')->nullable();
            $table->decimal('approved_ot_hours', 6, 2)->nullable();
            $table->decimal('payable_ot_hours', 6, 2)->nullable();
            $table->decimal('rendered_ot_hours', 6, 2)->nullable();
            $table->decimal('nd_hours', 6, 2)->nullable();
            $table->decimal('overtime_pay', 10, 2)->nullable();
            $table->decimal('night_differential_pay', 10, 2)->nullable();
            $table->decimal('total_premium_pay', 10, 2)->nullable();
            $table->string('premium_type', 60)->nullable();
            $table->string('status', 20)->default('—');
            $table->string('presence_label', 60)->nullable();
            $table->string('presence_issue', 60)->nullable();
            $table->string('overtime_status', 30)->nullable();
            $table->boolean('is_rest_day')->default(false);
            $table->string('holiday_name', 120)->nullable();
            $table->string('holiday_type', 40)->nullable();
            $table->boolean('has_correction')->default(false);
            $table->boolean('correction_approved')->default(false);
            $table->boolean('has_approved_overtime')->default(false);
            $table->decimal('payroll_impact_hours', 6, 2)->nullable();
            $table->json('extra')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date'], 'ads_employee_date_unique');
            $table->index(['company_id', 'date'], 'ads_company_date_idx');
            $table->index(['branch_id', 'date'], 'ads_branch_date_idx');
            $table->index(['department_id', 'date'], 'ads_department_date_idx');
            $table->index(['date', 'status'], 'ads_date_status_idx');
            $table->index(['employee_name'], 'ads_employee_name_idx');
            $table->index(['employee_code'], 'ads_employee_code_idx');
            $table->index(['date', 'company_id', 'status'], 'ads_date_company_status_idx');

            $table->foreign('employee_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_summaries');
    }
};
