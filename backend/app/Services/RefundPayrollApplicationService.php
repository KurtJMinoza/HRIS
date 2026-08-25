<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\RefundRequest;
use App\Models\User;
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

            $count = 0;
            $now = now();
            foreach ($refunds as $refund) {
                if (! $this->isEligibleForPayWindow($refund, $start, $end)) {
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
        $cutoffEnd = optional($refund->cutoff_end_date)->toDateString();

        if ($this->referencesFinalizedOriginalPayroll($refund) && $cutoffEnd !== null) {
            return $from > $cutoffEnd;
        }

        return $affectedFrom <= $to && $affectedTo >= $from;
    }

    private function referencesFinalizedOriginalPayroll(RefundRequest $refund): bool
    {
        if ($refund->original_payroll_batch_run_id) {
            return true;
        }

        return (bool) data_get($refund->calculation, 'finalized', false);
    }
}
