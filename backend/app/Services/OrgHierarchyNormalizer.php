<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes and validates Company → Branch → Division → Department → Section/Unit hierarchy.
 */
class OrgHierarchyNormalizer
{
    /**
     * @param  array{
     *   company_id?: int|null,
     *   branch_id?: int|null,
     *   division_id?: int|null,
     *   department_id?: int|null,
     *   section_unit_id?: int|null
     * }  $input
     * @return array{
     *   company_id: ?int,
     *   branch_id: ?int,
     *   division_id: ?int,
     *   department_id: ?int,
     *   section_unit_id: ?int
     * }
     */
    public function normalizeFromDeepest(array $input): array
    {
        $companyId = isset($input['company_id']) ? (int) $input['company_id'] : null;
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        $divisionId = isset($input['division_id']) ? (int) $input['division_id'] : null;
        $departmentId = isset($input['department_id']) ? (int) $input['department_id'] : null;
        $sectionUnitId = isset($input['section_unit_id']) ? (int) $input['section_unit_id'] : null;

        if ($sectionUnitId !== null) {
            $section = SectionUnit::query()->findOrFail($sectionUnitId);
            $departmentId = $section->department_id !== null ? (int) $section->department_id : $departmentId;
            $divisionId = $section->division_id !== null ? (int) $section->division_id : $divisionId;
            $branchId = $section->branch_id !== null ? (int) $section->branch_id : $branchId;
            $companyId = $section->company_id !== null ? (int) $section->company_id : $companyId;
        }

        if ($departmentId !== null) {
            $department = Department::query()->with('division')->findOrFail($departmentId);
            $divisionId = $department->division_id !== null ? (int) $department->division_id : $divisionId;
            $branchId = $department->branch_id !== null ? (int) $department->branch_id : $branchId;
            $companyId = $department->company_id !== null ? (int) $department->company_id : $companyId;
            if ($companyId === null && $department->branch_id !== null) {
                $companyId = (int) Branch::query()->whereKey($department->branch_id)->value('company_id');
            }
        }

        if ($divisionId !== null) {
            $division = Division::query()->findOrFail($divisionId);
            $branchId = $division->branch_id !== null ? (int) $division->branch_id : $branchId;
            $companyId = $division->company_id !== null ? (int) $division->company_id : $companyId;
        }

        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $companyId = (int) $branch->company_id;
        }

        if ($companyId !== null) {
            Company::query()->findOrFail($companyId);
        }

        $this->assertConsistentChain($companyId, $branchId, $divisionId, $departmentId, $sectionUnitId);

        return [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionUnitId,
        ];
    }

    /**
     * @param  array{
     *   company_id?: int|null,
     *   branch_id?: int|null,
     *   division_id?: int|null,
     *   department_id?: int|null
     * }  $input
     * @return array{0: int, 1: int|null, 2: int|null}
     */
    public function normalizeDivisionScope(array $input): array
    {
        $companyId = isset($input['company_id']) ? (int) $input['company_id'] : null;
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;

        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $companyId = (int) $branch->company_id;
        } elseif ($companyId !== null) {
            Company::query()->findOrFail($companyId);
        }

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Company is required.']]);
        }

        return [$companyId, $branchId, null];
    }

    /**
     * @param  array{
     *   company_id?: int|null,
     *   branch_id?: int|null,
     *   division_id?: int|null
     * }  $input
     * @return array{0: int, 1: int|null, 2: int}
     */
    public function normalizeDepartmentScope(array $input): array
    {
        $companyId = isset($input['company_id']) ? (int) $input['company_id'] : null;
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        $divisionId = isset($input['division_id']) ? (int) $input['division_id'] : null;

        if ($divisionId === null) {
            throw ValidationException::withMessages(['division_id' => ['Division is required when creating a department.']]);
        }

        $division = Division::query()->findOrFail($divisionId);
        $branchId = $division->branch_id !== null ? (int) $division->branch_id : $branchId;
        $companyId = $division->company_id !== null ? (int) $division->company_id : $companyId;

        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $companyId = (int) $branch->company_id;
        }

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Company is required.']]);
        }

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Branch is required when creating a department.']]);
        }

        return [$companyId, $branchId, $divisionId];
    }

    /**
     * @param  array{
     *   company_id?: int|null,
     *   branch_id?: int|null,
     *   division_id?: int|null,
     *   department_id?: int|null
     * }  $input
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public function normalizeSectionScope(array $input): array
    {
        $departmentId = isset($input['department_id']) ? (int) $input['department_id'] : null;

        if ($departmentId === null) {
            throw ValidationException::withMessages(['department_id' => ['Department is required when creating a section/unit.']]);
        }

        $normalized = $this->normalizeFromDeepest([
            'department_id' => $departmentId,
            'division_id' => $input['division_id'] ?? null,
            'branch_id' => $input['branch_id'] ?? null,
            'company_id' => $input['company_id'] ?? null,
        ]);

        if ($normalized['company_id'] === null || $normalized['branch_id'] === null || $normalized['division_id'] === null) {
            throw ValidationException::withMessages([
                'department_id' => ['Department must belong to a division, branch, and company.'],
            ]);
        }

        return [
            (int) $normalized['company_id'],
            (int) $normalized['branch_id'],
            (int) $normalized['division_id'],
            (int) $normalized['department_id'],
        ];
    }

    private function assertConsistentChain(
        ?int $companyId,
        ?int $branchId,
        ?int $divisionId,
        ?int $departmentId,
        ?int $sectionUnitId,
    ): void {
        if ($departmentId !== null && $divisionId !== null) {
            $departmentDivisionId = Department::query()->whereKey($departmentId)->value('division_id');
            if ($departmentDivisionId !== null && (int) $departmentDivisionId !== $divisionId) {
                throw ValidationException::withMessages([
                    'department_id' => ['The selected department does not belong to the selected division.'],
                ]);
            }
        }

        if ($sectionUnitId !== null && $departmentId !== null) {
            $sectionDepartmentId = SectionUnit::query()->whereKey($sectionUnitId)->value('department_id');
            if ($sectionDepartmentId !== null && (int) $sectionDepartmentId !== $departmentId) {
                throw ValidationException::withMessages([
                    'section_unit_id' => ['The selected section/unit does not belong to the selected department.'],
                ]);
            }
        }

        if ($sectionUnitId !== null && $divisionId !== null) {
            $sectionDivisionId = SectionUnit::query()->whereKey($sectionUnitId)->value('division_id');
            if ($sectionDivisionId !== null && (int) $sectionDivisionId !== $divisionId) {
                throw ValidationException::withMessages([
                    'section_unit_id' => ['The selected section/unit does not belong to the selected division.'],
                ]);
            }
        }

        if ($branchId !== null && $divisionId !== null) {
            $divisionBranchId = Division::query()->whereKey($divisionId)->value('branch_id');
            if ($divisionBranchId !== null && (int) $divisionBranchId !== $branchId) {
                throw ValidationException::withMessages([
                    'division_id' => ['The selected division does not belong to the selected branch.'],
                ]);
            }
        }

        if ($branchId !== null && $departmentId !== null) {
            $departmentBranchId = Department::query()->whereKey($departmentId)->value('branch_id');
            if ($departmentBranchId !== null && (int) $departmentBranchId !== $branchId) {
                throw ValidationException::withMessages([
                    'department_id' => ['The selected department does not belong to the selected branch.'],
                ]);
            }
        }
    }
}
