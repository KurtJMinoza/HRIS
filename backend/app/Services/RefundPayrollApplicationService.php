<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll Adjustment Ledger bridge.
 *
 * Approved refunds are consumed by the NEXT eligible payroll — finalized runs are never modified.
 * Finalize/payslip generation can call {@see self::pendingAdjustmentsForWindow()} to append
 * separate "Payroll Adjustments" payslip lines and {@see self::markProcessedForBatch()} to
 * settle them. Component codes inherit the ORIGINAL component's payroll treatment
 * (basic/OT/ND/holiday), so taxability follows the source component rather than a blanket rule.
 */
class RefundPayrollApplicationService
{
    /** component_code => [label, source_component, taxable_classification] */
    private const LINE_MAP = [
        'regular_pay' => ['code' => 'refund_basic_pay', 'label' => 'Attendance Refund', 'source' => 'basic_pay'],
        'ot_pay' => ['code' => 'refund_overtime', 'label' => 'Missing OT Recovery', 'source' => 'overtime_pay'],
        'nd_pay' => ['code' => 'refund_night_differential', 'label' => 'Night Differential Adjustment', 'source' => 'night_differential'],
        'holiday_premium_pay' => ['code' => 'refund_holiday_pay', 'label' => 'Holiday Pay Adjustment', 'source' => 'holiday_premium'],
        'paid_leave' => ['code' => 'refund_leave_pay', 'label' => 'Leave Pay Adjustment', 'source' => 'paid_leave'],
        'rest_day_worked_pay' => ['code' => 'refund_rest_day_pay', 'label' => 'Rest Day Pay Adjustment', 'source' => 'rest_day_worked_pay'],
    ];

    /**
     * Refunds ready to be absorbed by a pay window covering [from, to].
     *
     * @return list<array{refund_request_id:int, refund_number:string, reason:string,
     *   component_code:string, label:string, amount:float, source:string, line_type:string}>
     */
    public function pendingAdjustmentsForWindow(User|int $employee, string $from, string $to): array
    {
        $userId = $employee instanceof User ? $employee->id : $employee;

        $refunds = RefundRequest::query()
            ->where('employee_id', $userId)
            ->whereIn('status', RefundRequest::PAYROLL_PENDING_STATUSES)
            ->orderBy('id')
            ->get();

        $lines = [];
        foreach ($refunds as $refund) {
            if (! $this->isEligibleForPayWindow($refund, $from, $to)) {
                continue;
            }
            foreach ($this->componentLines($refund) as $line) {
                $line['refund_request_id'] = $refund->id;
                $line['refund_number'] = $refund->refund_number;
                $line['reason'] = $refund->reason;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<array{component_code:string,label:string,amount:float,source:string,line_type:string}>
     */
    public function componentLines(RefundRequest $refund): array
    {
        $components = $this->normalizedComponents($refund);
        $isOverpayment = $refund->direction === RefundRequest::DIRECTION_OVERPAYMENT;
        $reasonLabel = $this->payslipLabelForReason($refund);
        $lines = [];

        foreach ($components as $key => $c) {
            if (! isset(self::LINE_MAP[$key])) {
                continue;
            }
            $diff = round((float) ($c['difference'] ?? 0), 2);
            if (abs($diff) <= 0.004) {
                continue;
            }
            $map = self::LINE_MAP[$key];
            if ($diff > 0) {
                $lines[] = [
                    'component_code' => $map['code'],
                    'label' => $reasonLabel,
                    'amount' => $diff,
                    'source' => $map['source'],
                    'line_type' => 'earning',
                ];
            } elseif ($diff < 0) {
                $lines[] = [
                    'component_code' => 'payroll_recovery_'.$map['code'],
                    'label' => 'Payroll Recovery — '.$reasonLabel,
                    'amount' => abs($diff),
                    'source' => $map['source'],
                    'line_type' => 'deduction',
                ];
            }
        }

        if ($lines === [] && (float) $refund->refund_amount > 0.004) {
            $map = self::LINE_MAP['regular_pay'];
            $amount = round((float) $refund->refund_amount, 2);
            $lines[] = [
                'component_code' => $map['code'],
                'label' => $isOverpayment ? 'Payroll Recovery — '.$reasonLabel : $reasonLabel,
                'amount' => $amount,
                'source' => $map['source'],
                'line_type' => $isOverpayment ? 'deduction' : 'earning',
            ];
        }

        return $lines;
    }

    private function payslipLabelForReason(RefundRequest $refund): string
    {
        $label = trim((string) $refund->reasonLabel());

        return $label !== '' ? $label : 'Payroll Adjustment';
    }

    /**
     * Settle every approved refund absorbed by the given batch run.
     *
     * @return int number of refunds marked processed
     */
    public function markProcessedForBatch(PayrollBatchRun $batch, ?User $actor = null): int
    {
        $start = optional($batch->pay_period_start)->toDateString();
        $end = optional($batch->pay_period_end)->toDateString();
        if (! $start || ! $end) {
            return 0;
        }

        return DB::transaction(function () use ($batch, $actor, $start, $end) {
            $employeeIds = DB::table('payroll_employees')
                ->where('payroll_batch_run_id', $batch->id)
                ->pluck('user_id')
                ->unique();

            if ($employeeIds->isEmpty()) {
                return 0;
            }

            $refunds = RefundRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('status', RefundRequest::PAYROLL_PENDING_STATUSES)
                ->lockForUpdate()
                ->get();

            $payslipsByUser = Payslip::query()
                ->where('payroll_batch_run_id', $batch->id)
                ->where('status', '!=', Payslip::STATUS_VOIDED)
                ->get(['id', 'user_id', 'snapshot'])
                ->keyBy(fn (Payslip $payslip) => (int) $payslip->user_id);

            $count = 0;
            $now = now();
            foreach ($refunds as $refund) {
                if (! $this->isEligibleForPayWindow($refund, $start, $end)) {
                    continue;
                }
                $payslip = $payslipsByUser->get((int) $refund->employee_id);
                if (! $payslip instanceof Payslip || ! $this->payslipContainsRefund($payslip, $refund)) {
                    continue;
                }
                $fromStatus = $refund->status;
                $refund->status = RefundRequest::STATUS_PROCESSED;
                $refund->processed_at = $now;
                $refund->processed_by = $actor?->id;
                $refund->processed_batch_run_id = $batch->id;
                $refund->save();

                app(RefundWorkflowService::class)->writeAudit(
                    $refund,
                    $actor,
                    'payroll-applied',
                    $fromStatus,
                    RefundRequest::STATUS_PROCESSED,
                    "Applied via payroll batch #{$batch->id} ({$start} → {$end})."
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * Re-queue refunds that were marked processed on voided payroll batches.
     *
     * @return int number of refunds returned to approved
     */
    public function requeueRefundsOnVoidedBatches(?User $actor = null): int
    {
        $count = 0;
        $voidedBatches = PayrollBatchRun::query()
            ->where('status', PayrollBatchRun::STATUS_VOIDED)
            ->orderBy('id')
            ->get();

        foreach ($voidedBatches as $batch) {
            $count += $this->revertProcessedForVoidedBatch($batch, $actor);
        }

        return $count;
    }

    /**
     * Re-queue processed refunds that never landed on a payslip snapshot (e.g. voided batch finalize).
     *
     * @return int number of refunds returned to approved
     */
    public function requeueProcessedRefundsMissingFromPayslip(?User $actor = null): int
    {
        return DB::transaction(function () use ($actor) {
            $refunds = RefundRequest::query()
                ->where('status', RefundRequest::STATUS_PROCESSED)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($refunds as $refund) {
                $payslip = null;
                if ($refund->processed_batch_run_id) {
                    $payslip = Payslip::query()
                        ->where('payroll_batch_run_id', (int) $refund->processed_batch_run_id)
                        ->where('user_id', (int) $refund->employee_id)
                        ->where('status', '!=', Payslip::STATUS_VOIDED)
                        ->whereNull('voided_at')
                        ->first();
                }

                if ($payslip instanceof Payslip && $this->payslipContainsRefund($payslip, $refund)) {
                    continue;
                }

                $fromStatus = $refund->status;
                $refund->status = RefundRequest::STATUS_APPROVED;
                $refund->processed_at = null;
                $refund->processed_by = null;
                $refund->processed_batch_run_id = null;
                $refund->save();

                app(RefundWorkflowService::class)->writeAudit(
                    $refund,
                    $actor,
                    'payroll-requeued',
                    $fromStatus,
                    RefundRequest::STATUS_APPROVED,
                    'Re-queued because the processed payroll batch did not retain this adjustment on the payslip.'
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * Apply every approved refund onto the best matching payslip and settle on the batch run.
     *
     * @return array{requeued:int, applied:int, settled:int, skipped:int, details:list<string>}
     */
    public function repairApprovedRefundsOnPayslips(?User $actor = null): array
    {
        $payslipService = app(PayslipService::class);
        $requeued = $this->requeueRefundsOnVoidedBatches($actor)
            + $this->requeueProcessedRefundsMissingFromPayslip($actor);

        $refunds = RefundRequest::query()
            ->whereIn('status', RefundRequest::PAYROLL_PENDING_STATUSES)
            ->orderBy('id')
            ->get();

        $applied = 0;
        $skipped = 0;
        $details = [];
        $touchedBatchIds = [];

        foreach ($refunds as $refund) {
            $employee = User::query()->find((int) $refund->employee_id);
            if (! $employee instanceof User) {
                $skipped++;
                $details[] = "Refund #{$refund->id}: employee missing.";
                continue;
            }

            $payslip = $this->resolvePayslipForRefund($refund);
            if (! $payslip instanceof Payslip) {
                $skipped++;
                $details[] = "Refund #{$refund->id} ({$employee->last_name}): no eligible payslip yet.";
                continue;
            }

            if ($this->payslipContainsRefund($payslip, $refund)) {
                $skipped++;
                continue;
            }

            $force = in_array((string) $payslip->status, Payslip::lockingStatuses(), true);
            $payslipService->applyPendingRefundsToPayslip($payslip, $employee, $force);
            $payslip = $payslip->fresh() ?? $payslip;

            if (! $this->payslipContainsRefund($payslip, $refund)) {
                $skipped++;
                $details[] = "Refund #{$refund->id} ({$employee->last_name}): apply failed on payslip #{$payslip->id}.";
                continue;
            }

            if ($force) {
                $payslipService->ensurePayslipPdfOnDisk($payslip, $employee, forceRegenerate: true);
            }

            $applied++;
            $batchId = (int) ($payslip->payroll_batch_run_id ?? 0);
            if ($batchId > 0) {
                $touchedBatchIds[$batchId] = true;
            }
            $details[] = "Refund #{$refund->id} ({$employee->last_name}) → payslip #{$payslip->id} ({$payslip->pay_period_start?->toDateString()} → {$payslip->pay_period_end?->toDateString()}).";
        }

        $settled = 0;
        foreach (array_keys($touchedBatchIds) as $batchId) {
            $batch = PayrollBatchRun::query()->find((int) $batchId);
            if ($batch instanceof PayrollBatchRun) {
                $settled += $this->markProcessedForBatch($batch, $actor);
            }
        }

        return compact('requeued', 'applied', 'settled', 'skipped', 'details');
    }

    private function resolvePayslipForRefund(RefundRequest $refund): ?Payslip
    {
        $cutoffStart = optional($refund->cutoff_start_date)->toDateString();
        $cutoffEnd = optional($refund->cutoff_end_date)->toDateString();
        if ($cutoffStart === null || $cutoffEnd === null) {
            return null;
        }

        $candidates = Payslip::query()
            ->where('user_id', (int) $refund->employee_id)
            ->whereNull('voided_at')
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->orderByDesc('pay_period_start')
            ->get();

        foreach ($candidates as $payslip) {
            $from = $payslip->pay_period_start?->toDateString();
            $to = $payslip->pay_period_end?->toDateString();
            if ($from === null || $to === null) {
                continue;
            }
            if ($this->isEligibleForPayWindow($refund, $from, $to)) {
                return $payslip;
            }
        }

        return null;
    }

    /**
     * When a payroll batch is voided, refunds marked processed on that batch must be re-queued.
     *
     * @return int number of refunds returned to approved
     */
    public function revertProcessedForVoidedBatch(PayrollBatchRun $batch, ?User $actor = null): int
    {
        return DB::transaction(function () use ($batch, $actor) {
            $refunds = RefundRequest::query()
                ->where('processed_batch_run_id', $batch->id)
                ->where('status', RefundRequest::STATUS_PROCESSED)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($refunds as $refund) {
                $fromStatus = $refund->status;
                $refund->status = RefundRequest::STATUS_APPROVED;
                $refund->processed_at = null;
                $refund->processed_by = null;
                $refund->processed_batch_run_id = null;
                $refund->save();

                app(RefundWorkflowService::class)->writeAudit(
                    $refund,
                    $actor,
                    'payroll-reverted',
                    $fromStatus,
                    RefundRequest::STATUS_APPROVED,
                    "Re-queued because payroll batch #{$batch->id} was voided."
                );
                $count++;
            }

            return $count;
        });
    }

    public function payslipContainsRefund(Payslip $payslip, RefundRequest $refund): bool
    {
        return in_array((int) $refund->id, $this->refundRequestIdsOnPayslip($payslip), true);
    }

    /** @return list<int> */
    public function refundRequestIdsOnPayslip(Payslip $payslip): array
    {
        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
        if (! is_array($snapshot)) {
            return [];
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $ids = [];
        foreach ($summary['payroll_adjustment_lines'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $refundId = (int) ($line['refund_request_id'] ?? 0);
            if ($refundId > 0) {
                $ids[] = $refundId;
            }
        }
        foreach (array_merge(
            is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [],
            is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [],
        ) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $metadata = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
            $refundId = (int) ($metadata['refund_request_id'] ?? 0);
            if ($refundId > 0) {
                $ids[] = $refundId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, array<string, mixed>> */
    private function normalizedComponents(RefundRequest $refund): array
    {
        $raw = $refund->calculation['components'] ?? [];
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $isList = array_is_list($raw);
        if (! $isList) {
            return $raw;
        }

        // Legacy snapshots stored array_values() without keys — match by label order.
        $normalized = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (self::LINE_MAP as $key => $map) {
                if (($row['label'] ?? null) === self::componentLabel($key)) {
                    $normalized[$key] = $row;
                    break;
                }
            }
        }

        return $normalized;
    }

    private static function componentLabel(string $key): string
    {
        return match ($key) {
            'regular_pay' => 'Basic Pay',
            'ot_pay' => 'Overtime',
            'nd_pay' => 'Night Differential',
            'holiday_premium_pay' => 'Holiday Pay',
            'paid_leave' => 'Leave Adjustments',
            'rest_day_worked_pay' => 'Rest Day Worked Pay',
            default => $key,
        };
    }

    /**
     * Decide whether a pending refund belongs on this payroll window.
     *
     * Finalized originals (e.g. missing attendance on a closed run) apply on the NEXT payroll
     * after the locked cutoff ends — never by re-opening the old payslip.
     */
    public function isEligibleForPayWindow(RefundRequest $refund, string $from, string $to): bool
    {
        $affectedFrom = optional($refund->affected_date)->toDateString();
        if ($affectedFrom === null) {
            return false;
        }
        $affectedTo = optional($refund->affected_date_to)->toDateString() ?? $affectedFrom;
        $cutoffStart = optional($refund->cutoff_start_date)->toDateString();
        $cutoffEnd = optional($refund->cutoff_end_date)->toDateString();
        $applicationTiming = (string) data_get($refund->calculation, 'application_timing', '');

        if ($this->referencesFinalizedOriginalPayroll($refund) && $cutoffEnd !== null) {
            return $from > $cutoffEnd;
        }

        if ($applicationTiming === 'next_payroll' && $cutoffEnd !== null) {
            return $from > $cutoffEnd;
        }

        if ($applicationTiming === 'selected_payroll_cycle' && $cutoffStart !== null && $cutoffEnd !== null) {
            if ($from === $cutoffStart && $to === $cutoffEnd) {
                return true;
            }

            // Selected cycle closed (finalized or missed) — carry to the employee's next payroll.
            if ($this->selectedPayrollCycleHasClosed($refund) && $from > $cutoffEnd) {
                return true;
            }

            return false;
        }

        return $affectedFrom <= $to && $affectedTo >= $from;
    }

    private function referencesFinalizedOriginalPayroll(RefundRequest $refund): bool
    {
        if ($refund->original_payroll_batch_run_id) {
            return true;
        }

        if ((int) data_get($refund->calculation, 'original_batch_run_id', 0) > 0) {
            return true;
        }

        return (bool) data_get($refund->calculation, 'finalized', false);
    }

    /**
     * True when the admin-selected pay cycle can no longer absorb this refund for the employee
     * (finalized run exists, or the window ended with no open draft payslip for them).
     */
    private function selectedPayrollCycleHasClosed(RefundRequest $refund): bool
    {
        $cutoffStart = optional($refund->cutoff_start_date)->toDateString();
        $cutoffEnd = optional($refund->cutoff_end_date)->toDateString();
        if ($cutoffStart === null || $cutoffEnd === null) {
            return false;
        }

        $employeeId = (int) $refund->employee_id;
        if ($employeeId <= 0) {
            return Carbon::now()->toDateString() > $cutoffEnd;
        }

        $employee = User::query()->find($employeeId);
        $companyId = $employee?->getEffectiveCompanyId();

        if ($companyId !== null && $companyId > 0) {
            $batchClosed = PayrollBatchRun::query()
                ->whereDate('pay_period_start', $cutoffStart)
                ->whereDate('pay_period_end', $cutoffEnd)
                ->where('company_id', $companyId)
                ->where('status', PayrollBatchRun::STATUS_FINALIZED)
                ->exists();

            if ($batchClosed) {
                return true;
            }
        }

        $hasOpenDraft = Payslip::query()
            ->where('user_id', (int) $refund->employee_id)
            ->whereDate('pay_period_start', $cutoffStart)
            ->whereDate('pay_period_end', $cutoffEnd)
            ->whereNull('voided_at')
            ->whereIn('status', [Payslip::STATUS_DRAFT, Payslip::STATUS_GENERATED])
            ->exists();

        if ($hasOpenDraft) {
            return false;
        }

        return Carbon::now()->toDateString() > $cutoffEnd;
    }
}
