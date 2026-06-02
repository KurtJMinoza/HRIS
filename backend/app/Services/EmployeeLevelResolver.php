<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmployeeLevelResolver
{
    public const LEVEL_NAMES = [
        0 => 'Staff / Employee',
        1 => 'OIC / Team Leader / Unit/Section Head',
        2 => 'Department Head',
        3 => 'Division Head',
        4 => 'Branch Head',
        5 => 'Company Head / Executive',
        6 => 'Admin',
    ];

    /**
     * @param  int|User  $employee
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function resolveEmployeeLevel(int|User $employee, ?array $context = null): array
    {
        $user = $employee instanceof User ? $employee : User::query()->find($employee);

        if (! $user instanceof User) {
            return $this->payload(0, 'employee', null, null, null, null);
        }

        $context = $this->normalizeContext($context);
        $candidates = [];

        if ($this->isAdminLevel($user)) {
            $candidates[] = $this->payload(6, 'user_role', null, $this->organizationPathForUser($user), null, null);
        }

        if ((bool) ($user->is_execom ?? false)) {
            $candidates[] = $this->payload(5, 'executive_profile', null, $this->organizationPathForUser($user), null, null);
        }

        $candidates = array_merge($candidates, $this->legacyHeadCandidates($user, $context));
        $candidates = array_merge($candidates, $this->flexiblePositionCandidates($user, $context));
        $candidates = array_merge($candidates, $this->flexibleLeaderCandidates($user, $context));
        $candidates = array_merge($candidates, $this->teamLeaderCandidates($user, $context));
        $candidates = array_merge($candidates, $this->assignmentCandidates($user, $context));

        if ($candidates === []) {
            $candidates[] = $this->payload(0, 'employee_assignment', null, $this->organizationPathForUser($user), null, null);
        }

        usort($candidates, static function (array $a, array $b): int {
            return ((int) ($b['level_number'] ?? 0)) <=> ((int) ($a['level_number'] ?? 0));
        });

        return $candidates[0];
    }

    /**
     * @param  int|User  $employee
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public function syncCachedLevel(int|User $employee, string $reason = 'manual_sync', ?array $context = null): array
    {
        $user = $employee instanceof User ? $employee : User::query()->find($employee);
        if (! $user instanceof User) {
            return $this->payload(0, 'employee', null, null, null, null);
        }

        $resolved = $this->resolveEmployeeLevel($user, $context);
        $oldLevel = Schema::hasColumn('users', 'employee_level') ? $user->employee_level : null;
        $oldLabel = Schema::hasColumn('users', 'employee_level_label') ? $user->employee_level_label : null;

        if (Schema::hasColumn('users', 'employee_level')) {
            $updates = [
                'employee_level' => (int) $resolved['level_number'],
            ];
            if (Schema::hasColumn('users', 'employee_level_label')) {
                $updates['employee_level_label'] = $resolved['level_label'];
            }
            if (Schema::hasColumn('users', 'employee_level_resolved_at')) {
                $updates['employee_level_resolved_at'] = now();
            }

            User::query()->whereKey($user->id)->update($updates);
            $user->forceFill($updates);
        }

        Log::info('Employee level resolved', [
            'employee_id' => (int) $user->id,
            'old_level' => $oldLevel,
            'old_label' => $oldLabel,
            'new_level' => (int) $resolved['level_number'],
            'new_label' => $resolved['level_label'],
            'assignment_source' => $resolved['source_module'],
            'source_assignment_id' => $resolved['source_assignment_id'],
            'organization_path' => $resolved['organization_path'],
            'resolved_at' => now()->toIso8601String(),
            'reason' => $reason,
        ]);

        return $resolved;
    }

    /**
     * @return array<int, array{value:int,label:string,name:string}>
     */
    public function levelOptions(): array
    {
        return collect(self::LEVEL_NAMES)
            ->map(fn (string $name, int $level): array => [
                'value' => $level,
                'name' => $name,
                'label' => $this->labelForLevel($level),
            ])
            ->values()
            ->all();
    }

    public function labelForLevel(int $level): string
    {
        return 'Level '.$level.' - '.(self::LEVEL_NAMES[$level] ?? self::LEVEL_NAMES[0]);
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, int|null>
     */
    private function normalizeContext(?array $context): array
    {
        $keys = ['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'];
        $normalized = [];
        foreach ($keys as $key) {
            $value = $context[$key] ?? null;
            $normalized[$key] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        return $normalized;
    }

    private function isAdminLevel(User $user): bool
    {
        if ((bool) ($user->is_super_admin ?? false)) {
            return true;
        }

        $role = Str::lower((string) ($user->role ?? ''));

        return in_array($role, [
            'admin',
            'super_admin',
            'admin_hr',
            'hr_admin',
            'payroll_admin',
            'hr',
            'payroll',
        ], true);
    }

    /**
     * @param  array<string, int|null>  $context
     * @return list<array<string, mixed>>
     */
    private function legacyHeadCandidates(User $user, array $context): array
    {
        $candidates = [];

        foreach (Company::query()->where('company_head_id', $user->id)->get(['id', 'name']) as $company) {
            $source = ['company_id' => (int) $company->id];
            if ($this->matchesContext($source, $context)) {
                $candidates[] = $this->payload(5, 'company', (int) $company->id, $company->name, null, null);
            }
        }

        foreach (Branch::query()->where('branch_manager_id', $user->id)->with('company:id,name')->get(['id', 'name', 'company_id']) as $branch) {
            $source = ['company_id' => $branch->company_id, 'branch_id' => (int) $branch->id];
            if ($this->matchesContext($source, $context)) {
                $candidates[] = $this->payload(4, 'branch', (int) $branch->id, $this->path([$branch->company?->name, $branch->name]), null, null);
            }
        }

        foreach (Division::query()->where('division_head_id', $user->id)->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name'])->get() as $division) {
            $source = ['company_id' => $division->company_id ?? $division->branch?->company_id, 'branch_id' => $division->branch_id, 'division_id' => (int) $division->id];
            if ($this->matchesContext($source, $context)) {
                $candidates[] = $this->payload(3, 'division', (int) $division->id, $this->path([$division->company?->name ?? $division->branch?->company?->name, $division->branch?->name, $division->name]), null, null);
            }
        }

        foreach (Department::query()->where('department_head_id', $user->id)->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'division:id,name'])->get() as $department) {
            $source = ['company_id' => $department->company_id ?? $department->branch?->company_id, 'branch_id' => $department->branch_id, 'division_id' => $department->division_id, 'department_id' => (int) $department->id];
            if ($this->matchesContext($source, $context)) {
                $candidates[] = $this->payload(2, 'department', (int) $department->id, $this->path([$department->company?->name ?? $department->branch?->company?->name, $department->branch?->name, $department->division?->name, $department->name]), null, null);
            }
        }

        foreach (SectionUnit::query()->where('section_unit_head_id', $user->id)->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'division:id,name,company_id,branch_id', 'department:id,name,branch_id,division_id'])->get() as $section) {
            $source = ['company_id' => $section->company_id ?? $section->branch?->company_id ?? $section->division?->company_id, 'branch_id' => $section->branch_id ?? $section->division?->branch_id ?? $section->department?->branch_id, 'division_id' => $section->division_id ?? $section->department?->division_id, 'department_id' => $section->department_id, 'section_unit_id' => (int) $section->id];
            if ($this->matchesContext($source, $context)) {
                $candidates[] = $this->payload(1, 'section_unit', (int) $section->id, $this->path([$section->company?->name ?? $section->branch?->company?->name, $section->branch?->name, $section->division?->name, $section->department?->name, $section->name]), null, null);
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, int|null>  $context
     * @return list<array<string, mixed>>
     */
    private function flexiblePositionCandidates(User $user, array $context): array
    {
        if (! Schema::hasTable('organization_position_assignments')) {
            return [];
        }

        return OrganizationPositionAssignment::query()
            ->active()
            ->where('employee_id', $user->id)
            ->with(['positionType:id,organization_level,position_name,is_active', 'organizationUnit:id,name,parent_id,company_id,legacy_source_type,legacy_source_id,is_active'])
            ->get()
            ->map(function (OrganizationPositionAssignment $assignment) use ($context): ?array {
                $unit = $assignment->organizationUnit;
                if (! $unit || ! (bool) $unit->is_active || ! $assignment->positionType?->is_active) {
                    return null;
                }

                $source = $this->sourceFromUnit($unit);
                if (! $this->matchesContext($source, $context)) {
                    return null;
                }

                $level = $this->levelFromOrganizationPosition(
                    (string) $assignment->organization_level,
                    (string) $assignment->positionType->position_name,
                    $unit
                );

                return $this->payload(
                    $level,
                    'organization_position_assignment',
                    (int) $assignment->id,
                    $this->organizationUnitPath($unit),
                    $assignment->effective_from?->toDateString(),
                    $assignment->effective_to?->toDateString(),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|null>  $context
     * @return list<array<string, mixed>>
     */
    private function flexibleLeaderCandidates(User $user, array $context): array
    {
        if (! Schema::hasTable('organization_unit_leaders')) {
            return [];
        }

        return OrganizationUnitLeader::query()
            ->active()
            ->where('employee_id', $user->id)
            ->with('organizationUnit:id,name,parent_id,company_id,legacy_source_type,legacy_source_id,is_active')
            ->get()
            ->map(function (OrganizationUnitLeader $leader) use ($context): ?array {
                $unit = $leader->organizationUnit;
                if (! $unit || ! (bool) $unit->is_active) {
                    return null;
                }
                $source = $this->sourceFromUnit($unit);
                if (! $this->matchesContext($source, $context)) {
                    return null;
                }

                $level = $this->levelFromOrganizationPosition(
                    (string) ($unit->legacy_source_type ?? ''),
                    (string) $leader->leader_role,
                    $unit
                );

                return $this->payload($level, 'organization_unit_leader', (int) $leader->id, $this->organizationUnitPath($unit), null, null);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|null>  $context
     * @return list<array<string, mixed>>
     */
    private function teamLeaderCandidates(User $user, array $context): array
    {
        $candidates = [];

        if (Schema::hasTable('department_team_leaders')) {
            foreach (Department::query()->whereIn('id', DB::table('department_team_leaders')->where('employee_id', $user->id)->pluck('department_id'))->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'division:id,name'])->get() as $department) {
                $source = ['company_id' => $department->company_id ?? $department->branch?->company_id, 'branch_id' => $department->branch_id, 'division_id' => $department->division_id, 'department_id' => (int) $department->id];
                if ($this->matchesContext($source, $context)) {
                    $candidates[] = $this->payload(1, 'department_team_leader', (int) $department->id, $this->path([$department->company?->name ?? $department->branch?->company?->name, $department->branch?->name, $department->division?->name, $department->name]), null, null);
                }
            }
        }

        if (Schema::hasTable('section_unit_team_leaders')) {
            foreach (SectionUnit::query()->whereIn('id', DB::table('section_unit_team_leaders')->where('employee_id', $user->id)->pluck('section_unit_id'))->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'division:id,name', 'department:id,name'])->get() as $section) {
                $source = ['company_id' => $section->company_id ?? $section->branch?->company_id ?? $section->division?->company_id, 'branch_id' => $section->branch_id ?? $section->division?->branch_id ?? $section->department?->branch_id, 'division_id' => $section->division_id ?? $section->department?->division_id, 'department_id' => $section->department_id, 'section_unit_id' => (int) $section->id];
                if ($this->matchesContext($source, $context)) {
                    $candidates[] = $this->payload(1, 'section_unit_team_leader', (int) $section->id, $this->path([$section->company?->name ?? $section->branch?->company?->name, $section->branch?->name, $section->division?->name, $section->department?->name, $section->name]), null, null);
                }
            }
        }

        if (Schema::hasTable('teams')) {
            foreach (Team::query()->where('team_leader_id', $user->id)->with(['department:id,name,branch_id,division_id', 'department.branch:id,name,company_id', 'department.branch.company:id,name'])->get() as $team) {
                $department = $team->department;
                $source = ['company_id' => $department?->branch?->company_id, 'branch_id' => $department?->branch_id, 'division_id' => $department?->division_id, 'department_id' => $department?->id];
                if ($this->matchesContext($source, $context)) {
                    $candidates[] = $this->payload(1, 'team', (int) $team->id, $this->path([$department?->branch?->company?->name, $department?->branch?->name, $department?->name, $team->name]), null, null);
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, int|null>  $context
     * @return list<array<string, mixed>>
     */
    private function assignmentCandidates(User $user, array $context): array
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return [];
        }

        return EmployeeOrganizationAssignment::query()
            ->active()
            ->where('employee_id', $user->id)
            ->with('organizationUnit:id,name,parent_id,company_id,legacy_source_type,legacy_source_id,is_active')
            ->get()
            ->map(function (EmployeeOrganizationAssignment $assignment) use ($context): ?array {
                $source = [
                    'company_id' => $assignment->company_id,
                    'branch_id' => $assignment->branch_id,
                    'division_id' => $assignment->division_id,
                    'department_id' => $assignment->department_id,
                    'section_unit_id' => $assignment->section_unit_id,
                ];
                if (! $this->matchesContext($source, $context)) {
                    return null;
                }

                return $this->payload(
                    0,
                    'employee_organization_assignment',
                    (int) $assignment->id,
                    $this->assignmentPath($assignment),
                    $assignment->effective_from?->toDateString(),
                    $assignment->effective_to?->toDateString(),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function levelFromOrganizationPosition(string $organizationLevel, string $positionName, OrganizationUnit $unit): int
    {
        $level = Str::lower($organizationLevel);
        $name = Str::lower($positionName);

        if (str_contains($name, 'team leader') || str_contains($name, 'immediate leader') || str_contains($name, 'oic') || str_contains($name, 'officer-in-charge')) {
            return 1;
        }

        return match ($level) {
            'company' => 5,
            'branch' => 4,
            'division' => 3,
            'department' => 2,
            'section', 'section_unit' => 1,
            'team' => 1,
            default => match ((string) $unit->legacy_source_type) {
                'company' => 5,
                'branch' => 4,
                'division' => 3,
                'department' => 2,
                'section', 'section_unit' => 1,
                'team' => 1,
                default => 0,
            },
        };
    }

    /**
     * @param  array<string, int|null>  $source
     * @param  array<string, int|null>  $context
     */
    private function matchesContext(array $source, array $context): bool
    {
        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (array_key_exists($key, $source) && $source[$key] !== null && (int) $source[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int|null>
     */
    private function sourceFromUnit(OrganizationUnit $unit): array
    {
        $source = ['company_id' => $unit->company_id, 'branch_id' => null, 'division_id' => null, 'department_id' => null, 'section_unit_id' => null];
        $type = (string) $unit->legacy_source_type;
        $id = $unit->legacy_source_id ? (int) $unit->legacy_source_id : null;

        if ($id !== null) {
            match ($type) {
                'branch' => $source['branch_id'] = $id,
                'division' => $source['division_id'] = $id,
                'department' => $source['department_id'] = $id,
                'section', 'section_unit' => $source['section_unit_id'] = $id,
                default => null,
            };
        }

        return $source;
    }

    private function organizationUnitPath(OrganizationUnit $unit): ?string
    {
        $parts = [];
        $cursor = $unit;
        $guard = 0;

        while ($cursor instanceof OrganizationUnit && $guard < 12) {
            array_unshift($parts, $cursor->name);
            $cursor = $cursor->parent()->first(['id', 'name', 'parent_id']);
            $guard++;
        }

        return $this->path($parts);
    }

    private function organizationPathForUser(User $user): ?string
    {
        $user->loadMissing([
            'company:id,name',
            'branch:id,name,company_id',
            'branch.company:id,name',
            'division:id,name,company_id,branch_id',
            'division.company:id,name',
            'division.branch:id,name,company_id',
            'departmentRelation:id,name,company_id,branch_id,division_id',
            'departmentRelation.company:id,name',
            'departmentRelation.branch:id,name,company_id',
            'departmentRelation.branch.company:id,name',
            'sectionUnit:id,name,company_id,branch_id,department_id,division_id',
            'sectionUnit.company:id,name',
            'sectionUnit.branch:id,name,company_id',
            'sectionUnit.department:id,name,branch_id,division_id',
            'sectionUnit.division:id,name,company_id,branch_id',
        ]);

        return $this->path([
            $user->company?->name ?? $user->branch?->company?->name ?? $user->departmentRelation?->company?->name ?? $user->departmentRelation?->branch?->company?->name ?? $user->division?->company?->name ?? $user->sectionUnit?->company?->name,
            $user->branch?->name ?? $user->division?->branch?->name ?? $user->departmentRelation?->branch?->name ?? $user->sectionUnit?->branch?->name,
            $user->division?->name ?? $user->departmentRelation?->division?->name ?? $user->sectionUnit?->division?->name,
            $user->departmentRelation?->name ?? $user->sectionUnit?->department?->name,
            $user->sectionUnit?->name,
        ]);
    }

    private function assignmentPath(EmployeeOrganizationAssignment $assignment): ?string
    {
        if ($assignment->organizationUnit instanceof OrganizationUnit) {
            return $this->organizationUnitPath($assignment->organizationUnit);
        }

        return $this->path([
            $assignment->company_id ? Company::query()->whereKey($assignment->company_id)->value('name') : null,
            $assignment->branch_id ? Branch::query()->whereKey($assignment->branch_id)->value('name') : null,
            $assignment->division_id ? Division::query()->whereKey($assignment->division_id)->value('name') : null,
            $assignment->department_id ? Department::query()->whereKey($assignment->department_id)->value('name') : null,
            $assignment->section_unit_id ? SectionUnit::query()->whereKey($assignment->section_unit_id)->value('name') : null,
        ]);
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function path(array $parts): ?string
    {
        $path = collect($parts)
            ->map(fn ($part): string => trim((string) $part))
            ->filter()
            ->values()
            ->implode(' > ');

        return $path !== '' ? $path : null;
    }

    private function payload(int $level, string $sourceModule, ?int $sourceAssignmentId, ?string $organizationPath, ?string $effectiveFrom, ?string $effectiveTo): array
    {
        $level = max(0, min(6, $level));

        return [
            'level_number' => $level,
            'level_name' => self::LEVEL_NAMES[$level] ?? self::LEVEL_NAMES[0],
            'level_label' => $this->labelForLevel($level),
            'source_module' => $sourceModule,
            'source_assignment_id' => $sourceAssignmentId,
            'organization_path' => $organizationPath,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ];
    }
}
