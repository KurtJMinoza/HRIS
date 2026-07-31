<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Area;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\User;
use App\Support\CompanyLeadershipPosition;
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
        $ids = Company::query()
            ->where('company_head_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $companyId): bool => $this->legacyCompanyHeadColumnCountsForUser($user, $companyId));

        return $this->mergeLegacySourceIds(
            $ids,
            $user,
            'company',
            fn (OrganizationPositionAssignment $assignment, string $name): bool => CompanyLeadershipPosition::isCompanyHead($name),
        )->filter(fn (int $companyId): bool => $this->userCountsAsCompanyHeadForCompany($user, $companyId))->values();
    }

    /**
     * @return Collection<int, int>
     */
    public function companyIdsWhereOfficerInCharge(User $user): Collection
    {
        return $this->mergeLegacySourceIds(
            collect(),
            $user,
            'company',
            fn (OrganizationPositionAssignment $assignment, string $name): bool => CompanyLeadershipPosition::isOfficerInCharge($name),
        );
    }

    /**
     * Primary Officer in Charge per company (flexible assignment), keyed by company id.
     *
     * @param  list<int>  $companyIds
     * @return Collection<int, User>
     */
    public function officerInChargeByCompanyIds(array $companyIds): Collection
    {
        $companyIds = collect($companyIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($companyIds->isEmpty()
            || ! Schema::hasTable('organization_units')
            || ! Schema::hasTable('organization_position_assignments')) {
            return collect();
        }

        $units = OrganizationUnit::query()
            ->where('legacy_source_type', 'company')
            ->whereIn('legacy_source_id', $companyIds->all())
            ->where('is_active', true)
            ->get(['id', 'legacy_source_id']);

        if ($units->isEmpty()) {
            return collect();
        }

        $unitToCompany = $units->mapWithKeys(
            fn (OrganizationUnit $unit) => [(int) $unit->id => (int) $unit->legacy_source_id],
        );

        $assignments = OrganizationPositionAssignment::query()
            ->active()
            ->whereIn('organization_unit_id', $units->pluck('id')->all())
            ->whereHas('positionType', fn ($query) => $query->where('can_approve', true)->where('is_active', true))
            ->with('positionType')
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->filter(fn (OrganizationPositionAssignment $assignment): bool => CompanyLeadershipPosition::isOfficerInCharge(
                (string) ($assignment->positionType?->position_name ?? ''),
            ));

        $employeeIds = $assignments->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values();
        $users = $employeeIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $employeeIds->all())
                ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_image', 'email'])
                ->keyBy(fn (User $user) => (int) $user->id);

        $byCompany = collect();
        foreach ($assignments as $assignment) {
            $companyId = (int) ($unitToCompany[(int) $assignment->organization_unit_id] ?? 0);
            if ($companyId <= 0 || $byCompany->has($companyId)) {
                continue;
            }

            $user = $users->get((int) $assignment->employee_id);
            if ($user) {
                $byCompany->put($companyId, $user);
            }
        }

        return $byCompany;
    }

    public function hasCompanyLeadershipAssignment(User $user, int $companyId, bool $officerInCharge): bool
    {
        if ($companyId <= 0 || ! Schema::hasTable('organization_position_assignments')) {
            return false;
        }

        $unitIds = OrganizationUnit::query()
            ->where('legacy_source_type', 'company')
            ->where('legacy_source_id', $companyId)
            ->where('is_active', true)
            ->pluck('id');

        if ($unitIds->isEmpty()) {
            return false;
        }

        return OrganizationPositionAssignment::query()
            ->active()
            ->where('employee_id', (int) $user->id)
            ->whereIn('organization_unit_id', $unitIds->all())
            ->whereHas('positionType', fn ($query) => $query->where('can_approve', true)->where('is_active', true))
            ->with('positionType')
            ->get()
            ->contains(function (OrganizationPositionAssignment $assignment) use ($officerInCharge): bool {
                $name = (string) ($assignment->positionType?->position_name ?? '');

                return $officerInCharge
                    ? CompanyLeadershipPosition::isOfficerInCharge($name)
                    : CompanyLeadershipPosition::isCompanyHead($name);
            });
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
    public function areaIdsLedBy(User $user): Collection
    {
        $ids = Area::query()->where('area_manager_employee_id', $user->id)->pluck('id');

        return $this->mergeLegacySourceIds($ids, $user, 'area');
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
            || $this->companyIdsWhereOfficerInCharge($user)->isNotEmpty()
            || $this->areaIdsLedBy($user)->isNotEmpty()
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
     * @param  Collection<int, int>  $legacyColumnIds
     * @param  (callable(OrganizationPositionAssignment, string): bool)|null  $positionFilter
     * @return Collection<int, int>
     */
    private function mergeLegacySourceIds(
        Collection $legacyColumnIds,
        User $user,
        string $legacyType,
        ?callable $positionFilter = null,
    ): Collection {
        $ids = $legacyColumnIds
            ->merge($this->legacySourceIdsFromFlexibleAssignments($user, $legacyType, $positionFilter))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        return $this->filterExistingLegacyIds($ids, $legacyType);
    }

    /**
     * Drop ids that point at deleted company/branch/… rows so a removed section/unit
     * cannot keep an employee marked as its head.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function filterExistingLegacyIds(Collection $ids, string $legacyType): Collection
    {
        if ($ids->isEmpty()) {
            return $ids;
        }

        $existing = match ($legacyType) {
            'company' => Company::query()->whereIn('id', $ids->all())->pluck('id'),
            'area' => Area::query()->whereIn('id', $ids->all())->pluck('id'),
            'branch' => Branch::query()->whereIn('id', $ids->all())->pluck('id'),
            'division' => Division::query()->whereIn('id', $ids->all())->pluck('id'),
            'department' => Department::query()->whereIn('id', $ids->all())->pluck('id'),
            'section_unit' => SectionUnit::query()->whereIn('id', $ids->all())->pluck('id'),
            default => $ids,
        };

        $existingSet = $existing->map(fn ($id) => (int) $id)->all();

        return $ids->filter(fn (int $id) => in_array($id, $existingSet, true))->values();
    }

    /**
     * @param  (callable(OrganizationPositionAssignment, string): bool)|null  $positionFilter
     * @return Collection<int, int>
     */
    private function legacySourceIdsFromFlexibleAssignments(User $user, string $legacyType, ?callable $positionFilter = null): Collection
    {
        if (! Schema::hasTable('organization_units')) {
            return collect();
        }

        $fromUnits = collect();

        if (Schema::hasTable('organization_position_assignments')) {
            $assignments = OrganizationPositionAssignment::query()
                ->active()
                ->where('employee_id', (int) $user->id)
                ->whereHas('positionType', fn ($query) => $query->where('can_approve', true))
                ->with('positionType')
                ->get();

            $unitIds = $assignments
                ->filter(function (OrganizationPositionAssignment $assignment) use ($positionFilter): bool {
                    if ($positionFilter === null) {
                        return true;
                    }

                    $name = (string) ($assignment->positionType?->position_name ?? '');

                    return $positionFilter($assignment, $name);
                })
                ->pluck('organization_unit_id');

            if ($unitIds->isNotEmpty()) {
                $fromUnits = $fromUnits->merge(
                    OrganizationUnit::query()
                        ->whereIn('id', $unitIds->all())
                        ->where('legacy_source_type', $legacyType)
                        ->where('is_active', true)
                        ->pluck('legacy_source_id')
                );
            }
        }

        if (Schema::hasTable('organization_unit_leaders')) {
            $leaders = OrganizationUnitLeader::query()
                ->where('employee_id', (int) $user->id)
                ->where('is_active', true)
                ->get(['organization_unit_id', 'leader_role']);

            $leaderUnitIds = $leaders
                ->filter(function (OrganizationUnitLeader $leader) use ($positionFilter): bool {
                    if ($positionFilter === null) {
                        return true;
                    }

                    return $positionFilter(new OrganizationPositionAssignment(), (string) $leader->leader_role);
                })
                ->pluck('organization_unit_id');

            if ($leaderUnitIds->isNotEmpty()) {
                $fromUnits = $fromUnits->merge(
                    OrganizationUnit::query()
                        ->whereIn('id', $leaderUnitIds->all())
                        ->where('legacy_source_type', $legacyType)
                        ->where('is_active', true)
                        ->pluck('legacy_source_id')
                );
            }
        }

        return $fromUnits;
    }

    /**
     * User counts as Company Head for a company only when not OIC-only (no Company Head assignment).
     */
    public function userCountsAsCompanyHeadForCompany(User $user, int $companyId): bool
    {
        if ($this->hasCompanyLeadershipAssignment($user, $companyId, true)
            && ! $this->hasCompanyLeadershipAssignment($user, $companyId, false)) {
            return false;
        }

        return true;
    }

    /**
     * companies.company_head_id counts only when the user is not OIC-only on that company.
     */
    private function legacyCompanyHeadColumnCountsForUser(User $user, int $companyId): bool
    {
        return $this->userCountsAsCompanyHeadForCompany($user, $companyId);
    }
}
