<?php

namespace App\Services\Compensation;

use App\Models\DeductionScheduleSetting;
use App\Models\PayComponent;

/**
 * Single source of truth for allowance amount computation.
 *
 * Every module (Employee Compensation, Payroll Processing, Payslip Generation,
 * Gross Pay) must call this service and consume the returned result object
 * instead of performing independent calculations.
 *
 * Decision table (amount = 5,000 example):
 *
 * | Frequency        | Payroll Selection | Proration | 15th   | 30th   | Monthly Equivalent |
 * |------------------|-------------------|-----------|--------|--------|--------------------|
 * | Monthly Standard | 15th              | None      | 5,000  | 0      | 5,000              |
 * | Monthly Standard | 30th              | None      | 0      | 5,000  | 5,000              |
 * | Monthly Standard | 15th + 30th       | None      | 5,000  | 5,000  | 10,000             |
 * | Monthly Standard | 15th + 30th       | Split     | 2,500  | 2,500  | 5,000              |
 * | Payroll Standard | 15th              | None      | 5,000  | 0      | 5,000              |
 * | Payroll Standard | 30th              | None      | 0      | 5,000  | 5,000              |
 * | Payroll Standard | 15th + 30th       | None      | 5,000  | 5,000  | 10,000             |
 * | Payroll Standard | 15th + 30th       | Split     | 5,000  | 5,000  | 10,000             |
 * |                  |                   |           | (Note: Payroll Standard amount is per-run; Split only applies to Monthly Standard)
 */
final class AllowanceCalculationResult
{
    public function __construct(
        /** Amount to include in the current payroll run (0 when not scheduled for this run). */
        public readonly float $payrollAmount,
        /** Sum of all payroll amounts across the month — used for Employee Compensation display and projections. */
        public readonly float $monthlyEquivalent,
        /** Same as payrollAmount — alias for Gross Pay computation clarity. */
        public readonly float $grossPayAmount,
        /** Taxable portion of payrollAmount. */
        public readonly float $taxableAmount,
        /** Non-taxable portion of payrollAmount. */
        public readonly float $nonTaxableAmount,
        /** Number of payroll runs this component is paid in per month (1 or 2). */
        public readonly int $payrollCount,
        /** The resolved schedule type used for this computation. */
        public readonly string $resolvedSchedule,
        /** The resolved calculation standard used. */
        public readonly string $calculationStandard,
        /** Whether this component is scheduled for the current payroll run. */
        public readonly bool $isScheduledThisRun,
    ) {}

    public function toArray(): array
    {
        return [
            'payroll_amount'      => $this->payrollAmount,
            'monthly_equivalent'  => $this->monthlyEquivalent,
            'gross_pay_amount'    => $this->grossPayAmount,
            'taxable_amount'      => $this->taxableAmount,
            'non_taxable_amount'  => $this->nonTaxableAmount,
            'payroll_count'       => $this->payrollCount,
            'resolved_schedule'   => $this->resolvedSchedule,
            'calculation_standard' => $this->calculationStandard,
            'is_scheduled_this_run' => $this->isScheduledThisRun,
        ];
    }
}

final class AllowanceCalculationService
{
    public const PRORATION_NONE = 'none';

    public const PRORATION_SPLIT = 'split';

    private const PRORATION_METHODS = [
        self::PRORATION_NONE,
        self::PRORATION_SPLIT,
    ];
    /**
     * Compute all allowance amounts from a single set of inputs.
     *
     * @param  float   $amount              Configured allowance amount.
     * @param  string  $frequency           PayComponent::STANDARD_MONTHLY or STANDARD_PAYROLL.
     * @param  string  $selectedPayrolls    DeductionScheduleSetting::SCHEDULE_15TH, SCHEDULE_30TH, or SCHEDULE_BOTH.
     * @param  string  $prorationMethod     'none' or 'split'.
     * @param  string  $currentPayroll      DeductionScheduleSetting::SCHEDULE_15TH or SCHEDULE_30TH (the current run).
     * @param  bool    $isTaxable           Whether the allowance is taxable.
     */
    public function compute(
        float $amount,
        string $frequency,
        string $selectedPayrolls,
        string $prorationMethod,
        string $currentPayroll,
        bool $isTaxable = true,
    ): AllowanceCalculationResult {
        $amount = round(max(0.0, $amount), 2);
        $frequency = $this->normalizeFrequency($frequency);
        $selectedPayrolls = $this->normalizeSchedule($selectedPayrolls);
        $prorationMethod = strtolower(trim($prorationMethod));
        $currentPayroll = $this->normalizeCurrentRun($currentPayroll);

        $payrollCount = $this->countSelectedPayrolls($selectedPayrolls);

        // Split only applies to Monthly Standard (configured amount is a monthly total).
        // For Payroll Standard, the configured amount is already per-run — never split.
        $isSplit = $frequency === PayComponent::STANDARD_MONTHLY
            && $prorationMethod === 'split'
            && $selectedPayrolls === DeductionScheduleSetting::SCHEDULE_BOTH;

        // Determine the per-run amount for each selected payroll.
        $perRunAmount = $isSplit ? round($amount / 2, 2) : $amount;

        // Is this component scheduled for the current run?
        $isScheduledThisRun = $this->isScheduledForRun($selectedPayrolls, $currentPayroll);

        // Payroll amount = what goes into the current run.
        $payrollAmount = $isScheduledThisRun ? $perRunAmount : 0.0;

        // Monthly equivalent = total paid across all runs in a month.
        // Split: perRunAmount × 2 = original amount.
        // None + both: perRunAmount × 2 = amount × 2 (paid full on each run).
        // None + single: perRunAmount × 1 = amount.
        $monthlyEquivalent = round($perRunAmount * $payrollCount, 2);

        $grossPayAmount = $payrollAmount;
        $taxableAmount = $isTaxable ? $payrollAmount : 0.0;
        $nonTaxableAmount = $isTaxable ? 0.0 : $payrollAmount;

        return new AllowanceCalculationResult(
            payrollAmount: $payrollAmount,
            monthlyEquivalent: $monthlyEquivalent,
            grossPayAmount: $grossPayAmount,
            taxableAmount: $taxableAmount,
            nonTaxableAmount: $nonTaxableAmount,
            payrollCount: $payrollCount,
            resolvedSchedule: $selectedPayrolls,
            calculationStandard: $frequency,
            isScheduledThisRun: $isScheduledThisRun,
        );
    }

    /**
     * Compute monthly equivalent only (for Employee Compensation display, no current-run context needed).
     */
    public function monthlyEquivalent(
        float $amount,
        string $frequency,
        string $selectedPayrolls,
        string $prorationMethod,
    ): float {
        $result = $this->compute(
            amount: $amount,
            frequency: $frequency,
            selectedPayrolls: $selectedPayrolls,
            prorationMethod: $prorationMethod,
            currentPayroll: DeductionScheduleSetting::SCHEDULE_15TH, // arbitrary; not used for monthly equivalent
        );

        return $result->monthlyEquivalent;
    }

    /**
     * Compute the payslip display amounts for both the 15th and 30th runs.
     *
     * @return array{15th: float, 30th: float, monthly_equivalent: float}
     */
    public function payslipAmounts(
        float $amount,
        string $frequency,
        string $selectedPayrolls,
        string $prorationMethod,
        bool $isTaxable = true,
    ): array {
        $first = $this->compute($amount, $frequency, $selectedPayrolls, $prorationMethod, DeductionScheduleSetting::SCHEDULE_15TH, $isTaxable);
        $second = $this->compute($amount, $frequency, $selectedPayrolls, $prorationMethod, DeductionScheduleSetting::SCHEDULE_30TH, $isTaxable);

        return [
            '15th'               => $first->payrollAmount,
            '30th'               => $second->payrollAmount,
            'monthly_equivalent' => $first->monthlyEquivalent, // same regardless of which run we ask
        ];
    }

    // -------------------------------------------------------------------------
    // Proration method resolver
    // -------------------------------------------------------------------------

    /**
     * Resolve the allowance proration method (none vs split) from a component's
     * metadata and schedule override.
     *
     * Priority:
     * 1. Explicit `allowance_schedule_proration` in metadata (none|split).
     * 2. `schedule_override` slug — 'split' → split.
     * 3. Resolved schedule — 'both' defaults to none (full on each run),
     *    single-run schedules always return none.
     *
     * @param  array<string, mixed>  $component  Component line with metadata, schedule_override, resolved_schedule etc.
     */
    public static function resolveProrationMethod(array $component): string
    {
        $meta = is_array($component['metadata'] ?? null) ? $component['metadata'] : [];
        $explicit = strtolower(trim((string) ($meta['allowance_schedule_proration'] ?? '')));
        if (in_array($explicit, self::PRORATION_METHODS, true)) {
            return $explicit;
        }

        $override = $component['schedule_override'] ?? null;
        if (is_string($override) && strtolower(trim($override)) === 'split') {
            return self::PRORATION_SPLIT;
        }

        $resolved = $component['resolved_schedule'] ?? $component['pay_schedule_type'] ?? '';
        if (in_array($resolved, [DeductionScheduleSetting::SCHEDULE_15TH, DeductionScheduleSetting::SCHEDULE_30TH], true)) {
            return self::PRORATION_NONE;
        }

        return self::PRORATION_NONE;
    }

    /**
     * Shortcut to resolve proration method from a raw metadata array alone
     * (useful when the full component line is not available).
     */
    public static function resolveProrationMethodFromMetadata(?array $metadata, ?string $scheduleOverride = null, ?string $resolvedSchedule = null): string
    {
        return self::resolveProrationMethod([
            'metadata' => $metadata ?? [],
            'schedule_override' => $scheduleOverride,
            'resolved_schedule' => $resolvedSchedule,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalizeFrequency(string $frequency): string
    {
        return in_array($frequency, PayComponent::CALCULATION_STANDARDS, true)
            ? $frequency
            : PayComponent::STANDARD_MONTHLY;
    }

    private function normalizeSchedule(string $schedule): string
    {
        return in_array($schedule, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH,
        ], true) ? $schedule : DeductionScheduleSetting::SCHEDULE_BOTH;
    }

    private function normalizeCurrentRun(string $run): string
    {
        return in_array($run, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
        ], true) ? $run : DeductionScheduleSetting::SCHEDULE_15TH;
    }

    private function countSelectedPayrolls(string $selectedPayrolls): int
    {
        return $selectedPayrolls === DeductionScheduleSetting::SCHEDULE_BOTH ? 2 : 1;
    }

    private function isScheduledForRun(string $selectedPayrolls, string $currentPayroll): bool
    {
        return match ($selectedPayrolls) {
            DeductionScheduleSetting::SCHEDULE_BOTH  => true,
            DeductionScheduleSetting::SCHEDULE_15TH  => $currentPayroll === DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH  => $currentPayroll === DeductionScheduleSetting::SCHEDULE_30TH,
            default                                  => true,
        };
    }
}
