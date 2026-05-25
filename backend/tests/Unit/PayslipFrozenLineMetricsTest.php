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

    private function payslipServiceWithoutConstructor(): PayslipService
    {
        return (new ReflectionClass(PayslipService::class))->newInstanceWithoutConstructor();
    }
}
