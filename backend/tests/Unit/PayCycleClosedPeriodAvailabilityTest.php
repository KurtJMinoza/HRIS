<?php

namespace Tests\Unit;

use App\Models\PayCycle;
use App\Services\PayCycleService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PayCycleClosedPeriodAvailabilityTest extends TestCase
{
    #[Test]
    public function preview_includes_closed_semi_monthly_cutoff_after_cutoff_day(): void
    {
        $cycle = new PayCycle([
            'name' => 'Semi-Monthly',
            'code' => PayCycle::CODE_SEMI_MONTHLY,
            'cut_off_type' => PayCycle::CUT_OFF_FIXED_DAY,
            'cut_off_value' => [10, 25],
            'pay_day_type' => PayCycle::PAY_DAY_OFFSET,
            'pay_day_offset' => 5,
            'pro_ration_type' => PayCycle::PRORATION_DAILY,
        ]);

        $preview = app(PayCycleService::class)->buildCyclePreview(
            $cycle,
            Carbon::parse('2026-08-26', config('app.timezone', 'Asia/Manila')),
            2,
            4,
        );

        $windows = collect($preview['preview_periods'] ?? [])
            ->map(fn (array $p): string => ($p['cut_off_start_date'] ?? '').'|'.($p['cut_off_end_date'] ?? ''))
            ->all();

        $this->assertContains('2026-08-11|2026-08-25', $windows);
    }

    #[Test]
    public function company_default_keeps_closed_11_25_window_after_august_25(): void
    {
        $preview = app(PayCycleService::class)->buildCompanyDefaultPreview(
            Carbon::parse('2026-08-26', config('app.timezone', 'Asia/Manila'))
        );

        $this->assertSame('2026-08-11', $preview['cut_off_start_date'] ?? null);
        $this->assertSame('2026-08-25', $preview['cut_off_end_date'] ?? null);
    }
}
