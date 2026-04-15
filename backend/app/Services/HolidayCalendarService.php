<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Canonical holiday calendar for payroll and admin using local data only:
 * `holidays` table + seeded fallback map (no external API call at runtime).
 *
 * **Recurring:** rows with `is_recurring = true` apply to every calendar year on the same month/day
 * (e.g. anchor 2026-01-01 → 2027-01-01) without inserting duplicate DB rows per year.
 */
class HolidayCalendarService
{
    private const CACHE_KEY_PREFIX = 'holiday_calendar:merged_year:';

    private const CACHE_TTL_SECONDS = 86400;

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $mergedByYear = [];

    public function __construct() {}

    public function flushMergedYearCaches(): void
    {
        $this->mergedByYear = [];
        foreach (range(2020, 2035) as $year) {
            Cache::forget(self::CACHE_KEY_PREFIX.$year);
        }
    }

    /**
     * Merged holidays keyed by Y-m-d. DB overrides API on the same date.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mergedHolidaysForYear(int $year): array
    {
        $year = max(2020, min(2035, $year));
        if (isset($this->mergedByYear[$year])) {
            return $this->mergedByYear[$year];
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$year;
        $this->mergedByYear[$year] = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($year) {
            $customHolidays = Holiday::query()
                ->whereYear('date', $year)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'active');
                })
                ->orderBy('date')
                ->get()
                ->map(fn (Holiday $h) => $this->serializeHolidayRow($h))
                ->keyBy('date')
                ->all();

            $map = $this->seededFallbackForYear($year);
            foreach ($customHolidays as $date => $h) {
                // DB rows always win over seeded defaults on same date.
                $map[$date] = $h;
            }

            foreach ($this->recurringHolidayRowsForYear($year, array_keys($map)) as $dateKey => $row) {
                if (! isset($map[$dateKey])) {
                    $map[$dateKey] = $row;
                }
            }

            return $map;
        });

        return $this->mergedByYear[$year];
    }

    /**
     * Local seeded fallback holidays (PH nationwide baseline).
     *
     * @return array<string, array<string, mixed>>
     */
    private function seededFallbackForYear(int $year): array
    {
        // Dynamic movable holidays are expected to come from DB rows; this fallback avoids API dependency.
        $rows = [
            ['md' => '01-01', 'name' => "New Year's Day", 'type' => 'regular'],
            ['md' => '04-09', 'name' => 'Araw ng Kagitingan', 'type' => 'regular'],
            ['md' => '05-01', 'name' => 'Labor Day', 'type' => 'regular'],
            ['md' => '06-12', 'name' => 'Independence Day', 'type' => 'regular'],
            ['md' => '08-21', 'name' => 'Ninoy Aquino Day', 'type' => 'special'],
            ['md' => '08-25', 'name' => 'National Heroes Day', 'type' => 'regular'],
            ['md' => '11-01', 'name' => "All Saints' Day", 'type' => 'special'],
            ['md' => '11-30', 'name' => 'Bonifacio Day', 'type' => 'regular'],
            ['md' => '12-08', 'name' => 'Feast of the Immaculate Conception', 'type' => 'special'],
            ['md' => '12-24', 'name' => 'Christmas Eve', 'type' => 'special'],
            ['md' => '12-25', 'name' => 'Christmas Day', 'type' => 'regular'],
            ['md' => '12-30', 'name' => 'Rizal Day', 'type' => 'regular'],
            ['md' => '12-31', 'name' => "New Year's Eve", 'type' => 'special'],
        ];

        $out = [];
        foreach ($rows as $r) {
            $date = sprintf('%04d-%s', $year, $r['md']);
            $out[$date] = [
                'date' => $date,
                'name' => $r['name'],
                'type' => $r['type'],
                'scope' => 'nationwide',
                'description' => null,
                'regions' => null,
                'is_recurring' => true,
                'status' => 'active',
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $skipDates  Dates already filled (explicit rows win).
     * @return array<string, array<string, mixed>>
     */
    private function recurringHolidayRowsForYear(int $year, array $skipDates): array
    {
        $out = [];
        $templates = Holiday::query()
            ->where('is_recurring', true)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->orderBy('date')
            ->get();

        foreach ($templates as $h) {
            $anchor = $h->date instanceof Carbon ? $h->date->copy() : Carbon::parse((string) $h->date);
            if ((int) $anchor->year === $year) {
                continue;
            }

            try {
                $effective = Carbon::createFromDate($year, (int) $anchor->format('n'), (int) $anchor->format('j'))->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            $key = $effective->format('Y-m-d');
            if (in_array($key, $skipDates, true) || isset($out[$key])) {
                continue;
            }

            $out[$key] = [
                'id' => $h->id,
                'date' => $key,
                'name' => $h->name,
                'type' => $h->type,
                'scope' => $h->scope,
                'description' => $h->description ?? null,
                'regions' => $h->regions,
                'is_recurring' => true,
                'status' => $h->status ?? 'active',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHolidayRow(Holiday $h): array
    {
        $d = $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date;

        return [
            'id' => $h->id,
            'date' => $d,
            'name' => $h->name,
            'type' => $h->type,
            'scope' => $h->scope,
            'description' => $h->description ?? null,
            'regions' => $h->regions,
            'is_recurring' => (bool) ($h->is_recurring ?? false),
            'status' => $h->status ?? 'active',
        ];
    }

    /**
     * Holiday row for payroll / rules engine (aligned with Admin → Holidays).
     *
     * @return array{name: string, type: string, scope: string, description: ?string}|null
     */
    public function holidayForDate(string $dateKey, ?int $companyId = null): ?array
    {
        $year = (int) substr($dateKey, 0, 4);
        if ($year < 2000) {
            return null;
        }

        $map = $this->mergedHolidaysForYear($year);
        $row = $map[$dateKey] ?? null;
        if (! $row) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'scope' => (string) ($row['scope'] ?? 'nationwide'),
            'description' => $row['description'] ?? null,
        ];
    }
}
