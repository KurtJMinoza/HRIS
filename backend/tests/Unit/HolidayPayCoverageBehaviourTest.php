<?php

namespace Tests\Unit;

use App\Services\HolidayPayPolicyService;
use Tests\TestCase;

class HolidayPayCoverageBehaviourTest extends TestCase
{
    public function test_should_ignore_holiday_coverage_when_policy_says_so(): void
    {
        $service = app(HolidayPayPolicyService::class);
        $policy = [
            'regular_unworked' => ['coverage_behaviour' => 'ignore_coverage'],
            'regular_worked' => ['coverage_behaviour' => 'respect_coverage'],
            'special_unworked' => ['coverage_behaviour' => 'respect_coverage'],
            'special_worked' => ['coverage_behaviour' => 'ignore_coverage'],
        ];

        $this->assertTrue($service->shouldIgnoreHolidayCoverage($policy, 'regular', false));
        $this->assertFalse($service->shouldIgnoreHolidayCoverage($policy, 'regular', true));
        $this->assertFalse($service->shouldIgnoreHolidayCoverage($policy, 'special', false));
        $this->assertTrue($service->shouldIgnoreHolidayCoverage($policy, 'special', true));
    }

    public function test_worked_holiday_component_codes(): void
    {
        $service = app(HolidayPayPolicyService::class);

        $this->assertSame('REGULAR_HOLIDAY_WORKED_PAY', $service->holidayPayComponentCode('regular', false));
        $this->assertSame('SPECIAL_HOLIDAY_WORKED_PAY', $service->holidayPayComponentCode('special', false));
        $this->assertStringContainsString('Worked Pay', $service->holidayPayDescription('REGULAR_HOLIDAY_WORKED_PAY', 'Test Day'));
    }

    public function test_normalize_defaults_worked_employment_type_rule(): void
    {
        $service = app(HolidayPayPolicyService::class);
        $normalized = $service->resolveEffectivePolicy(null, ['type' => 'regular'], 3);

        $this->assertSame('all_employment_types', $normalized['regular_worked']['employment_type_rule']);
        $this->assertSame('all_employment_types', $normalized['special_worked']['employment_type_rule']);
    }

    public function test_normalize_defaults_coverage_behaviour_to_respect(): void
    {
        $service = app(HolidayPayPolicyService::class);
        $normalized = $service->resolveEffectivePolicy(null, ['type' => 'regular'], 3);

        $this->assertSame('respect_coverage', $normalized['regular_unworked']['coverage_behaviour']);
        $this->assertSame('respect_coverage', $normalized['regular_worked']['coverage_behaviour']);
        $this->assertSame('respect_coverage', $normalized['special_unworked']['coverage_behaviour']);
        $this->assertSame('respect_coverage', $normalized['special_worked']['coverage_behaviour']);
    }
}
