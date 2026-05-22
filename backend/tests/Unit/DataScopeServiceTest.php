<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\LegacyOrganizationMirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataScopeServiceTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('section_unit_team_leaders') || ! Schema::hasTable('employee_organization_assignments')) {
            $this->markTestSkipped('Section team leader or organization assignment tables are not available.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
            $this->transactionStarted = false;
        }

        parent::tearDown();
    }

    public function test_section_team_leader_scope_always_includes_self(): void
    {
        [$leader, $member, $sectionId] = $this->seedSectionTeamLeaderWithMember();

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($leader->fresh());

        $this->assertContains((int) $leader->id, $scopedIds);
        $this->assertContains((int) $member->id, $scopedIds);
    }

    public function test_section_team_leader_can_query_own_record_via_restrict_employee_query(): void
    {
        [$leader] = $this->seedSectionTeamLeaderWithMember();

        $visibleIds = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->tap(fn ($query) => app(DataScopeService::class)->restrictEmployeeQuery($leader->fresh(), $query))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $leader->id, $visibleIds);
    }

    public function test_section_team_leader_sees_shared_employee_from_another_company(): void
    {
        [$leader, $member, $sectionId] = $this->seedSectionTeamLeaderWithMember();
        [$otherCompany, $otherDepartment] = $this->seedOtherCompanyDepartment();
        $sharedEmployee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $otherCompany->id,
            'department_id' => (int) $otherDepartment->id,
            'section_unit_id' => null,
            'is_active' => true,
        ]);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'section_unit',
            (int) $sectionId,
            [(int) $sharedEmployee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($leader->fresh());

        $this->assertContains((int) $leader->id, $scopedIds);
        $this->assertContains((int) $sharedEmployee->id, $scopedIds);
        $this->assertSame(
            EmployeeOrganizationAssignment::TYPE_SHARED,
            EmployeeOrganizationAssignment::query()
                ->active()
                ->where('employee_id', (int) $sharedEmployee->id)
                ->where('section_unit_id', (int) $sectionId)
                ->value('assignment_type'),
        );
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function seedSectionTeamLeaderWithMember(): array
    {
        $company = Company::query()->create(['name' => 'Leader Co '.uniqid()]);
        $branch = Branch::query()->create([
            'name' => 'Main '.uniqid(),
            'company_id' => (int) $company->id,
        ]);
        $department = Department::query()->create([
            'name' => 'Ops '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
        ]);
        $sectionId = (int) DB::table('sections_or_units')->insertGetId([
            'name' => 'Team Section '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => (int) $department->id,
            'division_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(LegacyOrganizationMirrorService::class)->syncLegacyRecord('section_unit', $sectionId);

        $leader = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => (int) $department->id,
            'section_unit_id' => null,
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => (int) $department->id,
            'section_unit_id' => $sectionId,
            'is_active' => true,
        ]);

        DB::table('section_unit_team_leaders')->insert([
            'section_unit_id' => $sectionId,
            'employee_id' => (int) $leader->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$leader, $member, $sectionId];
    }

    /**
     * @return array{0: Company, 1: Department}
     */
    private function seedOtherCompanyDepartment(): array
    {
        $company = Company::query()->create(['name' => 'Other Co '.uniqid()]);
        $branch = Branch::query()->create([
            'name' => 'Other Branch '.uniqid(),
            'company_id' => (int) $company->id,
        ]);
        $department = Department::query()->create([
            'name' => 'Other Dept '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
        ]);

        return [$company, $department];
    }
}
