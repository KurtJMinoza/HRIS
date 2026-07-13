<?php

namespace Tests\Unit;

use App\Models\DeductionScheduleSetting;
use App\Models\PayComponent;
use App\Services\Compensation\AllowanceCalculationResult;
use App\Services\Compensation\AllowanceCalculationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllowanceCalculationServiceTest extends TestCase
{
    private AllowanceCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AllowanceCalculationService();
    }

    /** Full decision table from architecture doc (amount = 5,000). */
    #[DataProvider('decisionTableProvider')]
    public function test_decision_table(
        string $frequency,
        string $selectedPayrolls,
        string $prorationMethod,
        string $currentPayroll,
        float $expectedPayrollAmount,
        float $expectedMonthlyEquivalent,
        bool $expectedScheduled,
    ): void {
        $result = $this->service->compute(
            amount: 5000.0,
            frequency: $frequency,
            selectedPayrolls: $selectedPayrolls,
            prorationMethod: $prorationMethod,
            currentPayroll: $currentPayroll,
            isTaxable: true,
        );

        $this->assertSame($expectedPayrollAmount, $result->payrollAmount, 'payrollAmount mismatch');
        $this->assertSame($expectedMonthlyEquivalent, $result->monthlyEquivalent, 'monthlyEquivalent mismatch');
        $this->assertSame($expectedScheduled, $result->isScheduledThisRun, 'isScheduledThisRun mismatch');
        $this->assertSame($result->payrollAmount, $result->grossPayAmount, 'grossPayAmount should equal payrollAmount');
    }

    public static function decisionTableProvider(): array
    {
        return [
            'Monthly Standard + 15th + None' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_15TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_15TH,
                5000.0,   // payrollAmount
                5000.0,   // monthlyEquivalent
                true,     // isScheduledThisRun
            ],
            'Monthly Standard + 30th + None' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_30TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_30TH,
                5000.0,
                5000.0,
                true,
            ],
            'Monthly Standard + Both + None (15th run)' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_15TH,
                5000.0,   // full amount on each run
                10000.0,  // 5000 * 2
                true,
            ],
            'Monthly Standard + Both + None (30th run)' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_30TH,
                5000.0,
                10000.0,
                true,
            ],
            'Monthly Standard + Both + Split (15th run)' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                DeductionScheduleSetting::SCHEDULE_15TH,
                2500.0,   // half on each run
                5000.0,   // 2500 * 2 = original amount
                true,
            ],
            'Monthly Standard + Both + Split (30th run)' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                DeductionScheduleSetting::SCHEDULE_30TH,
                2500.0,
                5000.0,
                true,
            ],
            'Payroll Standard + 15th + None (15th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_15TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_15TH,
                5000.0,
                5000.0,
                true,
            ],
            'Payroll Standard + 15th + None (30th run — not scheduled)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_15TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_30TH,
                0.0,       // not scheduled for 30th
                5000.0,    // monthly equivalent still 5000 (only 1 run)
                false,
            ],
            'Payroll Standard + 30th + None (30th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_30TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_30TH,
                5000.0,
                5000.0,
                true,
            ],
            'Payroll Standard + 30th + None (15th run — not scheduled)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_30TH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_15TH,
                0.0,
                5000.0,
                false,
            ],
            'Payroll Standard + Both + None (15th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_15TH,
                5000.0,
                10000.0,
                true,
            ],
            'Payroll Standard + Both + None (30th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                DeductionScheduleSetting::SCHEDULE_30TH,
                5000.0,
                10000.0,
                true,
            ],
            'Payroll Standard + Both + Split (15th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                DeductionScheduleSetting::SCHEDULE_15TH,
                5000.0,
                10000.0,
                true,
            ],
            'Payroll Standard + Both + Split (30th run)' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                DeductionScheduleSetting::SCHEDULE_30TH,
                5000.0,
                10000.0,
                true,
            ],
        ];
    }

    #[DataProvider('monthlyEquivalentProvider')]
    public function test_monthly_equivalent(
        string $frequency,
        string $selectedPayrolls,
        string $prorationMethod,
        float $expectedMonthlyEquivalent,
    ): void {
        $result = $this->service->monthlyEquivalent(
            amount: 5000.0,
            frequency: $frequency,
            selectedPayrolls: $selectedPayrolls,
            prorationMethod: $prorationMethod,
        );

        $this->assertSame($expectedMonthlyEquivalent, $result);
    }

    public static function monthlyEquivalentProvider(): array
    {
        return [
            'Monthly Standard + 15th + None' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_15TH,
                AllowanceCalculationService::PRORATION_NONE,
                5000.0,
            ],
            'Monthly Standard + 30th + None' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_30TH,
                AllowanceCalculationService::PRORATION_NONE,
                5000.0,
            ],
            'Monthly Standard + Both + None' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                10000.0,
            ],
            'Monthly Standard + Both + Split' => [
                PayComponent::STANDARD_MONTHLY,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                5000.0,
            ],
            'Payroll Standard + 15th + None' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_15TH,
                AllowanceCalculationService::PRORATION_NONE,
                5000.0,
            ],
            'Payroll Standard + 30th + None' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_30TH,
                AllowanceCalculationService::PRORATION_NONE,
                5000.0,
            ],
            'Payroll Standard + Both + None' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_NONE,
                10000.0,
            ],
            'Payroll Standard + Both + Split' => [
                PayComponent::STANDARD_PAYROLL,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                AllowanceCalculationService::PRORATION_SPLIT,
                10000.0,
            ],
        ];
    }

    public function test_payslip_amounts(): void
    {
        // Monthly Standard + Both + Split: 15th=2500, 30th=2500, monthly=5000
        $result = $this->service->payslipAmounts(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_SPLIT,
        );

        $this->assertSame(2500.0, $result['15th']);
        $this->assertSame(2500.0, $result['30th']);
        $this->assertSame(5000.0, $result['monthly_equivalent']);
    }

    public function test_payslip_amounts_monthly_standard_both_none(): void
    {
        // Monthly Standard + Both + None: 15th=5000, 30th=5000, monthly=10000
        $result = $this->service->payslipAmounts(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
        );

        $this->assertSame(5000.0, $result['15th']);
        $this->assertSame(5000.0, $result['30th']);
        $this->assertSame(10000.0, $result['monthly_equivalent']);
    }

    public function test_payslip_amounts_single_run(): void
    {
        // Payroll Standard + 15th only: 15th=5000, 30th=0, monthly=5000
        $result = $this->service->payslipAmounts(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_PAYROLL,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_15TH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
        );

        $this->assertSame(5000.0, $result['15th']);
        $this->assertSame(0.0, $result['30th']);
        $this->assertSame(5000.0, $result['monthly_equivalent']);
    }

    public function test_taxable_amount(): void
    {
        $result = $this->service->compute(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
            isTaxable: true,
        );

        $this->assertSame(5000.0, $result->taxableAmount);
        $this->assertSame(0.0, $result->nonTaxableAmount);
    }

    public function test_non_taxable_amount(): void
    {
        $result = $this->service->compute(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
            isTaxable: false,
        );

        $this->assertSame(0.0, $result->taxableAmount);
        $this->assertSame(5000.0, $result->nonTaxableAmount);
    }

    public function test_zero_amount(): void
    {
        $result = $this->service->compute(
            amount: 0.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
        );

        $this->assertSame(0.0, $result->payrollAmount);
        $this->assertSame(0.0, $result->monthlyEquivalent);
        $this->assertSame(0.0, $result->grossPayAmount);
    }

    public function test_negative_amount_is_clamped(): void
    {
        $result = $this->service->compute(
            amount: -100.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
        );

        $this->assertSame(0.0, $result->payrollAmount);
        $this->assertSame(0.0, $result->monthlyEquivalent);
    }

    public function test_to_array(): void
    {
        $result = $this->service->compute(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_MONTHLY,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
        );

        $arr = $result->toArray();

        $this->assertSame(5000.0, $arr['payroll_amount']);
        $this->assertSame(10000.0, $arr['monthly_equivalent']);
        $this->assertSame(5000.0, $arr['gross_pay_amount']);
        $this->assertSame(2, $arr['payroll_count']);
        $this->assertSame('both', $arr['resolved_schedule']);
        $this->assertSame(PayComponent::STANDARD_MONTHLY, $arr['calculation_standard']);
        $this->assertTrue($arr['is_scheduled_this_run']);
    }

    #[DataProvider('prorationResolutionProvider')]
    public function test_resolve_poration_method(
        ?array $metadata,
        ?string $scheduleOverride,
        ?string $resolvedSchedule,
        string $expected,
        string $label,
    ): void {
        $result = AllowanceCalculationService::resolveProrationMethodFromMetadata(
            metadata: $metadata,
            scheduleOverride: $scheduleOverride,
            resolvedSchedule: $resolvedSchedule,
        );

        $this->assertSame($expected, $result, "Failed for case: $label");
    }

    public static function prorationResolutionProvider(): array
    {
        return [
            'explicit none in metadata' => [
                ['allowance_schedule_proration' => 'none'],
                null,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                'none',
                'explicit none in metadata',
            ],
            'explicit split in metadata' => [
                ['allowance_schedule_proration' => 'split'],
                null,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                'split',
                'explicit split in metadata',
            ],
            'schedule_override split' => [
                null,
                'split',
                DeductionScheduleSetting::SCHEDULE_BOTH,
                'split',
                'schedule_override split',
            ],
            'single run schedule defaults to none' => [
                null,
                null,
                DeductionScheduleSetting::SCHEDULE_15TH,
                'none',
                'single run -> none',
            ],
            'both schedule defaults to none' => [
                null,
                null,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                'none',
                'both -> none default',
            ],
            'metadata takes priority over schedule_override' => [
                ['allowance_schedule_proration' => 'none'],
                'split',
                DeductionScheduleSetting::SCHEDULE_BOTH,
                'none',
                'metadata priority',
            ],
        ];
    }

    public function test_multiple_run_counts(): void
    {
        // Single run = 1, Both = 2
        $single = $this->service->compute(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_PAYROLL,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_15TH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
        );
        $this->assertSame(1, $single->payrollCount);

        $both = $this->service->compute(
            amount: 5000.0,
            frequency: PayComponent::STANDARD_PAYROLL,
            selectedPayrolls: DeductionScheduleSetting::SCHEDULE_BOTH,
            prorationMethod: AllowanceCalculationService::PRORATION_NONE,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH,
        );
        $this->assertSame(2, $both->payrollCount);
    }
}
