<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;
use App\Support\HrApprovalStages;

/**
 * Centralized hierarchy chain for organization approvals.
 *
 * Builds concrete approval steps from the subject's organization assignment:
 * Section/Unit Head -> Division Head -> Department Head -> Branch Head -> Company Head -> Admin HR.
 */
class HrApprovalChainResolver
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
    ) {}

    /**
     * @return array<int, HrRole>|null
     */
    public function getApprovalChain(User $employee, bool $employeeSubmitted = true): ?array
    {
        $steps = $this->resolveApprovalChain($employee, null, $employeeSubmitted ? $employee : null);

        return $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
    }

    /**
     * Resolve ordered concrete approver steps for a request subject.
     *
     * @return array<int, array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }>
     */
    public function resolveApprovalChain(User|int $employee, ?string $moduleType = null, ?User $requestor = null): array
    {
        $subject = $employee instanceof User
            ? $employee
            : User::query()->findOrFail($employee);

        $subject = $subject->loadMissing([
            'company',
            'branch.company',
            'departmentRelation.branch.company',
            'division.department.branch.company',
            'division.branch.company',
            'division.company',
            'sectionUnit.division.department.branch.company',
            'sectionUnit.division.branch.company',
            'sectionUnit.division.company',
            'sectionUnit.department.branch.company',
            'sectionUnit.branch.company',
            'sectionUnit.company',
        ]);

        $subjectRole = $this->roleResolver->resolveForApprovalSubject($subject);
        $candidateRoles = $this->candidateRolesForSubject($subjectRole);
        $requestorId = $requestor ? (int) $requestor->id : (int) $subject->id;
        $subjectId = (int) $subject->id;
        $usedApproverIds = [];
        $steps = [];

        foreach ($candidateRoles as $role) {
            $approver = $this->resolveApproverForRole($subject, $role);
            if (! $approver) {
                continue;
            }

            $approverId = (int) $approver->id;
            if ($role !== HrRole::AdminHr && ($approverId === $subjectId || $approverId === $requestorId)) {
                continue;
            }

            if (in_array($approverId, $usedApproverIds, true)) {
                continue;
            }

            $usedApproverIds[] = $approverId;
            $steps[] = [
                'approval_level' => $role->value,
                'approver_role' => $role,
                'approver_id' => $approverId,
                'approver_name' => $approver->display_name,
                'approver' => $approver,
                'sequence_order' => count($steps) + 1,
            ];
        }

        return $steps;
    }

    /**
     * Shared centralized approval routing for employee-submitted requests.
     *
     * @return array{
     *   chain: array<int, HrRole>|null,
     *   fallback_to_admin: bool,
     *   fallback_reasons: array<int, string>,
     *   first_level_approver: ?User,
     *   hr_approver: ?User
     * }
     */
    public function resolveRoutingDecision(User $employee, bool $employeeSubmitted = true): array
    {
        $steps = $this->resolveApprovalChain($employee, null, $employeeSubmitted ? $employee : null);
        $chain = $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return [
            'chain' => $chain,
            'fallback_to_admin' => false,
            'fallback_reasons' => $chain === null ? ['no_active_admin_hr'] : [],
            'first_level_approver' => $firstLine['approver'] ?? null,
            'hr_approver' => $this->resolveHrApprover(),
        ];
    }

    public function initialApprovalStage(User $employee, bool $employeeSubmitted = true): string
    {
        $chain = $this->getApprovalChain($employee, $employeeSubmitted);
        if ($chain === null) {
            return HrApprovalStages::REJECTED;
        }

        if (count($chain) === 1 && $chain[0] === HrRole::AdminHr) {
            return HrApprovalStages::PENDING_SECOND;
        }

        return HrApprovalStages::PENDING_FIRST;
    }

    public function isFirstLevelApprover(User $actor, User $subjectEmployee): bool
    {
        $steps = $this->resolveApprovalChain($subjectEmployee, null, $subjectEmployee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine !== null && (int) $firstLine['approver_id'] === (int) $actor->id;
    }

    public function resolveFirstLevelApprover(User $employee): ?User
    {
        $steps = $this->resolveApprovalChain($employee, null, $employee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine['approver'] ?? null;
    }

    public function resolveHrApprover(): ?User
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->active()
            ->orderByDesc('is_super_admin')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<HrRole>
     */
    private function candidateRolesForSubject(HrRole $subjectRole): array
    {
        return match ($subjectRole) {
            HrRole::Employee => [
                HrRole::SectionUnitHead,
                HrRole::DivisionHead,
                HrRole::DepartmentHead,
                HrRole::BranchHead,
                HrRole::CompanyHead,
                HrRole::AdminHr,
            ],
            HrRole::SectionUnitHead => [
                HrRole::DivisionHead,
                HrRole::DepartmentHead,
                HrRole::BranchHead,
                HrRole::CompanyHead,
                HrRole::AdminHr,
            ],
            HrRole::DivisionHead => [
                HrRole::DepartmentHead,
                HrRole::BranchHead,
                HrRole::CompanyHead,
                HrRole::AdminHr,
            ],
            HrRole::DepartmentHead => [
                HrRole::BranchHead,
                HrRole::CompanyHead,
                HrRole::AdminHr,
            ],
            HrRole::BranchHead => [
                HrRole::CompanyHead,
                HrRole::AdminHr,
            ],
            HrRole::CompanyHead, HrRole::AdminHr => [
                HrRole::AdminHr,
            ],
        };
    }

    private function resolveApproverForRole(User $employee, HrRole $role): ?User
    {
        return match ($role) {
            HrRole::SectionUnitHead => $this->resolveSectionUnitHeadFor($employee),
            HrRole::DivisionHead => $this->resolveDivisionHeadFor($employee),
            HrRole::DepartmentHead => $this->resolveDepartmentHeadFor($employee),
            HrRole::BranchHead => $this->resolveBranchHeadFor($employee),
            HrRole::CompanyHead => $this->resolveCompanyHeadFor($employee),
            HrRole::AdminHr => $this->resolveHrApprover(),
            HrRole::Employee => null,
        };
    }

    private function resolveSectionUnitHeadFor(User $employee): ?User
    {
        $section = $this->sectionUnitFor($employee);
        if (! $section?->section_unit_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($section->section_unit_head_id);
    }

    private function resolveDivisionHeadFor(User $employee): ?User
    {
        $division = $this->divisionFor($employee);
        if (! $division?->division_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($division->division_head_id);
    }

    private function resolveDepartmentHeadFor(User $employee): ?User
    {
        $department = $this->departmentFor($employee);
        if (! $department?->department_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($department->department_head_id);
    }

    private function resolveBranchHeadFor(User $employee): ?User
    {
        $branch = $this->branchFor($employee);
        if (! $branch?->branch_manager_id) {
            return null;
        }

        return User::query()->activeRoster()->find($branch->branch_manager_id);
    }

    private function resolveCompanyHeadFor(User $employee): ?User
    {
        $company = $this->companyFor($employee);
        if (! $company?->company_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($company->company_head_id);
    }

    private function sectionUnitFor(User $employee): ?SectionUnit
    {
        if ($employee->relationLoaded('sectionUnit') && $employee->sectionUnit) {
            return $employee->sectionUnit;
        }

        return $employee->section_unit_id ? SectionUnit::query()->find($employee->section_unit_id) : null;
    }

    private function divisionFor(User $employee): ?Division
    {
        if ($employee->relationLoaded('division') && $employee->division) {
            return $employee->division;
        }
        if ($employee->division_id) {
            return Division::query()->find($employee->division_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->division_id) {
            return Division::query()->find($section->division_id);
        }

        return null;
    }

    private function departmentFor(User $employee): ?Department
    {
        if ($employee->relationLoaded('departmentRelation') && $employee->departmentRelation) {
            return $employee->departmentRelation;
        }
        if ($employee->department_id) {
            return Department::query()->find($employee->department_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->department_id) {
            return Department::query()->find($section->department_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->department_id) {
            return Department::query()->find($division->department_id);
        }

        $deptName = trim((string) ($employee->department ?? ''));

        return $deptName !== '' ? Department::query()->where('name', $deptName)->first() : null;
    }

    private function branchFor(User $employee): ?Branch
    {
        if ($employee->relationLoaded('branch') && $employee->branch) {
            return $employee->branch;
        }
        if ($employee->branch_id) {
            return Branch::query()->find($employee->branch_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->branch_id) {
            return Branch::query()->find($section->branch_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->branch_id) {
            return Branch::query()->find($division->branch_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->branch_id) {
            return Branch::query()->find($department->branch_id);
        }

        return null;
    }

    private function companyFor(User $employee): ?Company
    {
        if ($employee->relationLoaded('company') && $employee->company) {
            return $employee->company;
        }
        if ($employee->company_id) {
            return Company::query()->find($employee->company_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->company_id) {
            return Company::query()->find($section->company_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->company_id) {
            return Company::query()->find($division->company_id);
        }

        $branch = $this->branchFor($employee);
        if ($branch?->company_id) {
            return Company::query()->find($branch->company_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->branch_id) {
            $deptBranch = Branch::query()->find($department->branch_id);
            if ($deptBranch?->company_id) {
                return Company::query()->find($deptBranch->company_id);
            }
        }

        return null;
    }
}
