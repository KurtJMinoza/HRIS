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
                    'display_gross_pay' => 7658.70,
                    'display_net_pay' => 5958.70,
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

    public function test_finalized_display_totals_use_after_reductions_when_stored_gross_is_stale_high(): void
    {
        $regularAfterLate = 6810.80;
        $paidLeave = 570.54;
        $holidayPay = 570.54;
        $expectedGross = round($regularAfterLate + $paidLeave + $holidayPay, 2);
        $staleStoredGross = round(6846.46 + $paidLeave + $holidayPay, 2);

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => $staleStoredGross,
            'total_deductions' => 416.67,
            'net_pay' => round($staleStoredGross - 416.67, 2),
            'snapshot' => [
                'summary' => [
                    'regular_fixed_semi_monthly_payroll' => true,
                    'display_gross_pay' => $staleStoredGross,
                    'display_net_pay' => round($staleStoredGross - 416.67, 2),
                    'attendance_pay_breakdown' => [
                        'available' => true,
                        'regular_pay_after_reductions' => $regularAfterLate,
                        'total_deduction' => 35.66,
                    ],
                    'daily_computation_earning_lines' => [
                        [
                            'key' => 'daily:regular_pay',
                            'label' => 'Regular pay',
                            'amount' => $regularAfterLate,
                            'display_amount' => 6846.46,
                        ],
                        [
                            'key' => 'daily:paid_leave',
                            'label' => 'Leave adjustments',
                            'amount' => $paidLeave,
                            'display_amount' => $paidLeave,
                            'metadata' => ['included_in_fixed_semi_monthly_basic' => true],
                        ],
                        [
                            'key' => 'holiday:2026-08-31:REGULAR_HOLIDAY_WORKED_PAY',
                            'label' => 'Regular Holiday — Worked Pay: NATIONAL HEROES DAY',
                            'amount' => $holidayPay,
                            'component_code' => 'REGULAR_HOLIDAY_WORKED_PAY',
                        ],
                    ],
                    'payslip_earning_lines' => [],
                    'payslip_deduction_lines' => [],
                    'payslip_custom_deduction_lines' => [],
                ],
            ],
        ]);

        $totals = app(PayslipService::class)->payslipTotalsForDisplay($payslip);

        $this->assertEqualsWithDelta($expectedGross, $totals['gross_pay'], 0.02);
        $this->assertEqualsWithDelta(round($expectedGross - 416.67, 2), $totals['net_pay'], 0.02);
        $this->assertEqualsWithDelta(416.67, $totals['total_deductions'], 0.02);
    }

}
