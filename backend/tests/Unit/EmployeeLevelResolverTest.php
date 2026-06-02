<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\EmployeeLevelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeLevelResolverTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'employee_level')) {
            $this->markTestSkipped('Employee level columns are not available.');
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

    public function test_staff_employee_resolves_level_zero(): void
    {
        [, , , $section] = $this->organization();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'section_unit_id' => (int) $section->id,
            'is_active' => true,
        ]);

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(0, $resolved['level_number']);
        $this->assertSame('Staff / Employee', $resolved['level_name']);
    }

    public function test_team_leader_resolves_level_one(): void
    {
        [, , , $section] = $this->organization();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'section_unit_id' => (int) $section->id,
            'is_active' => true,
        ]);

        DB::table('section_unit_team_leaders')->insert([
            'section_unit_id' => (int) $section->id,
            'employee_id' => (int) $employee->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(1, $resolved['level_number']);
        $this->assertSame('OIC / Team Leader / Unit/Section Head', $resolved['level_name']);
    }

    public function test_section_head_resolves_level_one(): void
    {
        [, , , $section] = $this->organization();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'section_unit_id' => (int) $section->id,
            'is_active' => true,
        ]);
        $section->forceFill(['section_unit_head_id' => (int) $employee->id])->save();

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(1, $resolved['level_number']);
        $this->assertSame('OIC / Team Leader / Unit/Section Head', $resolved['level_name']);
    }

    public function test_department_head_resolves_level_two(): void
    {
        [, , $department] = $this->organization();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'department_id' => (int) $department->id,
            'is_active' => true,
        ]);
        $department->forceFill(['department_head_id' => (int) $employee->id])->save();

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(2, $resolved['level_number']);
        $this->assertSame('Department Head', $resolved['level_name']);
    }

    public function test_highest_active_assignment_wins(): void
    {
        [, , $department, $section] = $this->organization();
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'department_id' => (int) $department->id,
            'section_unit_id' => (int) $section->id,
            'is_active' => true,
        ]);
        DB::table('section_unit_team_leaders')->insert([
            'section_unit_id' => (int) $section->id,
            'employee_id' => (int) $employee->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $department->forceFill(['department_head_id' => (int) $employee->id])->save();

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(2, $resolved['level_number']);
    }

    public function test_admin_role_resolves_level_six(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(6, $resolved['level_number']);
        $this->assertSame('Admin', $resolved['level_name']);
    }

    public function test_executive_resolves_level_five(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_execom' => true,
            'is_active' => true,
        ]);

        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        $this->assertSame(5, $resolved['level_number']);
        $this->assertSame('Company Head / Executive', $resolved['level_name']);
    }

    /**
     * @return array{Company, Branch, Department, SectionUnit}
     */
    private function organization(): array
    {
        $company = Company::query()->create(['name' => 'Resolver Co '.uniqid()]);
        $branch = Branch::query()->create([
            'name' => 'Resolver Branch '.uniqid(),
            'company_id' => (int) $company->id,
        ]);
        $department = Department::query()->create([
            'name' => 'Resolver Dept '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
        ]);
        $section = SectionUnit::query()->create([
            'name' => 'Resolver Section '.uniqid(),
            'company_id' => (int) $company->id,
            'branch_id' => (int) $branch->id,
            'department_id' => (int) $department->id,
            'status' => 'active',
        ]);

        return [$company, $branch, $department, $section];
    }
}
