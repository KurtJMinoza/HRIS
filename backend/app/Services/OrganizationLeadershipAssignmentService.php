<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves org leadership from legacy head columns and flexible position assignments.
 * Supports multiple heads per unit and cross-company (shared) leadership.
 */
class OrganizationLeadershipAssignmentService
{
    /**
     * @return Collection<int, int>
     */
    public function companyIdsLedBy(User $user): Collection
    {
        $ids = Company::query()->where('company_head_id', $user->id)->pluck('id');

        return $this->mergeLegacySourceIds($ids, $user, 'company');
    }

    /**
     * @return Collection<int, int>
     */
    public function branchIdsLedBy(User $user): Collection
    {
        $ids = Branch::query()->where('branch_manager_id', $user->id)->pluck('id');

        return $this->mergeLegacySourceIds($ids, $user, 'branch');
    }

    /**
     * @return Collection<int, int>
     */
    public function divisionIdsLedBy(User $user): Collection
    {
        $ids = Division::query()->where('division_head_id', $user->id)->pluck('id');

        return $this->mergeLegacySourceIds($ids, $user, 'division');
    }

    /**
     * @return Collection<int, int>
     */
    public function departmentIdsLedBy(User $user): Collection
    {
        $ids = Department::query()->where('department_head_id', $user->id)->pluck('id');

        return $this->mergeLegacySourceIds($ids, $user, 'department');
    }

    /**
     * @return Collection<int, int>
     */
    public function sectionUnitIdsLedBy(User $user): Collection
    {
        $ids = SectionUnit::query()->where('section_unit_head_id', $user->id)->pluck('id');

        if (Schema::hasColumn('sections_or_units', 'head_employee_id')) {
            $ids = $ids->merge(SectionUnit::query()->where('head_employee_id', $user->id)->pluck('id'));
        }

        if (Schema::hasColumn('sections_or_units', 'team_leader_id')) {
            $ids = $ids->merge(SectionUnit::query()->where('team_leader_id', $user->id)->pluck('id'));
        }

        if (Schema::hasTable('section_unit_leaders')) {
            $ids = $ids->merge(
                DB::table('section_unit_leaders')
                    ->where('employee_id', (int) $user->id)
                    ->pluck('section_unit_id')
            );
        }

        if (Schema::hasTable('section_unit_team_leaders')) {
            $ids = $ids->merge(
                DB::table('section_unit_team_leaders')
                    ->where('employee_id', (int) $user->id)
                    ->pluck('section_unit_id')
            );
        }

        if (Schema::hasTable('organization_leadership_assignments')) {
            $leadership = DB::table('organization_leadership_assignments')
                ->where('employee_id', (int) $user->id);

            if (Schema::hasColumn('organization_leadership_assignments', 'organization_type')) {
                $leadership->where('organization_type', 'section_unit');
            }
            if (Schema::hasColumn('organization_leadership_assignments', 'can_approve')) {
                $leadership->where('can_approve', true);
            }
            if (Schema::hasColumn('organization_leadership_assignments', 'is_active')) {
                $leadership->where('is_active', true);
            }

            $sectionColumn = Schema::hasColumn('organization_leadership_assignments', 'section_unit_id')
                ? 'section_unit_id'
                : (Schema::hasColumn('organization_leadership_assignments', 'organization_id') ? 'organization_id' : null);

            if ($sectionColumn !== null) {
                $ids = $ids->merge($leadership->pluck($sectionColumn));
            }
        }

        return $this->mergeLegacySourceIds($ids, $user, 'section_unit');
    }

    public function leadsAnyUnit(User $user): bool
    {
        return $this->companyIdsLedBy($user)->isNotEmpty()
            || $this->branchIdsLedBy($user)->isNotEmpty()
            || $this->divisionIdsLedBy($user)->isNotEmpty()
            || $this->departmentIdsLedBy($user)->isNotEmpty()
            || $this->sectionUnitIdsLedBy($user)->isNotEmpty()
            || $user->teamLeaderSections()->exists()
            || $user->teamLeaderDepartments()->exists();
    }

    public function assertEligibleHeadCandidate(int $employeeId): void
    {
        if (! User::query()->activeRoster()->whereKey($employeeId)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'employee_id' => ['The selected employee must be active.'],
            ]);
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function mergeLegacySourceIds(Collection $legacyColumnIds, User $user, string $legacyType): Collection
    {
        return $legacyColumnIds
            ->merge($this->legacySourceIdsFromFlexibleAssignments($user, $legacyType))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function legacySourceIdsFromFlexibleAssignments(User $user, string $legacyType): Collection
    {
        if (! Schema::hasTable('organization_units')) {
            return collect();
        }

        $fromUnits = collect();

        if (Schema::hasTable('organization_position_assignments')) {
            $unitIds = OrganizationPositionAssignment::query()
                ->active()
                ->where('employee_id', (int) $user->id)
                ->whereHas('positionType', fn ($query) => $query->where('can_approve', true))
                ->pluck('organization_unit_id');

            if ($unitIds->isNotEmpty()) {
                $fromUnits = $fromUnits->merge(
                    OrganizationUnit::query()
                        ->whereIn('id', $unitIds->all())
                        ->where('legacy_source_type', $legacyType)
                        ->pluck('legacy_source_id')
                );
            }
        }

        if (Schema::hasTable('organization_unit_leaders')) {
            $leaderUnitIds = OrganizationUnitLeader::query()
                ->where('employee_id', (int) $user->id)
                ->where('is_active', true)
                ->pluck('organization_unit_id');

            if ($leaderUnitIds->isNotEmpty()) {
                $fromUnits = $fromUnits->merge(
                    OrganizationUnit::query()
                        ->whereIn('id', $leaderUnitIds->all())
                        ->where('legacy_source_type', $legacyType)
                        ->pluck('legacy_source_id')
                );
            }
        }

        return $fromUnits;
    }
}
