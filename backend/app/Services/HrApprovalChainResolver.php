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
    public function getApprovalChain(User $employee): ?array
    {
        $role = $this->roleResolver->resolveForApprovalSubject($employee);

        return match ($role) {
            HrRole::Employee => [HrRole::DepartmentHead, HrRole::AdminHr],
            HrRole::DepartmentHead => [HrRole::BranchHead, HrRole::AdminHr],
            HrRole::BranchHead => [HrRole::CompanyHead, HrRole::AdminHr],
            HrRole::CompanyHead => [HrRole::AdminHr],
            HrRole::AdminHr => [HrRole::AdminHr],
        };
    }

    public function initialApprovalStage(User $employee): string
    {
        $chain = $this->getApprovalChain($employee);
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
        $chain = $this->getApprovalChain($employee);
        if ($chain === null || count($chain) < 2) {
            return null;
        }

        return match ($chain[0]) {
            HrRole::DepartmentHead => $this->resolveDepartmentHeadFor($employee),
            HrRole::BranchHead => $this->resolveBranchHeadFor($employee),
            HrRole::CompanyHead => $this->resolveCompanyHeadFor($employee),
            default => null,
        };
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
}
