<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;

class HrRoleResolver
{
    /**
     * Resolve HR panel role. Admin accounts are always ADMIN (HR).
     * For employees: company head > branch head > department head > employee.
     */
    public function resolve(User $user): HrRole
    {
        if ($user->isAdmin()) {
            return HrRole::AdminHr;
        }

        return $this->resolveOrgHierarchyRole($user);
    }

    /**
     * Role for the *subject* of an approval chain (leave, OT, corrections).
     * Pure HR admin accounts (no org hat) are Admin (HR). Admin accounts with a line role
     * use org assignments. Employees use org hierarchy (company > branch > dept > employee).
     */
    public function resolveForApprovalSubject(User $user): HrRole
    {
        if ($user->isAdmin() && ! $this->isAssignedOrganizationHead($user)) {
            return HrRole::AdminHr;
        }

        return $this->resolveOrgHierarchyRole($user);
    }

    /**
     * Company head > branch head > department head > employee.
     * Non-employee users without an org hat resolve as Employee (legacy).
     */
    private function resolveOrgHierarchyRole(User $user): HrRole
    {
        if (! $user->isEmployee()) {
            if ($this->isAssignedOrganizationHead($user)) {
                return $this->resolveOrgHierarchyFromAssignments($user);
            }

            return HrRole::Employee;
        }

        return $this->resolveOrgHierarchyFromAssignments($user);
    }

    /**
     * Company head > branch head > department head > employee.
     */
    private function resolveOrgHierarchyFromAssignments(User $user): HrRole
    {
        if ($user->relationLoaded('companyHeadships') && $user->companyHeadships->isNotEmpty()) {
            return HrRole::CompanyHead;
        }
        if (Company::where('company_head_id', $user->id)->exists()) {
            return HrRole::CompanyHead;
        }

        if ($user->relationLoaded('managedBranch') && $user->managedBranch !== null) {
            return HrRole::BranchHead;
        }
        if (Branch::where('branch_manager_id', $user->id)->exists()) {
            return HrRole::BranchHead;
        }

        if ($user->relationLoaded('managedDepartment') && $user->managedDepartment !== null) {
            return HrRole::DepartmentHead;
        }
        if ($user->relationLoaded('departmentRelation')
            && $user->departmentRelation
            && (int) $user->departmentRelation->department_head_id === (int) $user->id) {
            return HrRole::DepartmentHead;
        }
        if (Department::where('department_head_id', $user->id)->exists()) {
            return HrRole::DepartmentHead;
        }

        return HrRole::Employee;
    }

    /**
     * Whether this user is assigned as company head, branch manager, or department head in org data.
     * Used so admin accounts that are also line managers file leave for themselves only.
     */
    public function isAssignedOrganizationHead(User $user): bool
    {
        return Company::where('company_head_id', $user->id)->exists()
            || Branch::where('branch_manager_id', $user->id)->exists()
            || Department::where('department_head_id', $user->id)->exists();
    }

    /**
     * HR-only: may create leave for another user in scope. False for org heads (they use self-service rules).
     */
    public function canFileLeaveForOthers(User $user): bool
    {
        return $user->isAdmin() && ! $this->isAssignedOrganizationHead($user);
    }

    /**
     * Regularization: Department / Branch / Company heads and HR may submit; line employees may not.
     */
    public function maySubmitRegularization(User $user): bool
    {
        return $this->resolveForApprovalSubject($user) !== HrRole::Employee;
    }

    /**
     * Regularization approve/reject: only Laravel admin accounts (HR) — {@see resolve()} is always AdminHr for admins.
     */
    public function isAdminHrAccount(User $user): bool
    {
        return $this->resolve($user) === HrRole::AdminHr;
    }
}
