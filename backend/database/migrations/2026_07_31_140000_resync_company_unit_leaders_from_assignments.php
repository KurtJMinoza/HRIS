<?php

use App\Models\OrganizationUnit;
use App\Services\LegacyOrganizationMirrorService;
use App\Services\OrganizationLeadershipService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_units') || ! Schema::hasTable('organization_position_assignments')) {
            return;
        }

        $mirror = app(LegacyOrganizationMirrorService::class);
        $leadership = app(OrganizationLeadershipService::class);

        OrganizationUnit::query()
            ->where('legacy_source_type', 'company')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (OrganizationUnit $unit) use ($mirror, $leadership): void {
                $legacyId = (int) ($unit->legacy_source_id ?? 0);
                if ($legacyId <= 0) {
                    return;
                }

                $mirror->syncLegacyPrimaryHead('company', $legacyId, $unit);
                $leadership->syncUnitLeadersFromAssignments($unit->fresh());
            });
    }

    public function down(): void
    {
        // Data repair only — no rollback.
    }
};
