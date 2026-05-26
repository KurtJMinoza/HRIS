<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\DeductionScheduleService;
use App\Services\PayslipService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PayrollFinalizeDeductionFreezeTest extends TestCase
{
    public function test_freeze_preserves_payroll_standard_deduction_amount(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular Pay', 'amount' => 3076.92],
                    ['key' => 'daily:ot_pay', 'label' => 'Overtime', 'amount' => 721.15],
                    ['key' => 'daily:nd_pay', 'label' => 'Night Differential', 'amount' => 12.02],
                ],
                'payslip_earning_lines' => [
                    ['key' => 'pay_component:allowance', 'label' => 'Allowance', 'amount' => 2000.00],
                ],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    [
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'label' => 'Lending Salary Deduction Every 30',
                        'resolved_calculation_standard' => 'payroll_standard',
                        'resolved_schedule' => 'every_30_only',
                        'component_amount' => 1550.00,
                        'amount' => 1550.00,
                    ],
                ],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_GENERATED,
            'gross_pay' => 5810.09,
            'total_deductions' => 4821.64,
            'net_pay' => 988.45,
            'snapshot' => $snapshot,
        ]);

        $metrics = $service->frozenPayslipLineMetrics($payslip);
        $catalog = $service->payrollDeductionLineCatalog($snapshot);

        $this->assertSame(5810.09, $metrics['gross_pay']);
        $this->assertSame(1550.00, $metrics['total_deductions']);
        $this->assertSame(4260.09, $metrics['net_pay']);
        $this->assertCount(1, $catalog);
        $this->assertSame(1550.00, $catalog[0]['amount']);
        $this->assertSame('payroll_standard', $catalog[0]['calculation_standard']);
    }

    public function test_deduction_line_mismatch_detects_changed_amount(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $draft = [[
            'line_key' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
            'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
            'component_name' => 'Lending Salary Deduction Every 30',
            'schedule' => 'every_30_only',
            'calculation_standard' => 'payroll_standard',
            'configured_amount' => 1550.00,
            'amount' => 1550.00,
        ]];
        $final = [[
            'line_key' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
            'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
            'component_name' => 'Lending Salary Deduction Every 30',
            'schedule' => 'every_30_only',
            'calculation_standard' => 'monthly_standard',
            'configured_amount' => 1550.00,
            'amount' => 266.82,
        ]];

        $mismatches = $service->deductionLineMismatches($draft, $final);

        $this->assertNotEmpty($mismatches);
        $this->assertSame(1550.00, $mismatches[0]['draft_amount']);
        $this->assertSame(266.82, $mismatches[0]['finalized_amount']);
    }

    public function test_payroll_standard_every_30_deduction_remains_full_amount(): void
    {
        $service = $this->payslipServiceWithoutConstructor();

        $catalog = $service->payrollDeductionLineCatalog($this->snapshotWithCustomDeduction(
            'LENDING_SALARY_DEDUCTION_EVERY_30',
            'Lending Salary Deduction Every 30',
            'payroll_standard',
            'every_30_only',
            1550.00,
            1550.00
        ));

        $this->assertSame(1550.00, $catalog[0]['configured_amount']);
        $this->assertSame(1550.00, $catalog[0]['amount']);
        $this->assertSame('payroll_standard', $catalog[0]['calculation_standard']);
        $this->assertSame('every_30_only', $catalog[0]['schedule']);
    }

    public function test_payroll_standard_split_schedule_deduction_remains_full_amount(): void
    {
        $service = $this->payslipServiceWithoutConstructor();

        $catalog = $service->payrollDeductionLineCatalog($this->snapshotWithCustomDeduction(
            'PAYROLL_STANDARD_SPLIT_DEDUCTION',
            'Payroll Standard Split Deduction',
            'payroll_standard',
            '15th_and_30th',
            500.00,
            500.00
        ));

        $this->assertSame(500.00, $catalog[0]['configured_amount']);
        $this->assertSame(500.00, $catalog[0]['amount']);
        $this->assertSame('payroll_standard', $catalog[0]['calculation_standard']);
    }

    public function test_monthly_standard_split_schedule_preserves_draft_resolved_amount(): void
    {
        $service = $this->payslipServiceWithoutConstructor();

        $catalog = $service->payrollDeductionLineCatalog($this->snapshotWithCustomDeduction(
            'MONTHLY_STANDARD_SPLIT_DEDUCTION',
            'Monthly Standard Split Deduction',
            'monthly_standard',
            '15th_and_30th',
            1000.00,
            500.00
        ));

        $this->assertSame(1000.00, $catalog[0]['configured_amount']);
        $this->assertSame(500.00, $catalog[0]['amount']);
        $this->assertSame('monthly_standard', $catalog[0]['calculation_standard']);
    }

    public function test_frozen_metrics_preserve_all_earnings_and_deductions(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'category' => 'regular_pay', 'label' => 'Regular Pay', 'amount' => 3076.92],
                    ['key' => 'daily:ot_pay', 'category' => 'overtime', 'label' => 'OT', 'amount' => 721.15],
                    ['key' => 'daily:nd_pay', 'category' => 'night_differential', 'label' => 'ND', 'amount' => 12.02],
                    ['key' => 'daily:holiday_pay', 'category' => 'holiday_pay', 'label' => 'Holiday', 'amount' => 0.00],
                ],
                'payslip_earning_lines' => [
                    ['key' => 'pay_component:allowance', 'category' => 'allowance', 'label' => 'Allowance', 'amount' => 2000.00],
                ],
                'payslip_deduction_lines' => [
                    ['key' => 'statutory:combined', 'category' => 'government_deduction', 'label' => 'Government Deductions', 'amount' => 3271.64],
                ],
                'payslip_custom_deduction_lines' => [
                    [
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'category' => 'deduction',
                        'label' => 'Lending Salary Deduction Every 30',
                        'resolved_calculation_standard' => 'payroll_standard',
                        'component_schedule' => 'every_30_only',
                        'component_amount' => 1550.00,
                        'resolved_amount' => 1550.00,
                    ],
                ],
            ],
        ];
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_GENERATED,
            'gross_pay' => 5810.09,
            'total_deductions' => 4821.64,
            'net_pay' => 988.45,
            'snapshot' => $snapshot,
        ]);

        $metrics = $service->frozenPayslipLineMetrics($payslip);
        $catalog = $service->payrollDeductionLineCatalog($snapshot);

        $this->assertSame(5810.09, $metrics['gross_pay']);
        $this->assertSame(4821.64, $metrics['total_deductions']);
        $this->assertSame(988.45, $metrics['net_pay']);
        $this->assertSame(3076.92, $metrics['regular_pay']);
        $this->assertSame(721.15, $metrics['overtime_pay']);
        $this->assertSame(12.02, $metrics['night_differential']);
        $this->assertSame(2000.00, $metrics['allowances']);
        $this->assertSame(1550.00, $catalog[1]['amount']);
        $this->assertSame('every_30_only', $catalog[1]['schedule']);
    }

    public function test_snapshot_totals_do_not_shrink_payroll_standard_deduction_to_gross_remaining(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular Pay', 'amount' => 1538.46],
                ],
                'payslip_earning_lines' => [
                    ['key' => 'pay_component:23', 'label' => 'Allowance', 'amount' => 2000.00],
                ],
                'payslip_deduction_lines' => [
                    ['key' => 'government:combined', 'label' => 'Government Deductions', 'amount' => 1700.00],
                ],
                'payslip_custom_deduction_lines' => [
                    ['key' => 'pay_component:27', 'label' => 'AWIC', 'amount' => 241.66],
                    ['key' => 'pay_component:26', 'label' => 'AWIC 30', 'amount' => 1329.98],
                    [
                        'key' => 'pay_component:38',
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'label' => 'Lending Salary Deduction Every 30',
                        'resolved_calculation_standard' => 'payroll_standard',
                        'component_schedule' => '30th',
                        'component_amount' => 1550.00,
                        'resolved_amount' => 1550.00,
                        'amount' => 1550.00,
                    ],
                ],
            ],
        ];

        $totals = $service->payslipLineTotalsFromSnapshot($snapshot);
        $catalog = $service->payrollDeductionLineCatalog($snapshot);

        $this->assertSame(3538.46, $totals['gross_pay']);
        $this->assertSame(4821.64, $totals['total_deductions']);
        $this->assertSame(-1283.18, $totals['net_pay']);
        $this->assertSame(1550.00, $catalog[3]['amount']);
        $this->assertSame('payroll_standard', $catalog[3]['calculation_standard']);
    }

    public function test_acceptance_negative_net_pay_uses_full_deduction_line_sum(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular Pay', 'amount' => 1538.46],
                ],
                'payslip_earning_lines' => [
                    ['key' => 'pay_component:allowance', 'label' => 'Allowance', 'amount' => 2000.00],
                ],
                'payslip_deduction_lines' => [
                    ['key' => 'government:sss', 'label' => 'SSS', 'amount' => 1000.00],
                    ['key' => 'government:philhealth', 'label' => 'PhilHealth', 'amount' => 500.00],
                    ['key' => 'government:pagibig', 'label' => 'Pag-IBIG', 'amount' => 200.00],
                ],
                'payslip_custom_deduction_lines' => [
                    ['key' => 'pay_component:awic15and30', 'component_code' => 'AWIC_DEDUCTION_EVERY_15_AND_30', 'label' => 'AWIC DEDUCTION EVERY 15 AND 30', 'amount' => 241.66],
                    ['key' => 'pay_component:awic30', 'component_code' => 'AWIC_DEDUCTION_EVERY_30', 'label' => 'AWIC DEDUCTION EVERY 30', 'amount' => 1329.98],
                    [
                        'key' => 'pay_component:lending30',
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'label' => 'LENDING SALARY DEDUCTION EVERY 30',
                        'resolved_calculation_standard' => 'payroll_standard',
                        'resolved_schedule' => '30th',
                        'component_amount' => 1550.00,
                        'amount' => 1550.00,
                    ],
                ],
            ],
        ];
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_GENERATED,
            'gross_pay' => 3538.46,
            'total_deductions' => 3538.46,
            'net_pay' => 0.00,
            'snapshot' => $snapshot,
        ]);

        $totals = $service->payslipLineTotalsFromSnapshot($snapshot);
        $metrics = $service->frozenPayslipLineMetrics($payslip);
        $catalog = $service->payrollDeductionLineCatalog($snapshot);

        $this->assertSame(3538.46, $totals['gross_pay']);
        $this->assertSame(4821.64, $totals['total_deductions']);
        $this->assertSame(-1283.18, $totals['net_pay']);
        $this->assertSame(4821.64, $metrics['total_deductions']);
        $this->assertSame(-1283.18, $metrics['net_pay']);
        $this->assertSame(1550.00, $catalog[5]['amount']);
    }

    public function test_display_totals_do_not_mutate_snapshot(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular Pay', 'amount' => 3076.92],
                ],
                'payslip_earning_lines' => [],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    [
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'label' => 'Lending Salary Deduction Every 30',
                        'amount' => 1550.00,
                    ],
                ],
            ],
        ];
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_GENERATED,
            'gross_pay' => 3076.92,
            'total_deductions' => 1550.00,
            'net_pay' => 1526.92,
            'snapshot' => $snapshot,
        ]);

        $totals = $service->payslipTotalsForDisplay($payslip);

        $this->assertSame(3076.92, $totals['gross_pay']);
        $this->assertSame(1550.00, $totals['total_deductions']);
        $this->assertSame(
            1550.00,
            (float) data_get($payslip->snapshot, 'summary.payslip_custom_deduction_lines.0.amount')
        );
    }

    public function test_unscheduled_zero_custom_deductions_are_hidden_from_payslip_view(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'deduction_schedule' => [
                    'semi_monthly_period' => 'second',
                ],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    [
                        'component_code' => 'FAMES_EVERY_15',
                        'label' => 'FAMES EVERY 15',
                        'amount' => 0.00,
                        'resolved_schedule' => '15th',
                    ],
                    [
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_15',
                        'label' => 'LENDING SALARY DEDUCTION EVERY 15',
                        'amount' => 0.00,
                        'resolved_schedule' => '15th',
                    ],
                    [
                        'component_code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                        'label' => 'LENDING SALARY DEDUCTION EVERY 30',
                        'amount' => 1550.00,
                        'resolved_schedule' => '30th',
                    ],
                ],
            ],
        ];

        $view = $service->frozenSnapshotForPayslipView($snapshot);
        $lines = $view['summary']['payslip_custom_deduction_lines'];

        $this->assertCount(1, $lines);
        $this->assertSame('LENDING_SALARY_DEDUCTION_EVERY_30', $lines[0]['component_code']);
        $this->assertSame(1550.00, $lines[0]['amount']);
    }

    public function test_live_custom_deduction_display_lines_skip_off_cycle_zero_rows(): void
    {
        $service = (new ReflectionClass(DeductionScheduleService::class))->newInstanceWithoutConstructor();

        $lines = $service->buildPayslipCustomDeductionDisplayLines([
            [
                'code' => 'FAMES_EVERY_15',
                'name' => 'FAMES EVERY 15',
                'computed_amount' => 1000.00,
                'scheduled_this_period' => 0.00,
                'deduction_schedule_type' => '15th',
                'payroll_run_type' => '30th',
            ],
            [
                'code' => 'LENDING_SALARY_DEDUCTION_EVERY_30',
                'name' => 'LENDING SALARY DEDUCTION EVERY 30',
                'computed_amount' => 1550.00,
                'scheduled_this_period' => 1550.00,
                'deduction_schedule_type' => '30th',
                'payroll_run_type' => '30th',
            ],
        ]);

        $this->assertCount(1, $lines);
        $this->assertSame('LENDING_SALARY_DEDUCTION_EVERY_30', $lines[0]['component_code']);
        $this->assertSame(1550.00, $lines[0]['amount']);
    }

    private function payslipServiceWithoutConstructor(): PayslipService
    {
        return (new ReflectionClass(PayslipService::class))->newInstanceWithoutConstructor();
    }

    private function snapshotWithCustomDeduction(
        string $code,
        string $label,
        string $standard,
        string $schedule,
        float $configuredAmount,
        float $resolvedAmount
    ): array {
        return [
            'summary' => [
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    [
                        'component_code' => $code,
                        'label' => $label,
                        'resolved_calculation_standard' => $standard,
                        'component_schedule' => $schedule,
                        'component_amount' => $configuredAmount,
                        'resolved_amount' => $resolvedAmount,
                    ],
                ],
            ],
        ];
    }
}
