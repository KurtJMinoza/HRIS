<?php

use App\Models\OrganizationUnit;
use App\Services\LegacyOrganizationMirrorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_units') || ! Schema::hasTable('companies')) {
            return;
        }

        $mirror = app(LegacyOrganizationMirrorService::class);

        OrganizationUnit::query()
            ->where('legacy_source_type', 'company')
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (OrganizationUnit $unit) use ($mirror): void {
                $legacyId = (int) ($unit->legacy_source_id ?? 0);
                if ($legacyId <= 0) {
                    return;
                }

                $mirror->syncLegacyPrimaryHead('company', $legacyId, $unit);
            });
    }

    public function down(): void
    {
        // Data repair only — no rollback.
    }
};
