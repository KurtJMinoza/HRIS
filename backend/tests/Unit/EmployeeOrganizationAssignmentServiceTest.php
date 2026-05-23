<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\LegacyOrganizationMirrorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeOrganizationAssignmentServiceTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('employee_organization_assignments') || ! Schema::hasTable('organization_units')) {
            $this->markTestSkipped('Organization assignment tables are not available.');
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

    public function test_shared_assignment_across_companies_saves_successfully(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = $this->employeeInCompany($companyA);

        $service = app(EmployeeOrganizationAssignmentService::class);
        $created = $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $this->assertCount(1, $created['assignments']);
        $this->assertSame(1, $created['added_count']);
        $this->assertSame((int) $companyA->id, (int) $employee->fresh()->company_id);
        $this->assertTrue(
            EmployeeOrganizationAssignment::query()
                ->active()
                ->where('employee_id', (int) $employee->id)
                ->where('department_id', (int) $departmentB->id)
                ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_SHARED)
                ->exists()
        );
    }

    public function test_transfer_primary_assignment_changes_primary_company(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = $this->employeeInCompany($companyA);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_TRANSFER_PRIMARY,
        );

        $employee->refresh();
        $this->assertSame((int) $companyB->id, (int) $employee->company_id);
        $this->assertSame((int) $departmentB->id, (int) $employee->department_id);
        $this->assertTrue(
            EmployeeOrganizationAssignment::query()
                ->active()
                ->where('employee_id', (int) $employee->id)
                ->where('is_primary', true)
                ->where('department_id', (int) $departmentB->id)
                ->exists()
        );
    }

    public function test_employee_profile_lists_primary_and_shared_assignments(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = $this->employeeInCompany($companyA);
        app(LegacyOrganizationMirrorService::class)->syncUserAssignment($employee->fresh());
        $service = app(EmployeeOrganizationAssignmentService::class);

        $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $assignments = $service->assignmentsForEmployee($employee->fresh());
        $types = collect($assignments)->pluck('assignment_type')->all();

        $this->assertGreaterThanOrEqual(2, count($assignments));
        $this->assertContains(EmployeeOrganizationAssignment::TYPE_SHARED, $types);
        $this->assertContains(EmployeeOrganizationAssignment::TYPE_PRIMARY, $types);
    }

    public function test_cross_company_division_shared_assignment_is_allowed(): void
    {
        [$companyA, $companyB] = $this->seedTwoCompanies();
        $divisionB = Division::query()->create([
            'name' => 'AWIC '.uniqid(),
            'code' => 'AW'.random_int(1000, 9999),
            'company_id' => (int) $companyB->id,
            'branch_id' => null,
            'status' => 'active',
        ]);
        $employee = $this->employeeInCompany($companyA);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'division',
            (int) $divisionB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $this->assertTrue(
            EmployeeOrganizationAssignment::query()
                ->active()
                ->where('employee_id', (int) $employee->id)
                ->where('division_id', (int) $divisionB->id)
                ->exists()
        );
    }

    public function test_inactive_employee_cannot_be_assigned(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $companyA->id,
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );
    }

    public function test_resubmitting_existing_assignment_skips_without_error(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = $this->employeeInCompany($companyA);
        $service = app(EmployeeOrganizationAssignmentService::class);

        $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $result = $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $this->assertSame(0, $result['added_count']);
        $this->assertSame(1, $result['skipped_existing_count']);
        $this->assertCount(0, $result['assignments']);
    }

    public function test_add_second_employee_while_resubmitting_existing(): void
    {
        [$companyA, $companyB, $departmentB] = $this->seedTwoCompanyDepartments();
        $first = $this->employeeInCompany($companyA);
        $second = $this->employeeInCompany($companyA);
        $service = app(EmployeeOrganizationAssignmentService::class);

        $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $first->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $result = $service->assignToLegacyUnit(
            'department',
            (int) $departmentB->id,
            [(int) $first->id, (int) $second->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $this->assertSame(1, $result['added_count']);
        $this->assertSame(1, $result['skipped_existing_count']);
        $this->assertSame(2, $result['final_assigned_count']);
    }

    public function test_request_context_filters_partial_primary_and_defaults_to_complete_shared_section(): void
    {
        [$companyA, , $departmentB] = $this->seedTwoCompanyDepartments();
        $employee = $this->employeeInCompany($companyA);
        app(LegacyOrganizationMirrorService::class)->syncUserAssignment($employee->fresh());

        $sectionId = DB::table('sections_or_units')->insertGetId([
            'name' => 'Leasing Ops '.uniqid(),
            'company_id' => (int) $departmentB->company_id,
            'branch_id' => (int) $departmentB->branch_id,
            'department_id' => (int) $departmentB->id,
            'division_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(LegacyOrganizationMirrorService::class)->syncLegacyRecord('section_unit', (int) $sectionId);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'section_unit',
            (int) $sectionId,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $contexts = app(EmployeeOrganizationAssignmentService::class)
            ->requestContextOptionsForEmployee($employee->fresh());

        $this->assertCount(1, $contexts['assignments']);
        $this->assertSame(EmployeeOrganizationAssignment::TYPE_SHARED, $contexts['default_assignment']['assignment_type']);
        $this->assertSame((int) $sectionId, (int) $contexts['default_assignment']['section_unit_id']);
    }

    public function test_request_context_prefers_complete_shared_section_over_complete_primary_section(): void
    {
        [$companyA, , $departmentB] = $this->seedTwoCompanyDepartments();
        $branchA = Branch::query()->create([
            'name' => 'Primary Branch '.uniqid(),
            'company_id' => (int) $companyA->id,
        ]);
        $departmentA = Department::query()->create([
            'name' => 'Primary Department '.uniqid(),
            'company_id' => (int) $companyA->id,
            'branch_id' => (int) $branchA->id,
        ]);
        $primarySectionId = DB::table('sections_or_units')->insertGetId([
            'name' => 'Primary Ops '.uniqid(),
            'company_id' => (int) $companyA->id,
            'branch_id' => (int) $branchA->id,
            'department_id' => (int) $departmentA->id,
            'division_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sharedSectionId = DB::table('sections_or_units')->insertGetId([
            'name' => 'Shared Leasing Ops '.uniqid(),
            'company_id' => (int) $departmentB->company_id,
            'branch_id' => (int) $departmentB->branch_id,
            'department_id' => (int) $departmentB->id,
            'division_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employee = $this->employeeInCompany($companyA);
        $service = app(EmployeeOrganizationAssignmentService::class);

        $service->assignToLegacyUnit(
            'section_unit',
            (int) $primarySectionId,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_TRANSFER_PRIMARY,
        );
        $service->assignToLegacyUnit(
            'section_unit',
            (int) $sharedSectionId,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $contexts = $service->requestContextOptionsForEmployee($employee->fresh());

        $this->assertCount(1, $contexts['assignments']);
        $this->assertSame(EmployeeOrganizationAssignment::TYPE_SHARED, $contexts['default_assignment']['assignment_type']);
        $this->assertSame((int) $sharedSectionId, (int) $contexts['default_assignment']['section_unit_id']);
    }

    /**
     * @return array{0: Company, 1: Company, 2: Department}
     */
    private function seedTwoCompanyDepartments(): array
    {
        [$companyA, $companyB] = $this->seedTwoCompanies();
        $branchB = Branch::query()->create([
            'name' => 'Main Branch '.uniqid(),
            'company_id' => (int) $companyB->id,
        ]);
        $departmentB = Department::query()->create([
            'name' => 'HR '.uniqid(),
            'company_id' => (int) $companyB->id,
            'branch_id' => (int) $branchB->id,
        ]);

        return [$companyA, $companyB, $departmentB];
    }

    /**
     * @return array{0: Company, 1: Company}
     */
    private function seedTwoCompanies(): array
    {
        $companyA = Company::query()->create(['name' => 'Company A '.uniqid()]);
        $companyB = Company::query()->create(['name' => 'Company B '.uniqid()]);

        return [$companyA, $companyB];
    }

    private function employeeInCompany(Company $company): User
    {
        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $company->id,
            'is_active' => true,
        ]);
    }
}
