<?php

namespace App\Support;

use App\Models\User;
use App\Services\OrganizationLeadershipAssignmentService;

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
        /** @var OrganizationLeadershipAssignmentService $assignments */
        $assignments = app(OrganizationLeadershipAssignmentService::class);

        if ($assignments->companyIdsLedBy($user)->isNotEmpty()) {
            return 'company_head';
        }

        if ($assignments->branchIdsLedBy($user)->isNotEmpty()) {
            return 'branch_head';
        }

        if ($assignments->divisionIdsLedBy($user)->isNotEmpty()) {
            return 'division_head';
        }

        if ($assignments->departmentIdsLedBy($user)->isNotEmpty()) {
            return 'department_head';
        }

        if ($assignments->sectionUnitIdsLedBy($user)->isNotEmpty()) {
            return 'section_unit_head';
        }

        return null;
    }
}
