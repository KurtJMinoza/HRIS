<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\SectionUnitRosterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SectionUnitRosterServiceTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('employee_organization_assignments') || ! Schema::hasTable('sections_or_units')) {
            $this->markTestSkipped('Section/Unit assignment tables are not available.');
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

    public function test_shared_assignment_appears_in_section_roster_and_count(): void
    {
        [$companyA, $companyB, $sectionB] = $this->seedSectionInCompanyB();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $companyA->id,
            'is_active' => true,
        ]);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'section_unit',
            (int) $sectionB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $roster = app(SectionUnitRosterService::class);
        $counts = $roster->countsForSection($sectionB->fresh());
        $members = $roster->rosterForSection($sectionB->fresh());

        $this->assertSame(1, $counts['assigned_employee_count']);
        $this->assertSame(1, $counts['shared_employee_count']);
        $this->assertSame(0, $counts['primary_employee_count']);
        $this->assertCount(1, $members);
        $this->assertSame((int) $employee->id, $members[0]['employee_id']);
        $this->assertSame(EmployeeOrganizationAssignment::TYPE_SHARED, $members[0]['source']);
        $this->assertSame((int) $companyA->id, (int) $employee->fresh()->company_id);
    }

    public function test_shared_assignment_persists_after_refresh(): void
    {
        [$companyA, , $sectionB] = $this->seedSectionInCompanyB();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'company_id' => (int) $companyA->id,
            'is_active' => true,
        ]);

        app(EmployeeOrganizationAssignmentService::class)->assignToLegacyUnit(
            'section_unit',
            (int) $sectionB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $roster = app(SectionUnitRosterService::class);
        $first = $roster->rosterForSection($sectionB->fresh());
        $second = $roster->rosterForSection($sectionB->fresh()->refresh());

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(EmployeeOrganizationAssignment::TYPE_SHARED, $second[0]['source']);
    }

    public function test_three_shared_assignments_increase_assigned_count(): void
    {
        [, , $sectionB] = $this->seedSectionInCompanyB();
        $assignService = app(EmployeeOrganizationAssignmentService::class);

        foreach (range(1, 3) as $index) {
            $employee = User::factory()->create([
                'role' => User::ROLE_EMPLOYEE,
                'is_active' => true,
            ]);
            $assignService->assignToLegacyUnit(
                'section_unit',
                (int) $sectionB->id,
                [(int) $employee->id],
                EmployeeOrganizationAssignmentService::MODE_SHARED,
            );
        }

        $counts = app(SectionUnitRosterService::class)->countsForSection($sectionB->fresh());
        $this->assertSame(3, $counts['assigned_employee_count']);
        $this->assertSame(3, $counts['shared_employee_count']);
    }

    public function test_duplicate_shared_assignment_is_rejected(): void
    {
        [, , $sectionB] = $this->seedSectionInCompanyB();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);

        $service = app(EmployeeOrganizationAssignmentService::class);
        $service->assignToLegacyUnit(
            'section_unit',
            (int) $sectionB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );

        $this->expectException(ValidationException::class);
        $service->assignToLegacyUnit(
            'section_unit',
            (int) $sectionB->id,
            [(int) $employee->id],
            EmployeeOrganizationAssignmentService::MODE_SHARED,
        );
    }

    public function test_primary_and_shared_same_section_counts_once_as_primary(): void
    {
        [, , $sectionB] = $this->seedSectionInCompanyB();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'section_unit_id' => (int) $sectionB->id,
            'is_active' => true,
        ]);

        EmployeeOrganizationAssignment::query()->create([
            'employee_id' => (int) $employee->id,
            'organization_unit_id' => $this->organizationUnitIdForSection($sectionB),
            'assignment_type' => EmployeeOrganizationAssignment::TYPE_SHARED,
            'section_unit_id' => (int) $sectionB->id,
            'is_primary' => false,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
        ]);

        $counts = app(SectionUnitRosterService::class)->countsForSection($sectionB->fresh());
        $this->assertSame(1, $counts['assigned_employee_count']);
        $this->assertSame(1, $counts['primary_employee_count']);
        $this->assertSame(0, $counts['shared_employee_count']);
    }

    /**
     * @return array{0: Company, 1: Company, 2: SectionUnit}
     */
    private function seedSectionInCompanyB(): array
    {
        $companyA = Company::query()->create(['name' => 'Company A '.uniqid()]);
        $companyB = Company::query()->create(['name' => 'Company B '.uniqid()]);
        $branchB = Branch::query()->create([
            'name' => 'Main '.uniqid(),
            'company_id' => (int) $companyB->id,
        ]);
        $departmentB = Department::query()->create([
            'name' => 'HR '.uniqid(),
            'company_id' => (int) $companyB->id,
            'branch_id' => (int) $branchB->id,
        ]);
        $sectionB = SectionUnit::query()->create([
            'name' => 'Unit '.uniqid(),
            'company_id' => (int) $companyB->id,
            'branch_id' => (int) $branchB->id,
            'department_id' => (int) $departmentB->id,
            'status' => 'active',
        ]);

        app(EmployeeOrganizationAssignmentService::class);
        app(\App\Services\LegacyOrganizationMirrorService::class)->syncLegacyRecord('section_unit', (int) $sectionB->id);

        return [$companyA, $companyB, $sectionB];
    }

    private function organizationUnitIdForSection(SectionUnit $section): int
    {
        $id = \App\Models\OrganizationUnit::query()
            ->where('legacy_source_type', 'section_unit')
            ->where('legacy_source_id', (int) $section->id)
            ->value('id');

        return (int) $id;
    }
}
