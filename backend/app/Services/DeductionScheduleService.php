<?php

namespace App\Services;

use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\PayCycle;
use App\Models\User;
use App\Support\BulkPayrollDraftContext;
use App\Support\CalculationStandard;
use App\Support\PayComponentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Central HR-configured rules for when employee deductions are withheld (15th / end-of-month / split).
 * Used for payroll previews and daily computation summaries — not a substitute for statutory remittance calendars.
 */
class DeductionScheduleService
{
    private const CACHE_PREFIX = 'payroll.deduction_schedule.';

    public function __construct(
        private readonly PayCycleService $payCycleService,
    ) {}

    /** @var array<int, string> */
    private const GOVERNMENT_COMPONENT_CODES = ['SSS', 'PHILHEALTH', 'PAGIBIG', 'WITHHOLDING_TAX'];

    public function timezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Resolve schedule type with employee-specific override support.
     * Priority: employee component schedule_override > global/default schedule.
     *
     * @param  string  $deductionKey  'pay_component:123' or 'government:SSS'
     * @param  int|null  $companyId
     * @param  int|null  $userId  Employee ID for override lookup
     * @param  int|null  $payComponentId  Pay component ID for override lookup
     * @param  int|null  $compensationAssignmentId  {@see EmployeeCompensationComponent::$id} when resolving from payroll/summary lines
     */
    public function resolveScheduleType(string $deductionKey, ?int $companyId, ?int $userId = null, ?int $payComponentId = null, ?int $compensationAssignmentId = null): string
    {
        if ($userId && $payComponentId) {
            return $this->resolveEmployeePayComponentSchedule(
                (int) $payComponentId,
                $companyId,
                (int) $userId,
                false,
                null,
                $compensationAssignmentId !== null ? (int) $compensationAssignmentId : null,
            )['resolved_schedule'];
        }

        return $this->resolveGlobalDeductionScheduleType($deductionKey, $companyId);
    }

    /**
     * Company default from deduction schedule settings (no employee override).
     */
    public function resolveGlobalDeductionScheduleType(string $deductionKey, ?int $companyId): string
    {
        $cacheKey = self::CACHE_PREFIX.md5((string) ($companyId ?? 'global').'|'.$deductionKey);

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($deductionKey, $companyId): string {
            $row = DeductionScheduleSetting::query()
                ->where('deduction_key', $deductionKey)
                ->when($companyId !== null, fn ($q) => $q->where(function ($sub) use ($companyId) {
                    $sub->where('company_id', $companyId)->orWhereNull('company_id');
                }), fn ($q) => $q->whereNull('company_id'))
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
        });
    }

    /**
     * Payroll / profile row metadata: employee override vs Deduction Schedule Settings default.
     *
     * When {@see $useLoadedAssignmentRow} is true, {@see $loadedAssignmentScheduleOverride} reflects the assignment row backing the earning/deduction line (no extra lookup).
     *
     * @param  int|null  $compensationAssignmentId  When set, {@see EmployeeCompensationComponent::$schedule_override} is read for this row only (avoids ambiguity when multiple assignments share a pay component).
     *
     * @return array{
     *     schedule_override:?string,
     *     default_schedule:string,
     *     resolved_schedule:string,
     *     schedule_source:'employee_override'|'default_schedule'
     * }
     */
    public function resolveEmployeePayComponentSchedule(
        ?int $payComponentId,
        ?int $companyId,
        ?int $userId,
        bool $useLoadedAssignmentRow = false,
        ?string $loadedAssignmentScheduleOverride = null,
        ?int $compensationAssignmentId = null,
    ): array {
        $key = $payComponentId ? 'pay_component:'.$payComponentId : '';

        $defaultType = $key !== ''
            ? $this->resolveGlobalDeductionScheduleType($key, $companyId)
            : DeductionScheduleSetting::SCHEDULE_BOTH;

        $overrideRaw = null;
        if ($useLoadedAssignmentRow) {
            $overrideRaw = $loadedAssignmentScheduleOverride;
        } elseif ($compensationAssignmentId && $userId) {
            $overrideRaw = EmployeeCompensationComponent::query()
                ->whereKey((int) $compensationAssignmentId)
                ->where('user_id', $userId)
                ->value('schedule_override');
        } elseif ($userId && $payComponentId) {
            $overrideRaw = EmployeeCompensationComponent::query()
                ->where('user_id', $userId)
                ->where('pay_component_id', $payComponentId)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->value('schedule_override');
        }

        if (is_string($overrideRaw)) {
            $overrideRaw = trim($overrideRaw);
        }
        $normalizedSlug = PayComponentSchedule::normalizeForStorage(is_string($overrideRaw) ? $overrideRaw : null);
        $effectiveOverride = $normalizedSlug !== null;

        $resolved = $effectiveOverride
            ? PayComponentSchedule::mapOverrideToDeductionScheduleType($normalizedSlug)
            : $defaultType;

        $result = [
            'schedule_override' => $effectiveOverride ? $normalizedSlug : null,
            'default_schedule' => $defaultType,
            'resolved_schedule' => $resolved,
            'schedule_source' => $effectiveOverride ? 'employee_override' : 'default_schedule',
        ];

        if (! BulkPayrollDraftContext::$active) {
            Log::debug('deduction_schedule.pay_component_resolved', [
                'employee_id' => $userId,
                'company_id' => $companyId,
                'pay_component_id' => $payComponentId,
                'compensation_assignment_id' => $compensationAssignmentId,
                'used_loaded_assignment_row' => $useLoadedAssignmentRow,
                'raw_assignment_schedule_override_input' => $useLoadedAssignmentRow ? $loadedAssignmentScheduleOverride : null,
                'db_schedule_override_normalized' => $normalizedSlug,
                'resolved_schedule' => $resolved,
                'schedule_source' => $result['schedule_source'],
                'default_schedule_settings' => $defaultType,
            ]);
        }

        return $result;
    }

    /**
     * Coerce a compensation-summary resolved schedule token to DeductionScheduleSetting schedule_type.
     *
     * @return ('15th'|'30th'|'both')|null
     */
    public function coerceResolvedScheduleConstant(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = trim($value);
        if ($t !== ''
            && in_array($t, [
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ], true)) {
            return $t;
        }

        return null;
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
            $amount = (float) ($line['applied_this_period'] ?? $line['scheduled_this_period'] ?? $line['computed_amount'] ?? 0);
            $basis = (float) ($line['original_amount'] ?? $line['computed_amount'] ?? $amount);
            $sched = $this->normalizeScheduleType((string) ($line['deduction_schedule_type'] ?? DeductionScheduleSetting::SCHEDULE_BOTH))
                ?? DeductionScheduleSetting::SCHEDULE_BOTH;
            $standard = $this->normalizeCalculationStandard($line['calculation_standard'] ?? null);
            $out[] = [
                'label' => $rawName,
                'amount' => round(max(0.0, $amount), 2),
                'deduction_schedule_type' => $sched,
                'calculation_standard' => $standard,
                'priority_order' => (int) ($line['priority_order'] ?? 0),
                'priority_bucket' => $line['priority_bucket'] ?? null,
                'legal_warning' => $line['legal_warning'] ?? null,
                'display' => $this->formatPayslipDeductionLineDisplay($short, $basis, $sched, $standard),
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
            $standard = $this->normalizeCalculationStandard(
                $line['resolved_calculation_standard'] ?? $line['calculation_standard'] ?? null
            );
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
                'calculation_standard' => $standard,
                'amount' => round(max(0.0, $thisPeriod), 2),
                'full_monthly' => round(max(0.0, $fullMonthly), 2),
                'display' => $this->formatPayslipEarningLineDisplay($label, $fullMonthly, $sched, $standard),
            ];
            if (is_array($line['allowance_proration'] ?? null)) {
                $row['allowance_proration'] = $line['allowance_proration'];
            }
            $out[] = $row;
        }

        return $out;
    }

    private function formatPayslipEarningLineDisplay(string $label, float $fullMonthly, string $sched, string $calculationStandard = PayComponent::STANDARD_MONTHLY): string
    {
        $fullMonthly = round(max(0.0, $fullMonthly), 2);
        if ($sched === DeductionScheduleSetting::SCHEDULE_BOTH) {
            $half = $calculationStandard === PayComponent::STANDARD_PAYROLL
                ? $fullMonthly
                : round($fullMonthly / 2, 2);

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

    private function formatPayslipDeductionLineDisplay(string $short, float $fullMonthly, string $sched, string $calculationStandard = PayComponent::STANDARD_MONTHLY): string
    {
        $fullMonthly = round(max(0.0, $fullMonthly), 2);
        if ($sched === DeductionScheduleSetting::SCHEDULE_BOTH) {
            $half = $calculationStandard === PayComponent::STANDARD_PAYROLL
                ? $fullMonthly
                : round($fullMonthly / 2, 2);

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
     * Prefer per-line schedules already resolved on the compensation-summary row; fall back to per-assignment / company lookup.
     */
    private function resolveEffectivePayComponentScheduleTypeForPayroll(User $user, array $line, int $payComponentId): string
    {
        $resolved = $this->coerceResolvedScheduleConstant(data_get($line, 'resolved_schedule'));
        if ($resolved !== null) {
            return $resolved;
        }
        $assignmentIdRaw = data_get($line, 'id');
        $assignmentId = is_numeric($assignmentIdRaw) ? (int) $assignmentIdRaw : null;
        $companyId = $user->getEffectiveCompanyId();

        return $this->resolveScheduleType(
            'pay_component:'.((int) $payComponentId),
            $companyId,
            (int) $user->id,
            (int) $payComponentId,
            $assignmentId,
        );
    }

    /**
     * Central resolver for payroll-run pay component amounts.
     *
     * @param  array<string, mixed>  $component
     * @param  array<string, mixed>  $payrollRun
     * @return array{
     *     original_amount: float,
     *     calculation_standard: string,
     *     default_calculation_standard: string,
     *     calculation_standard_override: string|null,
     *     resolved_calculation_standard: string,
     *     calculation_standard_source: string,
     *     resolved_schedule: string,
     *     payroll_run_type: string,
     *     divisor_applied: float,
     *     applied_amount: float
     * }
     */
    public function resolvePayComponentAmount(array $component, array $payrollRun): array
    {
        $component = $this->enrichPayrollLineCalculationStandard($component);
        $original = round(max(0.0, (float) ($component['computed_amount'] ?? $component['configured_value'] ?? $component['value'] ?? $component['default_value'] ?? 0)), 2);
        $standardMeta = $this->resolveCalculationStandardMeta($component);
        $standard = $standardMeta['resolved_calculation_standard'];
        $schedule = $this->normalizeScheduleType((string) ($component['resolved_schedule'] ?? $component['pay_schedule_type'] ?? $payrollRun['resolved_schedule'] ?? DeductionScheduleSetting::SCHEDULE_BOTH))
            ?? DeductionScheduleSetting::SCHEDULE_BOTH;

        $segment = $payrollRun['segment'] ?? null;
        $segment = in_array($segment, ['first', 'second'], true) ? (string) $segment : null;
        $referenceDate = $payrollRun['reference_date'] instanceof Carbon
            ? $payrollRun['reference_date']->copy()->timezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($payrollRun['reference_date'] ?? now()), $this->timezone())->startOfDay();
        $selectedPayDate = $payrollRun['selected_pay_date'] instanceof Carbon
            ? $payrollRun['selected_pay_date']->copy()->timezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($payrollRun['selected_pay_date'] ?? $referenceDate->toDateString()), $this->timezone())->startOfDay();
        $user = ($payrollRun['user'] ?? null) instanceof User ? $payrollRun['user'] : null;

        $payCycleCode = strtolower(trim((string) ($payrollRun['pay_cycle_code'] ?? $this->resolvePayCycleCodeForRun($user, $payrollRun))));
        $payrollRunType = $this->resolvePayrollRunType($payCycleCode, $segment, $selectedPayDate);

        $monthlyFactor = $this->resolveMonthlyStandardFactor(
            $user,
            $schedule,
            $payCycleCode,
            $segment,
            $referenceDate,
            $selectedPayDate,
            $payrollRun['period_start'] ?? $payrollRun['pay_period_start'] ?? null,
            $payrollRun['period_end'] ?? $payrollRun['pay_period_end'] ?? null
        );

        $runApplies = $monthlyFactor > 0.0;
        $divisorApplied = $standard === PayComponent::STANDARD_PAYROLL
            ? ($runApplies ? 1.0 : 0.0)
            : ($runApplies ? $monthlyFactor : 0.0);
        $appliedAmount = round($original * $divisorApplied, 2);

        $resolution = array_merge($standardMeta, [
            'original_amount' => $original,
            'calculation_standard' => $standard,
            'resolved_schedule' => $schedule,
            'payroll_run_type' => $payrollRunType,
            'divisor_applied' => $divisorApplied,
            'applied_amount' => $appliedAmount,
        ]);

        if (! BulkPayrollDraftContext::$active) {
            Log::debug('payroll.pay_component_amount_resolution', [
                'employee_id' => $user ? (int) $user->id : null,
                'employee_component_id' => $component['id'] ?? null,
                'component_id' => $component['pay_component_id'] ?? null,
                'component_code' => $component['code'] ?? null,
                'original_amount' => $original,
                'schedule_override' => $component['schedule_override'] ?? null,
                'default_schedule' => $component['default_schedule'] ?? null,
                'resolved_schedule' => $schedule,
                'calculation_standard_override' => $standardMeta['calculation_standard_override'],
                'default_calculation_standard' => $standardMeta['default_calculation_standard'],
                'resolved_calculation_standard' => $standard,
                'calculation_standard_source' => $standardMeta['calculation_standard_source'],
                'current_payroll_run' => $payrollRunType,
                'divisor_applied' => $divisorApplied,
                'final_applied_amount' => $appliedAmount,
            ]);
        }

        return $resolution;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function enrichPayrollLineCalculationStandard(array $line): array
    {
        $pcId = isset($line['pay_component_id']) ? (int) $line['pay_component_id'] : 0;
        if ($pcId > 0
            && ! isset($line['pay_component_calculation_standard'])
            && ! isset($line['default_calculation_standard'])) {
            $master = PayComponent::query()->find($pcId);
            if ($master) {
                $line['pay_component_calculation_standard'] = CalculationStandard::normalizeDefault($master->calculation_standard);
                $line['default_calculation_standard'] = $line['pay_component_calculation_standard'];
            }
        }

        return array_merge($line, $this->resolveCalculationStandardMeta($line));
    }

    /**
     * @param  array<string, mixed>  $component
     * @return array{
     *     default_calculation_standard: string,
     *     calculation_standard_override: string|null,
     *     resolved_calculation_standard: string,
     *     calculation_standard_source: 'employee_override'|'pay_component_default'
     * }
     */
    private function resolveCalculationStandardMeta(array $component): array
    {
        $overrideRaw = $component['calculation_standard_override']
            ?? $component['assignment_calculation_standard_override']
            ?? null;

        if (isset($component['resolved_calculation_standard'], $component['calculation_standard_source'])
            && ($component['default_calculation_standard'] ?? $component['pay_component_calculation_standard'] ?? null) !== null) {
            return [
                'default_calculation_standard' => CalculationStandard::normalizeDefault(
                    $component['default_calculation_standard'] ?? $component['pay_component_calculation_standard'] ?? null
                ),
                'calculation_standard_override' => CalculationStandard::normalizeForStorage(
                    \is_string($overrideRaw) ? $overrideRaw : null
                ),
                'resolved_calculation_standard' => CalculationStandard::normalizeDefault($component['resolved_calculation_standard']),
                'calculation_standard_source' => $component['calculation_standard_source'] === 'employee_override'
                    ? 'employee_override'
                    : 'pay_component_default',
            ];
        }

        return CalculationStandard::resolveMetadata(
            \is_string($overrideRaw) ? $overrideRaw : null,
            $component['default_calculation_standard'] ?? $component['pay_component_calculation_standard'] ?? null
        );
    }

    private function normalizeCalculationStandard(mixed $value): string
    {
        return CalculationStandard::normalizeDefault($value);
    }

    /**
     * @param  array<string, mixed>  $payrollRun
     */
    private function resolvePayCycleCodeForRun(?User $user, array $payrollRun): string
    {
        $preview = is_array($payrollRun['pay_cycle_preview'] ?? null) ? $payrollRun['pay_cycle_preview'] : [];
        $fromPreview = strtolower(trim((string) ($preview['code'] ?? $preview['pay_cycle_code'] ?? '')));
        if ($fromPreview !== '') {
            return $fromPreview;
        }
        if ($user) {
            try {
                $cycle = $this->payCycleService->resolveForUser($user);
                if ($cycle?->code) {
                    return (string) $cycle->code;
                }
            } catch (\Throwable) {
                return PayCycle::CODE_SEMI_MONTHLY;
            }
        }

        return PayCycle::CODE_SEMI_MONTHLY;
    }

    private function resolvePayrollRunType(string $payCycleCode, ?string $segment, Carbon $selectedPayDate): string
    {
        if ($payCycleCode === PayCycle::CODE_WEEKLY) {
            return 'weekly';
        }
        if ($payCycleCode === PayCycle::CODE_BI_WEEKLY) {
            return 'bi_weekly';
        }
        if ($payCycleCode === PayCycle::CODE_MONTHLY) {
            return 'monthly';
        }
        if ($segment === 'first') {
            return '15th';
        }
        if ($segment === 'second') {
            return '30th';
        }

        return $selectedPayDate->day <= 15 ? '15th' : '30th';
    }

    private function resolveMonthlyStandardFactor(
        ?User $user,
        string $schedule,
        string $payCycleCode,
        ?string $segment,
        Carbon $referenceDate,
        Carbon $selectedPayDate,
        mixed $periodStartRaw,
        mixed $periodEndRaw
    ): float {
        if (in_array($payCycleCode, [PayCycle::CODE_WEEKLY, PayCycle::CODE_BI_WEEKLY], true)
            && $schedule === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 1.0 / max(1, $this->countPayrollRunsInMonth($user, $payCycleCode, $selectedPayDate));
        }

        if ($payCycleCode === PayCycle::CODE_MONTHLY && $schedule === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return 1.0;
        }

        if ($user) {
            return $this->resolvePeriodAwareFactor($user, $schedule, $segment, $referenceDate, $selectedPayDate, $periodStartRaw, $periodEndRaw);
        }

        return $segment
            ? $this->prorationFactorForMonthlyAmountBySegment($schedule, $segment)
            : $this->prorationFactorForMonthlyAmount($schedule, $selectedPayDate);
    }

    private function countPayrollRunsInMonth(?User $user, string $payCycleCode, Carbon $selectedPayDate): int
    {
        if ($user) {
            try {
                $start = $selectedPayDate->copy()->startOfMonth();
                $end = $selectedPayDate->copy()->endOfMonth();
                $window = $this->payCycleService->getPayDatesForPeriod($start, $end, null, $user);
                $count = collect($window['periods'] ?? [])
                    ->pluck('pay_date')
                    ->filter()
                    ->map(fn ($date) => Carbon::parse((string) $date, $this->timezone())->toDateString())
                    ->filter(fn (string $date) => Carbon::parse($date, $this->timezone())->betweenIncluded($start, $end))
                    ->unique()
                    ->count();
                if ($count > 0) {
                    return $count;
                }
            } catch (\Throwable) {
                // Fall through to calendar estimate.
            }
        }

        if ($payCycleCode === PayCycle::CODE_BI_WEEKLY) {
            return (int) ceil($selectedPayDate->daysInMonth / 14);
        }

        return (int) ceil($selectedPayDate->daysInMonth / 7);
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
        $payrollRun = [
            'user' => $user,
            'reference_date' => $ref,
            'selected_pay_date' => $selectedPayDate,
            'segment' => $segment,
            'pay_period_start' => $periodStartRaw,
            'pay_period_end' => $periodEndRaw,
            'pay_cycle_preview' => $payCyclePreview,
        ];

        $attendanceProration = $compensationSummary['_attendance_proration'] ?? null;
        $attendanceFactor = $this->normalizeAttendanceProrationFactor($attendanceProration);

        $customFull = 0.0;
        $customThisPeriod = 0.0;
        $customLines = [];
        foreach ($compensationSummary['deductions'] ?? [] as $d) {
            $d = $this->enrichPayrollLineCalculationStandard($d);
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
                $sched = $this->resolveEffectivePayComponentScheduleTypeForPayroll($user, $d, (int) $pcId);
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
                $resolvedAmount = [
                    'original_amount' => $amt,
                    'calculation_standard' => $this->normalizeCalculationStandard($d['calculation_standard'] ?? null),
                    'resolved_schedule' => $sched,
                    'payroll_run_type' => $segment === 'first' ? '15th' : ($segment === 'second' ? '30th' : 'payroll'),
                    'applied_amount' => $thisAmt,
                ];
            } else {
                $resolvedAmount = $this->resolvePayComponentAmount(array_merge($d, [
                    'computed_amount' => $amt,
                    'resolved_schedule' => $sched,
                ]), $payrollRun);
                $thisAmt = (float) ($resolvedAmount['applied_amount'] ?? 0.0);
                if (! $this->isPayrollStandardResolution($resolvedAmount) && ! empty($d['is_proratable'])) {
                    $thisAmt = round($thisAmt * $attendanceFactor, 2);
                }
            }
            $customThisPeriod += $thisAmt;
            $customLines[] = array_merge($d, [
                'deduction_schedule_type' => $sched,
                'scheduled_this_period' => $thisAmt,
                'original_amount' => round($amt, 2),
                'calculation_standard' => $resolvedAmount['resolved_calculation_standard'] ?? $resolvedAmount['calculation_standard'] ?? PayComponent::STANDARD_MONTHLY,
                'default_calculation_standard' => $resolvedAmount['default_calculation_standard'] ?? $d['default_calculation_standard'] ?? null,
                'calculation_standard_override' => $resolvedAmount['calculation_standard_override'] ?? $d['calculation_standard_override'] ?? null,
                'resolved_calculation_standard' => $resolvedAmount['resolved_calculation_standard'] ?? null,
                'calculation_standard_source' => $resolvedAmount['calculation_standard_source'] ?? null,
                'divisor_applied' => $resolvedAmount['divisor_applied'] ?? null,
                'payroll_run_type' => $resolvedAmount['payroll_run_type'] ?? null,
                'pay_component_resolution' => $resolvedAmount,
                'attendance_proration_factor' => (! empty($d['is_proratable']) && $amortizedInstallment === null)
                    ? $attendanceFactor
                    : 1.0,
            ]);
        }

        $nonBasicEarningsThisPeriod = 0.0;
        $earningLines = [];
        foreach ($compensationSummary['earnings'] ?? [] as $e) {
            $e = $this->enrichPayrollLineCalculationStandard($e);
            $amt = (float) ($e['computed_amount'] ?? 0);
            $code = strtoupper((string) ($e['code'] ?? ''));
            $isBasic = $code === 'BASIC_SALARY';
            $pcId = $e['pay_component_id'] ?? null;
            if ($pcId) {
                $sched = $this->resolveEffectivePayComponentScheduleTypeForPayroll($user, $e, (int) $pcId);
            } else {
                $sched = DeductionScheduleSetting::SCHEDULE_BOTH;
            }
            $resolvedAmount = $this->resolvePayComponentAmount(array_merge($e, [
                'computed_amount' => $amt,
                'resolved_schedule' => $sched,
            ]), $payrollRun);
            $thisAmt = (float) ($resolvedAmount['applied_amount'] ?? 0.0);
            $factor = (float) ($resolvedAmount['divisor_applied'] ?? ($amt > 0.0 ? round($thisAmt / $amt, 6) : 0.0));
            $usesPayrollStandard = $this->isPayrollStandardResolution($resolvedAmount);
            $lineAttendanceFactor = (! $usesPayrollStandard && ! $isBasic && ! empty($e['is_proratable'])) ? $attendanceFactor : 1.0;
            $allowanceProration = null;
            $allowanceMode = $usesPayrollStandard
                ? 'scheduled_fixed'
                : $this->resolveAllowanceProrationType($e, $isBasic);
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
            if ($pcId && ! $isBasic && ! BulkPayrollDraftContext::$active) {
                Log::debug('payroll.earning_line_schedule_for_run', [
                    'employee_id' => (int) $user->id,
                    'pay_component_id' => (int) $pcId,
                    'comp_assignment_id' => $e['id'] ?? null,
                    'component_code' => $e['code'] ?? null,
                    'payroll_run_segment' => $segment,
                    'schedule_override' => $e['schedule_override'] ?? null,
                    'default_schedule' => $e['default_schedule'] ?? null,
                    'resolved_schedule' => $sched,
                    'schedule_source' => $e['schedule_source'] ?? null,
                    'calculation_standard_override' => $resolvedAmount['calculation_standard_override'] ?? null,
                    'default_calculation_standard' => $resolvedAmount['default_calculation_standard'] ?? null,
                    'resolved_calculation_standard' => $resolvedAmount['resolved_calculation_standard'] ?? null,
                    'calculation_standard_source' => $resolvedAmount['calculation_standard_source'] ?? null,
                    'current_payroll_run' => $resolvedAmount['payroll_run_type'] ?? null,
                    'divisor_applied' => $resolvedAmount['divisor_applied'] ?? null,
                    'earning_schedule_type_applied' => $sched,
                    'full_monthly_computed_amount' => round($amt, 2),
                    'final_payroll_amount_this_period' => round($thisAmt, 2),
                ]);
            }
            $earningLines[] = array_merge($e, [
                'earning_schedule_type' => $sched,
                'scheduled_this_period' => $thisAmt,
                'original_amount' => round($amt, 2),
                'calculation_standard' => $resolvedAmount['resolved_calculation_standard'] ?? $resolvedAmount['calculation_standard'] ?? PayComponent::STANDARD_MONTHLY,
                'default_calculation_standard' => $resolvedAmount['default_calculation_standard'] ?? $e['default_calculation_standard'] ?? null,
                'calculation_standard_override' => $resolvedAmount['calculation_standard_override'] ?? $e['calculation_standard_override'] ?? null,
                'resolved_calculation_standard' => $resolvedAmount['resolved_calculation_standard'] ?? null,
                'calculation_standard_source' => $resolvedAmount['calculation_standard_source'] ?? null,
                'divisor_applied' => $resolvedAmount['divisor_applied'] ?? null,
                'payroll_run_type' => $resolvedAmount['payroll_run_type'] ?? null,
                'pay_component_resolution' => $resolvedAmount,
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
     * Payroll Standard means the configured amount is already per payroll run.
     * Do not run monthly attendance/schedule distribution after the central resolver
     * has selected it for the current run.
     *
     * @param  array<string, mixed>  $resolvedAmount
     */
    private function isPayrollStandardResolution(array $resolvedAmount): bool
    {
        return CalculationStandard::normalizeDefault(
            $resolvedAmount['resolved_calculation_standard']
                ?? $resolvedAmount['calculation_standard']
                ?? null
        ) === PayComponent::STANDARD_PAYROLL;
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
     * Proratable allowances are payable-day based: schedule decides whether the component
     * applies in this run, then the amount is monthly allowance / monthly workdays * payable days.
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
        $attendanceProration = is_array($attendanceProration) ? $attendanceProration : [];
        $allowanceMeta = is_array($attendanceProration['allowance'] ?? null)
            ? $attendanceProration['allowance']
            : [];
        $monthlyAmount = round(max(0.0, (float) ($line['computed_amount'] ?? 0)), 2);
        $monthlyDivisor = max(1.0, (float) ($allowanceMeta['monthly_divisor_days'] ?? 0));
        $payableDayUnits = max(0.0, (float) ($allowanceMeta['payable_day_units'] ?? $allowanceMeta['worked_day_units'] ?? 0));
        $unpaidAbsentDays = max(0.0, (float) ($allowanceMeta['unpaid_absent_days'] ?? 0));
        $presentDays = max(0.0, (float) ($allowanceMeta['present_day_units'] ?? 0));
        $approvedPaidLeaveDays = max(0.0, (float) ($allowanceMeta['approved_paid_leave_day_units'] ?? 0));
        $approvedCorrectionDays = max(0.0, (float) ($allowanceMeta['approved_correction_day_units'] ?? 0));
        $scheduleFactor = max(0.0, min(1.0, $scheduleFactor));
        $isApplicable = $scheduleFactor > 0.0;

        $fullMonthDailyRate = round($monthlyAmount / $monthlyDivisor, 6);
        $scheduledBaseForRun = $isApplicable ? round($monthlyAmount * $scheduleFactor, 2) : 0.0;
        $amountByPayableDays = round($fullMonthDailyRate * $payableDayUnits, 2);
        $unpaidAbsenceDeduction = $isApplicable ? round($fullMonthDailyRate * $unpaidAbsentDays, 2) : 0.0;
        $originalProratedBeforeSchedule = $amountByPayableDays;
        $amount = $isApplicable ? $amountByPayableDays : 0.0;
        $scheduleDivisor = ($isApplicable && $scheduleFactor > 0.0)
            ? round(1.0 / $scheduleFactor, 4)
            : null;

        return [
            'allowance_type' => 'attendance_prorated',
            'configured_monthly_amount' => round($monthlyAmount, 2),
            'schedule_configuration' => $scheduleType,
            'selected_allowance_schedule_type' => $scheduleType,
            'current_payroll_run_type' => $currentRunType,
            'schedule_factor' => round($scheduleFactor, 6),
            'schedule_adjustment_multiplier' => round($scheduleFactor, 6),
            'schedule_adjustment_divisor' => $scheduleDivisor,
            'is_applicable_in_run' => $isApplicable,
            'original_prorated_allowance_before_schedule_adjustment' => $originalProratedBeforeSchedule,
            'allowance_base_before_proration' => $isApplicable ? round($monthlyAmount, 2) : 0.0,
            'scheduled_base_for_run_before_absence_deduction' => $scheduledBaseForRun,
            'payable_days_base_amount' => $amountByPayableDays,
            'unpaid_absence_deduction' => $unpaidAbsenceDeduction,
            'unpaid_absent_days' => round($unpaidAbsentDays, 6),
            'payable_day_units' => round($payableDayUnits, 6),
            'present_day_units' => round($presentDays, 6),
            'approved_paid_leave_day_units' => round($approvedPaidLeaveDays, 6),
            'approved_correction_day_units' => round($approvedCorrectionDays, 6),
            'proration_basis' => 'payable_days',
            'monthly_divisor_days' => round($monthlyDivisor, 4),
            'base_divisor_days' => round($monthlyDivisor, 4),
            'daily_allowance_rate' => round($fullMonthDailyRate, 6),
            'worked_day_units' => round($payableDayUnits, 6),
            'worked_minutes' => (int) ($allowanceMeta['worked_minutes'] ?? 0),
            'converted_hours' => round((float) ($allowanceMeta['converted_hours'] ?? 0), 4),
            'daily_hours' => round((float) ($allowanceMeta['daily_hours'] ?? 8), 4),
            'divisor_source' => (string) ($allowanceMeta['divisor_source'] ?? 'stable_schedule_monthly'),
            'attendance_counted' => is_array($allowanceMeta['attendance_counted'] ?? null) ? $allowanceMeta['attendance_counted'] : [],
            'attendance_excluded' => is_array($allowanceMeta['attendance_excluded'] ?? null) ? $allowanceMeta['attendance_excluded'] : [],
            'unpaid_absences' => is_array($allowanceMeta['unpaid_absences'] ?? null) ? $allowanceMeta['unpaid_absences'] : [],
            'effective_factor' => $monthlyAmount > 0 ? round($amount / $monthlyAmount, 6) : 0.0,
            'final_allowance_after_schedule_adjustment' => $amount,
            'amount' => $amount,
        ];
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

        $pcId = $line['pay_component_id'] ?? null;
        $assignmentId = isset($line['id']) && is_numeric($line['id']) ? (int) $line['id'] : null;
        $scheduleMeta = $pcId
            ? $this->resolveEmployeePayComponentSchedule(
                (int) $pcId,
                $user->getEffectiveCompanyId(),
                (int) $user->id,
                array_key_exists('schedule_override', $line),
                array_key_exists('schedule_override', $line) ? (is_scalar($line['schedule_override']) ? (string) $line['schedule_override'] : null) : null,
                $assignmentId,
            )
            : null;

        if (BulkPayrollDraftContext::$active) {
            return;
        }

        Log::info('payroll.allowance_proration', [
            'employee_id' => (int) $user->id,
            'pay_component_id' => $pcId,
            'code' => $line['code'] ?? null,
            'name' => $line['name'] ?? null,
            'period_start' => $periodStartRaw,
            'period_end' => $periodEndRaw,
            'pay_cycle_start' => $periodStartRaw,
            'pay_cycle_end' => $periodEndRaw,
            'schedule_override' => $scheduleMeta['schedule_override'] ?? null,
            'default_schedule' => $scheduleMeta['default_schedule'] ?? null,
            'resolved_schedule' => $scheduleMeta['resolved_schedule'] ?? null,
            'schedule_source' => $scheduleMeta['schedule_source'] ?? null,
            'resolved_schedule_type' => $scheduleType,
            'schedule_type' => $scheduleType,
            'allowance_type' => $allowanceProration['allowance_type'] ?? null,
            'configured_monthly_amount' => $allowanceProration['configured_monthly_amount'] ?? null,
            'monthly_allowance_amount' => $allowanceProration['configured_monthly_amount'] ?? null,
            'selected_allowance_schedule' => $allowanceProration['selected_allowance_schedule_type'] ?? $allowanceProration['schedule_configuration'] ?? null,
            'schedule_configuration' => $allowanceProration['schedule_configuration'] ?? null,
            'current_payroll_run_type' => $allowanceProration['current_payroll_run_type'] ?? null,
            'pro_ratable' => true,
            'pro_ratable_status' => true,
            'original_prorated_allowance_before_schedule_adjustment' => $allowanceProration['original_prorated_allowance_before_schedule_adjustment'] ?? null,
            'schedule_factor' => $allowanceProration['schedule_factor'] ?? null,
            'schedule_adjustment_multiplier' => $allowanceProration['schedule_adjustment_multiplier'] ?? null,
            'schedule_adjustment_divisor' => $allowanceProration['schedule_adjustment_divisor'] ?? null,
            'is_applicable_in_run' => $allowanceProration['is_applicable_in_run'] ?? null,
            'allowance_base_before_proration' => $allowanceProration['allowance_base_before_proration'] ?? null,
            'scheduled_base_for_run_before_absence_deduction' => $allowanceProration['scheduled_base_for_run_before_absence_deduction'] ?? null,
            'unpaid_absence_deduction' => $allowanceProration['unpaid_absence_deduction'] ?? null,
            'unpaid_absent_days' => $allowanceProration['unpaid_absent_days'] ?? null,
            'unpaid_absent_day_units' => $allowanceProration['unpaid_absent_days'] ?? null,
            'payable_day_units' => $allowanceProration['payable_day_units'] ?? null,
            'final_payable_days' => $allowanceProration['payable_day_units'] ?? null,
            'present_days' => $allowanceProration['present_day_units'] ?? null,
            'approved_paid_leave_days' => $allowanceProration['approved_paid_leave_day_units'] ?? null,
            'approved_correction_days' => $allowanceProration['approved_correction_day_units'] ?? null,
            'proration_basis' => $allowanceProration['proration_basis'] ?? null,
            'monthly_divisor_days' => $allowanceProration['monthly_divisor_days'] ?? null,
            'employee_monthly_scheduled_workdays' => $allowanceProration['monthly_divisor_days'] ?? null,
            'base_divisor_days' => $allowanceProration['base_divisor_days'] ?? null,
            'daily_allowance_rate' => $allowanceProration['daily_allowance_rate'] ?? null,
            'worked_day_units' => $allowanceProration['worked_day_units'] ?? null,
            'converted_hours' => $allowanceProration['converted_hours'] ?? null,
            'daily_hours' => $allowanceProration['daily_hours'] ?? null,
            'attendance_counted' => $allowanceProration['attendance_counted'] ?? [],
            'attendance_excluded' => $allowanceProration['attendance_excluded'] ?? [],
            'unpaid_absences' => $allowanceProration['unpaid_absences'] ?? [],
            'final_allowance_after_schedule_adjustment' => $allowanceProration['final_allowance_after_schedule_adjustment'] ?? $allowanceProration['amount'] ?? null,
            'final_allowance_amount' => $allowanceProration['amount'] ?? null,
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

        $row = DeductionScheduleSetting::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'deduction_key' => $deductionKey,
            ],
            ['schedule_type' => $scheduleType]
        );
        $this->forgetScheduleCache($companyId, $deductionKey);

        return $row;
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

    private function forgetScheduleCache(?int $companyId, string $deductionKey): void
    {
        foreach (array_unique([$companyId, null]) as $scopeCompanyId) {
            $cacheKey = self::CACHE_PREFIX.md5((string) ($scopeCompanyId ?? 'global').'|'.$deductionKey);
            Cache::forget($cacheKey);
        }
    }
}
