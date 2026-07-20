<?php

namespace Tests\Unit;

use App\Services\MergedKpiPerformanceService;
use Tests\TestCase;

class MergedKpiPerformanceServiceTest extends TestCase
{
    public function test_resolve_prefers_average_percent_when_kpi_activity_exists(): void
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

        $this->assertSame(77.0, $pct);
    }

    public function test_resolve_ignores_overall_efficiency_when_no_kpi_activity(): void
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

        $this->assertNull($pct);
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
        $this->assertSame('merged_kpi_period_snapshots', $rows->get(1660)['source']);
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
        $this->assertArrayHasKey('2026-07-10', $robina['by_date']);
        $this->assertArrayHasKey('2026-07-11', $robina['by_date']);
        $this->assertArrayHasKey('2026-07-10', $malubay['by_date']);
        $this->assertArrayHasKey('2026-07-11', $malubay['by_date']);
        $this->assertIsFloat($robina['by_date']['2026-07-10']);
        $this->assertIsFloat($malubay['by_date']['2026-07-11']);
        $this->assertArrayNotHasKey('2026-07-14', $malubay['by_date']);
    }

    public function test_no_performance_when_range_has_no_daily_snapshots(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        $bundle = $service->getPerformanceForRange('1999-01-01', '1999-01-01', [1660, 1671]);

        $this->assertTrue($bundle['by_employee']->isEmpty());
        $this->assertTrue($bundle['by_company']->isEmpty());
    }

    public function test_single_day_uses_per_employee_latest_snapshot_when_today_is_missing(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        // Live has a 2026-07-18 snapshot for 1671 only; 1660 should look back to 2026-07-17.
        $bundle = $service->getPerformanceForRange('2026-07-18', '2026-07-18', [1660, 1671]);
        $row = $bundle['by_employee']->get(1660);

        $this->assertNotNull($row);
        $this->assertIsFloat($row['performance_percentage']);
        $this->assertSame('2026-07-17', $row['as_of_date'] ?? null);
        $this->assertArrayHasKey('2026-07-18', $row['by_date']);
        $this->assertArrayHasKey(3, $bundle['by_company']->all());
    }

    public function test_contributor_progress_powers_sub_assignee_kpi(): void
    {
        $service = app(MergedKpiPerformanceService::class);
        $bundle = $service->getPerformanceForRange('2026-07-01', '2026-07-31', [1806, 1807]);
        $kurt = $bundle['by_employee']->get(1806);
        $laurence = $bundle['by_employee']->get(1807);

        $this->assertNotNull($kurt);
        $this->assertNotNull($laurence);
        $this->assertSame('merged_kpi_period_snapshots', $kurt['source']);
        $this->assertSame(33.33, $kurt['performance_percentage']);
        $this->assertSame(80.0, $laurence['performance_percentage']);
        $this->assertArrayHasKey('2026-07-17', $kurt['by_date']);
    }
}
