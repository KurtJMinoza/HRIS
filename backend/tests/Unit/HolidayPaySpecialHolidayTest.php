<?php

namespace Tests\Unit;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\User;
use App\Services\HolidayPayEvaluationService;
use App\Services\PayrollComputationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayPaySpecialHolidayTest extends TestCase
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

    /**
     * @return array{0: Company, 1: Company, 2: User, 3: Policy, 4: Holiday}
     */
    private function seedSpecialHolidayScenario(
        array $holidayPolicyOverride,
        string $employeeCompany = 'mchisi',
        string $employmentStatus = 'regular',
    ): array {
        $suffix = substr(uniqid(), -6);
        $aci = Company::create(['name' => 'ACI-'.$suffix]);
        $mchisi = Company::create(['name' => 'MCHISI-'.$suffix]);

        $employee = User::factory()->create([
            'company_id' => $employeeCompany === 'aci' ? $aci->id : $mchisi->id,
            'daily_rate' => 800,
            'schedule' => $this->schedule(),
            'employment_type' => 'full_time',
            'employment_status' => $employmentStatus,
        ]);

        $policy = Policy::create([
            'name' => 'Holiday Policy '.$suffix,
            'company_id' => $employee->company_id,
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

        $holiday = Holiday::query()->create([
            'name' => 'TEST DAY',
            'date' => '2026-06-24',
            'type' => 'special_non_working',
            'scope' => 'company',
            'company_id' => $mchisi->id,
            'status' => 'active',
        ]);

        return [$aci, $mchisi, $employee, $policy, $holiday];
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

    private function specialPolicy(): array
    {
        return [
            'special_unworked' => [
                'unworked_pay_policy' => 'selected_employment_types',
                'eligible_employment_types' => ['full_time', 'probationary', 'regular'],
                'coverage_behaviour' => 'ignore_coverage',
            ],
            'special_worked' => [
                'coverage_behaviour' => 'ignore_coverage',
                'employment_type_rule' => 'all_employment_types',
            ],
        ];
    }

    public function test_mchisi_employee_worked_special_holiday_gets_130x_premium(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedSpecialHolidayScenario($this->specialPolicy());
        $dateKey = '2026-06-24';
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
        $this->assertSame(1.3, (float) ($result['conditions']['first_8'] ?? 0));
        // SH worked: full 1.30× sits on the holiday line; regular_pay stays 0 for that day.
        $this->assertSame(0.0, (float) ($result['regular_pay'] ?? 0));
        $this->assertGreaterThan(900.0, (float) ($result['holiday_premium_pay'] ?? 0));
        $this->assertGreaterThan(900.0, (float) ($result['total_pay'] ?? 0));

        $holidayLine = collect($result['breakdown'] ?? [])
            ->first(fn (array $row) => ($row['component'] ?? '') === 'holiday_premium');
        $this->assertSame('SPECIAL_HOLIDAY_WORKED_PAY', $holidayLine['component_code'] ?? null);
        $this->assertStringContainsString('TEST DAY', (string) ($holidayLine['description'] ?? ''));
        $this->assertSame(1.3, (float) ($holidayLine['multiplier'] ?? 0));
    }

    public function test_mchisi_employee_unworked_special_holiday_gets_unworked_pay(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee, , $holiday] = $this->seedSpecialHolidayScenario($this->specialPolicy());
        $dateKey = '2026-06-24';
        $this->clockFullDay($employee, '2026-06-23');

        $evaluation = app(HolidayPayEvaluationService::class)->evaluateHoliday(
            $employee,
            $holiday->toArray(),
            Carbon::parse($dateKey, 'Asia/Manila'),
            null,
            ['daily_rate' => 800, 'hourly_rate' => 100, 'required_minutes' => 480]
        );

        $this->assertTrue($evaluation['scope_match']);
        $this->assertTrue($evaluation['eligible_for_holiday_evaluation']);
        $this->assertFalse($evaluation['worked']);
        $this->assertTrue($evaluation['should_create_unworked_holiday_pay']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $evaluation['component_code']);
        $this->assertSame(800.0, (float) ($evaluation['amount'] ?? 0));
        $this->assertSame(1.0, (float) ($evaluation['multiplier_loaded'] ?? 0));

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            null,
            null,
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertSame(800.0, (float) ($result['holiday_premium_pay'] ?? 0));
        $holidayLine = collect($result['breakdown'] ?? [])
            ->first(fn (array $row) => ($row['component'] ?? '') === 'holiday_premium');
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $holidayLine['component_code'] ?? null);
    }

    public function test_aci_employee_worked_special_holiday_paid_when_ignore_coverage(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedSpecialHolidayScenario($this->specialPolicy(), 'aci');
        $dateKey = '2026-06-24';
        $this->clockFullDay($employee, $dateKey);

        $evaluation = app(HolidayPayEvaluationService::class)->evaluateHoliday(
            $employee,
            null,
            Carbon::parse($dateKey, 'Asia/Manila'),
            null,
            ['daily_rate' => 800, 'hourly_rate' => 100, 'required_minutes' => 480, 'paid_regular_minutes' => 480]
        );

        $this->assertFalse($evaluation['scope_match']);
        $this->assertTrue($evaluation['eligible_for_holiday_evaluation']);
        $this->assertTrue($evaluation['should_create_worked_holiday_pay']);
        $this->assertSame('SPECIAL_HOLIDAY_WORKED_PAY', $evaluation['component_code']);
        $this->assertSame(1.3, (float) ($evaluation['multiplier_loaded'] ?? 0));

        $result = app(PayrollComputationService::class)->computeDayPayroll(
            $employee,
            $dateKey,
            Carbon::parse("{$dateKey} 08:00:00", 'Asia/Manila'),
            Carbon::parse("{$dateKey} 17:00:00", 'Asia/Manila'),
            $this->schedule(),
            800,
            'Asia/Manila'
        );

        $this->assertGreaterThan(200.0, (float) ($result['holiday_premium_pay'] ?? 0));
    }

    public function test_aci_employee_unworked_special_holiday_paid_when_ignore_coverage(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        [, , $employee] = $this->seedSpecialHolidayScenario($this->specialPolicy(), 'aci');
        $dateKey = '2026-06-24';
        $this->clockFullDay($employee, '2026-06-23');

        $evaluation = app(HolidayPayEvaluationService::class)->evaluateHoliday(
            $employee,
            null,
            Carbon::parse($dateKey, 'Asia/Manila'),
            null,
            ['daily_rate' => 800, 'hourly_rate' => 100, 'required_minutes' => 480]
        );

        $this->assertFalse($evaluation['scope_match']);
        $this->assertSame('ignore_coverage', $evaluation['coverage_behaviour']);
        $this->assertTrue($evaluation['eligible_for_holiday_evaluation']);
        $this->assertTrue($evaluation['should_create_unworked_holiday_pay']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $evaluation['component_code']);
        $this->assertSame(800.0, (float) ($evaluation['amount'] ?? 0));
    }
}
