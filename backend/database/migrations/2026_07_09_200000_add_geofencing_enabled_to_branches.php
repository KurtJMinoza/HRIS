<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'geofencing_enabled')) {
                $table->boolean('geofencing_enabled')->default(false)->after('default_pay_cycle_id');
            }
            if (! Schema::hasColumn('branches', 'geofencing_radius_meters')) {
                $table->unsignedInteger('geofencing_radius_meters')->nullable()->after('branch_longitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (Schema::hasColumn('branches', 'geofencing_enabled')) {
                $table->dropColumn('geofencing_enabled');
            }
            if (Schema::hasColumn('branches', 'geofencing_radius_meters')) {
                $table->dropColumn('geofencing_radius_meters');
            }
        });
    }
};
