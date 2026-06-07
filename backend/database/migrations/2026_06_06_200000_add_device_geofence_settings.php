<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geofence_global_settings')) {
            Schema::create('geofence_global_settings', function (Blueprint $table): void {
                $table->id();
                $table->boolean('attendance_without_geofence_enabled')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('branch_geofence_settings')) {
            Schema::create('branch_geofence_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->unique()->constrained('branches')->cascadeOnDelete();
                $table->boolean('allow_without_geofence')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('branch_geofences')) {
            Schema::table('branch_geofences', function (Blueprint $table): void {
                if (! Schema::hasColumn('branch_geofences', 'device_scope')) {
                    $table->string('device_scope', 32)->default('all_devices')->after('type');
                }
                if (! Schema::hasColumn('branch_geofences', 'status')) {
                    $table->string('status', 16)->default('active')->after('is_active');
                }
            });

            DB::table('branch_geofences')->orderBy('id')->each(function (object $geofence): void {
                DB::table('branch_geofences')
                    ->where('id', $geofence->id)
                    ->update([
                        'device_scope' => $geofence->device_scope ?: 'all_devices',
                        'status' => $geofence->status ?: ((bool) $geofence->is_active ? 'active' : 'inactive'),
                    ]);
            });
        }

        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                $table->decimal('latitude', 10, 7)->nullable()->change();
                $table->decimal('longitude', 10, 7)->nullable()->change();
                if (! Schema::hasColumn('geofence_validation_logs', 'device_scope_matched')) {
                    $table->string('device_scope_matched', 32)->nullable()->after('device_type');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'geofence_name')) {
                    $table->string('geofence_name')->nullable()->after('matched_geofence_id');
                }
                if (! Schema::hasColumn('geofence_validation_logs', 'skip_reason')) {
                    $table->string('skip_reason')->nullable()->after('failure_reason');
                }
            });
        }

        DB::table('geofence_global_settings')->updateOrInsert(
            ['id' => 1],
            [
                'attendance_without_geofence_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        if (Schema::hasTable('branches')) {
            DB::table('branches')->orderBy('id')->each(function (object $branch): void {
                DB::table('branch_geofence_settings')->updateOrInsert(
                    ['branch_id' => $branch->id],
                    [
                        'allow_without_geofence' => ($branch->geofence_no_active_policy ?? 'block') === 'allow',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('geofence_validation_logs')) {
            Schema::table('geofence_validation_logs', function (Blueprint $table): void {
                foreach (['skip_reason', 'geofence_name', 'device_scope_matched'] as $column) {
                    if (Schema::hasColumn('geofence_validation_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('branch_geofences')) {
            Schema::table('branch_geofences', function (Blueprint $table): void {
                foreach (['status', 'device_scope'] as $column) {
                    if (Schema::hasColumn('branch_geofences', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('branch_geofence_settings');
        Schema::dropIfExists('geofence_global_settings');
    }
};
