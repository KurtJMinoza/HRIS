<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayMovedDatePayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_moved_nationwide_holiday_does_not_pay_on_original_seeded_date(): void
    {
        $employee = $this->seedEmployee();

        // Simulates admin swap() before stub/original_date backfill: only the new date exists in DB.
        Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-13',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);

        $service = app(HolidayService::class);

        $this->assertNull($service->resolveHolidayForPayroll($employee, '2026-06-12'));
        $this->assertSame('Independence Day', $service->resolveHolidayForPayroll($employee, '2026-06-13')['name'] ?? null);
    }

    public function test_moved_holiday_with_original_date_suppresses_pay_on_original_date(): void
    {
        $employee = $this->seedEmployee();

        Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-13',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
            'is_swap' => true,
            'original_date' => '2026-06-12',
        ]);

        $service = app(HolidayService::class);

        $this->assertNull($service->resolveHolidayForPayroll($employee, '2026-06-12'));
        $this->assertSame('Independence Day', $service->resolveHolidayForPayroll($employee, '2026-06-13')['name'] ?? null);
    }

    public function test_duplicate_active_rows_from_bad_swap_suppresses_original_date(): void
    {
        $employee = $this->seedEmployee();

        // Bad swapSeeded left Independence Day active on both dates.
        Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-12',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);
        Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-13',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
            'is_swap' => true,
        ]);

        $service = app(HolidayService::class);

        $this->assertNull($service->resolveHolidayForPayroll($employee, '2026-06-12'));
        $this->assertSame('Independence Day', $service->resolveHolidayForPayroll($employee, '2026-06-13')['name'] ?? null);
    }

    private function seedEmployee(): User
    {
        $now = now();
        $companyId = DB::table('companies')->insertGetId(['name' => 'ACME', 'created_at' => $now, 'updated_at' => $now]);
        $branchId = DB::table('branches')->insertGetId(['company_id' => $companyId, 'name' => 'Main', 'created_at' => $now, 'updated_at' => $now]);

        return User::factory()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'is_active' => true,
        ]);
    }
}
