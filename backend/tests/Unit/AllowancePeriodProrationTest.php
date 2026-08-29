<?php

namespace Tests\Unit;

use App\Services\DeductionScheduleService;
use App\Services\PayrollComputationService;
use ReflectionMethod;
use Tests\TestCase;

class AllowancePeriodProrationTest extends TestCase
{
    public function test_attendance_prorated_allowance_uses_cutoff_scheduled_days_and_payable_days(): void
    {
        $service = app(DeductionScheduleService::class);
        $method = new ReflectionMethod(DeductionScheduleService::class, 'computeAttendanceProratedAllowanceAmount');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke(
            $service,
            [
                'configured_value' => 5000.0,
                'computed_amount' => 5000.0,
                'is_proratable' => true,
                'schedule_override' => 'split',
                'resolved_schedule' => 'both',
                'calculation_standard' => 'monthly_standard',
            ],
            [
                'scheduled_workdays' => 13.0,
                'allowance' => [
                    'payable_day_units' => 8.0,
                    'worked_day_units' => 8.0,
                    'present_day_units' => 5.0,
                    'monthly_divisor_days' => 26.0,
                    'divisor_source' => 'stable_schedule_monthly',
                ],
            ],
            'both',
            0.5,
            'second'
        );

        $this->assertSame(1538.46, (float) ($result['amount'] ?? 0));
        $this->assertSame(8.0, (float) ($result['payable_day_units'] ?? 0));
        $this->assertSame(2500.0, (float) ($result['scheduled_base_for_run_before_absence_deduction'] ?? 0));
        $this->assertSame(13.0, (float) ($result['period_scheduled_workdays'] ?? 0));
    }

    public function test_allowance_proration_formula_uses_payable_and_unpaid_absent_day_units(): void
    {
        $service = app(DeductionScheduleService::class);
        $method = new ReflectionMethod(DeductionScheduleService::class, 'computeAttendanceProratedAllowanceAmount');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke(
            $service,
            [
                'configured_value' => 5000.0,
                'computed_amount' => 5000.0,
                'is_proratable' => true,
                'schedule_override' => 'split',
                'resolved_schedule' => 'both',
                'calculation_standard' => 'monthly_standard',
            ],
            [
                'scheduled_workdays' => 13.0,
                'allowance' => [
                    'payable_day_units' => 8.0,
                    'worked_day_units' => 8.0,
                    'present_day_units' => 8.0,
                    'unpaid_absent_days' => 5.0,
                    'monthly_divisor_days' => 26.0,
                    'divisor_source' => 'stable_schedule_monthly',
                ],
            ],
            'both',
            0.5,
            'second'
        );

        $this->assertSame(1538.46, (float) ($result['amount'] ?? 0));
        $this->assertSame(8.0, (float) ($result['present_day_units'] ?? 0));
        $this->assertSame(13.0, (float) ($result['period_scheduled_workdays'] ?? 0));
    }

    public function test_unworked_holiday_without_attendance_counts_as_allowance_payable_day(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new ReflectionMethod(PayrollComputationService::class, 'computeScheduleAttendanceProrationForPeriod');
        $method->setAccessible(true);

        $presentDay = static fn (string $date): array => [
            'date' => $date,
            'status' => 'worked',
            'is_rest_day' => false,
            'required_minutes' => 480,
            'allowance_proration_day' => [
                'scheduled_deductible_day' => true,
                'payable_day' => true,
                'unpaid_absent_day' => false,
                'payable_day_unit' => 1.0,
                'reason' => 'payable_day',
                'sources' => [],
            ],
        ];

        $days = [
            $presentDay('2026-08-04'),
            $presentDay('2026-08-05'),
            $presentDay('2026-08-06'),
            $presentDay('2026-08-07'),
            [
                'date' => '2026-08-08',
                'status' => 'holiday',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'holiday' => ['name' => 'Test Holiday', 'type' => 'special'],
                'holiday_premium_pay' => 500.0,
                'holiday_pay_evaluation' => [
                    'worked' => false,
                    'should_create_unworked_holiday_pay' => true,
                    'amount' => 500.0,
                ],
                'allowance_proration_day' => [
                    'scheduled_deductible_day' => true,
                    'payable_day' => false,
                    'unpaid_absent_day' => true,
                    'reason' => 'absent_without_leave',
                    'sources' => [],
                ],
            ],
        ];

        /** @var array<string, mixed> $result */
        $result = $method->invoke($service, $days, 26, 8.0);

        $this->assertSame(5.0, (float) data_get($result, 'allowance.payable_day_units'));
        $this->assertSame(0.0, (float) data_get($result, 'allowance.unpaid_absent_days'));
        $this->assertSame(4.0, (float) data_get($result, 'allowance.present_day_units'));
        $this->assertSame(1.0, (float) data_get($result, 'allowance.unworked_holiday_day_units'));
    }

    public function test_ineligible_holiday_without_attendance_stays_unpaid_for_allowance(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new ReflectionMethod(PayrollComputationService::class, 'computeScheduleAttendanceProrationForPeriod');
        $method->setAccessible(true);

        $days = [
            [
                'date' => '2026-08-04',
                'status' => 'worked',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'allowance_proration_day' => [
                    'scheduled_deductible_day' => true,
                    'payable_day' => true,
                    'unpaid_absent_day' => false,
                    'payable_day_unit' => 1.0,
                    'reason' => 'payable_day',
                    'sources' => [],
                ],
            ],
            [
                'date' => '2026-08-08',
                'status' => 'holiday',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'holiday' => ['name' => 'Test Holiday', 'type' => 'regular'],
                'holiday_premium_pay' => 0.0,
                'holiday_pay_evaluation' => [
                    'worked' => false,
                    'should_create_unworked_holiday_pay' => false,
                    'amount' => 0.0,
                ],
                'allowance_proration_day' => [
                    'scheduled_deductible_day' => true,
                    'payable_day' => false,
                    'unpaid_absent_day' => true,
                    'reason' => 'absent_without_leave',
                    'sources' => [],
                ],
            ],
        ];

        /** @var array<string, mixed> $result */
        $result = $method->invoke($service, $days, 26, 8.0);

        $this->assertSame(1.0, (float) data_get($result, 'allowance.payable_day_units'));
        $this->assertSame(1.0, (float) data_get($result, 'allowance.unpaid_absent_days'));
        $this->assertSame(0.0, (float) data_get($result, 'allowance.unworked_holiday_day_units'));
    }

    public function test_fixed_every_allowance_payslip_line_has_no_day_units(): void
    {
        $service = app(DeductionScheduleService::class);

        $lines = $service->buildPayslipEarningDisplayLines([[
            'name' => 'ALLOWANCE EVERY 15 AND 30',
            'code' => 'ALLOWANCE_EVERY_15_AND_30',
            'category' => 'Fixed Allowance',
            'computed_amount' => 5000.0,
            'scheduled_this_period' => 2500.0,
            'is_proratable' => true,
            'allowance_proration' => [
                'allowance_type' => 'attendance_prorated',
                'payable_day_units' => 15.0,
            ],
        ]]);

        $this->assertSame(2500.0, (float) ($lines[0]['amount'] ?? 0));
        $this->assertArrayNotHasKey('units', $lines[0]);
    }

    public function test_attendance_prorate_allowance_payslip_line_shows_payable_day_units(): void
    {
        $service = app(DeductionScheduleService::class);

        $lines = $service->buildPayslipEarningDisplayLines([[
            'name' => 'ALLOWANCE PRORATE 15-30',
            'code' => 'ALLOWANCE_PRORATE_15_30',
            'category' => 'Fixed Allowance',
            'computed_amount' => 5000.0,
            'scheduled_this_period' => 1538.46,
            'is_proratable' => true,
            'allowance_proration' => [
                'allowance_type' => 'attendance_prorated',
                'payable_day_units' => 9.0,
            ],
        ]]);

        $this->assertSame('9 days', $lines[0]['units'] ?? null);
    }
}
