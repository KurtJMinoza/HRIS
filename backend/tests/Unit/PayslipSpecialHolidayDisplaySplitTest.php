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

    public function test_already_split_snapshot_is_not_split_again(): void
    {
        $service = $this->service();
        $line = $this->tunaFestivalLine(156.93, ['display_split_applied' => true]);
        $split = $service->resolveWorkedHolidayDisplaySplit($line, 559.23);

        $this->assertEqualsWithDelta(156.93, $split['premium'], 0.02);
    }

    public function test_legacy_over_split_amount_is_repaired_to_thirty_percent_premium(): void
    {
        $service = $this->service();
        $split = $service->resolveWorkedHolidayDisplaySplit($this->tunaFestivalLine(36.21), 559.23);

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

        $secondPass = $service->normalizeSnapshotForPayslipView($normalized);
        $lineAgain = $secondPass['summary']['daily_computation_earning_lines'][0];
        $this->assertEqualsWithDelta(156.93, (float) $lineAgain['amount'], 0.02);
    }
}
