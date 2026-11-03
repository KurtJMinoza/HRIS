<?php

namespace Tests\Unit;

use App\Services\GeofenceLiveMonitorService;
use Tests\TestCase;

class GeofenceLiveMonitorDayBoundsTest extends TestCase
{
    public function test_manila_day_includes_early_morning_punches_stored_in_utc(): void
    {
        config(['app.timezone' => 'UTC', 'attendance.timezone' => 'Asia/Manila']);

        [$start, $end] = app(GeofenceLiveMonitorService::class)->dayBounds('2026-11-03');

        // 06:00 Manila on Nov 3 is 2026-11-02 22:00 UTC; it must fall inside Nov 3's window.
        $this->assertSame('2026-11-02 16:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 15:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertTrue($start->lte(\Illuminate\Support\Carbon::parse('2026-11-02 22:00:00', 'UTC')));
    }
}
