<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\CompanyController;
use ReflectionMethod;
use Tests\TestCase;

class CompanyEmployeeCountsSubqueryTest extends TestCase
{
    public function test_company_employee_counts_subquery_groups_by_resolved_company(): void
    {
        $method = new ReflectionMethod(CompanyController::class, 'companyEmployeeCountsSubquery');
        $method->setAccessible(true);
        /** @var \Illuminate\Database\Query\Builder $subquery */
        $subquery = $method->invoke(new CompanyController(
            app(\App\Services\DataScopeService::class),
            app(\App\Services\HrRoleResolver::class),
            app(\App\Services\OrganizationLeadershipAssignmentService::class),
            app(\App\Services\OrganizationLeadershipService::class),
        ));

        $sql = strtolower($subquery->toSql());

        $this->assertStringContainsString('group by', $sql);
        $this->assertStringContainsString('count(*)', $sql);
        $this->assertStringContainsString('coalesce(u.company_id, ub.company_id, dpb.company_id)', $sql);
    }
}
