<?php

namespace App\Services;

use App\Contracts\PayrollDayComputation;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\PayrollBreakdown;
use App\Models\PayrollPeriod;
use App\Models\RefundRequest;
use App\Models\User;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Refund & Payroll Recovery calculation.
 *
 * Architecture rule (see HRIS manual §Refunds): this service NEVER re-implements
 * attendance/OT/holiday/ND/payroll math. It re-runs the SAME pipeline used by payslips —
 * PayrollComputationService::computeDayPayroll() / computeEmployeePayroll() — once with the
 * payroll's persisted results ("as paid") and once with corrected inputs ("expected"),
 * then reports the per-component refundable difference.
 */
class RefundCalculationService
{
    /** Day-result keys treated as payroll components in the diff table. */
    private const COMPONENT_KEYS = [
        'regular_pay' => 'Basic Pay',
        'ot_pay' => 'Overtime',
        'nd_pay' => 'Night Differential',
        'holiday_premium_pay' => 'Holiday Pay',
        'paid_leave' => 'Leave Adjustments',
        'rest_day_worked_pay' => 'Rest Day Worked Pay',
    ];

    private const BREAKDOWN_COMPONENT_ALIASES = [
        'paid_leave' => ['paid_leave', 'paid_leave_daily_flat'],
    ];

    public function __construct(
        private readonly PayrollDayComputation $payrollComputation,
        private readonly PayrollFreezeService $payrollFreeze,
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * Validate a create/update payload and produce a full engine-backed preview.
     *
     * @param  array{employee_id:int, reason:string, affected_date:string, affected_date_to?:?string,
     *     cutoff_start_date?:?string, cutoff_end_date?:?string, direct_refund_amount?:float|numeric-string|null,
     *     correction_payload?:?array, manual_corrected_amount?:float|numeric-string|null, reason_notes?:?string}  $input
     * @return array{
     *   employee:User, category:string, direction:string, affected_date:string, affected_date_to:?string,
     *   cutoff_start_date:?string, cutoff_end_date:?string, days:list<array>, components:array<string,array>,
     *   original_amount:float, corrected_amount:float, refund_amount:float,
     *   finalized:bool, original_batch_run_id:?int, lock_message:?string,
     *   application_timing:?string, application_note:?string, warnings:list<string>
     * }
     */
    public function preview(User $actor, array $input): array
    {
        $employee = $this->resolveEmployee($actor, (int) $input['employee_id']);
        $reason = (string) $input['reason'];
        $this->assertReasonKnown($reason);

        $affectedDate = Carbon::parse((string) $input['affected_date'])->startOfDay();
        if ($affectedDate->isFuture()) {
            throw new InvalidArgumentException('Affected date cannot be in the future.');
        }
        $affectedDateTo = ! empty($input['affected_date_to'])
            ? Carbon::parse((string) $input['affected_date_to'])->startOfDay()
            : null;
        if ($affectedDateTo !== null && $affectedDateTo->lessThan($affectedDate)) {
            throw new InvalidArgumentException('Affected date range end cannot be before its start.');
        }
        if ($affectedDateTo !== null && $affectedDate->diffInDays($affectedDateTo) > 31) {
            throw new InvalidArgumentException('Affected date range cannot exceed 31 days.');
        }

        [$cutoffStart, $cutoffEnd] = $this->resolveCutoffWindow(
            $employee,
            $affectedDate,
            $affectedDateTo ?? $affectedDate,
            $input['cutoff_start_date'] ?? null,
            $input['cutoff_end_date'] ?? null
        );

        if (array_key_exists('direct_refund_amount', $input) && $input['direct_refund_amount'] !== null && $input['direct_refund_amount'] !== '') {
            return $this->previewDirectAmount(
                $employee,
                $reason,
                $affectedDate,
                $affectedDateTo,
                $cutoffStart,
                $cutoffEnd,
                (float) $input['direct_refund_amount']
            );
        }

        $payload = is_array($input['correction_payload'] ?? null) ? $input['correction_payload'] : [];
        $this->validateCorrectionPayload($reason, $payload, $employee, $affectedDate);

        $manualCorrectedAmount = isset($input['manual_corrected_amount']) && $input['manual_corrected_amount'] !== null
            ? round(max(0.0, (float) $input['manual_corrected_amount']), 2)
            : null;

        $days = [];
        $cursor = $affectedDate->copy();
        $lastDate = $affectedDateTo ?? $affectedDate;
        while ($cursor->lessThanOrEqualTo($lastDate)) {
            $days[] = $this->calculateDay($employee, $cursor->copy(), $reason, $payload);
            $cursor->addDay();
        }

        $components = [];
        foreach (array_keys(self::COMPONENT_KEYS) as $key) {
            $components[$key] = ['paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0];
        }
        foreach ($days as $day) {
            foreach ($day['components'] as $key => $amounts) {
                $components[$key]['paid'] += $amounts['paid'];
                $components[$key]['expected'] += $amounts['expected'];
                $components[$key]['difference'] += $amounts['difference'];
            }
        }
        foreach ($components as $key => $amounts) {
            $components[$key]['label'] = self::COMPONENT_KEYS[$key];
            $components[$key]['paid'] = round($amounts['paid'], 2);
            $components[$key]['expected'] = round($amounts['expected'], 2);
            $components[$key]['difference'] = round($amounts['difference'], 2);
        }

        $originalAmount = round(array_sum(array_column($days, 'paid_total')), 2);
        $correctedAmount = $manualCorrectedAmount !== null
            ? $originalAmount + max(0.0, $manualCorrectedAmount - $originalAmount)
            : round(array_sum(array_column($days, 'expected_total')), 2);

        // Manual corrected amount (leave/computation-error cases): expected total replaces the engine day sum,
        // and the difference is attributed to Basic Pay so the audit trail stays component-explicit.
        if ($manualCorrectedAmount !== null) {
            $manualDifference = round($manualCorrectedAmount - $originalAmount, 2);
            $components['regular_pay']['expected'] = round($originalAmount + $manualDifference, 2);
            $components['regular_pay']['difference'] = round(
                $components['regular_pay']['difference'] + $manualDifference,
                2
            );
            $correctedAmount = $manualCorrectedAmount;
        } else {
            $correctedAmount = round(array_sum(array_column($days, 'expected_total')), 2);
        }

        $refundAmount = round($correctedAmount - $originalAmount, 2);

        [$finalized, $batchRunId, $lockMessage] = $this->detectFinalizedPayroll($employee, $affectedDate, $lastDate);

        $warnings = [];
        if ($manualCorrectedAmount !== null) {
            $warnings[] = 'Manual corrected amount used — engine day computation shown for reference only.';
        }
        if ($finalized) {
            $warnings[] = $lockMessage.' The adjustment will be applied to the next eligible payroll; the finalized run is never modified.';
        }

        $applicationTiming = $finalized ? 'next_payroll' : 'current_window';
        $applicationNote = $finalized
            ? 'The original payroll for this date is finalized and locked. After approval, the difference is applied on the employee\'s next eligible payroll — as extra pay (underpayment) or a payroll recovery deduction (overpayment). The closed payslip is never changed.'
            : 'This adjustment applies to the payroll run whose pay window covers the affected date(s).';

        if (in_array($reason, [
            RefundRequest::REASON_MISSING_TIME_IN,
            RefundRequest::REASON_MISSING_TIME_OUT,
            RefundRequest::REASON_MISSING_ATTENDANCE,
            RefundRequest::REASON_INCORRECT_LATE_DEDUCTION,
            RefundRequest::REASON_INCORRECT_UNDERTIME_DEDUCTION,
        ], true) && $finalized) {
            $applicationNote = 'Missing or incorrect attendance on a finalized payroll is corrected here: the engine compares what was actually paid vs what should have been paid, then applies the difference on the next payroll run after approval.';
        }
        foreach ($days as $day) {
            foreach ($day['warnings'] as $warning) {
                if (! in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
            }
        }

        return [
            'employee' => $employee,
            'category' => RefundRequest::categoriesForReason($reason)[0],
            'direction' => $refundAmount > 0.004
                ? RefundRequest::DIRECTION_UNDERPAYMENT
                : ($refundAmount < -0.004 ? RefundRequest::DIRECTION_OVERPAYMENT : RefundRequest::DIRECTION_ADJUSTMENT),
            'affected_date' => $affectedDate->toDateString(),
            'affected_date_to' => $affectedDateTo?->toDateString(),
            'cutoff_start_date' => $cutoffStart->toDateString(),
            'cutoff_end_date' => $cutoffEnd->toDateString(),
            'days' => $days,
            'components' => $components,
            'original_amount' => $originalAmount,
            'corrected_amount' => $correctedAmount,
            'refund_amount' => abs($refundAmount),
            'refund_signed_amount' => $refundAmount,
            'finalized' => $finalized,
            'original_batch_run_id' => $batchRunId,
            'lock_message' => $lockMessage,
            'application_timing' => $applicationTiming,
            'application_note' => $applicationNote,
            'warnings' => $warnings,
        ];
    }

    public function snapshotForPersist(array $preview): array
    {
        return [
            'engine' => 'PayrollComputationService@computeDayPayroll',
            'computed_at' => now()->toIso8601String(),
            'days' => $preview['days'],
            'components' => $preview['components'],
            'original_amount' => $preview['original_amount'],
            'corrected_amount' => $preview['corrected_amount'],
            'refund_signed_amount' => $preview['refund_signed_amount'] ?? $preview['refund_amount'],
            'warnings' => $preview['warnings'],
            'finalized' => $preview['finalized'],
            'original_batch_run_id' => $preview['original_batch_run_id'],
            'application_timing' => $preview['application_timing'] ?? null,
            'application_note' => $preview['application_note'] ?? null,
        ];
    }

    private function previewDirectAmount(
        User $employee,
        string $reason,
        Carbon $affectedDate,
        ?Carbon $affectedDateTo,
        Carbon $cutoffStart,
        Carbon $cutoffEnd,
        float $directAmount
    ): array {
        $signedAmount = round($directAmount, 2);
        if (abs($signedAmount) <= 0.004) {
            throw new InvalidArgumentException('Adjustment amount must be greater than zero.');
        }

        [$finalized, $batchRunId, $lockMessage] = $this->detectFinalizedPayroll($employee, $cutoffStart, $cutoffEnd);

        $absoluteAmount = abs($signedAmount);
        $components = [
            'regular_pay' => [
                'label' => self::COMPONENT_KEYS['regular_pay'],
                'paid' => $signedAmount < 0 ? $absoluteAmount : 0.0,
                'expected' => $signedAmount > 0 ? $absoluteAmount : 0.0,
                'difference' => $signedAmount,
            ],
            'ot_pay' => ['label' => self::COMPONENT_KEYS['ot_pay'], 'paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0],
            'nd_pay' => ['label' => self::COMPONENT_KEYS['nd_pay'], 'paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0],
            'holiday_premium_pay' => ['label' => self::COMPONENT_KEYS['holiday_premium_pay'], 'paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0],
            'paid_leave' => ['label' => self::COMPONENT_KEYS['paid_leave'], 'paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0],
            'rest_day_worked_pay' => ['label' => self::COMPONENT_KEYS['rest_day_worked_pay'], 'paid' => 0.0, 'expected' => 0.0, 'difference' => 0.0],
        ];

        return [
            'employee' => $employee,
            'category' => RefundRequest::categoriesForReason($reason)[0],
            'direction' => $signedAmount > 0.004
                ? RefundRequest::DIRECTION_UNDERPAYMENT
                : RefundRequest::DIRECTION_OVERPAYMENT,
            'affected_date' => $affectedDate->toDateString(),
            'affected_date_to' => $affectedDateTo?->toDateString(),
            'cutoff_start_date' => $cutoffStart->toDateString(),
            'cutoff_end_date' => $cutoffEnd->toDateString(),
            'days' => [],
            'components' => $components,
            'original_amount' => $signedAmount < 0 ? $absoluteAmount : 0.0,
            'corrected_amount' => $signedAmount > 0 ? $absoluteAmount : 0.0,
            'refund_amount' => $absoluteAmount,
            'refund_signed_amount' => $signedAmount,
            'finalized' => $finalized,
            'original_batch_run_id' => $batchRunId,
            'lock_message' => $lockMessage,
            'application_timing' => $finalized ? 'next_payroll' : 'selected_payroll_cycle',
            'application_note' => $finalized
                ? 'The selected payroll cycle is already finalized and locked. After approval, this manual adjustment applies on the employee\'s next eligible payroll — as extra pay (positive amount) or a payroll recovery deduction (negative amount).'
                : 'This manual adjustment will be reflected on the selected payroll cycle. Enter a positive amount for a refund/additional pay, or a negative amount for a payroll recovery deduction.',
            'warnings' => $finalized
                ? array_values(array_filter([
                    'Manual amount mode: the selected payroll cycle is finalized; the amount applies on the next eligible payroll.',
                    $lockMessage,
                ]))
                : ['Manual amount mode: the entered amount is applied directly to the selected payroll cycle.'],
        ];
    }

    /**
     * Re-run one affected date through the shared pipeline for both scenarios.
     *
     * @return array{date:string, paid_source:string, paid_total:float, expected_total:float,
     *   components:array<string,array{paid:float,expected:float,difference:float}>, conditions:array, warnings:list<string>}
     */
    private function calculateDay(User $employee, Carbon $date, string $reason, array $payload): array
    {
        $tz = $this->payrollComputation->getTimezone();
        $context = $this->payrollComputation->resolveSingleDayComputationContext($employee, $date);
        $schedule = $context['effective_schedule'];
        $dailyRate = (float) $context['daily_rate'];
        $dateKey = $date->toDateString();

        $warnings = [];
        if ($dailyRate <= 0) {
            $warnings[] = "No daily rate could be resolved for {$dateKey}; amounts are zero.";
        }

        // Scenario A — what payroll actually paid. Prefer the immutable persisted breakdown from the
        // original run; fall back to a fresh single-day engine pass over stored attendance.
        $persisted = $this->findPersistedBreakdown($employee, $date);
        if ($persisted !== null) {
            $paid = [
                'total_pay' => (float) $persisted->total_pay,
                'regular_pay' => (float) $persisted->regular_pay,
                'ot_pay' => (float) $persisted->ot_pay,
                'nd_pay' => (float) $persisted->nd_pay,
                'holiday_premium_pay' => (float) $persisted->holiday_premium_pay,
                'conditions' => $persisted->conditions ?? [],
                'breakdown' => $persisted->breakdown ?? [],
            ];
            $paidSource = 'finalized_payroll';
        } else {
            $window = $this->payrollComputation->computeEmployeePayroll($employee, $date->copy(), $date->copy());
            $dayRow = null;
            foreach (($window['days'] ?? []) as $candidate) {
                if (($candidate['date'] ?? null) === $dateKey) {
                    $dayRow = $candidate;
                    break;
                }
            }
            $paid = [
                'total_pay' => (float) ($dayRow['total_pay'] ?? 0),
                'regular_pay' => (float) ($dayRow['regular_pay'] ?? 0),
                'ot_pay' => (float) ($dayRow['ot_pay'] ?? 0),
                'nd_pay' => (float) ($dayRow['nd_pay'] ?? 0),
                'holiday_premium_pay' => (float) ($dayRow['holiday_premium_pay'] ?? 0),
                'conditions' => $dayRow['conditions'] ?? [],
                'breakdown' => $dayRow['breakdown'] ?? [],
            ];
            $paidSource = 'current_engine';
        }

        // Scenario B — corrected inputs through the same computeDayPayroll pipeline.
        [$correctedIn, $correctedOut] = $this->resolveCorrectedTimes($payload, $date, $tz);
        $corrected = $this->payrollComputation->computeDayPayroll(
            $employee,
            $dateKey,
            $correctedIn,
            $correctedOut,
            $schedule,
            $dailyRate,
            $tz
        );

        $components = [];
        foreach (self::COMPONENT_KEYS as $key => $label) {
            $paidValue = $this->componentAmountFromDay($paid, $key);
            $expectedValue = $this->componentAmountFromDay($corrected, $key);
            $components[$key] = [
                'label' => $label,
                'paid' => $paidValue,
                'expected' => $expectedValue,
                'difference' => round($expectedValue - $paidValue, 2),
            ];
        }

        return [
            'date' => $dateKey,
            'rule_code' => $corrected['conditions']['rule_code'] ?? null,
            'holiday_name' => $corrected['conditions']['holiday_name'] ?? null,
            'is_rest_day' => (bool) ($corrected['is_rest_day'] ?? false),
            'paid_source' => $paidSource,
            'paid_total' => round((float) $paid['total_pay'], 2),
            'expected_total' => round((float) ($corrected['total_pay'] ?? 0), 2),
            'components' => $components,
            'conditions' => $corrected['conditions'] ?? [],
            'paid_breakdown' => $paid['breakdown'] ?? [],
            'corrected_breakdown' => $corrected['breakdown'] ?? [],
            'approved_overtime_items' => $corrected['approved_overtime_items'] ?? [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function resolveCorrectedTimes(array $payload, Carbon $date, string $tz): array
    {
        $timeIn = trim((string) ($payload['time_in'] ?? ''));
        $timeOut = trim((string) ($payload['time_out'] ?? ''));

        $in = $timeIn !== '' ? Carbon::parse($date->toDateString().' '.$timeIn, $tz) : null;
        $out = $timeOut !== ''
            ? Carbon::parse($date->toDateString().' '.$timeOut, $tz)
            : null;
        if ($in !== null && $out !== null && $out->lessThanOrEqualTo($in)) {
            // Night-shift convention used across the codebase: out <= in means next-day.
            $out->addDay();
        }

        return [$in, $out];
    }

    private function findPersistedBreakdown(User $employee, Carbon $date): ?PayrollBreakdown
    {
        return PayrollBreakdown::query()
            ->whereHas('payrollPeriod', function ($q) use ($employee) {
                $q->where('user_id', $employee->id);
            })
            ->whereDate('date', $date->toDateString())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveCutoffWindow(User $employee, Carbon $from, Carbon $to, mixed $explicitStart, mixed $explicitEnd): array
    {
        if (! empty($explicitStart) && ! empty($explicitEnd)) {
            return [
                Carbon::parse((string) $explicitStart)->startOfDay(),
                Carbon::parse((string) $explicitEnd)->startOfDay(),
            ];
        }

        $period = PayrollPeriod::query()
            ->where('user_id', $employee->id)
            ->whereDate('cut_off_start_date', '<=', $to->toDateString())
            ->whereDate('cut_off_end_date', '>=', $from->toDateString())
            ->orderByDesc('id')
            ->first();
        if ($period !== null && $period->cut_off_start_date && $period->cut_off_end_date) {
            return [$period->cut_off_start_date->copy()->startOfDay(), $period->cut_off_end_date->copy()->startOfDay()];
        }

        // Fallback: semi-monthly style window around the affected date.
        $start = $from->day <= 15 ? $from->copy()->startOfMonth() : $from->copy()->startOfMonth()->addDays(15);
        $end = $from->day <= 15 ? $from->copy()->startOfMonth()->addDays(14) : $from->copy()->endOfMonth();

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * Detect whether the affected window sits inside a FINALIZED payroll batch.
     *
     * @return array{0:bool,1:?int,2:?string}
     */
    private function detectFinalizedPayroll(User $employee, Carbon $from, Carbon $to): array
    {
        $freeze = $this->payrollFreeze->isWindowFrozenForEmployee((int) $employee->id, $from, $to);
        if ($freeze['frozen']) {
            $label = ! empty($freeze['period_start']) && ! empty($freeze['period_end'])
                ? "{$freeze['period_start']} → {$freeze['period_end']}"
                : $from->toDateString();

            return [
                true,
                $freeze['payroll_run_id'],
                "This payroll period has already been finalized and is locked ({$label}).",
            ];
        }

        return [false, null, null];
    }

    private function validateCorrectionPayload(string $reason, array $payload, User $employee, Carbon $affectedDate): void
    {
        $needsPunches = in_array($reason, [
            RefundRequest::REASON_MISSING_TIME_IN,
            RefundRequest::REASON_MISSING_TIME_OUT,
            RefundRequest::REASON_MISSING_ATTENDANCE,
            RefundRequest::REASON_INCORRECT_LATE_DEDUCTION,
            RefundRequest::REASON_INCORRECT_UNDERTIME_DEDUCTION,
        ], true);

        if ($needsPunches && empty($payload['time_in']) && empty($payload['time_out'])) {
            throw new InvalidArgumentException('Provide the corrected time in and/or time out for this attendance issue.');
        }

        if (in_array($reason, [RefundRequest::REASON_MISSING_OVERTIME, RefundRequest::REASON_INCORRECT_OVERTIME_PAY], true)) {
            $overtimeId = (int) ($payload['overtime_id'] ?? 0);
            if ($overtimeId <= 0) {
                throw new InvalidArgumentException(
                    'Select the approved overtime request this refund references. This module does not invent overtime.'
                );
            }
            $overtime = Overtime::query()
                ->whereKey($overtimeId)
                ->where('user_id', $employee->id)
                ->where('status', Overtime::STATUS_APPROVED)
                ->whereDate('date', $affectedDate->toDateString())
                ->first();
            if ($overtime === null) {
                throw new InvalidArgumentException(
                    'The selected overtime request is not an approved overtime on the affected date for this employee.'
                );
            }
        }

        if (in_array($reason, [RefundRequest::REASON_INCORRECT_LEAVE_PAY, RefundRequest::REASON_INCORRECT_LEAVE_DEDUCTION], true)) {
            $leaveId = (int) ($payload['leave_request_id'] ?? 0);
            if ($leaveId <= 0) {
                throw new InvalidArgumentException('Select the approved leave request this refund references.');
            }
            $leave = LeaveRequest::query()
                ->whereKey($leaveId)
                ->where('user_id', $employee->id)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->whereDate('start_date', '<=', $affectedDate->toDateString())
                ->whereDate('end_date', '>=', $affectedDate->toDateString())
                ->first();
            if ($leave === null) {
                throw new InvalidArgumentException(
                    'The selected leave request is not an approved leave covering the affected date for this employee.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function componentAmountFromDay(array $day, string $key): float
    {
        if (in_array($key, ['paid_leave', 'rest_day_worked_pay'], true)) {
            $aliases = self::BREAKDOWN_COMPONENT_ALIASES[$key] ?? [$key];

            return $this->sumBreakdownComponent(is_array($day['breakdown'] ?? null) ? $day['breakdown'] : [], $aliases);
        }

        return round((float) ($day[$key] ?? 0), 2);
    }

    /**
     * @param  list<array<string, mixed>>  $breakdown
     * @param  list<string>  $componentNames
     */
    private function sumBreakdownComponent(array $breakdown, array $componentNames): float
    {
        $total = 0.0;
        foreach ($breakdown as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $component = strtolower(trim((string) ($entry['component'] ?? '')));
            if (in_array($component, $componentNames, true)) {
                $total += (float) ($entry['amount'] ?? 0);
            }
        }

        return round($total, 2);
    }

    private function assertReasonKnown(string $reason): void
    {
        foreach (RefundRequest::reasonOptions() as $option) {
            if ($option['value'] === $reason) {
                return;
            }
        }
        throw new InvalidArgumentException("Unknown refund reason '{$reason}'.");
    }

    private function resolveEmployee(User $actor, int $employeeId): User
    {
        /** @var User|null $employee */
        $employee = User::query()->find($employeeId);
        if ($employee === null) {
            throw new InvalidArgumentException('Selected employee does not exist.');
        }
        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);

        return $employee;
    }
}
