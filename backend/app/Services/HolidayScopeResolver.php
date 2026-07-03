<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\Holiday;
use App\Models\HolidayScope;
use App\Models\SectionUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The single, fail-closed source of truth for holiday scope applicability.
 *
 * Organization IDs are never compared in isolation: a matching child unit must
 * also belong to the employee's company/parent organization path.
 */
class HolidayScopeResolver
{
    /** @var array<int, array{id:int, company_id:?int}> */
    private array $branches = [];

    /** @var array<int, array{id:int, company_id:?int, branch_id:?int}> */
    private array $divisions = [];

    /** @var array<int, array{id:int, company_id:?int, branch_id:?int, division_id:?int}> */
    private array $departments = [];

    /** @var array<int, array{id:int, company_id:?int, branch_id:?int, division_id:?int, department_id:?int}> */
    private array $sections = [];

    /** @var array<int, Collection<int, EmployeeOrganizationAssignment>> */
    private array $assignmentsByEmployee = [];

    public function flushRuntimeCaches(): void
    {
        $this->branches = [];
        $this->divisions = [];
        $this->departments = [];
        $this->sections = [];
        $this->assignmentsByEmployee = [];
    }

    public function appliesToEmployee(Holiday $holiday, User $employee, Carbon $date): bool
    {
        return $this->appliesRowToEmployee($this->holidayRow($holiday), $employee, $date);
    }

    /**
     * Apply a serialized calendar row to an employee using assignments effective
     * on the holiday date. This is used by calendars as well as payroll.
     *
     * @param  array<string, mixed>  $holiday
     */
    public function appliesRowToEmployee(array $holiday, User $employee, Carbon|string $date): bool
    {
        $effectiveDate = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        [$scopeType, $scopeIds] = $this->scopeAndIds($holiday);

        if (! $this->isActiveOnDate($holiday, $effectiveDate)) {
            $this->logDecision($holiday, $scopeType, $scopeIds, $employee, null, false, 'inactive_or_date_mismatch');

            return false;
        }

        if ($scopeType === 'nationwide') {
            $this->logDecision($holiday, $scopeType, $scopeIds, $employee, null, true, 'nationwide');

            return true;
        }

        if ($scopeType === 'selected_employees') {
            $matched = in_array((int) $employee->id, $scopeIds, true);
            $this->logDecision($holiday, $scopeType, $scopeIds, $employee, null, $matched, $matched ? 'employee_in_scope' : 'employee_not_in_scope');

            return $matched;
        }

        $contexts = $this->organizationContextsForEmployee($employee, $effectiveDate);
        foreach ($contexts as $context) {
            [$matched, $reason] = $this->evaluate($holiday, $scopeType, $scopeIds, $context);
            if ($matched) {
                $this->logDecision($holiday, $scopeType, $scopeIds, $employee, $context, true, $reason);

                return true;
            }
        }

        $context = $contexts[0] ?? null;
        [, $reason] = $this->evaluate($holiday, $scopeType, $scopeIds, $context ?? $this->emptyContext());
        $this->logDecision($holiday, $scopeType, $scopeIds, $employee, $context, false, $reason);

        return false;
    }

    /**
     * Strict scope check for callers that have an explicit organization context
     * rather than a User model.
     *
     * @param  array<string, mixed>  $holiday
     * @param  array<string, int|null>  $context
     */
    public function appliesToContext(array $holiday, array $context): bool
    {
        [$scopeType, $scopeIds] = $this->scopeAndIds($holiday);
        if ($scopeType === 'nationwide') {
            return true;
        }

        [$matched] = $this->evaluate($holiday, $scopeType, $scopeIds, $this->normalizeContext($context));

        return $matched;
    }

    /**
     * @return list<array{company_id:?int, branch_id:?int, division_id:?int, department_id:?int, section_unit_id:?int, employee_id:?int}>
     */
    public function organizationContextsForEmployee(User $employee, Carbon $date): array
    {
        $base = $this->normalizeContext([
            'company_id' => $this->effectiveCompanyId($employee),
            'branch_id' => $employee->branch_id !== null ? (int) $employee->branch_id : null,
            'division_id' => $employee->division_id !== null ? (int) $employee->division_id : null,
            'department_id' => $employee->department_id !== null ? (int) $employee->department_id : null,
            'section_unit_id' => $employee->section_unit_id !== null ? (int) $employee->section_unit_id : null,
            'employee_id' => (int) $employee->id,
        ]);

        if (! Schema::hasTable('employee_organization_assignments')) {
            return [$base];
        }

        $employeeId = (int) $employee->id;
        $assignmentRows = $this->assignmentsByEmployee[$employeeId] ??= EmployeeOrganizationAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->get(['company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id', 'assignment_type', 'is_primary', 'effective_from', 'effective_to']);
        $assignments = $assignmentRows->filter(function (EmployeeOrganizationAssignment $assignment) use ($date): bool {
            $startsOnTime = $assignment->effective_from === null || $assignment->effective_from->startOfDay()->lessThanOrEqualTo($date);
            $endsOnTime = $assignment->effective_to === null || $assignment->effective_to->endOfDay()->greaterThanOrEqualTo($date);

            return $startsOnTime && $endsOnTime;
        });

        $hasPrimaryAssignment = $assignments->contains(
            fn (EmployeeOrganizationAssignment $row): bool => (bool) $row->is_primary || $row->assignment_type === EmployeeOrganizationAssignment::TYPE_PRIMARY
        );

        $contexts = $hasPrimaryAssignment ? [] : [$base];
        foreach ($assignments as $assignment) {
            $contexts[] = $this->normalizeContext([
                'company_id' => $assignment->company_id !== null ? (int) $assignment->company_id : null,
                'branch_id' => $assignment->branch_id !== null ? (int) $assignment->branch_id : null,
                'division_id' => $assignment->division_id !== null ? (int) $assignment->division_id : null,
                'department_id' => $assignment->department_id !== null ? (int) $assignment->department_id : null,
                'section_unit_id' => $assignment->section_unit_id !== null ? (int) $assignment->section_unit_id : null,
                'employee_id' => (int) $employee->id,
            ]);
        }

        $unique = [];
        foreach ($contexts as $context) {
            $unique[implode('|', array_map(static fn ($value) => (string) ($value ?? 0), $context))] = $context;
        }

        return array_values($unique) ?: [$base];
    }

    /**
     * @param  array<string, mixed>  $holiday
     * @return array{0:string, 1:list<int>}
     */
    private function scopeAndIds(array $holiday): array
    {
        $coverageType = isset($holiday['coverage_type']) ? strtolower(trim((string) $holiday['coverage_type'])) : '';
        if ($coverageType !== '') {
            $scopeType = match ($coverageType) {
                'company', 'companies', 'selected_companies' => 'selected_companies',
                'branch', 'branches', 'selected_branches' => 'selected_branches',
                'division', 'divisions', 'selected_divisions' => 'selected_divisions',
                'department', 'departments', 'selected_departments' => 'selected_departments',
                'section', 'sections', 'section_unit', 'section_units', 'selected_sections' => 'selected_sections',
                'employee', 'employees', 'selected_employees' => 'selected_employees',
                default => 'invalid',
            };

            return [$scopeType, $this->normalizeIds($holiday['coverage_ids'] ?? [])];
        }

        $rawScope = strtolower(trim((string) ($holiday['scope_type'] ?? $holiday['scope'] ?? '')));
        $scopeType = match ($rawScope) {
            'nationwide', 'national', 'regional' => 'nationwide',
            'company', 'companies', 'selected_companies' => 'selected_companies',
            'branch', 'branches', 'selected_branches' => 'selected_branches',
            'division', 'divisions', 'selected_divisions' => 'selected_divisions',
            'department', 'departments', 'selected_departments' => 'selected_departments',
            'section', 'sections', 'section_unit', 'section_units', 'selected_sections' => 'selected_sections',
            'employee', 'employees', 'selected_employees' => 'selected_employees',
            default => 'invalid',
        };

        $column = match ($scopeType) {
            'selected_companies' => 'company_id',
            'selected_branches' => 'branch_id',
            'selected_divisions' => 'division_id',
            'selected_departments' => 'department_id',
            'selected_sections' => 'section_unit_id',
            'selected_employees' => 'employee_id',
            default => null,
        };

        return [$scopeType, $column !== null ? $this->normalizeIds([$holiday[$column] ?? null]) : []];
    }

    /**
     * @param  array<string, mixed>  $holiday
     * @param  list<int>  $scopeIds
     * @param  array{company_id:?int, branch_id:?int, division_id:?int, department_id:?int, section_unit_id:?int, employee_id:?int}  $context
     * @return array{0:bool, 1:string}
     */
    private function evaluate(array $holiday, string $scopeType, array $scopeIds, array $context): array
    {
        if ($scopeType === 'invalid' || $scopeIds === []) {
            return [false, 'invalid_or_empty_scope'];
        }

        if ($scopeType === 'selected_companies') {
            $matched = $context['company_id'] !== null && in_array($context['company_id'], $scopeIds, true);

            return [$matched, $matched ? 'company_in_scope' : 'company_not_in_scope'];
        }

        if ($scopeType === 'selected_branches') {
            foreach ($scopeIds as $branchId) {
                $branch = $this->branch($branchId);
                if ($branch === null || ! $this->storedParentMatches($holiday, $branch)) {
                    continue;
                }
                if ($context['branch_id'] === $branchId
                    && $context['company_id'] !== null
                    && $branch['company_id'] !== null
                    && $context['company_id'] === $branch['company_id']) {
                    return [true, 'branch_and_company_match'];
                }
            }

            return [false, 'branch_not_in_scope_or_company_mismatch'];
        }

        if ($scopeType === 'selected_divisions') {
            foreach ($scopeIds as $divisionId) {
                $division = $this->division($divisionId);
                if ($division !== null
                    && $this->storedParentMatches($holiday, $division)
                    && $context['division_id'] === $divisionId
                    && $this->contextMatchesParents($context, $division)) {
                    return [true, 'division_and_parent_path_match'];
                }
            }

            return [false, 'division_not_in_scope_or_parent_mismatch'];
        }

        if ($scopeType === 'selected_departments') {
            foreach ($scopeIds as $departmentId) {
                $department = $this->department($departmentId);
                if ($department !== null
                    && $this->storedParentMatches($holiday, $department)
                    && $context['department_id'] === $departmentId
                    && $this->contextMatchesParents($context, $department)) {
                    return [true, 'department_and_parent_path_match'];
                }
            }

            return [false, 'department_not_in_scope_or_parent_mismatch'];
        }

        if ($scopeType === 'selected_sections') {
            foreach ($scopeIds as $sectionId) {
                $section = $this->section($sectionId);
                if ($section !== null
                    && $this->storedParentMatches($holiday, $section)
                    && $context['section_unit_id'] === $sectionId
                    && $this->contextMatchesParents($context, $section)) {
                    return [true, 'section_and_parent_path_match'];
                }
            }

            return [false, 'section_not_in_scope_or_parent_mismatch'];
        }

        if ($scopeType === 'selected_employees') {
            $matched = $context['employee_id'] !== null && in_array($context['employee_id'], $scopeIds, true);

            return [$matched, $matched ? 'employee_in_scope' : 'employee_not_in_scope'];
        }

        return [false, 'unsupported_scope'];
    }

    /** @param array<string, mixed> $holiday */
    private function isActiveOnDate(array $holiday, Carbon $date): bool
    {
        $status = strtolower(trim((string) ($holiday['status'] ?? 'active')));
        if (! in_array($status, ['', 'active'], true)) {
            return false;
        }

        $rawDate = $holiday['date'] ?? null;
        if ($rawDate === null || $rawDate === '') {
            return false;
        }

        $holidayDate = $rawDate instanceof Carbon ? $rawDate : Carbon::parse((string) $rawDate);

        return (bool) ($holiday['is_recurring'] ?? false)
            ? $holidayDate->format('m-d') === $date->format('m-d')
            : $holidayDate->toDateString() === $date->toDateString();
    }

    /**
     * Fill missing parents from authoritative organization records. Explicit,
     * conflicting IDs are preserved so the strict comparison rejects them.
     *
     * @param  array<string, int|null>  $context
     * @return array{company_id:?int, branch_id:?int, division_id:?int, department_id:?int, section_unit_id:?int, employee_id:?int}
     */
    private function normalizeContext(array $context): array
    {
        $normalized = $this->emptyContext();
        foreach (array_keys($normalized) as $key) {
            $value = $context[$key] ?? null;
            $normalized[$key] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        if ($normalized['section_unit_id'] !== null && ($section = $this->section($normalized['section_unit_id'])) !== null) {
            foreach (['company_id', 'branch_id', 'division_id', 'department_id'] as $key) {
                $normalized[$key] ??= $section[$key] ?? null;
            }
        }
        if ($normalized['department_id'] !== null && ($department = $this->department($normalized['department_id'])) !== null) {
            foreach (['company_id', 'branch_id', 'division_id'] as $key) {
                $normalized[$key] ??= $department[$key] ?? null;
            }
        }
        if ($normalized['division_id'] !== null && ($division = $this->division($normalized['division_id'])) !== null) {
            foreach (['company_id', 'branch_id'] as $key) {
                $normalized[$key] ??= $division[$key] ?? null;
            }
        }
        if ($normalized['branch_id'] !== null && ($branch = $this->branch($normalized['branch_id'])) !== null) {
            $normalized['company_id'] ??= $branch['company_id'];
        }

        return $normalized;
    }

    /** @return array{company_id:?int, branch_id:?int, division_id:?int, department_id:?int, section_unit_id:?int, employee_id:?int} */
    private function emptyContext(): array
    {
        return [
            'company_id' => null,
            'branch_id' => null,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'employee_id' => null,
        ];
    }

    /** @param array<string, int|null> $parents */
    private function contextMatchesParents(array $context, array $parents): bool
    {
        foreach (['company_id', 'branch_id', 'division_id', 'department_id'] as $key) {
            if (array_key_exists($key, $parents) && $parents[$key] !== null && $context[$key] !== $parents[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $holiday @param array<string, int|null> $parents */
    private function storedParentMatches(array $holiday, array $parents): bool
    {
        foreach (['company_id', 'branch_id', 'division_id', 'department_id'] as $key) {
            $stored = isset($holiday[$key]) && is_numeric($holiday[$key]) ? (int) $holiday[$key] : null;
            if ($stored !== null && $stored > 0 && isset($parents[$key]) && $parents[$key] !== null && $stored !== $parents[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @return array{id:int, company_id:?int}|null */
    private function branch(int $id): ?array
    {
        if (! array_key_exists($id, $this->branches)) {
            $row = Branch::query()->find($id, ['id', 'company_id']);
            $this->branches[$id] = $row ? ['id' => (int) $row->id, 'company_id' => $row->company_id !== null ? (int) $row->company_id : null] : null;
        }

        return $this->branches[$id];
    }

    /** @return array{id:int, company_id:?int, branch_id:?int}|null */
    private function division(int $id): ?array
    {
        if (! array_key_exists($id, $this->divisions)) {
            $row = Division::query()->find($id, ['id', 'company_id', 'branch_id']);
            $this->divisions[$id] = $row ? [
                'id' => (int) $row->id,
                'company_id' => $row->company_id !== null ? (int) $row->company_id : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            ] : null;
        }

        return $this->divisions[$id];
    }

    /** @return array{id:int, company_id:?int, branch_id:?int, division_id:?int}|null */
    private function department(int $id): ?array
    {
        if (! array_key_exists($id, $this->departments)) {
            $row = Department::query()->with('branch:id,company_id')->find($id, ['id', 'branch_id', 'division_id']);
            $this->departments[$id] = $row ? [
                'id' => (int) $row->id,
                'company_id' => $row->branch?->company_id !== null ? (int) $row->branch->company_id : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'division_id' => $row->division_id !== null ? (int) $row->division_id : null,
            ] : null;
        }

        return $this->departments[$id];
    }

    /** @return array{id:int, company_id:?int, branch_id:?int, division_id:?int, department_id:?int}|null */
    private function section(int $id): ?array
    {
        if (! array_key_exists($id, $this->sections)) {
            $row = SectionUnit::query()->find($id, ['id', 'company_id', 'branch_id', 'division_id', 'department_id']);
            $this->sections[$id] = $row ? [
                'id' => (int) $row->id,
                'company_id' => $row->company_id !== null ? (int) $row->company_id : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'division_id' => $row->division_id !== null ? (int) $row->division_id : null,
                'department_id' => $row->department_id !== null ? (int) $row->department_id : null,
            ] : null;
        }

        return $this->sections[$id];
    }

    /** @return list<int> */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): ?int => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
            $ids
        ))));
    }

    /** @return array<string, mixed> */
    private function holidayRow(Holiday $holiday): array
    {
        $coverageIds = $holiday->getCoverageIds();
        $coverageType = $holiday->coverage_type;

        if (empty($coverageIds) && $coverageType === null && Schema::hasTable('holiday_scopes')) {
            $scopes = $holiday->relationLoaded('holidayScopes')
                ? $holiday->holidayScopes
                : HolidayScope::query()->where('holiday_id', $holiday->id)->get();

            if ($scopes->isNotEmpty()) {
                $first = $scopes->first();
                $coverageType = $first->scope_type;
                $coverageIds = $scopes->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all();
            }
        }

        return [
            'id' => $holiday->id,
            'name' => $holiday->name,
            'date' => $holiday->date,
            'status' => $holiday->status,
            'is_recurring' => $holiday->is_recurring,
            'scope' => $holiday->scope,
            'scope_type' => $holiday->scope,
            'company_id' => $holiday->company_id,
            'branch_id' => $holiday->branch_id,
            'division_id' => $holiday->division_id,
            'department_id' => $holiday->department_id,
            'section_unit_id' => $holiday->section_unit_id,
            'employee_id' => $holiday->employee_id,
            'coverage_type' => $coverageType,
            'coverage_ids' => $coverageIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $holiday
     * @param  list<int>  $scopeIds
     * @param  array<string, int|null>|null  $context
     */
    private function logDecision(array $holiday, string $scopeType, array $scopeIds, User $employee, ?array $context, bool $matched, string $reason): void
    {
        $context = $context ?? $this->emptyContext();
        Log::debug('holiday_scope_resolver', [
            'holiday_id' => $holiday['id'] ?? null,
            'holiday_name' => $holiday['name'] ?? null,
            'coverage_type' => $holiday['coverage_type'] ?? $holiday['scope'] ?? 'nationwide',
            'selected_scope_ids' => $scopeIds,
            'employee_id' => (int) $employee->id,
            'employee_company_id' => $context['company_id'] ?? $this->effectiveCompanyId($employee),
            'employee_branch_id' => $context['branch_id'] ?? $employee->branch_id,
            'employee_division_id' => $context['division_id'] ?? $employee->division_id,
            'employee_department_id' => $context['department_id'] ?? $employee->department_id,
            'employee_section_id' => $context['section_unit_id'] ?? $employee->section_unit_id,
            'scope_match' => $matched,
            'skip_reason' => $matched ? null : $reason,
        ]);
    }

    private function effectiveCompanyId(User $employee): ?int
    {
        if ($employee->company_id !== null) {
            return (int) $employee->company_id;
        }

        $companyId = $employee->getEffectiveCompanyId();

        return $companyId !== null ? (int) $companyId : null;
    }
}
