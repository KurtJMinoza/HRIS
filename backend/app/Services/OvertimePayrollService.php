<?php

namespace App\Services;

use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
    public function applyPayrollEligibleScope(
        Builder $query,
        ?int $payrollBatchRunId,
        ?int $companyId = null,
        ?int $assignmentId = null
    ): Builder
    {
        $query->where('status', Overtime::STATUS_APPROVED);

        if (Schema::hasColumn('overtimes', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

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

        if (($companyId !== null && $companyId > 0 && Schema::hasColumn('overtimes', 'company_id'))
            || ($assignmentId !== null && $assignmentId > 0 && Schema::hasColumn('overtimes', 'assignment_id'))) {
            $query->where(function (Builder $q) use ($companyId, $assignmentId): void {
                if ($companyId !== null && $companyId > 0 && Schema::hasColumn('overtimes', 'company_id')) {
                    $q->where('company_id', $companyId);
                }
                if ($assignmentId !== null && $assignmentId > 0 && Schema::hasColumn('overtimes', 'assignment_id')) {
                    $method = ($companyId !== null && $companyId > 0 && Schema::hasColumn('overtimes', 'company_id'))
                        ? 'orWhere'
                        : 'where';
                    $q->{$method}('assignment_id', $assignmentId);
                }
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
     * @return array{start_hour: int, end_hour: int, premium: float}
     */
    public function nightDifferentialConfig(?object $policy = null): array
    {
        $nd = $this->policyResolver->getNdConfig($policy);

        return [
            'start_hour' => (int) ($nd['start_hour'] ?? config('payroll.night_differential.start_hour', 22)),
            'end_hour' => (int) ($nd['end_hour'] ?? config('payroll.night_differential.end_hour', 6)),
            'premium' => (float) ($nd['premium_multiplier'] ?? config('payroll.night_differential.premium_multiplier', config('payroll.nd_premium', 0.10))),
        ];
    }

    /**
     * Approved OT start/end instants for ND overlap (schedule_end → expected_end_time).
     *
     * @param  array{in?: string, out?: string}|null  $daySchedule
     * @return array{start: Carbon, end: Carbon}|null
     */
    public function resolveOvertimeWindow(Overtime $ot, string $dateKey, ?array $daySchedule, string $tz): ?array
    {
        $start = null;
        if ($ot->schedule_end !== null) {
            $schedEnd = $ot->schedule_end;
            $timeStr = $schedEnd instanceof CarbonInterface
                ? $schedEnd->format('H:i:s')
                : trim((string) $schedEnd);
            if ($timeStr !== '' && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeStr) === 1) {
                $start = Carbon::parse($dateKey.' '.$timeStr, $tz);
            }
        }
        if ($start === null && is_array($daySchedule) && ! empty($daySchedule['out'])) {
            $start = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        }
        if ($start === null) {
            return null;
        }

        $end = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd($ot, $dateKey, $daySchedule, $tz);
        if ($end === null) {
            $mins = (int) ($ot->computed_minutes ?? 0);
            if ($mins <= 0 && isset($ot->computed_hours)) {
                $mins = (int) round((float) $ot->computed_hours * 60);
            }
            if ($mins > 0) {
                $end = $start->copy()->addMinutes($mins);
            }
        }
        if ($end !== null && ! $end->greaterThan($start)) {
            $end->addDay();
        }
        if ($end === null || ! $end->greaterThan($start)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Minutes of an interval that fall inside the configured ND window (10PM–6AM by default).
     */
    public function nightMinutesInInterval(Carbon $start, Carbon $end, string $tz, ?object $policy = null): int
    {
        $nd = $this->nightDifferentialConfig($policy);
        $ndStart = (int) $nd['start_hour'];
        $ndEnd = (int) $nd['end_hour'];

        $in = $start->copy()->timezone($tz);
        $out = $end->copy()->timezone($tz);
        if (! $out->greaterThan($in)) {
            return 0;
        }

        $nightMinutes = 0;
        $cursor = $in->copy();
        $totalMinutes = (int) $in->diffInMinutes($out);
        for ($m = 0; $m < $totalMinutes; $m++) {
            $hour = (int) $cursor->format('G');
            if ($hour >= $ndStart || $hour < $ndEnd) {
                $nightMinutes++;
            }
            $cursor->addMinute();
        }

        return $nightMinutes;
    }

    /**
     * @param  list<Overtime>  $records
     * @return array{
     *   approved_hours: float,
     *   payable_hours: float,
     *   ot_pay: float,
     *   nd_hours: float,
     *   nd_minutes: int,
     *   nd_pay: float,
     *   total_premium: float,
     *   items: list<array<string, mixed>>,
     *   overtime_ids: list<int>
     * }
     */
    public function computeCompensationFromRecords(
        array $records,
        float $hourlyRate,
        ?object $policy,
        string $fallbackRuleCode,
        int $renderedOtMinutes = 0,
        ?string $dateKey = null,
        ?array $daySchedule = null,
        ?string $tz = null
    ): array {
        $tz = $tz ?? (string) config('payroll.timezone', config('attendance.timezone', 'Asia/Manila'));
        $approvedMinutes = 0;
        $otPay = 0.0;
        $ndMinutes = 0;
        $ndPay = 0.0;
        $items = [];
        $ids = [];
        $ndConfig = $this->nightDifferentialConfig($policy);

        $seenIds = [];
        foreach ($records as $ot) {
            if (! $ot instanceof Overtime) {
                continue;
            }
            $otId = (int) $ot->id;
            if ($otId <= 0 || isset($seenIds[$otId])) {
                continue;
            }
            $seenIds[$otId] = true;
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
            $ndRate = (float) ($multipliers['nd_addon'] ?? $ndConfig['premium']);
            $linePay = round($hours * $hourlyRate * $otMult, 2);
            $otPay += $linePay;
            $ids[] = $otId;

            $workDateKey = $dateKey
                ?? ($ot->date instanceof Carbon
                    ? $ot->date->toDateString()
                    : Carbon::parse((string) $ot->date)->toDateString());
            $window = $this->resolveOvertimeWindow($ot, $workDateKey, $daySchedule, $tz);
            $lineNdMinutes = 0;
            $lineNdPay = 0.0;
            $overlapStart = null;
            $overlapEnd = null;
            if ($window !== null) {
                $lineNdMinutes = $this->nightMinutesInInterval($window['start'], $window['end'], $tz, $policy);
                if ($lineNdMinutes > 0) {
                    $lineNdPay = round(($lineNdMinutes / 60.0) * $hourlyRate * $otMult * $ndRate, 2);
                    $ndMinutes += $lineNdMinutes;
                    $ndPay += $lineNdPay;
                    $overlapStart = $this->firstNightOverlapInstant($window['start'], $window['end'], $tz, $policy);
                    $overlapEnd = $overlapStart !== null
                        ? $overlapStart->copy()->addMinutes($lineNdMinutes)
                        : null;
                }
            }

            $items[] = [
                'type' => 'earning',
                'category' => 'overtime_pay',
                'source' => 'overtime_request',
                'source_id' => $otId,
                'overtime_id' => $otId,
                'overtime_request_id' => $otId,
                'employee_id' => (int) ($ot->user_id ?? 0),
                'date' => $workDateKey,
                'hours' => round($hours, 2),
                'ot_type' => $ot->ot_type,
                'ph_ot_rule' => $ruleCode,
                'approved_hours' => round($hours, 2),
                'hourly_rate' => round($hourlyRate, 4),
                'multiplier' => round($otMult, 4),
                'ot_multiplier' => round($otMult, 4),
                'amount' => $linePay,
                'ot_pay' => $linePay,
                'nd_hours' => round($lineNdMinutes / 60, 2),
                'nd_minutes' => $lineNdMinutes,
                'nd_pay' => $lineNdPay,
                'nd_rate' => round($ndRate, 4),
                'ot_start_time' => $window !== null ? $window['start']->format('H:i:s') : null,
                'ot_end_time' => $window !== null ? $window['end']->format('H:i:s') : null,
                'nd_window_start' => sprintf('%02d:00', (int) $ndConfig['start_hour']),
                'nd_window_end' => sprintf('%02d:00', (int) $ndConfig['end_hour']),
                'nd_overlap_start' => $overlapStart?->format('H:i:s'),
                'nd_overlap_end' => $overlapEnd?->format('H:i:s'),
                'total_premium' => round($linePay + $lineNdPay, 2),
                'payslip_nd_line_created' => $lineNdMinutes > 0,
            ];

            if ($window !== null) {
                $this->logApprovedOvertimeNdDebug($ot, $workDateKey, $window, $hours, $hourlyRate, $otMult, $ndRate, $linePay, $lineNdMinutes, $lineNdPay, $ndConfig, $overlapStart, $overlapEnd);
            }
        }

        $approvedMinutes = max(0, $approvedMinutes);
        $payableMinutes = $this->resolvePayableOtMinutes($renderedOtMinutes, $approvedMinutes);
        $payableHours = $payableMinutes / 60.0;
        $approvedHours = $approvedMinutes / 60.0;

        if ($this->payableBasis() !== 'approved' && $approvedMinutes > 0 && $payableMinutes < $approvedMinutes) {
            $scale = $payableMinutes / $approvedMinutes;
            $otPay = round($otPay * $scale, 2);
            $ndPay = round($ndPay * $scale, 2);
            $ndMinutes = (int) round($ndMinutes * $scale);
            foreach ($items as &$item) {
                $item['payable_hours'] = round(((float) $item['approved_hours']) * $scale, 2);
                $item['amount'] = round(((float) $item['amount']) * $scale, 2);
                $item['ot_pay'] = $item['amount'];
                $scaledNdMinutes = (int) round(((int) ($item['nd_minutes'] ?? 0)) * $scale);
                $item['nd_minutes'] = $scaledNdMinutes;
                $item['nd_hours'] = round($scaledNdMinutes / 60, 2);
                $item['nd_pay'] = round(((float) ($item['nd_pay'] ?? 0)) * $scale, 2);
                $item['total_premium'] = round(((float) ($item['amount'] ?? 0)) + ((float) ($item['nd_pay'] ?? 0)), 2);
                $item['payslip_nd_line_created'] = $scaledNdMinutes > 0;
            }
            unset($item);
        } else {
            foreach ($items as &$item) {
                $item['payable_hours'] = $item['approved_hours'];
            }
            unset($item);
        }

        $ndLineItems = [];
        foreach ($items as $item) {
            if ((int) ($item['nd_minutes'] ?? 0) <= 0) {
                continue;
            }
            $ndLineItems[] = [
                'type' => 'earning',
                'category' => 'night_differential',
                'source' => 'overtime_request',
                'source_id' => (int) ($item['overtime_id'] ?? 0),
                'overtime_id' => (int) ($item['overtime_id'] ?? 0),
                'overtime_request_id' => (int) ($item['overtime_id'] ?? 0),
                'date' => $item['date'] ?? null,
                'hours' => (float) ($item['nd_hours'] ?? 0),
                'amount' => (float) ($item['nd_pay'] ?? 0),
            ];
        }

        return [
            'approved_hours' => round($approvedHours, 2),
            'payable_hours' => round($payableHours, 2),
            'ot_pay' => round($otPay, 2),
            'nd_hours' => round($ndMinutes / 60, 2),
            'nd_minutes' => $ndMinutes,
            'nd_pay' => round($ndPay, 2),
            'total_premium' => round($otPay + $ndPay, 2),
            'items' => $items,
            'nd_items' => $ndLineItems,
            'overtime_ids' => $ids,
        ];
    }

    /**
     * First minute inside both the OT interval and the ND window (for audit logs).
     */
    private function firstNightOverlapInstant(Carbon $start, Carbon $end, string $tz, ?object $policy): ?Carbon
    {
        $nd = $this->nightDifferentialConfig($policy);
        $ndStart = (int) $nd['start_hour'];
        $ndEnd = (int) $nd['end_hour'];
        $cursor = $start->copy()->timezone($tz);
        $totalMinutes = (int) $start->diffInMinutes($end);
        for ($m = 0; $m < $totalMinutes; $m++) {
            $hour = (int) $cursor->format('G');
            if ($hour >= $ndStart || $hour < $ndEnd) {
                return $cursor->copy();
            }
            $cursor->addMinute();
        }

        return null;
    }

    /**
     * @param  array{start: Carbon, end: Carbon}  $window
     * @param  array{start_hour: int, end_hour: int, premium: float}  $ndConfig
     */
    private function logApprovedOvertimeNdDebug(
        Overtime $ot,
        string $dateKey,
        array $window,
        float $approvedHours,
        float $hourlyRate,
        float $otMult,
        float $ndRate,
        float $otPay,
        int $ndMinutes,
        float $ndPay,
        array $ndConfig,
        ?Carbon $overlapStart,
        ?Carbon $overlapEnd
    ): void {
        Log::debug('approved_overtime_night_differential', [
            'overtime_request_id' => (int) $ot->id,
            'employee_id' => (int) ($ot->user_id ?? 0),
            'date' => $dateKey,
            'ot_start_time' => $window['start']->format('H:i:s'),
            'ot_end_time' => $window['end']->format('H:i:s'),
            'approved_ot_hours' => round($approvedHours, 2),
            'nd_window_start' => sprintf('%02d:00', (int) $ndConfig['start_hour']),
            'nd_window_end' => sprintf('%02d:00', (int) $ndConfig['end_hour']),
            'nd_overlap_start' => $overlapStart?->format('H:i:s'),
            'nd_overlap_end' => $overlapEnd?->format('H:i:s'),
            'nd_hours' => round($ndMinutes / 60, 2),
            'hourly_rate' => round($hourlyRate, 4),
            'ot_multiplier' => round($otMult, 4),
            'nd_rate' => round($ndRate, 4),
            'ot_pay' => round($otPay, 2),
            'nd_pay' => round($ndPay, 2),
            'total_premium' => round($otPay + $ndPay, 2),
            'payslip_nd_line_created' => $ndMinutes > 0,
        ]);
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
        ?int $companyId = null,
        ?int $assignmentId = null,
        ?array $prefetchedRecords = null,
        ?array $daySchedule = null,
        ?string $tz = null
    ): array {
        $records = $prefetchedRecords ?? $this->fetchApprovedForUserDate(
            (int) $user->id,
            $dateKey,
            $companyId ?? $user->getEffectiveCompanyId(),
            $payrollBatchRunId,
            $assignmentId
        );

        return $this->computeCompensationFromRecords(
            $records,
            $hourlyRate,
            $policy,
            $fallbackRuleCode,
            $renderedOtMinutes,
            $dateKey,
            $daySchedule,
            $tz
        );
    }

    /**
     * @return list<Overtime>
     */
    public function fetchApprovedForUserDate(
        int $userId,
        string $dateKey,
        ?int $companyId,
        ?int $payrollBatchRunId,
        ?int $assignmentId = null
    ): array {
        if (! Schema::hasTable('overtimes')) {
            return [];
        }

        $query = Overtime::query()
            ->where('user_id', $userId)
            ->whereDate('date', $dateKey)
            ->orderBy('id');

        $this->applyPayrollEligibleScope($query, $payrollBatchRunId, $companyId, $assignmentId);

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
     * @param  list<int>  $overtimeIds
     */
    public function markIncludedIdsAsPaid(int $payrollBatchRunId, array $overtimeIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $overtimeIds), fn (int $id): bool => $id > 0)));
        if ($payrollBatchRunId <= 0 || $ids === [] || ! Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
            return 0;
        }

        $updated = Overtime::query()
            ->whereIn('id', $ids)
            ->where(function (Builder $q) use ($payrollBatchRunId): void {
                $q->whereNull('paid_payroll_run_id')
                    ->orWhere('paid_payroll_run_id', $payrollBatchRunId);
            })
            ->update([
                'paid_payroll_run_id' => $payrollBatchRunId,
                'paid_at' => now(),
            ]);

        Log::info('payroll_overtime_marked_paid_by_snapshot', [
            'payroll_run_id' => $payrollBatchRunId,
            'overtime_ids' => $ids,
            'overtime_rows_marked' => $updated,
        ]);

        return (int) $updated;
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
        ?int $payrollBatchRunId,
        ?array $assignmentIdsByUser = null
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

        $this->applyPayrollEligibleScope($query, $payrollBatchRunId, null);

        $byKey = [];
        foreach ($query->get() as $ot) {
            $assignmentId = (int) ($ot->assignment_id ?? 0);
            $expectedAssignmentId = $assignmentIdsByUser[(int) $ot->user_id] ?? null;
            $companyMatches = $companyId !== null
                && $companyId > 0
                && (int) ($ot->company_id ?? 0) === $companyId;
            $assignmentMatches = $expectedAssignmentId !== null
                && $expectedAssignmentId > 0
                && $assignmentId === $expectedAssignmentId;
            if ($companyId !== null && $companyId > 0 && ! $companyMatches && ! $assignmentMatches) {
                continue;
            }
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
        ?int $companyId,
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
            'company_id' => $companyId,
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
