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
                $table->boolean('geofence_module_enabled')->default(true);
                $table->boolean('attendance_without_geofence_enabled')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('geofence_global_settings', 'geofence_module_enabled')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                $table->boolean('geofence_module_enabled')->default(true)->after('id');
            });
        }

        $existing = DB::table('geofence_global_settings')->where('id', 1)->first();

        DB::table('geofence_global_settings')->updateOrInsert(
            ['id' => 1],
            [
                'geofence_module_enabled' => true,
                'attendance_without_geofence_enabled' => (bool) ($existing->attendance_without_geofence_enabled ?? true),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('geofence_global_settings') && Schema::hasColumn('geofence_global_settings', 'geofence_module_enabled')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                $table->dropColumn('geofence_module_enabled');
            });
        }
    }
};
