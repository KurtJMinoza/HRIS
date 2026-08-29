<?php

namespace Tests\Unit;

use App\Models\EmploymentPayrollSetting;
use App\Services\EmploymentPayrollPolicyApplicator;
use App\Services\EmploymentPayrollPolicyResolver;
use PHPUnit\Framework\TestCase;

class EmploymentPayrollPolicyApplicatorTest extends TestCase
{
    public function test_defaults_match_execom_style_gates(): void
    {
        $defaults = EmploymentPayrollSetting::defaults();

        $this->assertTrue($defaults['apply_custom_deductions']);
        $this->assertTrue($defaults['apply_allowances']);
        $this->assertTrue($defaults['allow_paid_leave']);
        $this->assertFalse(EmploymentPayrollSetting::defaults('probationary')['allow_paid_leave']);
        $this->assertFalse(EmploymentPayrollSetting::defaults('project_based')['allow_paid_leave']);
        $this->assertTrue(EmploymentPayrollSetting::defaults('regular')['allow_paid_leave']);
        $this->assertFalse($defaults['allow_overtime']);
        $this->assertFalse($defaults['allow_holiday_pay']);
    }

    public function test_applicator_strips_custom_deductions_when_disabled(): void
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $applicator = new EmploymentPayrollPolicyApplicator($resolver);

        $summary = [
            'basic_pay_this_period' => 1000.0,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'component' => 'regular_pay', 'amount' => 1000.0],
            ],
            'payslip_earning_lines' => [],
            'payslip_custom_deduction_lines' => [
                ['label' => 'Loan', 'amount' => 200.0],
            ],
            'custom_deductions_this_period' => 200.0,
            'custom_deductions_full_monthly' => 400.0,
            'deduction_schedule' => [
                'custom_lines' => [['amount' => 200.0]],
                'custom_deductions_this_period' => 200.0,
            ],
            'employee_statutory_this_period' => 100.0,
            'withholding_tax_this_period_estimate' => 10.0,
        ];

        $result = $applicator->applyToSummary($summary, [
            'employment_type' => 'probationary',
            'apply_custom_deductions' => false,
            'apply_allowances' => true,
            'allow_paid_leave' => false,
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
        ]);

        $this->assertSame([], $result['payslip_custom_deduction_lines']);
        $this->assertSame(0.0, $result['custom_deductions_this_period']);
        $this->assertSame(0.0, $result['custom_deductions_full_monthly']);
        $this->assertSame([], $result['deduction_schedule']['custom_lines']);
    }

    public function test_applicator_strips_overtime_and_holiday_when_disabled(): void
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $applicator = new EmploymentPayrollPolicyApplicator($resolver);

        $summary = [
            'basic_pay_this_period' => 1000.0,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'component' => 'regular_pay', 'amount' => 1000.0],
                ['key' => 'daily:ot_pay', 'component' => 'ot_pay', 'amount' => 200.0],
                ['key' => 'daily:holiday_premium', 'component' => 'holiday_premium', 'amount' => 150.0, 'scope_match' => true],
            ],
            'payslip_earning_lines' => [
                ['component_code' => 'MEAL_ALLOW', 'amount' => 50.0],
            ],
            'overtime_total_amount' => 200.0,
            'holiday_premium_breakdown' => [
                ['amount' => 150.0, 'eligible' => true, 'scope_match' => true],
            ],
            'employee_statutory_this_period' => 100.0,
            'custom_deductions_this_period' => 25.0,
            'withholding_tax_this_period_estimate' => 10.0,
        ];

        $policy = [
            'employment_type' => 'regular',
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => true,
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
        ];

        $result = $applicator->applyToSummary($summary, $policy);

        $this->assertSame([], $result['overtime_breakdown'] ?? null);
        $this->assertSame(0.0, $result['overtime_total_amount']);
        $this->assertSame([], $result['holiday_premium_breakdown']);
        $this->assertCount(1, $result['daily_computation_earning_lines']);
        $this->assertSame('regular_pay', $result['daily_computation_earning_lines'][0]['component']);
    }

    public function test_applicator_keeps_in_scope_holiday_when_enabled(): void
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $applicator = new EmploymentPayrollPolicyApplicator($resolver);

        $summary = [
            'basic_pay_this_period' => 1000.0,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:holiday_premium', 'component' => 'holiday_premium', 'amount' => 150.0, 'scope_match' => true],
                ['key' => 'daily:holiday_premium', 'component' => 'holiday_premium', 'amount' => 75.0, 'scope_match' => false],
            ],
            'payslip_earning_lines' => [],
            'holiday_premium_breakdown' => [
                ['amount' => 150.0, 'eligible' => true, 'scope_match' => true],
                ['amount' => 75.0, 'eligible' => true, 'scope_match' => false],
            ],
            'employee_statutory_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'withholding_tax_this_period_estimate' => 0.0,
        ];

        $policy = [
            'employment_type' => 'regular',
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => true,
            'allow_overtime' => false,
            'allow_holiday_pay' => true,
        ];

        $result = $applicator->applyToSummary($summary, $policy);

        $this->assertCount(1, $result['daily_computation_earning_lines']);
        $this->assertSame(150.0, $result['daily_computation_earning_lines'][0]['amount']);
        $this->assertCount(1, $result['holiday_premium_breakdown']);
    }

    public function test_consultant_suppression_respects_enabled_policy_toggles(): void
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $applicator = new EmploymentPayrollPolicyApplicator($resolver);

        $enabledPolicy = [
            'employment_type' => 'consultant',
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => true,
            'allow_overtime' => true,
            'allow_holiday_pay' => true,
        ];

        $this->assertFalse($applicator->shouldSuppressConsultantEarningLine([
            'component' => 'ot_pay',
            'amount' => 100.0,
        ], $enabledPolicy));
        $this->assertFalse($applicator->shouldSuppressConsultantEarningLine([
            'component' => 'holiday_premium',
            'amount' => 150.0,
        ], $enabledPolicy));
        $this->assertFalse($applicator->shouldSuppressConsultantEarningLine([
            'component' => 'paid_leave',
            'amount' => 80.0,
        ], $enabledPolicy));
        $this->assertTrue($applicator->shouldSuppressConsultantEarningLine([
            'component' => 'regular_pay',
            'amount' => 1000.0,
        ], $enabledPolicy));

        $disabledPolicy = array_merge($enabledPolicy, [
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
        ]);

        $this->assertTrue($applicator->shouldSuppressConsultantEarningLine([
            'component' => 'ot_pay',
            'amount' => 100.0,
        ], $disabledPolicy));
    }

    public function test_consultant_attendance_earnings_enabled_when_any_toggle_on(): void
    {
        $this->assertFalse(EmploymentPayrollPolicyApplicator::consultantAttendanceEarningsEnabled([
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
        ]));
        $this->assertTrue(EmploymentPayrollPolicyApplicator::consultantAttendanceEarningsEnabled([
            'allow_overtime' => true,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
        ]));
    }

    public function test_refund_lines_with_holiday_labels_are_not_stripped_by_holiday_policy(): void
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $applicator = new EmploymentPayrollPolicyApplicator($resolver);

        $summary = [
            'basic_pay_this_period' => 1000.0,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'component' => 'regular_pay', 'amount' => 1000.0],
            ],
            'payslip_earning_lines' => [
                [
                    'key' => 'refund_basic_pay',
                    'component_code' => 'refund_basic_pay',
                    'label' => 'Missing Holiday Pay',
                    'amount' => 1231.0,
                    'metadata' => ['refund_request_id' => 52],
                ],
                [
                    'key' => 'refund_basic_pay',
                    'component_code' => 'refund_basic_pay',
                    'label' => 'Incorrect Holiday Pay',
                    'amount' => 1231.0,
                    'metadata' => ['refund_request_id' => 55],
                ],
                [
                    'key' => 'daily:holiday_premium',
                    'component' => 'holiday_premium',
                    'label' => 'Holiday Premium',
                    'amount' => 500.0,
                ],
            ],
            'employee_statutory_this_period' => 0.0,
            'withholding_tax_this_period_estimate' => 0.0,
        ];

        $result = $applicator->applyToSummary($summary, [
            'employment_type' => 'regular',
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_paid_leave' => true,
            'allow_overtime' => true,
            'allow_holiday_pay' => false,
        ]);

        $labels = array_map(
            fn ($line) => (string) ($line['label'] ?? ''),
            $result['payslip_earning_lines']
        );

        $this->assertContains('Missing Holiday Pay', $labels);
        $this->assertContains('Incorrect Holiday Pay', $labels);
        $this->assertNotContains('Holiday Premium', $labels);
    }
}
