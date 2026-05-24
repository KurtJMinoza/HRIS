<?php

namespace App\Services;

use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Approved overtime inclusion for payroll: eligibility, payable hours, per-request rates, paid tracking.
 */
class OvertimePayrollService
{
    public function __construct(
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly PolicyResolverService $policyResolver,
    ) {}

    public function payableBasis(): string
    {
        $basis = strtolower(trim((string) config('payroll.ot_payable_basis', 'approved')));

        return in_array($basis, ['approved', 'rendered', 'min'], true) ? $basis : 'approved';
    }

    /**
     * @param  Builder<Overtime>  $query
     * @return Builder<Overtime>
     */
    public function applyPayrollEligibleScope(Builder $query, ?int $payrollBatchRunId, ?int $companyId = null): Builder
    {
        $query->where('status', Overtime::STATUS_APPROVED);

        if (Schema::hasColumn('overtimes', 'voided_at')) {
            $query->whereNull('voided_at');
        }

        if (Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
            $query->where(function (Builder $q) use ($payrollBatchRunId): void {
                $q->whereNull('paid_payroll_run_id');
                if ($payrollBatchRunId !== null && $payrollBatchRunId > 0) {
                    $q->orWhere('paid_payroll_run_id', $payrollBatchRunId);
                }
            });
        }

        if ($companyId !== null && $companyId > 0 && Schema::hasColumn('overtimes', 'company_id')) {
            $query->where(function (Builder $q) use ($companyId): void {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        return $query;
    }

    public function resolvePayableOtMinutes(int $renderedOtMinutes, int $approvedOtMinutes): int
    {
        $renderedOtMinutes = max(0, $renderedOtMinutes);
        $approvedOtMinutes = max(0, $approvedOtMinutes);

        return match ($this->payableBasis()) {
            'rendered' => $renderedOtMinutes,
            'min' => min($renderedOtMinutes, $approvedOtMinutes),
            default => $approvedOtMinutes,
        };
    }

    /**
     * @param  list<Overtime>  $records
     * @return array{
     *   approved_hours: float,
     *   payable_hours: float,
     *   ot_pay: float,
     *   items: list<array<string, mixed>>,
     *   overtime_ids: list<int>
     * }
     */
    public function computeCompensationFromRecords(
        array $records,
        float $hourlyRate,
        ?object $policy,
        string $fallbackRuleCode,
        int $renderedOtMinutes = 0
    ): array {
        $approvedMinutes = 0;
        $otPay = 0.0;
        $items = [];
        $ids = [];

        foreach ($records as $ot) {
            if (! $ot instanceof Overtime) {
                continue;
            }
            $hours = max(0.0, (float) ($ot->computed_hours ?? 0));
            if ($hours <= 0.0001) {
                continue;
            }
            $approvedMinutes += (int) round($hours * 60);
            $ruleCode = is_string($ot->ph_ot_rule) && trim($ot->ph_ot_rule) !== ''
                ? strtoupper(trim($ot->ph_ot_rule))
                : strtoupper($fallbackRuleCode);
            $multipliers = $this->rulesEngine->getMultipliersForRule($ruleCode, $policy);
            $otMult = (float) ($multipliers['ot'] ?? 1.25);
            $linePay = round($hours * $hourlyRate * $otMult, 2);
            $otPay += $linePay;
            $ids[] = (int) $ot->id;
            $items[] = [
                'overtime_id' => (int) $ot->id,
                'date' => $ot->date?->toDateString(),
                'ot_type' => $ot->ot_type,
                'ph_ot_rule' => $ruleCode,
                'approved_hours' => round($hours, 2),
                'hourly_rate' => round($hourlyRate, 4),
                'multiplier' => round($otMult, 4),
                'amount' => $linePay,
            ];
        }

        $approvedMinutes = max(0, $approvedMinutes);
        $payableMinutes = $this->resolvePayableOtMinutes($renderedOtMinutes, $approvedMinutes);
        $payableHours = $payableMinutes / 60.0;
        $approvedHours = $approvedMinutes / 60.0;

        if ($this->payableBasis() !== 'approved' && $approvedMinutes > 0 && $payableMinutes < $approvedMinutes) {
            $scale = $payableMinutes / $approvedMinutes;
            $otPay = round($otPay * $scale, 2);
            foreach ($items as &$item) {
                $item['payable_hours'] = round(((float) $item['approved_hours']) * $scale, 2);
                $item['amount'] = round(((float) $item['amount']) * $scale, 2);
            }
            unset($item);
        } else {
            foreach ($items as &$item) {
                $item['payable_hours'] = $item['approved_hours'];
            }
            unset($item);
        }

        return [
            'approved_hours' => round($approvedHours, 2),
            'payable_hours' => round($payableHours, 2),
            'ot_pay' => round($otPay, 2),
            'items' => $items,
            'overtime_ids' => $ids,
        ];
    }

    /**
     * @return array{
     *   approved_hours: float,
     *   payable_hours: float,
     *   ot_pay: float,
     *   items: list<array<string, mixed>>,
     *   overtime_ids: list<int>
     * }
     */
    public function computeApprovedOvertimeForDate(
        User $user,
        string $dateKey,
        float $hourlyRate,
        ?object $policy,
        string $fallbackRuleCode,
        int $renderedOtMinutes,
        ?int $payrollBatchRunId,
        ?array $prefetchedRecords = null
    ): array {
        $records = $prefetchedRecords ?? $this->fetchApprovedForUserDate(
            (int) $user->id,
            $dateKey,
            $user->getEffectiveCompanyId(),
            $payrollBatchRunId
        );

        return $this->computeCompensationFromRecords(
            $records,
            $hourlyRate,
            $policy,
            $fallbackRuleCode,
            $renderedOtMinutes
        );
    }

    /**
     * @return list<Overtime>
     */
    public function fetchApprovedForUserDate(
        int $userId,
        string $dateKey,
        ?int $companyId,
        ?int $payrollBatchRunId
    ): array {
        if (! Schema::hasTable('overtimes')) {
            return [];
        }

        $query = Overtime::query()
            ->where('user_id', $userId)
            ->whereDate('date', $dateKey)
            ->orderBy('id');

        $this->applyPayrollEligibleScope($query, $payrollBatchRunId, $companyId);

        return $query->get()->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    public function markIncludedAsPaid(
        int $payrollBatchRunId,
        array $userIds,
        Carbon $from,
        Carbon $to,
        ?int $companyId = null
    ): int {
        if ($payrollBatchRunId <= 0 || $userIds === [] || ! Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
            return 0;
        }

        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        $query = Overtime::query()
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString());

        $this->applyPayrollEligibleScope($query, null, $companyId);

        $count = (clone $query)->count();
        if ($count === 0) {
            return 0;
        }

        $query->update([
            'paid_payroll_run_id' => $payrollBatchRunId,
            'paid_at' => now(),
        ]);

        Log::info('payroll_overtime_marked_paid', [
            'payroll_run_id' => $payrollBatchRunId,
            'employee_count' => count($ids),
            'payroll_period_start' => $from->toDateString(),
            'payroll_period_end' => $to->toDateString(),
            'overtime_rows_marked' => $count,
            'company_id' => $companyId,
        ]);

        return $count;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<string, list<Overtime>>
     */
    public function prefetchApprovedByUserDate(
        array $userIds,
        Carbon $from,
        Carbon $to,
        ?int $companyId,
        ?int $payrollBatchRunId
    ): array {
        $ids = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($ids === [] || ! Schema::hasTable('overtimes')) {
            return [];
        }

        $fromExt = $from->copy()->startOfDay()->subDay();
        $toExt = $to->copy()->startOfDay()->addDay();

        $query = Overtime::query()
            ->whereIn('user_id', $ids)
            ->whereDate('date', '>=', $fromExt->toDateString())
            ->whereDate('date', '<=', $toExt->toDateString())
            ->orderBy('id');

        $this->applyPayrollEligibleScope($query, $payrollBatchRunId, $companyId);

        $byKey = [];
        foreach ($query->get() as $ot) {
            $d = $ot->date instanceof Carbon
                ? $ot->date->toDateString()
                : Carbon::parse((string) $ot->date)->toDateString();
            $key = (int) $ot->user_id.'|'.$d;
            $byKey[$key][] = $ot;
        }

        return $byKey;
    }

    /**
     * @param  list<array<string, mixed>>  $periodItems
     */
    public function logPayrollOvertimeDebug(
        int $userId,
        ?int $payrollRunId,
        string $periodStart,
        string $periodEnd,
        array $periodItems,
        float $totalHours,
        float $totalAmount,
        array $skipped = []
    ): void {
        Log::info('payroll_overtime_computation', [
            'employee_id' => $userId,
            'payroll_run_id' => $payrollRunId,
            'payroll_period_start' => $periodStart,
            'payroll_period_end' => $periodEnd,
            'ot_payable_basis' => $this->payableBasis(),
            'approved_ot_records' => count($periodItems),
            'overtime_ids' => array_values(array_unique(array_map(
                static fn (array $row) => (int) ($row['overtime_id'] ?? 0),
                $periodItems
            ))),
            'ot_dates' => array_values(array_unique(array_filter(array_map(
                static fn (array $row) => $row['date'] ?? null,
                $periodItems
            )))),
            'items' => $periodItems,
            'total_ot_hours' => round($totalHours, 2),
            'total_ot_amount' => round($totalAmount, 2),
            'skipped' => $skipped,
        ]);
    }
}
