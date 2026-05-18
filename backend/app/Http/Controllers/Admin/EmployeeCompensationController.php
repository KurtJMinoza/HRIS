<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Jobs\ComputeCompensationSummaryJob;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Services\PayCycleService;
use App\Services\PayrollCalculatorService;
use App\Services\ScheduleRateService;
use App\Support\EmployeeProfileCache;
use App\Support\PayComponentSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EmployeeCompensationController extends Controller
{
    use AssertsEmployeeOrgScope;

    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly PayCycleService $payCycleService,
        private readonly ScheduleRateService $scheduleRateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->ensureCompensationTableExists()) {
            return $response;
        }

        $validated = $request->validate([
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $employeeIds = collect($validated['employee_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($employeeIds->isEmpty() && $request->filled('employee_id')) {
            $employeeIds = collect([(int) $request->input('employee_id')]);
        }

        if ($employeeIds->isEmpty()) {
            return response()->json(['employees' => []]);
        }

        $employees = User::query()
            ->whereIn('id', $employeeIds)
            ->roster()
            ->orderByLastName()
            ->get();

        $asOf = isset($validated['as_of_date']) ? Carbon::parse($validated['as_of_date'])->toDateString() : now()->toDateString();

        foreach ($employees as $employee) {
            $this->assertEmployeeOrgScope($request, $employee);
        }

        $queued = false;
        foreach ($employees as $employee) {
            try {
                ComputeCompensationSummaryJob::dispatch((int) $employee->id)->onQueue('default');
                $queued = true;
            } catch (\Throwable $e) {
                Log::warning('Failed to queue compensation summary warm job', [
                    'user_id' => $employee->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // UX: When the UI is requesting a single employee (Compensation Structure live preview),
        // compute on-demand so gross pay does not stay 0 while the queue warms the cache.
        // Bulk requests still default to "pending" to avoid expensive synchronous computation loops.
        $allowCompute = $employeeIds->count() === 1;

        return response()->json([
            'recalculation_queued' => $queued,
            'summary_mode' => $allowCompute ? 'computed' : 'pending',
            'employees' => $employees->map(function (User $employee) use ($asOf, $allowCompute) {
                $summary = $this->calculator->buildEmployeeCompensationSummary($employee, [
                    'as_of_date' => $asOf,
                    'proration_factor' => 1,
                    'include_deduction_schedule_catalog' => false,
                    'cache' => true,
                    'allow_compute' => $allowCompute,
                ]);

                return [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'employee_code' => $employee->employee_code,
                        'position' => $employee->position,
                        'department' => $employee->department,
                        'profile_image' => $employee->profile_image,
                        'profile_image_url' => $employee->profile_image_url,
                        'is_active' => (bool) $employee->is_active,
                        'active_status' => $employee->employment_active_status,
                        'is_deactivated' => $employee->isAccountDeactivated(),
                    ],
                    'summary' => $summary,
                ];
            })->values(),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        if ($response = $this->ensureCompensationTableExists()) {
            return $response;
        }

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'structure_name' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'components.*.name' => ['nullable', 'string', 'max:255'],
            'components.*.code' => ['nullable', 'string', 'max:100'],
            'components.*.type' => ['nullable', 'string', Rule::in(PayComponent::TYPES)],
            'components.*.category' => ['nullable', 'string', 'max:64'],
            'components.*.calculation_type' => ['nullable', 'string', Rule::in(PayComponent::CALCULATION_TYPES)],
            'components.*.value' => ['nullable', 'numeric'],
            'components.*.default_value' => ['nullable', 'numeric'],
            'components.*.hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'components.*.hours' => ['nullable', 'numeric', 'min:0'],
            'components.*.formula' => ['nullable', 'string'],
            'components.*.is_taxable' => ['nullable', 'boolean'],
            'components.*.contributes_sss' => ['nullable', 'boolean'],
            'components.*.contributes_philhealth' => ['nullable', 'boolean'],
            'components.*.contributes_pagibig' => ['nullable', 'boolean'],
            'components.*.is_proratable' => ['nullable', 'boolean'],
            'components.*.is_active' => ['nullable', 'boolean'],
            'components.*.is_custom' => ['nullable', 'boolean'],
            'components.*.schedule_override' => ['nullable', 'string', Rule::in(PayComponentSchedule::validationSlugs())],
            'components.*.metadata' => ['nullable', 'array'],
        ]);

        $employees = User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->activeRoster()
            ->get();

        if ($employees->count() !== count(array_unique($validated['employee_ids']))) {
            return response()->json([
                'message' => 'One or more selected employees are deactivated and cannot receive new compensation assignments.',
            ], 422);
        }

        foreach ($employees as $employee) {
            $this->assertEmployeeOrgScope($request, $employee);
        }

        $created = collect();
        foreach ($employees as $employee) {
            foreach ($validated['components'] as $componentPayload) {
                $created->push($this->storeAssignment($employee, $validated, $componentPayload));
            }
        }

        $queuedIds = $employees->pluck('id')->unique()->values();
        foreach ($queuedIds as $uid) {
            $this->refreshCompensationCaches((int) $uid, 'bulk assign');
        }

        return response()->json([
            'message' => 'Compensation components assigned successfully.',
            'count' => $created->count(),
            'recalculation_queued' => $queuedIds->isNotEmpty(),
        ], 201);
    }

    public function update(Request $request, $userId, $id): JsonResponse
    {
        if ($response = $this->ensureCompensationTableExists()) {
            return $response;
        }

        $userId = (int) $userId;
        $id = (int) $id;

        $employee = User::query()->activeRoster()->where('id', $userId)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $assignment = EmployeeCompensationComponent::query()
            ->where('user_id', $employee->id)
            ->whereKey($id)
            ->first();
        if (! $assignment) {
            return response()->json([
                'message' => 'This compensation assignment is no longer available. Refresh the page to see current data.',
            ], 404);
        }

        $validated = $request->validate([
            'structure_name' => ['nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(PayComponent::TYPES)],
            'category' => ['nullable', 'string', 'max:64'],
            'calculation_type' => ['sometimes', 'string', Rule::in(PayComponent::CALCULATION_TYPES)],
            'value' => ['nullable', 'numeric'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'formula' => ['nullable', 'string'],
            'is_taxable' => ['sometimes', 'boolean'],
            'contributes_sss' => ['sometimes', 'boolean'],
            'contributes_philhealth' => ['sometimes', 'boolean'],
            'contributes_pagibig' => ['sometimes', 'boolean'],
            'is_proratable' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
            'schedule_override' => ['nullable', 'string', Rule::in(PayComponentSchedule::validationSlugs())],
            'metadata' => ['nullable', 'array'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim((string) $validated['code']));
        }

        if (\array_key_exists('schedule_override', $validated)) {
            $validated['schedule_override'] = PayComponentSchedule::normalizeForStorage(
                isset($validated['schedule_override']) && $validated['schedule_override'] !== null
                    ? (string) $validated['schedule_override']
                    : null
            );
        }

        $metadata = is_array($assignment->metadata) ? $assignment->metadata : [];
        $currentSource = $metadata['assignment_source'] ?? null;
        if ($currentSource === 'auto_apply_all' || $currentSource === null) {
            $metadata['assignment_source'] = 'manual_override';
            $metadata['overridden_at'] = now()->toIso8601String();
            $metadata['auto_applied'] = false;
            $validated['metadata'] = array_merge($metadata, $validated['metadata'] ?? []);
        } elseif ($currentSource === 'manual') {
            $metadata['assignment_source'] = 'manual_override';
            $metadata['overridden_at'] = now()->toIso8601String();
            $validated['metadata'] = array_merge($metadata, $validated['metadata'] ?? []);
        }

        $assignment->fill($validated);
        $assignment->save();
        $this->syncEmployeeSalaryProfileFromBasicSalaryAssignment($employee, $assignment);

        if (\array_key_exists('schedule_override', $validated)) {
            Log::debug('employee_compensation.schedule_persist', [
                'employee_id' => (int) $employee->id,
                'pay_component_id' => $assignment->pay_component_id,
                'assignment_id' => $assignment->id,
                'request_schedule_override' => $validated['schedule_override'],
                'persisted_schedule_override' => $assignment->schedule_override,
                'context' => 'assignment_update',
            ]);
        }

        $this->refreshCompensationCaches((int) $employee->id, 'assignment update');

        return response()->json([
            'message' => 'Employee compensation updated successfully.',
            'recalculation_queued' => true,
            'assignment' => $this->assignmentResponse($assignment->fresh(['payComponent'])),
        ]);
    }

    public function destroy(Request $request, $userId, $id): JsonResponse
    {
        if ($response = $this->ensureCompensationTableExists()) {
            return $response;
        }

        $userId = (int) $userId;
        $id = (int) $id;

        if ($id <= 0) {
            throw (new ModelNotFoundException)->setModel(EmployeeCompensationComponent::class, [$id]);
        }

        $employee = User::query()->activeRoster()->where('id', $userId)->firstOrFail();
        $this->assertEmployeeOrgScope($request, $employee);

        $assignment = EmployeeCompensationComponent::query()
            ->where('user_id', $employee->id)
            ->whereKey($id)
            ->first();
        if (! $assignment) {
            return response()->json([
                'message' => 'This compensation assignment is no longer available. Refresh the page to see current data.',
            ], 404);
        }

        $uid = (int) $employee->id;
        $isBasicSalary = $this->isBasicSalaryAssignment($assignment);
        $assignment->delete();
        if ($isBasicSalary) {
            $this->clearEmployeeSalaryProfile($employee);
        }

        $this->refreshCompensationCaches($uid, 'assignment delete');

        return response()->json([
            'message' => 'Employee compensation removed successfully.',
            'recalculation_queued' => true,
        ]);
    }

    private function storeAssignment(User $employee, array $validated, array $componentPayload): EmployeeCompensationComponent
    {
        $master = null;
        if (! empty($componentPayload['pay_component_id'])) {
            $master = PayComponent::query()->findOrFail((int) $componentPayload['pay_component_id']);
        }

        $existingAssignment = $master
            ? EmployeeCompensationComponent::query()
                ->where('user_id', $employee->id)
                ->where('pay_component_id', $master->id)
                ->first()
            : null;

        $metadata = (array) ($existingAssignment?->metadata ?? $componentPayload['metadata'] ?? []);
        $existingSource = $metadata['assignment_source'] ?? null;
        $metadata['assignment_source'] = $existingSource === 'auto_apply_all' ? 'manual_override' : ($existingSource ?: 'manual');
        if ($metadata['assignment_source'] === 'manual_override') {
            $metadata['overridden_at'] = now()->toIso8601String();
        }
        $metadata['auto_applied'] = false;

        $masterMeta = is_array($master?->metadata ?? null) ? $master->metadata : [];
        $calc = strtolower((string) ($componentPayload['calculation_type'] ?? $master?->calculation_type ?? PayComponent::CALC_FIXED));

        $resolvedValue = isset($componentPayload['value'])
            ? (float) $componentPayload['value']
            : (isset($componentPayload['default_value'])
                ? (float) $componentPayload['default_value']
                : (float) ($master?->default_value ?? 0));

        $resolvedHourlyRate = null;
        $resolvedHours = null;
        if (isset($componentPayload['hourly_rate']) && $componentPayload['hourly_rate'] !== null && $componentPayload['hourly_rate'] !== '') {
            $resolvedHourlyRate = (float) $componentPayload['hourly_rate'];
        } elseif ($calc === PayComponent::CALC_HOURLY) {
            $resolvedHourlyRate = isset($masterMeta['default_hourly_rate'])
                ? (float) $masterMeta['default_hourly_rate']
                : $resolvedValue;
        }
        if (isset($componentPayload['hours']) && $componentPayload['hours'] !== null && $componentPayload['hours'] !== '') {
            $resolvedHours = (float) $componentPayload['hours'];
        } elseif ($calc === PayComponent::CALC_HOURLY && isset($masterMeta['default_hours'])) {
            $resolvedHours = (float) $masterMeta['default_hours'];
        } elseif ($calc === PayComponent::CALC_DAILY && isset($masterMeta['default_days'])) {
            $resolvedHours = (float) $masterMeta['default_days'];
        }

        $scheduleIncoming = \array_key_exists('schedule_override', $componentPayload);
        $payload = [
            'user_id' => $employee->id,
            'pay_component_id' => $master?->id,
            'structure_name' => $validated['structure_name'] ?? null,
            'name' => trim((string) ($componentPayload['name'] ?? $master?->name ?? '')),
            'code' => strtoupper(trim((string) ($componentPayload['code'] ?? $master?->code ?? ''))),
            'type' => strtolower((string) ($componentPayload['type'] ?? $master?->type ?? PayComponent::TYPE_EARNING)),
            'category' => $componentPayload['category'] ?? $master?->category,
            'calculation_type' => $calc,
            'value' => $resolvedValue,
            'hourly_rate' => $resolvedHourlyRate,
            'hours' => $resolvedHours,
            'formula' => $componentPayload['formula'] ?? $master?->formula,
            'is_taxable' => (bool) ($componentPayload['is_taxable'] ?? $master?->is_taxable ?? true),
            'contributes_sss' => (bool) ($componentPayload['contributes_sss'] ?? $master?->contributes_sss ?? false),
            'contributes_philhealth' => (bool) ($componentPayload['contributes_philhealth'] ?? $master?->contributes_philhealth ?? false),
            'contributes_pagibig' => (bool) ($componentPayload['contributes_pagibig'] ?? $master?->contributes_pagibig ?? false),
            'is_proratable' => (bool) ($componentPayload['is_proratable'] ?? $master?->is_proratable ?? false),
            'is_custom' => (bool) ($componentPayload['is_custom'] ?? $master === null),
            'effective_from' => $validated['effective_from'] ?? $master?->effective_from?->toDateString(),
            'effective_to' => $validated['effective_to'] ?? $master?->effective_to?->toDateString(),
            'is_active' => (bool) ($componentPayload['is_active'] ?? true),
            'metadata' => $metadata,
        ];
        if ($scheduleIncoming) {
            $payload['schedule_override'] = PayComponentSchedule::normalizeForStorage(
                isset($componentPayload['schedule_override']) && $componentPayload['schedule_override'] !== null && $componentPayload['schedule_override'] !== ''
                    ? (string) $componentPayload['schedule_override']
                    : null
            );
        } elseif ($existingAssignment) {
            $payload['schedule_override'] = PayComponentSchedule::normalizeForStorage(
                \is_string($existingAssignment->schedule_override) ? $existingAssignment->schedule_override : null
            );
        } else {
            $payload['schedule_override'] = null;
        }

        if ($existingAssignment) {
            $existingAssignment->fill($payload);
            $existingAssignment->save();
            $this->syncEmployeeSalaryProfileFromBasicSalaryAssignment($employee, $existingAssignment);
            Log::debug('employee_compensation.schedule_persist', [
                'employee_id' => (int) $employee->id,
                'pay_component_id' => $payload['pay_component_id'] ?? null,
                'assignment_id' => $existingAssignment->id,
                'request_schedule_override' => $componentPayload['schedule_override'] ?? null,
                'persisted_schedule_override' => $existingAssignment->schedule_override,
            ]);

            return $existingAssignment->load('payComponent');
        }

        $assignment = EmployeeCompensationComponent::create($payload);
        $this->syncEmployeeSalaryProfileFromBasicSalaryAssignment($employee, $assignment);
        Log::debug('employee_compensation.schedule_persist', [
            'employee_id' => (int) $employee->id,
            'pay_component_id' => $payload['pay_component_id'] ?? null,
            'assignment_id' => $assignment->id,
            'request_schedule_override' => $componentPayload['schedule_override'] ?? null,
            'persisted_schedule_override' => $assignment->schedule_override,
        ]);

        return $assignment->load('payComponent');
    }

    private function syncEmployeeSalaryProfileFromBasicSalaryAssignment(User $employee, EmployeeCompensationComponent $assignment): void
    {
        if (! $this->isBasicSalaryAssignment($assignment)) {
            return;
        }

        $monthlySalary = round(max(0.0, (float) ($assignment->value ?? 0)), 2);
        if ($monthlySalary <= 0) {
            $this->clearEmployeeSalaryProfile($employee);

            return;
        }

        $computedRates = $this->scheduleRateService->calculateDailyAndHourlyRate((int) $employee->id, $monthlySalary);
        $dailyRate = $computedRates['daily_rate'] ?? null;
        $hourlyRate = $computedRates['hourly_rate'] ?? null;
        if ($dailyRate === null || $hourlyRate === null) {
            $dailyRate ??= round($monthlySalary / 22, 2);
            $hourlyRate ??= round($dailyRate / 8, 2);
        }
        $profilePayload = [
            'monthly_salary' => $monthlySalary,
            'monthly_rate' => $monthlySalary,
            'daily_rate' => $dailyRate,
            'hourly_rate' => $hourlyRate,
            'salary_effectivity_date' => $employee->salary_effectivity_date?->toDateString() ?: now()->toDateString(),
        ];

        if (! $employee->pay_cycle_id) {
            $profilePayload['pay_cycle_id'] = $this->payCycleService->resolveForUser($employee)?->id;
        }

        $employee->forceFill($profilePayload)->save();

        $metadata = is_array($assignment->metadata ?? null) ? $assignment->metadata : [];
        $metadata['source'] = 'employee_compensation_basic_salary';
        $metadata['synced_to_salary_profile_at'] = now()->toIso8601String();
        $assignment->forceFill(['metadata' => $metadata])->save();
    }

    private function clearEmployeeSalaryProfile(User $employee): void
    {
        $employee->forceFill([
            'monthly_salary' => null,
            'monthly_rate' => null,
            'daily_rate' => null,
            'hourly_rate' => null,
            'salary_effectivity_date' => null,
        ])->save();
    }

    private function isBasicSalaryAssignment(EmployeeCompensationComponent $assignment): bool
    {
        return strtoupper(trim((string) ($assignment->code ?? ''))) === 'BASIC_SALARY'
            || strcasecmp(trim((string) ($assignment->name ?? '')), 'Basic Salary') === 0
            || strcasecmp(trim((string) ($assignment->category ?? '')), 'Basic Salary') === 0
            || strtoupper(trim((string) ($assignment->payComponent?->code ?? ''))) === 'BASIC_SALARY';
    }

    private function assignmentResponse(EmployeeCompensationComponent $assignment): array
    {
        return [
            'id' => $assignment->id,
            'user_id' => $assignment->user_id,
            'pay_component_id' => $assignment->pay_component_id,
            'structure_name' => $assignment->structure_name,
            'name' => $assignment->name,
            'code' => $assignment->code,
            'type' => $assignment->type,
            'category' => $assignment->category,
            'calculation_type' => $assignment->calculation_type,
            'value' => (float) $assignment->value,
            'hourly_rate' => $assignment->hourly_rate !== null ? (float) $assignment->hourly_rate : null,
            'hours' => $assignment->hours !== null ? (float) $assignment->hours : null,
            'formula' => $assignment->formula,
            'is_taxable' => (bool) $assignment->is_taxable,
            'contributes_sss' => (bool) $assignment->contributes_sss,
            'contributes_philhealth' => (bool) $assignment->contributes_philhealth,
            'contributes_pagibig' => (bool) $assignment->contributes_pagibig,
            'is_proratable' => (bool) $assignment->is_proratable,
            'is_custom' => (bool) $assignment->is_custom,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'is_active' => (bool) $assignment->is_active,
            'schedule_override' => PayComponentSchedule::normalizeForStorage(
                \is_string($assignment->schedule_override) ? $assignment->schedule_override : null
            ),
            'metadata' => $assignment->metadata,
            'pay_component' => $assignment->payComponent ? [
                'id' => $assignment->payComponent->id,
                'name' => $assignment->payComponent->name,
                'code' => $assignment->payComponent->code,
            ] : null,
        ];
    }

    private function ensureCompensationTableExists(): ?JsonResponse
    {
        if (Schema::hasTable('employee_compensation_components')) {
            return null;
        }

        return response()->json([
            'message' => 'Employee compensation is not available yet because the database migration for employee_compensation_components has not been run.',
        ], 409);
    }

    private function refreshCompensationCaches(int $userId, string $reason): void
    {
        EmployeeProfileCache::invalidate($userId);
        $this->calculator->forgetCompensationSummaryCacheForUser($userId);

        try {
            ComputeCompensationSummaryJob::dispatch($userId)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to queue compensation summary after '.$reason, [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
