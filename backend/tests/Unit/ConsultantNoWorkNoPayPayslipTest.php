<?php

namespace Tests\Unit;

use App\Services\PayslipService;
use Tests\TestCase;

class ConsultantNoWorkNoPayPayslipTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function snapshot(array $summary): array
    {
        return ['summary' => $summary];
    }

    public function test_no_work_no_pay_consultant_uses_the_regular_attendance_payslip_layout(): void
    {
        $service = app(PayslipService::class);
        $snapshot = $this->snapshot([
            'employment_status' => 'consultant',
            'consultant_fixed_payroll' => false,
            'consultant_no_work_no_pay' => true,
            'basic_pay_this_period' => 23076.96,
            'actual_days_worked' => 12,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23076.96],
            ],
            'payslip_earning_lines' => [],
        ]);
        $summary = $snapshot['summary'];

        $this->assertTrue($service->isConsultantNoWorkNoPaySnapshot($snapshot, $summary));
        $this->assertFalse($service->usesConsultantFixedPayslipLayout($snapshot, $summary));
    }

    public function test_no_work_no_pay_consultant_payslip_totals_use_the_attendance_driven_earning_lines(): void
    {
        $service = app(PayslipService::class);
        $snapshot = $this->snapshot([
            'employment_status' => 'consultant',
            'consultant_fixed_payroll' => false,
            'consultant_no_work_no_pay' => true,
            'basic_pay_this_period' => 23076.96,
            'actual_days_worked' => 12,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23076.96],
            ],
            'payslip_earning_lines' => [],
            'employee_statutory_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'withholding_tax_this_period_estimate' => 0.0,
        ]);

        $totals = $service->payslipLineTotalsFromSnapshot($snapshot);

        // The manual attendance must reach the payslip totals instead of collapsing to zero.
        $this->assertEqualsWithDelta(23076.96, (float) $totals['gross_pay'], 0.05);
    }

    public function test_fixed_consultant_payslip_keeps_the_flat_basic_pay_layout(): void
    {
        $service = app(PayslipService::class);
        $snapshot = $this->snapshot([
            'employment_status' => 'consultant',
            'consultant_fixed_payroll' => true,
            'basic_pay_this_period' => 23076.96,
            'daily_computation_earning_lines' => [
                ['key' => 'consultant:basic_pay', 'label' => 'Basic Pay', 'amount' => 23076.96],
            ],
            'payslip_earning_lines' => [
                ['key' => 'consultant_basic_pay', 'label' => 'Basic Pay', 'amount' => 23076.96],
            ],
        ]);
        $summary = $snapshot['summary'];

        $this->assertFalse($service->isConsultantNoWorkNoPaySnapshot($snapshot, $summary));
        $this->assertTrue($service->usesConsultantFixedPayslipLayout($snapshot, $summary));
    }

    /**
     * The payslip view/preview payload must expose the consultant payroll flags — the frontend
     * decides between the flat consultant Basic Pay and the regular earnings breakdown from them.
     */
    public function test_display_summary_exposes_the_consultant_payroll_flags(): void
    {
        $service = app(PayslipService::class);
        $method = new \ReflectionMethod($service, 'payslipDisplaySummary');
        $method->setAccessible(true);

        $employee = new \App\Models\User([
            'employment_status' => 'consultant',
            'employment_type' => 'full_time',
        ]);

        $noWorkNoPay = $method->invoke($service, [
            'basic_pay_this_period' => 23076.96,
            'actual_days_worked' => 12,
            'consultant_fixed_payroll' => false,
            'consultant_no_work_no_pay' => true,
            'employment_status' => 'consultant',
            'employment_type' => 'full_time',
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23076.96, 'units' => '12 days'],
            ],
        ], [], $employee, [
            ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23076.96, 'units' => '12 days'],
        ]);

        $this->assertTrue($noWorkNoPay['consultant_no_work_no_pay']);
        $this->assertFalse($noWorkNoPay['consultant_fixed_payroll']);
        $this->assertSame('consultant', $noWorkNoPay['employment_status']);
        $this->assertSame('full_time', $noWorkNoPay['employment_type']);
        $this->assertCount(1, $noWorkNoPay['daily_computation_earning_lines']);
        $this->assertSame('Regular pay', $noWorkNoPay['daily_computation_earning_lines'][0]['label']);
        $this->assertSame('12 days', $noWorkNoPay['daily_computation_earning_lines'][0]['units']);

        $fixed = $method->invoke($service, [
            'basic_pay_this_period' => 23076.96,
            'consultant_fixed_payroll' => true,
            'consultant_no_work_no_pay' => false,
            'employment_status' => 'consultant',
            'employment_type' => 'full_time',
        ], [], $employee, []);

        $this->assertFalse($fixed['consultant_no_work_no_pay']);
        $this->assertTrue($fixed['consultant_fixed_payroll']);
    }

    /**
     * A "no work, no pay" consultant must be shaped exactly like a probationary employee:
     * attendance-driven "Regular pay", no flat consultant Basic Pay, no fixed-pay flags.
     */
    public function test_no_work_no_pay_consultant_snapshot_matches_the_probationary_shape(): void
    {
        $service = app(PayslipService::class);

        $probationary = $this->snapshot([
            'employment_status' => 'probationary',
            'employment_type' => 'full_time',
            'basic_pay_this_period' => 5624.96,
            'actual_days_worked' => 9.75,
            'regular_fixed_semi_monthly_payroll' => null,
            'attendance_status' => null,
            'absent_days' => null,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 5624.97, 'units' => '9 days, 6 hrs 0 mins'],
            ],
            'payslip_earning_lines' => [],
            'employee_statutory_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'withholding_tax_this_period_estimate' => 0.0,
        ]);

        $consultant = $this->snapshot([
            'employment_status' => 'consultant',
            'employment_type' => 'full_time',
            'consultant_fixed_payroll' => false,
            'consultant_no_work_no_pay' => true,
            'basic_pay_this_period' => 23076.96,
            'actual_days_worked' => 12,
            'regular_fixed_semi_monthly_payroll' => null,
            'attendance_status' => null,
            'absent_days' => null,
            'daily_computation_earning_lines' => [
                ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23076.96, 'units' => '12 days'],
            ],
            'payslip_earning_lines' => [],
            'employee_statutory_this_period' => 0.0,
            'custom_deductions_this_period' => 0.0,
            'withholding_tax_this_period_estimate' => 0.0,
        ]);

        // Both must be treated as regular attendance-driven payslips.
        $this->assertFalse($service->usesConsultantFixedPayslipLayout($probationary, $probationary['summary']));
        $this->assertFalse($service->usesConsultantFixedPayslipLayout($consultant, $consultant['summary']));

        $probationaryTotals = $service->payslipLineTotalsFromSnapshot($probationary);
        $consultantTotals = $service->payslipLineTotalsFromSnapshot($consultant);

        // Gross comes from the attendance-driven "Regular pay" line in both cases.
        $this->assertEqualsWithDelta(5624.96, (float) $probationaryTotals['gross_pay'], 0.05);
        $this->assertEqualsWithDelta(23076.96, (float) $consultantTotals['gross_pay'], 0.05);

        $this->assertSame(
            array_map(fn ($line) => $line['key'] ?? null, $probationary['summary']['daily_computation_earning_lines']),
            array_map(fn ($line) => $line['key'] ?? null, $consultant['summary']['daily_computation_earning_lines'])
        );
    }
}
