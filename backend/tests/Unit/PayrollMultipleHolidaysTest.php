<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use App\Services\HolidayPayPolicyService;
use App\Services\HolidayService;
use App\Services\PayrollComputationService;
use App\Services\PayslipService;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

class PayrollMultipleHolidaysTest extends TestCase
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

    public function test_holidays_for_payroll_period_returns_every_holiday_date_sorted(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }

        $company = Company::create(['name' => 'Payroll Holidays '.substr(uniqid(), -6)]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $holidayIds = [];

        foreach ([
            ['2030-03-12', 'Independence Day', 'regular'],
            ['2030-03-19', 'Ninoy Aquino Day', 'special'],
            ['2030-03-24', 'Company Anniversary', 'special_working'],
        ] as [$date, $name, $type]) {
            $holidayIds[] = Holiday::query()->create([
                'name' => $name.' '.substr(uniqid(), -4),
                'date' => $date,
                'type' => $type,
                'scope' => 'company',
                'company_id' => $company->id,
                'status' => 'active',
            ])->id;
        }

        $rows = array_values(array_filter(
            app(HolidayService::class)->holidaysForPayrollPeriod($user, '2030-03-01', '2030-03-31'),
            fn (array $row): bool => in_array($row['id'] ?? null, $holidayIds, true)
        ));

        $this->assertCount(3, $rows);
        $this->assertSame('2030-03-12', $rows[0]['date']);
        $this->assertSame('2030-03-19', $rows[1]['date']);
        $this->assertSame('2030-03-24', $rows[2]['date']);
    }

    public function test_build_holiday_premium_breakdown_emits_one_row_per_holiday_evaluation(): void
    {
        $service = app(PayrollComputationService::class);
        $method = (new ReflectionClass(PayrollComputationService::class))
            ->getMethod('buildHolidayPremiumBreakdown');
        $method->setAccessible(true);

        $days = [
            [
                'date' => '2026-06-12',
                'status' => 'worked',
                'required_minutes' => 480,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'holiday_premium_pay' => 1000.0,
                'holiday' => ['id' => 12, 'name' => 'Independence Day', 'type' => 'regular'],
                'conditions' => ['first_8' => 2.0, 'holiday_eligible' => true, 'rule_code' => 'RH'],
                'breakdown' => [['component' => 'holiday_premium', 'amount' => 1000.0]],
                'holiday_pay_evaluation' => [
                    'date' => '2026-06-12',
                    'holiday_id' => 12,
                    'holiday_name' => 'Independence Day',
                    'holiday_type' => 'regular',
                    'worked' => true,
                    'should_create_holiday_pay' => true,
                    'amount' => 1000.0,
                    'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
                ],
            ],
            [
                'date' => '2026-06-19',
                'status' => 'holiday',
                'required_minutes' => 480,
                'regular_day_minutes' => 0,
                'regular_night_minutes' => 0,
                'holiday_premium_pay' => 625.0,
                'holiday' => ['id' => 19, 'name' => 'Ninoy Aquino Day', 'type' => 'special'],
                'conditions' => ['holiday_eligible' => true, 'holiday_unworked_multiplier' => 1.0, 'rule_code' => 'SH'],
                'breakdown' => [['component' => 'holiday_premium', 'amount' => 625.0]],
                'holiday_pay_evaluation' => [
                    'date' => '2026-06-19',
                    'holiday_id' => 19,
                    'holiday_name' => 'Ninoy Aquino Day',
                    'holiday_type' => 'special',
                    'worked' => false,
                    'should_create_holiday_pay' => true,
                    'amount' => 625.0,
                    'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                ],
            ],
        ];

        $breakdown = $method->invoke($service, $days, 625.0);

        $this->assertCount(2, $breakdown);
        $this->assertSame('REGULAR_HOLIDAY_WORKED_PAY', $breakdown[0]['component_code']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $breakdown[1]['component_code']);
        $this->assertSame(1000.0, $breakdown[0]['amount']);
        $this->assertSame(625.0, $breakdown[1]['amount']);
    }

    public function test_build_per_holiday_earning_lines_use_distinct_keys_per_holiday(): void
    {
        $service = app(PayrollComputationService::class);
        $method = (new ReflectionClass(PayrollComputationService::class))
            ->getMethod('buildPerHolidayEarningLines');
        $method->setAccessible(true);

        $breakdown = [
            [
                'date' => '2026-06-12',
                'holiday_id' => 12,
                'holiday_name' => 'Independence Day',
                'holiday_type' => 'regular',
                'eligible' => true,
                'amount' => 1000.0,
                'worked' => true,
                'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
            ],
            [
                'date' => '2026-06-19',
                'holiday_id' => 19,
                'holiday_name' => 'Ninoy Aquino Day',
                'holiday_type' => 'special',
                'eligible' => true,
                'amount' => 625.0,
                'worked' => false,
                'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
            ],
        ];

        $lines = $method->invoke($service, $breakdown, 625.0);
        $keys = array_column($lines, 'key');

        $this->assertCount(2, $lines);
        $this->assertContains('holiday:2026-06-12:12:REGULAR_HOLIDAY_WORKED_PAY', $keys);
        $this->assertContains('holiday:2026-06-19:19:SPECIAL_HOLIDAY_UNWORKED_PAY', $keys);
    }

    public function test_payslip_deduplication_keeps_multiple_holiday_lines_with_same_component_code(): void
    {
        $service = app(PayslipService::class);
        $method = (new ReflectionClass(PayslipService::class))
            ->getMethod('deduplicatePayslipLines');
        $method->setAccessible(true);

        $lines = [
            [
                'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                'label' => 'Special Holiday — Unworked Pay: Ninoy Aquino Day',
                'amount' => 625.0,
                'metadata' => ['holiday_id' => 19, 'holiday_date' => '2026-06-19'],
            ],
            [
                'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                'label' => 'Special Holiday — Unworked Pay: Another Special',
                'amount' => 625.0,
                'metadata' => ['holiday_id' => 30, 'holiday_date' => '2026-06-30'],
            ],
        ];

        $deduped = $method->invoke($service, $lines);

        $this->assertCount(2, $deduped);
    }

    public function test_special_unworked_uses_special_unworked_policy_block(): void
    {
        $service = app(HolidayPayPolicyService::class);
        $policy = new \App\Models\Policy([
            'holiday_policy' => array_replace_recursive(\App\Models\Policy::DEFAULT_HOLIDAY_POLICY, [
                'special_unworked' => [
                    'unworked_pay_policy' => 'selected_employment_types',
                    'eligible_employment_types' => ['regular'],
                ],
            ]),
        ]);

        $employee = new User([
            'employment_status' => 'regular',
            'employment_type' => 'full_time',
            'schedule' => json_encode([
                'mon' => ['in' => '08:00', 'out' => '17:00'],
                'tue' => ['in' => '08:00', 'out' => '17:00'],
                'wed' => ['in' => '08:00', 'out' => '17:00'],
                'thu' => ['in' => '08:00', 'out' => '17:00'],
                'fri' => ['in' => '08:00', 'out' => '17:00'],
            ]),
        ]);

        $result = $service->computeHolidayPay($employee, [
            'date_key' => '2026-06-19',
            'worked' => false,
            'daily_rate' => 625,
        ], ['date' => '2026-06-19', 'type' => 'special', 'name' => 'Ninoy Aquino Day'], $policy);

        $this->assertTrue($result['eligible']);
        $this->assertSame(625.0, $result['holiday_premium_pay']);
        $this->assertSame('SPECIAL_HOLIDAY_UNWORKED_PAY', $result['breakdown']['component_code']);
    }

    public function test_worked_special_holiday_line_uses_premium_hourly_rate(): void
    {
        $service = app(PayrollComputationService::class);
        $method = (new ReflectionClass(PayrollComputationService::class))
            ->getMethod('buildPerHolidayEarningLines');
        $method->setAccessible(true);

        $lines = $method->invoke($service, [[
            'date' => '2026-06-24',
            'holiday_id' => 24,
            'holiday_name' => 'TEST SPECIAL',
            'holiday_type' => 'special',
            'eligible' => true,
            'amount' => 572.69,
            'hours' => 8,
            'worked' => true,
            'multiplier' => 2.0,
            'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
        ]], 572.69);

        $this->assertCount(1, $lines);
        $this->assertSame(572.69, $lines[0]['amount']);
        $this->assertEqualsWithDelta(71.58625, $lines[0]['hourly_rate'], 0.001);
    }

    public function test_worked_holiday_line_uses_actual_hours_not_full_day(): void
    {
        $service = app(PayrollComputationService::class);
        $method = (new ReflectionClass(PayrollComputationService::class))
            ->getMethod('buildPerHolidayEarningLines');
        $method->setAccessible(true);

        $lines = $method->invoke($service, [[
            'date' => '2026-06-12',
            'holiday_id' => 12,
            'holiday_name' => 'TEST HOLIDAY',
            'holiday_type' => 'regular',
            'eligible' => true,
            'amount' => 605.77,
            'hours' => 7,
            'worked' => true,
            'multiplier' => 2.0,
            'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
        ]], 692.31);

        $this->assertCount(1, $lines);
        $this->assertNull($lines[0]['units']);
        $this->assertSame(420, $lines[0]['minutes_worked']);

        $payslipService = app(PayslipService::class);
        $normalize = (new ReflectionClass(PayslipService::class))
            ->getMethod('normalizePayslipLineList');
        $normalize->setAccessible(true);

        $normalized = $normalize->invoke($payslipService, [[
            ...$lines[0],
            'units' => '1 day',
        ]], 'Earning', false, false, 86.53875);

        $this->assertSame('7 hrs', $normalized[0]['units']);
        $this->assertSame(605.77, $normalized[0]['amount']);
    }

    public function test_payslip_normalization_preserves_worked_holiday_premium_amount(): void
    {
        $service = app(PayslipService::class);
        $method = (new ReflectionClass(PayslipService::class))
            ->getMethod('normalizePayslipLineList');
        $method->setAccessible(true);

        $lines = $method->invoke($service, [[
            'key' => 'holiday:2026-06-12:12:REGULAR_HOLIDAY_WORKED_PAY',
            'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
            'label' => 'Regular Holiday — Worked Pay: Independence Day',
            'amount' => 572.69,
            'minutes_worked' => 480,
            'hourly_rate' => 71.58625,
        ]], 'Earning', false, false, 71.58625);

        $this->assertCount(1, $lines);
        $this->assertSame(572.69, $lines[0]['amount']);
    }
}
