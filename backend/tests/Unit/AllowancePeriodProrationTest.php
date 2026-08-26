<?php

namespace Tests\Unit;

use App\Services\DeductionScheduleService;
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

    public function test_scheduled_holiday_without_attendance_is_not_payable(): void
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
