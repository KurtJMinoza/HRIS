<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_geofences')) {
            Schema::table('branch_geofences', function (Blueprint $table): void {
                if (! Schema::hasColumn('branch_geofences', 'address')) {
                    $table->string('address')->nullable()->after('name');
                }
                if (! Schema::hasColumn('branch_geofences', 'description')) {
                    $table->text('description')->nullable()->after('address');
                }
                if (! Schema::hasColumn('branch_geofences', 'ownership_type')) {
                    $table->string('ownership_type', 32)->default('shared')->after('description');
                }
                if (! Schema::hasColumn('branch_geofences', 'owner_employee_id')) {
                    $table->foreignId('owner_employee_id')->nullable()->after('ownership_type')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('branch_geofences', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        if (Schema::hasTable('geofence_global_settings')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('geofence_global_settings', 'no_assignment_policy')) {
                    $table->string('no_assignment_policy', 32)->default('block')->after('employee_exemption_ids');
                }
                if (! Schema::hasColumn('geofence_global_settings', 'legacy_branch_bypass_archived_at')) {
                    $table->timestamp('legacy_branch_bypass_archived_at')->nullable()->after('no_assignment_policy');
                }
                if (! Schema::hasColumn('geofence_global_settings', 'legacy_branch_bypass_snapshot')) {
                    $table->json('legacy_branch_bypass_snapshot')->nullable()->after('legacy_branch_bypass_archived_at');
                }
            });
        }

        // ponytail: one-shot archive — branch bypass must never drive runtime again
        if (Schema::hasTable('branch_geofence_settings') && Schema::hasTable('geofence_global_settings')) {
            $bypassRows = DB::table('branch_geofence_settings')
                ->where('allow_without_geofence', true)
                ->pluck('branch_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if ($bypassRows !== []) {
                $global = DB::table('geofence_global_settings')->where('id', 1)->first();
                $snapshot = $global?->legacy_branch_bypass_snapshot;
                $decoded = is_string($snapshot) ? json_decode($snapshot, true) : (is_array($snapshot) ? $snapshot : []);
                $decoded['archived_branch_ids'] = array_values(array_unique(array_merge(
                    $decoded['archived_branch_ids'] ?? [],
                    $bypassRows,
                )));

                DB::table('geofence_global_settings')->updateOrInsert(
                    ['id' => 1],
                    [
                        'attendance_without_geofence_enabled' => false,
                        'legacy_branch_bypass_archived_at' => now(),
                        'legacy_branch_bypass_snapshot' => json_encode($decoded),
                        'updated_at' => now(),
                    ],
                );

                DB::table('branch_geofence_settings')->update(['allow_without_geofence' => false]);
            } else {
                DB::table('geofence_global_settings')->updateOrInsert(
                    ['id' => 1],
                    [
                        'attendance_without_geofence_enabled' => false,
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branch_geofences')) {
            Schema::table('branch_geofences', function (Blueprint $table): void {
                foreach (['owner_employee_id', 'ownership_type', 'description', 'address', 'deleted_at'] as $col) {
                    if (Schema::hasColumn('branch_geofences', $col)) {
                        if ($col === 'owner_employee_id') {
                            $table->dropForeign(['owner_employee_id']);
                        }
                        if ($col === 'deleted_at') {
                            $table->dropSoftDeletes();
                        } else {
                            $table->dropColumn($col);
                        }
                    }
                }
            });
        }

        if (Schema::hasTable('geofence_global_settings')) {
            Schema::table('geofence_global_settings', function (Blueprint $table): void {
                foreach (['no_assignment_policy', 'legacy_branch_bypass_archived_at', 'legacy_branch_bypass_snapshot'] as $col) {
                    if (Schema::hasColumn('geofence_global_settings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
