<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\HolidayController;
use App\Models\Holiday;
use App\Services\HolidayCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class HolidayCalendarSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_tombstone_suppresses_seeded_holiday_without_showing_in_calendar(): void
    {
        Holiday::query()->whereYear('date', 2026)->where('name', 'Independence Day')->delete();

        Holiday::query()->create([
            'date' => '2026-06-12',
            'name' => 'Independence Day',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'inactive',
        ]);

        $service = app(HolidayCalendarService::class);
        $service->flushMergedYearCaches();

        $rows = $service->holidaysForYear(2026);
        $this->assertFalse($rows->contains(fn (array $row) => ($row['name'] ?? '') === 'Independence Day'));
    }

    public function test_would_seeded_holiday_resurface_on_delete_for_nationwide_baseline(): void
    {
        $holiday = Holiday::query()->create([
            'date' => '2026-06-12',
            'name' => 'Independence Day',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);

        $service = app(HolidayCalendarService::class);
        $this->assertTrue($service->wouldSeededHolidayResurfaceOnDelete($holiday));
    }

    public function test_moved_holiday_only_shows_on_new_date_in_calendar_and_list_api(): void
    {
        Holiday::query()->where('name', 'Independence Day')->delete();

        Holiday::query()->create([
            'date' => '2026-06-12',
            'name' => 'Independence Day',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'inactive',
            'is_recurring' => false,
        ]);
        Holiday::query()->create([
            'date' => '2026-06-13',
            'name' => 'Independence Day',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
            'is_swap' => true,
            'original_date' => '2026-06-12',
        ]);

        $service = app(HolidayCalendarService::class);
        $service->flushMergedYearCaches();

        $rows = $service->holidaysForYear(2026)
            ->filter(fn (array $row) => ($row['name'] ?? '') === 'Independence Day')
            ->values();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-13', $rows->first()['date']);
    }

    public function test_swap_keeps_holiday_active_and_visible_on_new_date(): void
    {
        Holiday::query()->where('name', 'Independence Day')->delete();

        $holiday = Holiday::query()->create([
            'date' => '2026-06-12',
            'name' => 'Independence Day',
            'type' => 'regular',
            'scope' => 'nationwide',
            'status' => 'active',
        ]);

        $response = app(HolidayController::class)->swap(
            Request::create('/admin/holidays/'.$holiday->id.'/swap', 'POST', ['date' => '2026-06-13']),
            (int) $holiday->id
        );

        $this->assertSame(200, $response->getStatusCode());

        $holiday->refresh();
        $this->assertSame('2026-06-13', $holiday->date?->format('Y-m-d'));
        $this->assertSame('active', $holiday->status);

        $stub = Holiday::query()
            ->where('date', '2026-06-12')
            ->where('scope', 'nationwide')
            ->where('status', 'inactive')
            ->first();
        $this->assertNotNull($stub);
        $this->assertNotSame($holiday->id, $stub->id);

        $service = app(HolidayCalendarService::class);
        $service->flushMergedYearCaches();
        $rows = $service->holidaysForYear(2026)
            ->filter(fn (array $row) => ($row['name'] ?? '') === 'Independence Day')
            ->values();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-13', $rows->first()['date']);
    }
}
