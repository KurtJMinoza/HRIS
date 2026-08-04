<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\Holiday;
use App\Models\PayrollDailyRecord;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\HolidayCalendarService;
use App\Services\HolidayScopeResolver;
use App\Services\HolidayService;
use App\Services\PolicyResolverService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\PhPayrollReference;
use App\Support\TextSanitizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly HolidayService $holidayService,
        private readonly HolidayScopeResolver $holidayScopeResolver,
        private readonly PolicyResolverService $policyResolver,
        private readonly Holiday $holiday,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    /**
     * Get yearly holidays, preserving multiple scoped holidays on the same date.
     *
     * Heavy fields (`impact`, `holiday_policy`) are opt-in via `include=impact,holiday_policy`
     * so the Admin Holiday calendar stays fast by default.
     */
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $year = max(2020, min(2030, $year));
        $companyId = $request->filled('company_id') ? max(1, (int) $request->input('company_id')) : null;
        $include = $this->parseHolidayInclude($request);
        $includeImpact = in_array('impact', $include, true);
        $includePolicy = in_array('holiday_policy', $include, true) || in_array('policy', $include, true);

        $holidays = $this->holidayCalendar->holidaysForYear($year)
            ->map(function (array $row) use ($companyId, $includeImpact, $includePolicy) {
                $type = strtolower((string) ($row['type'] ?? 'special'));
                $payload = array_merge($row, [
                    'payroll_hints' => PhPayrollReference::hintsForHolidayType($type),
                ]);
                if ($includeImpact) {
                    $payload['impact'] = $this->holidayImpact($row);
                }
                if ($includePolicy) {
                    $payload['holiday_policy'] = $this->holidayPolicySnapshot($row, $companyId);
                }

                return $payload;
            })
            ->values()
            ->all();

        return response()->json([
            'holidays' => $holidays,
            'year' => $year,
            'payroll_matrix' => [
                'first_8_hour_by_condition' => PhPayrollReference::firstEightHourMatrix(),
                'ot_multiplier_by_day_type' => PhPayrollReference::otMultiplierTable(),
            ],
        ]);
    }

    public function employeeIndex(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $year = max(2020, min(2030, $year));
        $employee = $request->user();
        $include = $this->parseHolidayInclude($request);
        $includeImpact = in_array('impact', $include, true);
        $includePolicy = in_array('holiday_policy', $include, true) || in_array('policy', $include, true);

        $rows = $this->holidayCalendar->holidaysForYear($year)
            ->filter(fn (array $row) => $this->holidayAppliesToEmployee($row, $employee))
            ->values()
            ->all();

        $rows = $this->dedupeEmployeeHolidayRows($rows);

        $summary = [
            'regular' => 0,
            'special' => 0,
            'local' => 0,
            'total' => count($rows),
        ];

        foreach ($rows as $row) {
            $type = strtolower((string) ($row['type'] ?? ''));
            $scope = strtolower((string) ($row['scope'] ?? 'nationwide'));
            if ($type === 'regular') {
                $summary['regular']++;
            } elseif (in_array($type, ['special', 'special_non_working'], true)) {
                $summary['special']++;
            }
            if (! in_array($scope, ['nationwide', 'regional'], true)) {
                $summary['local']++;
            }
        }

        $companyId = $employee->getEffectiveCompanyId();
        $branchId = $employee->branch_id !== null ? (int) $employee->branch_id : null;

        return response()->json([
            'year' => $year,
            'holidays' => array_map(function (array $row) use ($includeImpact, $includePolicy, $companyId, $branchId) {
                $payload = array_merge($row, [
                    'payroll_hints' => PhPayrollReference::hintsForHolidayType(strtolower((string) ($row['type'] ?? 'special'))),
                ]);
                if ($includeImpact) {
                    $payload['impact'] = $this->holidayImpact($row);
                }
                if ($includePolicy) {
                    $payload['holiday_policy'] = $this->holidayPolicySnapshot($row, $companyId, $branchId);
                }

                return $payload;
            }, $rows),
            'summary' => $summary,
        ]);
    }

    /** @return list<string> */
    private function parseHolidayInclude(Request $request): array
    {
        $raw = $request->input('include', []);
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $raw
        ))));
    }

    /** @return array<string, mixed> */
    private function holidayPolicySnapshot(array $holiday, ?int $companyId, ?int $branchId = null): array
    {
        $dateKey = (string) ($holiday['date'] ?? now()->toDateString());
        $policy = $this->policyResolver->getActivePolicy($companyId, $branchId, $dateKey);
        $type = strtolower(trim(str_replace(['-', ' '], '_', (string) ($holiday['type'] ?? 'special'))));
        $ruleCode = match ($type) {
            'regular', 'regular_holiday' => 'RH',
            'double', 'double_holiday' => 'DH',
            'special_working', 'special_working_day' => 'SH',
            default => 'SH',
        };
        $multipliers = $this->policyResolver->getMultipliersForRule($policy, $ruleCode);
        $settings = $policy?->resolvedHolidayPolicy() ?? \App\Models\Policy::DEFAULT_HOLIDAY_POLICY;

        return [
            'source' => 'payroll_policy_settings',
            'policy_id' => $policy?->id,
            'policy_name' => $policy?->name ?? 'DOLE Global Default',
            'scope' => $policy?->company_id !== null ? 'company_override' : 'global_default',
            'pay_unworked_regular' => true,
            'pay_unworked_special' => (bool) ($settings['pay_unworked_special'] ?? false),
            'worked_multiplier' => (float) $multipliers['first_8'],
            'ot_multiplier' => (float) $multipliers['ot'],
            'nd_addon_multiplier' => max(0.10, (float) ($multipliers['nd_addon'] ?? 0.10)),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $valid = $this->validateHolidayPayload($request);
        $payloads = $this->payloadsForWrite($valid);

        foreach ($payloads as $payload) {
            if (($payload['status'] ?? 'active') !== 'inactive' && $this->holidayExistsForScope($payload)) {
                return response()->json(['message' => 'A holiday already exists on this date for one of the selected scopes'], 422);
            }
        }

        try {
            foreach ($payloads as $payload) {
                $this->assertHolidayDatesMutable([$valid['date']], $payload);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holidays = [];
        foreach ($payloads as $payload) {
            $holidays[] = $this->upsertHolidayRow($payload);
        }
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holidays[0]),
            'holidays' => array_map(fn (Holiday $holiday) => $this->holidayPayload($holiday), $holidays),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $valid = $this->validateHolidayPayload($request);
        $payload = $this->payloadsForWrite($valid)[0] ?? $this->payloadForWrite($valid);

        if ($this->holidayExistsForScope($payload, $id)) {
            return response()->json(['message' => 'A holiday already exists on this date for the selected scope'], 422);
        }

        try {
            $oldTargets = $this->scopeTargetsFromHoliday($holiday);
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString()], $oldTargets);
            $this->assertHolidayDatesMutable([$valid['date']], $payload);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday->update($payload);
        $holiday->refresh();
        $holiday->syncHolidayScopes();
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ]);
    }

    public function swap(Request $request, int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $valid = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $targets = $this->scopeTargetsFromHoliday($holiday);
        $candidate = array_merge($targets, ['date' => $valid['date']]);
        $this->deactivateConflictingHolidaysOnDate(
            $targets,
            $valid['date'],
            $id,
            (string) $holiday->name
        );
        if ($this->holidayExistsForScope($candidate, $id)) {
            return response()->json(['message' => 'A holiday already exists on the swap date for this scope'], 422);
        }

        try {
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString(), $valid['date']], $targets);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $oldDate = $holiday->date instanceof Carbon
            ? $holiday->date->format('Y-m-d')
            : (string) $holiday->date;
        $newDate = $valid['date'];

        if ($oldDate !== $newDate) {
            $this->upsertInactiveHolidayStub($holiday, $oldDate, (int) $holiday->id);
            $holiday->update([
                'date' => $newDate,
                'original_date' => $holiday->original_date ?? $oldDate,
                'is_swap' => true,
                'status' => 'active',
            ]);
            $this->holidayService->flushCoverageForDate($oldDate);
            $this->holidayService->flushCoverageForDate($newDate);
        } else {
            $holiday->update(['date' => $newDate]);
        }

        $holiday->refresh();
        $holiday->syncHolidayScopes();
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ]);
    }

    public function swapSeeded(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'new_date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working'])],
            'scope' => ['required', Rule::in(['nationwide', 'regional', 'company', 'branch', 'division', 'department', 'section_unit', 'employee'])],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'section_unit_id' => ['nullable', 'integer', Rule::exists('sections_or_units', 'id')],
            'employee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*' => ['string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_recurring' => ['sometimes', 'boolean'],
        ]);

        $old = $this->normalizeSeededPayload(array_merge($valid, [
            'status' => 'inactive',
        ]), $valid['date']);
        $new = $this->normalizeSeededPayload(array_merge($valid, [
            'date' => $valid['new_date'],
            'status' => 'active',
            'is_swap' => true,
            'original_date' => $valid['date'],
        ]), $valid['new_date']);

        $this->deactivateConflictingHolidaysOnDate($old, (string) $new['date'], null, (string) ($valid['name'] ?? ''));

        if ($this->holidayExistsForScope($new)) {
            return response()->json(['message' => 'A holiday already exists on the swap date for this scope'], 422);
        }

        try {
            $this->assertHolidayDatesMutable([$old['date']], $old);
            $this->assertHolidayDatesMutable([$new['date']], $new);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $existing = $this->holiday->newQuery()
            ->where('date', $old['date'])
            ->where('scope', $old['scope'])
            ->where('company_id', $old['company_id'] ?? null)
            ->where('branch_id', $old['branch_id'] ?? null)
            ->where('division_id', $old['division_id'] ?? null)
            ->where('department_id', $old['department_id'] ?? null)
            ->where('section_unit_id', $old['section_unit_id'] ?? null)
            ->where('employee_id', $old['employee_id'] ?? null)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $oldDate = (string) $old['date'];
            $newDate = (string) $new['date'];
            if ($oldDate !== $newDate) {
                $this->upsertInactiveHolidayStub($existing, $oldDate, (int) $existing->id);
                $existing->update([
                    'date' => $newDate,
                    'original_date' => $existing->original_date ?? $oldDate,
                    'is_swap' => true,
                    'status' => 'active',
                ]);
                $this->holidayService->flushCoverageForDate($oldDate);
                $this->holidayService->flushCoverageForDate($newDate);
            }
            $holiday = $existing->refresh();
            $holiday->syncHolidayScopes();
        } else {
            $this->holiday->newQuery()->updateOrCreate(
                [
                    'date' => $old['date'],
                    'scope' => $old['scope'],
                    'company_id' => $old['company_id'] ?? null,
                    'branch_id' => $old['branch_id'] ?? null,
                    'division_id' => $old['division_id'] ?? null,
                    'department_id' => $old['department_id'] ?? null,
                    'section_unit_id' => $old['section_unit_id'] ?? null,
                    'employee_id' => $old['employee_id'] ?? null,
                ],
                $this->payloadForWrite($old)
            );
            $holiday = $this->holiday->newQuery()->create($this->payloadForWrite($new));
            $holiday->syncHolidayScopes();
            $this->holidayService->flushCoverageForDate($valid['date']);
            $this->holidayService->flushCoverageForDate($valid['new_date']);
        }

        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ], 201);
    }

    /**
     * Create a Swap Holiday with coverage-based targeting.
     */
    public function storeSwap(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'original_date' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working'])],
            'coverage_type' => ['required', Rule::in(['company', 'branches', 'divisions', 'departments', 'section_units', 'employees'])],
            'coverage_ids' => ['required', 'array', 'min:1', 'max:500'],
            'coverage_ids.*' => ['integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $coverageIds = array_values(array_unique(array_filter(
            array_map('intval', $valid['coverage_ids'])
        )));

        $this->validateCoverageIds($valid['coverage_type'], $coverageIds);

        $scope = match ($valid['coverage_type']) {
            'company' => 'company',
            'branches' => 'branch',
            'divisions' => 'division',
            'departments' => 'department',
            'section_units' => 'section_unit',
            'employees' => 'employee',
        };

        $payload = [
            'name' => $valid['name'],
            'date' => $valid['date'],
            'type' => $valid['type'],
            'scope' => $scope,
            'coverage_type' => $valid['coverage_type'],
            'coverage_ids' => $coverageIds,
            'is_swap' => true,
            'original_date' => $valid['original_date'] ?? null,
            'description' => $valid['description'] ?? null,
            'regions' => null,
            'is_recurring' => (bool) ($valid['is_recurring'] ?? false),
            'status' => $valid['status'] ?? 'active',
            'company_id' => null,
            'branch_id' => null,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'employee_id' => null,
        ];

        $payload = array_merge($payload, $this->coverageTargetColumns($valid['coverage_type'], $coverageIds));

        if (! empty($valid['original_date']) && $valid['original_date'] !== $valid['date']) {
            $this->upsertInactiveHolidayStub($this->holiday->newInstance($payload), $valid['original_date']);
            $this->holidayService->flushCoverageForDate($valid['original_date']);
        }

        $holiday = $this->holiday->newQuery()->create($payload);
        $holiday->refresh()->syncHolidayScopes();
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($valid['date']);
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
            'affected_employees' => $this->holidayService->countAffectedEmployees($holiday),
        ], 201);
    }

    /**
     * Update a Swap Holiday's coverage.
     */
    public function updateSwap(Request $request, int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $oldDateKey = $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date;

        $valid = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'original_date' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['sometimes', Rule::in(['regular', 'special', 'special_non_working'])],
            'coverage_type' => ['sometimes', Rule::in(['company', 'branches', 'divisions', 'departments', 'section_units', 'employees'])],
            'coverage_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'coverage_ids.*' => ['integer'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (isset($valid['type']) && $valid['type'] === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $updateData = [];

        if (isset($valid['name'])) {
            $updateData['name'] = $valid['name'];
        }
        if (isset($valid['date'])) {
            $updateData['date'] = $valid['date'];
        }
        if (array_key_exists('original_date', $valid)) {
            $updateData['original_date'] = $valid['original_date'];
        }
        if (isset($valid['type'])) {
            $updateData['type'] = $valid['type'];
        }
        if (isset($valid['description'])) {
            $updateData['description'] = $valid['description'];
        }
        if (isset($valid['is_recurring'])) {
            $updateData['is_recurring'] = (bool) $valid['is_recurring'];
        }
        if (isset($valid['status'])) {
            $updateData['status'] = $valid['status'];
        }

        if (isset($valid['coverage_type']) || isset($valid['coverage_ids'])) {
            $coverageType = (string) ($valid['coverage_type'] ?? $holiday->coverage_type ?? '');
            $coverageIds = array_values(array_unique(array_filter(
                array_map('intval', $valid['coverage_ids'] ?? $holiday->getCoverageIds())
            )));
            $this->validateCoverageIds($coverageType, $coverageIds);

            $updateData['coverage_type'] = $coverageType;
            $updateData['coverage_ids'] = $coverageIds;
            $updateData['scope'] = match ($coverageType) {
                'company' => 'company',
                'branches' => 'branch',
                'divisions' => 'division',
                'departments' => 'department',
                'section_units' => 'section_unit',
                'employees' => 'employee',
            };
            $updateData = array_merge($updateData, $this->coverageTargetColumns($coverageType, $coverageIds));
        }

        $holiday->update($updateData);
        $holiday->refresh();
        $holiday->syncHolidayScopes();

        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($oldDateKey);
        $this->holidayService->flushCoverageForDate(
            $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date
        );

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
            'affected_employees' => $this->holidayService->countAffectedEmployees($holiday),
        ]);
    }

    public function destroy(int|string $id): JsonResponse
    {
        if (! is_numeric($id)) {
            return response()->json(['message' => 'Invalid holiday id'], 404);
        }
        $id = (int) $id;

        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $dateKey = $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date;

        try {
            $this->assertHolidayDatesMutable(
                [$holiday->date?->toDateString()],
                $this->scopeTargetsFromHoliday($holiday)
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Cannot delete this holiday because payroll for this date has already been finalized.',
            ], 422);
        }

        // Built-in PH holidays must stay suppressed after delete or the seeded calendar resurfaces.
        if ($this->holidayCalendar->wouldSeededHolidayResurfaceOnDelete($holiday)) {
            $holiday->update([
                'status' => 'inactive',
                'is_swap' => false,
                'original_date' => null,
                'is_recurring' => false,
            ]);
            $this->holidayCalendar->flushMergedYearCaches();
            $this->holidayService->flushCoverageForDate($dateKey);
            $this->holidayService->flushRuntimeCaches();

            return response()->json([
                'message' => 'Holiday deleted',
                'deleted' => true,
                'removed' => true,
            ]);
        }

        $holiday->delete();
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($dateKey);
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'message' => 'Holiday deleted',
            'deleted' => true,
        ]);
    }

    /**
     * Delete a built-in (seeded) Philippine holiday that has no DB id yet.
     * Creates a hidden suppression stub so the national baseline does not reappear.
     */
    public function destroySeeded(Request $request): JsonResponse
    {
        $valid = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(['regular', 'special', 'special_non_working', 'company'])],
        ]);

        $dateKey = (string) $valid['date'];
        $name = TextSanitizer::clean((string) $valid['name'], (string) $valid['name']) ?? (string) $valid['name'];
        $type = (string) ($valid['type'] ?? 'regular');

        try {
            $this->assertHolidayDatesMutable([$dateKey], [
                'scope' => 'nationwide',
                'company_id' => null,
                'branch_id' => null,
                'department_id' => null,
                'employee_id' => null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Cannot delete this holiday because payroll for this date has already been finalized.',
            ], 422);
        }

        $existing = $this->holiday->newQuery()
            ->whereDate('date', $dateKey)
            ->where('scope', 'nationwide')
            ->whereNull('company_id')
            ->whereNull('branch_id')
            ->whereNull('department_id')
            ->whereNull('employee_id')
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])
            ->first();

        if ($existing) {
            return $this->destroy((int) $existing->id);
        }

        $this->holiday->newQuery()->create([
            'name' => $name,
            'date' => $dateKey,
            'type' => $type,
            'scope' => 'nationwide',
            'company_id' => null,
            'branch_id' => null,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'employee_id' => null,
            'is_recurring' => false,
            'status' => 'inactive',
            'is_swap' => false,
            'original_date' => null,
        ]);

        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($dateKey);
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'message' => 'Holiday deleted',
            'deleted' => true,
            'removed' => true,
        ]);
    }

    public function deactivate(int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $dateKey = $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date;

        try {
            $this->assertHolidayDatesMutable(
                [$holiday->date?->toDateString()],
                $this->scopeTargetsFromHoliday($holiday)
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Cannot change this holiday because payroll for this date has already been finalized.',
            ], 422);
        }

        $holiday->update(['status' => 'inactive']);
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($dateKey);
        $this->holidayService->flushRuntimeCaches();

        return response()->json([
            'message' => 'Holiday deactivated',
            'holiday' => $this->holidayPayload($holiday->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateHolidayPayload(Request $request): array
    {
        if (! $request->filled('scope') && $request->filled('scope_type')) {
            $request->merge(['scope' => $request->input('scope_type')]);
        }

        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working'])],
            'scope' => ['required', Rule::in(['nationwide', 'regional', 'company', 'branch', 'division', 'department', 'section_unit', 'employee'])],
            'scope_type' => ['nullable', Rule::in(['nationwide', 'regional', 'company', 'branch', 'division', 'department', 'section_unit', 'employee'])],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'division_id' => ['nullable', 'integer', Rule::exists('divisions', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'section_unit_id' => ['nullable', 'integer', Rule::exists('sections_or_units', 'id')],
            'employee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'company_ids' => ['nullable', 'array', 'max:100'],
            'company_ids.*' => ['integer', Rule::exists('companies', 'id')],
            'branch_ids' => ['nullable', 'array', 'max:200'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
            'division_ids' => ['nullable', 'array', 'max:250'],
            'division_ids.*' => ['integer', Rule::exists('divisions', 'id')],
            'department_ids' => ['nullable', 'array', 'max:300'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')],
            'section_unit_ids' => ['nullable', 'array', 'max:300'],
            'section_unit_ids.*' => ['integer', Rule::exists('sections_or_units', 'id')],
            'employee_ids' => ['nullable', 'array', 'max:500'],
            'employee_ids.*' => ['integer', Rule::exists('users', 'id')],
            'description' => ['nullable', 'string', 'max:1000'],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*' => ['string', 'max:120'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $valid['company_ids'] = $this->normalizedIdList($valid['company_ids'] ?? null, $valid['company_id'] ?? null);
        $valid['branch_ids'] = $this->normalizedIdList($valid['branch_ids'] ?? null, $valid['branch_id'] ?? null);
        $valid['division_ids'] = $this->normalizedIdList($valid['division_ids'] ?? null, $valid['division_id'] ?? null);
        $valid['department_ids'] = $this->normalizedIdList($valid['department_ids'] ?? null, $valid['department_id'] ?? null);
        $valid['section_unit_ids'] = $this->normalizedIdList($valid['section_unit_ids'] ?? null, $valid['section_unit_id'] ?? null);
        $valid['employee_ids'] = $this->normalizedIdList($valid['employee_ids'] ?? null, $valid['employee_id'] ?? null);

        $scope = (string) ($valid['scope'] ?? 'nationwide');
        if ($scope === 'regional' && empty($valid['regions'])) {
            abort(response()->json(['message' => 'Select at least one region for a regional holiday'], 422));
        }
        if (in_array($scope, ['company', 'branch', 'division', 'department', 'section_unit', 'employee'], true) && empty($valid['company_ids'])) {
            abort(response()->json(['message' => 'Select at least one company for this holiday scope'], 422));
        }
        if (in_array($scope, ['branch', 'division', 'department', 'section_unit'], true) && empty($valid['branch_ids'])) {
            abort(response()->json(['message' => 'Select at least one branch for this holiday scope'], 422));
        }
        if (in_array($scope, ['department', 'section_unit'], true) && empty($valid['department_ids'])) {
            abort(response()->json(['message' => 'Select at least one department for this holiday scope'], 422));
        }
        if ($scope === 'division' && empty($valid['division_ids'])) {
            abort(response()->json(['message' => 'Select at least one division for this holiday scope'], 422));
        }
        if ($scope === 'section_unit' && empty($valid['section_unit_ids'])) {
            abort(response()->json(['message' => 'Select at least one section/unit for this holiday scope'], 422));
        }
        if ($scope === 'employee' && empty($valid['employee_ids'])) {
            abort(response()->json(['message' => 'Select at least one employee for this holiday scope'], 422));
        }

        $this->validateScopeHierarchy($valid);

        if (! in_array($scope, ['company', 'branch', 'division', 'department', 'section_unit', 'employee'], true)) {
            $valid['company_id'] = null;
            $valid['branch_id'] = null;
            $valid['division_id'] = null;
            $valid['department_id'] = null;
            $valid['section_unit_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'company') {
            $valid['branch_id'] = null;
            $valid['division_id'] = null;
            $valid['department_id'] = null;
            $valid['section_unit_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'branch') {
            $valid['division_id'] = null;
            $valid['department_id'] = null;
            $valid['section_unit_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'division') {
            $valid['department_id'] = null;
            $valid['section_unit_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'department') {
            $valid['section_unit_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'section_unit') {
            $valid['employee_id'] = null;
        }

        if (in_array($scope, ['company', 'branch', 'division', 'department', 'section_unit', 'employee'], true)) {
            $valid['company_id'] = $valid['company_ids'][0] ?? null;
            $valid['branch_id'] = $valid['branch_ids'][0] ?? null;
            $valid['division_id'] = $valid['division_ids'][0] ?? null;
            $valid['department_id'] = $valid['department_ids'][0] ?? null;
            $valid['section_unit_id'] = $valid['section_unit_ids'][0] ?? null;
            $valid['employee_id'] = $valid['employee_ids'][0] ?? null;
        }

        return $valid;
    }

    private function holidayAppliesToEmployee(array $row, User $employee): bool
    {
        $date = (string) ($row['date'] ?? '');

        return $date !== '' && $this->holidayScopeResolver->appliesRowToEmployee($row, $employee, Carbon::parse($date));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeEmployeeHolidayRows(array $rows): array
    {
        $deduped = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['date'] ?? '')).'|'.trim((string) ($row['name'] ?? '')));
            if ($key === '|') {
                continue;
            }
            if (! isset($deduped[$key]) || $this->holidayScopeRank($row) > $this->holidayScopeRank($deduped[$key])) {
                $deduped[$key] = $row;
            }
        }

        usort($deduped, fn (array $a, array $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return array_values($deduped);
    }

    private function holidayScopeRank(array $row): int
    {
        return match (strtolower((string) ($row['scope'] ?? 'nationwide'))) {
            'employee' => 70,
            'section_unit' => 60,
            'department' => 50,
            'division' => 45,
            'branch' => 40,
            'company' => 30,
            'regional' => 20,
            default => 10,
        };
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function normalizeSeededPayload(array $valid, string $date): array
    {
        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }
        $valid['date'] = $date;
        $valid['is_recurring'] = (bool) ($valid['is_recurring'] ?? false);
        $valid['description'] = $valid['description'] ?? null;
        $valid['regions'] = is_array($valid['regions'] ?? null) ? $valid['regions'] : [];
        $this->validateScopeHierarchy($valid);

        $scope = (string) ($valid['scope'] ?? 'nationwide');
        if (! in_array($scope, ['company', 'branch', 'department', 'employee'], true)) {
            $valid['company_id'] = null;
            $valid['branch_id'] = null;
            $valid['department_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'company') {
            $valid['branch_id'] = null;
            $valid['department_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'branch') {
            $valid['department_id'] = null;
            $valid['employee_id'] = null;
        } elseif ($scope === 'department') {
            $valid['employee_id'] = null;
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $valid
     */
    private function validateScopeHierarchy(array $valid): void
    {
        $companyIds = $this->normalizedIdList($valid['company_ids'] ?? null, $valid['company_id'] ?? null);
        $branchIds = $this->normalizedIdList($valid['branch_ids'] ?? null, $valid['branch_id'] ?? null);
        $divisionIds = $this->normalizedIdList($valid['division_ids'] ?? null, $valid['division_id'] ?? null);
        $departmentIds = $this->normalizedIdList($valid['department_ids'] ?? null, $valid['department_id'] ?? null);
        $sectionUnitIds = $this->normalizedIdList($valid['section_unit_ids'] ?? null, $valid['section_unit_id'] ?? null);
        $employeeIds = $this->normalizedIdList($valid['employee_ids'] ?? null, $valid['employee_id'] ?? null);

        if (! empty($companyIds)) {
            $existingCompanyIds = Company::query()->whereIn('id', $companyIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (count($existingCompanyIds) !== count($companyIds)) {
                abort(response()->json(['message' => 'One or more selected companies were not found'], 422));
            }
        }

        if (! empty($branchIds)) {
            $branches = Branch::query()->whereIn('id', $branchIds)->get(['id', 'company_id']);
            if ($branches->count() !== count($branchIds)) {
                abort(response()->json(['message' => 'One or more selected branches were not found'], 422));
            }
            if (! empty($companyIds)) {
                $invalid = $branches->contains(fn (Branch $branch) => ! in_array((int) $branch->company_id, $companyIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected branches do not belong to the selected companies'], 422));
                }
            }
        }

        if (! empty($divisionIds)) {
            $divisions = Division::query()->whereIn('id', $divisionIds)->get(['id', 'company_id', 'branch_id']);
            if ($divisions->count() !== count($divisionIds)) {
                abort(response()->json(['message' => 'One or more selected divisions were not found'], 422));
            }
            if (! empty($branchIds)) {
                $invalid = $divisions->contains(fn (Division $division) => ! in_array((int) $division->branch_id, $branchIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected divisions do not belong to the selected branches'], 422));
                }
            }
            if (! empty($companyIds)) {
                $invalid = $divisions->contains(fn (Division $division) => ! in_array((int) $division->company_id, $companyIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected divisions do not belong to the selected companies'], 422));
                }
            }
        }

        if (! empty($departmentIds)) {
            $departments = Department::query()->with('branch:id,company_id')->whereIn('id', $departmentIds)->get();
            if ($departments->count() !== count($departmentIds)) {
                abort(response()->json(['message' => 'One or more selected departments were not found'], 422));
            }
            if (! empty($divisionIds)) {
                $invalid = $departments->contains(fn (Department $department) => ! in_array((int) ($department->division_id ?? 0), $divisionIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected departments do not belong to the selected divisions'], 422));
                }
            }
            if (! empty($branchIds)) {
                $invalid = $departments->contains(fn (Department $department) => ! in_array((int) $department->branch_id, $branchIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected departments do not belong to the selected branches'], 422));
                }
            }
            if (! empty($companyIds)) {
                $invalid = $departments->contains(fn (Department $department) => ! in_array((int) ($department->branch?->company_id ?? 0), $companyIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected departments do not belong to the selected companies'], 422));
                }
            }
        }

        if (! empty($sectionUnitIds)) {
            $sections = SectionUnit::query()->whereIn('id', $sectionUnitIds)->get(['id', 'company_id', 'branch_id', 'division_id', 'department_id']);
            if ($sections->count() !== count($sectionUnitIds)) {
                abort(response()->json(['message' => 'One or more selected sections/units were not found'], 422));
            }
            if (! empty($departmentIds)) {
                $invalid = $sections->contains(fn (SectionUnit $section) => ! in_array((int) $section->department_id, $departmentIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected sections/units do not belong to the selected departments'], 422));
                }
            }
            if (! empty($divisionIds)) {
                $invalid = $sections->contains(fn (SectionUnit $section) => ! in_array((int) ($section->division_id ?? 0), $divisionIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected sections/units do not belong to the selected divisions'], 422));
                }
            }
            if (! empty($branchIds)) {
                $invalid = $sections->contains(fn (SectionUnit $section) => ! in_array((int) $section->branch_id, $branchIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected sections/units do not belong to the selected branches'], 422));
                }
            }
            if (! empty($companyIds)) {
                $invalid = $sections->contains(fn (SectionUnit $section) => ! in_array((int) $section->company_id, $companyIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected sections/units do not belong to the selected companies'], 422));
                }
            }
        }

        if (! empty($employeeIds)) {
            $employees = User::query()->whereIn('id', $employeeIds)->get();
            if ($employees->count() !== count($employeeIds)) {
                abort(response()->json(['message' => 'One or more selected employees were not found'], 422));
            }
            if (! empty($companyIds)) {
                $invalid = $employees->contains(fn (User $employee) => ! in_array((int) ($employee->getEffectiveCompanyId() ?? 0), $companyIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected employees do not belong to the selected companies'], 422));
                }
            }
            if (! empty($branchIds)) {
                $invalid = $employees->contains(fn (User $employee) => ! in_array((int) ($employee->branch_id ?? 0), $branchIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected employees do not belong to the selected branches'], 422));
                }
            }
            if (! empty($departmentIds)) {
                $invalid = $employees->contains(fn (User $employee) => ! in_array((int) ($employee->department_id ?? 0), $departmentIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected employees do not belong to the selected departments'], 422));
                }
            }
            if (! empty($sectionUnitIds)) {
                $invalid = $employees->contains(fn (User $employee) => ! in_array((int) ($employee->section_unit_id ?? 0), $sectionUnitIds, true));
                if ($invalid) {
                    abort(response()->json(['message' => 'One or more selected employees do not belong to the selected sections/units'], 422));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $valid
     */
    private function holidayExistsForScope(array $valid, ?int $ignoreId = null): bool
    {
        $query = $this->holiday->newQuery()
            ->where('date', $valid['date'])
            ->whereIn('status', ['active', 'draft']);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $scope = $valid['scope'] ?? 'nationwide';
        $coverageType = $valid['coverage_type'] ?? null;
        $coverageIds = $valid['coverage_ids'] ?? [];

        if ($scope === 'nationwide') {
            $query->where('scope', 'nationwide');
        } elseif ($coverageType !== null && is_array($coverageIds) && ! empty($coverageIds)) {
            $query->where('scope', $scope);
            $coverageIds = array_map('intval', $coverageIds);

            $query->where(function ($q) use ($coverageType, $coverageIds, $valid) {
                $q->where(function ($sub) use ($coverageType, $coverageIds) {
                    $sub->where('coverage_type', $coverageType)
                        ->whereJsonContains('coverage_ids', $coverageIds[0]);
                });

                if (! empty($valid['company_id'])) {
                    $q->orWhere(function ($sub) use ($valid) {
                        $sub->where('scope', $valid['scope'] ?? 'nationwide')
                            ->where('company_id', $valid['company_id'])
                            ->whereNull('branch_id')
                            ->whereNull('division_id')
                            ->whereNull('department_id')
                            ->whereNull('section_unit_id')
                            ->whereNull('employee_id');
                    });
                }
            });
        } else {
            $query->where('scope', $scope)
                ->where('company_id', $valid['company_id'] ?? null)
                ->where('branch_id', $valid['branch_id'] ?? null)
                ->where('division_id', $valid['division_id'] ?? null)
                ->where('department_id', $valid['department_id'] ?? null)
                ->where('section_unit_id', $valid['section_unit_id'] ?? null)
                ->where('employee_id', $valid['employee_id'] ?? null);
        }

        return $query->exists();
    }

    /**
     * @param  array<string, mixed>  $targets
     */
    private function deactivateConflictingHolidaysOnDate(
        array $targets,
        string $date,
        ?int $ignoreId = null,
        ?string $name = null
    ): void {
        $query = $this->holiday->newQuery()
            ->where('date', $date)
            ->where('scope', $targets['scope'] ?? 'nationwide')
            ->where('company_id', $targets['company_id'] ?? null)
            ->where('branch_id', $targets['branch_id'] ?? null)
            ->where('division_id', $targets['division_id'] ?? null)
            ->where('department_id', $targets['department_id'] ?? null)
            ->where('section_unit_id', $targets['section_unit_id'] ?? null)
            ->where('employee_id', $targets['employee_id'] ?? null)
            ->whereIn('status', ['active', 'draft']);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($name !== null && trim($name) !== '') {
            $query->where('name', $name);
        }

        $ids = $query->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $this->holiday->newQuery()->whereIn('id', $ids)->update(['status' => 'inactive']);
        $this->holidayService->flushCoverageForDate($date);
    }

    /** @param  array<string, mixed>  $payload */
    private function upsertHolidayRow(array $payload): Holiday
    {
        $keys = [
            'date' => $payload['date'],
            'scope' => $payload['scope'] ?? 'nationwide',
            'company_id' => $payload['company_id'] ?? null,
            'branch_id' => $payload['branch_id'] ?? null,
            'division_id' => $payload['division_id'] ?? null,
            'department_id' => $payload['department_id'] ?? null,
            'section_unit_id' => $payload['section_unit_id'] ?? null,
            'employee_id' => $payload['employee_id'] ?? null,
        ];

        $holiday = $this->holiday->newQuery()->updateOrCreate($keys, $payload);

        // ponytail: drop orphan duplicates left by old swap/deactivate paths (no unique DB constraint).
        $this->holiday->newQuery()
            ->where($keys)
            ->where('id', '!=', $holiday->id)
            ->delete();

        $holiday->refresh()->syncHolidayScopes();

        return $holiday;
    }

    /**
     * @return list<int>
     */
    private function normalizedIdList(mixed $ids, mixed $fallback = null): array
    {
        $list = is_array($ids) ? $ids : [];
        if (empty($list) && $fallback !== null && $fallback !== '') {
            $list = [$fallback];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : null,
            $list
        ))));
    }

    /**
     * @param  list<string|null>  $dates
     * @param  array<string, mixed>  $targets
     */
    private function assertHolidayDatesMutable(array $dates, array $targets): void
    {
        foreach (array_unique(array_filter($dates)) as $date) {
            $this->payrollPeriodMutationGuard->assertCalendarDateMutableForPayroll(
                Carbon::parse((string) $date)->startOfDay(),
                $targets['company_id'] ?? null,
                $targets['branch_id'] ?? null,
                $targets['department_id'] ?? null,
                $targets['employee_id'] ?? null
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeTargetsFromHoliday(Holiday $holiday): array
    {
        return [
            'scope' => $holiday->scope ?? 'nationwide',
            'company_id' => $holiday->company_id !== null ? (int) $holiday->company_id : null,
            'branch_id' => $holiday->branch_id !== null ? (int) $holiday->branch_id : null,
            'division_id' => $holiday->division_id !== null ? (int) $holiday->division_id : null,
            'department_id' => $holiday->department_id !== null ? (int) $holiday->department_id : null,
            'section_unit_id' => $holiday->section_unit_id !== null ? (int) $holiday->section_unit_id : null,
            'employee_id' => $holiday->employee_id !== null ? (int) $holiday->employee_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function payloadForWrite(array $valid): array
    {
        $name = TextSanitizer::clean((string) ($valid['name'] ?? ''), 'Holiday') ?? 'Holiday';
        $description = isset($valid['description'])
            ? TextSanitizer::clean((string) $valid['description'])
            : null;

        return [
            'name' => $name,
            'date' => $valid['date'],
            'type' => $valid['type'],
            'scope' => $valid['scope'],
            'company_id' => $valid['company_id'] ?? null,
            'branch_id' => $valid['branch_id'] ?? null,
            'division_id' => $valid['division_id'] ?? null,
            'department_id' => $valid['department_id'] ?? null,
            'section_unit_id' => $valid['section_unit_id'] ?? null,
            'employee_id' => $valid['employee_id'] ?? null,
            'description' => $description,
            'regions' => ($valid['scope'] === 'regional') ? array_values($valid['regions'] ?? []) : null,
            'is_recurring' => (bool) ($valid['is_recurring'] ?? false),
            'status' => $valid['status'],
            'is_swap' => (bool) ($valid['is_swap'] ?? false),
            'original_date' => $valid['original_date'] ?? null,
        ];
    }

    private function upsertInactiveHolidayStub(Holiday $source, string $stubDate, ?int $ignoreId = null): void
    {
        $targets = $this->scopeTargetsFromHoliday($source);

        $keys = [
            'date' => $stubDate,
            'scope' => $targets['scope'],
            'company_id' => $targets['company_id'],
            'branch_id' => $targets['branch_id'],
            'division_id' => $targets['division_id'],
            'department_id' => $targets['department_id'],
            'section_unit_id' => $targets['section_unit_id'],
            'employee_id' => $targets['employee_id'],
        ];

        $payload = [
            'name' => $source->name,
            'type' => $source->type,
            'description' => $source->description,
            'regions' => $source->regions,
            'is_recurring' => false,
            'status' => 'inactive',
            'is_swap' => false,
            'original_date' => null,
            'coverage_type' => $source->coverage_type,
            'coverage_ids' => $source->coverage_ids,
        ];

        $query = $this->holiday->newQuery()->where($keys);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $stub = $query->first();
        if ($stub) {
            $stub->update($payload);

            return;
        }

        $this->holiday->newQuery()->create(array_merge($keys, $payload));
    }

    /**
     * Expand plural UI selections into the existing one-target-per-row holiday model.
     *
     * @param  array<string, mixed>  $valid
     * @return list<array<string, mixed>>
     */
    private function payloadsForWrite(array $valid): array
    {
        $scope = (string) ($valid['scope'] ?? 'nationwide');
        $base = $this->payloadForWrite($valid);

        if ($scope === 'company') {
            return array_map(fn (int $companyId) => array_merge($base, [
                'company_id' => $companyId,
                'branch_id' => null,
                'division_id' => null,
                'department_id' => null,
                'section_unit_id' => null,
                'employee_id' => null,
                'regions' => null,
            ]), $this->normalizedIdList($valid['company_ids'] ?? null, $valid['company_id'] ?? null));
        }

        if ($scope === 'branch') {
            $branches = Branch::query()
                ->whereIn('id', $this->normalizedIdList($valid['branch_ids'] ?? null, $valid['branch_id'] ?? null))
                ->get(['id', 'company_id'])
                ->keyBy('id');

            return $branches->map(fn (Branch $branch) => array_merge($base, [
                'company_id' => (int) $branch->company_id,
                'branch_id' => (int) $branch->id,
                'division_id' => null,
                'department_id' => null,
                'section_unit_id' => null,
                'employee_id' => null,
                'regions' => null,
            ]))->values()->all();
        }

        if ($scope === 'division') {
            $divisions = Division::query()
                ->whereIn('id', $this->normalizedIdList($valid['division_ids'] ?? null, $valid['division_id'] ?? null))
                ->get(['id', 'company_id', 'branch_id'])
                ->keyBy('id');

            return $divisions->map(fn (Division $division) => array_merge($base, [
                'company_id' => (int) $division->company_id,
                'branch_id' => (int) $division->branch_id,
                'division_id' => (int) $division->id,
                'department_id' => null,
                'section_unit_id' => null,
                'employee_id' => null,
                'regions' => null,
            ]))->values()->all();
        }

        if ($scope === 'department') {
            $departments = Department::query()
                ->with('branch:id,company_id')
                ->whereIn('id', $this->normalizedIdList($valid['department_ids'] ?? null, $valid['department_id'] ?? null))
                ->get()
                ->keyBy('id');

            return $departments->map(fn (Department $department) => array_merge($base, [
                'company_id' => (int) ($department->branch?->company_id ?? $valid['company_id'] ?? 0) ?: null,
                'branch_id' => (int) $department->branch_id,
                'division_id' => $department->division_id !== null ? (int) $department->division_id : null,
                'department_id' => (int) $department->id,
                'section_unit_id' => null,
                'employee_id' => null,
                'regions' => null,
            ]))->values()->all();
        }

        if ($scope === 'section_unit') {
            $sections = SectionUnit::query()
                ->whereIn('id', $this->normalizedIdList($valid['section_unit_ids'] ?? null, $valid['section_unit_id'] ?? null))
                ->get(['id', 'company_id', 'branch_id', 'division_id', 'department_id'])
                ->keyBy('id');

            return $sections->map(fn (SectionUnit $section) => array_merge($base, [
                'company_id' => (int) $section->company_id,
                'branch_id' => (int) $section->branch_id,
                'division_id' => $section->division_id !== null ? (int) $section->division_id : null,
                'department_id' => (int) $section->department_id,
                'section_unit_id' => (int) $section->id,
                'employee_id' => null,
                'regions' => null,
            ]))->values()->all();
        }

        if ($scope === 'employee') {
            $employees = User::query()
                ->whereIn('id', $this->normalizedIdList($valid['employee_ids'] ?? null, $valid['employee_id'] ?? null))
                ->orderByLastName()
                ->get()
                ->keyBy('id');

            return $employees->map(fn (User $employee) => array_merge($base, [
                'company_id' => $employee->getEffectiveCompanyId() !== null ? (int) $employee->getEffectiveCompanyId() : ($valid['company_id'] ?? null),
                'branch_id' => $employee->branch_id !== null ? (int) $employee->branch_id : null,
                'division_id' => $employee->division_id !== null ? (int) $employee->division_id : null,
                'department_id' => $employee->department_id !== null ? (int) $employee->department_id : null,
                'section_unit_id' => $employee->section_unit_id !== null ? (int) $employee->section_unit_id : null,
                'employee_id' => (int) $employee->id,
                'regions' => null,
            ]))->values()->all();
        }

        return [$base];
    }

    /**
     * Validate that coverage IDs exist in the database.
     */
    private function validateCoverageIds(string $coverageType, array $coverageIds): void
    {
        if (empty($coverageIds)) {
            abort(response()->json(['message' => 'Coverage IDs cannot be empty'], 422));
        }

        $count = match ($coverageType) {
            'company' => Company::query()->whereIn('id', $coverageIds)->count(),
            'branches' => Branch::query()->whereIn('id', $coverageIds)->count(),
            'divisions' => Division::query()->whereIn('id', $coverageIds)->count(),
            'departments' => Department::query()->whereIn('id', $coverageIds)->count(),
            'section_units' => SectionUnit::query()->whereIn('id', $coverageIds)->count(),
            'employees' => User::query()->whereIn('id', $coverageIds)->count(),
            default => 0,
        };

        if ($count !== count($coverageIds)) {
            $entityName = match ($coverageType) {
                'company' => 'companies',
                'branches' => 'branches',
                'divisions' => 'divisions',
                'departments' => 'departments',
                'section_units' => 'sections/units',
                'employees' => 'employees',
                default => 'entities',
            };
            abort(response()->json(['message' => "One or more selected {$entityName} were not found"], 422));
        }
    }

    /**
     * Clear every legacy single-target column when coverage changes, then fill
     * the authoritative path only when exactly one target was selected.
     *
     * @param  list<int>  $coverageIds
     * @return array<string, int|null>
     */
    private function coverageTargetColumns(string $coverageType, array $coverageIds): array
    {
        $columns = [
            'company_id' => null,
            'branch_id' => null,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'employee_id' => null,
        ];

        if (count($coverageIds) !== 1) {
            return $columns;
        }

        $id = $coverageIds[0];
        if ($coverageType === 'company') {
            $columns['company_id'] = $id;
        } elseif ($coverageType === 'branches') {
            $branch = Branch::query()->find($id, ['id', 'company_id']);
            $columns['company_id'] = $branch?->company_id;
            $columns['branch_id'] = $id;
        } elseif ($coverageType === 'divisions') {
            $division = Division::query()->find($id, ['id', 'company_id', 'branch_id']);
            $columns['company_id'] = $division?->company_id;
            $columns['branch_id'] = $division?->branch_id;
            $columns['division_id'] = $id;
        } elseif ($coverageType === 'departments') {
            $department = Department::query()->with('branch:id,company_id')->find($id);
            $columns['company_id'] = $department?->branch?->company_id;
            $columns['branch_id'] = $department?->branch_id;
            $columns['division_id'] = $department?->division_id;
            $columns['department_id'] = $id;
        } elseif ($coverageType === 'section_units') {
            $section = SectionUnit::query()->find($id, ['id', 'company_id', 'branch_id', 'division_id', 'department_id']);
            $columns['company_id'] = $section?->company_id;
            $columns['branch_id'] = $section?->branch_id;
            $columns['division_id'] = $section?->division_id;
            $columns['department_id'] = $section?->department_id;
            $columns['section_unit_id'] = $id;
        } elseif ($coverageType === 'employees') {
            $employee = User::query()->find($id);
            $columns['company_id'] = $employee?->getEffectiveCompanyId();
            $columns['branch_id'] = $employee?->branch_id;
            $columns['division_id'] = $employee?->division_id;
            $columns['department_id'] = $employee?->department_id;
            $columns['section_unit_id'] = $employee?->section_unit_id;
            $columns['employee_id'] = $id;
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayPayload(Holiday $holiday): array
    {
        $payload = [
            'id' => $holiday->id,
            'date' => $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date,
            'name' => $holiday->name,
            'type' => $holiday->type,
            'scope' => $holiday->scope,
            'scope_type' => $holiday->scope,
            'company_id' => $holiday->company_id,
            'branch_id' => $holiday->branch_id,
            'division_id' => $holiday->division_id,
            'department_id' => $holiday->department_id,
            'section_unit_id' => $holiday->section_unit_id,
            'employee_id' => $holiday->employee_id,
            'coverage_type' => $holiday->coverage_type,
            'coverage_ids' => $holiday->getCoverageIds(),
            'is_swap' => (bool) $holiday->is_swap,
            'original_date' => $holiday->original_date instanceof Carbon
                ? $holiday->original_date->format('Y-m-d')
                : ($holiday->original_date ? (string) $holiday->original_date : null),
            'description' => $holiday->description,
            'regions' => $holiday->regions,
            'is_recurring' => (bool) $holiday->is_recurring,
            'status' => $holiday->status ?? 'active',
            'source' => 'custom',
        ];

        return array_merge($payload, [
            'impact' => $this->holidayImpact($payload),
        ]);
    }

    /**
     * Live holiday impact based on the current active roster and posted payroll daily records.
     *
     * @param  array<string, mixed>  $holiday
     * @return array<string, mixed>
     */
    private function holidayImpact(array $holiday): array
    {
        $employeeQuery = $this->employeesForHolidayScope($holiday);
        $affectedEmployees = (clone $employeeQuery)->count();
        $date = (string) ($holiday['date'] ?? '');
        $type = strtolower((string) ($holiday['type'] ?? 'company'));
        $multiplier = $this->holidayPremiumMultiplier($type);

        $actualPremium = 0.0;
        $payrollRecordCount = 0;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $actualQuery = PayrollDailyRecord::query()
                ->whereDate('date', $date)
                ->whereIn('user_id', (clone $employeeQuery)->select('users.id'));

            $payrollRecordCount = (clone $actualQuery)->count();
            $actualPremium = round((float) (clone $actualQuery)->sum('holiday_premium_pay'), 2);
        }

        $estimatedPremium = 0.0;
        if ($multiplier > 0 && $affectedEmployees > 0) {
            $rateExpression = 'COALESCE(daily_rate, monthly_rate / 26, monthly_salary / 26, 0)';
            $estimatedPremium = round((float) (clone $employeeQuery)->sum(DB::raw($rateExpression)) * $multiplier, 2);
        }

        return [
            'affected_employees' => $affectedEmployees,
            'actual_premium_amount' => $actualPremium,
            'estimated_premium_amount' => $estimatedPremium,
            'premium_amount' => $actualPremium > 0 ? $actualPremium : $estimatedPremium,
            'premium_multiplier' => $multiplier,
            'payroll_record_count' => $payrollRecordCount,
            'is_actual' => $actualPremium > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $holiday
     */
    private function employeesForHolidayScope(array $holiday): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query()->activeRoster();
        $scope = strtolower((string) ($holiday['scope'] ?? 'nationwide'));
        $companyId = isset($holiday['company_id']) ? (int) $holiday['company_id'] : null;
        $branchId = isset($holiday['branch_id']) ? (int) $holiday['branch_id'] : null;
        $divisionId = isset($holiday['division_id']) ? (int) $holiday['division_id'] : null;
        $departmentId = isset($holiday['department_id']) ? (int) $holiday['department_id'] : null;
        $sectionUnitId = isset($holiday['section_unit_id']) ? (int) $holiday['section_unit_id'] : null;
        $employeeId = isset($holiday['employee_id']) ? (int) $holiday['employee_id'] : null;

        // Fast SQL paths for the common admin scopes; fall back to resolver only when needed.
        return match ($scope) {
            'nationwide', 'regional', '' => $query,
            'company' => $companyId > 0
                ? $query->where('company_id', $companyId)
                : $query->whereRaw('1 = 0'),
            'branch' => $branchId > 0
                ? $query->where('branch_id', $branchId)
                : $query->whereRaw('1 = 0'),
            'division' => $divisionId > 0
                ? $query->where('division_id', $divisionId)
                : $query->whereRaw('1 = 0'),
            'department' => $departmentId > 0
                ? $query->where('department_id', $departmentId)
                : $query->whereRaw('1 = 0'),
            'section', 'section_unit' => $sectionUnitId > 0
                ? $query->where('section_unit_id', $sectionUnitId)
                : $query->whereRaw('1 = 0'),
            'employee' => $employeeId > 0
                ? $query->where('id', $employeeId)
                : $query->whereRaw('1 = 0'),
            default => $this->employeesForHolidayScopeViaResolver($query, $holiday),
        };
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\User>  $query
     * @param  array<string, mixed>  $holiday
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
     */
    private function employeesForHolidayScopeViaResolver(\Illuminate\Database\Eloquent\Builder $query, array $holiday): \Illuminate\Database\Eloquent\Builder
    {
        $date = Carbon::parse((string) ($holiday['date'] ?? now()->toDateString()));
        $employeeIds = (clone $query)->get(['id', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'])
            ->filter(fn (User $employee): bool => $this->holidayScopeResolver->appliesRowToEmployee($holiday, $employee, $date))
            ->pluck('id')
            ->all();

        return $query->whereIn('id', $employeeIds !== [] ? $employeeIds : [0]);
    }

    private function holidayPremiumMultiplier(string $type): float
    {
        return match ($type) {
            'regular' => 2.0,
            'special', 'special_non_working' => 1.3,
            default => 0.0,
        };
    }
}
