<?php

namespace Tests\Unit;

use App\Services\PayslipService;
use Tests\TestCase;

class PayslipSpecialHolidayDisplaySplitTest extends TestCase
{
    private function service(): PayslipService
    {
        return app(PayslipService::class);
    }

    private function tunaFestivalLine(float $amount, array $metadata = []): array
    {
        return [
            'key' => 'holiday:2026-09-05:258:SPECIAL_HOLIDAY_WORKED_PAY',
            'label' => 'Special Holiday — Worked Pay: tuna festival',
            'description' => 'Special Holiday — Worked Pay: tuna festival',
            'amount' => $amount,
            'minutes_worked' => 449,
            'hourly_rate' => 90.8749,
            'component_code' => 'SPECIAL_HOLIDAY_WORKED_PAY',
            'metadata' => array_merge([
                'worked' => true,
                'holiday_type' => 'special',
                'multiplier' => 1.3,
                'scope_match' => true,
            ], $metadata),
        ];
    }

    private function bisligLine(float $amount, array $metadata = []): array
    {
        return [
            'key' => 'holiday:2026-09-05:258:SPECIAL_HOLIDAY_WORKED_PAY',
            'label' => 'Special Holiday — Worked Pay (30% Additional): Charter of the City of Bislig',
            'amount' => $amount,
            'units' => '8 hrs',
            'minutes_worked' => 480,
            'component_code' => 'SPECIAL_HOLIDAY_WORKED_PAY',
            'metadata' => array_merge([
                'worked' => true,
                'holiday_type' => 'special',
                'multiplier' => 1.3,
                'scope_match' => true,
            ], $metadata),
        ];
    }

    public function test_special_holiday_gross_amount_splits_to_thirty_percent_premium(): void
    {
        $service = $this->service();
        $split = $service->resolveWorkedHolidayDisplaySplit($this->tunaFestivalLine(680.05), 559.23);

        $this->assertTrue($split['rolls_base_into_regular']);
        $this->assertEqualsWithDelta(523.12, $split['base'], 0.02);
        $this->assertEqualsWithDelta(156.93, $split['premium'], 0.02);
    }

    public function test_legacy_split_flag_with_full_gross_amount_is_resplit_to_thirty_percent_premium(): void
    {
        $service = $this->service();
        $split = $service->resolveWorkedHolidayDisplaySplit(
            $this->bisligLine(739.0, ['display_split_applied' => true]),
            568.46
        );

        $this->assertTrue($split['rolls_base_into_regular']);
        $this->assertEqualsWithDelta(568.46, $split['base'], 0.02);
        $this->assertEqualsWithDelta(170.54, $split['premium'], 0.02);
    }

    public function test_normalize_snapshot_splits_bislig_line_to_thirty_percent_only(): void
    {
        $service = $this->service();
        $dailyRate = 568.46;
        $presentDayBase = round(12 * $dailyRate, 2);
        $snapshot = [
            'daily_rate' => $dailyRate,
            'summary' => [
                'daily_rate' => $dailyRate,
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => round(13 * $dailyRate, 2),
                'regular_pay_present_day_units' => 12.0,
                'regular_fixed_present_day_cap_applied' => true,
                'regular_fixed_present_day_base_pay' => $presentDayBase,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'scheduled_days_count' => 13,
                    'total_deduction' => 888.21,
                    'rows' => [
                        ['key' => 'late', 'deduction_amount' => 319.75],
                        ['key' => 'absence', 'deduction_amount' => 568.46],
                    ],
                ],
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => round($presentDayBase - 319.75, 2),
                        'units' => '12 days',
                        'component_code' => 'REGULAR_PAY',
                    ],
                    $this->bisligLine(739.0, ['display_split_applied' => true]),
                ],
            ],
            'daily_computation_days' => [],
        ];

        $normalized = $service->normalizeSnapshotForPayslipView($snapshot);
        $summary = $normalized['summary'];

        $holidayLine = null;
        $regularLine = null;
        foreach ($summary['daily_computation_earning_lines'] as $line) {
            if (! is_array($line)) {
                continue;
            }
            if ($this->strContainsHoliday($line)) {
                $holidayLine = $line;
            }
            if (str_contains(strtolower((string) ($line['key'] ?? '')), 'regular')) {
                $regularLine = $line;
            }
        }

        $this->assertNotNull($holidayLine);
        $this->assertEqualsWithDelta(170.54, (float) ($holidayLine['amount'] ?? 0), 0.02);
        $this->assertNotNull($regularLine);
        $this->assertEqualsWithDelta(round(13 * $dailyRate, 2), (float) ($regularLine['display_amount'] ?? 0), 0.02);
        $this->assertStringContainsString('13', (string) ($regularLine['units'] ?? ''));
        $this->assertEqualsWithDelta(6501.79, (float) ($summary['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0), 0.02);
    }

    public function test_already_split_snapshot_is_not_split_again(): void
    {
        $service = $this->service();
        $line = $this->tunaFestivalLine(156.93, ['display_split_applied' => true]);
        $split = $service->resolveWorkedHolidayDisplaySplit($line, 559.23);

        $this->assertEqualsWithDelta(156.93, $split['premium'], 0.02);
    }

    public function test_normalize_snapshot_applies_thirty_percent_label_once(): void
    {
        $service = $this->service();
        $snapshot = [
            'daily_rate' => 559.23,
            'summary' => [
                'daily_rate' => 559.23,
                'daily_computation_earning_lines' => [
                    $this->tunaFestivalLine(680.05),
                ],
            ],
            'daily_computation_days' => [],
        ];

        $normalized = $service->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0];

        $this->assertEqualsWithDelta(156.93, (float) $line['amount'], 0.02);
        $this->assertStringContainsString('30% Additional', (string) $line['label']);
        $this->assertTrue((bool) ($line['metadata']['display_split_applied'] ?? false));
    }

    public function test_full_semi_monthly_basic_headline_does_not_add_worked_holiday_base_twice(): void
    {
        $service = $this->service();
        $dailyRate = 597.46;
        $fixedGross = 7767.0;
        $snapshot = [
            'daily_rate' => $dailyRate,
            'summary' => [
                'daily_rate' => $dailyRate,
                'regular_fixed_semi_monthly_payroll' => true,
                'fixed_semi_monthly_basic_gross' => $fixedGross,
                'semi_monthly_basic_salary' => $fixedGross,
                'basic_pay_this_period' => $fixedGross,
                'regular_pay_present_day_units' => 13.0,
                'regular_pay_scheduled_day_units' => 13.0,
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'scheduled_days_count' => 13,
                    'total_deduction' => 0.0,
                    'rows' => [],
                ],
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => $fixedGross,
                        'units' => '13 days',
                        'component_code' => 'REGULAR_PAY',
                    ],
                    $this->bisligLine(776.7, ['scope_match' => true]),
                ],
            ],
            'daily_computation_days' => [],
        ];

        $normalized = $service->normalizeSnapshotForPayslipView($snapshot);
        $regularLine = null;
        foreach ($normalized['summary']['daily_computation_earning_lines'] as $line) {
            if (is_array($line) && str_contains(strtolower((string) ($line['key'] ?? '')), 'regular')) {
                $regularLine = $line;
                break;
            }
        }

        $this->assertNotNull($regularLine);
        $this->assertEqualsWithDelta($fixedGross, (float) ($regularLine['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(
            $fixedGross,
            (float) ($normalized['summary']['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0),
            0.02
        );
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function strContainsHoliday(array $line): bool
    {
        return str_contains(strtoupper((string) ($line['component_code'] ?? '')), 'SPECIAL_HOLIDAY');
    }
}
