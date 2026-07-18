<?php

namespace Tests\Unit;

use App\Services\MergedKpiPerformanceService;
use Tests\TestCase;

class MergedKpiPerformanceServiceTest extends TestCase
{
    public function test_resolve_prefers_overall_percent_when_kpi_activity_exists(): void
    {
        $service = app(MergedKpiPerformanceService::class);

        $pct = $service->resolvePerformancePercentage([
            'kpi_count' => 2,
            'snapshot_count' => 10,
            'total_items' => 100,
            'overall_percent' => 80,
            'average_percent' => 77,
            'overall_efficiency' => 99,
        ]);

        $this->assertSame(80.0, $pct);
    }

    public function test_resolve_uses_overall_efficiency_when_no_kpi_activity(): void
    {
        $service = app(MergedKpiPerformanceService::class);

        $pct = $service->resolvePerformancePercentage([
            'kpi_count' => 0,
            'snapshot_count' => 0,
            'total_items' => 0,
            'overall_percent' => 0,
            'average_percent' => 0,
            'overall_efficiency' => 100,
        ]);

        $this->assertSame(100.0, $pct);
    }

    public function test_resolve_returns_null_when_no_usable_metrics(): void
    {
        $service = app(MergedKpiPerformanceService::class);

        $pct = $service->resolvePerformancePercentage([
            'kpi_count' => 0,
            'snapshot_count' => 0,
            'total_items' => 0,
            'overall_percent' => 0,
            'average_percent' => 0,
            'overall_efficiency' => null,
        ]);

        $this->assertNull($pct);
    }

    public function test_loads_kpi_rows_mapped_to_hris_user_ids(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        $bundle = $service->getPerformanceForRange('2026-07-01', '2026-07-13', [1660, 1671, 999999]);
        $rows = $bundle['by_employee'];

        $this->assertTrue($rows->has(1660));
        $this->assertTrue($rows->has(1671));
        $this->assertFalse($rows->has(999999));

        $this->assertIsFloat($rows->get(1660)['performance_percentage']);
        $this->assertIsFloat($rows->get(1671)['performance_percentage']);
        $this->assertContains($rows->get(1660)['source'], [
            'merged_kpi_period_snapshots',
        ]);
        $this->assertArrayHasKey(3, $bundle['by_company']->all());
        $this->assertIsFloat($bundle['by_company']->get(3));
    }

    public function test_period_snapshots_attach_performance_to_dates(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        $bundle = $service->getPerformanceForRange('2026-07-10', '2026-07-11', [1660, 1671]);
        $robina = $bundle['by_employee']->get(1671);
        $malubay = $bundle['by_employee']->get(1660);

        $this->assertNotNull($robina);
        $this->assertNotNull($malubay);
        $this->assertArrayHasKey('by_date', $robina);
        $this->assertSame(100.0, $robina['by_date']['2026-07-10'] ?? null);
        $this->assertSame(100.0, $robina['by_date']['2026-07-11'] ?? null);
        $this->assertSame(100.0, $malubay['by_date']['2026-07-10'] ?? null);
        $this->assertSame(33.0, $malubay['by_date']['2026-07-11'] ?? null);
        $this->assertArrayNotHasKey('2026-07-14', $malubay['by_date']);
    }

    public function test_no_performance_when_range_has_no_daily_snapshots(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        $bundle = $service->getPerformanceForRange('1999-01-01', '1999-01-01', [1660, 1671]);

        $this->assertTrue($bundle['by_employee']->isEmpty());
        $this->assertTrue($bundle['by_company']->isEmpty());
    }
}
