<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\PayslipService;
use Tests\TestCase;

class PayslipStoredSnapshotViewPayloadTest extends TestCase
{
    public function test_finalized_display_totals_keep_frozen_net_when_lines_sum_higher(): void
    {
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 7658.70,
            'total_deductions' => 1700.00,
            'net_pay' => 5958.70,
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => 8427.93,
                    'display_net_pay' => 6727.93,
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 4423.07],
                        ['key' => 'daily:paid_leave', 'label' => 'Leave adjustments', 'amount' => 1538.46],
                        [
                            'key' => 'holiday:2026-08-15:231:SPECIAL_HOLIDAY_UNWORKED_PAY',
                            'label' => 'Special Holiday — Unworked Pay',
                            'amount' => 769.23,
                            'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        ],
                        [
                            'key' => 'holiday:2026-08-22:242:SPECIAL_HOLIDAY_UNWORKED_PAY',
                            'label' => 'Special Holiday — Unworked Pay',
                            'amount' => 769.23,
                            'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        ],
                    ],
                    'payslip_earning_lines' => [[
                        'key' => 'refund_basic_pay',
                        'label' => 'Other',
                        'amount' => 927.94,
                        'component_code' => 'refund_basic_pay',
                    ]],
                    'payslip_deduction_lines' => [],
                    'payslip_custom_deduction_lines' => [],
                ],
            ],
        ]);

        $totals = app(PayslipService::class)->payslipTotalsForDisplay($payslip);

        $this->assertSame(5958.70, $totals['net_pay']);
        $this->assertSame(7658.70, $totals['gross_pay']);
        $this->assertSame(1700.00, $totals['total_deductions']);
    }

}
