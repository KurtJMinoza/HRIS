<?php

namespace Tests\Unit;

use App\Models\Division;
use App\Models\User;
use App\Services\OrgUnitEmployeeCountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrgUnitEmployeeCountServiceTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('divisions') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Division tables are not available.');
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

    public function test_for_division_returns_expected_count_shape(): void
    {
        $division = Division::query()->create([
            'name' => 'Test Division '.uniqid(),
            'code' => 'TD'.random_int(1000, 9999),
            'company_id' => null,
            'branch_id' => null,
            'division_head_id' => null,
            'status' => 'active',
            'description' => null,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'division_id' => $division->id,
        ]);

        $service = app(OrgUnitEmployeeCountService::class);
        $single = $service->forDivision($division);
        $batch = $service->forDivisions(collect([$division->fresh()]));

        $this->assertSame(1, $single['assigned_employee_count']);
        $this->assertSame(1, $single['total_employees']);
        $this->assertArrayHasKey('branch_employee_count', $single);
        $this->assertArrayHasKey('unassigned_employee_count', $single);
        $this->assertSame($single, $batch[(int) $division->id]);

        $employee->forceFill(['division_id' => null])->save();
    }

    public function test_for_division_includes_employees_in_child_departments(): void
    {
        $division = Division::query()->create([
            'name' => 'Test Division '.uniqid(),
            'code' => 'TD'.random_int(1000, 9999),
            'company_id' => null,
            'branch_id' => null,
            'division_head_id' => null,
            'status' => 'active',
            'description' => null,
        ]);

        $departmentId = DB::table('departments')->insertGetId([
            'name' => 'Finance '.uniqid(),
            'company_id' => null,
            'branch_id' => null,
            'division_id' => $division->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'division_id' => null,
            'department_id' => $departmentId,
        ]);

        $service = app(OrgUnitEmployeeCountService::class);
        $single = $service->forDivision($division);

        $this->assertSame(1, $single['assigned_employee_count']);
        $this->assertSame(1, $service->divisionMembersQuery((int) $division->id)->count());
    }

    public function test_for_divisions_returns_empty_array_for_empty_collection(): void
    {
        $service = app(OrgUnitEmployeeCountService::class);

        $this->assertSame([], $service->forDivisions(collect()));
    }
}
