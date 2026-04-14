<?php

namespace App\Services;

use App\Models\EmployeeTaxInfo;
use App\Models\TaxCalculationLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * BIR withholding orchestration: tax profiles, year-end true-up, retro guidance, audit logging.
 * Core graduated tax math remains in {@see PayrollCalculatorService::computeTrainAnnualIncomeTax()}.
 */
class TaxComputationService
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator
    ) {}

    /**
     * Alias for HR-facing "earnings classification" (taxable vs non-taxable pay components).
     *
     * @param  array<int, array<string, mixed>>  $earnings
     * @return array<string, mixed>
     */
    public function classifyEarnings(array $earnings): array
    {
        return $this->calculator->classifyEarnings($earnings);
    }

    /**
     * Map persisted employee tax profile to API `tax_profile` shape for {@see PayrollCalculatorService::calculateWithholdingTax()}.
     *
     * @return array<string, mixed>
     */
    public function buildTaxProfileFromEmployee(User $user): array
    {
        if (! Schema::hasTable('employee_tax_info')) {
            return [];
        }

        $info = EmployeeTaxInfo::query()->where('user_id', $user->id)->first();
        if ($info === null) {
            return [];
        }

        return [
            'is_mwe' => (bool) ($info->is_mwe ?? false),
            'mwe_monthly_ceiling' => isset($info->mwe_monthly_ceiling) ? (float) $info->mwe_monthly_ceiling : null,
            'is_senior_citizen' => (bool) ($info->is_senior_citizen ?? false),
            'is_pwd' => (bool) ($info->is_pwd ?? false),
            'is_solo_parent' => (bool) ($info->is_solo_parent ?? false),
            'tax_regime' => (string) ($info->tax_regime ?? 'standard_train'),
            'additional_exemption_amount' => isset($info->additional_exemption_amount) ? (float) $info->additional_exemption_amount : null,
        ];
    }

    /**
     * Year-end balancing: compare full-year tax due (TRAIN) to cumulative withholding.
     *
     * @param  array{
     *   annual_taxable_income?: float,
     *   withholding_tax_ytd?: float,
     *   calendar_year?: int
     * }  $params
     * @return array<string, mixed>
     */
    public function calculateYearEndAdjustment(array $params): array
    {
        $annualTaxable = round(max(0.0, (float) ($params['annual_taxable_income'] ?? 0)), 2);
        $withheldYtd = round(max(0.0, (float) ($params['withholding_tax_ytd'] ?? 0)), 2);
        $year = (int) ($params['calendar_year'] ?? (int) date('Y'));

        $train = $this->calculator->computeTrainAnnualIncomeTax($annualTaxable);
        $annualDue = (float) $train['tax_due'];
        $diff = round($annualDue - $withheldYtd, 2);

        return [
            'calendar_year' => $year,
            'annual_taxable_income' => $annualTaxable,
            'annual_income_tax_due' => $annualDue,
            'withholding_tax_ytd' => $withheldYtd,
            'train_bracket' => $train,
            'additional_withholding_due' => $diff > 0 ? $diff : 0.0,
            'over_withheld_refund' => $diff < 0 ? abs($diff) : 0.0,
            'balanced' => abs($diff) < 0.01,
            'metadata' => [
                'law_reference' => 'NIRC Sec. 24(A) TRAIN; year-end reconciliation per employer practice / BIR Form 2316.',
            ],
        ];
    }

    /**
     * When tax tables, employment status, or salary changes mid-year, recompute prior periods using payroll history.
     * This implementation returns a structured plan — persist per-period WHT in payroll runs to get exact deltas.
     *
     * @return array<string, mixed>
     */
    public function recalculateRetroactiveTax(?int $employeeId, string $fromDate, string $toDate): array
    {
        return [
            'employee_id' => $employeeId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'status' => 'requires_ledger',
            'message' => 'Per-period taxable compensation and withheld tax must be loaded from posted payroll. '
                .'Use this engine to recompute each month with corrected `tax_profile`, then compare to stored withholding.',
            'suggested_steps' => [
                'Export payroll register: gross taxable, withholding, and pay dates for the range.',
                'For each month, call withholding preview with corrected earnings + tax profile.',
                'Post adjustment in final payroll or year-end true-up (see calculateYearEndAdjustment).',
            ],
        ];
    }

    /**
     * Optional audit trail for compliance (year-end, retro runs, regularization hooks).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $result
     */
    public function logCalculation(
        string $context,
        ?int $userId,
        ?int $companyId,
        array $input,
        array $result,
        ?string $ip,
        ?int $actorUserId
    ): void {
        if (! Schema::hasTable('tax_calculation_logs')) {
            return;
        }

        TaxCalculationLog::query()->create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'context' => $context,
            'input' => $input,
            'result' => $result,
            'ip_address' => $ip,
            'created_by_user_id' => $actorUserId,
        ]);
    }

    /**
     * Called after regularization / contract changes — does not recompute automatically; logs for HR follow-up.
     */
    public function flagTaxReviewAfterEmploymentChange(int $userId, string $reason): void
    {
        $this->logCalculation(
            'employment_change',
            $userId,
            null,
            ['reason' => $reason],
            ['action' => 'review_tax_profile_and_ytd_withholding'],
            null,
            null
        );
    }
}
