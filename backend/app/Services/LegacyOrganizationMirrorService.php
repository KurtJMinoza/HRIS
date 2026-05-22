<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationPositionType;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class LegacyOrganizationMirrorService
{
    public function syncLegacyRecord(string $legacyType, int $legacyId): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        match ($legacyType) {
            'company' => $this->syncCompanyIfExists($legacyId),
            'branch' => $this->syncBranchIfExists($legacyId),
            'division' => $this->syncDivisionIfExists($legacyId),
            'department' => $this->syncDepartmentIfExists($legacyId),
            'section_unit' => $this->syncSectionUnitIfExists($legacyId),
            default => null,
        };
    }

    private function syncCompanyIfExists(int $legacyId): void
    {
        $model = Company::query()->find($legacyId);
        if ($model) {
            $this->syncCompany($model);
        }
    }

    private function syncBranchIfExists(int $legacyId): void
    {
        $model = Branch::query()->find($legacyId);
        if ($model) {
            $this->syncBranch($model);
        }
    }

    private function syncDivisionIfExists(int $legacyId): void
    {
        $model = Division::query()->find($legacyId);
        if ($model) {
            $this->syncDivision($model);
        }
    }

    private function syncDepartmentIfExists(int $legacyId): void
    {
        $model = Department::query()->find($legacyId);
        if ($model) {
            $this->syncDepartment($model);
        }
    }

    private function syncSectionUnitIfExists(int $legacyId): void
    {
        $model = SectionUnit::query()->find($legacyId);
        if ($model) {
            $this->syncSectionUnit($model);
        }
    }

    public function syncLegacyPrimaryHead(string $legacyType, int $legacyId, OrganizationUnit $unit): void
    {
        $primary = OrganizationPositionAssignment::query()
            ->with('positionType')
            ->where('organization_unit_id', (int) $unit->id)
            ->active()
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->first();

        if (! $primary) {
            return;
        }

        $employeeId = (int) $primary->employee_id;

        match ($legacyType) {
            'company' => Company::query()->whereKey($legacyId)->update(['company_head_id' => $employeeId]),
            'branch' => Branch::query()->whereKey($legacyId)->update(['branch_manager_id' => $employeeId]),
            'division' => Division::query()->whereKey($legacyId)->update(['division_head_id' => $employeeId]),
            'department' => Department::query()->whereKey($legacyId)->update(['department_head_id' => $employeeId]),
            'section_unit' => SectionUnit::query()->whereKey($legacyId)->update(['section_unit_head_id' => $employeeId]),
            default => null,
        };
    }

    public function sync(Model $model): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        match (true) {
            $model instanceof Company => $this->syncCompany($model),
            $model instanceof Branch => $this->syncBranch($model),
            $model instanceof Division => $this->syncDivision($model),
            $model instanceof Department => $this->syncDepartment($model),
            $model instanceof SectionUnit => $this->syncSectionUnit($model),
            $model instanceof Team => $this->syncTeam($model),
            $model instanceof User => $this->syncUserAssignment($model),
            default => null,
        };
    }

    public function deactivate(Model $model): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $source = $this->legacySourceFor($model);
        if (! $source) {
            return;
        }

        OrganizationUnit::query()
            ->where('legacy_source_type', $source[0])
            ->where('legacy_source_id', $source[1])
            ->update(['is_active' => false]);
    }

    public function syncCompany(Company $company): ?OrganizationUnit
    {
        $unit = $this->upsertUnit('company', (int) $company->id, [
            'organization_type_id' => $this->typeId('company', 'Company', 10),
            'parent_id' => null,
            'company_id' => (int) $company->id,
            'name' => $company->name,
            'code' => null,
            'description' => null,
            'is_active' => true,
            'sort_order' => (int) $company->id,
        ]);

        $this->syncSingleLeader($unit, $company->company_head_id ? (int) $company->company_head_id : null, 'Company Head');

        return $unit;
    }

    public function syncBranch(Branch $branch): ?OrganizationUnit
    {
        $parentId = $this->companyUnitId($branch->company_id ? (int) $branch->company_id : null);
        $unit = $this->upsertUnit('branch', (int) $branch->id, [
            'organization_type_id' => $this->typeId('branch', 'Branch', 20),
            'parent_id' => $parentId,
            'company_id' => $branch->company_id ? (int) $branch->company_id : null,
            'name' => $branch->name,
            'code' => null,
            'description' => $branch->address,
            'is_active' => true,
            'sort_order' => (int) $branch->id,
        ]);

        $this->syncSingleLeader($unit, $branch->branch_manager_id ? (int) $branch->branch_manager_id : null, 'Branch Head');

        return $unit;
    }

    public function syncDivision(Division $division): ?OrganizationUnit
    {
        $companyId = $division->company_id ? (int) $division->company_id : ($division->branch?->company_id ? (int) $division->branch->company_id : null);
        $parentId = $division->branch_id
            ? $this->legacyUnitId('branch', (int) $division->branch_id)
            : $this->companyUnitId($companyId);

        $unit = $this->upsertUnit('division', (int) $division->id, [
            'organization_type_id' => $this->typeId('division', 'Division', 30),
            'parent_id' => $parentId,
            'company_id' => $companyId,
            'name' => $division->name,
            'code' => $division->code,
            'description' => $division->description,
            'is_active' => ($division->status ?? 'active') === 'active',
            'sort_order' => (int) $division->id,
        ]);

        $this->syncSingleLeader($unit, $division->division_head_id ? (int) $division->division_head_id : null, 'Division Head');

        return $unit;
    }

    public function syncDepartment(Department $department): ?OrganizationUnit
    {
        $companyId = $department->company_id ? (int) $department->company_id : ($department->branch?->company_id ? (int) $department->branch->company_id : null);
        $parentId = $department->division_id
            ? $this->legacyUnitId('division', (int) $department->division_id)
            : ($department->branch_id
                ? $this->legacyUnitId('branch', (int) $department->branch_id)
                : $this->companyUnitId($companyId));

        $unit = $this->upsertUnit('department', (int) $department->id, [
            'organization_type_id' => $this->typeId('department', 'Department', 40),
            'parent_id' => $parentId,
            'company_id' => $companyId,
            'name' => $department->name,
            'code' => null,
            'description' => $department->description,
            'is_active' => true,
            'sort_order' => (int) $department->id,
            'hierarchy_mismatch' => (bool) ($department->hierarchy_mismatch ?? false),
        ]);

        $this->syncSingleLeader($unit, $department->department_head_id ? (int) $department->department_head_id : null, 'Department Head');
        $this->syncTeamLeadersForDepartment($unit, $department);

        return $unit;
    }

    private function syncTeamLeadersForDepartment(OrganizationUnit $unit, Department $department): void
    {
        if (! method_exists($department, 'teamLeaders')) {
            return;
        }

        $department->loadMissing('teamLeaders');
        foreach ($department->teamLeaders as $index => $leader) {
            $this->syncPositionAssignment(
                $unit,
                'department',
                (int) $leader->id,
                'Team Leader',
                false,
                10 + $index,
            );
        }
    }

    public function syncSectionUnit(SectionUnit $section): ?OrganizationUnit
    {
        $companyId = $section->company_id ? (int) $section->company_id : ($section->branch?->company_id ? (int) $section->branch->company_id : null);
        $parentId = $section->department_id
            ? $this->legacyUnitId('department', (int) $section->department_id)
            : ($section->division_id
                ? $this->legacyUnitId('division', (int) $section->division_id)
                : ($section->branch_id ? $this->legacyUnitId('branch', (int) $section->branch_id) : $this->companyUnitId($companyId)));

        $unit = $this->upsertUnit('section_unit', (int) $section->id, [
            'organization_type_id' => $this->typeId('section', 'Section', 50),
            'parent_id' => $parentId,
            'company_id' => $companyId,
            'name' => $section->name,
            'code' => $section->code,
            'description' => $section->description,
            'is_active' => ($section->status ?? 'active') === 'active',
            'sort_order' => (int) $section->id,
        ]);

        $this->syncSingleLeader($unit, $section->section_unit_head_id ? (int) $section->section_unit_head_id : null, 'Section Leader');
        $this->syncTeamLeadersForSection($unit, $section);

        return $unit;
    }

    private function syncTeamLeadersForSection(OrganizationUnit $unit, SectionUnit $section): void
    {
        if (! method_exists($section, 'teamLeaders')) {
            return;
        }

        $section->loadMissing('teamLeaders');
        foreach ($section->teamLeaders as $index => $leader) {
            $this->syncPositionAssignment(
                $unit,
                'section_unit',
                (int) $leader->id,
                'Team Leader',
                false,
                10 + $index,
            );
        }
    }

    public function syncTeam(Team $team): ?OrganizationUnit
    {
        $department = $team->department_id ? Department::query()->find($team->department_id) : null;
        $companyId = $department?->company_id ? (int) $department->company_id : ($department?->branch?->company_id ? (int) $department->branch->company_id : null);

        $unit = $this->upsertUnit('team', (int) $team->id, [
            'organization_type_id' => $this->typeId('team', 'Team', 70),
            'parent_id' => $team->department_id ? $this->legacyUnitId('department', (int) $team->department_id) : null,
            'company_id' => $companyId,
            'name' => $team->name,
            'code' => null,
            'description' => null,
            'is_active' => true,
            'sort_order' => (int) $team->id,
        ]);

        $this->syncSingleLeader($unit, $team->team_leader_id ? (int) $team->team_leader_id : null, 'Team Leader');

        return $unit;
    }

    public function syncUserAssignment(User $user): void
    {
        if (! $user->isRosterEligible()) {
            return;
        }

        $unit = $this->deepestUnitForUser($user);
        if (! $unit) {
            return;
        }

        EmployeeOrganizationAssignment::query()
            ->where('employee_id', (int) $user->id)
            ->where('is_primary', true)
            ->where('organization_unit_id', '<>', (int) $unit->id)
            ->update(['is_primary' => false]);

        $payload = [
            'assignment_type' => EmployeeOrganizationAssignment::TYPE_PRIMARY,
            'company_id' => $user->company_id ? (int) $user->company_id : null,
            'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
            'division_id' => $user->division_id ? (int) $user->division_id : null,
            'department_id' => $user->department_id ? (int) $user->department_id : null,
            'section_unit_id' => $user->section_unit_id ? (int) $user->section_unit_id : null,
            'is_primary' => true,
            'immediate_leader_id' => $user->supervisor_id ? (int) $user->supervisor_id : null,
            'effective_from' => $user->hire_date,
            'effective_to' => null,
            'is_active' => (bool) $user->is_active && (bool) $unit->is_active,
        ];

        EmployeeOrganizationAssignment::query()->updateOrCreate(
            [
                'employee_id' => (int) $user->id,
                'organization_unit_id' => (int) $unit->id,
            ],
            $payload,
        );
    }

    private function upsertUnit(string $sourceType, int $sourceId, array $attributes): OrganizationUnit
    {
        return OrganizationUnit::query()->updateOrCreate(
            [
                'legacy_source_type' => $sourceType,
                'legacy_source_id' => $sourceId,
            ],
            array_merge([
                'approval_routing_rule' => OrganizationUnit::ROUTING_FIRST_ASSIGNED,
            ], $attributes),
        );
    }

    private function syncSingleLeader(OrganizationUnit $unit, ?int $employeeId, string $role): void
    {
        OrganizationUnitLeader::query()
            ->where('organization_unit_id', (int) $unit->id)
            ->where('leader_role', $role)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', '<>', $employeeId))
            ->update(['is_active' => false, 'is_primary' => false]);

        if ($employeeId === null) {
            return;
        }

        OrganizationUnitLeader::query()->updateOrCreate(
            [
                'organization_unit_id' => (int) $unit->id,
                'employee_id' => $employeeId,
                'leader_role' => $role,
            ],
            [
                'is_primary' => true,
                'approval_priority' => 1,
                'is_active' => true,
            ],
        );

        $level = match ($unit->legacy_source_type) {
            'company' => 'company',
            'branch' => 'branch',
            'division' => 'division',
            'department' => 'department',
            'section_unit' => 'section_unit',
            default => 'section_unit',
        };

        $this->syncPositionAssignment($unit, $level, $employeeId, $role, true, 1);
    }

    private function syncPositionAssignment(
        OrganizationUnit $unit,
        string $level,
        int $employeeId,
        string $positionName,
        bool $primary,
        int $priority,
    ): void {
        if (! Schema::hasTable('organization_position_types') || ! Schema::hasTable('organization_position_assignments')) {
            return;
        }

        $positionType = OrganizationPositionType::query()->firstOrCreate(
            [
                'organization_level' => $level,
                'position_name' => $positionName,
            ],
            [
                'approval_priority' => $priority,
                'can_approve' => true,
                'is_final_approver' => false,
                'is_active' => true,
            ],
        );

        OrganizationPositionAssignment::query()->updateOrCreate(
            [
                'organization_unit_id' => (int) $unit->id,
                'position_type_id' => (int) $positionType->id,
                'employee_id' => $employeeId,
            ],
            [
                'organization_level' => $level,
                'is_primary' => $primary,
                'approval_priority' => $priority,
                'effective_from' => null,
                'effective_to' => null,
                'is_active' => true,
            ],
        );
    }

    private function deepestUnitForUser(User $user): ?OrganizationUnit
    {
        $legacy = [
            ['team', $user->team_id],
            ['section_unit', $user->section_unit_id],
            ['department', $user->department_id],
            ['division', $user->division_id],
            ['branch', $user->branch_id],
            ['company', $user->company_id],
        ];

        foreach ($legacy as [$type, $id]) {
            if ($id === null) {
                continue;
            }

            $unitId = $this->legacyUnitId($type, (int) $id);
            if ($unitId !== null) {
                return OrganizationUnit::query()->find($unitId);
            }
        }

        return null;
    }

    private function companyUnitId(?int $companyId): ?int
    {
        if ($companyId === null) {
            return null;
        }

        $unitId = $this->legacyUnitId('company', $companyId);
        if ($unitId !== null) {
            return $unitId;
        }

        $company = Company::query()->find($companyId);

        return $company ? $this->syncCompany($company)?->id : null;
    }

    private function legacyUnitId(string $sourceType, int $sourceId): ?int
    {
        $id = OrganizationUnit::query()
            ->where('legacy_source_type', $sourceType)
            ->where('legacy_source_id', $sourceId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function typeId(string $code, string $name, int $order): int
    {
        $type = OrganizationType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'level_order' => $order,
                'is_system' => true,
                'is_active' => true,
            ],
        );

        return (int) $type->id;
    }

    private function legacySourceFor(Model $model): ?array
    {
        return match (true) {
            $model instanceof Company => ['company', (int) $model->id],
            $model instanceof Branch => ['branch', (int) $model->id],
            $model instanceof Division => ['division', (int) $model->id],
            $model instanceof Department => ['department', (int) $model->id],
            $model instanceof SectionUnit => ['section_unit', (int) $model->id],
            $model instanceof Team => ['team', (int) $model->id],
            default => null,
        };
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('organization_types')
            && Schema::hasTable('organization_units')
            && Schema::hasTable('organization_unit_leaders')
            && Schema::hasTable('employee_organization_assignments');
    }
}
