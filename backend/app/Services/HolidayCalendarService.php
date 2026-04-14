<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\Carbon;

/**
 * Canonical holiday calendar for payroll and admin: Calendarific API + `holidays` table,
 * with DB rows overriding API on the same date (same merge as Admin HolidayController).
 *
 * **Recurring:** rows with `is_recurring = true` apply to every calendar year on the same month/day
 * (e.g. anchor 2026-01-01 → 2027-01-01) without inserting duplicate DB rows per year.
 */
class HolidayCalendarService
{
    /** @var array<int, array<string, array<string, mixed>>> */
    private array $mergedByYear = [];

    public function __construct(
        private readonly CalendarificHolidayService $apiHolidayService,
    ) {}

    public function flushMergedYearCaches(): void
    {
        $this->mergedByYear = [];
    }

    /**
     * Merged holidays keyed by Y-m-d. DB overrides API on the same date.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mergedHolidaysForYear(int $year): array
    {
        $year = max(2020, min(2030, $year));
        if (isset($this->mergedByYear[$year])) {
            return $this->mergedByYear[$year];
        }

        $apiHolidays = $this->apiHolidayService->getHolidays($year);

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

        $map = [];
        foreach ($apiHolidays as $h) {
            $map[$h['date']] = [
                'date' => $h['date'],
                'name' => $h['name'],
                'type' => $h['type'],
                'scope' => 'nationwide',
                'description' => $h['description'] ?? null,
            ];
        }
        foreach ($customHolidays as $date => $h) {
            $map[$date] = $h;
        }

        foreach ($this->recurringHolidayRowsForYear($year, array_keys($map)) as $dateKey => $row) {
            if (! isset($map[$dateKey])) {
                $map[$dateKey] = $row;
            }
        }

        $this->mergedByYear[$year] = $map;

        return $this->mergedByYear[$year];
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
