<?php

namespace Tests\Unit;

use App\Support\BranchEmployeeCounts;
use Tests\TestCase;

class BranchEmployeeCountsSubqueryTest extends TestCase
{
    public function test_branch_employee_counts_subquery_groups_distinct_users_by_branch(): void
    {
        $subquery = BranchEmployeeCounts::subquery();
        $sql = strtolower($subquery->toSql());

        $this->assertStringContainsString('group by', $sql);
        $this->assertStringContainsString('count(distinct user_id)', $sql);
        $this->assertStringContainsString('union', $sql);
    }
}
