<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'geofence_enabled')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->boolean('geofence_enabled')->default(true)->after('default_pay_cycle_id');
                $table->string('geofence_no_active_policy', 16)->default('block')->after('geofence_enabled');
                $table->string('geofence_accuracy_policy', 16)->default('balanced')->after('geofence_no_active_policy');
                $table->string('geofence_poor_accuracy_action', 16)->default('block')->after('geofence_accuracy_policy');
                $table->unsignedInteger('geofence_default_accuracy_threshold_meters')->default(100)->after('geofence_poor_accuracy_action');
                $table->boolean('geofence_allow_cross_branch')->default(false)->after('geofence_default_accuracy_threshold_meters');
            });
        }

        Schema::create('branch_geofences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lng', 10, 7)->nullable();
            $table->unsignedInteger('radius_meters')->nullable();
            $table->json('polygon_geojson')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(1);
            $table->unsignedInteger('accuracy_threshold_meters')->default(100);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'is_active', 'type'], 'branch_geofences_scope_idx');
            $table->index(['branch_id', 'is_active', 'priority'], 'branch_geofences_active_priority_idx');
        });

        Schema::create('geofence_validation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('attendance_log_id')->nullable()->constrained('attendance_logs')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->foreignId('matched_geofence_id')->nullable()->constrained('branch_geofences')->nullOnDelete();
            $table->boolean('is_inside')->default(false);
            $table->decimal('distance_to_center', 10, 2)->nullable();
            $table->string('validation_status', 32)->index();
            $table->string('failure_reason')->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('method', 32)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at'], 'geofence_logs_employee_created_idx');
            $table->index(['branch_id', 'created_at'], 'geofence_logs_branch_created_idx');
            $table->index(['validation_status', 'created_at'], 'geofence_logs_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_validation_logs');
        Schema::dropIfExists('branch_geofences');

        if (Schema::hasColumn('branches', 'geofence_enabled')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropColumn([
                    'geofence_enabled',
                    'geofence_no_active_policy',
                    'geofence_accuracy_policy',
                    'geofence_poor_accuracy_action',
                    'geofence_default_accuracy_threshold_meters',
                    'geofence_allow_cross_branch',
                ]);
            });
        }
    }
};
