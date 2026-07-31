<?php

use App\Models\OrganizationUnit;
use App\Services\OrganizationLeadershipService;
use App\Support\CompanyLeadershipPosition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_position_types')) {
            return;
        }

        $retiredIds = [];

        DB::table('organization_position_types')
            ->where('organization_level', 'section_unit')
            ->orderBy('id')
            ->each(function ($row) use (&$retiredIds): void {
                $name = (string) $row->position_name;
                if (! CompanyLeadershipPosition::isRetiredAssignableType('section_unit', $name)) {
                    return;
                }

                $retiredIds[] = (int) $row->id;
                DB::table('organization_position_types')
                    ->where('id', (int) $row->id)
                    ->update(['is_active' => false]);
            });

        if ($retiredIds === [] || ! Schema::hasTable('organization_position_assignments')) {
            return;
        }

        DB::table('organization_position_assignments')
            ->whereIn('position_type_id', $retiredIds)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if (! Schema::hasTable('organization_units')) {
            return;
        }

        $unitIds = DB::table('organization_position_assignments')
            ->whereIn('position_type_id', $retiredIds)
            ->distinct()
            ->pluck('organization_unit_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($unitIds === []) {
            return;
        }

        $leadership = app(OrganizationLeadershipService::class);
        foreach ($unitIds as $unitId) {
            $unit = OrganizationUnit::query()->find($unitId);
            if ($unit) {
                $leadership->syncUnitLeadersFromAssignments($unit);
            }
        }
    }

    public function down(): void
    {
        // Data repair only — no rollback.
    }
};
