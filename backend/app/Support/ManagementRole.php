<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;

/**
 * Org "hat" for an employee (company / branch / department head), used for profile UI scoping
 * and Salary tab copy — independent of Laravel admin vs employee account type.
 *
 * Priority matches {@see \App\Services\HrRoleResolver::resolveOrgHierarchyFromAssignments}.
 */
final class ManagementRole
{
    /**
     * @return 'company_head'|'branch_head'|'department_head'|'division_head'|'section_unit_head'|null
     */
    public static function resolve(User $user): ?string
    {
        $user->loadMissing([
            'companyHeadships:id,name,company_head_id',
            'managedBranch:id,name,company_id,branch_manager_id',
            'managedDepartment:id,name,branch_id,department_head_id',
            'departmentRelation:id,name,branch_id,department_head_id',
            'managedDivision:id,name,company_id,branch_id,department_id,division_head_id',
            'division:id,name,company_id,branch_id,department_id,division_head_id',
            'managedSectionUnit:id,name,company_id,branch_id,department_id,division_id,section_unit_head_id',
            'sectionUnit:id,name,company_id,branch_id,department_id,division_id,section_unit_head_id',
        ]);

        if ($user->relationLoaded('companyHeadships') && $user->companyHeadships->isNotEmpty()) {
            return 'company_head';
        }
        if (Company::query()->where('company_head_id', $user->id)->exists()) {
            return 'company_head';
        }

        if ($user->relationLoaded('managedBranch') && $user->managedBranch !== null) {
            return 'branch_head';
        }
        if (Branch::query()->where('branch_manager_id', $user->id)->exists()) {
            return 'branch_head';
        }

        if ($user->relationLoaded('managedDepartment') && $user->managedDepartment !== null) {
            return 'department_head';
        }
        if ($user->relationLoaded('departmentRelation')
            && $user->departmentRelation
            && (int) $user->departmentRelation->department_head_id === (int) $user->id) {
            return 'department_head';
        }
        if (Department::query()->where('department_head_id', $user->id)->exists()) {
            return 'department_head';
        }

        if ($user->relationLoaded('managedDivision') && $user->managedDivision !== null) {
            return 'division_head';
        }
        if ($user->relationLoaded('division')
            && $user->division
            && (int) $user->division->division_head_id === (int) $user->id) {
            return 'division_head';
        }
        if (Division::query()->where('division_head_id', $user->id)->exists()) {
            return 'division_head';
        }

        if ($user->relationLoaded('managedSectionUnit') && $user->managedSectionUnit !== null) {
            return 'section_unit_head';
        }
        if ($user->relationLoaded('sectionUnit')
            && $user->sectionUnit
            && (int) $user->sectionUnit->section_unit_head_id === (int) $user->id) {
            return 'section_unit_head';
        }
        if (SectionUnit::query()->where('section_unit_head_id', $user->id)->exists()) {
            return 'section_unit_head';
        }

        return null;
    }
}
