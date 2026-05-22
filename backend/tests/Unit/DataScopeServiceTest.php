<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\LegacyOrganizationMirrorService;
use App\Services\RbacService;
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

    public function test_section_team_leader_scope_defaults_to_self_only(): void
    {
        [$leader, $member, $sectionId] = $this->seedSectionTeamLeaderWithMember();

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($leader->fresh());

        $this->assertContains((int) $leader->id, $scopedIds);
        $this->assertNotContains((int) $member->id, $scopedIds);
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

    public function test_department_head_scope_always_includes_self_even_when_listed_as_branch_manager(): void
    {
        [$head, $member, $department] = $this->seedDepartmentHeadWithMember();

        Branch::query()->whereKey((int) $department->branch_id)->update([
            'branch_manager_id' => (int) $head->id,
        ]);

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($head->fresh(), 'attendance');

        $this->assertContains((int) $head->id, $scopedIds);
        $this->assertNotContains((int) $member->id, $scopedIds);

        $visibleIds = User::query()
            ->attendanceEmployees()
            ->active()
            ->tap(fn ($query) => app(DataScopeService::class)->restrictEmployeeQuery($head->fresh(), $query))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $head->id, $visibleIds);
        $this->assertNotContains((int) $member->id, $visibleIds);
    }

    public function test_department_head_approval_scope_includes_department_member_without_data_visibility(): void
    {
        [$head, $member] = $this->seedDepartmentHeadWithMember();

        $reportIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($head->fresh(), 'reports');
        $approvalIds = app(DataScopeService::class)->getApprovalScopedEmployeeIdsForUser($head->fresh());

        $this->assertContains((int) $head->id, $reportIds);
        $this->assertNotContains((int) $member->id, $reportIds);
        $this->assertContains((int) $head->id, $approvalIds);
        $this->assertContains((int) $member->id, $approvalIds);
    }

    public function test_section_head_approval_scope_includes_shared_employee_without_report_visibility(): void
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

        $reportIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($leader->fresh(), 'reports');
        $approvalIds = app(DataScopeService::class)->getApprovalScopedEmployeeIdsForUser($leader->fresh());

        $this->assertContains((int) $leader->id, $reportIds);
        $this->assertNotContains((int) $member->id, $reportIds);
        $this->assertNotContains((int) $sharedEmployee->id, $reportIds);
        $this->assertContains((int) $member->id, $approvalIds);
        $this->assertContains((int) $sharedEmployee->id, $approvalIds);
    }

    public function test_department_head_from_another_company_still_sees_own_records(): void
    {
        [$head, $member, $department] = $this->seedDepartmentHeadWithMember();
        [$otherCompany] = $this->seedOtherCompanyDepartment();
        $head->forceFill([
            'company_id' => (int) $otherCompany->id,
            'branch_id' => null,
            'department_id' => null,
        ])->save();

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($head->fresh(), 'reports');

        $this->assertContains((int) $head->id, $scopedIds);
        $this->assertNotContains((int) $member->id, $scopedIds);
        $this->assertSame((int) $head->id, (int) $department->fresh()->department_head_id);
    }

    public function test_division_head_scope_defaults_to_self_only(): void
    {
        [$head, $member] = $this->seedDivisionHeadWithMember();

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($head->fresh(), 'reports');

        $this->assertContains((int) $head->id, $scopedIds);
        $this->assertNotContains((int) $member->id, $scopedIds);
    }

    public function test_department_head_report_scope_defaults_to_own_only_with_module_access(): void
    {
        [$head, $member] = $this->seedDepartmentHeadWithMember();
        $this->grantRolePermission('department_head', 'can_access_reports_module');
        $this->grantRolePermission('department_head', 'can_view_own_reports');

        $scopedIds = app(DataScopeService::class)->getReportScopedEmployeeIds($head->fresh());

        $this->assertContains((int) $head->id, $scopedIds);
        $this->assertNotContains((int) $member->id, $scopedIds);
    }

    public function test_employee_report_scope_includes_self_with_module_access(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $this->grantRolePermission('employee', 'can_access_reports_module');
        $this->grantRolePermission('employee', 'can_view_own_reports');

        $scopedIds = app(DataScopeService::class)->getReportScopedEmployeeIds($employee->fresh());

        $this->assertSame([(int) $employee->id], $scopedIds);
    }

    public function test_report_scope_empty_without_module_access(): void
    {
        [$head] = $this->seedDepartmentHeadWithMember();

        $scopedIds = app(DataScopeService::class)->getReportScopedEmployeeIds($head->fresh());

        $this->assertSame([], $scopedIds);
    }

    public function test_department_head_with_explicit_subordinate_reports_permission_sees_department_scope(): void
    {
        [$head, $member] = $this->seedDepartmentHeadWithMember();
        $this->grantRolePermission('department_head', 'can_view_subordinate_reports');

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($head->fresh(), 'reports');

        $this->assertContains((int) $head->id, $scopedIds);
        $this->assertContains((int) $member->id, $scopedIds);
    }

    public function test_super_admin_system_user_is_not_attendance_or_report_visible(): void
    {
        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'is_super_admin' => true,
            'is_system_user' => true,
            'is_hidden' => true,
            'exclude_from_attendance' => true,
            'exclude_from_reports' => true,
            'is_active' => true,
        ]);

        $this->assertFalse(User::query()->attendanceEmployees()->whereKey((int) $superAdmin->id)->exists());
        $this->assertFalse(User::query()->reportableEmployees()->whereKey((int) $superAdmin->id)->exists());
    }

    public function test_section_team_leader_sees_shared_employee_from_another_company(): void
    {
        [$leader, $member, $sectionId] = $this->seedSectionTeamLeaderWithMember();
        $this->grantRolePermission('section_unit_head', 'can_view_subordinate_reports');
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

        $scopedIds = app(DataScopeService::class)->getScopedEmployeeIdsForUser($leader->fresh(), 'reports');

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

    private function grantRolePermission(string $roleKey, string $slug): void
    {
        $permissionId = DB::table('permissions')->updateOrInsert(
            ['slug' => $slug],
            [
                'module' => 'test',
                'label' => $slug,
                'description' => $slug,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
        DB::table('role_permissions')->updateOrInsert(
            ['role_key' => $roleKey, 'permission_id' => $permissionId],
            ['created_at' => now(), 'updated_at' => now()],
        );
        RbacService::forgetRoleCache($roleKey);
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
     * @return array{0: User, 1: User, 2: Department}
     */
    private function seedDepartmentHeadWithMember(): array
    {
        $company = Company::query()->create(['name' => 'Dept Head Co '.uniqid()]);
        $branch = Branch::query()->create([
            'name' => 'Dept Branch '.uniqid(),
            'company_id' => (int) $company->id,
        ]);
        $department = Department::query()->create([
            'name' => 'Finance '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
        ]);

        $head = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => null,
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => (int) $department->id,
            'is_active' => true,
        ]);

        Department::query()->whereKey((int) $department->id)->update([
            'department_head_id' => (int) $head->id,
        ]);

        return [$head, $member, $department];
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function seedDivisionHeadWithMember(): array
    {
        $company = Company::query()->create(['name' => 'Division Co '.uniqid()]);
        $branch = Branch::query()->create([
            'name' => 'Division Branch '.uniqid(),
            'company_id' => (int) $company->id,
        ]);
        $division = Division::query()->create([
            'name' => 'Operations Division '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
        ]);

        $head = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'division_id' => null,
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'division_id' => (int) $division->id,
            'is_active' => true,
        ]);

        Division::query()->whereKey((int) $division->id)->update([
            'division_head_id' => (int) $head->id,
        ]);

        return [$head, $member];
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
