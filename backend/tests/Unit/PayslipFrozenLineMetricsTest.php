<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\PayslipService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PayslipFrozenLineMetricsTest extends TestCase
{
    public function test_frozen_metrics_preserve_required_payroll_line_categories(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 0,
            'total_deductions' => 0,
            'net_pay' => 0,
            'snapshot' => [
                'summary' => [
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 1000],
                        ['key' => 'daily:holiday_premium', 'label' => 'Holiday Pay', 'amount' => 300],
                        ['key' => 'daily:ot_pay', 'label' => 'Overtime', 'amount' => 200],
                        ['key' => 'daily:nd_pay', 'label' => 'Night Differential', 'amount' => 100],
                        ['key' => 'daily:paid_leave', 'label' => 'Paid Leave', 'amount' => 400],
                    ],
                    'payslip_earning_lines' => [
                        ['key' => 'pay_component:7', 'label' => 'Meal Allowance', 'amount' => 150],
                    ],
                    'payslip_deduction_lines' => [
                        ['key' => 'SSS', 'label' => 'SSS', 'amount' => 50],
                    ],
                    'payslip_custom_deduction_lines' => [
                        ['key' => 'deduction:9', 'label' => 'Other Deduction', 'amount' => 75],
                    ],
                ],
            ],
        ]);

        $metrics = $service->frozenPayslipLineMetrics($payslip);

        $this->assertSame(8, $metrics['line_count']);
        $this->assertSame(2150.0, $metrics['gross_pay']);
        $this->assertSame(125.0, $metrics['total_deductions']);
        $this->assertSame(2025.0, $metrics['net_pay']);
        $this->assertSame(1000.0, $metrics['regular_pay']);
        $this->assertSame(300.0, $metrics['holiday_pay']);
        $this->assertSame(200.0, $metrics['overtime_pay']);
        $this->assertSame(100.0, $metrics['night_differential']);
        $this->assertSame(400.0, $metrics['paid_leave']);
        $this->assertSame(150.0, $metrics['allowances']);
        $this->assertSame(75.0, $metrics['other_deductions']);
        $this->assertContains('government_deduction', $metrics['categories']);
    }

    public function test_finalized_sync_does_not_rewrite_snapshot(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:holiday_premium', 'label' => 'Holiday Pay', 'amount' => 300],
                ],
                'payslip_earning_lines' => [],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [],
            ],
        ];
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 999,
            'total_deductions' => 0,
            'net_pay' => 999,
            'snapshot' => $snapshot,
        ]);

        $totals = $service->syncPayslipSummaryFromLines($payslip);

        $this->assertSame(300.0, $totals['gross_pay']);
        $this->assertFalse($totals['changed']);
        $this->assertSame($snapshot, $payslip->snapshot);
        $this->assertSame('999.00', (string) $payslip->gross_pay);
    }

    public function test_execom_sanitize_keeps_overtime_and_holiday_when_allowed(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $method = (new ReflectionClass(PayslipService::class))->getMethod('sanitizeExecomPayslipSummary');
        $method->setAccessible(true);

        $summary = [
            'execom_settings' => [
                'apply_custom_deductions' => false,
                'apply_allowances' => false,
                'allow_paid_leave' => true,
                'allow_overtime' => true,
                'allow_holiday_pay' => true,
            ],
            'basic_pay' => 25000.0,
            'basic_pay_this_period' => 25000.0,
            'paid_leave_amount' => 0.0,
            'leave_deduction' => 0.0,
            'overtime_total_amount' => 500.0,
            'payslip_earning_lines' => [
                [
                    'key' => 'execom_basic_pay',
                    'label' => 'Basic Pay',
                    'category' => 'basic_pay',
                    'component_code' => 'BASIC_SALARY',
                    'amount' => 25000.0,
                ],
                [
                    'key' => 'daily:ot_pay',
                    'label' => 'Overtime',
                    'component' => 'ot_pay',
                    'category' => 'overtime',
                    'amount' => 500.0,
                ],
                [
                    'key' => 'daily:holiday_premium',
                    'label' => 'Holiday premium',
                    'component' => 'holiday_premium',
                    'category' => 'holiday_pay',
                    'amount' => 1000.0,
                    'metadata' => ['scope_match' => true],
                ],
                [
                    'key' => 'holiday:out-of-scope:REGULAR_HOLIDAY_UNWORKED_PAY',
                    'label' => 'Regular Holiday — Unworked Pay: OUT',
                    'category' => 'holiday_pay',
                    'amount' => 769.23,
                    'metadata' => ['scope_match' => false],
                ],
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 999.0,
                ],
            ],
            'payslip_deduction_lines' => [],
            'payslip_custom_deduction_lines' => [],
        ];

        $out = $method->invoke($service, $summary, []);
        $labels = array_map(
            static fn (array $line): string => (string) ($line['label'] ?? ''),
            $out['payslip_earning_lines']
        );

        $this->assertContains('Basic Pay', $labels);
        $this->assertContains('Overtime', $labels);
        $this->assertContains('Holiday premium', $labels);
        $this->assertNotContains('Regular Holiday — Unworked Pay: OUT', $labels);
        $this->assertNotContains('Regular pay', $labels);
        $this->assertSame(500.0, $out['overtime_total_amount']);
        $this->assertSame(1500.0, $out['attendance_premium_pay_this_period']);
        $this->assertSame(26500.0, $out['total_pay']);
    }

    public function test_regular_pay_attendance_label_uses_present_day_count(): void
    {
        $service = app(PayslipService::class);
        $label = $service->regularPayAttendanceLabel([
            'daily_rate' => 525.0,
            'attendance_display_summary' => [
                'presence_days_count' => 13,
                'working_days_count' => 13,
                'lines' => [],
            ],
            'daily_computation_earning_lines' => [
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'minutes_worked' => (12 * 8 * 60) + (6 * 60) + 30,
                    'amount' => 4921.88,
                ],
            ],
        ]);

        $this->assertSame('13 days', $label);
    }

    public function test_regular_pay_display_amount_shows_present_day_gross_only(): void
    {
        $snapshot = [
            'daily_rate' => 582.69,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 582.69,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 15150,
                'semi_monthly_basic_salary' => 7575,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 7429.30,
                ]],
            ],
            'daily_computation_days' => array_fill(0, 13, [
                'date' => '2026-07-26',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 450,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'undertime_deduction_minutes' => 30,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 450,
                    'rate' => 72.83625,
                    'amount' => 546.27,
                ]],
            ]),
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;
        $totalDeduction = (float) ($normalized['summary']['attendance_pay_breakdown']['total_deduction'] ?? 0);
        $netAmount = round((float) ($line['amount'] ?? 0), 2);
        $displayAmount = round((float) ($line['display_amount'] ?? 0), 2);

        $this->assertSame('13 days', $line['units'] ?? null);
        $this->assertSame(7575.0, $displayAmount);
        $this->assertSame($netAmount, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame($netAmount, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));
        $this->assertGreaterThan($netAmount, $displayAmount);
        $this->assertGreaterThan(0.0, $totalDeduction);
    }

    public function test_payslip_totals_snap_daily_rate_rounding_to_semi_monthly(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 692.31,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 18000,
                'semi_monthly_basic_salary' => 9000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 9000.03,
                    'minutes_worked' => 13 * 8 * 60,
                    'hourly_rate' => 692.31 / 8,
                ]],
            ],
            'daily_computation_days' => array_fill(0, 13, [
                'date' => '2026-07-26',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 480,
                    'rate' => 692.31 / 8,
                    'amount' => 692.31,
                ]],
            ]),
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame(9000.0, round((float) ($line['display_amount'] ?? 0), 2));
        $this->assertSame(9000.0, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame(9000.0, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));

        $displayTotals = app(PayslipService::class)->payslipDisplayTotalsFromSnapshot($snapshot);
        $this->assertSame(9000.0, $displayTotals['gross_pay']);
        $this->assertSame(9000.0, $displayTotals['net_pay']);
    }

    public function test_consultant_payslip_does_not_double_count_basic_pay_in_gross(): void
    {
        $snapshot = [
            'summary' => [
                'consultant_fixed_payroll' => true,
                'employment_status' => 'consultant',
                'basic_pay_this_period' => 23158,
                'payslip_earning_lines' => [
                    ['key' => 'consultant_basic_pay', 'label' => 'Basic Pay', 'amount' => 23158],
                    ['key' => 'daily:regular_pay', 'label' => 'Basic Pay', 'amount' => 23158],
                    ['key' => 'pay_component:allowance', 'label' => 'ALLOWANCE', 'component_code' => 'ALLOWANCE', 'amount' => 10000],
                ],
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23158],
                ],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    ['key' => 'lending', 'label' => 'LENDING SALARY DEDUCTION EVERY 15 AND 30', 'amount' => 15900],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);

        $this->assertSame(33158.0, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame(17258.0, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));
    }

    private function payslipServiceWithoutConstructor(): PayslipService
    {
        return (new ReflectionClass(PayslipService::class))->newInstanceWithoutConstructor();
    }
}
