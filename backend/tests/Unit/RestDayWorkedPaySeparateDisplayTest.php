<?php

namespace Tests\Unit;

use App\Services\PayrollComputationService;
use App\Services\PayslipService;
use Tests\TestCase;

class RestDayWorkedPaySeparateDisplayTest extends TestCase
{
    private const DAILY_RATE = 653.85;

    private const HOURLY_RATE = 81.73125;

    private const REGULAR_AMOUNT = 9153.85;

    private const REST_DAY_PREMIUM = 196.16;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dailyComputationDays(): array
    {
        $days = [];
        foreach (['2026-09-26', '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10'] as $date) {
            $days[] = [
                'date' => $date,
                'status' => 'worked',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'worked_minutes' => 480,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'breakdown' => [
                    ['component' => 'regular_pay', 'minutes' => 480, 'rate' => self::HOURLY_RATE, 'multiplier' => 1, 'amount' => self::DAILY_RATE],
                ],
            ];
        }

        $days[] = [
            'date' => '2026-09-27',
            'status' => 'worked',
            'is_rest_day' => true,
            'required_minutes' => 480,
            'worked_minutes' => 480,
            'regular_day_minutes' => 480,
            'regular_night_minutes' => 0,
            'regular_pay' => self::DAILY_RATE,
            'breakdown' => [
                ['component' => 'regular_pay', 'minutes' => 480, 'rate' => self::HOURLY_RATE, 'multiplier' => 1, 'amount' => self::DAILY_RATE],
                ['component' => 'rest_day_worked_pay', 'minutes' => 480, 'rate' => self::HOURLY_RATE, 'multiplier' => 1.3, 'amount' => self::REST_DAY_PREMIUM],
            ],
        ];

        return $days;
    }

    /**
     * @return array<string, mixed>
     */
    private function probationarySummary(string $employmentStatus = 'probationary'): array
    {
        return [
            'employment_status' => $employmentStatus,
            'employment_type' => 'full_time',
            'daily_rate' => self::DAILY_RATE,
            'daily_rate_divisor_days' => 26,
            'basic_pay_this_period' => self::REGULAR_AMOUNT,
            'attendance_premium_pay_this_period' => self::REST_DAY_PREMIUM,
            'gross_pay_this_period' => 9350.01,
            'total_pay' => 9350.01,
            'daily_computation_earning_lines' => [
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => self::REGULAR_AMOUNT,
                    'units' => '14 days',
                    'minutes_worked' => 6720,
                    'hourly_rate' => self::HOURLY_RATE,
                ],
                [
                    'key' => 'daily:rest_day_worked_pay',
                    'label' => 'Rest Day Worked Pay',
                    'amount' => self::REST_DAY_PREMIUM,
                    'minutes_worked' => 480,
                    'hourly_rate' => self::HOURLY_RATE,
                ],
            ],
            'daily_computation_days' => $this->dailyComputationDays(),
        ];
    }

    public function test_probationary_rest_day_split_moves_base_out_of_regular_pay(): void
    {
        $service = app(PayrollComputationService::class);
        $lines = $service->applySeparateRestDayWorkedPayDisplayLines(
            $this->probationarySummary()['daily_computation_earning_lines'],
            $this->dailyComputationDays(),
            self::DAILY_RATE
        );

        $regular = collect($lines)->firstWhere('key', 'daily:regular_pay');
        $base = collect($lines)->first(fn ($line) => ! empty($line['metadata']['rest_day_worked_base'] ?? null));
        $premium = collect($lines)->first(fn ($line) => str_contains((string) ($line['label'] ?? ''), '30% Additional'));

        $this->assertNotNull($regular);
        $this->assertSame(round(self::REGULAR_AMOUNT - self::DAILY_RATE, 2), round((float) $regular['amount'], 2));
        $this->assertNotNull($base);
        $this->assertSame(self::DAILY_RATE, round((float) $base['amount'], 2));
        $this->assertSame('Rest Day Worked Pay', $base['label']);
        $this->assertNotNull($premium);
        $this->assertSame(self::REST_DAY_PREMIUM, round((float) $premium['amount'], 2));
    }

    public function test_payslip_view_shows_thirteen_day_regular_pay_for_probationary_rest_day_work(): void
    {
        $summary = $this->probationarySummary();
        $out = [
            'summary' => $summary,
            'daily_computation_days' => $this->dailyComputationDays(),
            'daily_rate' => self::DAILY_RATE,
        ];
        $frozen = app(PayslipService::class)->frozenSnapshotForPayslipView($out, null);

        $lines = $frozen['summary']['daily_computation_earning_lines'] ?? [];
        $regular = collect($lines)->first(fn ($line) => ($line['key'] ?? '') === 'daily:regular_pay');
        $base = collect($lines)->first(fn ($line) => ! empty($line['metadata']['rest_day_worked_base'] ?? null));

        $this->assertNotNull($regular);
        $this->assertSame('13 days', $regular['units'] ?? null);
        $this->assertNotNull($base);
        $this->assertEqualsWithDelta(
            9350.01,
            (float) ($frozen['summary']['display_gross_pay'] ?? 0),
            0.06
        );
    }

    public function test_project_based_uses_same_rest_day_split(): void
    {
        $this->assertTrue(
            app(PayrollComputationService::class)->usesSeparateRestDayWorkedPayDisplayForEmploymentStatus('project_based')
        );
        $this->assertFalse(
            app(PayrollComputationService::class)->usesSeparateRestDayWorkedPayDisplayForEmploymentStatus('regular')
        );
    }
}
