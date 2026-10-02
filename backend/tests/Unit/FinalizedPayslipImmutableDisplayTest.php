<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\PayslipService;
use Tests\TestCase;

class FinalizedPayslipImmutableDisplayTest extends TestCase
{
    public function test_finalized_frozen_snapshot_skips_live_semi_monthly_repair(): void
    {
        $service = app(PayslipService::class);
        $snapshot = [
            'summary' => [
                'regular_fixed_semi_monthly_payroll' => true,
                'display_gross_pay' => 5000.0,
                'display_net_pay' => 4800.0,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'regular_pay_after_reductions' => 4500.0,
                    'total_deduction' => 35.66,
                    'scheduled_days_count' => 11,
                ],
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 4500.0,
                    'display_amount' => 5000.0,
                    'units' => '10 days',
                ]],
            ],
            'daily_computation_days' => [[
                'date' => '2026-08-01',
                'day_type' => 'regular',
                'minutes_worked' => 480,
            ]],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 5000.0,
            'total_deductions' => 200.0,
            'net_pay' => 4800.0,
        ]);

        $view = $service->frozenSnapshotForPayslipView($snapshot, $payslip);
        $summary = is_array($view['summary'] ?? null) ? $view['summary'] : [];

        $this->assertSame(5000.0, $summary['display_gross_pay']);
        $this->assertSame(4800.0, $summary['display_net_pay']);
        $this->assertSame(4500.0, $summary['daily_computation_earning_lines'][0]['amount']);
    }
}
