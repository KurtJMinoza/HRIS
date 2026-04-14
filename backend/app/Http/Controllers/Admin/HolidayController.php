<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\HolidayCalendarService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\PhPayrollReference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly Holiday $holiday,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    /**
     * Get merged holidays: Time and Date API + custom DB holidays.
     * DB holidays override API on same date.
     */
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $year = max(2020, min(2030, $year));

        $map = $this->holidayCalendar->mergedHolidaysForYear($year);

        $holidays = array_values($map);
        usort($holidays, fn ($a, $b) => strcmp($a['date'], $b['date']));

        // Enrich each row with PH payroll hints (first 8h / OT reference; statutory notes).
        $holidays = array_map(function (array $row) {
            $type = strtolower((string) ($row['type'] ?? 'special'));

            return array_merge($row, [
                'payroll_hints' => PhPayrollReference::hintsForHolidayType($type),
            ]);
        }, $holidays);

        return response()->json([
            'holidays' => $holidays,
            'year' => $year,
            'payroll_matrix' => [
                'first_8_hour_by_condition' => PhPayrollReference::firstEightHourMatrix(),
                'ot_multiplier_by_day_type' => PhPayrollReference::otMultiplierTable(),
            ],
        ]);
    }

    /**
     * Store a new custom holiday.
     */
    public function store(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'scope' => ['required', Rule::in(['nationwide', 'company', 'regional'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*' => ['string', 'max:120'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (($valid['scope'] ?? '') === 'regional' && empty($valid['regions'])) {
            return response()->json(['message' => 'Select at least one region for a regional holiday'], 422);
        }

        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $exists = $this->holiday->newQuery()->where('date', $valid['date'])->exists();
        if ($exists) {
            return response()->json(['message' => 'A holiday already exists on this date'], 422);
        }

        try {
            $this->payrollPeriodMutationGuard->assertCalendarDateMutableForPayroll(
                Carbon::parse((string) $valid['date'])->startOfDay()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday = $this->holiday->newQuery()->create([
            'name' => $valid['name'],
            'date' => $valid['date'],
            'type' => $valid['type'],
            'scope' => $valid['scope'],
            'description' => $valid['description'] ?? null,
            'regions' => ($valid['scope'] === 'regional') ? array_values($valid['regions'] ?? []) : null,
            'is_recurring' => (bool) ($valid['is_recurring'] ?? false),
            'status' => $valid['status'],
        ]);

        $this->holidayCalendar->flushMergedYearCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ], 201);
    }

    /**
     * Update a custom holiday.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);

        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'scope' => ['required', Rule::in(['nationwide', 'company', 'regional'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*' => ['string', 'max:120'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (($valid['scope'] ?? '') === 'regional' && empty($valid['regions'])) {
            return response()->json(['message' => 'Select at least one region for a regional holiday'], 422);
        }

        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $exists = $this->holiday->newQuery()
            ->where('date', $valid['date'])
            ->where('id', '!=', $id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'A holiday already exists on this date'], 422);
        }

        try {
            foreach (array_unique([$holiday->date?->toDateString(), $valid['date']]) as $d) {
                if ($d) {
                    $this->payrollPeriodMutationGuard->assertCalendarDateMutableForPayroll(Carbon::parse($d)->startOfDay());
                }
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday->update([
            'name' => $valid['name'],
            'date' => $valid['date'],
            'type' => $valid['type'],
            'scope' => $valid['scope'],
            'description' => $valid['description'] ?? null,
            'regions' => ($valid['scope'] === 'regional') ? array_values($valid['regions'] ?? []) : null,
            'is_recurring' => (bool) ($valid['is_recurring'] ?? false),
            'status' => $valid['status'],
        ]);

        $holiday->refresh();

        $this->holidayCalendar->flushMergedYearCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayPayload(Holiday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date,
            'name' => $holiday->name,
            'type' => $holiday->type,
            'scope' => $holiday->scope,
            'description' => $holiday->description,
            'regions' => $holiday->regions,
            'is_recurring' => (bool) $holiday->is_recurring,
            'status' => $holiday->status ?? 'active',
        ];
    }

    /**
     * Delete a custom holiday.
     */
    public function destroy(int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        try {
            if ($holiday->date) {
                $this->payrollPeriodMutationGuard->assertCalendarDateMutableForPayroll(
                    Carbon::parse($holiday->date->toDateString())->startOfDay()
                );
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $holiday->delete();

        $this->holidayCalendar->flushMergedYearCaches();

        return response()->json(['message' => 'Holiday deleted']);
    }
}
