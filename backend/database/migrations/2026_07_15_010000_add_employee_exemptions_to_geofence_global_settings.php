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
                $table->json('employee_exemption_ids')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('geofence_global_settings', 'employee_exemption_ids')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                $table->json('employee_exemption_ids')->nullable()->after('attendance_without_geofence_enabled');
            });
        }

        $existing = DB::table('geofence_global_settings')->where('id', 1)->first();
        if ($existing) {
            DB::table('geofence_global_settings')
                ->where('id', 1)
                ->whereNull('employee_exemption_ids')
                ->update([
                    'employee_exemption_ids' => json_encode([]),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('geofence_global_settings')->insert([
                'id' => 1,
                'geofence_module_enabled' => true,
                'attendance_without_geofence_enabled' => true,
                'employee_exemption_ids' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('geofence_global_settings') && Schema::hasColumn('geofence_global_settings', 'employee_exemption_ids')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                $table->dropColumn('employee_exemption_ids');
            });
        }
    }
};
