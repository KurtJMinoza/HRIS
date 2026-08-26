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
}
