<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\PayrollDailyRecord;
use App\Models\User;
use App\Services\HolidayCalendarService;
use App\Services\HolidayService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\PhPayrollReference;
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
        private readonly Holiday $holiday,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    /**
     * Get yearly holidays, preserving multiple scoped holidays on the same date.
     */
    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $year = max(2020, min(2030, $year));

        $holidays = $this->holidayCalendar->holidaysForYear($year);

        $holidays = array_map(function (array $row) {
            $type = strtolower((string) ($row['type'] ?? 'special'));

            return array_merge($row, [
                'payroll_hints' => PhPayrollReference::hintsForHolidayType($type),
                'impact' => $this->holidayImpact($row),
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

    public function store(Request $request): JsonResponse
    {
        $valid = $this->validateHolidayPayload($request);
        $payloads = $this->payloadsForWrite($valid);

        foreach ($payloads as $payload) {
            if ($this->holidayExistsForScope($payload)) {
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
            $holidays[] = $this->holiday->newQuery()->create($payload);
        }
        $this->holidayCalendar->flushMergedYearCaches();

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
        $this->holidayCalendar->flushMergedYearCaches();

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
        if ($this->holidayExistsForScope($candidate, $id)) {
            return response()->json(['message' => 'A holiday already exists on the swap date for this scope'], 422);
        }

        try {
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString(), $valid['date']], $targets);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday->update(['date' => $valid['date']]);
        $holiday->refresh();
        $this->holidayCalendar->flushMergedYearCaches();

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
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'scope' => ['required', Rule::in(['nationwide', 'regional', 'company', 'branch', 'department', 'employee'])],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
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
        ]), $valid['new_date']);

        if ($this->holidayExistsForScope($new)) {
            return response()->json(['message' => 'A holiday already exists on the swap date for this scope'], 422);
        }

        try {
            $this->assertHolidayDatesMutable([$old['date']], $old);
            $this->assertHolidayDatesMutable([$new['date']], $new);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->holiday->newQuery()->firstOrCreate(
            [
                'date' => $old['date'],
                'scope' => $old['scope'],
                'company_id' => $old['company_id'] ?? null,
                'branch_id' => $old['branch_id'] ?? null,
                'department_id' => $old['department_id'] ?? null,
                'employee_id' => $old['employee_id'] ?? null,
            ],
            $this->payloadForWrite($old)
        );
        $holiday = $this->holiday->newQuery()->create($this->payloadForWrite($new));
        $this->holidayCalendar->flushMergedYearCaches();

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
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'coverage_type' => ['required', Rule::in(['company', 'branches', 'departments', 'employees'])],
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
            'departments' => 'department',
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
            'department_id' => null,
            'employee_id' => null,
        ];

        if ($valid['coverage_type'] === 'company' && count($coverageIds) === 1) {
            $payload['company_id'] = $coverageIds[0];
        } elseif ($valid['coverage_type'] === 'branches' && count($coverageIds) === 1) {
            $branch = Branch::query()->find($coverageIds[0], ['id', 'company_id']);
            $payload['branch_id'] = $coverageIds[0];
            $payload['company_id'] = $branch?->company_id;
        } elseif ($valid['coverage_type'] === 'departments' && count($coverageIds) === 1) {
            $dept = Department::query()->with('branch:id,company_id')->find($coverageIds[0]);
            $payload['department_id'] = $coverageIds[0];
            $payload['branch_id'] = $dept?->branch_id;
            $payload['company_id'] = $dept?->branch?->company_id;
        } elseif ($valid['coverage_type'] === 'employees' && count($coverageIds) === 1) {
            $user = User::query()->find($coverageIds[0]);
            $payload['employee_id'] = $coverageIds[0];
            $payload['company_id'] = $user?->getEffectiveCompanyId();
            $payload['branch_id'] = $user?->branch_id;
            $payload['department_id'] = $user?->department_id;
        }

        $holiday = $this->holiday->newQuery()->create($payload);
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($valid['date']);

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

        $valid = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'original_date' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['sometimes', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'coverage_type' => ['sometimes', Rule::in(['company', 'branches', 'departments', 'employees'])],
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

        if (isset($valid['coverage_type']) && isset($valid['coverage_ids'])) {
            $coverageIds = array_values(array_unique(array_filter(
                array_map('intval', $valid['coverage_ids'])
            )));
            $this->validateCoverageIds($valid['coverage_type'], $coverageIds);

            $updateData['coverage_type'] = $valid['coverage_type'];
            $updateData['coverage_ids'] = $coverageIds;
            $updateData['scope'] = match ($valid['coverage_type']) {
                'company' => 'company',
                'branches' => 'branch',
                'departments' => 'department',
                'employees' => 'employee',
            };
        }

        $holiday->update($updateData);
        $holiday->refresh();

        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate(
            $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date
        );

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
            'affected_employees' => $this->holidayService->countAffectedEmployees($holiday),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);

        try {
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString()], $this->scopeTargetsFromHoliday($holiday));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $dateKey = $holiday->date instanceof Carbon ? $holiday->date->format('Y-m-d') : (string) $holiday->date;
        $holiday->delete();
        $this->holidayCalendar->flushMergedYearCaches();
        $this->holidayService->flushCoverageForDate($dateKey);

        return response()->json(['message' => 'Holiday deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateHolidayPayload(Request $request): array
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(['regular', 'special', 'special_non_working', 'special_working', 'company'])],
            'scope' => ['required', Rule::in(['nationwide', 'regional', 'company', 'branch', 'department', 'employee'])],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'employee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'company_ids' => ['nullable', 'array', 'max:100'],
            'company_ids.*' => ['integer', Rule::exists('companies', 'id')],
            'branch_ids' => ['nullable', 'array', 'max:200'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')],
            'department_ids' => ['nullable', 'array', 'max:300'],
            'department_ids.*' => ['integer', Rule::exists('departments', 'id')],
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
        $valid['department_ids'] = $this->normalizedIdList($valid['department_ids'] ?? null, $valid['department_id'] ?? null);
        $valid['employee_ids'] = $this->normalizedIdList($valid['employee_ids'] ?? null, $valid['employee_id'] ?? null);

        $scope = (string) ($valid['scope'] ?? 'nationwide');
        if ($scope === 'regional' && empty($valid['regions'])) {
            abort(response()->json(['message' => 'Select at least one region for a regional holiday'], 422));
        }
        if (in_array($scope, ['company', 'branch', 'department', 'employee'], true) && empty($valid['company_ids'])) {
            abort(response()->json(['message' => 'Select at least one company for this holiday scope'], 422));
        }
        if ($scope === 'branch' && empty($valid['branch_ids'])) {
            abort(response()->json(['message' => 'Select at least one branch for this holiday scope'], 422));
        }
        if ($scope === 'department' && empty($valid['department_ids'])) {
            abort(response()->json(['message' => 'Select at least one department for this holiday scope'], 422));
        }
        if ($scope === 'employee' && empty($valid['employee_ids'])) {
            abort(response()->json(['message' => 'Select at least one employee for this holiday scope'], 422));
        }

        $this->validateScopeHierarchy($valid);

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

        if (in_array($scope, ['company', 'branch', 'department', 'employee'], true)) {
            $valid['company_id'] = $valid['company_ids'][0] ?? null;
            $valid['branch_id'] = $valid['branch_ids'][0] ?? null;
            $valid['department_id'] = $valid['department_ids'][0] ?? null;
            $valid['employee_id'] = $valid['employee_ids'][0] ?? null;
        }

        return $valid;
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
        $departmentIds = $this->normalizedIdList($valid['department_ids'] ?? null, $valid['department_id'] ?? null);
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

        if (! empty($departmentIds)) {
            $departments = Department::query()->with('branch:id,company_id')->whereIn('id', $departmentIds)->get();
            if ($departments->count() !== count($departmentIds)) {
                abort(response()->json(['message' => 'One or more selected departments were not found'], 422));
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
        }
    }

    /**
     * @param  array<string, mixed>  $valid
     */
    private function holidayExistsForScope(array $valid, ?int $ignoreId = null): bool
    {
        $query = $this->holiday->newQuery()
            ->where('date', $valid['date'])
            ->where('scope', $valid['scope'] ?? 'nationwide')
            ->where('company_id', $valid['company_id'] ?? null)
            ->where('branch_id', $valid['branch_id'] ?? null)
            ->where('department_id', $valid['department_id'] ?? null)
            ->where('employee_id', $valid['employee_id'] ?? null);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }

    /**
     * @param  mixed  $ids
     * @param  mixed  $fallback
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
            'department_id' => $holiday->department_id !== null ? (int) $holiday->department_id : null,
            'employee_id' => $holiday->employee_id !== null ? (int) $holiday->employee_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function payloadForWrite(array $valid): array
    {
        return [
            'name' => $valid['name'],
            'date' => $valid['date'],
            'type' => $valid['type'],
            'scope' => $valid['scope'],
            'company_id' => $valid['company_id'] ?? null,
            'branch_id' => $valid['branch_id'] ?? null,
            'department_id' => $valid['department_id'] ?? null,
            'employee_id' => $valid['employee_id'] ?? null,
            'description' => $valid['description'] ?? null,
            'regions' => ($valid['scope'] === 'regional') ? array_values($valid['regions'] ?? []) : null,
            'is_recurring' => (bool) ($valid['is_recurring'] ?? false),
            'status' => $valid['status'],
        ];
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
                'department_id' => null,
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
                'department_id' => null,
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
                'department_id' => (int) $department->id,
                'employee_id' => null,
                'regions' => null,
            ]))->values()->all();
        }

        if ($scope === 'employee') {
            $employees = User::query()
                ->whereIn('id', $this->normalizedIdList($valid['employee_ids'] ?? null, $valid['employee_id'] ?? null))
                ->get()
                ->keyBy('id');

            return $employees->map(fn (User $employee) => array_merge($base, [
                'company_id' => $employee->getEffectiveCompanyId() !== null ? (int) $employee->getEffectiveCompanyId() : ($valid['company_id'] ?? null),
                'branch_id' => $employee->branch_id !== null ? (int) $employee->branch_id : null,
                'department_id' => $employee->department_id !== null ? (int) $employee->department_id : null,
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
            'departments' => Department::query()->whereIn('id', $coverageIds)->count(),
            'employees' => User::query()->whereIn('id', $coverageIds)->count(),
            default => 0,
        };

        if ($count !== count($coverageIds)) {
            $entityName = match ($coverageType) {
                'company' => 'companies',
                'branches' => 'branches',
                'departments' => 'departments',
                'employees' => 'employees',
                default => 'entities',
            };
            abort(response()->json(['message' => "One or more selected {$entityName} were not found"], 422));
        }
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
            'company_id' => $holiday->company_id,
            'branch_id' => $holiday->branch_id,
            'department_id' => $holiday->department_id,
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
        $query = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);

        $coverageType = $holiday['coverage_type'] ?? null;
        $coverageIds = $holiday['coverage_ids'] ?? [];
        if ($coverageType !== null && is_array($coverageIds) && ! empty($coverageIds)) {
            $coverageIds = array_map('intval', $coverageIds);

            return match ($coverageType) {
                'company' => $query->where(function ($q) use ($coverageIds) {
                    $q->whereIn('company_id', $coverageIds)
                        ->orWhereHas('companyHeadships', fn ($sub) => $sub->whereIn('id', $coverageIds))
                        ->orWhereHas('branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds))
                        ->orWhereHas('departmentRelation.branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds));
                }),
                'branches' => $query->where(function ($q) use ($coverageIds) {
                    $q->whereIn('branch_id', $coverageIds)
                        ->orWhereHas('departmentRelation', fn ($sub) => $sub->whereIn('branch_id', $coverageIds));
                }),
                'departments' => $query->whereIn('department_id', $coverageIds),
                'employees' => $query->whereIn('id', $coverageIds),
                default => $query,
            };
        }

        $scope = (string) ($holiday['scope'] ?? 'nationwide');

        if ($scope === 'employee' && ! empty($holiday['employee_id'])) {
            return $query->where('id', (int) $holiday['employee_id']);
        }

        if ($scope === 'department' && ! empty($holiday['department_id'])) {
            return $query->where('department_id', (int) $holiday['department_id']);
        }

        if ($scope === 'branch' && ! empty($holiday['branch_id'])) {
            $branchId = (int) $holiday['branch_id'];

            return $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereHas('departmentRelation', fn ($department) => $department->where('branch_id', $branchId));
            });
        }

        if ($scope === 'company' && ! empty($holiday['company_id'])) {
            $companyId = (int) $holiday['company_id'];

            return $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('companyHeadships', fn ($company) => $company->where('id', $companyId))
                    ->orWhereHas('branch', fn ($branch) => $branch->where('company_id', $companyId))
                    ->orWhereHas('departmentRelation.branch', fn ($branch) => $branch->where('company_id', $companyId));
            });
        }

        return $query;
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
