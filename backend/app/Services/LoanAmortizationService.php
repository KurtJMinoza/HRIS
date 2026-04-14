<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DeductionScheduleSetting;
use App\Models\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\LoanAmortization;
use App\Models\LoanRequest;
use App\Models\PayCycle;
use App\Models\PayrollPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: amortization schedule + balance tracking integrated with Pay Cycle dates and payroll runs.
 *
 * Integration points:
 * - {@see LoanRequestService::approveFinal} calls {@see self::generateSchedule} after creating {@see EmployeeDeduction}.
 * - {@see DeductionApplicationService::applyLoanBalancesAfterSavedPayroll} calls {@see self::applyPaymentFromPayrollRun} when the saved
 *   {@see PayrollPeriod}'s cut-off matches the next pending installment ({@see self::firstPendingInstallmentMatchesPayrollCutOff}).
 * - Deduction schedule keys (`pay_component:{id}`) continue to drive semi-monthly proration via {@see DeductionScheduleService}.
 *
 * Proration note: {@see DeductionScheduleService::prorationFactorForMonthlyAmount} uses calendar 1–15 vs 16–end, which does not
 * match pay-cycle segments (e.g. cut-off 10/25). Semi-monthly installments therefore use `semi_month_segment` on each
 * generated pay period instead of cut-off end day alone.
 */
class LoanAmortizationService
{
    public function __construct(
        private readonly PayCycleService $payCycleService,
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly PayrollCalculatorService $payrollCalculator,
        private readonly DeductionAuditService $deductionAuditService,
    ) {}

    /**
     * Employee pay cycle: user assignment → branch/company default → any active default cycle for company.
     */
    public function resolvePayCycleForEmployee(User $user): ?PayCycle
    {
        $cycle = $this->payCycleService->resolveForUser($user);
        if ($cycle) {
            return $cycle;
        }

        $companyId = $user->company_id ? (int) $user->company_id : null;
        if ($companyId) {
            $company = Company::query()->with('defaultPayCycle')->find($companyId);
            if ($company?->default_pay_cycle_id) {
                $resolved = $company->defaultPayCycle ?: PayCycle::query()->find((int) $company->default_pay_cycle_id);
                if ($resolved) {
                    return $resolved;
                }
            }

            $q = PayCycle::query()
                ->where(function ($b) use ($companyId) {
                    $b->where('company_id', $companyId)
                        ->orWhereHas('companies', fn ($c) => $c->where('companies.id', $companyId));
                })
                ->where('is_active', true)
                ->orderBy('id');
            if ($this->payCycleService->supportsDefaultFlag()) {
                $q->where('is_default', true);
            }

            return $q->first();
        }

        return null;
    }

    /**
     * Fraction of the HR "monthly" installment applied on this pay run (matches pay cycle cadence + deduction schedule).
     */
    public function loanInstallmentProrationFactor(PayCycle $cycle, string $sched, array $period): float
    {
        if ($cycle->code === PayCycle::CODE_SEMI_MONTHLY) {
            $segment = $period['semi_month_segment'] ?? null;
            if ($segment === 'first' || $segment === 'second') {
                return match ($sched) {
                    DeductionScheduleSetting::SCHEDULE_BOTH => 0.5,
                    DeductionScheduleSetting::SCHEDULE_15TH => $segment === 'first' ? 1.0 : 0.0,
                    DeductionScheduleSetting::SCHEDULE_30TH => $segment === 'second' ? 1.0 : 0.0,
                    default => 0.5,
                };
            }

            $anchor = $period['pay_date'] ?? $period['end'] ?? null;
            $ref = $anchor instanceof Carbon
                ? $anchor->copy()
                : Carbon::parse((string) $anchor, $this->deductionScheduleService->timezone())->startOfDay();
            $cutOffType = ((int) $ref->day <= 15) ? 'first' : 'second';
            if (! $this->payCycleService->shouldApplyOnThisCutOff($sched, $cutOffType)) {
                return 0.0;
            }

            return $sched === DeductionScheduleSetting::SCHEDULE_BOTH ? 0.5 : 1.0;
        }

        if (in_array($cycle->code, [PayCycle::CODE_MONTHLY, PayCycle::CODE_PROJECT], true)) {
            return 1.0;
        }

        // Approximate splits when pay cadence is not semi-monthly (product may refine later).
        if ($cycle->code === PayCycle::CODE_WEEKLY) {
            return match ($sched) {
                DeductionScheduleSetting::SCHEDULE_BOTH => 0.25,
                DeductionScheduleSetting::SCHEDULE_15TH, DeductionScheduleSetting::SCHEDULE_30TH => 0.5,
                default => 0.25,
            };
        }

        if ($cycle->code === PayCycle::CODE_BI_WEEKLY) {
            return match ($sched) {
                DeductionScheduleSetting::SCHEDULE_BOTH => 0.5,
                DeductionScheduleSetting::SCHEDULE_15TH, DeductionScheduleSetting::SCHEDULE_30TH => 1.0,
                default => 0.5,
            };
        }

        return 1.0;
    }

    /**
     * Build installment rows from the employee's pay cycle (cut-off dates) until principal is repaid.
     */
    public function generateSchedule(EmployeeDeduction $ed, LoanRequest $loan): void
    {
        if (! Schema::hasTable('pay_loan_amortizations')) {
            return;
        }

        $user = User::query()->find($ed->user_id);
        if (! $user) {
            return;
        }

        $cycle = $this->resolvePayCycleForEmployee($user);
        if (! $cycle) {
            return;
        }

        LoanAmortization::query()->where('employee_deduction_id', $ed->id)->delete();

        $principalTotal = round((float) $loan->requested_amount, 2);
        $monthlyPayment = round(max(0.01, (float) $ed->amount), 2);
        $termMonths = $loan->term_months ? max(1, (int) $loan->term_months) : (int) max(1, ceil($principalTotal / $monthlyPayment));

        $maxPeriods = max(24, $termMonths * 12);
        $start = Carbon::parse($ed->start_date->toDateString(), $this->deductionScheduleService->timezone())->startOfDay();
        $periods = $this->payrollCalculator->generatePayPeriods($cycle, $start, $maxPeriods);

        $companyId = $user->getEffectiveCompanyId();
        $schedKey = $ed->pay_component_id ? 'pay_component:'.((int) $ed->pay_component_id) : null;
        // Prefer schedule from the approved loan request / employee assignment (per-employee), then HR catalog defaults.
        $sched = $this->normalizeScheduleType($loan->deduction_schedule ?? null)
            ?? $this->normalizeScheduleType($ed->deduction_schedule ?? null);
        if ($sched === null) {
            $sched = $schedKey
                ? $this->deductionScheduleService->resolveScheduleType($schedKey, $companyId)
                : DeductionScheduleSetting::SCHEDULE_BOTH;
        }

        $balance = $principalTotal;
        $annualRate = round(max(0.0, (float) ($ed->interest_rate_annual ?? $loan->interest_rate_percent ?? 0)), 4);
        $interestType = in_array((string) ($ed->interest_type ?? $loan->interest_type ?? ''), ['simple', 'compound'], true)
            ? (string) ($ed->interest_type ?? $loan->interest_type)
            : 'simple';
        $n = 0;

        foreach ($periods as $period) {
            if ($balance <= 0.009) {
                break;
            }

            $ref = $period['end'] instanceof Carbon
                ? $period['end']->copy()
                : Carbon::parse((string) $period['end'], $this->deductionScheduleService->timezone())->startOfDay();

            $factor = $this->loanInstallmentProrationFactor($cycle, $sched, $period);
            if ($factor <= 0) {
                continue;
            }

            $interestThis = $this->interestForInstallment(
                $annualRate,
                $interestType,
                $principalTotal,
                $balance,
                $factor
            );

            $cap = round($monthlyPayment * $factor, 2);
            $principalThis = round(min($cap - $interestThis, $balance), 2);
            if ($principalThis < 0) {
                $principalThis = 0;
            }
            $interestThis = round(min($interestThis, max(0.0, $cap - $principalThis)), 2);
            $total = round($principalThis + $interestThis, 2);

            if ($total <= 0) {
                continue;
            }

            $n++;
            $payDate = $period['pay_date'] ?? null;
            $payDateStr = $payDate instanceof Carbon
                ? $payDate->toDateString()
                : ($payDate ? Carbon::parse((string) $payDate)->toDateString() : $ref->toDateString());

            $row = [
                'employee_deduction_id' => $ed->id,
                'loan_request_id' => $loan->id,
                'installment_number' => $n,
                'due_date' => $ref->toDateString(),
                'period_label' => $period['cycle_label'] ?? $period['preview_line'] ?? null,
                'principal' => $principalThis,
                'interest' => $interestThis,
                'total_installment' => $total,
                'status' => LoanAmortization::STATUS_PENDING,
            ];
            if (Schema::hasColumn('pay_loan_amortizations', 'pay_date')) {
                $row['pay_date'] = $payDateStr;
            }
            LoanAmortization::query()->create($row);

            $balance = round($balance - $principalThis, 2);
        }

        $ed->refresh();
        $totals = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->selectRaw('COALESCE(SUM(total_installment), 0) as total_repayment, COALESCE(SUM(interest), 0) as total_interest')
            ->first();
        $totalRepayment = round((float) ($totals?->total_repayment ?? 0), 2);
        $totalInterest = round((float) ($totals?->total_interest ?? 0), 2);

        if (Schema::hasColumn('pay_employee_deductions', 'total_repayment_amount')) {
            $ed->total_repayment_amount = $totalRepayment;
        }
        if (Schema::hasColumn('pay_employee_deductions', 'with_interest')) {
            $ed->with_interest = $annualRate > 0;
        }
        if (Schema::hasColumn('pay_employee_deductions', 'interest_type')) {
            $ed->interest_type = $annualRate > 0 ? $interestType : null;
        }
        $next = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->where('status', LoanAmortization::STATUS_PENDING)
            ->orderBy('installment_number')
            ->first();

        $ed->next_due_date = $this->nextDueCarbonFromInstallment($next);
        $ed->save();

        if ($loan->exists) {
            if (Schema::hasColumn('pay_loan_requests', 'total_repayment_amount')) {
                $loan->total_repayment_amount = $totalRepayment;
            }
            if (Schema::hasColumn('pay_loan_requests', 'with_interest')) {
                $loan->with_interest = $annualRate > 0;
            }
            if (Schema::hasColumn('pay_loan_requests', 'interest_rate_percent')) {
                $loan->interest_rate_percent = $annualRate > 0 ? $annualRate : null;
            }
            if (Schema::hasColumn('pay_loan_requests', 'interest_type')) {
                $loan->interest_type = $annualRate > 0 ? $interestType : null;
            }
            $loan->save();
        }
    }

    /**
     * Phase 2 canonical API: generate schedule from approved assignment.
     * If caller does not pass a loan request model, we resolve it from the assignment link.
     */
    public function generateAmortizationSchedule(EmployeeDeduction $employeeDeduction): void
    {
        $loan = $employeeDeduction->loanRequest ?: LoanRequest::query()->find($employeeDeduction->loan_request_id);
        if (! $loan) {
            return;
        }
        $this->generateSchedule($employeeDeduction, $loan);
    }

    /**
     * Called after a saved payroll run; marks the next due installment and reduces principal balance.
     *
     * @param  float  $appliedAmount  Amount deducted this run (already prorated), must match {@see DeductionApplicationService}.
     */
    /**
     * True when the employee's next pending installment row is tied to this payroll run's cut-off end.
     * Prevents marking an installment paid on the wrong pay run (e.g. semi-monthly segment mismatch).
     */
    public function firstPendingInstallmentMatchesPayrollCutOff(EmployeeDeduction $ed, PayrollPeriod $period): bool
    {
        if (! Schema::hasTable('pay_loan_amortizations')) {
            return false;
        }

        $cutOffEnd = $period->cut_off_end_date?->toDateString();
        $periodPayDate = $period->pay_date?->toDateString();
        if ($cutOffEnd === null && $periodPayDate === null) {
            return false;
        }

        $firstPending = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->where('status', LoanAmortization::STATUS_PENDING)
            ->orderBy('installment_number')
            ->first();

        if (! $firstPending) {
            return false;
        }

        // Primary match: installment pay date to payroll period pay date.
        if ($periodPayDate !== null && Schema::hasColumn('pay_loan_amortizations', 'pay_date') && $firstPending->pay_date) {
            $installmentPayDate = $firstPending->pay_date instanceof Carbon
                ? $firstPending->pay_date->toDateString()
                : Carbon::parse((string) $firstPending->pay_date)->toDateString();
            if ($installmentPayDate === $periodPayDate) {
                return true;
            }
        }

        if ($cutOffEnd === null || ! $firstPending->due_date) {
            return false;
        }

        $dueStr = $firstPending->due_date instanceof Carbon
            ? $firstPending->due_date->toDateString()
            : Carbon::parse((string) $firstPending->due_date)->toDateString();

        return $dueStr === $cutOffEnd;
    }

    public function applyPaymentFromPayrollRun(
        EmployeeDeduction $ed,
        float $appliedAmount,
        Carbon $referenceDate,
        ?int $payrollPeriodId = null
    ): void {
        if (! $ed->is_amortized || $appliedAmount <= 0 || $ed->remaining_balance === null) {
            return;
        }

        $row = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->where('status', LoanAmortization::STATUS_PENDING)
            ->orderBy('installment_number')
            ->first();

        if (! $row) {
            return;
        }

        $appliedAmount = round($appliedAmount, 2);
        $expected = round((float) $row->total_installment, 2);
        if (abs($appliedAmount - $expected) > 0.05 && $appliedAmount < $expected - 0.01) {
            // Partial payments not modeled in Phase 2 — apply proportional principal reduction.
            $ratio = min(1.0, max(0.0, $appliedAmount / max(0.01, $expected)));
            $principalPart = round((float) $row->principal * $ratio, 2);
        } else {
            $principalPart = round((float) $row->principal, 2);
        }

        $row->status = LoanAmortization::STATUS_PAID;
        $row->paid_at = now();
        $row->payroll_period_id = $payrollPeriodId;
        $row->save();

        $newBal = round(max(0.0, (float) $ed->remaining_balance - $principalPart), 2);
        $oldBal = (float) $ed->remaining_balance;
        $ed->remaining_balance = $newBal;

        $next = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->where('status', LoanAmortization::STATUS_PENDING)
            ->orderBy('installment_number')
            ->first();

        $ed->next_due_date = $this->nextDueCarbonFromInstallment($next);
        if ($newBal <= 0) {
            $ed->is_active = false;
            $ed->next_due_date = null;
        }
        $ed->save();

        $this->deductionAuditService->log(
            $ed,
            'loan_installment_paid',
            null,
            $appliedAmount,
            $newBal,
            [
                'remaining_balance' => round($oldBal, 2),
                'installment_number' => $row->installment_number,
            ],
            [
                'remaining_balance' => round($newBal, 2),
                'installment_number' => $row->installment_number,
                'status' => LoanAmortization::STATUS_PAID,
            ],
            'Installment applied from saved payroll run.',
            [
                'payroll_period_id' => $payrollPeriodId,
                'reference_date' => $referenceDate->toDateString(),
            ]
        );
    }

    /**
     * Phase 2 canonical API: calculate this employee's amortized installment(s) for one payroll period.
     *
     * @return list<array{employee_deduction_id:int, amount:float, installment_number:int|null}>
     */
    public function calculateInstallmentForPeriod(User $employee, PayrollPeriod $payPeriod): array
    {
        $rows = EmployeeDeduction::query()
            ->where('user_id', $employee->id)
            ->where('is_active', true)
            ->where('is_amortized', true)
            ->with(['deductionType', 'amortizationSchedule'])
            ->get();

        $out = [];
        foreach ($rows as $ed) {
            if (! $this->firstPendingInstallmentMatchesPayrollCutOff($ed, $payPeriod)) {
                continue;
            }
            $pending = $ed->amortizationSchedule
                ->whereIn('status', [LoanAmortization::STATUS_PENDING, LoanAmortization::STATUS_OVERDUE])
                ->sortBy('installment_number')
                ->first();
            if (! $pending) {
                continue;
            }
            $out[] = [
                'employee_deduction_id' => (int) $ed->id,
                'amount' => round((float) $pending->total_installment, 2),
                'installment_number' => $pending->installment_number ? (int) $pending->installment_number : null,
            ];
        }

        return $out;
    }

    /**
     * Phase 2 canonical API: apply one amortized deduction to balance + schedule row.
     */
    public function applyLoanDeduction(EmployeeDeduction $employeeDeduction, float $amount, PayrollPeriod $payPeriod): void
    {
        $this->applyPaymentFromPayrollRun(
            $employeeDeduction,
            $amount,
            $payPeriod->reference_date
                ? Carbon::parse($payPeriod->reference_date->toDateString())
                : Carbon::parse($payPeriod->cut_off_end_date->toDateString()),
            (int) $payPeriod->id
        );
    }

    /**
     * Next withholding date shown to HR/employee: pay day from pay cycle, not cut-off end.
     */
    private function nextDueCarbonFromInstallment(?LoanAmortization $row): ?Carbon
    {
        if (! $row) {
            return null;
        }
        if (Schema::hasColumn('pay_loan_amortizations', 'pay_date') && $row->pay_date) {
            return $row->pay_date instanceof Carbon ? $row->pay_date->copy() : Carbon::parse((string) $row->pay_date)->startOfDay();
        }

        return $row->due_date instanceof Carbon ? $row->due_date->copy() : Carbon::parse((string) $row->due_date)->startOfDay();
    }

    private function interestForInstallment(
        float $annualRatePercent,
        string $interestType,
        float $principalTotal,
        float $remainingBalance,
        float $factor
    ): float {
        if ($annualRatePercent <= 0 || $factor <= 0) {
            return 0.0;
        }
        $monthlyRate = $annualRatePercent / 100.0 / 12.0;
        $base = $interestType === 'compound' ? $remainingBalance : $principalTotal;
        if ($base <= 0 || $monthlyRate <= 0) {
            return 0.0;
        }

        return round($base * $monthlyRate * $factor, 2);
    }

    /**
     * Mutates the model for JSON only: aligns `next_due_date` with the pending amortization pay date (or next pay day for legacy loans).
     */
    public function overlayNextDueForResponse(EmployeeDeduction $ed, ?User $user = null): void
    {
        $ed->loadMissing(['amortizationSchedule', 'deductionType']);
        $this->hydrateOverdueStatusesForResponse($ed);

        if ($ed->is_amortized && Schema::hasTable('pay_loan_amortizations')) {
            $pending = $ed->amortizationSchedule
                ->where('status', LoanAmortization::STATUS_PENDING)
                ->sortBy('installment_number')
                ->first();
            if ($pending) {
                $d = $pending->pay_date ?? $pending->due_date;
                if ($d) {
                    $ed->setAttribute('next_due_date', Carbon::parse((string) $d)->toDateString());

                    return;
                }
            }
        }

        $type = $ed->deductionType;
        $actor = $user ?? User::query()->find($ed->user_id);
        if (
            $actor
            && $type
            && $type->type === DeductionType::TYPE_LOAN
            && $ed->remaining_balance !== null
            && ! $ed->is_amortized
        ) {
            $preview = $this->payCycleService->previewForUser($actor);
            if (! empty($preview['pay_date'])) {
                $ed->setAttribute('next_due_date', Carbon::parse((string) $preview['pay_date'])->toDateString());
            }
        }
    }

    /**
     * HR: close loan early — zero balance and skip pending installments.
     */
    public function earlyPayoff(EmployeeDeduction $ed, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($ed, $actorUserId) {
            $oldBal = (float) $ed->remaining_balance;
            LoanAmortization::query()
                ->where('employee_deduction_id', $ed->id)
                ->where('status', LoanAmortization::STATUS_PENDING)
                ->update(['status' => LoanAmortization::STATUS_SKIPPED]);

            $ed->remaining_balance = 0;
            $ed->is_active = false;
            $ed->next_due_date = null;
            $ed->save();
            $this->deductionAuditService->log(
                $ed,
                'loan_early_payoff',
                $actorUserId,
                $oldBal,
                0,
                ['remaining_balance' => round($oldBal, 2)],
                ['remaining_balance' => 0.0, 'is_active' => false],
                'Loan closed early by HR action.'
            );
        });
    }

    /**
     * HR: manual balance correction (e.g. write-off). Regenerating schedule is Phase 3+; here we only adjust balance.
     */
    public function adjustRemainingBalance(EmployeeDeduction $ed, float $newBalance, ?int $actorUserId = null): void
    {
        $oldBal = (float) $ed->remaining_balance;
        $ed->remaining_balance = round(max(0.0, $newBalance), 2);
        if ($ed->remaining_balance <= 0) {
            $ed->is_active = false;
            $ed->next_due_date = null;
        }
        $ed->save();
        $this->deductionAuditService->log(
            $ed,
            'loan_balance_adjusted',
            $actorUserId,
            null,
            (float) $ed->remaining_balance,
            ['remaining_balance' => round($oldBal, 2)],
            ['remaining_balance' => round((float) $ed->remaining_balance, 2)],
            'Manual remaining balance adjustment.'
        );
    }

    /**
     * Mark pending installments as overdue in-memory for response payloads without mutating DB state.
     */
    public function hydrateOverdueStatusesForResponse(EmployeeDeduction $ed): void
    {
        if (! $ed->relationLoaded('amortizationSchedule')) {
            return;
        }
        $today = now()->startOfDay();
        foreach ($ed->amortizationSchedule as $row) {
            if (! $row->due_date) {
                continue;
            }
            $status = strtolower((string) ($row->status ?? ''));
            $due = $row->due_date instanceof Carbon ? $row->due_date->copy()->startOfDay() : Carbon::parse((string) $row->due_date)->startOfDay();
            if ($status === LoanAmortization::STATUS_PAID) {
                $row->setAttribute('status', LoanAmortization::STATUS_PAID);

                continue;
            }
            if (in_array($status, [LoanAmortization::STATUS_SKIPPED], true)) {
                $row->setAttribute('status', LoanAmortization::STATUS_SKIPPED);

                continue;
            }
            if ($due->lt($today)) {
                $row->setAttribute('status', LoanAmortization::STATUS_OVERDUE);
            } else {
                $row->setAttribute('status', LoanAmortization::STATUS_PENDING);
            }
        }
    }

    /**
     * Outstanding loan principal for final-pay / clearance checks (call from payroll or HR).
     *
     * @return array{total_outstanding: float, lines: list<array{id: int, name: string, balance: float}>}
     */
    public function outstandingLoanSummary(User $user): array
    {
        $lines = [];
        $total = 0.0;

        $rows = EmployeeDeduction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('deductionType')
            ->get();

        foreach ($rows as $ed) {
            $type = $ed->deductionType;
            if (! $type || $type->type !== DeductionType::TYPE_LOAN || $ed->remaining_balance === null) {
                continue;
            }
            $bal = (float) $ed->remaining_balance;
            if ($bal <= 0) {
                continue;
            }
            $total += $bal;
            $lines[] = [
                'employee_deduction_id' => $ed->id,
                'name' => $type->name,
                'balance' => round($bal, 2),
            ];
        }

        return [
            'total_outstanding' => round($total, 2),
            'lines' => $lines,
        ];
    }

    private function normalizeScheduleType(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $raw = strtolower(trim($value));
        if ($raw === '') {
            return null;
        }

        return match ($raw) {
            DeductionScheduleSetting::SCHEDULE_15TH, '15', 'first', 'first_half', 'first-half' => DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH, '30', 'second', 'second_half', 'second-half', 'end_of_month', 'eom' => DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH, '50/50', 'half', 'split', 'semi-monthly', 'semi_monthly' => DeductionScheduleSetting::SCHEDULE_BOTH,
            default => null,
        };
    }
}
