<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;

/**
 * Resolves evaluator users from role types for evaluation assignments.
 * ponytail: mirrors org-head lookup from HrApprovalChainResolver; upgrade path is extracting shared OrgHeadResolver.
 */
class EvaluationEvaluatorResolver
{
    /** @var list<string> */
    public const ROLE_TYPES = [
        'immediate_supervisor',
        'section_head',
        'department_head',
        'division_head',
        'area_head',
        'branch_head',
        'company_head',
        'hr',
        'self',
        'custom',
    ];

    /** Reporting-chain roles resolved from the org hierarchy (ordered nearest → highest). */
    public const HIERARCHY_ROLES = [
        'immediate_supervisor',
        'section_head',
        'department_head',
        'division_head',
        'area_head',
        'branch_head',
        'company_head',
    ];

    /** Organizational options always offered regardless of an employee's reporting chain. */
    public const SPECIAL_ROLES = [
        'hr',
        'self',
        'custom',
    ];

    /**
     * Analyze the selected employees' reporting chains and return only the hierarchy
     * evaluator levels that actually resolve to someone, with coverage counts and the
     * distinct evaluator names at each level.
     *
     * @param  iterable<User>  $employees
     * @return array{
     *     hierarchy: list<array{role: string, label: string, employee_count: int, evaluator_count: int, evaluators: list<string>}>,
     *     special: list<array{role: string, label: string}>,
     *     employee_count: int
     * }
     */
    public function previewForEmployees(iterable $employees): array
    {
        $employees = is_array($employees) ? $employees : iterator_to_array($employees);
        $employeeCount = count($employees);

        $hierarchy = [];

        foreach (self::HIERARCHY_ROLES as $role) {
            $covered = 0;
            $evaluatorNames = [];

            foreach ($employees as $employee) {
                $evaluator = $this->resolveRole($employee, $role);
                if ($evaluator && (int) $evaluator->id !== (int) $employee->id) {
                    $covered++;
                    $evaluatorNames[(int) $evaluator->id] = $this->displayName($evaluator);
                }
            }

            if ($covered === 0) {
                continue;
            }

            $hierarchy[] = [
                'role' => $role,
                'label' => $this->roleLabel($role),
                'employee_count' => $covered,
                'evaluator_count' => count($evaluatorNames),
                'evaluators' => array_values($evaluatorNames),
            ];
        }

        $special = array_map(fn (string $role) => [
            'role' => $role,
            'label' => $this->roleLabel($role),
        ], self::SPECIAL_ROLES);

        return [
            'hierarchy' => $hierarchy,
            'special' => $special,
            'employee_count' => $employeeCount,
        ];
    }

    private function displayName(User $user): string
    {
        $name = trim(implode(' ', array_filter([
            $user->first_name,
            $user->last_name,
        ])));

        return $name !== '' ? $name : ($user->name ?? 'Employee #' . $user->id);
    }

    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    /**
     * @param  list<string>  $roleTypes
     * @param  list<int>  $customEvaluatorIds
     * @return list<array{role: string, user: User}>
     */
    public function resolve(User $employee, array $roleTypes, array $customEvaluatorIds = []): array
    {
        $resolved = [];

        foreach ($roleTypes as $role) {
            if ($role === 'custom') {
                foreach ($customEvaluatorIds as $userId) {
                    $user = User::query()->activeRoster()->find((int) $userId);
                    if ($user) {
                        // Picking the employee themselves as a custom evaluator counts as a self evaluation.
                        $isSelf = (int) $user->id === (int) $employee->id;
                        $resolved[] = ['role' => $isSelf ? 'self' : 'custom', 'user' => $user];
                    }
                }
                continue;
            }

            if ($role === 'peer') {
                continue; // ponytail: peer selection uses custom_evaluator_ids for now
            }

            $user = $this->resolveRole($employee, $role);
            if ($user && ($role === 'self' || (int) $user->id !== (int) $employee->id)) {
                $resolved[] = ['role' => $role, 'user' => $user];
            }
        }

        // De-dupe by user id, keep first role label
        $seen = [];
        $unique = [];
        foreach ($resolved as $entry) {
            $id = (int) $entry['user']->id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $entry;
        }

        return $unique;
    }

    public function roleLabel(string $role): string
    {
        return match ($role) {
            'immediate_supervisor' => 'Immediate Supervisor',
            'section_head' => 'Section Head',
            'department_head' => 'Department Head',
            'division_head' => 'Division Head',
            'area_head' => 'Area Head',
            'branch_head' => 'Branch Head',
            'company_head' => 'Company Head',
            'hr' => 'HR',
            'self' => 'Self Evaluation',
            'peer' => 'Peer',
            'custom' => 'Custom Evaluator',
            default => ucwords(str_replace('_', ' ', $role)),
        };
    }

    private function resolveRole(User $employee, string $role): ?User
    {
        return match ($role) {
            'immediate_supervisor' => $this->resolveImmediateSupervisor($employee),
            'section_head' => $this->resolveSectionHead($employee),
            'department_head' => $this->resolveDepartmentHead($employee),
            'division_head' => $this->resolveDivisionHead($employee),
            'area_head' => $this->resolveAreaHead($employee),
            'branch_head' => $this->resolveBranchHead($employee),
            'company_head' => $this->resolveCompanyHead($employee),
            'hr' => $this->resolveHrContact($employee),
            'self' => $employee,
            default => null,
        };
    }

    private function resolveImmediateSupervisor(User $employee): ?User
    {
        if (! $employee->supervisor_id) {
            return null;
        }

        return User::query()->activeRoster()->find($employee->supervisor_id);
    }

    private function resolveSectionHead(User $employee): ?User
    {
        $section = $this->sectionUnitFor($employee);
        if ($section?->section_unit_head_id) {
            $head = User::query()->activeRoster()->find($section->section_unit_head_id);
            if ($head) {
                return $head;
            }
        }

        return null;
    }

    private function resolveDepartmentHead(User $employee): ?User
    {
        $department = $this->departmentFor($employee);
        if (! $department?->department_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($department->department_head_id);
    }

    private function resolveDivisionHead(User $employee): ?User
    {
        $division = $this->divisionFor($employee);
        if (! $division?->division_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($division->division_head_id);
    }

    private function resolveAreaHead(User $employee): ?User
    {
        $area = $this->areaFor($employee);
        if (! $area?->area_manager_employee_id) {
            return null;
        }

        return User::query()->activeRoster()->find($area->area_manager_employee_id);
    }

    private function resolveBranchHead(User $employee): ?User
    {
        $branch = $this->branchFor($employee);
        if (! $branch?->branch_manager_id) {
            return null;
        }

        return User::query()->activeRoster()->find($branch->branch_manager_id);
    }

    private function resolveCompanyHead(User $employee): ?User
    {
        $company = $this->companyFor($employee);
        if (! $company?->company_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($company->company_head_id);
    }

    private function resolveHrContact(User $employee): ?User
    {
        $companyId = $this->companyFor($employee)?->id ?? $employee->company_id;
        if (! $companyId) {
            return null;
        }

        return User::query()
            ->activeRoster()
            ->where('company_id', $companyId)
            ->get()
            ->first(fn (User $user) => $this->hrRoleResolver->resolve($user) === HrRole::AdminHr);
    }

    private function sectionUnitFor(User $employee): ?SectionUnit
    {
        return $employee->section_unit_id
            ? SectionUnit::query()->find($employee->section_unit_id)
            : null;
    }

    private function departmentFor(User $employee): ?Department
    {
        if ($employee->department_id) {
            return Department::query()->find($employee->department_id);
        }

        $section = $this->sectionUnitFor($employee);

        return $section?->department_id
            ? Department::query()->find($section->department_id)
            : null;
    }

    private function divisionFor(User $employee): ?Division
    {
        if ($employee->division_id) {
            return Division::query()->find($employee->division_id);
        }

        $department = $this->departmentFor($employee);

        return $department?->division_id
            ? Division::query()->find($department->division_id)
            : null;
    }

    private function branchFor(User $employee): ?Branch
    {
        if ($employee->branch_id) {
            return Branch::query()->find($employee->branch_id);
        }

        $department = $this->departmentFor($employee);

        return $department?->branch_id
            ? Branch::query()->find($department->branch_id)
            : null;
    }

    private function areaFor(User $employee): ?Area
    {
        $branch = $this->branchFor($employee);

        return $branch?->area_id
            ? Area::query()->find($branch->area_id)
            : null;
    }

    private function companyFor(User $employee): ?Company
    {
        if ($employee->company_id) {
            return Company::query()->find($employee->company_id);
        }

        $branch = $this->branchFor($employee);

        return $branch?->company_id
            ? Company::query()->find($branch->company_id)
            : null;
    }
}
