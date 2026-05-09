<?php

namespace App\Services;

use App\Models\DeductionScheduleSetting;
use App\Models\EmployeeCompensationComponent;
use App\Models\EmployeeTaxInfo;
use App\Models\PayComponent;
use App\Models\PayCycle;
use App\Models\SssBracket;
use App\Models\StatutoryContribution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PayrollCalculatorService
{
    /** @var array<string, bool> */
    private array $schemaCapabilities = [];

    /** @var array<int, array{min: float, max: float, msc: float}>|null */
    private ?array $sssBracketsMemo = null;

    /**
     * Philippine SSS contributions (RA 11199, SSS Circular No. 2024-006 — effective January 2025).
     *
     * Prior to 2025, the regular SS program commonly used a 14% total on MSC (4.5% EE + 9.5% ER).
     * From January 2025, the regular SS rate is 15% of Monthly Salary Credit (MSC):
     * - Employee share: 5% of MSC
     * - Employer share (regular SS): 10% of MSC
     * - Employees’ Compensation (EC): employer-only — ₱30 flat per SSS Circular No. 2024-006
     * - Maximum MSC is ₱35,000 for the contribution schedule.
     *
     * Amounts must be derived from the official MSC bracket for the employee’s monthly basic salary,
     * never by applying percentages directly to actual salary when that salary differs from MSC.
     *
     * Built-in table below: current MSC schedule (same 15% rules since Jan 2025; no further SSS rate hike in 2026)
     * when DB (`sss_brackets` / JSON) is unavailable.
     */
    private const SSS_MSC_BRACKETS = [
        ['min' => 0.00, 'max' => 5249.99, 'msc' => 5000.00],
        ['min' => 5250.00, 'max' => 5749.99, 'msc' => 5500.00],
        ['min' => 5750.00, 'max' => 6249.99, 'msc' => 6000.00],
        ['min' => 6250.00, 'max' => 6749.99, 'msc' => 6500.00],
        ['min' => 6750.00, 'max' => 7249.99, 'msc' => 7000.00],
        ['min' => 7250.00, 'max' => 7749.99, 'msc' => 7500.00],
        ['min' => 7750.00, 'max' => 8249.99, 'msc' => 8000.00],
        ['min' => 8250.00, 'max' => 8749.99, 'msc' => 8500.00],
        ['min' => 8750.00, 'max' => 9249.99, 'msc' => 9000.00],
        ['min' => 9250.00, 'max' => 9749.99, 'msc' => 9500.00],
        ['min' => 9750.00, 'max' => 10249.99, 'msc' => 10000.00],
        ['min' => 10250.00, 'max' => 10749.99, 'msc' => 10500.00],
        ['min' => 10750.00, 'max' => 11249.99, 'msc' => 11000.00],
        ['min' => 11250.00, 'max' => 11749.99, 'msc' => 11500.00],
        ['min' => 11750.00, 'max' => 12249.99, 'msc' => 12000.00],
        ['min' => 12250.00, 'max' => 12749.99, 'msc' => 12500.00],
        ['min' => 12750.00, 'max' => 13249.99, 'msc' => 13000.00],
        ['min' => 13250.00, 'max' => 13749.99, 'msc' => 13500.00],
        ['min' => 13750.00, 'max' => 14249.99, 'msc' => 14000.00],
        ['min' => 14250.00, 'max' => 14749.99, 'msc' => 14500.00],
        ['min' => 14750.00, 'max' => 15249.99, 'msc' => 15000.00],
        ['min' => 15250.00, 'max' => 15749.99, 'msc' => 15500.00],
        ['min' => 15750.00, 'max' => 16249.99, 'msc' => 16000.00],
        ['min' => 16250.00, 'max' => 16749.99, 'msc' => 16500.00],
        ['min' => 16750.00, 'max' => 17249.99, 'msc' => 17000.00],
        ['min' => 17250.00, 'max' => 17749.99, 'msc' => 17500.00],
        ['min' => 17750.00, 'max' => 18249.99, 'msc' => 18000.00],
        ['min' => 18250.00, 'max' => 18749.99, 'msc' => 18500.00],
        ['min' => 18750.00, 'max' => 19249.99, 'msc' => 19000.00],
        ['min' => 19250.00, 'max' => 19749.99, 'msc' => 19500.00],
        ['min' => 19750.00, 'max' => 20249.99, 'msc' => 20000.00],
        ['min' => 20250.00, 'max' => 20749.99, 'msc' => 20500.00],
        ['min' => 20750.00, 'max' => 21249.99, 'msc' => 21000.00],
        ['min' => 21250.00, 'max' => 21749.99, 'msc' => 21500.00],
        ['min' => 21750.00, 'max' => 22249.99, 'msc' => 22000.00],
        ['min' => 22250.00, 'max' => 22749.99, 'msc' => 22500.00],
        ['min' => 22750.00, 'max' => 23249.99, 'msc' => 23000.00],
        ['min' => 23250.00, 'max' => 23749.99, 'msc' => 23500.00],
        ['min' => 23750.00, 'max' => 24249.99, 'msc' => 24000.00],
        ['min' => 24250.00, 'max' => 24749.99, 'msc' => 24500.00],
        ['min' => 24750.00, 'max' => 25249.99, 'msc' => 25000.00],
        ['min' => 25250.00, 'max' => 25749.99, 'msc' => 25500.00],
        ['min' => 25750.00, 'max' => 26249.99, 'msc' => 26000.00],
        ['min' => 26250.00, 'max' => 26749.99, 'msc' => 26500.00],
        ['min' => 26750.00, 'max' => 27249.99, 'msc' => 27000.00],
        ['min' => 27250.00, 'max' => 27749.99, 'msc' => 27500.00],
        ['min' => 27750.00, 'max' => 28249.99, 'msc' => 28000.00],
        ['min' => 28250.00, 'max' => 28749.99, 'msc' => 28500.00],
        ['min' => 28750.00, 'max' => 29249.99, 'msc' => 29000.00],
        ['min' => 29250.00, 'max' => 29749.99, 'msc' => 29500.00],
        ['min' => 29750.00, 'max' => 30249.99, 'msc' => 30000.00],
        ['min' => 30250.00, 'max' => 30749.99, 'msc' => 30500.00],
        ['min' => 30750.00, 'max' => 31249.99, 'msc' => 31000.00],
        ['min' => 31250.00, 'max' => 31749.99, 'msc' => 31500.00],
        ['min' => 31750.00, 'max' => 32249.99, 'msc' => 32000.00],
        ['min' => 32250.00, 'max' => 32749.99, 'msc' => 32500.00],
        ['min' => 32750.00, 'max' => 33249.99, 'msc' => 33000.00],
        ['min' => 33250.00, 'max' => 33749.99, 'msc' => 33500.00],
        ['min' => 33750.00, 'max' => 34249.99, 'msc' => 34000.00],
        ['min' => 34250.00, 'max' => 34749.99, 'msc' => 34500.00],
        ['min' => 34750.00, 'max' => 1000000.00, 'msc' => 35000.00],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function generatePayPeriods(PayCycle $payCycle, Carbon|string $startDate, int $numberOfPeriods = 6): array
    {
        $reference = $startDate instanceof Carbon
            ? $startDate->copy()->startOfDay()
            : Carbon::parse((string) $startDate)->startOfDay();

        $periodCount = max(1, $numberOfPeriods);

        return match ($payCycle->code) {
            PayCycle::CODE_SEMI_MONTHLY => $this->generateSemiMonthlyPayPeriods($payCycle, $reference, $periodCount),
            default => $this->generateGenericPayPeriods($payCycle, $reference, $periodCount),
        };
    }

    public function adjustForWeekend(Carbon|string $date, ?string $rule = null): Carbon
    {
        $value = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        $weekendRule = $rule ?: PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY;

        if ($weekendRule !== PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY) {
            return $value;
        }

        return match ($value->dayOfWeek) {
            Carbon::SATURDAY => $value->subDay(),
            Carbon::SUNDAY => $value->subDays(2),
            default => $value,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateSemiMonthlyPayPeriods(PayCycle $payCycle, Carbon $reference, int $count): array
    {
        [$firstCutoff, $secondCutoff] = $this->resolveSemiMonthlyCutoffs($payCycle);
        $period = $this->resolveSemiMonthlyPeriod($payCycle, $reference, $firstCutoff, $secondCutoff);
        $periods = [];

        for ($index = 0; $index < $count; $index++) {
            $periods[] = $period;
            $period = $this->resolveSemiMonthlyPeriod(
                $payCycle,
                $period['end']->copy()->addDay()->startOfDay(),
                $firstCutoff,
                $secondCutoff
            );
        }

        return $periods;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveSemiMonthlyCutoffs(PayCycle $payCycle): array
    {
        $raw = collect($payCycle->cut_off_value ?? [10, 25])
            ->map(function ($value) {
                if ($value === 'end_of_month') {
                    return 31;
                }

                return is_numeric($value) ? (int) $value : null;
            })
            ->filter(fn ($value) => is_int($value) && $value >= 1)
            ->values();

        $firstCutoff = (int) ($raw->get(0) ?: 10);
        $secondCutoff = (int) ($raw->get(1) ?: 25);

        if ($secondCutoff <= $firstCutoff) {
            $secondCutoff = min(31, $firstCutoff + 15);
        }

        return [$firstCutoff, $secondCutoff];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSemiMonthlyPeriod(PayCycle $payCycle, Carbon $reference, int $firstCutoff, int $secondCutoff): array
    {
        $currentMonthEndDay = $reference->copy()->endOfMonth()->day;
        $effectiveSecondCutoff = min($secondCutoff, $currentMonthEndDay);

        if ($reference->day <= $firstCutoff) {
            $previousMonth = $reference->copy()->subMonthNoOverflow();
            $previousMonthEndDay = $previousMonth->copy()->endOfMonth()->day;
            $start = $secondCutoff >= $previousMonthEndDay
                ? $reference->copy()->startOfMonth()
                : $previousMonth->copy()->day(min($secondCutoff + 1, $previousMonthEndDay));
            $end = $reference->copy()->day(min($firstCutoff, $currentMonthEndDay));
            $segment = 'first';
        } elseif ($reference->day <= $effectiveSecondCutoff) {
            $start = $reference->copy()->startOfMonth()->day(min($firstCutoff + 1, $currentMonthEndDay));
            $end = $reference->copy()->day($effectiveSecondCutoff);
            $segment = 'second';
        } else {
            $nextMonth = $reference->copy()->addMonthNoOverflow();
            $nextMonthEndDay = $nextMonth->copy()->endOfMonth()->day;
            $start = $reference->copy()->day(min($effectiveSecondCutoff + 1, $currentMonthEndDay));
            $end = $nextMonth->copy()->day(min($firstCutoff, $nextMonthEndDay));
            $segment = 'first';
        }

        [$payDate, $weekendAdjusted] = $this->resolveSemiMonthlyPayDate($payCycle, $end, $segment);

        return [
            'start' => $start->startOfDay(),
            'end' => $end->startOfDay(),
            'pay_date' => $payDate,
            'reference_date' => $reference->copy()->startOfDay(),
            /** first|second semi-monthly segment — use for loan installment schedule (15th vs 30th style), not calendar day 1–15 alone */
            'semi_month_segment' => $segment,
            'cycle_label' => sprintf(
                '%s %s, %s – %s %s, %s',
                $start->format('F'),
                $start->format('j'),
                $start->format('Y'),
                $end->format('F'),
                $end->format('j'),
                $end->format('Y')
            ),
            'preview_line' => sprintf(
                '%s %s, %s – %s %s, %s -> Pay Date: %s %s, %s',
                $start->format('F'),
                $start->format('j'),
                $start->format('Y'),
                $end->format('F'),
                $end->format('j'),
                $end->format('Y'),
                $payDate->format('F'),
                $payDate->format('j'),
                $payDate->format('Y')
            ),
            'period_days' => $start->diffInDays($end) + 1,
            'weekend_adjustment_rule' => $this->weekendAdjustmentRule($payCycle),
            'weekend_adjusted' => $weekendAdjusted,
        ];
    }

    /**
     * @return array{0: Carbon, 1: bool}
     */
    private function resolveSemiMonthlyPayDate(PayCycle $payCycle, Carbon $periodEnd, string $segment): array
    {
        $payDayValue = is_array($payCycle->pay_day_value) ? $payCycle->pay_day_value : [];

        if ($payCycle->pay_day_type === PayCycle::PAY_DAY_OFFSET) {
            $offset = $segment === 'first'
                ? (int) (data_get($payDayValue, 'first_offset') ?? $payCycle->pay_day_offset ?? data_get($payDayValue, 'offset', 5))
                : (int) (data_get($payDayValue, 'second_offset') ?? $payCycle->pay_day_offset ?? data_get($payDayValue, 'offset', 5));
            $rawDate = $periodEnd->copy()->addDays($offset);
            $adjusted = $this->adjustForWeekend($rawDate, $this->weekendAdjustmentRule($payCycle));

            return [$adjusted, ! $rawDate->isSameDay($adjusted)];
        }

        if ($payCycle->pay_day_type === PayCycle::PAY_DAY_CUSTOM) {
            $date = data_get($payCycle->pay_day_value, 'date');
            if ($date) {
                $rawDate = Carbon::parse((string) $date)->startOfDay();
                $adjusted = $this->adjustForWeekend($rawDate, $this->weekendAdjustmentRule($payCycle));

                return [$adjusted, ! $rawDate->isSameDay($adjusted)];
            }
        }

        $firstPayDay = data_get($payDayValue, 'first_day', data_get($payDayValue, 'day', 15));
        $secondPayDay = data_get($payDayValue, 'second_day', 'end_of_month');

        $rawDate = $segment === 'first'
            ? $periodEnd->copy()->startOfMonth()->day(min((int) $firstPayDay, $periodEnd->copy()->endOfMonth()->day))
            : $this->resolveSemiMonthlyFixedSecondPayDate($periodEnd, $secondPayDay);
        $adjusted = $this->adjustForWeekend($rawDate, $this->weekendAdjustmentRule($payCycle));

        return [$adjusted, ! $rawDate->isSameDay($adjusted)];
    }

    private function resolveSemiMonthlyFixedSecondPayDate(Carbon $periodEnd, mixed $configuredDay): Carbon
    {
        if ($configuredDay === 'end_of_month' || (is_numeric($configuredDay) && (int) $configuredDay >= 31)) {
            return $periodEnd->copy()->endOfMonth()->startOfDay();
        }

        $day = max(1, (int) $configuredDay);

        return $periodEnd->copy()->startOfMonth()->day(min($day, $periodEnd->copy()->endOfMonth()->day))->startOfDay();
    }

    private function weekendAdjustmentRule(PayCycle $payCycle): string
    {
        return (string) data_get($payCycle->metadata, 'weekend_adjustment_rule', PayCycle::WEEKEND_ADJUST_PREVIOUS_FRIDAY);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateGenericPayPeriods(PayCycle $payCycle, Carbon $reference, int $count): array
    {
        $service = app(PayCycleService::class);
        $periods = [];
        $cursor = $reference->copy();

        for ($index = 0; $index < $count; $index++) {
            $period = $service->getCutOffPeriod($payCycle, $cursor);
            $payDate = $this->adjustForWeekend($period['pay_date'], $this->weekendAdjustmentRule($payCycle));
            $periods[] = [
                'start' => $period['start']->copy()->startOfDay(),
                'end' => $period['end']->copy()->startOfDay(),
                'pay_date' => $payDate,
                'reference_date' => $period['reference_date']->copy()->startOfDay(),
                'cycle_label' => $period['cycle_label'],
                'preview_line' => sprintf(
                    '%s %s, %s – %s %s, %s -> Pay Date: %s %s, %s',
                    $period['start']->format('F'),
                    $period['start']->format('j'),
                    $period['start']->format('Y'),
                    $period['end']->format('F'),
                    $period['end']->format('j'),
                    $period['end']->format('Y'),
                    $payDate->format('F'),
                    $payDate->format('j'),
                    $payDate->format('Y')
                ),
                'period_days' => $period['start']->diffInDays($period['end']) + 1,
                'weekend_adjustment_rule' => $this->weekendAdjustmentRule($payCycle),
                'weekend_adjusted' => false,
            ];
            $cursor = $period['end']->copy()->addDay()->startOfDay();
        }

        return $periods;
    }

    public function determineSSSMSCBracket(float $basicSalary): array
    {
        $salary = round(max(0.0, $basicSalary), 2);

        $rows = $this->getSssBrackets();
        foreach ($rows as $row) {
            if ($salary >= $row['min'] && $salary <= $row['max']) {
                $label = (string) ($row['label'] ?? '');

                return $this->mergeSssBracketExtras($row, $label !== ''
                    ? $label
                    : 'SSS MSC '.number_format((float) $row['msc'], 2).' from range '.number_format((float) $row['min'], 2).' - '.number_format((float) $row['max'], 2));
            }
        }

        $last = end($rows) ?: ['msc' => 35000.0, 'min' => 34750.0, 'max' => 1000000.0];

        return $this->mergeSssBracketExtras($last, 'SSS MSC '.number_format((float) $last['msc'], 2).' (max cap)');
    }

    /**
     * Attach optional per-row amounts from `sss_brackets` / JSON (Circular schedule) when present.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mergeSssBracketExtras(array $row, string $label): array
    {
        $out = [
            'msc' => (float) $row['msc'],
            'min' => (float) $row['min'],
            'max' => (float) $row['max'],
            'label' => $label,
        ];
        foreach (['ee_share', 'er_share', 'ec_amount', 'employee_ss', 'employer_ss', 'employer_ec'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                $out[$k] = is_numeric($row[$k]) ? (float) $row[$k] : $row[$k];
            }
        }

        return $out;
    }

    /**
     * Prefer DB-configured SSS brackets (sss_brackets then statutory_contributions.brackets),
     * fallback to built-in schedule for resiliency.
     *
     * @return array<int, array{min: float, max: float, msc: float}>
     */
    private function getSssBrackets(): array
    {
        if (is_array($this->sssBracketsMemo)) {
            return $this->sssBracketsMemo;
        }

        $cacheKey = 'payroll.sss.brackets.v1';
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            $this->sssBracketsMemo = $cached;

            return $cached;
        }

        if (class_exists(SssBracket::class) && $this->hasTableCached('sss_brackets')) {
            $hasRangeStart = $this->hasColumnCached('sss_brackets', 'range_start');
            $hasRangeEnd = $this->hasColumnCached('sss_brackets', 'range_end');
            $hasRangeFrom = $this->hasColumnCached('sss_brackets', 'range_from');
            $hasRangeTo = $this->hasColumnCached('sss_brackets', 'range_to');
            $hasRangeLabel = $this->hasColumnCached('sss_brackets', 'range_label');
            $hasSalaryMin = $this->hasColumnCached('sss_brackets', 'salary_min');
            $hasSalaryMax = $this->hasColumnCached('sss_brackets', 'salary_max');

            $columns = ['msc'];
            if ($hasRangeStart) {
                $columns[] = 'range_start';
            }
            if ($hasRangeEnd) {
                $columns[] = 'range_end';
            }
            if ($hasRangeFrom) {
                $columns[] = 'range_from';
            }
            if ($hasRangeTo) {
                $columns[] = 'range_to';
            }
            if ($hasRangeLabel) {
                $columns[] = 'range_label';
            }
            if ($hasSalaryMin) {
                $columns[] = 'salary_min';
            }
            if ($hasSalaryMax) {
                $columns[] = 'salary_max';
            }
            foreach (['ee_share', 'er_share', 'ec_amount', 'employee_ss', 'employer_ss', 'employer_ec'] as $col) {
                if ($this->hasColumnCached('sss_brackets', $col)) {
                    $columns[] = $col;
                }
            }

            $query = SssBracket::query()->where('is_active', true);
            if ($this->hasColumnCached('sss_brackets', 'effective_from')) {
                $latestEffective = SssBracket::query()
                    ->where('is_active', true)
                    ->max('effective_from');
                if ($latestEffective) {
                    $query->whereDate('effective_from', $latestEffective);
                }
            }
            if ($hasRangeStart) {
                $query->orderBy('range_start');
            } elseif ($hasRangeFrom) {
                $query->orderBy('range_from');
            } elseif ($hasSalaryMin) {
                $query->orderBy('salary_min');
            }
            if ($hasSalaryMin) {
                $query->orderBy('salary_min');
            }

            $dbRows = $query->get($columns);
            if ($dbRows->count() > 0) {
                $mapped = $dbRows->map(function ($r) {
                    $base = [
                        'min' => (float) ($r->range_start ?? $r->range_from ?? $r->salary_min ?? 0),
                        'max' => ($r->range_end ?? $r->range_to ?? $r->salary_max) !== null
                            ? (float) ($r->range_end ?? $r->range_to ?? $r->salary_max)
                            : null,
                        'msc' => (float) $r->msc,
                        'label' => (string) ($r->range_label ?? ''),
                    ];
                    foreach (['ee_share', 'er_share', 'ec_amount', 'employee_ss', 'employer_ss', 'employer_ec'] as $k) {
                        if (isset($r->{$k}) && $r->{$k} !== null && $r->{$k} !== '') {
                            $base[$k] = (float) $r->{$k};
                        }
                    }

                    return $base;
                })->sortBy('min')->values();

                // Normalize nullable bounds so bracket lookup remains correct for
                // "Below x" and "x and above" style rows.
                $sorted = $mapped->all();
                $count = count($sorted);
                $rows = [];
                for ($index = 0; $index < $count; $index++) {
                    $row = $sorted[$index];
                    $min = (float) ($row['min'] ?? 0);
                    $max = $row['max'];
                    $label = strtolower((string) ($row['label'] ?? ''));
                    $nextMin = ($index + 1 < $count) ? (float) ($sorted[$index + 1]['min'] ?? 0) : null;

                    if ($max === null) {
                        if (str_starts_with($label, 'below') && $nextMin !== null && $nextMin > $min) {
                            $max = round($nextMin - 0.01, 2);
                        } else {
                            $max = 1000000.00;
                        }
                    } else {
                        $max = (float) $max;
                    }

                    if ($max < $min) {
                        $max = $min;
                    }

                    $out = [
                        'min' => $min,
                        'max' => $max,
                        'msc' => (float) ($row['msc'] ?? 0),
                        'label' => (string) ($row['label'] ?? ''),
                    ];
                    foreach (['ee_share', 'er_share', 'ec_amount', 'employee_ss', 'employer_ss', 'employer_ec'] as $k) {
                        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                            $out[$k] = (float) $row[$k];
                        }
                    }

                    $rows[] = $out;
                }
                if ($this->looksLikeFullSss2025Schedule($rows)) {
                    Cache::put($cacheKey, $rows, now()->addMinutes(30));
                    $this->sssBracketsMemo = $rows;

                    return $rows;
                }
                Log::warning('SSS brackets table is incomplete; falling back to built-in SSS 2025 schedule.', [
                    'row_count' => count($rows),
                    'min_msc' => collect($rows)->min('msc'),
                    'max_msc' => collect($rows)->max('msc'),
                ]);
            }
        }

        if (! $this->hasTableCached('statutory_contributions')) {
            $rows = $this->normalizeSssBrackets(self::SSS_MSC_BRACKETS);
            Cache::put($cacheKey, $rows, now()->addMinutes(30));
            $this->sssBracketsMemo = $rows;

            return $rows;
        }

        $sss = StatutoryContribution::query()
            ->where('code', 'SSS')
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->first();
        if ($sss && is_array($sss->brackets) && count($sss->brackets) > 0) {
            $rows = $this->normalizeSssBrackets(collect($sss->brackets)
                ->map(function ($r) {
                    $base = [
                        'min' => (float) ($r['min'] ?? 0),
                        'max' => (float) ($r['max'] ?? 0),
                        'msc' => (float) ($r['msc'] ?? 0),
                        'label' => (string) ($r['label'] ?? $r['range_label'] ?? ''),
                    ];
                    foreach (['ee_share', 'er_share', 'ec_amount', 'employee_ss', 'employer_ss', 'employer_ec'] as $k) {
                        if (isset($r[$k]) && $r[$k] !== null && $r[$k] !== '') {
                            $base[$k] = (float) $r[$k];
                        }
                    }

                    return $base;
                })
                ->sortBy('min')
                ->values()
                ->all());
            if ($this->looksLikeFullSss2025Schedule($rows)) {
                Cache::put($cacheKey, $rows, now()->addMinutes(30));
                $this->sssBracketsMemo = $rows;

                return $rows;
            }
            Log::warning('Statutory SSS JSON brackets are incomplete; falling back to built-in SSS 2025 schedule.', [
                'row_count' => count($rows),
                'min_msc' => collect($rows)->min('msc'),
                'max_msc' => collect($rows)->max('msc'),
            ]);
        }

        $rows = $this->normalizeSssBrackets(self::SSS_MSC_BRACKETS);
        Cache::put($cacheKey, $rows, now()->addMinutes(30));
        $this->sssBracketsMemo = $rows;

        return $rows;
    }

    /**
     * Ensure all SSS bracket rows include a stable label key.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSssBrackets(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            $min = (float) ($row['min'] ?? 0);
            $max = (float) ($row['max'] ?? $min);
            $msc = (float) ($row['msc'] ?? 0);
            $label = (string) ($row['label'] ?? '');
            if ($label === '') {
                $label = sprintf('SSS MSC %s from range %s - %s', number_format($msc, 2), number_format($min, 2), number_format($max, 2));
            }

            $row['min'] = $min;
            $row['max'] = $max;
            $row['msc'] = $msc;
            $row['label'] = $label;

            return $row;
        }, $rows));
    }

    /**
     * Guardrail for stale/migrated data: accept only full SSS Circular 2024-006 (Jan 2025) schedule.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function looksLikeFullSss2025Schedule(array $rows): bool
    {
        if (count($rows) < 61) {
            return false;
        }
        $minMsc = (float) collect($rows)->min('msc');
        $maxMsc = (float) collect($rows)->max('msc');

        return $minMsc <= 5000.0 && $maxMsc >= 35000.0;
    }

    /**
     * 13th-month pay: amount up to ₱90,000 per year is exempt from tax (RR No. 3-2015 et seq.; TRAIN).
     * Amount in excess is taxable and included in gross compensation.
     */
    public const THIRTEENTH_MONTH_EXEMPT_ANNUAL = 90000.0;

    /**
     * NIRC Section 24(A) as amended by TRAIN (RA 10963) — graduated annual income tax on taxable income.
     * This implements the official annual rate schedule used for annualized withholding and year-end true-up.
     *
     * CREATE Act (RA 11976) introduced a **simplified regime** for eligible taxpayers; employees on standard
     * compensation withholding remain on this schedule unless the employer elects otherwise — verify BIR guidance.
     *
     * @return array{tax_due: float, bracket_index: int, bracket_description: string, marginal_rate: float|null}
     */
    public function computeTrainAnnualIncomeTax(float $annualTaxableIncome): array
    {
        $x = round(max(0.0, $annualTaxableIncome), 2);
        $tax = 0.0;
        $idx = 0;
        $desc = 'Not over ₱250,000 (0%)';
        $marginal = null;

        if ($x <= 250000.0) {
            $tax = 0.0;
        } elseif ($x <= 400000.0) {
            $idx = 1;
            $desc = 'Over ₱250,000 but not over ₱400,000 (15% of excess over ₱250,000)';
            $marginal = 0.15;
            $tax = ($x - 250000.0) * 0.15;
        } elseif ($x <= 800000.0) {
            $idx = 2;
            $desc = 'Over ₱400,000 but not over ₱800,000 (₱22,500 + 20% of excess over ₱400,000)';
            $marginal = 0.20;
            $tax = 22500.0 + ($x - 400000.0) * 0.20;
        } elseif ($x <= 2000000.0) {
            $idx = 3;
            $desc = 'Over ₱800,000 but not over ₱2,000,000 (₱102,500 + 25% of excess over ₱800,000)';
            $marginal = 0.25;
            $tax = 102500.0 + ($x - 800000.0) * 0.25;
        } elseif ($x <= 8000000.0) {
            $idx = 4;
            $desc = 'Over ₱2,000,000 but not over ₱8,000,000 (₱402,500 + 30% of excess over ₱2,000,000)';
            $marginal = 0.30;
            $tax = 402500.0 + ($x - 2000000.0) * 0.30;
        } else {
            $idx = 5;
            $desc = 'Over ₱8,000,000 (₱2,202,500 + 35% of excess over ₱8,000,000)';
            $marginal = 0.35;
            $tax = 2202500.0 + ($x - 8000000.0) * 0.35;
        }

        return [
            'tax_due' => round($tax, 2),
            'bracket_index' => $idx,
            'bracket_description' => $desc,
            'marginal_rate' => $marginal,
        ];
    }

    /**
     * BIR Revenue Regulations No. 11-2018 — **Table A** (Withholding Tax on Compensation, **monthly**).
     * TRAIN (RA 10963) rates applied on **taxable income for the month** (after mandatory EE contributions are
     * already removed in {@see calculateWithholdingTax()}). This matches the official monthly bracket method,
     * e.g. second bracket: 15% of excess over ₱20,833 → ₱22,925 taxable → (22,925 − 20,833) × 15% = ₱313.80.
     *
     * @return array{
     *   withholding_monthly: float,
     *   bracket_index: int,
     *   bracket_description: string,
     *   marginal_rate: float|null
     * }
     */
    public function computeBirRr112018MonthlyWithholdingTax(float $monthlyTaxableCompensation): array
    {
        $tc = round(max(0.0, $monthlyTaxableCompensation), 2);

        if ($tc <= 20833.0) {
            return [
                'withholding_monthly' => 0.0,
                'bracket_index' => 0,
                'bracket_description' => 'Not over ₱20,833 (0%)',
                'marginal_rate' => null,
            ];
        }

        if ($tc <= 33332.0) {
            $tax = ($tc - 20833.0) * 0.15;

            return [
                'withholding_monthly' => round($tax, 2),
                'bracket_index' => 1,
                'bracket_description' => 'Over ₱20,833 but not over ₱33,332 (15% of excess over ₱20,833)',
                'marginal_rate' => 0.15,
            ];
        }

        if ($tc <= 66667.0) {
            $tax = 1875.0 + ($tc - 33333.0) * 0.20;

            return [
                'withholding_monthly' => round($tax, 2),
                'bracket_index' => 2,
                'bracket_description' => 'Over ₱33,332 but not over ₱66,667 (₱1,875 + 20% of excess over ₱33,333)',
                'marginal_rate' => 0.20,
            ];
        }

        if ($tc <= 166667.0) {
            $tax = 8541.80 + ($tc - 66667.0) * 0.25;

            return [
                'withholding_monthly' => round($tax, 2),
                'bracket_index' => 3,
                'bracket_description' => 'Over ₱66,667 but not over ₱166,667 (₱8,541.80 + 25% of excess over ₱66,667)',
                'marginal_rate' => 0.25,
            ];
        }

        if ($tc <= 666667.0) {
            $tax = 33541.80 + ($tc - 166667.0) * 0.30;

            return [
                'withholding_monthly' => round($tax, 2),
                'bracket_index' => 4,
                'bracket_description' => 'Over ₱166,667 but not over ₱666,667 (₱33,541.80 + 30% of excess over ₱166,667)',
                'marginal_rate' => 0.30,
            ];
        }

        $tax = 183541.80 + ($tc - 666667.0) * 0.35;

        return [
            'withholding_monthly' => round($tax, 2),
            'bracket_index' => 5,
            'bracket_description' => 'Over ₱666,667 (₱183,541.80 + 35% of excess over ₱666,667)',
            'marginal_rate' => 0.35,
        ];
    }

    /**
     * Classify pay components into taxable vs non-taxable buckets for withholding base.
     * De minimis and other non-taxable items must be flagged `taxable => false` at source (pay rules).
     *
     * @param  array<int, array<string, mixed>>  $lines  Each line: code, amount, taxable (bool), optional label
     * @return array<string, mixed>
     */
    public function classifyTaxableIncome(array $lines): array
    {
        $normalized = [];
        $taxableTotal = 0.0;
        $nonTaxableTotal = 0.0;

        foreach ($lines as $row) {
            $code = (string) ($row['code'] ?? 'item');
            $amount = round(max(0.0, (float) ($row['amount'] ?? 0)), 2);
            $taxable = filter_var($row['taxable'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($taxable === null) {
                $taxable = true;
            }
            $label = (string) ($row['label'] ?? $code);

            $normalized[] = [
                'code' => $code,
                'label' => $label,
                'amount' => $amount,
                'taxable' => $taxable,
            ];

            if ($taxable) {
                $taxableTotal += $amount;
            } else {
                $nonTaxableTotal += $amount;
            }
        }

        return [
            'lines' => $normalized,
            'taxable_total' => round($taxableTotal, 2),
            'non_taxable_total' => round($nonTaxableTotal, 2),
            'gross_total' => round($taxableTotal + $nonTaxableTotal, 2),
        ];
    }

    /**
     * HR / API alias for {@see classifyTaxableIncome()} — taxable vs non-taxable pay components.
     *
     * @param  array<int, array<string, mixed>>  $earnings
     * @return array<string, mixed>
     */
    public function classifyEarnings(array $earnings): array
    {
        return $this->classifyTaxableIncome($earnings);
    }

    /**
     * Monthly amount used as the TRAIN withholding base: taxable compensation for the period
     * minus employee share of mandatory SSS, PhilHealth, and Pag-IBIG only (same components as
     * {@see calculateAllStatutoryContributions()} totals.employee_deduction).
     *
     * Order: mandatory EE contributions first, then BIR RR 11-2018 Table A on the balance (see {@see calculateWithholdingTax()}).
     */
    public function monthlyTaxableCompensationForWithholding(float $grossMonthlyTaxableCompensation, array $statutoryBreakdown): float
    {
        $eeMandatory = (float) data_get($statutoryBreakdown, 'totals.employee_deduction', 0);

        return round(max(0.0, $grossMonthlyTaxableCompensation - $eeMandatory), 2);
    }

    /**
     * BIR creditable withholding on compensation — preview engine.
     *
     * **Monthly withholding (default):** After mandatory EE contributions, apply **BIR RR 11-2018 Table A**
     * (monthly compensation brackets). This is the standard “excess over ₱20,833 × 15%” (etc.) method — not
     * annual TRAIN ÷ 12, which can differ by a few pesos in the same band.
     *
     * **13th month (taxable excess over ₱90,000):** Adds a monthly supplement from the marginal **annual** TRAIN
     * tax on that excess (÷ 12). Loans and non-mandatory deductions never reduce the withholding base here.
     *
     * **Philippine withholding base:** By default, `monthly_taxable_compensation` and classified `earnings` are treated
     * as *gross* monthly taxable compensation. Employee SSS, PhilHealth, and Pag-IBIG shares (from the same
     * statutory engine as payslips) are subtracted *before* TRAIN. Pass `withholding_base_is_net_of_mandatory => true`
     * when the input is already net of those contributions (e.g. after {@see buildEmployeeCompensationSummary()}).
     *
     * @param  array{
     *   earnings?: array<int, array<string, mixed>>,
     *   monthly_taxable_compensation?: float,
     *   withholding_base_is_net_of_mandatory?: bool,
     *   withholding_gross_taxable_monthly?: float,
     *   withholding_employee_mandatory_monthly?: float,
     *   statutory_salary_basis?: float,
     *   statutory_contribution_bases?: array<string, float>,
     *   method?: 'annualized'|'per_period_monthly',
     *   period_type?: 'monthly'|'semimonthly',
     *   thirteenth_month_amount?: float,
     *   tax_profile?: array<string, mixed>
     * }  $params
     * @return array<string, mixed>
     */
    public function calculateWithholdingTax(array $params): array
    {
        $method = (string) ($params['method'] ?? 'annualized');
        if (! in_array($method, ['annualized', 'per_period_monthly'], true)) {
            $method = 'annualized';
        }

        $periodType = (string) ($params['period_type'] ?? 'monthly');
        if (! in_array($periodType, ['monthly', 'semimonthly'], true)) {
            $periodType = 'monthly';
        }

        $profile = is_array($params['tax_profile'] ?? null) ? $params['tax_profile'] : [];

        $monthlyTaxable = null;
        $classification = null;
        $grossMonthlyTaxableForWithholding = null;
        $employeeMandatoryForWithholdingBase = null;
        $netOfMandatory = filter_var($params['withholding_base_is_net_of_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! empty($params['earnings']) && is_array($params['earnings'])) {
            $classification = $this->classifyTaxableIncome($params['earnings']);
            $grossTaxableFromEarnings = (float) $classification['taxable_total'];
            $grossAllFromEarnings = (float) $classification['gross_total'];
            // One gross for SSS/PH/Pag EE when computing mandatory reduction for WHT (align with payslip taxable pay).
            $basisDefault = $grossTaxableFromEarnings > 0.0 ? $grossTaxableFromEarnings : $grossAllFromEarnings;
            $basisForStatutory = (float) ($params['statutory_salary_basis'] ?? $basisDefault);
            $bases = is_array($params['statutory_contribution_bases'] ?? null) ? $params['statutory_contribution_bases'] : [];
            $statutoryForWht = empty($bases)
                ? $this->calculateAllStatutoryContributions($basisForStatutory, [
                    'sss' => $basisForStatutory,
                    'philhealth' => $basisForStatutory,
                    'pagibig' => $basisForStatutory,
                ])
                : $this->calculateAllStatutoryContributions($basisForStatutory, $bases);
            $grossMonthlyTaxableForWithholding = $grossTaxableFromEarnings;
            $employeeMandatoryForWithholdingBase = (float) data_get($statutoryForWht, 'totals.employee_deduction', 0);
            $monthlyTaxable = $netOfMandatory
                ? $grossTaxableFromEarnings
                : $this->monthlyTaxableCompensationForWithholding($grossTaxableFromEarnings, $statutoryForWht);
        } else {
            $grossInput = round(max(0.0, (float) ($params['monthly_taxable_compensation'] ?? 0)), 2);
            if ($netOfMandatory) {
                $monthlyTaxable = $grossInput;
                if (isset($params['withholding_gross_taxable_monthly'])) {
                    $grossMonthlyTaxableForWithholding = round(max(0.0, (float) $params['withholding_gross_taxable_monthly']), 2);
                }
                if (isset($params['withholding_employee_mandatory_monthly'])) {
                    $employeeMandatoryForWithholdingBase = round(max(0.0, (float) $params['withholding_employee_mandatory_monthly']), 2);
                }
            } else {
                $basisForStatutory = (float) ($params['statutory_salary_basis'] ?? $grossInput);
                $bases = is_array($params['statutory_contribution_bases'] ?? null) ? $params['statutory_contribution_bases'] : [];
                $statutoryForWht = $bases !== []
                    ? $this->calculateAllStatutoryContributions($basisForStatutory, $bases)
                    : $this->calculateAllStatutoryContributions($basisForStatutory, [
                        'sss' => $basisForStatutory,
                        'philhealth' => $basisForStatutory,
                        'pagibig' => $basisForStatutory,
                    ]);
                $grossMonthlyTaxableForWithholding = $grossInput;
                $employeeMandatoryForWithholdingBase = (float) data_get($statutoryForWht, 'totals.employee_deduction', 0);
                $monthlyTaxable = $this->monthlyTaxableCompensationForWithholding($grossInput, $statutoryForWht);
            }
        }

        $monthlyTaxableBeforeProfileExemptions = round($monthlyTaxable, 2);

        $additionalMonthly = round(max(0.0, (float) ($profile['additional_exemption_amount'] ?? 0)), 2);
        if ($additionalMonthly > 0) {
            $monthlyTaxable = round(max(0.0, $monthlyTaxable - $additionalMonthly), 2);
        }

        $thirteenth = round(max(0.0, (float) ($params['thirteenth_month_amount'] ?? 0)), 2);
        $thirteenthTaxableExcess = max(0.0, $thirteenth - self::THIRTEENTH_MONTH_EXEMPT_ANNUAL);

        $annualFromMonthly = $monthlyTaxable * 12.0;
        $annualTaxable = round($annualFromMonthly + $thirteenthTaxableExcess, 2);

        $train = $this->computeTrainAnnualIncomeTax($annualTaxable);
        $annualTaxDue = (float) $train['tax_due'];

        // 1) RR 11-2018 Table A on monthly taxable income (after mandatory SSS/PhilHealth/Pag-IBIG only).
        $birMonthly = $this->computeBirRr112018MonthlyWithholdingTax($monthlyTaxable);
        $monthlyWithholdingFromTable = (float) ($birMonthly['withholding_monthly'] ?? 0);

        // 2) Taxable 13th-month amount in excess of ₱90k: marginal annual TRAIN on that excess, spread ÷ 12.
        $thirteenthSupplementMonthly = 0.0;
        if ($thirteenthTaxableExcess > 0) {
            $annualWith13th = $monthlyTaxable * 12.0 + $thirteenthTaxableExcess;
            $annualSalaryOnly = $monthlyTaxable * 12.0;
            $taxWith = (float) $this->computeTrainAnnualIncomeTax($annualWith13th)['tax_due'];
            $taxWithout = (float) $this->computeTrainAnnualIncomeTax($annualSalaryOnly)['tax_due'];
            $thirteenthSupplementMonthly = round(max(0.0, $taxWith - $taxWithout) / 12.0, 2);
        }

        $monthlyWithholding = round($monthlyWithholdingFromTable + $thirteenthSupplementMonthly, 2);

        $perPeriod = $periodType === 'semimonthly'
            ? round($monthlyWithholding / 2.0, 2)
            : $monthlyWithholding;

        $mweApplied = false;
        $mweReason = null;
        if (! empty($profile['is_mwe'])) {
            $ceiling = $profile['mwe_monthly_ceiling'] ?? null;
            if ($ceiling === null || $ceiling === '') {
                $cfg = Config::get('tax.mwe_default_monthly_ceiling');
                $ceiling = $cfg !== null && $cfg !== '' ? (float) $cfg : null;
            } else {
                $ceiling = (float) $ceiling;
            }
            if ($ceiling !== null && $ceiling > 0 && $monthlyTaxable <= $ceiling) {
                $monthlyWithholding = 0.0;
                $perPeriod = $periodType === 'semimonthly' ? 0.0 : 0.0;
                $mweApplied = true;
                $mweReason = 'Monthly taxable compensation does not exceed configured MWE ceiling; withholding treated as zero (verify against BIR/DOLE).';
            } elseif ($ceiling === null || $ceiling <= 0) {
                $mweReason = 'MWE flagged but no monthly ceiling set — configure employee or `TAX_MWE_DEFAULT_MONTHLY_CEILING`.';
            }
        }

        $specialReview = ! empty($profile['is_senior_citizen']) || ! empty($profile['is_pwd']);
        $regime = (string) ($profile['tax_regime'] ?? 'standard_train');

        $effectiveRateMonthly = $monthlyTaxable > 0
            ? round(($monthlyWithholding / $monthlyTaxable) * 100.0, 4)
            : 0.0;

        return [
            'type' => 'BIR_WITHHOLDING',
            'tax_table' => 'bir_rr11_2018_table_a_monthly',
            'method' => $method,
            'period_type' => $periodType,
            'gross_monthly_taxable_compensation' => $grossMonthlyTaxableForWithholding,
            'employee_mandatory_contributions_monthly' => $employeeMandatoryForWithholdingBase,
            'monthly_taxable_compensation' => $monthlyTaxable,
            'classification' => $classification,
            'tax_profile_applied' => $profile !== [] ? $profile : null,
            'thirteenth_month_amount' => $thirteenth,
            'thirteenth_month_taxable_excess' => round($thirteenthTaxableExcess, 2),
            'annual_taxable_income_projected' => $annualTaxable,
            'annual_income_tax_per_train' => $annualTaxDue,
            'train_bracket' => $train,
            'bir_monthly_table_bracket' => $birMonthly,
            'withholding_per_month_from_table' => round($monthlyWithholdingFromTable, 2),
            'withholding_per_month_from_thirteenth_supplement' => $thirteenthSupplementMonthly,
            'withholding_per_month' => $monthlyWithholding,
            'withholding_per_period' => $perPeriod,
            'effective_rate_percent_of_monthly_taxable' => $effectiveRateMonthly,
            'metadata' => [
                'law_reference' => 'BIR RR 11-2018 Table A (monthly); TRAIN annual for 13th-month marginal / year-end',
                'note' => 'Regular monthly WHT uses RR 11-2018 monthly brackets on taxable income after mandatory EE contributions only. Loans do not reduce this base.',
                'withholding_base_computation' => [
                    'gross_monthly_taxable' => $grossMonthlyTaxableForWithholding,
                    'employee_mandatory_monthly' => $employeeMandatoryForWithholdingBase,
                    'base_after_mandatory_before_profile_exemptions' => $monthlyTaxableBeforeProfileExemptions,
                    'withholding_base_is_net_of_mandatory' => $netOfMandatory,
                ],
                'mwe_exemption_applied' => $mweApplied,
                'mwe_note' => $mweReason,
                'special_taxpayer_flags' => $specialReview,
                'tax_regime' => $regime,
                'dependent_count_stored' => 'TRAIN compensation withholding does not use dependent exemptions; field kept for HR records / future rules.',
            ],
        ];
    }

    /**
     * Merge {@see EmployeeTaxInfo} into withholding params (method, period_type, tax_profile).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function mergeEmployeeTaxProfileIntoWithholdingParams(User $user, array $params): array
    {
        try {
            $info = EmployeeTaxInfo::query()->where('user_id', $user->id)->first();
            if ($info) {
                if (! empty($info->withholding_method)) {
                    $params['method'] = $info->withholding_method;
                }
                if (! empty($info->period_type)) {
                    $params['period_type'] = $info->period_type;
                }
                $params['tax_profile'] = [
                    'is_mwe' => (bool) ($info->is_mwe ?? false),
                    'mwe_monthly_ceiling' => isset($info->mwe_monthly_ceiling) ? (float) $info->mwe_monthly_ceiling : null,
                    'is_senior_citizen' => (bool) ($info->is_senior_citizen ?? false),
                    'is_pwd' => (bool) ($info->is_pwd ?? false),
                    'is_solo_parent' => (bool) ($info->is_solo_parent ?? false),
                    'tax_regime' => (string) ($info->tax_regime ?? 'standard_train'),
                    'additional_exemption_amount' => isset($info->additional_exemption_amount) ? (float) $info->additional_exemption_amount : null,
                ];
            }
        } catch (QueryException $e) {
            Log::warning('Payroll calculator tax profile fallback', [
                'employee_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }

        return $params;
    }

    /**
     * Withholding from employee record + optional pay-component lines (taxable flags from payroll rules).
     *
     * @param  array<int, array<string, mixed>>  $periodEarnings
     */
    public function calculateWithholdingTaxForEmployee(?int $employeeId, array $periodEarnings = [], bool $isAnnualized = true): array
    {
        $params = [
            'method' => $isAnnualized ? 'annualized' : 'per_period_monthly',
            'period_type' => 'monthly',
        ];

        if ($employeeId !== null) {
            $user = User::query()->find($employeeId);
            if ($user) {
                if ($periodEarnings !== []) {
                    $params['earnings'] = $periodEarnings;
                } else {
                    $params['monthly_taxable_compensation'] = $this->resolveBasicSalaryForPayroll($user);
                }

                $params = $this->mergeEmployeeTaxProfileIntoWithholdingParams($user, $params);

                return $this->calculateWithholdingTax($params);
            }
        }

        if ($periodEarnings !== []) {
            $params['earnings'] = $periodEarnings;
        } else {
            $params['monthly_taxable_compensation'] = 0.0;
        }

        return $this->calculateWithholdingTax($params);
    }

    /**
     * Human-readable salary range for the MSC row (for payslips / HR audit UI).
     */
    private function formatSssBracketSalaryRange(array $bracket): string
    {
        $min = (float) ($bracket['min'] ?? 0);
        $max = (float) ($bracket['max'] ?? 0);
        if ($max >= 999999.0) {
            return number_format($min, 2, '.', ',').' and above';
        }

        return number_format($min, 2, '.', ',').' – '.number_format($max, 2, '.', ',');
    }

    /**
     * Resolve SSS EE, ER (regular SS), and EC from the matched bracket row.
     *
     * Priority: peso columns on `sss_brackets` / statutory JSON (`ee_share`, `employee_ss`, `er_share`, `employer_ss`, `ec_amount`, `employer_ec`).
     * If a column is missing, that component is derived from MSC using the 2025 schedule (5% EE, 10% ER, EC ₱30 flat) — never from raw basic salary.
     *
     * @return array{employee: float, employer: float, ec: float, amounts_source: string, msc_bracket_range: string}
     */
    private function resolveSssContributionsFromBracket(array $bracket): array
    {
        $msc = round((float) ($bracket['msc'] ?? 0), 2);

        $eeRaw = $bracket['employee_ss'] ?? $bracket['ee_share'] ?? null;
        $erRaw = $bracket['employer_ss'] ?? $bracket['er_share'] ?? null;
        $ecRaw = $bracket['employer_ec'] ?? $bracket['ec_amount'] ?? null;

        $eeFromTable = $eeRaw !== null && $eeRaw !== '';
        $erFromTable = $erRaw !== null && $erRaw !== '';
        $ecFromTable = $ecRaw !== null && $ecRaw !== '';

        $employee = $eeFromTable ? round((float) $eeRaw, 2) : round($msc * 0.05, 2);
        $employer = $erFromTable ? round((float) $erRaw, 2) : round($msc * 0.10, 2);
        $ec = $ecFromTable ? round((float) $ecRaw, 2) : 30.0;

        $amountsSource = ($eeFromTable && $erFromTable && $ecFromTable)
            ? 'bracket_table'
            : (($eeFromTable || $erFromTable || $ecFromTable) ? 'mixed_table_msc' : 'msc_schedule');

        return [
            'employee' => $employee,
            'employer' => $employer,
            'ec' => $ec,
            'amounts_source' => $amountsSource,
            'msc_bracket_range' => $this->formatSssBracketSalaryRange($bracket),
            'ee_from_table' => $eeFromTable,
            'er_from_table' => $erFromTable,
            'ec_from_table' => $ecFromTable,
        ];
    }

    /**
     * Regular employed members: MSC from salary bracket (RA 11199 / SSS Circular 2024-006).
     * Amounts come from bracket table columns when present; otherwise from MSC (not from raw salary).
     *
     * @param  string  $membershipType  regular|ofw|self_employed|voluntary_member|household
     */
    public function calculateSSS(float $basicSalary, string $membershipType = 'regular'): array
    {
        $membershipType = strtolower(trim($membershipType));
        $salaryForMsc = round(max(0.0, $basicSalary), 2);

        if (in_array($membershipType, ['ofw', 'ofw_land', 'ofw_sea'], true)) {
            $salaryForMsc = max($salaryForMsc, 5000.0);
        }

        $bracket = $this->determineSSSMSCBracket($salaryForMsc);
        $msc = round((float) $bracket['msc'], 2);

        $resolved = $this->resolveSssContributionsFromBracket($bracket);
        $employee = $resolved['employee'];
        $employer = $resolved['employer'];
        $ec = $resolved['ec'];
        $employeeShareCap = round($msc * 0.05, 2);
        $employeeShareCorrected = false;

        // Guardrail: misconfigured bracket rows sometimes place TOTAL regular SS in EE column.
        // Employee payroll deduction must never exceed employee share.
        if ($employee > $employeeShareCap + 0.01) {
            $employee = $employeeShareCap;
            $employeeShareCorrected = true;
            // Keep employer regular SS aligned when ER is not explicitly table-driven.
            if (! (bool) ($resolved['er_from_table'] ?? false)) {
                $employer = round($msc * 0.10, 2);
            }
        }

        return [
            'type' => 'SSS',
            'membership_type' => $membershipType,
            'basic_salary_used' => round(max(0.0, $basicSalary), 2),
            'salary_used_for_msc_lookup' => $salaryForMsc,
            'msc_used' => $msc,
            'bracket_range' => (string) ($bracket['label'] ?? ''),
            'msc_bracket_range' => $resolved['msc_bracket_range'],
            'amounts_source' => $resolved['amounts_source'],
            'employee_amount' => $employee,
            'employer_amount' => $employer,
            'ec_amount' => $ec,
            'total_amount' => round($employee + $employer + $ec, 2),
            'metadata' => [
                'law_reference' => 'RA 11199',
                'circular_reference' => 'SSS Circular No. 2024-006 (effective Jan 2025)',
                'computation_basis' => 'Lookup MSC from salary range, then apply contribution amounts from `sss_brackets` (or MSC-based 5% EE + 10% ER + EC when columns absent). Total regular SS is 15% of MSC since January 2025 — never apply percentages to raw basic salary.',
                'rate_summary' => 'SSS regular program: 15% of MSC (5% employee + 10% employer) effective Jan 2025; EC employer-only ₱30 per Circular 2024-006.',
                'amounts_source' => $resolved['amounts_source'],
                'ec_rule' => 'EC ₱30.00 (flat amount per SSS Circular No. 2024-006)',
                'employee_share_cap' => $employeeShareCap,
                'employee_share_corrected' => $employeeShareCorrected,
                'membership_note' => $membershipType !== 'regular'
                    ? 'Non-regular membership: confirm MSC and rates with the latest SSS contribution schedule for this class.'
                    : null,
            ],
        ];
    }

    public function calculatePhilHealth(float $basicSalary): array
    {
        // RA 11223: 5% total with floor 10,000 and ceiling 100,000.
        $salary = max(0.0, $basicSalary);
        $floor = 10000.0;
        $ceiling = 100000.0;
        $base = min($ceiling, max($floor, $salary));
        $total = round($base * 0.05, 2);
        $employee = round($total / 2, 2);
        $employer = round($total / 2, 2);
        $floorApplied = $salary < $floor;
        $ceilingApplied = $salary > $ceiling;

        return [
            'type' => 'PhilHealth',
            'basic_salary_used' => round($salary, 2),
            'msc_used' => null,
            'bracket_range' => 'PhilHealth base '.number_format($base, 2).' (floor '.number_format($floor, 2).', ceiling '.number_format($ceiling, 2).')',
            'employee_amount' => $employee,
            'employer_amount' => $employer,
            'ec_amount' => 0.0,
            'total_amount' => round($employee + $employer, 2),
            'metadata' => [
                'law_reference' => 'RA 11223',
                'total_rate' => 0.05,
                'employee_rate' => 0.025,
                'employer_rate' => 0.025,
                'salary_floor' => $floor,
                'salary_ceiling' => $ceiling,
                'applied_salary' => round($base, 2),
                'floor_applied' => $floorApplied,
                'ceiling_applied' => $ceilingApplied,
                'computation_note' => 'Premium is computed on the configured PhilHealth contributory base.',
            ],
        ];
    }

    /**
     * Mandatory employee/employer shares on capped salary; optional voluntary employee add-on (MP2-style or additional voluntary).
     *
     * @param  float  $voluntaryEmployeeAmount  Extra employee-only amount (e.g. voluntary MP2) added after mandatory tier.
     */
    public function calculatePagIBIG(float $basicSalary, bool $isVoluntary = false, float $voluntaryEmployeeAmount = 0.0): array
    {
        // RA 9679: employee 1% (<=1,500) else 2%; employer 2%; capped at base 10,000.
        $salary = max(0.0, $basicSalary);
        $cap = 10000.0;
        $threshold = 1500.0;
        $base = min($cap, $salary);
        $employeeRate = $salary <= 1500 ? 0.01 : 0.02;
        $employerRate = 0.02;
        $capApplied = $salary > $cap;

        $employee = round($base * $employeeRate, 2);
        if ($voluntaryEmployeeAmount > 0.0) {
            $employee = round($employee + max(0.0, $voluntaryEmployeeAmount), 2);
        }
        $employer = round($base * $employerRate, 2);

        return [
            'type' => 'PagIBIG',
            'basic_salary_used' => round($salary, 2),
            'msc_used' => null,
            'bracket_range' => 'Pag-IBIG base '.number_format($base, 2).' (cap '.number_format($cap, 2).')',
            'employee_amount' => $employee,
            'employer_amount' => $employer,
            'ec_amount' => 0.0,
            'total_amount' => round($employee + $employer, 2),
            'metadata' => [
                'law_reference' => 'RA 9679',
                'employee_rate' => $employeeRate,
                'employer_rate' => $employerRate,
                'tier_threshold' => $threshold,
                'salary_cap' => $cap,
                'applied_salary' => round($base, 2),
                'cap_applied' => $capApplied,
                'voluntary_employee_addon' => round(max(0.0, $voluntaryEmployeeAmount), 2),
                'is_voluntary_context' => $isVoluntary,
                'computation_note' => 'Mandatory contributions are capped; voluntary add-ons are employee-only unless policy states otherwise.',
            ],
        ];
    }

    public function calculateAllStatutoryContributions(float $basicSalary, array $bases = []): array
    {
        $sssBase = round(max(0.0, (float) ($bases['sss'] ?? $basicSalary)), 2);
        $philHealthBase = round(max(0.0, (float) ($bases['philhealth'] ?? $basicSalary)), 2);
        $pagIbigBase = round(max(0.0, (float) ($bases['pagibig'] ?? $basicSalary)), 2);

        $sss = $this->calculateSSS($sssBase);
        $philHealth = $this->calculatePhilHealth($philHealthBase);
        $pagIbig = $this->calculatePagIBIG($pagIbigBase);

        $employeeDeductionTotal = round(
            $sss['employee_amount'] + $philHealth['employee_amount'] + $pagIbig['employee_amount'],
            2
        );
        $employerLiabilityTotal = round(
            $sss['employer_amount'] + $sss['ec_amount'] + $philHealth['employer_amount'] + $pagIbig['employer_amount'],
            2
        );

        return [
            'basic_salary' => round(max(0.0, $basicSalary), 2),
            'bases' => [
                'sss' => $sssBase,
                'philhealth' => $philHealthBase,
                'pagibig' => $pagIbigBase,
            ],
            'sss' => $sss,
            'philhealth' => $philHealth,
            'pagibig' => $pagIbig,
            'totals' => [
                'employee_deduction' => $employeeDeductionTotal,
                'employer_liability' => $employerLiabilityTotal,
                'combined_remittance' => round($employeeDeductionTotal + $employerLiabilityTotal, 2),
            ],
        ];
    }

    public function resolveBasicSalaryForPayroll(User $employee, ?string $asOfDate = null): float
    {
        $asOfDate = $asOfDate ?: now()->toDateString();
        if ($this->profileMonthlyBase($employee) <= 0.0) {
            return 0.0;
        }

        $assignment = collect($this->getActiveEmployeeCompensationComponents($employee, $asOfDate))
            ->first(fn (array $line) => strtoupper((string) ($line['code'] ?? '')) === 'BASIC_SALARY');
        if ($assignment) {
            return round(max(0.0, (float) ($assignment['computed_amount'] ?? 0)), 2);
        }

        return $this->resolveLegacyBasicSalaryForPayroll($employee);
    }

    private function resolveLegacyBasicSalaryForPayroll(User $employee): float
    {
        $monthly = (float) ($employee->monthly_salary ?? 0);
        if ($monthly > 0) {
            return $monthly;
        }

        return 0.0;
    }

    /**
     * Drop file-cached {@see buildEmployeeCompensationSummary} entries for a user.
     * Must run when {@see EmployeeCompensationComponent} rows change; otherwise the UI can show
     * stale earning line ids and PATCH/DELETE will 404 after a delete.
     */
    public function forgetCompensationSummaryCacheForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $store = Cache::store('file');
        $prorationFactors = [1.0];
        $hoursVariants = [0.0];
        $catalogFlags = [0, 1];

        for ($d = -21; $d <= 21; $d++) {
            $asOfDate = Carbon::now()->addDays($d)->toDateString();
            foreach ($prorationFactors as $pf) {
                foreach ($hoursVariants as $hw) {
                    foreach ($catalogFlags as $catalog) {
                        $key = sprintf(
                            'payroll.compensation_summary.employee.%d.as_of.%s.proration.%s.hours.%s.catalog.%d',
                            $userId,
                            $asOfDate,
                            number_format($pf, 4, '.', ''),
                            number_format($hw, 4, '.', ''),
                            $catalog
                        );
                        $store->forget($key);
                    }
                }
            }
        }
    }

    public function buildEmployeeCompensationSummary(User|int $employee, array $options = []): array
    {
        $asOfDate = isset($options['as_of_date']) ? Carbon::parse((string) $options['as_of_date'])->toDateString() : now()->toDateString();
        if ($employee instanceof User) {
            $user = $employee;
        } else {
            $employeeId = (int) $employee;
            $user = $employeeId > 0 ? User::query()->find($employeeId) : null;
            if (! $user) {
                Log::warning('Payroll compensation summary skipped: employee not found', [
                    'employee_id' => $employeeId,
                    'as_of_date' => $asOfDate,
                ]);

                return $this->emptyCompensationSummary($asOfDate);
            }
        }
        $prorationFactor = max(0.0, (float) ($options['proration_factor'] ?? 1.0));
        $hoursWorked = max(0.0, (float) ($options['hours_worked'] ?? 0.0));
        $includeCatalog = (bool) ($options['include_deduction_schedule_catalog'] ?? true);
        $cacheEnabled = (bool) ($options['cache'] ?? true);
        $allowCompute = (bool) ($options['allow_compute'] ?? true);

        // Guardrail: if an active employee has legacy monthly salary but no BASIC_SALARY assignment row,
        // persist a baseline row so Salary tab / daily computation / payslip preview read the same source.
        $this->ensureBaselineBasicSalaryAssignment($user, $asOfDate);

        if ($cacheEnabled) {
            $cacheStore = Cache::store('file');
            $cacheKey = sprintf(
                'payroll.compensation_summary.employee.%d.as_of.%s.proration.%s.hours.%s.catalog.%d',
                (int) $user->id,
                $asOfDate,
                number_format($prorationFactor, 4, '.', ''),
                number_format($hoursWorked, 4, '.', ''),
                $includeCatalog ? 1 : 0
            );
            $cached = $cacheStore->get($cacheKey);
            if (is_array($cached)) {
                Log::info('Payroll compensation summary cache hit', [
                    'employee_id' => $user->id,
                    'as_of_date' => $asOfDate,
                    'cache_key' => $cacheKey,
                ]);

                return $cached;
            }

            if (! $allowCompute) {
                return array_merge($this->emptyCompensationSummary($asOfDate), [
                    '_summary_pending' => true,
                ]);
            }

            $computed = $this->buildEmployeeCompensationSummary($user->id, array_merge($options, [
                'as_of_date' => $asOfDate,
                'proration_factor' => $prorationFactor,
                'hours_worked' => $hoursWorked,
                'include_deduction_schedule_catalog' => $includeCatalog,
                'cache' => false,
            ]));
            $cacheStore->put($cacheKey, $computed, now()->addMinutes(5));
            Log::info('Payroll compensation summary cache miss', [
                'employee_id' => $user->id,
                'as_of_date' => $asOfDate,
                'cache_key' => $cacheKey,
            ]);

            return $computed;
        }

        if (! $allowCompute) {
            return array_merge($this->emptyCompensationSummary($asOfDate), [
                '_summary_pending' => true,
            ]);
        }

        $rows = $this->getActiveEmployeeCompensationRows($user, $asOfDate);
        if ($this->profileMonthlyBase($user) <= 0) {
            $rows = $rows
                ->reject(fn (EmployeeCompensationComponent $row): bool => strtoupper(trim((string) $row->code)) === 'BASIC_SALARY')
                ->values();
        }
        $primaryBasicRow = $rows->first(
            fn ($r) => $r->type === PayComponent::TYPE_EARNING
                && strtoupper(trim((string) $r->code)) === 'BASIC_SALARY'
        );
        $basicSalary = $primaryBasicRow
            ? $this->computeCompensationAmount($primaryBasicRow, 0.0, 0.0, $prorationFactor, $hoursWorked)
            : 0.0;
        $grossEarnings = 0.0;
        foreach ($rows as $row) {
            if ($row->type === PayComponent::TYPE_EARNING) {
                if (strtoupper(trim((string) $row->code)) === 'BASIC_SALARY') {
                    continue;
                }
                $grossEarnings += $this->computeCompensationAmount($row, $basicSalary, 0.0, $prorationFactor, $hoursWorked);
            }
        }

        if ($basicSalary <= 0.0) {
            $basicSalary = $this->resolveLegacyBasicSalaryForPayroll($user);
        }

        $grossBeforePercentOfGross = round($basicSalary + $grossEarnings, 2);
        $earnings = [];
        $deductions = [];
        $sssBase = $basicSalary;
        $philHealthBase = $basicSalary;
        $pagIbigBase = $basicSalary;
        $taxableEarnings = [];

        $basicSalaryLineEmitted = false;

        foreach ($rows as $row) {
            if ($row->type === PayComponent::TYPE_EARNING
                && strtoupper(trim((string) $row->code)) === 'BASIC_SALARY'
                && $basicSalaryLineEmitted) {
                continue;
            }

            $amount = $this->computeCompensationAmount($row, $basicSalary, $grossBeforePercentOfGross, $prorationFactor, $hoursWorked);
            $line = [
                'id' => $row->id,
                'pay_component_id' => $row->pay_component_id,
                /** Used by {@see attachPayScheduleTypes} so schedule metadata matches this assignment row. */
                'assignment_schedule_override' => $row->schedule_override,
                'structure_name' => $row->structure_name,
                'name' => $row->name,
                'code' => $row->code,
                'type' => $row->type,
                'category' => $row->category,
                'calculation_type' => $row->calculation_type,
                'computed_amount' => $amount,
                'configured_value' => round((float) ($row->value ?? 0), 2),
                'hourly_rate' => $row->hourly_rate !== null ? round((float) $row->hourly_rate, 2) : null,
                'hours' => $row->hours !== null ? round((float) $row->hours, 2) : null,
                'formula' => $row->formula,
                'is_taxable' => (bool) $row->is_taxable,
                'contributes_sss' => (bool) $row->contributes_sss,
                'contributes_philhealth' => (bool) $row->contributes_philhealth,
                'contributes_pagibig' => (bool) $row->contributes_pagibig,
                'is_proratable' => (bool) $row->is_proratable,
                'is_custom' => (bool) $row->is_custom,
                'effective_from' => $row->effective_from?->toDateString(),
                'effective_to' => $row->effective_to?->toDateString(),
                'metadata' => $row->metadata,
            ];

            if ($row->type === PayComponent::TYPE_EARNING
                && strtoupper(trim((string) $row->code)) === 'BASIC_SALARY') {
                $basicSalaryLineEmitted = true;
            }

            if ($row->type === PayComponent::TYPE_EARNING) {
                $earnings[] = $line;
                if ($row->is_taxable) {
                    $taxableEarnings[] = [
                        'code' => $row->code,
                        'label' => $row->name,
                        'amount' => $amount,
                        'taxable' => true,
                    ];
                }
                if ($row->contributes_sss) {
                    $sssBase += $amount;
                }
                if ($row->contributes_philhealth) {
                    $philHealthBase += $amount;
                }
                if ($row->contributes_pagibig) {
                    $pagIbigBase += $amount;
                }
            } else {
                $deductions[] = $line;
            }
        }

        foreach (app(DeductionApplicationService::class)->buildSyntheticDeductionLines($user, $asOfDate) as $syn) {
            $deductions[] = $syn;
        }

        if ($basicSalary > 0.0 && collect($earnings)->doesntContain(fn (array $line) => strtoupper((string) ($line['code'] ?? '')) === 'BASIC_SALARY')) {
            $legacyBasicLine = [
                'id' => null,
                'pay_component_id' => null,
                'structure_name' => null,
                'name' => 'Basic Salary',
                'code' => 'BASIC_SALARY',
                'type' => PayComponent::TYPE_EARNING,
                'category' => 'Basic Salary',
                'calculation_type' => PayComponent::CALC_FIXED,
                'computed_amount' => round($basicSalary, 2),
                'configured_value' => round($basicSalary, 2),
                'hourly_rate' => null,
                'hours' => null,
                'formula' => null,
                'is_taxable' => true,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => false,
                'is_custom' => false,
                'effective_from' => null,
                'effective_to' => null,
                'metadata' => ['source' => 'legacy_salary_fields'],
            ];
            array_unshift($earnings, $legacyBasicLine);
            $taxableEarnings[] = [
                'code' => 'BASIC_SALARY',
                'label' => 'Basic Salary',
                'amount' => round($basicSalary, 2),
                'taxable' => true,
            ];
        }

        $earningsTotal = round(collect($earnings)->sum('computed_amount'), 2);
        $deductionsTotal = round(collect($deductions)->sum('computed_amount'), 2);
        $taxClassification = $this->classifyTaxableIncome($taxableEarnings);
        /*
         * Statutory employee shares for payroll withholding use the monthly BASIC salary base.
         * This keeps SSS/PhilHealth/Pag-IBIG aligned with Government Deductions Compliance Audit
         * (e.g. ₱25,000 -> PhilHealth EE ₱625.00) and prevents pay-component flag drift.
         */
        $statutoryBase = round(max(0.0, $basicSalary), 2);
        $statutory = $this->calculateAllStatutoryContributions($statutoryBase, [
            'sss' => $statutoryBase,
            'philhealth' => $statutoryBase,
            'pagibig' => $statutoryBase,
        ]);
        /*
         * Tax order (PH payroll): mandatory EE first, then RR 11-2018 monthly withholding table.
         * For monthly withholding estimate in payslip/generate/finalize, use the monthly BASIC salary
         * as gross taxable compensation baseline so ₱25,000 -> ₱22,925 taxable -> ₱313.80 withholding.
         */
        $grossTaxableMonthly = $statutoryBase > 0.0
            ? $statutoryBase
            : (float) ($taxClassification['taxable_total'] ?? 0);
        $monthlyBaseNetOfMandatory = $this->monthlyTaxableCompensationForWithholding($grossTaxableMonthly, $statutory);
        $withholdingParams = $this->mergeEmployeeTaxProfileIntoWithholdingParams($user, [
            'monthly_taxable_compensation' => $monthlyBaseNetOfMandatory,
            'withholding_base_is_net_of_mandatory' => true,
            'withholding_gross_taxable_monthly' => $grossTaxableMonthly,
            'withholding_employee_mandatory_monthly' => (float) data_get($statutory, 'totals.employee_deduction', 0),
            'method' => 'annualized',
            'period_type' => 'monthly',
        ]);
        $withholding = $this->calculateWithholdingTax($withholdingParams);
        $employeeStatutory = (float) ($statutory['totals']['employee_deduction'] ?? 0);
        $withholdingMonthly = (float) ($withholding['withholding_per_month'] ?? 0);
        // Government/statutory and withholding before employee loan & other custom deductions (typical PH net pay ordering).
        $netPay = round(max(0.0, $earningsTotal - $employeeStatutory - $withholdingMonthly - $deductionsTotal), 2);

        $deductionScheduleCatalog = $includeCatalog
            ? app(DeductionScheduleService::class)->listRowsForAdmin($user->getEffectiveCompanyId())
            : [];

        $earnings = $this->attachPayScheduleTypes($user, $earnings);
        $deductions = $this->attachPayScheduleTypes($user, $deductions);

        $scheduleSvc = app(DeductionScheduleService::class);
        $companyId = $user->getEffectiveCompanyId();

        return [
            'as_of_date' => $asOfDate,
            'pay_cycle_preview' => app(PayCycleService::class)->previewForUser($user, $asOfDate),
            'basic_salary' => round($basicSalary, 2),
            'earnings' => $earnings,
            'deductions' => $deductions,
            /** HR deduction schedule keys for statutory lines (profile / payslip alignment). */
            'government_pay_schedules' => [
                'sss' => $scheduleSvc->resolveScheduleType(DeductionScheduleSetting::GOV_SSS, $companyId),
                'philhealth' => $scheduleSvc->resolveScheduleType(DeductionScheduleSetting::GOV_PHILHEALTH, $companyId),
                'pagibig' => $scheduleSvc->resolveScheduleType(DeductionScheduleSetting::GOV_PAGIBIG, $companyId),
                'withholding_tax' => $scheduleSvc->resolveScheduleType(DeductionScheduleSetting::GOV_WITHHOLDING, $companyId),
            ],
            'totals' => [
                'gross_earnings' => $earningsTotal,
                'custom_deductions' => $deductionsTotal,
                'employee_statutory_deductions' => round($employeeStatutory, 2),
                'withholding_tax' => round($withholdingMonthly, 2),
                'net_pay' => $netPay,
            ],
            'tax_classification' => $taxClassification,
            'statutory' => $statutory,
            'withholding' => $withholding,
            'deduction_schedule_catalog' => $deductionScheduleCatalog,
        ];
    }

    /**
     * Backfill BASIC_SALARY assignment for active employees when only legacy salary fields are present.
     * This keeps compensation preview and payroll modules aligned on one assignment source.
     */
    private function ensureBaselineBasicSalaryAssignment(User $user, string $asOfDate): void
    {
        if (! class_exists(EmployeeCompensationComponent::class) || ! $this->hasTableCached('employee_compensation_components')) {
            return;
        }

        if ($this->hasColumnCached('users', 'is_active') && ! (bool) ($user->is_active ?? false)) {
            return;
        }

        $existing = EmployeeCompensationComponent::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereRaw("upper(code) = 'BASIC_SALARY'")
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $asOfDate);
            })
            ->where(function ($query) use ($asOfDate) {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->first();
        if ($existing) {
            return;
        }

        $resolvedBasic = $this->resolveLegacyBasicSalaryForPayroll($user);
        if ($resolvedBasic <= 0) {
            return;
        }

        $master = null;
        if (class_exists(PayComponent::class) && $this->hasTableCached('pay_components')) {
            $master = PayComponent::query()
                ->whereRaw("upper(code) = 'BASIC_SALARY'")
                ->where('is_active', true)
                ->orderByDesc('is_system_protected')
                ->orderBy('id')
                ->first();
        }

        $metadata = [
            'assignment_source' => 'auto_backfill_basic_salary',
            'auto_applied' => true,
            'backfilled_at' => now()->toIso8601String(),
            'source' => 'legacy_salary_fields',
        ];

        try {
            EmployeeCompensationComponent::query()->create([
                'user_id' => (int) $user->id,
                'pay_component_id' => $master?->id,
                'structure_name' => null,
                'name' => $master?->name ?: 'Basic Salary',
                'code' => 'BASIC_SALARY',
                'type' => PayComponent::TYPE_EARNING,
                'category' => $master?->category ?: 'Basic Salary',
                'calculation_type' => PayComponent::CALC_FIXED,
                'value' => round($resolvedBasic, 2),
                'hourly_rate' => null,
                'hours' => null,
                'formula' => null,
                'is_taxable' => true,
                'contributes_sss' => false,
                'contributes_philhealth' => false,
                'contributes_pagibig' => false,
                'is_proratable' => true,
                'is_custom' => $master === null,
                'effective_from' => $asOfDate,
                'effective_to' => null,
                'is_active' => true,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Payroll baseline BASIC_SALARY backfill skipped', [
                'employee_id' => $user->id,
                'as_of_date' => $asOfDate,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Defensive fallback payload when employee record is unavailable.
     */
    private function emptyCompensationSummary(string $asOfDate): array
    {
        $zeroStatutory = $this->calculateAllStatutoryContributions(0.0);
        $zeroWithholding = $this->calculateWithholdingTax([
            'monthly_taxable_compensation' => 0.0,
            'withholding_base_is_net_of_mandatory' => true,
            'method' => 'annualized',
            'period_type' => 'monthly',
        ]);

        return [
            'as_of_date' => $asOfDate,
            'pay_cycle_preview' => null,
            'basic_salary' => 0.0,
            'earnings' => [],
            'deductions' => [],
            'government_pay_schedules' => [
                'sss' => null,
                'philhealth' => null,
                'pagibig' => null,
                'withholding_tax' => null,
            ],
            'totals' => [
                'gross_earnings' => 0.0,
                'custom_deductions' => 0.0,
                'employee_statutory_deductions' => 0.0,
                'withholding_tax' => 0.0,
                'net_pay' => 0.0,
            ],
            'tax_classification' => [
                'lines' => [],
                'taxable_total' => 0.0,
                'non_taxable_total' => 0.0,
                'gross_total' => 0.0,
            ],
            'statutory' => $zeroStatutory,
            'withholding' => $zeroWithholding,
            'deduction_schedule_catalog' => [],
        ];
    }

    public function getActiveEmployeeCompensationComponents(User|int $employee, ?string $asOfDate = null): array
    {
        $summary = $this->buildEmployeeCompensationSummary($employee, [
            'as_of_date' => $asOfDate ?: now()->toDateString(),
            'proration_factor' => 1,
        ]);

        return array_values(array_merge($summary['earnings'], $summary['deductions']));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function attachPayScheduleTypes(User $user, array $lines): array
    {
        $companyId = $user->getEffectiveCompanyId();
        $userId = (int) $user->id;
        $svc = app(DeductionScheduleService::class);

        return array_map(function (array $line) use ($svc, $companyId, $userId) {
            $pcId = $line['pay_component_id'] ?? null;
            if ($pcId) {
                $usesLoadedAssignmentRow = \array_key_exists('assignment_schedule_override', $line);
                $loadedOverride = $usesLoadedAssignmentRow ? ($line['assignment_schedule_override'] ?? null) : null;
                $brk = $svc->resolveEmployeePayComponentSchedule(
                    (int) $pcId,
                    $companyId,
                    $userId,
                    $usesLoadedAssignmentRow,
                    $loadedOverride,
                    isset($line['id']) ? (int) $line['id'] : null,
                );
                $line['pay_schedule_type'] = $brk['resolved_schedule'];
                $line['schedule_override'] = $brk['schedule_override'];
                $line['default_schedule'] = $brk['default_schedule'];
                $line['resolved_schedule'] = $brk['resolved_schedule'];
                $line['schedule_source'] = $brk['schedule_source'];

                Log::debug('payroll.pay_component_schedule_resolution', [
                    'employee_id' => $userId,
                    'pay_component_id' => (int) $pcId,
                    'comp_assignment_id' => $line['id'] ?? null,
                    'uses_loaded_assignment_row' => $usesLoadedAssignmentRow,
                    'loaded_assignment_schedule_override' => $loadedOverride,
                    'saved_schedule_override' => $brk['schedule_override'],
                    'default_schedule_settings' => $brk['default_schedule'],
                    'resolved_schedule' => $brk['resolved_schedule'],
                    'schedule_source' => $brk['schedule_source'],
                    'line_computed_monthly_amount' => $line['computed_amount'] ?? null,
                ]);

                unset($line['assignment_schedule_override']);

                return $line;
            }
            $line['pay_schedule_type'] = null;
            $line['schedule_override'] = null;
            $line['default_schedule'] = null;
            $line['resolved_schedule'] = null;
            $line['schedule_source'] = null;
            unset($line['assignment_schedule_override']);

            return $line;
        }, $lines);
    }

    /**
     * @return Collection<int, EmployeeCompensationComponent>
     */
    private function getActiveEmployeeCompensationRows(User $employee, string $asOfDate): Collection
    {
        if (! class_exists(EmployeeCompensationComponent::class)) {
            return collect();
        }

        try {
            return EmployeeCompensationComponent::query()
                ->with('payComponent')
                ->where('user_id', $employee->id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereHas('payComponent')
                        ->orWhere('is_custom', true);
                })
                ->where(function ($query) use ($asOfDate) {
                    $query->whereNull('effective_from')
                        ->orWhereDate('effective_from', '<=', $asOfDate);
                })
                ->where(function ($query) use ($asOfDate) {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $asOfDate);
                })
                ->orderByRaw("case when upper(code) = 'BASIC_SALARY' then 0 else 1 end")
                ->orderBy('type')
                ->orderBy('name')
                ->get();
        } catch (QueryException $e) {
            Log::warning('Payroll calculator compensation rows fallback', [
                'employee_id' => $employee->id,
                'as_of_date' => $asOfDate,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function profileMonthlyBase(User $employee): float
    {
        $monthly = (float) ($employee->monthly_salary ?? 0);
        if ($monthly > 0) {
            return round($monthly, 2);
        }

        return 0.0;
    }

    private function isProfileBackedBasicSalaryRow(EmployeeCompensationComponent $row): bool
    {
        if (strtoupper(trim((string) $row->code)) !== 'BASIC_SALARY') {
            return false;
        }

        $metadata = is_array($row->metadata ?? null) ? $row->metadata : [];
        $source = strtolower(trim((string) ($metadata['source'] ?? '')));
        $assignmentSource = strtolower(trim((string) ($metadata['assignment_source'] ?? '')));

        return in_array($source, ['legacy_salary_fields', 'salary_profile'], true)
            || in_array($assignmentSource, ['auto_backfill_basic_salary'], true);
    }

    private function computeCompensationAmount(
        EmployeeCompensationComponent $row,
        float $basicSalary,
        float $grossBeforePercentOfGross,
        float $prorationFactor,
        float $hoursWorked
    ): float {
        $value = round((float) ($row->value ?? 0), 2);
        $masterMeta = [];
        try {
            $master = $row->payComponent;
            if ($master && is_array($master->metadata ?? null)) {
                $masterMeta = $master->metadata;
            }
        } catch (\Throwable) {
            $masterMeta = [];
        }

        $assignmentHours = $row->hours !== null ? (float) $row->hours : null;
        $assignmentHourlyRate = $row->hourly_rate !== null ? (float) $row->hourly_rate : null;

        $calc = (string) $row->calculation_type;

        $hourlyRate = $assignmentHourlyRate;
        if ($hourlyRate === null) {
            if ($calc === PayComponent::CALC_HOURLY) {
                $hourlyRate = isset($masterMeta['default_hourly_rate'])
                    ? (float) $masterMeta['default_hourly_rate']
                    : $value;
            } else {
                $hourlyRate = $value;
            }
        }

        $hours = $assignmentHours;
        if ($hours === null) {
            if ($calc === PayComponent::CALC_HOURLY && isset($masterMeta['default_hours'])) {
                $hours = (float) $masterMeta['default_hours'];
            } elseif ($calc === PayComponent::CALC_DAILY && isset($masterMeta['default_days'])) {
                $hours = (float) $masterMeta['default_days'];
            } else {
                $hours = $hoursWorked;
            }
        }

        $dailyRate = $value;
        if ($calc === PayComponent::CALC_HOURLY && $hourlyRate > 0 && $hours > 0) {
            $dailyRate = $hourlyRate * $hours;
        }

        $amount = match ($calc) {
            PayComponent::CALC_PERCENT_BASIC => round($basicSalary * ($value / 100), 2),
            PayComponent::CALC_PERCENT_GROSS => round($grossBeforePercentOfGross * ($value / 100), 2),
            PayComponent::CALC_DAILY => round($value * (max(1.0, $hours)), 2),
            PayComponent::CALC_HOURLY => round(max(0.0, $hourlyRate) * max(0.0, $hours), 2),
            PayComponent::CALC_FORMULA => $this->evaluateCompensationFormula(
                (string) ($row->formula ?? ''),
                $basicSalary,
                $grossBeforePercentOfGross,
                $value,
                $hours,
                $hourlyRate,
                $dailyRate
            ),
            default => $value,
        };

        if ($row->is_proratable) {
            $amount = round($amount * $prorationFactor, 2);
        }

        return round(max(0.0, $amount), 2);
    }

    private function evaluateCompensationFormula(
        string $formula,
        float $basicSalary,
        float $grossBeforePercentOfGross,
        float $defaultValue,
        float $hours,
        float $hourlyRate,
        float $dailyRate
    ): float {
        $expr = strtoupper(trim($formula));
        if ($expr === '') {
            return round($defaultValue, 2);
        }

        $expr = str_replace(
            ['BASIC', 'GROSS', 'DEFAULT_VALUE', 'HOURS', 'HOURLY_RATE', 'DAILY_RATE'],
            [
                (string) $basicSalary,
                (string) $grossBeforePercentOfGross,
                (string) $defaultValue,
                (string) $hours,
                (string) $hourlyRate,
                (string) $dailyRate,
            ],
            $expr
        );

        if (! preg_match('/^[0-9\.\+\-\*\/\(\)\s]+$/', $expr)) {
            return round($defaultValue, 2);
        }

        try {
            /** @var float|int $result */
            $result = eval('return '.$expr.';');
        } catch (\Throwable) {
            return round($defaultValue, 2);
        }

        return round(max(0.0, (float) $result), 2);
    }

    public function latestRatesByCode(?int $companyId = null): Collection
    {
        if (! $this->hasTableCached('statutory_contributions')) {
            return collect();
        }

        $hasIsActive = $this->hasColumnCached('statutory_contributions', 'is_active');

        return StatutoryContribution::query()
            ->when($hasIsActive, fn ($q) => $q->where('is_active', true))
            ->when($companyId, fn ($q) => $q->where(function ($x) use ($companyId) {
                $x->whereNull('company_id')->orWhere('company_id', $companyId);
            }), fn ($q) => $q->whereNull('company_id'))
            ->orderBy('code')
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('code')
            ->map(fn (Collection $rows) => $rows->first())
            ->values();
    }

    private function hasTableCached(string $table): bool
    {
        $key = 'table:'.$table;
        if (array_key_exists($key, $this->schemaCapabilities)) {
            return $this->schemaCapabilities[$key];
        }

        $cacheKey = 'schema_capability.table.'.$table;
        $exists = (bool) Cache::remember($cacheKey, now()->addHours(12), fn () => Schema::hasTable($table));
        $this->schemaCapabilities[$key] = $exists;

        return $exists;
    }

    private function hasColumnCached(string $table, string $column): bool
    {
        $key = 'column:'.$table.'.'.$column;
        if (array_key_exists($key, $this->schemaCapabilities)) {
            return $this->schemaCapabilities[$key];
        }

        $cacheKey = 'schema_capability.column.'.$table.'.'.$column;
        $exists = (bool) Cache::remember($cacheKey, now()->addHours(12), fn () => Schema::hasColumn($table, $column));
        $this->schemaCapabilities[$key] = $exists;

        return $exists;
    }
}
