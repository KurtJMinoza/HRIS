<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

class EfficiencyPeriodResolver
{
    /**
     * @return array{type: string, start_date: string, end_date: string, timezone: string}
     */
    public function resolve(Request $request): array
    {
        $timezone = config('attendance.timezone', config('app.timezone', 'UTC'));
        $period = $this->normalizePeriod((string) $request->input(
            'period',
            $request->filled('start_date') || $request->filled('from_date') || $request->filled('date') ? 'custom' : 'today',
        ));
        $today = Carbon::now($timezone)->startOfDay();

        [$start, $end] = match ($period) {
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => $this->weekRange($today, $timezone),
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'custom' => $this->customRange($request, $today, $timezone),
            default => [$today->copy(), $today->copy()],
        };

        if ($end->lessThan($start)) {
            $end = $start->copy();
        }
        if ($start->diffInDays($end) > 366) {
            $end = $start->copy()->addDays(366);
        }

        return [
            'type' => $period,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'timezone' => $timezone,
        ];
    }

    private function normalizePeriod(string $period): string
    {
        return match (strtolower(trim($period))) {
            'yesterday' => 'yesterday',
            'this_week', 'week' => 'this_week',
            'this_month', 'month' => 'this_month',
            'custom' => 'custom',
            default => 'today',
        };
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function weekRange(Carbon $today, string $timezone): array
    {
        $firstDay = (int) config('attendance.first_day_of_week', Carbon::MONDAY);
        $start = $today->copy()->startOfWeek($firstDay);

        return [$start, $start->copy()->addDays(6)->endOfDay()->timezone($timezone)];
    }

    /**
     * @return array{Carbon, Carbon}
     */
    private function customRange(Request $request, Carbon $fallback, string $timezone): array
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'), $timezone)->startOfDay()
            : ($request->filled('from_date')
                ? Carbon::parse($request->input('from_date'), $timezone)->startOfDay()
                : ($request->filled('date')
                    ? Carbon::parse($request->input('date'), $timezone)->startOfDay()
                    : $fallback->copy()));
        $end = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'), $timezone)->startOfDay()
            : ($request->filled('to_date')
                ? Carbon::parse($request->input('to_date'), $timezone)->startOfDay()
                : $start->copy());

        return [$start, $end];
    }
}
