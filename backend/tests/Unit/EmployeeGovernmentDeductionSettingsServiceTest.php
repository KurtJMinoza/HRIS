<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\GovernmentDeductionExemptionResolver;
use App\Services\PayslipService;
use Carbon\Carbon;
use Tests\TestCase;

class EmployeeGovernmentDeductionSettingsServiceTest extends TestCase
{
    public function test_exempted_statutory_deductions_are_zeroed_and_totals_recomputed(): void
    {
        $service = new GovernmentDeductionExemptionResolver;

        $statutory = $service->applyToStatutory([
            'sss' => ['employee_amount' => 750.00, 'employer_amount' => 1500.00, 'ec_amount' => 30.00],
            'philhealth' => ['employee_amount' => 500.00, 'employer_amount' => 500.00],
            'pagibig' => ['employee_amount' => 200.00, 'employer_amount' => 200.00],
            'totals' => ['employee_deduction' => 1450.00, 'employer_liability' => 2230.00],
        ], [
            'deduct_sss' => false,
            'deduct_philhealth' => true,
            'deduct_pagibig' => false,
        ]);

        $this->assertSame(0.0, $statutory['sss']['employee_amount']);
        $this->assertSame(0.0, $statutory['sss']['employer_amount']);
        $this->assertSame(0.0, $statutory['sss']['ec_amount']);
        $this->assertSame(500.00, $statutory['philhealth']['employee_amount']);
        $this->assertSame(0.0, $statutory['pagibig']['employee_amount']);
        $this->assertSame(500.00, $statutory['totals']['employee_deduction']);
        $this->assertSame(500.00, $statutory['totals']['employer_liability']);
        $this->assertTrue($statutory['sss']['exempted']);
        $this->assertSame('SSS - Government deduction exempted', $statutory['sss']['exemption_note']);
    }

    public function test_exempted_withholding_tax_is_zeroed(): void
    {
        $service = new GovernmentDeductionExemptionResolver;

        [$withholding, $monthly] = $service->applyToWithholding([
            'withholding_per_month' => 1234.56,
            'withholding_per_period' => 617.28,
        ], 1234.56, [
            'deduct_withholding_tax' => false,
        ]);

        $this->assertSame(0.0, $monthly);
        $this->assertSame(0.0, $withholding['withholding_per_month']);
        $this->assertSame(0.0, $withholding['withholding_per_period']);
        $this->assertTrue($withholding['exempted']);
        $this->assertSame('Withholding Tax - Government deduction exempted', $withholding['exemption_note']);
    }

    public function test_payslip_snapshot_zeros_exempted_government_deduction_lines(): void
    {
        $employee = new User([
            'id' => 1644,
            'first_name' => 'Federica',
            'last_name' => 'Dela Cruz',
        ]);

        $snapshot = [
            'summary' => [
                'gross_pay_this_period' => 20000.0,
                'custom_deductions_this_period' => 1519.25,
                'payslip_deduction_lines' => [
                    ['key' => 'government:SSS', 'label' => 'SSS', 'amount' => 1000.0],
                    ['key' => 'government:PHILHEALTH', 'label' => 'PhilHealth', 'amount' => 500.0],
                    ['key' => 'government:PAGIBIG', 'label' => 'Pag-IBIG', 'amount' => 200.0],
                    ['key' => 'government:WITHHOLDING', 'label' => 'Withholding tax', 'amount' => 0.0],
                ],
                'government_deduction_exemption' => [
                    'active_for_period' => true,
                    'deduct_sss' => false,
                    'deduct_philhealth' => false,
                    'deduct_pagibig' => false,
                    'deduct_withholding_tax' => true,
                ],
            ],
        ];

        $service = app(PayslipService::class);
        $patched = $service->applyGovernmentExemptionToPayslipSnapshot(
            $snapshot,
            $employee,
            Carbon::parse('2026-05-11'),
            Carbon::parse('2026-05-25')
        );

        $lines = $patched['summary']['payslip_deduction_lines'];
        $this->assertSame(0.0, $lines[0]['amount']);
        $this->assertSame(0.0, $lines[1]['amount']);
        $this->assertSame(0.0, $lines[2]['amount']);
        $this->assertTrue($lines[0]['exempted']);
        $this->assertSame(1519.25, $patched['summary']['total_deductions_this_period']);
        $this->assertSame(0.0, $patched['summary']['employee_statutory_this_period']);
        $this->assertSame(18480.75, $patched['summary']['net_pay_after_withholding_estimate']);
    }

}
