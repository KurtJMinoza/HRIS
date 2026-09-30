<?php

namespace Tests\Unit;

use App\Services\PayrollComputationService;
use ReflectionMethod;
use Tests\TestCase;

class ConsultantDailyRateTest extends TestCase
{
    public function test_consultant_working_days_use_schedule_divisor_not_config_fallback(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new ReflectionMethod(PayrollComputationService::class, 'resolveConsultantWorkingDays');
        $method->setAccessible(true);

        $sixDaySchedule = [
            'mon' => ['in' => '08:00', 'out' => '17:00'],
            'tue' => ['in' => '08:00', 'out' => '17:00'],
            'wed' => ['in' => '08:00', 'out' => '17:00'],
            'thu' => ['in' => '08:00', 'out' => '17:00'],
            'fri' => ['in' => '08:00', 'out' => '17:00'],
            'sat' => ['in' => '08:00', 'out' => '17:00'],
        ];

        $days = $method->invoke($service, [], $sixDaySchedule);

        $this->assertSame(26, $days);
    }

    public function test_consultant_working_days_honor_period_context_override(): void
    {
        $service = app(PayrollComputationService::class);
        $method = new ReflectionMethod(PayrollComputationService::class, 'resolveConsultantWorkingDays');
        $method->setAccessible(true);

        $days = $method->invoke($service, ['daily_rate_divisor_days' => 30], null);

        $this->assertSame(30, $days);
    }
}
