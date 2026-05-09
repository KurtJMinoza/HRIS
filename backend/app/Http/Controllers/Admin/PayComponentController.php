<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Services\PayComponentAssignmentService;
use App\Services\PayComponentService;
use App\Services\PayrollCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayComponentController extends Controller
{
    public function __construct(
        private readonly PayComponentAssignmentService $assignmentService,
        private readonly PayComponentService $payComponentService,
        private readonly PayrollCalculatorService $payrollCalculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hasCode = Schema::hasColumn('pay_components', 'code');
        $hasCategory = Schema::hasColumn('pay_components', 'category');
        $hasIsActive = Schema::hasColumn('pay_components', 'is_active');

        $query = PayComponent::query()->orderBy('type');
        if ($hasCategory) {
            $query->orderBy('category');
        }
        $query->orderBy('name');

        if ($request->boolean('all') !== true && $hasIsActive) {
            $query->where('is_active', true);
        }

        if ($request->filled('type')) {
            $query->where('type', strtolower((string) $request->input('type')));
        }
        if ($request->filled('component_type') && Schema::hasColumn('pay_components', 'component_type')) {
            $query->where('component_type', strtolower((string) $request->input('component_type')));
        }

        if ($request->filled('search')) {
            $like = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($inner) use ($like, $hasCategory, $hasCode) {
                $inner->where('name', 'like', $like);
                if ($hasCode) {
                    $inner->orWhere('code', 'like', $like);
                }
                if ($hasCategory) {
                    $inner->orWhere('category', 'like', $like);
                }
            });
        }

        return response()->json([
            'components' => $query->get()->map(fn (PayComponent $component) => $this->responseRow($component))->values(),
            'duplicate_warnings' => $this->buildPayComponentDuplicateWarnings(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $component = PayComponent::create($this->filterPayloadForExistingColumns($payload));
        $assignedCount = $this->assignmentService->syncForAllEmployees($component);

        return response()->json([
            'message' => $assignedCount > 0
                ? "Pay component created successfully and applied to {$assignedCount} employees."
                : 'Pay component created successfully.',
            'component' => $this->responseRow($component),
            'auto_applied_count' => $assignedCount,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $component = PayComponent::query()->findOrFail($id);
        $payload = $this->validatePayload($request, $component->id, true);
        if ($this->payComponentService->isSystemProtected($component)) {
            $payload = $this->payComponentService->stripProtectedMutations($component, $payload);
        }
        $component->fill($this->filterPayloadForExistingColumns($payload));
        $component->save();
        $assignedCount = $this->assignmentService->syncForAllEmployees($component);

        Log::info('pay_component.updated', [
            'pay_component_id' => $component->id,
            'code' => $component->code,
            'is_system_protected' => $this->payComponentService->isSystemProtected($component),
            'actor_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => $assignedCount > 0
                ? "Pay component updated successfully and synced to {$assignedCount} employees."
                : 'Pay component updated successfully.',
            'component' => $this->responseRow($component->fresh()),
            'auto_applied_count' => $assignedCount,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $component = PayComponent::query()->findOrFail($id);
        if (strtoupper(trim((string) ($component->code ?? ''))) === 'BASIC_SALARY') {
            return response()->json([
                'message' => 'The Basic Salary component is required for payroll and cannot be deleted. You can archive it to deactivate.',
            ], 422);
        }
        $activeCount = $this->countActiveAssignments($component);
        $forceUnassign = $request->boolean('force_unassign');

        if ($activeCount > 0 && ! $forceUnassign) {
            return response()->json([
                'message' => "This component is used by {$activeCount} active employee assignment(s). Deleting may affect payroll and payslips.",
                'active_assignment_count' => $activeCount,
                'requires_confirmation' => true,
            ], 409);
        }

        if ($activeCount > 0 && $forceUnassign) {
            EmployeeCompensationComponent::query()
                ->where('pay_component_id', $component->id)
                ->update(['is_active' => false]);
        }

        $this->forgetCompensationSummaryCacheForPayComponent($component->id);

        Log::info('pay_component.deleted', [
            'pay_component_id' => $component->id,
            'code' => $component->code,
            'had_active_assignments' => $activeCount,
            'force_unassign' => $forceUnassign,
            'actor_user_id' => $request->user()?->id,
        ]);

        $component->delete();

        return response()->json([
            'message' => $activeCount > 0
                ? "Pay component deleted and {$activeCount} assignment(s) were deactivated."
                : 'Pay component deleted successfully.',
        ]);
    }

    /**
     * Cascade deletes on pay_components do not fire {@see EmployeeCompensationComponent} model events — clear summary cache explicitly.
     */
    private function forgetCompensationSummaryCacheForPayComponent(int $payComponentId): void
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return;
        }
        $userIds = EmployeeCompensationComponent::query()
            ->where('pay_component_id', $payComponentId)
            ->pluck('user_id')
            ->unique()
            ->filter();
        foreach ($userIds as $uid) {
            $this->payrollCalculator->forgetCompensationSummaryCacheForUser((int) $uid);
        }
    }

    private function countActiveAssignments(PayComponent $component): int
    {
        if (! Schema::hasTable('employee_compensation_components')) {
            return 0;
        }

        return (int) EmployeeCompensationComponent::query()
            ->where('pay_component_id', $component->id)
            ->where('is_active', true)
            ->count();
    }

    private function validatePayload(Request $request, ?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $hasCode = Schema::hasColumn('pay_components', 'code');

        $rules = [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, 'string', Rule::in(PayComponent::TYPES)],
            'category' => ['nullable', 'string', 'max:64'],
            'calculation_type' => [$required, 'string', Rule::in(PayComponent::CALCULATION_TYPES)],
            'default_value' => ['nullable', 'numeric'],
            'formula' => ['nullable', 'string'],
            'is_taxable' => ['sometimes', 'boolean'],
            'contributes_sss' => ['sometimes', 'boolean'],
            'contributes_philhealth' => ['sometimes', 'boolean'],
            'contributes_pagibig' => ['sometimes', 'boolean'],
            'is_proratable' => ['sometimes', 'boolean'],
            'apply_to_all' => ['sometimes', 'boolean'],
            'component_type' => ['sometimes', 'string', Rule::in(PayComponent::COMPONENT_TYPES)],
            'is_system_protected' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'metadata.default_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'metadata.default_hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'metadata.default_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'metadata.default_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];

        if (Schema::hasColumn('pay_components', 'is_loan')) {
            $rules['is_loan'] = ['sometimes', 'boolean'];
            $rules['is_amortized'] = ['sometimes', 'boolean'];
        }
        if (Schema::hasColumn('pay_components', 'default_term_months')) {
            $rules['default_term_months'] = ['nullable', 'integer', 'min:1', 'max:600'];
        }

        if ($hasCode) {
            $rules['code'] = [
                $required,
                'string',
                'max:100',
                Rule::unique('pay_components', 'code')
                    ->where(fn ($q) => $q->whereNull('deleted_at'))
                    ->ignore($ignoreId),
            ];
        }

        $validated = $request->validate($rules);

        if ($hasCode && array_key_exists('code', $validated)) {
            $validated['code'] = strtoupper(trim((string) $validated['code']));
        } else {
            unset($validated['code']);
        }
        if (array_key_exists('type', $validated)) {
            $validated['type'] = strtolower((string) $validated['type']);
        }
        if (array_key_exists('calculation_type', $validated)) {
            $validated['calculation_type'] = strtolower((string) $validated['calculation_type']);
            if ($validated['calculation_type'] === PayComponent::CALC_FORMULA
                && ! $this->payComponentService->assertSafeFormula($validated['formula'] ?? null)) {
                throw ValidationException::withMessages([
                    'formula' => ['Unsafe formula. Only BASIC, GROSS, DEFAULT_VALUE, HOURS, HOURLY_RATE, DAILY_RATE and arithmetic operators are allowed.'],
                ]);
            }
        }
        if (array_key_exists('component_type', $validated)) {
            $validated['component_type'] = strtolower((string) $validated['component_type']);
        } elseif (! $partial) {
            $validated['component_type'] = PayComponent::COMPONENT_USER;
        }
        if (array_key_exists('category', $validated) && $validated['category'] !== null) {
            $validated['category'] = trim((string) $validated['category']);
        }
        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim((string) $validated['name']);
        }
        if (Schema::hasColumn('pay_components', 'is_loan')) {
            if (($validated['type'] ?? null) === PayComponent::TYPE_EARNING) {
                $validated['is_loan'] = false;
                $validated['is_amortized'] = false;
            }
        }
        if (Schema::hasColumn('pay_components', 'default_term_months')) {
            $t = $validated['type'] ?? null;
            $isLoan = $validated['is_loan'] ?? null;
            if (array_key_exists('type', $validated) && $t === PayComponent::TYPE_EARNING) {
                $validated['default_term_months'] = null;
            } elseif (array_key_exists('is_loan', $validated) && $isLoan === false) {
                $validated['default_term_months'] = null;
            }
        }
        // Auto-derive amortization from term months for loan components.
        if (Schema::hasColumn('pay_components', 'is_amortized')) {
            $isLoan = (bool) ($validated['is_loan'] ?? false);
            $term = $validated['default_term_months'] ?? null;
            if ($isLoan && is_numeric($term) && (int) $term > 0) {
                $validated['is_amortized'] = true;
            } else {
                $validated['is_amortized'] = false;
            }
        }
        if ($this->isBasicSalaryPayload($validated)) {
            $validated['apply_to_all'] = false;
            $validated['component_type'] = PayComponent::COMPONENT_SYSTEM;
            $validated['is_system_protected'] = true;
        }

        $typeForNameCheck = $validated['type'] ?? null;
        if ($partial && $ignoreId !== null && $typeForNameCheck === null) {
            $typeForNameCheck = PayComponent::query()->whereKey($ignoreId)->value('type');
        }
        if (array_key_exists('name', $validated)
            && strcasecmp(trim((string) $validated['name']), 'Basic Salary') === 0
            && $typeForNameCheck === PayComponent::TYPE_EARNING) {
            $dup = PayComponent::query()
                ->whereNull('deleted_at')
                ->where('type', PayComponent::TYPE_EARNING)
                ->whereRaw('lower(trim(name)) = ?', ['basic salary'])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', (int) $ignoreId))
                ->exists();
            if ($dup) {
                throw ValidationException::withMessages([
                    'name' => ['Only one earning component may be named "Basic Salary". Use the existing system component or pick a different name.'],
                ]);
            }
        }

        $validated['default_value'] = isset($validated['default_value']) ? (float) $validated['default_value'] : 0.0;

        return $validated;
    }

    private function responseRow(PayComponent $component): array
    {
        $hasCode = Schema::hasColumn('pay_components', 'code');

        return [
            'id' => $component->id,
            'name' => $component->name,
            'code' => $hasCode ? $component->code : null,
            'type' => $component->type,
            'category' => Schema::hasColumn('pay_components', 'category') ? $component->category : null,
            'calculation_type' => $component->calculation_type,
            'default_value' => (float) $component->default_value,
            'formula' => $component->formula,
            'is_taxable' => (bool) $component->is_taxable,
            'contributes_sss' => (bool) $component->contributes_sss,
            'contributes_philhealth' => (bool) $component->contributes_philhealth,
            'contributes_pagibig' => (bool) $component->contributes_pagibig,
            'is_proratable' => (bool) $component->is_proratable,
            'apply_to_all' => Schema::hasColumn('pay_components', 'apply_to_all')
                ? (bool) $component->apply_to_all
                : false,
            'component_type' => Schema::hasColumn('pay_components', 'component_type')
                ? (string) ($component->component_type ?: PayComponent::COMPONENT_USER)
                : PayComponent::COMPONENT_USER,
            'is_system_protected' => Schema::hasColumn('pay_components', 'is_system_protected')
                ? (bool) $component->is_system_protected
                : false,
            'effective_from' => $component->effective_from?->toDateString(),
            'effective_to' => $component->effective_to?->toDateString(),
            'is_active' => (bool) $component->is_active,
            'is_loan' => Schema::hasColumn('pay_components', 'is_loan') ? (bool) $component->is_loan : false,
            'is_amortized' => Schema::hasColumn('pay_components', 'is_amortized') ? (bool) $component->is_amortized : false,
            'default_term_months' => Schema::hasColumn('pay_components', 'default_term_months')
                ? ($component->default_term_months !== null ? (int) $component->default_term_months : null)
                : null,
            'metadata' => $component->metadata,
            'created_at' => $component->created_at?->toIso8601String(),
            'updated_at' => $component->updated_at?->toIso8601String(),
            'is_core_basic_salary' => $hasCode && strtoupper(trim((string) ($component->code ?? ''))) === 'BASIC_SALARY',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPayComponentDuplicateWarnings(): array
    {
        if (! Schema::hasTable('pay_components') || ! Schema::hasColumn('pay_components', 'code')) {
            return [];
        }

        $rows = PayComponent::query()
            ->whereNull('deleted_at')
            ->get(['id', 'code', 'name', 'type']);

        $warnings = [];
        $byCode = $rows->groupBy(fn (PayComponent $c) => strtoupper(trim((string) ($c->code ?? ''))));
        foreach ($byCode as $code => $items) {
            if ($code === '' || $items->count() < 2) {
                continue;
            }
            $warnings[] = [
                'kind' => 'duplicate_code',
                'code' => $code,
                'ids' => $items->pluck('id')->values()->all(),
            ];
        }

        $basicNamed = $rows->filter(
            fn (PayComponent $c) => $c->type === PayComponent::TYPE_EARNING
                && strcasecmp(trim((string) ($c->name ?? '')), 'Basic Salary') === 0
        );
        if ($basicNamed->count() > 1) {
            $warnings[] = [
                'kind' => 'duplicate_basic_salary_name',
                'ids' => $basicNamed->pluck('id')->values()->all(),
            ];
        }

        return $warnings;
    }

    private function filterPayloadForExistingColumns(array $payload): array
    {
        $columns = collect([
            'name',
            'code',
            'type',
            'category',
            'calculation_type',
            'default_value',
            'formula',
            'is_taxable',
            'contributes_sss',
            'contributes_philhealth',
            'contributes_pagibig',
            'is_proratable',
            'apply_to_all',
            'component_type',
            'is_system_protected',
            'effective_from',
            'effective_to',
            'is_active',
            'is_loan',
            'is_amortized',
            'default_term_months',
            'metadata',
        ])->filter(fn (string $column) => Schema::hasColumn('pay_components', $column))
            ->all();

        return array_intersect_key($payload, array_flip($columns));
    }

    private function isBasicSalaryPayload(array $payload): bool
    {
        return strtoupper((string) ($payload['code'] ?? '')) === 'BASIC_SALARY'
            || strcasecmp((string) ($payload['category'] ?? ''), 'Basic Salary') === 0
            || strcasecmp((string) ($payload['name'] ?? ''), 'Basic Salary') === 0;
    }
}
