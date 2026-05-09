<?php

namespace App\Services;

use App\Models\DeductionScheduleSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Central HR-configured rules for when employee deductions are withheld (15th / end-of-month / split).
 * Used for payroll previews and daily computation summaries — not a substitute for statutory remittance calendars.
 */
class DeductionScheduleService
{
    public function __construct(
        private readonly PayCycleService $payCycleService,
    ) {}

    /** @var array<int, string> */
    private const GOVERNMENT_COMPONENT_CODES = ['SSS', 'PHILHEALTH', 'PAGIBIG', 'WITHHOLDING_TAX'];

    public function timezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    public function resolveScheduleType(string $deductionKey, ?int $companyId): string
    {
        $row = DeductionScheduleSetting::query()
            ->where('deduction_key', $deductionKey)
            ->when($companyId !== null, fn ($q) => $q->where(function ($sub) use ($companyId) {
                $sub->where('company_id', $companyId)->orWhereNull('company_id');
            }), fn ($q) => $q->whereNull('company_id'))
            // Prefer company-specific row over global fallback (company_id null).
            ->orderByRaw('CASE WHEN company_id IS NOT NULL THEN 0 ELSE 1 END')
            ->first();

        if ($row && in_array($row->schedule_type, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH,
        ], true)) {
            return $row->schedule_type;
        }

        return DeductionScheduleSetting::SCHEDULE_BOTH;
    }

    /**
     * Semi-monthly period from calendar day: days 1–15 = first half, 16–end = second half.
     *
     * @return 'first'|'second'
     */
    public function semiMonthlyPeriodForDate(Carbon $date): string
    {
        $d = $date->copy()->timezone($this->timezone())->day;

        return $d <= 15 ? 'first' : 'second';
    }

    /**
     * Fraction of the full monthly amount that applies to this semi-monthly run (0, 0.5, or 1).
     */
    public function prorationFactorForMonthlyAmount(string $scheduleType, Carbon $referenceDate): float
    {
        $period = $this->semiMonthlyPeriodForDate($referenceDate);
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 0.5;
        }
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_15TH) {
            return $period === 'first' ? 1.0 : 0.0;
        }
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_30TH) {
            return $period === 'second' ? 1.0 : 0.0;
        }

        return 1.0;
    }

    /**
     * Segment-aware proration that prefers pay-cycle context over calendar-day heuristics.
     *
     * @param  'first'|'second'|null  $segment
     */
    public function prorationFactorForMonthlyAmountBySegment(string $scheduleType, ?string $segment): float
    {
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 0.5;
        }
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_15TH) {
            return $segment === 'first' ? 1.0 : 0.0;
        }
        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_30TH) {
            return $segment === 'second' ? 1.0 : 0.0;
        }

        return 1.0;
    }

    /**
     * Resolve deduction dates for a concrete payroll period (past/current/future).
     *
     * @return array{
     *   schedule_type: string,
     *   pay_period_start: string,
     *   pay_period_end: string,
     *   dates: list<string>,
     *   period_rows: list<array<string, mixed>>
     * }
     */
    public function getDeductionDatesForPeriod(
        User $employee,
        string $scheduleType,
        Carbon|string $payPeriodStart,
        Carbon|string $payPeriodEnd
    ): array {
        $start = $payPeriodStart instanceof Carbon
            ? $payPeriodStart->copy()->timezone($this->timezone())->startOfDay()
            : Carbon::parse((string) $payPeriodStart, $this->timezone())->startOfDay();
        $end = $payPeriodEnd instanceof Carbon
            ? $payPeriodEnd->copy()->timezone($this->timezone())->startOfDay()
            : Carbon::parse((string) $payPeriodEnd, $this->timezone())->startOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $window = $this->payCycleService->getPayDatesForPeriod(
            $start->copy()->subMonthsNoOverflow(1),
            $end->copy()->addMonthsNoOverflow(1),
            null,
            $employee
        );
        $periodRows = collect($window['periods'] ?? [])->filter(function ($row) use ($start, $end) {
            $rowStart = Carbon::parse((string) ($row['cut_off_start_date'] ?? ''), $this->timezone())->startOfDay();
            $rowEnd = Carbon::parse((string) ($row['cut_off_end_date'] ?? ''), $this->timezone())->startOfDay();

            return $rowStart->isSameDay($start) && $rowEnd->isSameDay($end);
        })->values();

        $normalizedSchedule = in_array($scheduleType, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH,
        ], true) ? $scheduleType : DeductionScheduleSetting::SCHEDULE_BOTH;

        $nextWindow = $this->payCycleService->getPayDatesForPeriod($start, $end->copy()->addMonthsNoOverflow(1), null, $employee);
        $inScope = collect($nextWindow['periods'] ?? [])
            ->filter(function ($row) use ($start) {
                $payDate = Carbon::parse((string) ($row['pay_date'] ?? ''), $this->timezone())->startOfDay();

                return $payDate->gte($start);
            })
            ->values();

        $dates = collect();
        if ($normalizedSchedule === DeductionScheduleSetting::SCHEDULE_BOTH) {
            $dates = $inScope->pluck('pay_date')->take(2);
        } elseif ($normalizedSchedule === DeductionScheduleSetting::SCHEDULE_15TH) {
            $first = $inScope->first(fn ($row) => ($row['semi_month_segment'] ?? null) === 'first') ?? $inScope->first();
            if ($first) {
                $dates = collect([(string) $first['pay_date']]);
            }
        } else {
            $second = $inScope->first(fn ($row) => ($row['semi_month_segment'] ?? null) === 'second')
                ?? $inScope->slice(1, 1)->first()
                ?? $inScope->first();
            if ($second) {
                $dates = collect([(string) $second['pay_date']]);
            }
        }

        return [
            'schedule_type' => $normalizedSchedule,
            'pay_period_start' => $start->toDateString(),
            'pay_period_end' => $end->toDateString(),
            'dates' => $dates->filter()->unique()->values()->all(),
            'period_rows' => $periodRows->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $statutory  Output of {@see PayrollCalculatorService::calculateAllStatutoryContributions}
     * @return array{
     *   period: string,
     *   reference_date: string,
     *   lines: list<array{key: string, label: string, schedule_type: string, full_monthly_employee: float, this_period_employee: float}>,
     *   employee_statutory_this_period: float,
     *   withholding_full_monthly: float,
     *   withholding_this_period: float,
     * }
     */
    public function buildGovernmentSchedulePreview(
        User $user,
        Carbon $referenceDate,
        array $statutory,
        float $withholdingMonthlyFull,
        ?array $payCyclePreview = null,
        ?string $segmentOverride = null,
    ): array {
        $companyId = $user->getEffectiveCompanyId();
        $ref = $referenceDate->copy()->timezone($this->timezone())->startOfDay();
        $segment = in_array($segmentOverride, ['first', 'second'], true)
            ? $segmentOverride
            : $this->resolveSemiMonthSegmentForPayrollContext($user, $ref, $payCyclePreview);
        $resolvedPeriod = $segment ?? $this->semiMonthlyPeriodForDate($ref);

        $lines = [];
        $map = [
            DeductionScheduleSetting::GOV_SSS => ['label' => 'SSS (employee)', 'amount' => (float) ($statutory['sss']['employee_amount'] ?? 0)],
            DeductionScheduleSetting::GOV_PHILHEALTH => ['label' => 'PhilHealth (employee)', 'amount' => (float) ($statutory['philhealth']['employee_amount'] ?? 0)],
            DeductionScheduleSetting::GOV_PAGIBIG => ['label' => 'Pag-IBIG (employee)', 'amount' => (float) ($statutory['pagibig']['employee_amount'] ?? 0)],
        ];

        $totalThisPeriod = 0.0;
        foreach ($map as $key => $meta) {
            $sched = $this->resolveScheduleType($key, $companyId);
            $factor = $segment
                ? $this->prorationFactorForMonthlyAmountBySegment($sched, $segment)
                : $this->prorationFactorForMonthlyAmount($sched, $ref);
            $full = round($meta['amount'], 2);
            $thisPeriod = round($full * $factor, 2);
            $totalThisPeriod += $thisPeriod;
            $lines[] = [
                'key' => $key,
                'label' => $meta['label'],
                'schedule_type' => $sched,
                'full_monthly_employee' => $full,
                'this_period_employee' => $thisPeriod,
            ];
        }

        $whSched = $this->resolveScheduleType(DeductionScheduleSetting::GOV_WITHHOLDING, $companyId);
        $whFactor = $segment
            ? $this->prorationFactorForMonthlyAmountBySegment($whSched, $segment)
            : $this->prorationFactorForMonthlyAmount($whSched, $ref);
        $withholdingThis = round(max(0.0, $withholdingMonthlyFull) * $whFactor, 2);

        return [
            'period' => $resolvedPeriod,
            'reference_date' => $ref->toDateString(),
            'lines' => $lines,
            'employee_statutory_this_period' => round($totalThisPeriod, 2),
            'withholding_schedule_type' => $whSched,
            'withholding_full_monthly' => round($withholdingMonthlyFull, 2),
            'withholding_this_period' => $withholdingThis,
        ];
    }

    /**
     * Human-readable deduction lines for payslip / payroll preview (15th vs 30th split when schedule is "both").
     *
     * @param  array<string, mixed>  $governmentPreview  Output of {@see self::buildGovernmentSchedulePreview}
     * @return list<array{key: string, label: string, display: string}>
     */
    public function buildPayslipDeductionDisplayLines(array $governmentPreview, float $withholdingMonthlyFull): array
    {
        $out = [];
        foreach ($governmentPreview['lines'] ?? [] as $line) {
            $short = trim((string) preg_replace('/\s*\(employee\)\s*/i', '', (string) ($line['label'] ?? '')));
            $full = (float) ($line['full_monthly_employee'] ?? 0);
            $thisPeriod = (float) ($line['this_period_employee'] ?? 0);
            $sched = (string) ($line['schedule_type'] ?? DeductionScheduleSetting::SCHEDULE_BOTH);
            $out[] = [
                'key' => (string) ($line['key'] ?? ''),
                'label' => $short,
                'amount' => round(max(0.0, $thisPeriod), 2),
                'display' => $this->formatPayslipDeductionLineDisplay($short, $full, $sched),
            ];
        }
        $whSched = (string) ($governmentPreview['withholding_schedule_type'] ?? DeductionScheduleSetting::SCHEDULE_BOTH);
        $whThisPeriod = (float) ($governmentPreview['withholding_this_period'] ?? 0);
        $out[] = [
            'key' => DeductionScheduleSetting::GOV_WITHHOLDING,
            'label' => 'Withholding tax',
            'amount' => round(max(0.0, $whThisPeriod), 2),
            'display' => $this->formatPayslipDeductionLineDisplay('Withholding tax', max(0.0, $withholdingMonthlyFull), $whSched),
        ];

        return $out;
    }

    /**
     * Loan and other employee deductions (from {@see summarizeForPayrollComputation} `custom_lines`) with 15th/30th split text.
     * Government lines use {@see buildPayslipDeductionDisplayLines}; this covers HR-assigned rows so payslips can show e.g. loan splits.
     *
     * @param  list<array<string, mixed>>  $customLines
     * @return list<array{label: string, display: string, deduction_schedule_type: string}>
     */
    public function buildPayslipCustomDeductionDisplayLines(array $customLines): array
    {
        $out = [];
        foreach ($customLines as $line) {
            $rawName = trim((string) ($line['name'] ?? ''));
            if ($rawName === '') {
                $rawName = trim((string) ($line['code'] ?? '')) ?: 'Deduction';
            }
            $short = trim((string) preg_replace('/\s*\(Remaining:.*$/i', '', $rawName));
            if ($short === '') {
                $short = $rawName;
            }
            $full = (float) ($line['applied_this_period'] ?? $line['scheduled_this_period'] ?? $line['computed_amount'] ?? 0);
            $sched = $this->normalizeScheduleType((string) ($line['deduction_schedule_type'] ?? DeductionScheduleSetting::SCHEDULE_BOTH))
                ?? DeductionScheduleSetting::SCHEDULE_BOTH;
            $out[] = [
                'label' => $rawName,
                'amount' => round(max(0.0, $full), 2),
                'deduction_schedule_type' => $sched,
                'priority_order' => (int) ($line['priority_order'] ?? 0),
                'priority_bucket' => $line['priority_bucket'] ?? null,
                'legal_warning' => $line['legal_warning'] ?? null,
                'display' => $this->formatPayslipDeductionLineDisplay($short, $full, $sched),
            ];
        }

        return $out;
    }

    /**
     * Human-readable earning lines for payslip / payroll preview (15th vs 30th split when schedule is "both").
     *
     * @param  list<array<string, mixed>>  $earningLines  Rows from {@see self::summarizeForPayrollComputation} `earning_lines` (non-basic only recommended for display).
     * @return list<array{key: string, label: string, display: string, schedule_type: string}>
     */
    public function buildPayslipEarningDisplayLines(array $earningLines): array
    {
        $out = [];
        foreach ($earningLines as $line) {
            if (! empty($line['is_basic_salary_line'])) {
                continue;
            }
            $label = trim((string) ($line['name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($line['code'] ?? '')) ?: 'Earning';
            }
            $fullMonthly = (float) ($line['computed_amount'] ?? 0);
            $sched = $this->normalizeScheduleType((string) ($line['earning_schedule_type'] ?? DeductionScheduleSetting::SCHEDULE_BOTH))
                ?? DeductionScheduleSetting::SCHEDULE_BOTH;
            // Use the prorated amount for the current pay period (50/50 split, 15th-only, 30th-only).
            // Falls back to full monthly only when scheduled_this_period is absent (legacy data).
            $thisPeriod = array_key_exists('scheduled_this_period', $line)
                ? (float) $line['scheduled_this_period']
                : $fullMonthly;
            $pcId = $line['pay_component_id'] ?? null;
            $key = $pcId ? 'pay_component:'.((int) $pcId) : 'earning:'.md5($label);
            $row = [
                'key' => $key,
                'label' => $label,
                'schedule_type' => $sched,
                'amount' => round(max(0.0, $thisPeriod), 2),
                'full_monthly' => round(max(0.0, $fullMonthly), 2),
                'display' => $this->formatPayslipEarningLineDisplay($label, $fullMonthly, $sched),
            ];
            if (is_array($line['allowance_proration'] ?? null)) {
                $row['allowance_proration'] = $line['allowance_proration'];
            }
            $out[] = $row;
        }

        return $out;
    }

    private function formatPayslipEarningLineDisplay(string $label, float $fullMonthly, string $sched): string
    {
        $fullMonthly = round(max(0.0, $fullMonthly), 2);
        if ($sched === DeductionScheduleSetting::SCHEDULE_BOTH) {
            $half = round($fullMonthly / 2, 2);

            return sprintf(
                '%s (15th): ₱%s | %s (30th): ₱%s',
                $label,
                number_format($half, 2),
                $label,
                number_format($half, 2)
            );
        }
        if ($sched === DeductionScheduleSetting::SCHEDULE_15TH) {
            return sprintf('%s (15th): ₱%s', $label, number_format($fullMonthly, 2));
        }

        return sprintf('%s (30th): ₱%s', $label, number_format($fullMonthly, 2));
    }

    private function formatPayslipDeductionLineDisplay(string $short, float $fullMonthly, string $sched): string
    {
        $fullMonthly = round(max(0.0, $fullMonthly), 2);
        if ($sched === DeductionScheduleSetting::SCHEDULE_BOTH) {
            $half = round($fullMonthly / 2, 2);

            return sprintf(
                '%s 15th: ₱%s | %s 30th: ₱%s',
                $short,
                number_format($half, 2),
                $short,
                number_format($half, 2)
            );
        }
        if ($sched === DeductionScheduleSetting::SCHEDULE_15TH) {
            return sprintf('%s (15th): ₱%s', $short, number_format($fullMonthly, 2));
        }

        return sprintf('%s (30th): ₱%s', $short, number_format($fullMonthly, 2));
    }

    private function normalizeScheduleType(mixed $value): ?string
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

    /**
     * Applies HR deduction schedules to compensation summary amounts for payroll preview (semi-monthly 15th vs 30th vs split).
     *
     * @param  array<string, mixed>  $compensationSummary  Output of {@see PayrollCalculatorService::buildEmployeeCompensationSummary}
     * @return array<string, mixed>
     */
    public function summarizeForPayrollComputation(User $user, Carbon $referenceDate, array $compensationSummary): array
    {
        $companyId = $user->getEffectiveCompanyId();
        $statutory = $compensationSummary['statutory'] ?? [];
        $withholdingMonthly = (float) ($compensationSummary['totals']['withholding_tax'] ?? 0);
        if ($withholdingMonthly <= 0 && isset($compensationSummary['withholding']['withholding_per_month'])) {
            $withholdingMonthly = (float) $compensationSummary['withholding']['withholding_per_month'];
        }
        $ref = $referenceDate->copy()->timezone($this->timezone())->startOfDay();
        $payCyclePreview = is_array($compensationSummary['pay_cycle_preview'] ?? null) ? $compensationSummary['pay_cycle_preview'] : null;
        $periodStartRaw = $compensationSummary['pay_period_start'] ?? null;
        $periodEndRaw = $compensationSummary['pay_period_end'] ?? null;
        $selectedPayDate = ! empty($compensationSummary['selected_pay_date'])
            ? Carbon::parse((string) $compensationSummary['selected_pay_date'], $this->timezone())->startOfDay()
            : $ref;
        $segment = $this->inferSemiMonthSegmentFromPayDate($selectedPayDate)
            ?? $this->resolveSemiMonthSegmentForPayrollContext($user, $selectedPayDate, $payCyclePreview);
        $gov = $this->buildGovernmentSchedulePreview($user, $selectedPayDate, $statutory, $withholdingMonthly, $payCyclePreview, $segment);

        $attendanceProration = $compensationSummary['_attendance_proration'] ?? null;
        $attendanceFactor = $this->normalizeAttendanceProrationFactor($attendanceProration);

        $customFull = 0.0;
        $customThisPeriod = 0.0;
        $customLines = [];
        foreach ($compensationSummary['deductions'] ?? [] as $d) {
            $code = strtoupper(trim((string) ($d['code'] ?? '')));
            // Prevent duplicate statutory/withholding rows in payroll totals and payslip breakdown.
            if ($code !== '' && in_array($code, self::GOVERNMENT_COMPONENT_CODES, true)) {
                continue;
            }
            $amt = (float) ($d['computed_amount'] ?? 0);
            $customFull += $amt;
            $pcId = $d['pay_component_id'] ?? null;
            $metaSched = $this->normalizeScheduleType(data_get($d, 'metadata.deduction_schedule'));
            if ($metaSched !== null) {
                $sched = $metaSched;
            } elseif ($pcId) {
                $sched = $this->resolveScheduleType('pay_component:'.((int) $pcId), $companyId);
            } else {
                $sched = DeductionScheduleSetting::SCHEDULE_BOTH;
            }
            $amortizedInstallment = $this->resolveAmortizedInstallmentForPayrollContext(
                $d,
                $selectedPayDate,
                $periodEndRaw
            );
            if ($amortizedInstallment !== null) {
                $thisAmt = $amortizedInstallment;
            } else {
                $factor = $this->resolvePeriodAwareFactor($user, $sched, $segment, $ref, $selectedPayDate, $periodStartRaw, $periodEndRaw);
                $thisAmt = round($amt * $factor, 2);
                if (! empty($d['is_proratable'])) {
                    $thisAmt = round($thisAmt * $attendanceFactor, 2);
                }
            }
            $customThisPeriod += $thisAmt;
            $customLines[] = array_merge($d, [
                'deduction_schedule_type' => $sched,
                'scheduled_this_period' => $thisAmt,
                'attendance_proration_factor' => (! empty($d['is_proratable']) && $amortizedInstallment === null)
                    ? $attendanceFactor
                    : 1.0,
            ]);
        }

        $nonBasicEarningsThisPeriod = 0.0;
        $earningLines = [];
        foreach ($compensationSummary['earnings'] ?? [] as $e) {
            $amt = (float) ($e['computed_amount'] ?? 0);
            $code = strtoupper((string) ($e['code'] ?? ''));
            $isBasic = $code === 'BASIC_SALARY';
            $pcId = $e['pay_component_id'] ?? null;
            if ($pcId) {
                $sched = $this->resolveScheduleType('pay_component:'.((int) $pcId), $companyId);
            } else {
                $sched = DeductionScheduleSetting::SCHEDULE_BOTH;
            }
            $factor = $this->resolvePeriodAwareFactor($user, $sched, $segment, $ref, $selectedPayDate, $periodStartRaw, $periodEndRaw);
            $thisAmt = round($amt * $factor, 2);
            $lineAttendanceFactor = (! $isBasic && ! empty($e['is_proratable'])) ? $attendanceFactor : 1.0;
            $allowanceProration = null;
            $allowanceMode = $this->resolveAllowanceProrationType($e, $isBasic);
            if ($allowanceMode === 'attendance_prorated') {
                $allowanceProration = $this->computeAttendanceProratedAllowanceAmount(
                    $e,
                    $attendanceProration,
                    $sched,
                    $factor,
                    $segment
                );
                $thisAmt = (float) ($allowanceProration['amount'] ?? 0.0);
                $lineAttendanceFactor = (float) ($allowanceProration['effective_factor'] ?? 0.0);
                $this->logAllowanceProration($user, $e, $sched, $periodStartRaw, $periodEndRaw, $allowanceProration);
            } elseif ($lineAttendanceFactor < 1.0 - 1.0e-9) {
                $thisAmt = round($thisAmt * $lineAttendanceFactor, 2);
            }
            if (! $isBasic) {
                $nonBasicEarningsThisPeriod += $thisAmt;
            }
            $earningLines[] = array_merge($e, [
                'earning_schedule_type' => $sched,
                'scheduled_this_period' => $thisAmt,
                'is_basic_salary_line' => $isBasic,
                'attendance_proration_factor' => $lineAttendanceFactor,
                'allowance_proration' => $allowanceProration,
            ]);
        }

        return [
            'semi_monthly_period' => $gov['period'],
            'reference_date' => $ref->toDateString(),
            'government' => $gov,
            'custom_deductions_full_monthly' => round($customFull, 2),
            'custom_deductions_this_period' => round($customThisPeriod, 2),
            'custom_lines' => $customLines,
            'earning_lines' => $earningLines,
            'non_basic_earnings_this_period' => round($nonBasicEarningsThisPeriod, 2),
            'employee_statutory_this_period' => $gov['employee_statutory_this_period'],
            'withholding_this_period' => $gov['withholding_this_period'],
            'attendance_proration' => is_array($attendanceProration) ? array_merge([
                'factor' => $attendanceFactor,
            ], $attendanceProration) : [
                'factor' => 1.0,
                'scheduled_workdays' => 0.0,
                'credited_day_units' => 0.0,
            ],
        ];
    }

    /**
     * Clamp attendance proration factor from payroll period context (see DeductionScheduleService inputs).
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function normalizeAttendanceProrationFactor(?array $meta): float
    {
        if (! is_array($meta)) {
            return 1.0;
        }
        $f = (float) ($meta['factor'] ?? 1.0);

        return max(0.0, min(1.0, $f));
    }

    /**
     * Allowance components can be configured through existing pay-component fields:
     * - fixed monthly/semi-monthly/cutoff allowance: `is_proratable = false`, schedule controls timing.
     * - attendance-prorated allowance: `is_proratable = true` and allowance category/name/code, or
     *   `metadata.allowance_proration_type = attendance_prorated`.
     * - legacy cutoff-prorated allowance: `metadata.allowance_proration_type = cutoff_prorated`.
     */
    private function resolveAllowanceProrationType(array $line, bool $isBasic): string
    {
        if ($isBasic || empty($line['is_proratable'])) {
            return 'scheduled_fixed';
        }

        $metaMode = strtolower(trim((string) data_get($line, 'metadata.allowance_proration_type', '')));
        $metaMode = str_replace(['-', ' '], '_', $metaMode);
        if (in_array($metaMode, ['attendance_prorated', 'attendance_based', 'actual_attendance'], true)) {
            return 'attendance_prorated';
        }
        if (in_array($metaMode, ['cutoff_prorated', 'period_prorated', 'scheduled_prorated'], true)) {
            return 'cutoff_prorated';
        }
        if (in_array($metaMode, ['monthly_fixed', 'semi_monthly_fixed', 'scheduled_fixed'], true)) {
            return 'scheduled_fixed';
        }

        return $this->isAllowanceLine($line) ? 'attendance_prorated' : 'cutoff_prorated';
    }

    private function isAllowanceLine(array $line): bool
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($line['category'] ?? ''),
            (string) ($line['name'] ?? ''),
            (string) ($line['code'] ?? ''),
        ])));

        return str_contains($haystack, 'allowance');
    }

    /**
     * Attendance-prorated allowances first honor the pay-component schedule, then prorate the
     * scheduled base by valid attendance day units in the cutoff.
     *
     * Example, split schedule: 2600 monthly * 0.5 = 1300 base; 1300 / 13 scheduled
     * divisor days * 4.8625 present day units = 486.25.
     *
     * @param  array<string, mixed>|null  $attendanceProration
     * @return array<string, mixed>
     */
    private function computeAttendanceProratedAllowanceAmount(
        array $line,
        ?array $attendanceProration,
        string $scheduleType,
        float $scheduleFactor,
        ?string $currentRunType
    ): array
    {
        $allowanceMeta = is_array($attendanceProration['allowance'] ?? null)
            ? $attendanceProration['allowance']
            : [];
        $monthlyAmount = round(max(0.0, (float) ($line['computed_amount'] ?? 0)), 2);
        $monthlyDivisor = max(1.0, (float) ($allowanceMeta['monthly_divisor_days'] ?? 0));
        $workedDayUnits = max(0.0, (float) ($allowanceMeta['worked_day_units'] ?? 0));
        $scheduleFactor = max(0.0, min(1.0, $scheduleFactor));
        $isApplicable = $scheduleFactor > 0.0;
        $allowanceBase = $isApplicable ? round($monthlyAmount * $scheduleFactor, 2) : 0.0;
        $baseDivisorDays = $this->resolveAllowanceBaseDivisorDays($monthlyDivisor, $scheduleType, $scheduleFactor);
        $dailyRate = ($isApplicable && $baseDivisorDays > 0)
            ? round($allowanceBase / $baseDivisorDays, 6)
            : 0.0;
        $amount = round($dailyRate * $workedDayUnits, 2);

        return [
            'allowance_type' => 'attendance_prorated',
            'configured_monthly_amount' => round($monthlyAmount, 2),
            'schedule_configuration' => $scheduleType,
            'current_payroll_run_type' => $currentRunType,
            'schedule_factor' => round($scheduleFactor, 6),
            'is_applicable_in_run' => $isApplicable,
            'allowance_base_before_proration' => $allowanceBase,
            'monthly_divisor_days' => round($monthlyDivisor, 4),
            'base_divisor_days' => round($baseDivisorDays, 4),
            'daily_allowance_rate' => round($dailyRate, 6),
            'worked_day_units' => round($workedDayUnits, 6),
            'worked_minutes' => (int) ($allowanceMeta['worked_minutes'] ?? 0),
            'converted_hours' => round((float) ($allowanceMeta['converted_hours'] ?? 0), 4),
            'daily_hours' => round((float) ($allowanceMeta['daily_hours'] ?? 8), 4),
            'divisor_source' => (string) ($allowanceMeta['divisor_source'] ?? 'stable_schedule_monthly'),
            'attendance_counted' => is_array($allowanceMeta['attendance_counted'] ?? null) ? $allowanceMeta['attendance_counted'] : [],
            'attendance_excluded' => is_array($allowanceMeta['attendance_excluded'] ?? null) ? $allowanceMeta['attendance_excluded'] : [],
            'effective_factor' => $monthlyAmount > 0 ? round($amount / $monthlyAmount, 6) : 0.0,
            'amount' => $amount,
        ];
    }

    private function resolveAllowanceBaseDivisorDays(float $monthlyDivisor, string $scheduleType, float $scheduleFactor): float
    {
        if ($scheduleFactor <= 0.0) {
            return 0.0;
        }

        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return max(1.0, $monthlyDivisor * $scheduleFactor);
        }

        return max(1.0, $monthlyDivisor);
    }

    private function logAllowanceProration(
        User $user,
        array $line,
        string $scheduleType,
        mixed $periodStartRaw,
        mixed $periodEndRaw,
        ?array $allowanceProration
    ): void {
        if (! is_array($allowanceProration)) {
            return;
        }

        Log::info('payroll.allowance_proration', [
            'employee_id' => (int) $user->id,
            'pay_component_id' => $line['pay_component_id'] ?? null,
            'code' => $line['code'] ?? null,
            'name' => $line['name'] ?? null,
            'period_start' => $periodStartRaw,
            'period_end' => $periodEndRaw,
            'schedule_type' => $scheduleType,
            'allowance_type' => $allowanceProration['allowance_type'] ?? null,
            'configured_monthly_amount' => $allowanceProration['configured_monthly_amount'] ?? null,
            'schedule_configuration' => $allowanceProration['schedule_configuration'] ?? null,
            'current_payroll_run_type' => $allowanceProration['current_payroll_run_type'] ?? null,
            'schedule_factor' => $allowanceProration['schedule_factor'] ?? null,
            'is_applicable_in_run' => $allowanceProration['is_applicable_in_run'] ?? null,
            'allowance_base_before_proration' => $allowanceProration['allowance_base_before_proration'] ?? null,
            'monthly_divisor_days' => $allowanceProration['monthly_divisor_days'] ?? null,
            'base_divisor_days' => $allowanceProration['base_divisor_days'] ?? null,
            'daily_allowance_rate' => $allowanceProration['daily_allowance_rate'] ?? null,
            'worked_day_units' => $allowanceProration['worked_day_units'] ?? null,
            'converted_hours' => $allowanceProration['converted_hours'] ?? null,
            'daily_hours' => $allowanceProration['daily_hours'] ?? null,
            'attendance_counted' => $allowanceProration['attendance_counted'] ?? [],
            'attendance_excluded' => $allowanceProration['attendance_excluded'] ?? [],
            'amount' => $allowanceProration['amount'] ?? null,
        ]);
    }

    /**
     * Public helper for schedule-aware proration in a concrete payroll period.
     * Used by payroll summary to apply 15th/30th/Both on basic salary and component lines.
     */
    public function factorForScheduleInPeriod(
        User $user,
        string $scheduleType,
        Carbon $referenceDate,
        ?Carbon $selectedPayDate = null,
        Carbon|string|null $periodStart = null,
        Carbon|string|null $periodEnd = null,
        ?string $segment = null
    ): float {
        $ref = $referenceDate->copy()->timezone($this->timezone())->startOfDay();
        $selected = $selectedPayDate?->copy()->timezone($this->timezone())->startOfDay() ?? $ref;
        $startRaw = $periodStart instanceof Carbon ? $periodStart->toDateString() : $periodStart;
        $endRaw = $periodEnd instanceof Carbon ? $periodEnd->toDateString() : $periodEnd;

        return $this->resolvePeriodAwareFactor($user, $scheduleType, $segment, $ref, $selected, $startRaw, $endRaw);
    }

    private function resolvePeriodAwareFactor(
        User $user,
        string $scheduleType,
        ?string $segment,
        Carbon $referenceDate,
        Carbon $selectedPayDate,
        mixed $periodStartRaw,
        mixed $periodEndRaw
    ): float {
        $resolvedCutOffType = in_array($segment, ['first', 'second'], true) ? $segment : null;

        if ($periodStartRaw && $periodEndRaw) {
            $normalizedStart = Carbon::parse((string) $periodStartRaw, $this->timezone())->toDateString();
            $normalizedEnd = Carbon::parse((string) $periodEndRaw, $this->timezone())->toDateString();

            // Resolve concrete run cut-off type from the exact period row first.
            // This avoids misclassification when nearby runs (e.g. month-end) are also in scope.
            $window = $this->payCycleService->getPayDatesForPeriod(
                Carbon::parse((string) $periodStartRaw, $this->timezone())->copy()->subMonthsNoOverflow(1),
                Carbon::parse((string) $periodEndRaw, $this->timezone())->copy()->addMonthsNoOverflow(1),
                null,
                $user
            );
            foreach (($window['periods'] ?? []) as $row) {
                $rowStart = (string) ($row['cut_off_start_date'] ?? '');
                $rowEnd = (string) ($row['cut_off_end_date'] ?? '');
                if ($rowStart !== $normalizedStart || $rowEnd !== $normalizedEnd) {
                    continue;
                }
                $rowSegment = $row['semi_month_segment'] ?? null;
                if ($resolvedCutOffType === null && in_array($rowSegment, ['first', 'second'], true)) {
                    $resolvedCutOffType = (string) $rowSegment;
                }
                break;
            }

            // Resolve concrete run cut-off type from the actual period window, independent of the component schedule.
            // This prevents "30th only" rows from being mistakenly applied on first-half runs.
            $allCutOffDates = $this->getDeductionDatesForPeriod(
                $user,
                DeductionScheduleSetting::SCHEDULE_BOTH,
                (string) $periodStartRaw,
                (string) $periodEndRaw
            );
            $dateList = collect($allCutOffDates['dates'] ?? [])->filter()->values();
            if ($dateList->count() > 0 && $resolvedCutOffType === null) {
                $selectedStr = $selectedPayDate->toDateString();
                $firstDate = (string) ($dateList->get(0) ?? '');
                $secondDate = (string) ($dateList->get(1) ?? '');
                if ($selectedStr !== '' && $selectedStr === $firstDate) {
                    $resolvedCutOffType = 'first';
                } elseif ($selectedStr !== '' && $selectedStr === $secondDate) {
                    $resolvedCutOffType = 'second';
                }
            }
        }

        if ($resolvedCutOffType === null) {
            $resolvedCutOffType = $this->inferSemiMonthSegmentFromPayDate($selectedPayDate);
        }

        if ($resolvedCutOffType === null) {
            $resolvedCutOffType = $this->resolveSemiMonthSegmentForPayrollContext($user, $selectedPayDate, null);
        }

        $applies = $this->payCycleService->shouldApplyOnThisCutOff($scheduleType, $resolvedCutOffType);
        if (! $applies) {
            return 0.0;
        }

        if ($scheduleType === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 0.5;
        }

        return 1.0;
    }

    /**
     * Exact installment handling for amortized loans:
     * - Match installment row by pay_date to current payroll pay date.
     * - Fallback to due_date for legacy rows without pay_date.
     * - If no match, this period deduction is zero.
     */
    private function resolveAmortizedInstallmentForPayrollContext(
        array $line,
        Carbon $selectedPayDate,
        mixed $periodEndRaw
    ): ?float {
        $meta = is_array($line['metadata'] ?? null) ? $line['metadata'] : [];
        $isExactAmortized = (bool) ($meta['amortization_exact_installment'] ?? false);
        if (! $isExactAmortized) {
            return null;
        }

        $schedule = $meta['amortization_schedule'] ?? [];
        if (! is_array($schedule) || count($schedule) === 0) {
            return 0.0;
        }

        $selectedPayDateStr = $selectedPayDate->toDateString();
        $periodEndStr = $periodEndRaw ? Carbon::parse((string) $periodEndRaw, $this->timezone())->toDateString() : null;

        foreach ($schedule as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            if (! in_array($status, ['pending', 'overdue'], true)) {
                continue;
            }
            $payDate = isset($row['pay_date']) && $row['pay_date']
                ? Carbon::parse((string) $row['pay_date'], $this->timezone())->toDateString()
                : null;
            $dueDate = isset($row['due_date']) && $row['due_date']
                ? Carbon::parse((string) $row['due_date'], $this->timezone())->toDateString()
                : null;

            if ($payDate !== null && $payDate === $selectedPayDateStr) {
                return round(max(0.0, (float) ($row['total_installment'] ?? 0)), 2);
            }
            // Secondary match: payroll cut-off end. Keep this even when pay_date is present so
            // preview/generate/finalize remain stable if selected_pay_date context is missing/misaligned.
            if ($periodEndStr !== null && $dueDate !== null && $dueDate === $periodEndStr) {
                return round(max(0.0, (float) ($row['total_installment'] ?? 0)), 2);
            }
        }

        return 0.0;
    }

    /**
     * Derive semi-month segment from selected pay date itself.
     * Works for past/current/future periods and weekend-adjusted pay dates:
     * - <= 15th pay-date bucket => first cut-off
     * - > 15th pay-date bucket => second cut-off
     *
     * @return 'first'|'second'|null
     */
    private function inferSemiMonthSegmentFromPayDate(?Carbon $selectedPayDate): ?string
    {
        if (! $selectedPayDate instanceof Carbon) {
            return null;
        }

        $payDate = $selectedPayDate->copy()->timezone($this->timezone())->startOfDay();
        $day = (int) $payDate->day;
        if ($day <= 15) {
            return 'first';
        }

        return 'second';
    }

    /**
     * Prefer explicit segment from pay-cycle preview periods; fallback to live pay-cycle windows; fallback to calendar-day.
     *
     * @return 'first'|'second'|null
     */
    public function resolveSemiMonthSegmentForPayrollContext(User $user, Carbon $referenceDate, ?array $payCyclePreview = null): ?string
    {
        $ref = $referenceDate->copy()->timezone($this->timezone())->startOfDay();
        $candidateRows = collect($payCyclePreview['preview_periods'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->values();

        foreach ($candidateRows as $row) {
            $start = Carbon::parse((string) ($row['cut_off_start_date'] ?? ''), $this->timezone())->startOfDay();
            $end = Carbon::parse((string) ($row['cut_off_end_date'] ?? ''), $this->timezone())->startOfDay();
            if ($ref->betweenIncluded($start, $end)) {
                $segment = $row['semi_month_segment'] ?? null;
                if ($segment === 'first' || $segment === 'second') {
                    return $segment;
                }
            }
        }

        $window = $this->payCycleService->getPayDatesForPeriod($ref->copy()->subMonthsNoOverflow(2), $ref->copy()->addMonthsNoOverflow(2), null, $user);
        foreach (($window['periods'] ?? []) as $row) {
            $start = Carbon::parse((string) ($row['cut_off_start_date'] ?? ''), $this->timezone())->startOfDay();
            $end = Carbon::parse((string) ($row['cut_off_end_date'] ?? ''), $this->timezone())->startOfDay();
            if ($ref->betweenIncluded($start, $end)) {
                $segment = $row['semi_month_segment'] ?? null;
                if ($segment === 'first' || $segment === 'second') {
                    return $segment;
                }
            }
        }

        return $this->semiMonthlyPeriodForDate($ref);
    }

    /**
     * @return list<array{id: int|null, name: string, code: string|null, category: string|null, type: string, schedule_type: string}>
     */
    public function listRowsForAdmin(?int $companyId): array
    {
        $gov = [
            ['deduction_key' => DeductionScheduleSetting::GOV_SSS, 'name' => 'SSS', 'type' => 'Government', 'category' => 'Social Security'],
            ['deduction_key' => DeductionScheduleSetting::GOV_PHILHEALTH, 'name' => 'PhilHealth', 'type' => 'Government', 'category' => 'Health'],
            ['deduction_key' => DeductionScheduleSetting::GOV_PAGIBIG, 'name' => 'Pag-IBIG', 'type' => 'Government', 'category' => 'Housing'],
            ['deduction_key' => DeductionScheduleSetting::GOV_WITHHOLDING, 'name' => 'Withholding tax', 'type' => 'Government', 'category' => 'BIR'],
        ];

        $out = [];
        foreach ($gov as $g) {
            $out[] = [
                'id' => null,
                'deduction_key' => $g['deduction_key'],
                'name' => $g['name'],
                'code' => null,
                'category' => $g['category'],
                'description' => 'Statutory · '.($g['category'] ?? ''),
                'type' => $g['type'],
                'schedule_type' => $this->resolveScheduleType($g['deduction_key'], $companyId),
            ];
        }

        $components = \App\Models\PayComponent::query()
            ->where('type', \App\Models\PayComponent::TYPE_DEDUCTION)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('code')
                    ->orWhereRaw('UPPER(TRIM(code)) != ?', ['BASIC_SALARY']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category']);

        foreach ($components as $pc) {
            $key = 'pay_component:'.$pc->id;
            $descParts = array_filter([$pc->code ? (string) $pc->code : null, $pc->category ? (string) $pc->category : null]);
            $out[] = [
                'id' => (int) $pc->id,
                'deduction_key' => $key,
                'name' => $pc->name,
                'code' => $pc->code,
                'category' => $pc->category,
                'description' => $descParts !== [] ? implode(' · ', $descParts) : 'Pay component deduction',
                'type' => 'Loan / deduction',
                'schedule_type' => $this->resolveScheduleType($key, $companyId),
            ];
        }

        $earnings = \App\Models\PayComponent::query()
            ->where('type', \App\Models\PayComponent::TYPE_EARNING)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('code')
                    ->orWhereRaw('UPPER(TRIM(code)) != ?', ['BASIC_SALARY']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category']);

        foreach ($earnings as $pc) {
            $key = 'pay_component:'.$pc->id;
            $descParts = array_filter([$pc->code ? (string) $pc->code : null, $pc->category ? (string) $pc->category : null]);
            $out[] = [
                'id' => (int) $pc->id,
                'deduction_key' => $key,
                'name' => $pc->name,
                'code' => $pc->code,
                'category' => $pc->category,
                'description' => $descParts !== [] ? implode(' · ', $descParts) : 'Pay component earning',
                'type' => 'Earning',
                'schedule_type' => $this->resolveScheduleType($key, $companyId),
            ];
        }

        return $out;
    }

    public function upsertSetting(?int $companyId, string $deductionKey, string $scheduleType): DeductionScheduleSetting
    {
        if (! in_array($scheduleType, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH,
        ], true)) {
            throw new \InvalidArgumentException('Invalid schedule_type.');
        }

        return DeductionScheduleSetting::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'deduction_key' => $deductionKey,
            ],
            ['schedule_type' => $scheduleType]
        );
    }

    /**
     * @param  list<array{deduction_key: string, schedule_type: string}>  $settings
     * @return list<DeductionScheduleSetting>
     */
    public function upsertMany(?int $companyId, array $settings): array
    {
        $saved = [];
        foreach ($settings as $row) {
            if (! is_array($row) || empty($row['deduction_key']) || empty($row['schedule_type'])) {
                continue;
            }
            $saved[] = $this->upsertSetting($companyId, (string) $row['deduction_key'], (string) $row['schedule_type']);
        }

        return $saved;
    }
}
