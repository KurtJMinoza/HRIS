<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;

class HrRoleResolver
{
    /**
     * Resolve HR panel role (primary badge / permission tier).
     * Laravel admins always include ADMIN (HR); organizational hat is separate — see {@see listEffectiveHrRoles()}.
     */
    public function resolve(User $user): HrRole
    {
        if ($user->isAdmin()) {
            return HrRole::AdminHr;
        }

        return $this->resolveOrgHierarchyRole($user);
    }

    /**
     * Organizational hat only (company / branch / department head, or employee), ignoring Laravel admin flag.
     * Used when applying combined Admin (HR) + head roles.
     */
    public function resolveOrganizationalRole(User $user): HrRole
    {
        return $this->resolveOrgHierarchyFromAssignments($user);
    }

    /**
     * All roles to show in UI: Admin (HR) when {@see User::isAdmin()}, plus org hat when not plain employee.
     *
     * @return list<HrRole>
     */
    public function listEffectiveHrRoles(User $user): array
    {
        $roles = [];
        if ($user->isAdmin()) {
            $roles[] = HrRole::AdminHr;
        }

        // Org hat (company / branch / department / division / section-unit head) or line employee. Admins who are also
        // roster staff (no head assignment) must still show EMPLOYEE alongside ADMIN (HR).
        $org = $this->resolveOrgHierarchyFromAssignments($user);
        if ($org !== HrRole::Employee) {
            $roles[] = $org;
        } else {
            $roles[] = HrRole::Employee;
        }

        return $roles;
    }

    /**
     * Role for the *subject* of an approval chain (leave, OT, corrections).
     * Admin (HR) has highest priority and always resolves as AdminHr.
     */
    public function resolveForApprovalSubject(User $user): HrRole
    {
        if ($user->isAdmin()) {
            return HrRole::AdminHr;
        }

        return $this->resolveOrgHierarchyRole($user);
    }

    /**
     * Company head > branch head > division head > department head > section/unit head > employee.
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
     * Company head > branch head > division head > department head > section/unit head > employee.
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

        if ($user->relationLoaded('managedDivision') && $user->managedDivision !== null) {
            return HrRole::DivisionHead;
        }
        if ($user->relationLoaded('division')
            && $user->division
            && (int) $user->division->division_head_id === (int) $user->id) {
            return HrRole::DivisionHead;
        }
        if (Division::where('division_head_id', $user->id)->exists()) {
            return HrRole::DivisionHead;
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

        if ($user->relationLoaded('managedSectionUnit') && $user->managedSectionUnit !== null) {
            return HrRole::SectionUnitHead;
        }
        if ($user->relationLoaded('sectionUnit')
            && $user->sectionUnit
            && (int) $user->sectionUnit->section_unit_head_id === (int) $user->id) {
            return HrRole::SectionUnitHead;
        }
        if (SectionUnit::where('section_unit_head_id', $user->id)->exists()) {
            return HrRole::SectionUnitHead;
        }
        if ($user->teamLeaderSections()->exists() || $user->teamLeaderDepartments()->exists()) {
            return HrRole::SectionUnitHead;
        }

        return HrRole::Employee;
    }

    /**
     * Whether this user is assigned as an organization head in org data.
     * Used so admin accounts that are also line managers file leave for themselves only.
     */
    public function isAssignedOrganizationHead(User $user): bool
    {
        return Company::where('company_head_id', $user->id)->exists()
            || Branch::where('branch_manager_id', $user->id)->exists()
            || Department::where('department_head_id', $user->id)->exists()
            || Division::where('division_head_id', $user->id)->exists()
            || SectionUnit::where('section_unit_head_id', $user->id)->exists()
            || $user->teamLeaderSections()->exists()
            || $user->teamLeaderDepartments()->exists();
    }

    /**
     * HR-only: may create leave for other users.
     * Admin (HR) override is unconditional, even when org-hat assignments exist.
     */
    public function canFileLeaveForOthers(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Regularization: org heads and HR may submit; line employees may not.
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
