<?php

namespace App\Services;

use App\Models\DeductionScheduleSetting;
use App\Models\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\LoanAmortization;
use App\Models\PayComponent;
use App\Models\PayCycle;
use App\Models\PayrollPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Applies HR-configured employee deductions (loans/benefits) to payroll previews and persists loan balances after saved payroll runs.
 *
 * Loan display: {@see self::resolveNextDueForMetadata} prefers amortization `pay_date` (payroll withhold date) so Salary tab /
 * payslip stay aligned with Pay Cycle even if `pay_employee_deductions.next_due_date` is stale.
 *
 * Amortized loans: installments flip to paid only when {@see LoanAmortizationService::firstPendingInstallmentMatchesPayrollCutOff}
 * is true for the saved {@see PayrollPeriod} (cut-off end = installment `due_date`). Use {@see self::autoProcessDueInstallments}
 * to backfill if payroll existed but balances were not applied.
 */
class DeductionApplicationService
{
    public function __construct(
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly LoanAmortizationService $loanAmortizationService,
        private readonly PayCycleService $payCycleService,
        private readonly GarnishmentService $garnishmentService,
        private readonly DeductionAuditService $deductionAuditService,
    ) {}

    /**
     * Monthly full amount for an assignment (before semi-monthly proration in {@see DeductionScheduleService}).
     */
    public function monthlyFullAmount(EmployeeDeduction $ed): float
    {
        $type = $ed->relationLoaded('deductionType') ? $ed->deductionType : $ed->deductionType()->first();
        $isLoan = $type && $type->type === DeductionType::TYPE_LOAN;

        $base = max(0.0, (float) $ed->amount);
        if ($isLoan && $ed->remaining_balance !== null) {
            return min($base, max(0.0, (float) $ed->remaining_balance));
        }

        return $base;
    }

    /**
     * Build synthetic deduction lines merged into {@see PayrollCalculatorService::buildEmployeeCompensationSummary} deductions array.
     *
     * @return list<array<string, mixed>>
     */
    public function buildSyntheticDeductionLines(User $user, string $asOfDate): array
    {
        $asOf = Carbon::parse($asOfDate)->startOfDay();
        $rows = EmployeeDeduction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $asOf->toDateString())
            ->where(function ($q) use ($asOf) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $asOf->toDateString());
            })
            ->with(['deductionType', 'payComponent', 'amortizationSchedule', 'loanRequest'])
            ->orderBy('id')
            ->get();

        $out = [];
        $payCyclePreviewCache = null;
        foreach ($rows as $ed) {
            $type = $ed->deductionType;
            if (! $type || ! $type->is_active) {
                continue;
            }
            $this->loanAmortizationService->hydrateOverdueStatusesForResponse($ed);
            $isLoan = $type->type === DeductionType::TYPE_LOAN;
            $pendingInstallment = null;
            if ($isLoan && $ed->is_amortized && $ed->relationLoaded('amortizationSchedule')) {
                $pendingInstallment = $ed->amortizationSchedule
                    ->whereIn('status', [LoanAmortization::STATUS_PENDING, LoanAmortization::STATUS_OVERDUE])
                    ->sortBy('installment_number')
                    ->first();
            }

            $monthly = $this->monthlyFullAmount($ed);
            if ($pendingInstallment) {
                $monthly = round((float) $pendingInstallment->total_installment, 2);
            }
            if ($monthly <= 0) {
                continue;
            }

            $name = $type->name;
            $displayName = $name;
            $isLegallyAllowed = $ed->is_legally_allowed === null ? true : (bool) $ed->is_legally_allowed;
            $isDisciplinary = Str::contains(strtolower((string) $name), ['disciplinary', 'penalty', 'sanction']);
            $withInterest = (bool) ($ed->with_interest ?? false) || (float) ($ed->interest_rate_annual ?? 0) > 0;
            if ($isLoan && $ed->remaining_balance !== null) {
                $displayName = $name.' (Remaining: ₱'.number_format((float) $ed->remaining_balance, 2, '.', ',').($withInterest ? ' + Interest' : '').')';
            }
            $code = 'EMP_DED_'.$ed->id;
            $pcId = $ed->pay_component_id;
            $resolvedSchedule = $this->resolveEmployeeDeductionSchedule($ed, $user->getEffectiveCompanyId());

            $out[] = [
                'id' => null,
                'employee_deduction_id' => (int) $ed->id,
                'pay_component_id' => $pcId ? (int) $pcId : null,
                'structure_name' => 'Automated deduction',
                'name' => $displayName,
                'code' => $code,
                'type' => PayComponent::TYPE_DEDUCTION,
                'category' => $type->type,
                'calculation_type' => PayComponent::CALC_FIXED,
                'computed_amount' => round($monthly, 2),
                'configured_value' => round((float) $ed->amount, 2),
                'hourly_rate' => null,
                'hours' => null,
                'formula' => null,
                'is_taxable' => false,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
                'is_custom' => true,
                'effective_from' => $ed->start_date?->toDateString(),
                'effective_to' => $ed->end_date?->toDateString(),
                'metadata' => [
                    'source' => 'employee_deduction',
                    'employee_deduction_id' => (int) $ed->id,
                    'deduction_type_id' => (int) $type->id,
                    'remaining_balance' => $ed->remaining_balance !== null ? round((float) $ed->remaining_balance, 2) : null,
                    'is_amortized' => (bool) $ed->is_amortized,
                    'with_interest' => $withInterest,
                    'interest_rate_annual' => $ed->interest_rate_annual !== null ? round((float) $ed->interest_rate_annual, 4) : null,
                    'interest_type' => (is_string($ed->interest_type) && trim($ed->interest_type) !== '') ? trim((string) $ed->interest_type) : null,
                    'total_repayment_amount' => $ed->total_repayment_amount !== null ? round((float) $ed->total_repayment_amount, 2) : null,
                    'amortization_exact_installment' => (bool) ($isLoan && $ed->is_amortized),
                    'next_installment_number' => $pendingInstallment?->installment_number ? (int) $pendingInstallment->installment_number : null,
                    'next_installment_amount' => $pendingInstallment ? round((float) $pendingInstallment->total_installment, 2) : null,
                    'next_installment_pay_date' => $pendingInstallment?->pay_date ? Carbon::parse((string) $pendingInstallment->pay_date)->toDateString() : null,
                    'next_installment_due_date' => $pendingInstallment?->due_date ? Carbon::parse((string) $pendingInstallment->due_date)->toDateString() : null,
                    'next_due_date' => $this->resolveNextDueForMetadata($user, $ed, $asOf, $payCyclePreviewCache),
                    'total_loan_amount' => $ed->total_loan_amount !== null ? round((float) $ed->total_loan_amount, 2) : null,
                    'deduction_schedule' => $resolvedSchedule,
                    'deduction_type' => $type->type,
                    'deduction_type_slug' => $type->slug,
                    'is_court_ordered_garnishment' => (bool) $ed->is_court_ordered_garnishment,
                    'is_legally_allowed' => $isLegallyAllowed,
                    'legal_warning' => ($isDisciplinary && ! $isLegallyAllowed)
                        ? 'Disciplinary deduction is blocked unless legal permission is enabled.'
                        : null,
                    'priority_order' => $this->priorityOrderForRow(
                        $type->type,
                        $type->slug,
                        (string) $type->name,
                        (bool) $ed->is_court_ordered_garnishment,
                        $ed->priority_override
                    ),
                    // Surface schedule to Salary tab / employee profile for "View schedule" without extra API roundtrip.
                    'amortization_schedule' => ($ed->is_amortized && $ed->relationLoaded('amortizationSchedule'))
                        ? $ed->amortizationSchedule
                            ->map(fn (LoanAmortization $row) => [
                                'id' => (int) $row->id,
                                'installment_number' => (int) $row->installment_number,
                                'due_date' => optional($row->due_date)->toDateString(),
                                'pay_date' => optional($row->pay_date)->toDateString(),
                                'principal' => round((float) $row->principal, 2),
                                'interest' => round((float) $row->interest, 2),
                                'total_installment' => round((float) $row->total_installment, 2),
                                'status' => (string) $row->status,
                            ])
                            ->values()
                            ->all()
                        : [],
                ],
            ];
        }

        return $out;
    }

    /**
     * Phase 3: enforce deduction priority + legal minimum take-home + garnishment caps.
     *
     * @param  list<array<string, mixed>>  $customLines
     * @return array{custom_lines:list<array<string,mixed>>, custom_deductions_this_period:float, legal_warnings:list<string>, minimum_take_home_floor:float}
     */
    public function enforcePriorityAndLegalLimitsForPayrollPeriod(
        User $user,
        array $customLines,
        float $grossThisPeriod,
        float $employeeStatutoryThisPeriod,
        float $withholdingThisPeriod,
        Carbon $from,
        Carbon $to,
        ?float $actualWorkingDays = null
    ): array {
        $floorDays = $actualWorkingDays !== null && $actualWorkingDays > 0
            ? (int) ceil($actualWorkingDays)
            : max(1, $from->diffInDays($to) + 1);
        $floor = $this->garnishmentService->minimumWageProtectionAmount($floorDays);
        $disposableAfterStatutory = max(0.0, $grossThisPeriod - $employeeStatutoryThisPeriod - $withholdingThisPeriod);
        $floor = min($floor, $disposableAfterStatutory);
        $remainingBudget = max(0.0, $grossThisPeriod - $employeeStatutoryThisPeriod - $withholdingThisPeriod - $floor);
        $disposableIncome = max(0.0, $grossThisPeriod - $employeeStatutoryThisPeriod - $withholdingThisPeriod);
        $maxGarnishment = $this->garnishmentService->garnishmentCap($disposableIncome);
        $garnishmentUsed = 0.0;
        $warnings = [];

        $indexed = [];
        foreach ($customLines as $idx => $line) {
            $meta = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
            $type = (string) ($meta['deduction_type'] ?? '');
            $slug = (string) ($meta['deduction_type_slug'] ?? '');
            $name = (string) ($line['name'] ?? '');
            $isGarnishment = (bool) ($meta['is_court_ordered_garnishment'] ?? false);
            $order = $this->priorityOrderForRow($type, $slug, $name, $isGarnishment, $meta['priority_order'] ?? null);
            $line['priority_order'] = $order;
            $line['priority_bucket'] = $this->priorityBucketLabel($order);
            $line['scheduled_this_period'] = round((float) ($line['scheduled_this_period'] ?? $line['computed_amount'] ?? 0), 2);
            $indexed[] = ['idx' => $idx, 'line' => $line];
        }

        usort($indexed, function (array $a, array $b): int {
            $pa = (int) ($a['line']['priority_order'] ?? 999);
            $pb = (int) ($b['line']['priority_order'] ?? 999);
            if ($pa === $pb) {
                return ((int) $a['idx']) <=> ((int) $b['idx']);
            }

            return $pa <=> $pb;
        });

        $appliedTotal = 0.0;
        foreach ($indexed as &$row) {
            $line = $row['line'];
            $meta = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
            $desired = round(max(0.0, (float) ($line['scheduled_this_period'] ?? 0)), 2);
            $applied = 0.0;
            $warning = null;
            $isLegallyAllowed = (bool) ($meta['is_legally_allowed'] ?? true);
            $isGarnishment = (bool) ($meta['is_court_ordered_garnishment'] ?? false);
            $isDisciplinary = Str::contains(
                strtolower((string) ($line['name'] ?? '')),
                ['disciplinary', 'penalty', 'sanction']
            );

            if ($desired <= 0) {
                $line['applied_this_period'] = 0.0;
                $line['blocked_this_period'] = 0.0;
                $line['legal_warning'] = null;
                $row['line'] = $line;

                continue;
            }

            if ($isDisciplinary && ! $isLegallyAllowed) {
                $warning = 'Blocked disciplinary deduction: legal permission flag is required.';
            } elseif ($isGarnishment) {
                // Court-ordered garnishments remain subject to statutory garnishment limits.
                // Ordinary payroll deductions are not capped to available gross/net pay.
                $allowedByCap = max(0.0, $maxGarnishment - $garnishmentUsed);
                $allowedByBudget = min($remainingBudget, $allowedByCap);
                $applied = round(max(0.0, min($desired, $allowedByBudget)), 2);
                if ($applied < $desired) {
                    $warning = 'Reduced by legal garnishment cap / minimum-wage protection.';
                }
            } else {
                $applied = $desired;
            }

            $remainingBudget = round(max(0.0, $remainingBudget - $applied), 2);
            if ($isGarnishment) {
                $garnishmentUsed = round($garnishmentUsed + $applied, 2);
            }
            $appliedTotal = round($appliedTotal + $applied, 2);
            $line['applied_this_period'] = $applied;
            $line['blocked_this_period'] = round(max(0.0, $desired - $applied), 2);
            $line['legal_warning'] = $warning;
            if ($warning) {
                $warnings[] = (($line['name'] ?? 'Deduction').': '.$warning);
            }
            $row['line'] = $line;
        }
        unset($row);

        usort($indexed, fn (array $a, array $b): int => ((int) $a['idx']) <=> ((int) $b['idx']));
        $finalLines = array_map(fn (array $r): array => $r['line'], $indexed);

        return [
            'custom_lines' => $finalLines,
            'custom_deductions_this_period' => round($appliedTotal, 2),
            'legal_warnings' => array_values(array_unique($warnings)),
            'minimum_take_home_floor' => round($floor, 2),
        ];
    }

    private function priorityOrderForRow(
        string $deductionType,
        string $deductionSlug,
        string $name,
        bool $isCourtOrderedGarnishment,
        mixed $priorityOverride
    ): int {
        if (is_numeric($priorityOverride) && (int) $priorityOverride > 0) {
            return (int) $priorityOverride;
        }
        if ($isCourtOrderedGarnishment || Str::contains(strtolower($name.' '.$deductionSlug), ['garnish'])) {
            return 5;
        }

        return match (strtolower(trim($deductionType))) {
            DeductionType::TYPE_LOAN => 3,
            DeductionType::TYPE_BENEFIT => 4,
            default => 5,
        };
    }

    private function priorityBucketLabel(int $order): string
    {
        return match ($order) {
            3 => 'Loans & salary advances',
            4 => 'Benefits deductions',
            default => 'Other authorized deductions',
        };
    }

    /**
     * @param  array<string, mixed>|null  $payCyclePreviewCache  Passed by ref: one preview per compensation build.
     */
    private function resolveNextDueForMetadata(User $user, EmployeeDeduction $ed, Carbon $asOf, ?array &$payCyclePreviewCache): ?string
    {
        if ($ed->is_amortized && $ed->relationLoaded('amortizationSchedule')) {
            $pending = $ed->amortizationSchedule
                ->where('status', LoanAmortization::STATUS_PENDING)
                ->sortBy('installment_number')
                ->first();
            if ($pending) {
                $d = $pending->pay_date ?? $pending->due_date;

                return $d ? Carbon::parse((string) $d)->toDateString() : null;
            }
        }

        if ($ed->next_due_date) {
            return $ed->next_due_date->toDateString();
        }

        $type = $ed->deductionType;
        if ($type && $type->type === DeductionType::TYPE_LOAN && $ed->remaining_balance !== null && ! $ed->is_amortized) {
            if ($payCyclePreviewCache === null) {
                $payCyclePreviewCache = $this->payCycleService->previewForUser($user, $asOf) ?? [];
            }
            $pd = $payCyclePreviewCache['pay_date'] ?? null;
            if ($pd) {
                return Carbon::parse((string) $pd)->toDateString();
            }
        }

        return null;
    }

    /**
     * After a payroll period is saved, reduce loan remaining balances by amounts that applied this period.
     *
     * @param  PayrollPeriod|null  $payrollPeriod  When set, amortized installments require cut-off alignment and proration uses the period's reference date.
     */
    public function applyLoanBalancesAfterSavedPayroll(User $user, Carbon $referenceDate, ?PayrollPeriod $payrollPeriod = null): void
    {
        $tz = $this->deductionScheduleService->timezone();
        $ref = $referenceDate->copy()->timezone($tz)->startOfDay();
        $refForProration = $ref;
        if ($payrollPeriod) {
            if ($payrollPeriod->reference_date) {
                $refForProration = Carbon::parse($payrollPeriod->reference_date->toDateString(), $tz)->startOfDay();
            } elseif ($payrollPeriod->cut_off_end_date) {
                $refForProration = Carbon::parse($payrollPeriod->cut_off_end_date->toDateString(), $tz)->startOfDay();
            }
        }

        $companyId = $user->getEffectiveCompanyId();

        $rows = EmployeeDeduction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $refForProration->toDateString())
            ->where(function ($q) use ($refForProration) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $refForProration->toDateString());
            })
            ->with('deductionType')
            ->get();

        foreach ($rows as $ed) {
            $type = $ed->deductionType;
            if (! $type || $type->type !== DeductionType::TYPE_LOAN || $ed->remaining_balance === null) {
                continue;
            }

            if ($ed->is_amortized) {
                if (! $payrollPeriod) {
                    // Amortized schedule rows must link to audit payroll — never apply without a saved period.
                    continue;
                }
                $this->applySingleAmortizedLoanForPayrollPeriod($user, $ed, $payrollPeriod);

                continue;
            }

            $monthly = $this->monthlyFullAmount($ed);
            if ($monthly <= 0) {
                continue;
            }

            $sched = $this->resolveEmployeeDeductionSchedule($ed, $companyId);
            // Keep in sync with {@see LoanAmortizationService::generateSchedule} so installment rows match withheld amounts.
            $cycle = $this->loanAmortizationService->resolvePayCycleForEmployee($user);
            $factor = $cycle
                ? $this->loanAmortizationService->loanInstallmentProrationFactor(
                    $cycle,
                    $sched,
                    $this->payrollPeriodContextForLoanProration($cycle, $refForProration)
                )
                : $this->resolveScheduleFactorForPayrollPeriod($user, $sched, $refForProration, $payrollPeriod);
            $applied = round($monthly * $factor, 2);
            if ($applied <= 0) {
                continue;
            }

            $newBal = round(max(0.0, (float) $ed->remaining_balance - $applied), 2);
            $oldBal = (float) $ed->remaining_balance;
            $ed->remaining_balance = $newBal;
            if ($newBal <= 0) {
                $ed->is_active = false;
            }
            $ed->save();
            $this->deductionAuditService->log(
                $ed,
                'payroll_deduction_applied',
                null,
                $applied,
                $newBal,
                ['remaining_balance' => round($oldBal, 2)],
                ['remaining_balance' => round($newBal, 2)],
                'Applied from saved payroll period deduction pass.',
                [
                    'payroll_period_id' => $payrollPeriod?->id,
                    'reference_date' => $refForProration->toDateString(),
                    'mode' => 'non_amortized',
                ]
            );
        }
    }

    /**
     * Catch-up: mark pending installments paid when a {@see PayrollPeriod} already exists for the installment cut-off
     * (e.g. after deploy or if a prior apply failed). Does not create payroll runs.
     *
     * @return int Number of installments marked paid
     */
    public function autoProcessDueInstallments(Carbon $referenceDate, ?int $userId = null): int
    {
        $tz = $this->deductionScheduleService->timezone();
        $until = $referenceDate->copy()->timezone($tz)->startOfDay();
        $appliedCount = 0;

        $q = EmployeeDeduction::query()
            ->where('is_active', true)
            ->where('is_amortized', true)
            ->with(['deductionType', 'user']);

        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        foreach ($q->cursor() as $ed) {
            $type = $ed->deductionType;
            if (! $type || $type->type !== DeductionType::TYPE_LOAN || $ed->remaining_balance === null) {
                continue;
            }

            $user = $ed->user;
            if (! $user) {
                continue;
            }

            $firstPending = LoanAmortization::query()
                ->where('employee_deduction_id', $ed->id)
                ->where('status', LoanAmortization::STATUS_PENDING)
                ->orderBy('installment_number')
                ->first();

            if (! $firstPending || ! $firstPending->due_date) {
                continue;
            }

            $dueStr = $firstPending->due_date instanceof Carbon
                ? $firstPending->due_date->toDateString()
                : Carbon::parse((string) $firstPending->due_date)->toDateString();

            if ($dueStr > $until->toDateString()) {
                continue;
            }

            $period = PayrollPeriod::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($dueStr) {
                    // Prefer pay-date linkage for amortization schedules; keep cut-off fallback for legacy rows.
                    $q->whereDate('pay_date', $dueStr)
                        ->orWhereDate('cut_off_end_date', $dueStr);
                })
                ->orderByDesc('id')
                ->first();

            if (! $period) {
                continue;
            }

            if ($this->applySingleAmortizedLoanForPayrollPeriod($user, $ed->fresh(['deductionType']), $period)) {
                $appliedCount++;
            }
        }

        return $appliedCount;
    }

    /**
     * Computes proration for one amortized loan and marks the next installment paid when cut-off matches the payroll period.
     */
    private function applySingleAmortizedLoanForPayrollPeriod(User $user, EmployeeDeduction $ed, PayrollPeriod $payrollPeriod): bool
    {
        if (! $ed->is_amortized) {
            return false;
        }

        $tz = $this->deductionScheduleService->timezone();
        $refForProration = $payrollPeriod->reference_date
            ? Carbon::parse($payrollPeriod->reference_date->toDateString(), $tz)->startOfDay()
            : Carbon::parse($payrollPeriod->cut_off_end_date->toDateString(), $tz)->startOfDay();

        $type = $ed->deductionType;
        if (! $type || $type->type !== DeductionType::TYPE_LOAN || $ed->remaining_balance === null) {
            return false;
        }

        if (! $this->loanAmortizationService->firstPendingInstallmentMatchesPayrollCutOff($ed, $payrollPeriod)) {
            return false;
        }

        $pending = LoanAmortization::query()
            ->where('employee_deduction_id', $ed->id)
            ->whereIn('status', [LoanAmortization::STATUS_PENDING, LoanAmortization::STATUS_OVERDUE])
            ->orderBy('installment_number')
            ->first();
        $applied = $pending ? round((float) $pending->total_installment, 2) : 0.0;
        if ($applied <= 0) {
            return false;
        }

        $this->loanAmortizationService->applyPaymentFromPayrollRun(
            $ed->fresh(),
            $applied,
            $refForProration,
            (int) $payrollPeriod->id
        );

        return true;
    }

    /**
     * Cut-off / pay-day context for the payroll reference date (aligned with {@see LoanAmortizationService::generateSchedule}).
     *
     * @return array<string, mixed>
     */
    private function payrollPeriodContextForLoanProration(PayCycle $cycle, Carbon $ref): array
    {
        $p = $this->payCycleService->getCutOffPeriod($cycle, $ref);

        return [
            'end' => $p['end'],
            'pay_date' => $p['pay_date'],
            'semi_month_segment' => $p['semi_month_segment'] ?? null,
        ];
    }

    private function resolveScheduleFactorForPayrollPeriod(
        User $user,
        string $scheduleType,
        Carbon $referenceDate,
        ?PayrollPeriod $payrollPeriod
    ): float {
        if ($payrollPeriod && $payrollPeriod->from_date && $payrollPeriod->to_date) {
            $periodDates = $this->deductionScheduleService->getDeductionDatesForPeriod(
                $user,
                $scheduleType,
                $payrollPeriod->from_date->toDateString(),
                $payrollPeriod->to_date->toDateString()
            );
            $selectedPayDate = $payrollPeriod->pay_date?->toDateString()
                ?? $payrollPeriod->reference_date?->toDateString()
                ?? $referenceDate->toDateString();
            $dates = collect($periodDates['dates'] ?? [])->filter()->values();
            if ($dates->count() > 0) {
                if ($scheduleType === DeductionScheduleSetting::SCHEDULE_BOTH) {
                    return $dates->contains($selectedPayDate) ? 0.5 : 0.0;
                }

                return $dates->contains($selectedPayDate) ? 1.0 : 0.0;
            }
        }

        return $this->deductionScheduleService->prorationFactorForMonthlyAmount($scheduleType, $referenceDate);
    }

    private function resolveEmployeeDeductionSchedule(EmployeeDeduction $ed, ?int $companyId): string
    {
        $selfSchedule = $this->normalizeScheduleType($ed->deduction_schedule);
        if ($selfSchedule !== null) {
            return $selfSchedule;
        }

        $loanRequestSchedule = $this->normalizeScheduleType($ed->loanRequest?->deduction_schedule);
        if ($loanRequestSchedule !== null) {
            return $loanRequestSchedule;
        }

        if ($ed->pay_component_id) {
            return $this->deductionScheduleService->resolveScheduleType('pay_component:'.((int) $ed->pay_component_id), $companyId);
        }

        return DeductionScheduleSetting::SCHEDULE_BOTH;
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
            DeductionScheduleSetting::SCHEDULE_15TH, '15', '15th_only', '15_only', 'every_15_only', 'every_15', 'first', 'first_half', 'first-half' => DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH, '30', '30th_only', '30_only', 'every_30_only', 'every_30', 'second', 'second_half', 'second-half', 'end_of_month', 'end-of-month', 'eom' => DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH, 'every_payroll', 'every-payroll', 'every_15_and_30', '15_and_30', '15/30', '50/50', 'half', 'split', 'semi-monthly', 'semi_monthly' => DeductionScheduleSetting::SCHEDULE_BOTH,
            default => null,
        };
    }
}
