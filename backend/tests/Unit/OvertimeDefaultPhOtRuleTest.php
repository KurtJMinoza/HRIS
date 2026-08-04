<?php

namespace Tests\Unit;

use App\Http\Controllers\EmployeeOvertimeController;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class OvertimeDefaultPhOtRuleTest extends TestCase
{
    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM holidays LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scheduleMonFri(): array
    {
        return [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => null,
        ];
    }

    private function detect(User $user, string $dateYmd): string
    {
        $method = new ReflectionMethod(EmployeeOvertimeController::class, 'detectDefaultPhOtRule');
        $method->setAccessible(true);

        return (string) $method->invoke(app(EmployeeOvertimeController::class), $user, $dateYmd);
    }

    public function test_inactive_regular_holiday_defaults_to_ordinary_day(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $suffix = substr(uniqid(), -6);
        $company = Company::create(['name' => 'OT-Rule-'.$suffix]);
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'schedule' => $this->scheduleMonFri(),
            'employment_status' => 'regular',
        ]);

        // 2026-07-30 is Thursday — an ordinary workday unless an *active* holiday applies.
        Holiday::query()->create([
            'name' => 'Inactive Test Holiday '.$suffix,
            'date' => '2026-07-30',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'inactive',
            'company_id' => null,
        ]);

        $this->assertSame('ORD', $this->detect($employee, '2026-07-30'));
    }

    public function test_active_regular_holiday_defaults_to_rh(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $suffix = substr(uniqid(), -6);
        $company = Company::create(['name' => 'OT-Rule-RH-'.$suffix]);
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'schedule' => $this->scheduleMonFri(),
            'employment_status' => 'regular',
        ]);

        Holiday::query()->create([
            'name' => 'Active Regular '.$suffix,
            'date' => '2026-07-29',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
            'company_id' => null,
        ]);

        $this->assertSame('RH', $this->detect($employee, '2026-07-29'));
        $this->assertSame('ORD', $this->detect($employee, '2026-07-30'));
    }

    public function test_department_scoped_holiday_does_not_default_other_departments_to_rh(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $depts = DB::table('departments')->orderBy('id')->limit(2)->get(['id', 'company_id', 'branch_id']);
        if ($depts->count() < 2) {
            $this->markTestSkipped('Need at least two departments');
        }

        $deptA = $depts[0];
        $deptB = $depts[1];
        $suffix = substr(uniqid(), -6);

        $otherDept = User::factory()->create([
            'company_id' => $deptA->company_id,
            'branch_id' => $deptA->branch_id,
            'department_id' => $deptA->id,
            'schedule' => $this->scheduleMonFri(),
            'employment_status' => 'regular',
        ]);
        $inDept = User::factory()->create([
            'company_id' => $deptB->company_id,
            'branch_id' => $deptB->branch_id,
            'department_id' => $deptB->id,
            'schedule' => $this->scheduleMonFri(),
            'employment_status' => 'regular',
        ]);

        $holiday = Holiday::query()->create([
            'name' => 'Dept Only '.$suffix,
            'date' => '2026-07-28',
            'type' => 'regular',
            'scope' => 'department',
            'status' => 'active',
            'company_id' => $deptB->company_id,
            'branch_id' => $deptB->branch_id,
            'department_id' => $deptB->id,
        ]);

        try {
            $this->assertSame('ORD', $this->detect($otherDept, '2026-07-28'));
            $this->assertSame('RH', $this->detect($inDept, '2026-07-28'));
        } finally {
            Holiday::query()->whereKey($holiday->id)->delete();
            $otherDept->delete();
            $inDept->delete();
        }
    }
}
