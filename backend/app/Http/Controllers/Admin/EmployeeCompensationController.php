<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssertsEmployeeOrgScope;
use App\Http\Controllers\Controller;
use App\Jobs\ComputeCompensationSummaryJob;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use App\Support\EmployeeProfileCache;
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
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->orderBy('name')
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
            'components.*.metadata' => ['nullable', 'array'],
        ]);

        $employees = User::query()
            ->whereIn('id', $validated['employee_ids'])
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->get();

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

        $employee = User::query()->where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
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
            'metadata' => ['nullable', 'array'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim((string) $validated['code']));
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

        $employee = User::query()->where('id', $userId)->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)->firstOrFail();
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
        $assignment->delete();

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

        $payload = [
            'user_id' => $employee->id,
            'pay_component_id' => $master?->id,
            'structure_name' => $validated['structure_name'] ?? null,
            'name' => trim((string) ($componentPayload['name'] ?? $master?->name ?? '')),
            'code' => strtoupper(trim((string) ($componentPayload['code'] ?? $master?->code ?? ''))),
            'type' => strtolower((string) ($componentPayload['type'] ?? $master?->type ?? PayComponent::TYPE_EARNING)),
            'category' => $componentPayload['category'] ?? $master?->category,
            'calculation_type' => strtolower((string) ($componentPayload['calculation_type'] ?? $master?->calculation_type ?? PayComponent::CALC_FIXED)),
            'value' => (float) ($componentPayload['value'] ?? $componentPayload['default_value'] ?? $master?->default_value ?? 0),
            'hourly_rate' => isset($componentPayload['hourly_rate']) ? (float) $componentPayload['hourly_rate'] : null,
            'hours' => isset($componentPayload['hours']) ? (float) $componentPayload['hours'] : null,
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

        if ($existingAssignment) {
            $existingAssignment->fill($payload);
            $existingAssignment->save();

            return $existingAssignment->load('payComponent');
        }

        $assignment = EmployeeCompensationComponent::create($payload);

        return $assignment->load('payComponent');
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
