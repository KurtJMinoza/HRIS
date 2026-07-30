<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SectionUnitRosterService
{
    /** @var list<string> */
    public const SUPPLEMENTAL_ASSIGNMENT_TYPES = [
        EmployeeOrganizationAssignment::TYPE_SHARED,
        EmployeeOrganizationAssignment::TYPE_TEMPORARY,
        EmployeeOrganizationAssignment::TYPE_ACTING,
    ];

    public function sectionMembersQuery(int $sectionUnitId): Builder
    {
        if ($sectionUnitId <= 0) {
            return User::query()->whereRaw('1 = 0');
        }

        $supplementalIds = $this->supplementalAssignmentEmployeeIds($sectionUnitId);

        return User::query()
            ->visibleEmployees()
            ->where(function (Builder $query) use ($sectionUnitId, $supplementalIds): void {
                $query->where('section_unit_id', $sectionUnitId);
                if ($supplementalIds !== []) {
                    $query->orWhereIn('id', $supplementalIds);
                }
            });
    }

    /**
     * @return array{
     *   assigned_employee_count: int,
     *   primary_employee_count: int,
     *   shared_employee_count: int,
     *   temporary_employee_count: int,
     *   acting_employee_count: int,
     *   department_employee_count: int,
     *   division_employee_count: int,
     *   unassigned_employee_count: int,
     *   total_employees: int
     * }
     */
    public function countsForSection(SectionUnit $section, ?int $departmentPool = null, ?int $unassigned = null): array
    {
        $sectionId = (int) $section->id;
        $roster = $this->rosterForSection($section);
        $bySource = collect($roster)->countBy(fn (array $row) => $row['source']);

        $departmentPool = $departmentPool ?? ($section->department_id
            ? (int) User::query()->visibleEmployees()->where('department_id', (int) $section->department_id)->count()
            : 0);

        $unassigned = $unassigned ?? $this->unassignedToSectionCount($section);

        return [
            'assigned_employee_count' => count($roster),
            'primary_employee_count' => (int) ($bySource[EmployeeOrganizationAssignment::TYPE_PRIMARY] ?? 0),
            'shared_employee_count' => (int) ($bySource[EmployeeOrganizationAssignment::TYPE_SHARED] ?? 0),
            'temporary_employee_count' => (int) ($bySource[EmployeeOrganizationAssignment::TYPE_TEMPORARY] ?? 0),
            'acting_employee_count' => (int) ($bySource[EmployeeOrganizationAssignment::TYPE_ACTING] ?? 0),
            'department_employee_count' => $departmentPool,
            'division_employee_count' => $departmentPool,
            'unassigned_employee_count' => $unassigned,
            'total_employees' => count($roster),
        ];
    }

    /**
     * @param  Collection<int, SectionUnit>  $sections
     * @return array<int, array<string, int>>
     */
    public function countsForSections(Collection $sections): array
    {
        if ($sections->isEmpty()) {
            return [];
        }

        $sectionIds = $sections->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($sectionIds === []) {
            return [];
        }

        $departmentIds = $sections->pluck('department_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $divisionIds = $sections->pluck('division_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $departmentPools = $departmentIds !== []
            ? User::query()
                ->visibleEmployees()
                ->whereIn('department_id', $departmentIds)
                ->selectRaw('department_id as group_key, COUNT(*) as aggregate_count')
                ->groupBy('department_id')
                ->pluck('aggregate_count', 'group_key')
                ->map(fn ($count) => (int) $count)
                ->all()
            : [];

        $divisionPools = $divisionIds !== []
            ? User::query()
                ->visibleEmployees()
                ->whereIn('division_id', $divisionIds)
                ->selectRaw('division_id as group_key, COUNT(*) as aggregate_count')
                ->groupBy('division_id')
                ->pluck('aggregate_count', 'group_key')
                ->map(fn ($count) => (int) $count)
                ->all()
            : [];

        $assignedBySection = [];
        $sourceBySection = [];
        $userOrgById = [];
        foreach ($sections as $section) {
            $sectionId = (int) $section->id;
            $assignedBySection[$sectionId] = [];
            $sourceBySection[$sectionId] = [
                EmployeeOrganizationAssignment::TYPE_PRIMARY => [],
                EmployeeOrganizationAssignment::TYPE_SHARED => [],
                EmployeeOrganizationAssignment::TYPE_TEMPORARY => [],
                EmployeeOrganizationAssignment::TYPE_ACTING => [],
            ];
        }

        $primaryUsers = User::query()
            ->visibleEmployees()
            ->whereIn('section_unit_id', $sectionIds)
            ->get(['id', 'department_id', 'division_id', 'section_unit_id']);

        foreach ($primaryUsers as $user) {
            $employeeId = (int) $user->id;
            $sectionId = (int) ($user->section_unit_id ?? 0);
            if (! isset($assignedBySection[$sectionId])) {
                continue;
            }

            $userOrgById[$employeeId] = [
                'department_id' => $user->department_id ? (int) $user->department_id : null,
                'division_id' => $user->division_id ? (int) $user->division_id : null,
                'section_unit_id' => $sectionId,
            ];
            $assignedBySection[$sectionId][$employeeId] = true;
            $sourceBySection[$sectionId][EmployeeOrganizationAssignment::TYPE_PRIMARY][$employeeId] = true;
        }

        if ($this->assignmentsReady()) {
            $assignments = EmployeeOrganizationAssignment::query()
                ->active()
                ->whereIn('section_unit_id', $sectionIds)
                ->whereIn('assignment_type', self::SUPPLEMENTAL_ASSIGNMENT_TYPES)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get(['employee_id', 'section_unit_id', 'assignment_type']);

            if ($assignments->isNotEmpty()) {
                $assignmentUsers = User::query()
                    ->visibleEmployees()
                    ->whereIn('id', $assignments->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
                    ->get(['id', 'department_id', 'division_id', 'section_unit_id'])
                    ->keyBy('id');

                $supplementalSourceByEmployee = [];
                foreach ($assignments as $assignment) {
                    $employeeId = (int) $assignment->employee_id;
                    $sectionId = (int) ($assignment->section_unit_id ?? 0);
                    $user = $assignmentUsers->get($employeeId);
                    if (! $user || ! isset($assignedBySection[$sectionId])) {
                        continue;
                    }

                    $userOrgById[$employeeId] = [
                        'department_id' => $user->department_id ? (int) $user->department_id : null,
                        'division_id' => $user->division_id ? (int) $user->division_id : null,
                        'section_unit_id' => $user->section_unit_id ? (int) $user->section_unit_id : null,
                    ];
                    $assignedBySection[$sectionId][$employeeId] = true;

                    if ((int) ($user->section_unit_id ?? 0) === $sectionId) {
                        continue;
                    }

                    $supplementalKey = $sectionId.':'.$employeeId;
                    if (isset($supplementalSourceByEmployee[$supplementalKey])) {
                        continue;
                    }

                    $type = in_array($assignment->assignment_type, self::SUPPLEMENTAL_ASSIGNMENT_TYPES, true)
                        ? (string) $assignment->assignment_type
                        : EmployeeOrganizationAssignment::TYPE_SHARED;
                    $supplementalSourceByEmployee[$supplementalKey] = $type;
                    $sourceBySection[$sectionId][$type][$employeeId] = true;
                }
            }
        }

        $result = [];
        foreach ($sections as $section) {
            $sectionId = (int) $section->id;
            $departmentId = $section->department_id ? (int) $section->department_id : null;
            $divisionId = $section->division_id ? (int) $section->division_id : null;
            $pool = $departmentId !== null
                ? (int) ($departmentPools[$departmentId] ?? 0)
                : ($divisionId !== null ? (int) ($divisionPools[$divisionId] ?? 0) : 0);

            $assignedInPool = 0;
            foreach (array_keys($assignedBySection[$sectionId] ?? []) as $employeeId) {
                $org = $userOrgById[(int) $employeeId] ?? null;
                if (! $org) {
                    continue;
                }
                if ($departmentId !== null && (int) ($org['department_id'] ?? 0) === $departmentId) {
                    $assignedInPool++;
                } elseif ($departmentId === null && $divisionId !== null && (int) ($org['division_id'] ?? 0) === $divisionId) {
                    $assignedInPool++;
                }
            }

            $assignedCount = count($assignedBySection[$sectionId] ?? []);
            $result[$sectionId] = [
                'assigned_employee_count' => $assignedCount,
                'primary_employee_count' => count($sourceBySection[$sectionId][EmployeeOrganizationAssignment::TYPE_PRIMARY] ?? []),
                'shared_employee_count' => count($sourceBySection[$sectionId][EmployeeOrganizationAssignment::TYPE_SHARED] ?? []),
                'temporary_employee_count' => count($sourceBySection[$sectionId][EmployeeOrganizationAssignment::TYPE_TEMPORARY] ?? []),
                'acting_employee_count' => count($sourceBySection[$sectionId][EmployeeOrganizationAssignment::TYPE_ACTING] ?? []),
                'department_employee_count' => $pool,
                'division_employee_count' => $pool,
                'unassigned_employee_count' => max(0, $pool - $assignedInPool),
                'total_employees' => $assignedCount,
            ];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rosterForSection(SectionUnit $section): array
    {
        $sectionId = (int) $section->id;
        $section->loadMissing(['company:id,name', 'branch:id,name', 'department:id,name', 'division:id,name']);

        $users = $this->sectionMembersQuery($sectionId)
            ->with([
                'company:id,name',
                'branch:id,name,company_id',
                'branch.company:id,name',
                'departmentRelation:id,name,branch_id',
                'departmentRelation.branch:id,name,company_id',
                'departmentRelation.branch.company:id,name',
                'division:id,name',
                'sectionUnit:id,name',
            ])
            ->orderByLastName()
            ->get();

        if ($users->isEmpty()) {
            return [];
        }

        $assignmentsByEmployee = $this->activeAssignmentsByEmployeeForSection($sectionId, $users->pluck('id')->map(fn ($id) => (int) $id)->all());

        $rows = [];
        foreach ($users as $user) {
            $rows[] = $this->formatRosterRow($user, $section, $assignmentsByEmployee[(int) $user->id] ?? collect());
        }

        return $rows;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<int, Collection<int, EmployeeOrganizationAssignment>>
     */
    private function activeAssignmentsByEmployeeForSection(int $sectionId, array $employeeIds): array
    {
        if (! $this->assignmentsReady() || $employeeIds === []) {
            return [];
        }

        $rows = EmployeeOrganizationAssignment::query()
            ->active()
            ->where('section_unit_id', $sectionId)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $map[$employeeId] ??= collect();
            $map[$employeeId]->push($row);
        }

        return $map;
    }

    /**
     * @param  Collection<int, EmployeeOrganizationAssignment>  $assignmentsAtSection
     * @return array<string, mixed>
     */
    private function formatRosterRow(User $user, SectionUnit $section, Collection $assignmentsAtSection): array
    {
        $sectionId = (int) $section->id;
        $isPrimaryFk = (int) ($user->section_unit_id ?? 0) === $sectionId;

        $source = EmployeeOrganizationAssignment::TYPE_PRIMARY;
        $assignmentRow = null;

        if ($isPrimaryFk) {
            $source = EmployeeOrganizationAssignment::TYPE_PRIMARY;
        } elseif ($assignmentsAtSection->isNotEmpty()) {
            $assignmentRow = $assignmentsAtSection->first();
            $source = (string) ($assignmentRow->assignment_type ?: EmployeeOrganizationAssignment::TYPE_SHARED);
        }

        $originalPath = $this->orgPathForUser($user);
        $assignedPath = $this->orgPathForSection($section);

        return [
            'id' => (int) $user->id,
            'employee_id' => (int) $user->id,
            'name' => $user->display_name,
            'employee_name' => $user->display_name,
            'formatted_name' => $user->formatted_name,
            'profile_image' => $user->profile_image_url,
            'source' => $source,
            'assignment_type' => $source,
            'original_company' => $this->originalCompanyName($user),
            'original_department' => $user->departmentRelation?->name ?? $user->department,
            'original_org_path' => $originalPath,
            'assigned_company' => $section->company?->name,
            'assigned_section_unit' => $section->name,
            'assigned_org_path' => $assignedPath,
            'effective_from' => $assignmentRow?->effective_from?->toDateString(),
            'effective_to' => $assignmentRow?->effective_to?->toDateString(),
            'status' => $user->is_active ? 'active' : 'inactive',
            'is_active' => (bool) $user->is_active,
            'assignment_id' => $assignmentRow ? (int) $assignmentRow->id : null,
        ];
    }

    private function originalCompanyName(User $user): ?string
    {
        if ($user->company?->name) {
            return $user->company->name;
        }

        return $user->branch?->company?->name
            ?? $user->departmentRelation?->branch?->company?->name;
    }

    private function orgPathForUser(User $user): string
    {
        return implode(' > ', array_values(array_filter([
            $this->originalCompanyName($user),
            $user->branch?->name,
            $user->division?->name,
            $user->departmentRelation?->name ?? $user->department,
            $user->sectionUnit?->name,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
    }

    private function orgPathForSection(SectionUnit $section): string
    {
        return implode(' > ', array_values(array_filter([
            $section->company?->name,
            $section->branch?->name,
            $section->division?->name,
            $section->department?->name,
            $section->name,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
    }

    /**
     * @return list<int>
     */
    private function supplementalAssignmentEmployeeIds(int $sectionUnitId): array
    {
        if (! $this->assignmentsReady()) {
            return [];
        }

        return EmployeeOrganizationAssignment::query()
            ->active()
            ->where('section_unit_id', $sectionUnitId)
            ->whereIn('assignment_type', self::SUPPLEMENTAL_ASSIGNMENT_TYPES)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function unassignedToSectionCount(SectionUnit $section): int
    {
        $sectionId = (int) $section->id;
        $assignedIds = $this->sectionMembersQuery($sectionId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $poolQuery = User::query()->visibleEmployees();
        if ($section->department_id) {
            $poolQuery->where('department_id', (int) $section->department_id);
        } elseif ($section->division_id) {
            $poolQuery->where('division_id', (int) $section->division_id);
        } else {
            return 0;
        }

        if ($assignedIds === []) {
            return (int) $poolQuery->count();
        }

        return (int) $poolQuery->whereNotIn('id', $assignedIds)->count();
    }

    private function assignmentsReady(): bool
    {
        return Schema::hasTable('employee_organization_assignments');
    }
}
