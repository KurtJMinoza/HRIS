<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DeductionScheduleSetting;
use App\Models\PayCycle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PayCycleService
{
    /**
     * Company default cut-off/pay-date logic (no PayCycle template selected).
     *
     * Recurring pattern (dynamic for any month/year):
     * - Cut-off 11–25 -> Pay date = month-end of same month (weekend-adjusted)
     * - Cut-off 26–10 -> Pay date = 15th of month containing the cut-off end (weekend-adjusted)
     *
     * @return array{
     *   reference_date: string,
     *   cut_off_start_date: string,
     *   cut_off_end_date: string,
     *   pay_date: string,
     *   cycle_label: string,
     *   weekend_adjusted: bool,
     *   weekend_adjustment_note: string|null,
     *   semi_month_segment: 'first'|'second'
     * }
     */
    public function buildCompanyDefaultPreview(Carbon|string|null $anchorDate = null): array
    {
        $anchor = $anchorDate instanceof Carbon
            ? $anchorDate->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($anchorDate ?: now()), $this->timezone())->startOfDay();
        $period = $this->resolveCompanyDefaultPeriodFromAnchor($anchor);

        $cycleLabel = sprintf(
            '%s %s, %s – %s %s, %s',
            $period['start']->format('F'),
            $period['start']->format('j'),
            $period['start']->format('Y'),
            $period['end']->format('F'),
            $period['end']->format('j'),
            $period['end']->format('Y'),
        );

        return [
            // Requirement: reference date must be the pay date.
            'reference_date' => $period['pay_date']->toDateString(),
            'cut_off_start_date' => $period['start']->toDateString(),
            'cut_off_end_date' => $period['end']->toDateString(),
            'pay_date' => $period['pay_date']->toDateString(),
            'cycle_label' => $cycleLabel,
            'weekend_adjusted' => $period['weekend_adjusted'],
            'weekend_adjustment_note' => $period['weekend_adjusted']
                ? 'Weekend adjustment applied: pay dates landing on Saturday or Sunday move to the previous Friday.'
                : null,
            'semi_month_segment' => $period['semi_month_segment'],
        ];
    }

    /**
     * Company default preview but anchored by a pay date (reference date = pay date).
     * Infers which segment applies by matching the adjusted 15th or adjusted month-end.
     */
    public function buildCompanyDefaultPreviewFromPayDate(Carbon|string $payDate): array
    {
        $ref = $payDate instanceof Carbon
            ? $payDate->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) $payDate, $this->timezone())->startOfDay();
        $candidates = [
            // Try nearby anchors to cover adjusted 15th/EOM dates in all months.
            $ref->copy()->day(10)->startOfDay(),
            $ref->copy()->day(25)->startOfDay(),
            $ref->copy()->subMonthNoOverflow()->day(25)->startOfDay(),
            $ref->copy()->addMonthNoOverflow()->day(10)->startOfDay(),
            $ref->copy(),
        ];

        foreach ($candidates as $anchor) {
            $preview = $this->buildCompanyDefaultPreview($anchor);
            if (($preview['pay_date'] ?? null) === $ref->toDateString()) {
                // Reference date contract: use exact selected pay date.
                $preview['reference_date'] = $ref->toDateString();

                return $preview;
            }
        }

        // Fallback: treat provided date as anchor.
        return $this->buildCompanyDefaultPreview($ref);
    }

    public function supportsDefaultFlag(): bool
    {
        return Schema::hasTable('pay_cycles') && Schema::hasColumn('pay_cycles', 'is_default');
    }

    public function timezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Set each employee's pay_cycle_id to their company's current default_pay_cycle_id (or null).
     * Call after pay cycles are saved or company defaults change.
     *
     * @param  array<int|string>  $companyIds
     */
    public function applyDefaultPayCyclesToEmployeesForCompanies(array $companyIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $companyIds), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return;
        }

        foreach ($ids as $companyId) {
            $company = Company::query()->find($companyId);
            if (! $company) {
                continue;
            }

            User::query()
                ->where('company_id', $companyId)
                ->where('role', User::ROLE_EMPLOYEE)
                ->update(['pay_cycle_id' => $company->default_pay_cycle_id]);
        }
    }

    /**
     * True when the user's stored pay cycle matches the company's default (bulk-synced from Pay Cycle admin).
     * Uses {@see User::getEffectiveCompanyId()} so Company Heads (often without `users.company_id`) still match their head company.
     */
    public function isPayCycleInheritedFromCompany(User $user): bool
    {
        $effectiveCompanyId = $user->getEffectiveCompanyId();
        if ($effectiveCompanyId === null) {
            return false;
        }

        $company = Company::query()->find($effectiveCompanyId);
        $defaultId = $company?->default_pay_cycle_id;
        if ($defaultId === null) {
            return false;
        }

        return (int) ($user->pay_cycle_id ?? 0) === (int) $defaultId;
    }

    /**
     * Resolve pay cycle for payroll / Salary tab preview. Handles Company Heads who may only be linked via
     * {@see Company::company_head_id} (no `users.company_id` / branch), so company default must still apply.
     */
    public function resolveForUser(User $user): ?PayCycle
    {
        if ($user->relationLoaded('payCycle') && $user->payCycle) {
            return $user->payCycle;
        }

        if ($user->pay_cycle_id) {
            $direct = PayCycle::query()->find($user->pay_cycle_id);
            if ($direct) {
                return $direct;
            }
        }

        $user->loadMissing([
            'payCycle',
            'branch.defaultPayCycle',
            'company.defaultPayCycle',
            'companyHeadships.defaultPayCycle',
        ]);

        if ($user->branch?->default_pay_cycle_id) {
            return $user->branch->defaultPayCycle ?: PayCycle::query()->find($user->branch->default_pay_cycle_id);
        }

        if ($user->company?->default_pay_cycle_id) {
            return $user->company->defaultPayCycle ?: PayCycle::query()->find($user->company->default_pay_cycle_id);
        }

        $headCompany = $user->companyHeadships->first();
        if (! $headCompany) {
            $headCompany = Company::query()
                ->where('company_head_id', $user->id)
                ->first();
        }
        if ($headCompany) {
            $headCompany->loadMissing('defaultPayCycle');
            if ($headCompany->default_pay_cycle_id) {
                return $headCompany->defaultPayCycle ?: PayCycle::query()->find($headCompany->default_pay_cycle_id);
            }
        }

        $effectiveCompanyId = $user->getEffectiveCompanyId();
        if ($effectiveCompanyId === null) {
            return null;
        }

        return $this->firstActivePayCycleForCompany($effectiveCompanyId, preferDefault: true)
            ?? $this->firstActivePayCycleForCompany($effectiveCompanyId, preferDefault: false);
    }

    /**
     * Active pay cycles for a company: prefer `is_default` when schema supports it.
     */
    private function firstActivePayCycleForCompany(int $companyId, bool $preferDefault): ?PayCycle
    {
        $query = PayCycle::query()
            ->where(function ($builder) use ($companyId) {
                $builder->where('company_id', $companyId)
                    ->orWhereHas('companies', fn ($companies) => $companies->where('companies.id', $companyId));
            })
            ->where('is_active', true)
            ->orderBy('id');

        if ($preferDefault && $this->supportsDefaultFlag()) {
            $query->where('is_default', true);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewForUser(User $user, Carbon|string|null $referenceDate = null): ?array
    {
        $cycle = $this->resolveForUser($user);
        if (! $cycle) {
            return null;
        }

        $reference = $referenceDate instanceof Carbon
            ? $referenceDate->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($referenceDate ?: now()), $this->timezone())->startOfDay();

        return $this->buildCyclePreview($cycle, $reference);
    }

    /**
     * Return pay-cycle windows and pay dates that overlap a date range.
     *
     * @return array{
     *   start_date: string,
     *   end_date: string,
     *   periods: list<array{
     *     cut_off_start_date: string,
     *     cut_off_end_date: string,
     *     pay_date: string,
     *     cycle_label: string,
     *     weekend_adjusted: bool,
     *     weekend_adjustment_note: string|null,
     *     semi_month_segment: string|null
     *   }>,
     *   pay_dates: list<string>
     * }
     */
    public function getPayDatesForPeriod(
        Carbon|string $startDate,
        Carbon|string $endDate,
        ?PayCycle $cycle = null,
        ?User $user = null
    ): array {
        $start = $this->normalizeDate($startDate);
        $end = $this->normalizeDate($endDate);
        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $effectiveCycle = $cycle ?? ($user ? $this->resolveForUser($user) : null);
        if (! $effectiveCycle) {
            return $this->companyDefaultPayDatesForPeriod($start, $end);
        }

        $seed = $start->copy()->subMonthNoOverflow()->startOfDay();
        $daySpan = max(1, (int) $start->diffInDays($end) + 1);
        $periodCount = $this->estimatePeriodsForRange($effectiveCycle, $daySpan);
        $generated = $this->generatePayPeriods($effectiveCycle, $seed, $periodCount);
        $note = 'Weekend adjustment applied: pay dates landing on Saturday or Sunday move to the previous Friday.';
        $periods = [];
        foreach ($generated as $item) {
            $itemStart = ($item['start'] ?? null) instanceof Carbon
                ? $item['start']->copy()->setTimezone($this->timezone())->startOfDay()
                : Carbon::parse((string) ($item['start'] ?? ''), $this->timezone())->startOfDay();
            $itemEnd = ($item['end'] ?? null) instanceof Carbon
                ? $item['end']->copy()->setTimezone($this->timezone())->startOfDay()
                : Carbon::parse((string) ($item['end'] ?? ''), $this->timezone())->startOfDay();
            if ($itemEnd->lt($start) || $itemStart->gt($end)) {
                continue;
            }
            $periods[] = [
                'cut_off_start_date' => $itemStart->toDateString(),
                'cut_off_end_date' => $itemEnd->toDateString(),
                'pay_date' => ($item['pay_date'] instanceof Carbon ? $item['pay_date'] : Carbon::parse((string) $item['pay_date'], $this->timezone()))
                    ->copy()->setTimezone($this->timezone())->toDateString(),
                'cycle_label' => (string) ($item['cycle_label'] ?? ''),
                'weekend_adjusted' => (bool) ($item['weekend_adjusted'] ?? false),
                'weekend_adjustment_note' => ! empty($item['weekend_adjusted']) ? $note : null,
                'semi_month_segment' => isset($item['semi_month_segment']) ? (string) $item['semi_month_segment'] : null,
            ];
        }

        usort($periods, fn (array $a, array $b) => strcmp($a['cut_off_start_date'], $b['cut_off_start_date']));
        $payDates = collect($periods)->pluck('pay_date')->filter()->unique()->values()->all();

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'periods' => $periods,
            'pay_dates' => $payDates,
        ];
    }

    /**
     * Resolve next deduction date(s) for 15th/30th/both schedules.
     *
     * @return array{
     *   schedule_type: string,
     *   as_of_date: string,
     *   next_dates: list<string>,
     *   periods: list<array<string, mixed>>
     * }
     */
    public function getNextDeductionDate(
        User $employee,
        string $scheduleType,
        Carbon|string|null $asOfDate = null,
        ?PayCycle $cycle = null
    ): array {
        $asOf = $this->normalizeDate($asOfDate ?: now());
        $effectiveCycle = $cycle ?? $this->resolveForUser($employee);
        $window = $this->getPayDatesForPeriod($asOf, $asOf->copy()->addMonthsNoOverflow(6), $effectiveCycle, $employee);
        $periods = collect($window['periods'] ?? [])
            ->filter(fn (array $row) => Carbon::parse((string) $row['pay_date'], $this->timezone())->gte($asOf))
            ->values();

        $normalizedSchedule = in_array($scheduleType, [
            DeductionScheduleSetting::SCHEDULE_15TH,
            DeductionScheduleSetting::SCHEDULE_30TH,
            DeductionScheduleSetting::SCHEDULE_BOTH,
        ], true) ? $scheduleType : DeductionScheduleSetting::SCHEDULE_BOTH;

        $eligible = $periods->filter(function (array $row) use ($normalizedSchedule) {
            $segment = (string) ($row['semi_month_segment'] ?? '');
            if ($segment === 'first' || $segment === 'second') {
                return match ($normalizedSchedule) {
                    DeductionScheduleSetting::SCHEDULE_15TH => $segment === 'first',
                    DeductionScheduleSetting::SCHEDULE_30TH => $segment === 'second',
                    default => true,
                };
            }

            // Non-semi-monthly fallback: 15th => first upcoming run, 30th => second upcoming run, both => first two runs.
            return true;
        })->values();

        if (! $eligible->count()) {
            $eligible = $periods;
        }

        if ($normalizedSchedule === DeductionScheduleSetting::SCHEDULE_15TH) {
            $selected = $eligible->take(1);
        } elseif ($normalizedSchedule === DeductionScheduleSetting::SCHEDULE_30TH) {
            $segmentFiltered = $eligible->filter(fn (array $row) => ($row['semi_month_segment'] ?? null) === 'second')->values();
            $selected = $segmentFiltered->count() > 0 ? $segmentFiltered->take(1) : $eligible->skip(1)->take(1);
            if (! $selected->count()) {
                $selected = $eligible->take(1);
            }
        } else {
            $selected = $eligible->take(2);
        }

        return [
            'schedule_type' => $normalizedSchedule,
            'as_of_date' => $asOf->toDateString(),
            'next_dates' => $selected->pluck('pay_date')->filter()->values()->all(),
            'periods' => $selected->values()->all(),
        ];
    }

    /**
     * Unified helper for earnings/deductions/loans: pay dates applicable to a concrete period.
     *
     * @return array{
     *   start_date:string,
     *   end_date:string,
     *   periods:list<array<string,mixed>>,
     *   pay_dates:list<string>
     * }
     */
    public function getApplicablePayDates(
        User $employee,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        ?PayCycle $cycle = null
    ): array {
        return $this->getPayDatesForPeriod($periodStart, $periodEnd, $cycle, $employee);
    }

    /**
     * Schedule gate for a concrete cut-off run.
     *
     * @param  'first'|'second'|null  $cutOffType
     */
    public function shouldApplyOnThisCutOff(string $scheduleType, ?string $cutOffType): bool
    {
        $sched = strtolower(trim($scheduleType));
        if ($sched === DeductionScheduleSetting::SCHEDULE_BOTH) {
            return true;
        }
        if (! in_array($cutOffType, ['first', 'second'], true)) {
            return true;
        }

        return match ($sched) {
            DeductionScheduleSetting::SCHEDULE_15TH => $cutOffType === 'first',
            DeductionScheduleSetting::SCHEDULE_30TH => $cutOffType === 'second',
            default => true,
        };
    }

    /**
     * @return array{start: Carbon, end: Carbon, pay_date: Carbon, reference_date: Carbon, cycle_label: string}
     */
    public function getCutOffPeriod(PayCycle $cycle, Carbon|string|null $referenceDate = null): array
    {
        $reference = $referenceDate instanceof Carbon
            ? $referenceDate->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($referenceDate ?: now()), $this->timezone())->startOfDay();

        return match ($cycle->code) {
            PayCycle::CODE_SEMI_MONTHLY => $this->semiMonthlyPeriod($cycle, $reference),
            PayCycle::CODE_WEEKLY => $this->weeklyPeriod($cycle, $reference, 7),
            PayCycle::CODE_BI_WEEKLY => $this->weeklyPeriod($cycle, $reference, 14),
            PayCycle::CODE_DAILY => $this->dailyPeriod($cycle, $reference),
            PayCycle::CODE_PROJECT => $this->projectPeriod($cycle, $reference),
            default => $this->monthlyPeriod($cycle, $reference),
        };
    }

    public function calculateProrationFactor(
        float $daysWorked,
        float $requiredDays,
        float $hoursWorked = 0,
        float $requiredHours = 0,
        string $proRationType = PayCycle::PRORATION_NONE
    ): float {
        return match ($proRationType) {
            PayCycle::PRORATION_DAILY => $requiredDays > 0 ? round(max(0.0, min(1.0, $daysWorked / $requiredDays)), 4) : 1.0,
            PayCycle::PRORATION_HOURLY => $requiredHours > 0 ? round(max(0.0, min(1.0, $hoursWorked / $requiredHours)), 4) : 1.0,
            default => 1.0,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function generatePayPeriods(PayCycle $cycle, Carbon|string|null $fromDate = null, int $numberOfPeriods = 6): array
    {
        $reference = $fromDate instanceof Carbon
            ? $fromDate->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) ($fromDate ?: now()), $this->timezone())->startOfDay();

        return app(PayrollCalculatorService::class)->generatePayPeriods($cycle, $reference, $numberOfPeriods);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCyclePreview(PayCycle $cycle, Carbon|string|null $referenceDate = null): array
    {
        $period = $this->getCutOffPeriod($cycle, $referenceDate);
        $generatedPeriods = $this->generatePayPeriods($cycle, $period['reference_date'], 6);
        $firstGenerated = (is_array($generatedPeriods) && isset($generatedPeriods[0]) && is_array($generatedPeriods[0]))
            ? $generatedPeriods[0]
            : null;

        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'code' => $cycle->code,
            'cut_off_type' => $cycle->cut_off_type,
            'cut_off_value' => $cycle->cut_off_value,
            'pay_day_type' => $cycle->pay_day_type,
            'pay_day_value' => $cycle->pay_day_value,
            'pay_day_offset' => $cycle->pay_day_offset,
            'pro_ration_type' => $cycle->pro_ration_type,
            'weekend_adjustment_rule' => (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY),
            'reference_date' => $period['reference_date']->toDateString(),
            'cut_off_start_date' => $period['start']->toDateString(),
            'cut_off_end_date' => $period['end']->toDateString(),
            'pay_date' => $period['pay_date']->toDateString(),
            'cycle_label' => $period['cycle_label'],
            'period_days' => $period['start']->diffInDays($period['end']) + 1,
            'preview_line' => sprintf(
                '%s %s, %s – %s %s, %s -> Pay Date: %s %s, %s',
                $period['start']->format('F'),
                $period['start']->format('j'),
                $period['start']->format('Y'),
                $period['end']->format('F'),
                $period['end']->format('j'),
                $period['end']->format('Y'),
                $period['pay_date']->format('F'),
                $period['pay_date']->format('j'),
                $period['pay_date']->format('Y')
            ),
            'weekend_adjusted' => (bool) ($firstGenerated['weekend_adjusted'] ?? false),
            'preview_periods' => collect($generatedPeriods)->map(function (array $item) {
                return [
                    'cut_off_start_date' => $item['start']->toDateString(),
                    'cut_off_end_date' => $item['end']->toDateString(),
                    'pay_date' => $item['pay_date']->toDateString(),
                    'cycle_label' => $item['cycle_label'],
                    'period_days' => (int) ($item['period_days'] ?? ($item['start']->diffInDays($item['end']) + 1)),
                    'weekend_adjusted' => (bool) ($item['weekend_adjusted'] ?? false),
                    'semi_month_segment' => isset($item['semi_month_segment']) ? (string) $item['semi_month_segment'] : null,
                    'preview_line' => (string) ($item['preview_line'] ?? sprintf(
                        '%s %s, %s – %s %s, %s -> Pay Date: %s %s, %s',
                        $item['start']->format('F'),
                        $item['start']->format('j'),
                        $item['start']->format('Y'),
                        $item['end']->format('F'),
                        $item['end']->format('j'),
                        $item['end']->format('Y'),
                        $item['pay_date']->format('F'),
                        $item['pay_date']->format('j'),
                        $item['pay_date']->format('Y')
                    )),
                ];
            })->values()->all(),
            'weekend_adjustment_note' => collect($generatedPeriods)->contains(fn (array $item) => ! empty($item['weekend_adjusted']))
                ? 'Weekend adjustment applied: pay dates landing on Saturday or Sunday move to the previous Friday.'
                : null,
        ];
    }

    /**
     * @return Collection<int, PayCycle>
     */
    public function listScoped(?int $companyId = null): Collection
    {
        $query = PayCycle::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name');

        if ($this->supportsDefaultFlag()) {
            $query->orderByDesc('is_default');
        }

        return $query->get();
    }

    private function monthlyPeriod(PayCycle $cycle, Carbon $reference): array
    {
        $start = $reference->copy()->startOfMonth();
        $end = $reference->copy()->endOfMonth();

        return [
            'start' => $start,
            'end' => $end,
            'pay_date' => $this->resolvePayDate($cycle, $end, $reference),
            'reference_date' => $reference,
            'cycle_label' => sprintf('%s %s', $reference->format('F'), $reference->format('Y')),
        ];
    }

    private function semiMonthlyPeriod(PayCycle $cycle, Carbon $reference): array
    {
        $generated = app(PayrollCalculatorService::class)->generatePayPeriods($cycle, $reference, 1);
        $current = (is_array($generated) && isset($generated[0])) ? $generated[0] : null;

        if ($current) {
            return [
                'start' => $current['start']->copy()->startOfDay(),
                'end' => $current['end']->copy()->startOfDay(),
                'pay_date' => $current['pay_date']->copy()->startOfDay(),
                'reference_date' => $current['reference_date']->copy()->startOfDay(),
                'cycle_label' => (string) $current['cycle_label'],
                'semi_month_segment' => $current['semi_month_segment'] ?? null,
            ];
        }

        return [
            'start' => $reference->copy()->startOfMonth()->startOfDay(),
            'end' => $reference->copy()->day(min(10, $reference->copy()->endOfMonth()->day))->startOfDay(),
            'pay_date' => $this->resolvePayDate($cycle, $reference->copy()->day(min(10, $reference->copy()->endOfMonth()->day)), $reference),
            'reference_date' => $reference,
            'cycle_label' => sprintf('%s %s', $reference->format('F'), $reference->format('Y')),
        ];
    }

    private function weeklyPeriod(PayCycle $cycle, Carbon $reference, int $lengthDays): array
    {
        $anchor = strtolower((string) data_get($cycle->cut_off_value, 'day_of_week', data_get($cycle->cut_off_value, 'anchor_day', 'monday')));
        $map = [
            'sunday' => Carbon::SUNDAY,
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
        ];
        $anchorDow = $map[$anchor] ?? Carbon::MONDAY;
        $daysBack = ($reference->dayOfWeek - $anchorDow + 7) % 7;
        $start = $reference->copy()->subDays($daysBack)->startOfDay();
        $end = $start->copy()->addDays($lengthDays - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'pay_date' => $this->resolvePayDate($cycle, $end, $reference),
            'reference_date' => $reference,
            'cycle_label' => sprintf('%s to %s', $start->format('M j, Y'), $end->format('M j, Y')),
        ];
    }

    private function dailyPeriod(PayCycle $cycle, Carbon $reference): array
    {
        return [
            'start' => $reference->copy(),
            'end' => $reference->copy(),
            'pay_date' => $this->resolvePayDate($cycle, $reference, $reference),
            'reference_date' => $reference,
            'cycle_label' => sprintf('Daily - %s', $reference->format('M j, Y')),
        ];
    }

    private function projectPeriod(PayCycle $cycle, Carbon $reference): array
    {
        $customStart = data_get($cycle->cut_off_value, 'start_date');
        $customEnd = data_get($cycle->cut_off_value, 'end_date');
        $start = $customStart ? Carbon::parse((string) $customStart, $this->timezone())->startOfDay() : $reference->copy()->startOfMonth();
        $end = $customEnd ? Carbon::parse((string) $customEnd, $this->timezone())->startOfDay() : $reference->copy()->endOfMonth();

        return [
            'start' => $start,
            'end' => $end,
            'pay_date' => $this->resolvePayDate($cycle, $end, $reference),
            'reference_date' => $reference,
            'cycle_label' => $cycle->name,
        ];
    }

    private function resolvePayDate(PayCycle $cycle, Carbon $periodEnd, Carbon $reference): Carbon
    {
        if ($cycle->pay_day_type === PayCycle::PAY_DAY_FIXED_DAY) {
            $day = (int) (data_get($cycle->pay_day_value, 'day') ?: $cycle->pay_day_offset ?: $periodEnd->day);
            $baseMonth = $periodEnd->copy()->addMonthNoOverflow()->startOfMonth();

            return app(PayrollCalculatorService::class)->adjustForWeekend(
                $baseMonth->copy()->day(min($day, $baseMonth->copy()->endOfMonth()->day))->startOfDay(),
                (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY)
            );
        }

        if ($cycle->pay_day_type === PayCycle::PAY_DAY_CUSTOM) {
            $date = data_get($cycle->pay_day_value, 'date');
            if ($date) {
                return app(PayrollCalculatorService::class)->adjustForWeekend(
                    Carbon::parse((string) $date, $this->timezone())->startOfDay(),
                    (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY)
                );
            }
        }

        $offset = (int) ($cycle->pay_day_offset ?? data_get($cycle->pay_day_value, 'offset', 5));

        return app(PayrollCalculatorService::class)->adjustForWeekend(
            $periodEnd->copy()->addDays($offset)->startOfDay(),
            (string) data_get($cycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY)
        );
    }

    private function normalizeDate(Carbon|string $value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->setTimezone($this->timezone())->startOfDay()
            : Carbon::parse((string) $value, $this->timezone())->startOfDay();
    }

    /**
     * @return array{start_date:string,end_date:string,periods:list<array<string,mixed>>,pay_dates:list<string>}
     */
    private function companyDefaultPayDatesForPeriod(Carbon $start, Carbon $end): array
    {
        $periods = [];
        $cursor = $start->copy();
        while ($cursor->lte($end->copy()->addMonthNoOverflow())) {
            $preview = $this->buildCompanyDefaultPreview($cursor);
            $periodStart = Carbon::parse((string) $preview['cut_off_start_date'], $this->timezone())->startOfDay();
            $periodEnd = Carbon::parse((string) $preview['cut_off_end_date'], $this->timezone())->startOfDay();
            if (! $periodEnd->lt($start) && ! $periodStart->gt($end)) {
                $periods[] = [
                    'cut_off_start_date' => $preview['cut_off_start_date'],
                    'cut_off_end_date' => $preview['cut_off_end_date'],
                    'pay_date' => $preview['pay_date'],
                    'cycle_label' => (string) ($preview['cycle_label'] ?? ''),
                    'weekend_adjusted' => (bool) ($preview['weekend_adjusted'] ?? false),
                    'weekend_adjustment_note' => $preview['weekend_adjustment_note'] ?? null,
                    'semi_month_segment' => in_array(($preview['semi_month_segment'] ?? null), ['first', 'second'], true)
                        ? (string) $preview['semi_month_segment']
                        : null,
                ];
            }
            $cursor = $periodEnd->copy()->addDay()->startOfDay();
        }

        $periods = collect($periods)
            ->unique(fn (array $row) => ($row['cut_off_start_date'] ?? '').'|'.($row['cut_off_end_date'] ?? ''))
            ->sortBy('cut_off_start_date')
            ->values()
            ->all();

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'periods' => $periods,
            'pay_dates' => collect($periods)->pluck('pay_date')->filter()->unique()->values()->all(),
        ];
    }

    private function estimatePeriodsForRange(PayCycle $cycle, int $daySpan): int
    {
        $approxLen = match ($cycle->code) {
            PayCycle::CODE_DAILY => 1,
            PayCycle::CODE_WEEKLY => 7,
            PayCycle::CODE_BI_WEEKLY => 14,
            PayCycle::CODE_MONTHLY, PayCycle::CODE_PROJECT => 30,
            default => 15, // semi-monthly and fallback
        };

        // Add headroom so cross-boundary overlaps and weekend-adjusted pay-date lookups are always covered.
        return max(8, (int) ceil($daySpan / max(1, $approxLen)) + 8);
    }

    /**
     * @return array{
     *   start: Carbon,
     *   end: Carbon,
     *   pay_date: Carbon,
     *   weekend_adjusted: bool,
     *   semi_month_segment: 'first'|'second'
     * }
     */
    private function resolveCompanyDefaultPeriodFromAnchor(Carbon $anchor): array
    {
        $day = (int) $anchor->day;
        if ($day >= 11 && $day <= 25) {
            $start = $anchor->copy()->day(11)->startOfDay();
            $end = $anchor->copy()->day(25)->startOfDay();
            $unadjustedPay = $anchor->copy()->endOfMonth()->startOfDay();
            $segment = 'second';
        } elseif ($day >= 26) {
            $start = $anchor->copy()->day(26)->startOfDay();
            $end = $anchor->copy()->addMonthNoOverflow()->startOfMonth()->day(10)->startOfDay();
            $unadjustedPay = $end->copy()->day(15)->startOfDay();
            $segment = 'first';
        } else {
            // day 1–10 belongs to prior month 26–10 window.
            $end = $anchor->copy()->day(10)->startOfDay();
            $start = $anchor->copy()->subMonthNoOverflow()->startOfMonth()->day(26)->startOfDay();
            $unadjustedPay = $end->copy()->day(15)->startOfDay();
            $segment = 'first';
        }

        $payDate = app(PayrollCalculatorService::class)->adjustForWeekend(
            $unadjustedPay,
            PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY
        );

        return [
            'start' => $start,
            'end' => $end,
            'pay_date' => $payDate,
            'weekend_adjusted' => ! $payDate->isSameDay($unadjustedPay),
            'semi_month_segment' => $segment,
        ];
    }
}
