<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\User;
use App\Services\HolidayPayEvaluationService;
use App\Services\HolidayPayPolicyService;
use App\Services\HolidayService;
use App\Services\PayrollComputationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayPayWorkedOutsideCoverageTest extends TestCase
{
    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM policies LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function schedule(): array
    {
        return [
            'sun' => null,
            'mon' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
        ];
    }

    /** @return array{0: Company, 1: Company, 2: User, 3: Policy} */
    private function seedScenario(array $holidayPolicyOverride): array
    {
        $suffix = substr(uniqid(), -6);
        $aci = Company::create(['name' => 'ACI-'.$suffix]);
        $mchisi = Company::create(['name' => 'MCHISI-'.$suffix]);

        $employee = User::factory()->create([
            'company_id' => $aci->id,
            'daily_rate' => 800,
            'schedule' => $this->schedule(),
            'employment_type' => 'regular',
        ]);

        $policy = Policy::create([
            'name' => 'ACI Holiday Policy',
            'company_id' => $aci->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
            'holiday_policy' => array_replace_recursive(Policy::DEFAULT_HOLIDAY_POLICY, $holidayPolicyOverride),
        ]);

        foreach (['ORD' => ['first8' => 1.0, 'ot' => 1.25], 'RH' => ['first8' => 2.0, 'ot' => 2.60], 'SH' => ['first8' => 1.30, 'ot' => 1.69]] as $code => $mult) {
            PolicyMultiplier::create([
                'policy_id' => $policy->id,
                'condition_key' => $code,
                'first8_multiplier' => $mult['first8'],
                'ot_multiplier' => $mult['ot'],
                'nd_addon_multiplier' => 0.10,
            ]);
        }

        Holiday::query()->create([
            'name' => 'Independence Day',
            'date' => '2026-06-13',
            'type' => 'regular',
            'scope' => 'company',
            'company_id' => $mchisi->id,
            'status' => 'active',
        ]);

        return [$aci, $mchisi, $employee, $policy];
    }

    private function clockFullDay(User $employee, string $dateKey): void
    {
        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_IN,
            'verified_at' => Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila')->utc(),
            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
        ]);
        AttendanceLog::create([
            'user_id' => $employee->id,
            'type' => AttendanceLog::TYPE_CLOCK_OUT,
            'verified_at' => Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila')->utc(),
            'authentication_method' => AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION,
        ]);
    }

    public function test_worked_regular_holiday_outside_coverage_paid_when_policy_ignores_coverage(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedScenario([
            'regular_worked' => ['coverage_behaviour' => 'ignore_coverage'],
        ]);
        $dateKey = '2026-06-13';
        $this->clockFullDay($employee, $dateKey);

        $holidayService = app(HolidayService::class);
        $this->assertNull($holidayService->resolveHolidayForPayroll($employee, $dateKey));
        $earnings = $holidayService->resolveHolidayForPayrollEarnings($employee, $dateKey);
        $this->assertNotNull($earnings['holiday']);
        $this->assertFalse($earnings['calendar_scope_match']);

        $timeIn = Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila');
        $timeOut = Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila');
        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            $timeIn,
            $timeOut,
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertSame('RH', $result['conditions']['rule_code'] ?? null);
        $this->assertSame(2.0, (float) ($result['conditions']['first_8'] ?? 0));
        $this->assertGreaterThan(600.0, (float) ($result['regular_pay'] ?? 0));
        $this->assertGreaterThan(600.0, (float) ($result['holiday_premium_pay'] ?? 0));
        $this->assertGreaterThan(1200.0, (float) ($result['total_pay'] ?? 0));

        $holidayLine = collect($result['breakdown'] ?? [])
            ->first(fn (array $row) => ($row['component'] ?? '') === 'holiday_premium');
        $this->assertSame('REGULAR_HOLIDAY_WORKED_PAY', $holidayLine['component_code'] ?? null);
    }

    public function test_worked_regular_holiday_outside_coverage_not_paid_when_unworked_ignores_but_holiday_not_selected(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedScenario([
            'regular_unworked' => [
                'coverage_behaviour' => 'ignore_coverage',
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [],
            ],
            'regular_worked' => ['coverage_behaviour' => 'respect_coverage'],
        ]);
        $dateKey = '2026-06-13';
        $this->clockFullDay($employee, $dateKey);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        // Unworked Ignore without this holiday selected must not open worked premium outside scope.
        $this->assertSame('ORD', $result['conditions']['rule_code'] ?? null);
        $this->assertSame(0.0, (float) ($result['holiday_premium_pay'] ?? 0));
    }

    public function test_worked_regular_holiday_outside_coverage_paid_when_unworked_ignores_and_holiday_selected(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee, $policy] = $this->seedScenario([
            'regular_unworked' => [
                'coverage_behaviour' => 'ignore_coverage',
                'holiday_selection_mode' => 'selected_regular_holidays',
                'holiday_ids' => [], // filled below after holiday create
            ],
            'regular_worked' => ['coverage_behaviour' => 'respect_coverage'],
        ]);

        $holidayId = (int) Holiday::query()->whereDate('date', '2026-06-13')->value('id');
        $this->assertGreaterThan(0, $holidayId);
        $holidayPolicy = $policy->holiday_policy;
        $holidayPolicy['regular_unworked']['holiday_ids'] = [$holidayId];
        $policy->holiday_policy = $holidayPolicy;
        $policy->save();

        $dateKey = '2026-06-13';
        $this->clockFullDay($employee, $dateKey);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertSame('RH', $result['conditions']['rule_code'] ?? null);
        $this->assertGreaterThan(600.0, (float) ($result['holiday_premium_pay'] ?? 0));
    }

    public function test_worked_regular_holiday_outside_coverage_not_paid_when_policy_respects_coverage(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedScenario([
            'regular_worked' => ['coverage_behaviour' => 'respect_coverage'],
        ]);
        $dateKey = '2026-06-13';
        $this->clockFullDay($employee, $dateKey);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertSame('ORD', $result['conditions']['rule_code'] ?? null);
        $this->assertSame(0.0, (float) ($result['holiday_premium_pay'] ?? 0));
    }

    public function test_unworked_regular_holiday_outside_coverage_paid_when_policy_ignores_coverage(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedScenario([
            'regular_unworked' => [
                'coverage_behaviour' => 'ignore_coverage',
                'unworked_pay_policy' => 'dole_default',
            ],
        ]);
        $this->clockFullDay($employee, '2026-06-12');

        $evaluation = app(HolidayPayEvaluationService::class)->evaluateHoliday(
            $employee,
            null,
            Carbon::parse('2026-06-13', 'Asia/Manila'),
            null,
            ['daily_rate' => 800, 'hourly_rate' => 100, 'required_minutes' => 480]
        );

        $this->assertFalse($evaluation['holiday_scope_match']);
        $this->assertTrue($evaluation['coverage_override_applied']);
        $this->assertTrue($evaluation['should_create_unworked_holiday_pay']);
        $this->assertSame('REGULAR_HOLIDAY_UNWORKED_PAY', $evaluation['component_code']);
        $this->assertSame(800.0, (float) ($evaluation['amount'] ?? 0));
    }

    public function test_worked_special_holiday_outside_coverage_uses_worked_component_code(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, $mchisi, $employee] = $this->seedScenario([
            'special_worked' => ['coverage_behaviour' => 'ignore_coverage'],
        ]);

        Holiday::query()->whereDate('date', '2026-08-21')->delete();
        Holiday::query()->create([
            'name' => 'Special Day',
            'date' => '2026-08-21',
            'type' => 'special',
            'scope' => 'company',
            'company_id' => $mchisi->id,
            'status' => 'active',
        ]);

        $dateKey = '2026-08-21';
        $this->clockFullDay($employee, $dateKey);

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertSame('SH', $result['conditions']['rule_code'] ?? null);
        // SH worked: full 1.30× sits on the holiday line; regular_pay stays 0 for that day.
        $this->assertSame(0.0, (float) ($result['regular_pay'] ?? 0));
        $this->assertGreaterThan(900.0, (float) ($result['holiday_premium_pay'] ?? 0));
        $this->assertGreaterThan(900.0, (float) ($result['total_pay'] ?? 0));

        $service = app(HolidayPayPolicyService::class);
        $this->assertSame('SPECIAL_HOLIDAY_WORKED_PAY', $service->holidayPayComponentCode('special', false));
    }
}
