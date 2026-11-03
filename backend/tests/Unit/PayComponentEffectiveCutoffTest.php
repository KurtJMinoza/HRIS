<?php

namespace Tests\Unit;

use App\Support\PayComponentEffectiveCutoff;
use Tests\TestCase;

class PayComponentEffectiveCutoffTest extends TestCase
{
    public function test_normalize_requires_cutoff_start_and_end(): void
    {
        $this->assertNull(PayComponentEffectiveCutoff::normalize(null));
        $this->assertNull(PayComponentEffectiveCutoff::normalize(['cut_off_end_date' => '2026-10-25']));
    }

    public function test_apply_effective_dates_sets_end_from_cutoff_and_clears_start(): void
    {
        $validated = [
            'metadata' => [
                'effective_until_cutoff' => [
                    'pay_cycle_id' => 3,
                    'pay_cycle_name' => 'Semi-monthly',
                    'cut_off_start_date' => '2026-10-11',
                    'cut_off_end_date' => '2026-10-25',
                    'pay_date' => '2026-10-30',
                    'cycle_label' => 'Oct 11–25, 2026',
                ],
            ],
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ];

        PayComponentEffectiveCutoff::applyEffectiveDates($validated);

        $this->assertNull($validated['effective_from']);
        $this->assertSame('2026-10-25', $validated['effective_to']);
        $this->assertSame('2026-10-25', $validated['metadata']['effective_until_cutoff']['cut_off_end_date']);
    }

    public function test_apply_effective_dates_clears_end_when_cutoff_removed(): void
    {
        $validated = [
            'metadata' => [],
            'effective_to' => '2026-10-25',
        ];

        PayComponentEffectiveCutoff::applyEffectiveDates($validated);

        $this->assertNull($validated['effective_from']);
        $this->assertNull($validated['effective_to']);
        $this->assertNull($validated['metadata']);
    }
}
