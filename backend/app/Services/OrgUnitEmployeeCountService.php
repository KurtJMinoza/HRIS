<?php

namespace App\Services;

use App\Contracts\OrgUnitEmployeeCounter;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Roster employee counts for Division and Section/Unit org modules.
 *
 * Hierarchy: Company → Branch → Division → Department → Section/Unit.
 */
class OrgUnitEmployeeCountService implements OrgUnitEmployeeCounter
{
    public function __construct(
        private readonly SectionUnitRosterService $sectionUnitRoster,
    ) {}
    /**
     * @return Builder<User>
     */
    public function divisionMembersQuery(int $divisionId): Builder
    {
        if ($divisionId <= 0) {
            return $this->rosterQuery()->whereRaw('1 = 0');
        }

        $departmentIds = Department::query()
            ->where('division_id', $divisionId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        $sectionIds = SectionUnit::query()
            ->where('division_id', $divisionId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        return $this->rosterQuery()->where(function (Builder $query) use ($divisionId, $departmentIds, $sectionIds): void {
            $query->where('division_id', $divisionId);

            if ($departmentIds !== []) {
                $query->orWhereIn('department_id', $departmentIds);
            }

            if ($sectionIds !== []) {
                $query->orWhereIn('section_unit_id', $sectionIds);
            }

            $sharedIds = $this->sharedAssignmentEmployeeIdsForDivision($divisionId, $departmentIds, $sectionIds);
            if ($sharedIds !== []) {
                $query->orWhereIn('id', $sharedIds);
            }
        });
    }

    /**
     * @param  list<int>  $departmentIds
     * @param  list<int>  $sectionIds
     * @return list<int>
     */
    private function sharedAssignmentEmployeeIdsForDivision(int $divisionId, array $departmentIds, array $sectionIds): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('employee_organization_assignments')) {
            return [];
        }

        return EmployeeOrganizationAssignment::query()
            ->active()
            ->where(function (Builder $query) use ($divisionId, $departmentIds, $sectionIds): void {
                $query->where('division_id', $divisionId);
                if ($departmentIds !== []) {
                    $query->orWhereIn('department_id', $departmentIds);
                }
                if ($sectionIds !== []) {
                    $query->orWhereIn('section_unit_id', $sectionIds);
                }
            })
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function forDivision(Division $division): array
    {
        $assigned = $this->assignedDivisionCount((int) $division->id);
        $branch = $division->branch_id
            ? $this->branchEmployeeCount((int) $division->branch_id)
            : 0;
        $unassigned = $division->branch_id
            ? $this->unassignedToDivisionCount((int) $division->id, (int) $division->branch_id)
            : 0;

        return $this->formatDivisionCounts($assigned, $branch, $unassigned);
    }

    /**
     * @return array{
     *   assigned_employee_count: int,
     *   department_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }
     */
    public function forSectionUnit(SectionUnit $section): array
    {
        return $this->sectionUnitRoster->countsForSection($section);
    }

    /**
     * @param  Collection<int, Division>  $divisions
     * @return array<int, array{assigned_employee_count: int, branch_employee_count: int, unassigned_employee_count: int, total_employees: int}>
     */
    public function forDivisions(Collection $divisions): array
    {
        if ($divisions->isEmpty()) {
            return [];
        }

        $divisionIds = $divisions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $branchIds = $divisions->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        $branchCounts = $branchIds !== []
            ? $this->groupedCount(
                $this->rosterQuery()->whereIn('branch_id', $branchIds),
                'branch_id'
            )
            : [];

        $unassignedByDivision = $this->unassignedToDivisionCountsBulk($divisionIds);

        $result = [];
        foreach ($divisions as $division) {
            $id = (int) $division->id;
            $branchId = $division->branch_id ? (int) $division->branch_id : null;
            $assigned = $this->assignedDivisionCount($id);
            $branch = $branchId !== null ? (int) ($branchCounts[$branchId] ?? 0) : 0;
            $unassigned = (int) ($unassignedByDivision[$id] ?? 0);
            $result[$id] = $this->formatDivisionCounts($assigned, $branch, $unassigned);
        }

        return $result;
    }

    /**
     * @param  Collection<int, SectionUnit>  $sections
     * @return array<int, array{assigned_employee_count: int, department_employee_count: int, unassigned_employee_count: int, total_employees: int}>
     */
    public function forSectionUnits(Collection $sections): array
    {
        return $this->sectionUnitRoster->countsForSections($sections);
    }

    private function rosterQuery(): Builder
    {
        return User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
    }

    private function assignedDivisionCount(int $divisionId): int
    {
        return (int) $this->divisionMembersQuery($divisionId)->count();
    }

    private function assignedSectionCount(int $sectionId): int
    {
        return (int) $this->sectionUnitRoster->sectionMembersQuery($sectionId)->count();
    }

    private function departmentEmployeeCount(int $departmentId): int
    {
        return (int) $this->rosterQuery()->where('department_id', $departmentId)->count();
    }

    private function branchEmployeeCount(int $branchId): int
    {
        return (int) $this->rosterQuery()->where('branch_id', $branchId)->count();
    }

    private function unassignedToDivisionCount(int $divisionId, int $branchId): int
    {
        return (int) $this->rosterQuery()
            ->where('branch_id', $branchId)
            ->where(function (Builder $query) use ($divisionId): void {
                $query->whereNull('division_id')
                    ->orWhere('division_id', '!=', $divisionId);
            })
            ->count();
    }

    private function unassignedToSectionInDivisionCount(int $sectionId, int $divisionId): int
    {
        return (int) $this->rosterQuery()
            ->where('division_id', $divisionId)
            ->where(function (Builder $query) use ($sectionId): void {
                $query->whereNull('section_unit_id')
                    ->orWhere('section_unit_id', '!=', $sectionId);
            })
            ->count();
    }

    private function unassignedToSectionInDepartmentCount(int $sectionId, int $departmentId): int
    {
        return (int) $this->rosterQuery()
            ->where('department_id', $departmentId)
            ->where(function (Builder $query) use ($sectionId): void {
                $query->whereNull('section_unit_id')
                    ->orWhere('section_unit_id', '!=', $sectionId);
            })
            ->count();
    }

    /**
     * @param  list<int>  $divisionIds
     * @return array<int, int>
     */
    private function unassignedToDivisionCountsBulk(array $divisionIds): array
    {
        if ($divisionIds === []) {
            return [];
        }

        $rows = DB::table('divisions as d')
            ->join('users as u', function ($join): void {
                $join->on('u.branch_id', '=', 'd.branch_id')
                    ->whereIn('u.role', User::ROSTER_ELIGIBLE_ROLES);
            })
            ->whereIn('d.id', $divisionIds)
            ->where(function ($query): void {
                $query->whereNull('u.division_id')
                    ->orWhereColumn('u.division_id', '!=', 'd.id');
            })
            ->groupBy('d.id')
            ->selectRaw('d.id as division_id, COUNT(*) as aggregate_count')
            ->pluck('aggregate_count', 'division_id');

        return $rows->map(fn ($count) => (int) $count)->all();
    }

    /**
     * @param  Collection<int, SectionUnit>  $sections
     * @return array<int, int>
     */
    private function unassignedToSectionCountsBulk(Collection $sections): array
    {
        $result = [];

        foreach ($sections as $section) {
            $id = (int) $section->id;
            if ($section->department_id) {
                $result[$id] = $this->unassignedToSectionInDepartmentCount($id, (int) $section->department_id);
            } elseif ($section->division_id) {
                $result[$id] = $this->unassignedToSectionInDivisionCount($id, (int) $section->division_id);
            } else {
                $result[$id] = 0;
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function groupedCount(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($column.' as group_key, COUNT(*) as aggregate_count')
            ->groupBy($column)
            ->pluck('aggregate_count', 'group_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @return array{assigned_employee_count: int, branch_employee_count: int, unassigned_employee_count: int, total_employees: int}
     */
    private function formatDivisionCounts(int $assigned, int $branch, int $unassigned): array
    {
        return [
            'assigned_employee_count' => $assigned,
            'branch_employee_count' => $branch,
            'department_employee_count' => $branch,
            'unassigned_employee_count' => $unassigned,
            'total_employees' => $assigned,
        ];
    }

    /**
     * @return array{assigned_employee_count: int, department_employee_count: int, division_employee_count: int, unassigned_employee_count: int, total_employees: int}
     */
    private function formatSectionCounts(int $assigned, int $pool, int $unassigned): array
    {
        return [
            'assigned_employee_count' => $assigned,
            'department_employee_count' => $pool,
            'division_employee_count' => $pool,
            'unassigned_employee_count' => $unassigned,
            'total_employees' => $assigned,
        ];
    }
}
