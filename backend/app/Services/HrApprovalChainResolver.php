<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Support\HrApprovalStages;

/**
 * Shared hierarchy chain for multi-step HR approvals (corrections, overtime, leave).
 * Mirrors {@see AttendanceCorrectionApprovalService} routing rules.
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
        return $this->resolveRoutingDecision($employee, $employeeSubmitted)['chain'];
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
        $role = $this->roleResolver->resolveForApprovalSubject($employee);
        $hrApprover = $this->resolveHrApprover();

        if (! $hrApprover) {
            return [
                'chain' => null,
                'fallback_to_admin' => false,
                'fallback_reasons' => ['no_active_admin_hr'],
                'first_level_approver' => null,
                'hr_approver' => null,
            ];
        }

        if (! $employeeSubmitted) {
            $chain = match ($role) {
                HrRole::Employee => [HrRole::DepartmentHead, HrRole::AdminHr],
                HrRole::DepartmentHead => [HrRole::BranchHead, HrRole::AdminHr],
                HrRole::BranchHead => [HrRole::CompanyHead, HrRole::AdminHr],
                HrRole::CompanyHead, HrRole::AdminHr => [HrRole::AdminHr],
                default => null,
            };

            return [
                'chain' => $chain,
                'fallback_to_admin' => false,
                'fallback_reasons' => [],
                'first_level_approver' => $chain && count($chain) >= 2 ? $this->resolveFirstLevelApproverByRole($employee, $chain[0]) : null,
                'hr_approver' => $hrApprover,
            ];
        }

        $validation = $this->validateFirstLevelApproverForRole($employee, $role);
        if (! $validation['valid']) {
            return [
                'chain' => [HrRole::AdminHr],
                'fallback_to_admin' => true,
                'fallback_reasons' => $validation['reasons'],
                'first_level_approver' => null,
                'hr_approver' => $hrApprover,
            ];
        }

        // Role-based multi-level flow:
        // Employee -> Department Head -> Admin (HR)
        // Department Head -> Branch Head -> Admin (HR)
        // Branch Head -> Company Head -> Admin (HR)
        // Company Head/Admin (HR) -> Admin (HR)
        $chain = match ($role) {
            HrRole::Employee => [HrRole::DepartmentHead, HrRole::AdminHr],
            HrRole::DepartmentHead => [HrRole::BranchHead, HrRole::AdminHr],
            HrRole::BranchHead => [HrRole::CompanyHead, HrRole::AdminHr],
            HrRole::CompanyHead, HrRole::AdminHr => [HrRole::AdminHr],
        };

        return [
            'chain' => $chain,
            'fallback_to_admin' => false,
            'fallback_reasons' => [],
            'first_level_approver' => $validation['first_level_approver'],
            'hr_approver' => $hrApprover,
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
        $employeeRole = $this->roleResolver->resolveForApprovalSubject($subjectEmployee);

        // Use org assignments only (department_head_id, branch_manager_id, company_head_id).
        // Do not require resolve($actor) to be DepartmentHead/BranchHead/CompanyHead: panel users
        // with role=admin resolve as AdminHr but may still be the assigned line approver.
        if ($employeeRole === HrRole::Employee) {
            return $this->isDepartmentHeadOf($actor, $subjectEmployee);
        }

        if ($employeeRole === HrRole::DepartmentHead) {
            return $this->isBranchHeadOf($actor, $subjectEmployee);
        }

        if ($employeeRole === HrRole::BranchHead) {
            return $this->isCompanyHeadOf($actor, $subjectEmployee);
        }

        return false;
    }

    public function resolveFirstLevelApprover(User $employee): ?User
    {
        $routing = $this->resolveRoutingDecision($employee);
        $chain = $routing['chain'];
        if ($chain === null || count($chain) < 2) {
            return null;
        }

        return $routing['first_level_approver'] ?? $this->resolveFirstLevelApproverByRole($employee, $chain[0]);
    }

    public function resolveHrApprover(): ?User
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->orderByDesc('is_super_admin')
            ->orderBy('id')
            ->first();
    }

    private function isDepartmentHeadOf(User $actor, User $employee): bool
    {
        $headedDeptIds = Department::query()
            ->where('department_head_id', $actor->id)
            ->pluck('id');

        if ($headedDeptIds->isEmpty()) {
            return false;
        }

        if ($employee->department_id) {
            return $headedDeptIds->contains((int) $employee->department_id);
        }

        // Legacy rows: department_id unset but users.department matches a headed department name
        // (same rule as DataScopeService::restrictEmployeeQuery for department heads).
        $deptNames = Department::query()
            ->whereIn('id', $headedDeptIds)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        if ($deptNames === []) {
            return false;
        }

        $empDept = trim((string) ($employee->department ?? ''));
        if ($empDept === '') {
            return false;
        }

        return in_array($empDept, $deptNames, true);
    }

    private function resolveFirstLevelApproverByRole(User $employee, HrRole $role): ?User
    {
        return match ($role) {
            HrRole::DepartmentHead => $this->resolveDepartmentHeadFor($employee),
            HrRole::BranchHead => $this->resolveBranchHeadFor($employee),
            HrRole::CompanyHead => $this->resolveCompanyHeadFor($employee),
            default => null,
        };
    }

    private function resolveDepartmentHeadFor(User $employee): ?User
    {
        $department = null;
        if ($employee->department_id) {
            $department = Department::find($employee->department_id);
        }

        if (! $department) {
            $deptName = trim((string) ($employee->department ?? ''));
            if ($deptName !== '') {
                $department = Department::query()->where('name', $deptName)->first();
            }
        }

        if (! $department || ! $department->department_head_id) {
            return null;
        }

        return User::find($department->department_head_id);
    }

    private function isBranchHeadOf(User $actor, User $employee): bool
    {
        $employeeBranchId = $employee->branch_id;
        if (! $employeeBranchId && $employee->department_id) {
            $dept = Department::find($employee->department_id);
            $employeeBranchId = $dept?->branch_id;
        }

        if (! $employeeBranchId) {
            return false;
        }

        $branch = Branch::find($employeeBranchId);

        return $branch && $branch->branch_manager_id === $actor->id;
    }

    private function resolveBranchHeadFor(User $employee): ?User
    {
        $employeeBranchId = $employee->branch_id;
        if (! $employeeBranchId && $employee->department_id) {
            $dept = Department::find($employee->department_id);
            $employeeBranchId = $dept?->branch_id;
        }

        if (! $employeeBranchId) {
            return null;
        }

        $branch = Branch::find($employeeBranchId);
        if (! $branch || ! $branch->branch_manager_id) {
            return null;
        }

        return User::find($branch->branch_manager_id);
    }

    private function isCompanyHeadOf(User $actor, User $employee): bool
    {
        $employeeCompanyId = $employee->company_id;
        if (! $employeeCompanyId && $employee->branch_id) {
            $branch = Branch::find($employee->branch_id);
            $employeeCompanyId = $branch?->company_id;
        }
        if (! $employeeCompanyId && $employee->department_id) {
            $dept = Department::find($employee->department_id);
            if ($dept && $dept->branch_id) {
                $branch = Branch::find($dept->branch_id);
                $employeeCompanyId = $branch?->company_id;
            }
        }

        if (! $employeeCompanyId) {
            return false;
        }

        $company = Company::find($employeeCompanyId);

        return $company
            && $company->company_head_id !== null
            && (int) $company->company_head_id === (int) $actor->id;
    }

    private function resolveCompanyHeadFor(User $employee): ?User
    {
        $employeeCompanyId = $employee->company_id;
        if (! $employeeCompanyId && $employee->branch_id) {
            $branch = Branch::find($employee->branch_id);
            $employeeCompanyId = $branch?->company_id;
        }
        if (! $employeeCompanyId && $employee->department_id) {
            $dept = Department::find($employee->department_id);
            if ($dept && $dept->branch_id) {
                $branch = Branch::find($dept->branch_id);
                $employeeCompanyId = $branch?->company_id;
            }
        }

        if (! $employeeCompanyId) {
            return null;
        }

        $company = Company::find($employeeCompanyId);
        if (! $company || ! $company->company_head_id) {
            return null;
        }

        return User::find($company->company_head_id);
    }

    /**
     * @return array{
     *   valid: bool,
     *   reasons: array<int, string>,
     *   department_head: ?User,
     *   branch_head: ?User,
     *   company_head: ?User
     * }
     */
    private function validateFirstLevelApproverForRole(User $employee, HrRole $role): array
    {
        $reasons = [];
        $firstLevelApprover = null;

        if ($role === HrRole::Employee) {
            $firstLevelApprover = $this->resolveDepartmentHeadFor($employee);
            $this->appendApproverValidationErrors(
                $reasons,
                'department_head',
                $firstLevelApprover,
                $employee,
                fn (User $approver): bool => $this->isDepartmentHeadOf($approver, $employee)
            );
        } elseif ($role === HrRole::DepartmentHead) {
            $firstLevelApprover = $this->resolveBranchHeadFor($employee);
            $this->appendApproverValidationErrors(
                $reasons,
                'branch_head',
                $firstLevelApprover,
                $employee,
                fn (User $approver): bool => $this->isBranchHeadOf($approver, $employee)
            );
        } elseif ($role === HrRole::BranchHead) {
            $firstLevelApprover = $this->resolveCompanyHeadFor($employee);
            $this->appendApproverValidationErrors(
                $reasons,
                'company_head',
                $firstLevelApprover,
                $employee,
                fn (User $approver): bool => $this->isCompanyHeadOf($approver, $employee)
            );
        }

        return [
            'valid' => $reasons === [],
            'reasons' => $reasons,
            'first_level_approver' => $firstLevelApprover,
        ];
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function appendApproverValidationErrors(
        array &$reasons,
        string $headKey,
        ?User $approver,
        User $employee,
        callable $mappingValidator
    ): void {
        if (! $approver) {
            $reasons[] = $headKey.'_missing';

            return;
        }

        if ((int) $approver->id === (int) $employee->id) {
            $reasons[] = $headKey.'_self_approval_conflict';
        }

        if (! (bool) ($approver->is_active ?? false)) {
            $reasons[] = $headKey.'_inactive';
        }

        if (! in_array((string) $approver->role, User::ROSTER_ELIGIBLE_ROLES, true)) {
            $reasons[] = $headKey.'_wrong_role';
        }

        if (isset($approver->deleted_at) && $approver->deleted_at !== null) {
            $reasons[] = $headKey.'_soft_deleted';
        }

        if (! (bool) $mappingValidator($approver)) {
            $reasons[] = $headKey.'_invalid_organization_mapping';
        }
    }
}
