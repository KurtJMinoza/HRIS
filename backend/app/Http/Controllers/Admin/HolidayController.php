<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
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

        if ($this->holidayExistsForScope($valid)) {
            return response()->json(['message' => 'A holiday already exists on this date for the selected scope'], 422);
        }

        try {
            $this->assertHolidayDatesMutable([$valid['date']], $valid);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday = $this->holiday->newQuery()->create($this->payloadForWrite($valid));
        $this->holidayCalendar->flushMergedYearCaches();

        return response()->json([
            'holiday' => $this->holidayPayload($holiday),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);
        $valid = $this->validateHolidayPayload($request);

        if ($this->holidayExistsForScope($valid, $id)) {
            return response()->json(['message' => 'A holiday already exists on this date for the selected scope'], 422);
        }

        try {
            $oldTargets = $this->scopeTargetsFromHoliday($holiday);
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString()], $oldTargets);
            $this->assertHolidayDatesMutable([$valid['date']], $valid);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday->update($this->payloadForWrite($valid));
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

    public function destroy(int $id): JsonResponse
    {
        $holiday = $this->holiday->newQuery()->findOrFail($id);

        try {
            $this->assertHolidayDatesMutable([$holiday->date?->toDateString()], $this->scopeTargetsFromHoliday($holiday));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $holiday->delete();
        $this->holidayCalendar->flushMergedYearCaches();

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
            'description' => ['nullable', 'string', 'max:1000'],
            'regions' => ['nullable', 'array', 'max:50'],
            'regions.*' => ['string', 'max:120'],
            'is_recurring' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive', 'draft'])],
        ]);

        if (($valid['type'] ?? '') === 'special_non_working') {
            $valid['type'] = 'special';
        }

        $scope = (string) ($valid['scope'] ?? 'nationwide');
        if ($scope === 'regional' && empty($valid['regions'])) {
            abort(response()->json(['message' => 'Select at least one region for a regional holiday'], 422));
        }
        if (in_array($scope, ['company', 'branch', 'department', 'employee'], true) && empty($valid['company_id'])) {
            abort(response()->json(['message' => 'Select a company for this holiday scope'], 422));
        }
        if ($scope === 'branch' && empty($valid['branch_id'])) {
            abort(response()->json(['message' => 'Select a branch for this holiday scope'], 422));
        }
        if ($scope === 'department' && empty($valid['department_id'])) {
            abort(response()->json(['message' => 'Select a department for this holiday scope'], 422));
        }
        if ($scope === 'employee' && empty($valid['employee_id'])) {
            abort(response()->json(['message' => 'Select an employee for this holiday scope'], 422));
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
        $companyId = ! empty($valid['company_id']) ? (int) $valid['company_id'] : null;
        $branchId = ! empty($valid['branch_id']) ? (int) $valid['branch_id'] : null;
        $departmentId = ! empty($valid['department_id']) ? (int) $valid['department_id'] : null;
        $employeeId = ! empty($valid['employee_id']) ? (int) $valid['employee_id'] : null;

        if ($companyId !== null && $branchId !== null) {
            $branchOk = Branch::query()->whereKey($branchId)->where('company_id', $companyId)->exists();
            if (! $branchOk) {
                abort(response()->json(['message' => 'Selected branch does not belong to the selected company'], 422));
            }
        }

        if ($departmentId !== null) {
            $department = Department::query()->with('branch:id,company_id')->find($departmentId);
            if (! $department) {
                abort(response()->json(['message' => 'Selected department was not found'], 422));
            }
            if ($branchId !== null && (int) $department->branch_id !== $branchId) {
                abort(response()->json(['message' => 'Selected department does not belong to the selected branch'], 422));
            }
            if ($companyId !== null && (int) ($department->branch?->company_id ?? 0) !== $companyId) {
                abort(response()->json(['message' => 'Selected department does not belong to the selected company'], 422));
            }
        }

        if ($employeeId !== null) {
            $employee = User::query()->find($employeeId);
            if (! $employee) {
                abort(response()->json(['message' => 'Selected employee was not found'], 422));
            }
            if ($companyId !== null && (int) ($employee->getEffectiveCompanyId() ?? 0) !== $companyId) {
                abort(response()->json(['message' => 'Selected employee does not belong to the selected company'], 422));
            }
            if ($branchId !== null && (int) ($employee->branch_id ?? 0) !== $branchId) {
                abort(response()->json(['message' => 'Selected employee does not belong to the selected branch'], 422));
            }
            if ($departmentId !== null && (int) ($employee->department_id ?? 0) !== $departmentId) {
                abort(response()->json(['message' => 'Selected employee does not belong to the selected department'], 422));
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
            'company_id' => $holiday->company_id,
            'branch_id' => $holiday->branch_id,
            'department_id' => $holiday->department_id,
            'employee_id' => $holiday->employee_id,
            'description' => $holiday->description,
            'regions' => $holiday->regions,
            'is_recurring' => (bool) $holiday->is_recurring,
            'status' => $holiday->status ?? 'active',
            'source' => 'custom',
        ];
    }
}
