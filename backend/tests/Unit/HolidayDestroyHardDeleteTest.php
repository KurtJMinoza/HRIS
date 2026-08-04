<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\HolidayController;
use App\Models\Holiday;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayDestroyHardDeleteTest extends TestCase
{
    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM holidays LIMIT 1');
            DB::select('SELECT 1 FROM payslips LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_destroy_hard_deletes_custom_holiday(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $suffix = substr(uniqid(), -6);
        $holiday = Holiday::query()->create([
            'name' => 'Custom Delete Me '.$suffix,
            'date' => '2026-03-15',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);

        $response = app(HolidayController::class)->destroy((int) $holiday->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue((bool) ($response->getData(true)['deleted'] ?? false));
        $this->assertNull(Holiday::query()->find($holiday->id));
    }

    public function test_destroy_blocked_when_payroll_finalized(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $suffix = substr(uniqid(), -6);
        $user = User::factory()->create();
        $holiday = Holiday::query()->create([
            'name' => 'Locked Holiday '.$suffix,
            'date' => '2026-03-20',
            'type' => 'regular',
            'scope' => 'company',
            'company_id' => $user->company_id,
            'status' => 'active',
        ]);

        $payslip = Payslip::query()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'branch_id' => $user->branch_id,
            'department_id' => $user->department_id,
            'pay_period_start' => '2026-03-16',
            'pay_period_end' => '2026-03-31',
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 0,
            'net_pay' => 0,
        ]);

        if (! $user->company_id) {
            $payslip->delete();
            Holiday::query()->whereKey($holiday->id)->delete();
            $user->delete();
            $this->markTestSkipped('User factory did not assign company_id');
        }

        try {
            $response = app(HolidayController::class)->destroy((int) $holiday->id);

            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString('finalized', strtolower((string) ($response->getData(true)['message'] ?? '')));
            $still = Holiday::query()->find($holiday->id);
            $this->assertNotNull($still);
            $this->assertSame('active', $still->status);
        } finally {
            $payslip->delete();
            Holiday::query()->whereKey($holiday->id)->delete();
            $user->delete();
        }
    }

    public function test_destroy_blocks_seeded_resurface_by_suppressing(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        Holiday::query()
            ->whereDate('date', '2026-06-12')
            ->where('name', 'Independence Day')
            ->delete();

        $holiday = Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-12',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);

        $response = app(HolidayController::class)->destroy((int) $holiday->id);

        $this->assertSame(200, $response->getStatusCode());
        $still = Holiday::query()->find($holiday->id);
        $this->assertNotNull($still);
        $this->assertSame('inactive', $still->status);

        $calendar = app(\App\Services\HolidayCalendarService::class);
        $calendar->flushMergedYearCaches();
        $this->assertFalse(
            $calendar->holidaysForYear(2026, true)->contains(fn (array $row) => ($row['name'] ?? '') === 'Independence Day')
        );
    }

    public function test_destroy_seeded_creates_hidden_suppression(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        Holiday::query()
            ->whereDate('date', '2026-06-12')
            ->where('name', 'Independence Day')
            ->delete();

        $response = app(HolidayController::class)->destroySeeded(
            \Illuminate\Http\Request::create('/admin/holidays/seeded/delete', 'POST', [
                'date' => '2026-06-12',
                'name' => 'Independence Day',
                'type' => 'regular',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());

        $stub = Holiday::query()
            ->whereDate('date', '2026-06-12')
            ->where('name', 'Independence Day')
            ->where('status', 'inactive')
            ->first();
        $this->assertNotNull($stub);

        $calendar = app(\App\Services\HolidayCalendarService::class);
        $calendar->flushMergedYearCaches();
        $this->assertFalse(
            $calendar->holidaysForYear(2026, true)->contains(fn (array $row) => ($row['name'] ?? '') === 'Independence Day')
        );

        Holiday::query()->whereKey($stub->id)->delete();
    }
}
