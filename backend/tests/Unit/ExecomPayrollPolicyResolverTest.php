<?php

namespace Tests\Unit;

use App\Models\ExecomPayrollSetting;
use App\Services\ExecomPayrollPolicyResolver;
use PHPUnit\Framework\TestCase;

class ExecomPayrollPolicyResolverTest extends TestCase
{
    public function test_defaults_exclude_government_deduction_gate(): void
    {
        $defaults = ExecomPayrollSetting::defaults(7);

        $this->assertArrayNotHasKey('apply_government_deductions', $defaults);
        $this->assertTrue($defaults['allow_paid_leave']);
        $this->assertFalse($defaults['allow_overtime']);
        $this->assertFalse($defaults['allow_holiday_pay']);
        $this->assertTrue($defaults['apply_custom_deductions']);
        $this->assertArrayNotHasKey('auto_present_attendance_reports', $defaults);

        $setting = new ExecomPayrollSetting($defaults);
        $policy = $setting->toPolicyArray();

        $this->assertArrayNotHasKey('apply_government_deductions', $policy);
        $this->assertArrayNotHasKey('auto_present_attendance_reports', $policy);
        foreach ([
            'apply_custom_deductions',
            'apply_allowances',
            'allow_paid_leave',
            'allow_overtime',
            'allow_holiday_pay',
        ] as $key) {
            $this->assertIsBool($policy[$key], $key.' must be boolean');
        }

        $this->assertSame('execom:payroll-policy:', ExecomPayrollPolicyResolver::CACHE_PREFIX);
    }

    public function test_defaults_still_enable_custom_deductions_when_no_saved_row(): void
    {
        $defaults = ExecomPayrollSetting::defaults(3);
        $this->assertTrue($defaults['apply_custom_deductions']);

        $globalDisabled = (new ExecomPayrollSetting([
            ...ExecomPayrollSetting::defaults(null),
            'apply_custom_deductions' => false,
        ]))->toPolicyArray();

        $this->assertFalse($globalDisabled['apply_custom_deductions']);
    }
}
