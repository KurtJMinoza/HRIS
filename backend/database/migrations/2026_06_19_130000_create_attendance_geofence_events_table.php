<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_geofence_events')) {
            return;
        }

        Schema::create('attendance_geofence_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();
            $table->foreignId('geofence_validation_log_id')->nullable()->constrained('geofence_validation_logs')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('clock_type', 24);
            $table->string('event_type', 40)->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->string('geofence_status', 40)->index();
            $table->foreignId('matched_geofence_id')->nullable()->constrained('branch_geofences')->nullOnDelete();
            $table->string('device_type', 32)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('platform', 80)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'branch_id'], 'attendance_geofence_events_date_branch_idx');
            $table->index(['company_id', 'created_at'], 'attendance_geofence_events_company_date_idx');
            $table->index(['employee_id', 'created_at'], 'attendance_geofence_events_employee_date_idx');
            $table->unique('geofence_validation_log_id', 'attendance_geofence_events_validation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_geofence_events');
    }
};
