<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidaySectionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_unit_holiday_only_applies_to_matching_section(): void
    {
        [$companyId, $branchId, $divisionId, $departmentId, $sectionAId, $sectionBId] = $this->seedOrg();

        $sectionAEmployee = User::factory()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionAId,
            'is_active' => true,
        ]);
        $sectionBEmployee = User::factory()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionBId,
            'is_active' => true,
        ]);

        Holiday::query()->create([
            'name' => 'Section A Day',
            'date' => '2026-06-15',
            'type' => 'regular',
            'scope' => 'section_unit',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionAId,
            'status' => 'active',
        ]);

        $service = app(HolidayService::class);

        $this->assertSame('Section A Day', $service->resolveHolidayForPayroll($sectionAEmployee, '2026-06-15')['name'] ?? null);
        $this->assertNull($service->resolveHolidayForPayroll($sectionBEmployee, '2026-06-15'));
    }

    public function test_section_unit_holiday_beats_nationwide_holiday_for_same_date(): void
    {
        [$companyId, $branchId, $divisionId, $departmentId, $sectionAId] = $this->seedOrg();
        $employee = User::factory()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionAId,
            'is_active' => true,
        ]);

        Holiday::query()->create([
            'name' => 'Nationwide Day',
            'date' => '2026-06-16',
            'type' => 'special',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);
        Holiday::query()->create([
            'name' => 'Section A Day',
            'date' => '2026-06-16',
            'type' => 'regular',
            'scope' => 'section_unit',
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'division_id' => $divisionId,
            'department_id' => $departmentId,
            'section_unit_id' => $sectionAId,
            'status' => 'active',
        ]);

        $holiday = app(HolidayService::class)->resolveHolidayForPayroll($employee, '2026-06-16');

        $this->assertSame('Section A Day', $holiday['name'] ?? null);
        $this->assertSame('section_unit', $holiday['scope'] ?? null);
    }

    /**
     * @return array{0:int, 1:int, 2:int, 3:int, 4:int, 5:int}
     */
    private function seedOrg(): array
    {
        $now = now();
        $companyId = DB::table('companies')->insertGetId(['name' => 'ACME', 'created_at' => $now, 'updated_at' => $now]);
        $branchId = DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Main', 'created_at' => $now, 'updated_at' => $now]);
        $divisionId = DB::table('divisions')->insertGetId(['company_id' => $companyId, 'branch_id' => $branchId, 'name' => 'Ops', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $departmentId = DB::table('departments')->insertGetId(['branch_id' => $branchId, 'division_id' => $divisionId, 'name' => 'Support', 'created_at' => $now, 'updated_at' => $now]);
        $sectionAId = DB::table('sections_or_units')->insertGetId(['company_id' => $companyId, 'branch_id' => $branchId, 'division_id' => $divisionId, 'department_id' => $departmentId, 'name' => 'Section A', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $sectionBId = DB::table('sections_or_units')->insertGetId(['company_id' => $companyId, 'branch_id' => $branchId, 'division_id' => $divisionId, 'department_id' => $departmentId, 'name' => 'Section B', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        return [$companyId, $branchId, $divisionId, $departmentId, $sectionAId, $sectionBId];
    }
}
