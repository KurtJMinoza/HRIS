<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\GovernmentDeductionExemptionResolver;
use App\Services\PayrollCalculatorService;
use App\Support\EmployeeProfileCache;
use App\Support\GovernmentExemptionCache;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeGovernmentDeductionSettingController extends Controller
{
    public function __construct(
        private readonly GovernmentDeductionExemptionResolver $settingsService,
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollCalculatorService $payrollCalculator,
    ) {}

    public function show(Request $request, int $userId): JsonResponse
    {
        $employee = $this->findAccessibleEmployee($request, $userId);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        return response()->json([
            'settings' => $this->settingsService->settingsForEmployee((int) $employee->id),
        ]);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $employee = $this->findAccessibleEmployee($request, $userId);
        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $validated = $request->validate($this->rules());
        $validated = $this->normalizeSettingsPayload($validated);
        $this->assertExemptionReason($validated);
        $setting = $this->settingsService->upsertForEmployee($employee, $validated, $request->user());
        $this->forgetPayrollCaches((int) $employee->id);

        return response()->json([
            'message' => 'Government deduction settings saved.',
            'settings' => $this->settingsService->defaultPayload($setting),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'payroll_type' => ['nullable', 'string', 'in:regular,execom'],
            'employee_type' => ['nullable', 'string', 'in:regular,probationary,consultant,contractual,project_based'],
            'execom' => ['nullable', 'boolean'],
            'consultant' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()
            ->payrollEmployees()
            ->with(['governmentDeductionSetting', 'company:id,name', 'departmentRelation:id,name'])
            ->select(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'name', 'employee_code', 'company_id', 'department_id', 'employment_status', 'employment_type', 'is_execom'])
            ->orderByLastName();

        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $query->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $like))
                    ->orWhereHas('departmentRelation', fn ($department) => $department->where('name', 'like', $like))
                    ->orWhere('employment_type', 'like', $like)
                    ->orWhere('employment_status', 'like', $like);
            });
        }
        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }
        if (($validated['payroll_type'] ?? null) === 'execom' || $request->boolean('execom')) {
            $query->where('is_execom', true);
        } elseif (($validated['payroll_type'] ?? null) === 'regular') {
            $query->where(function ($q) {
                $q->where('is_execom', false)->orWhereNull('is_execom');
            });
        }
        if ($request->boolean('consultant')) {
            $query->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(employment_status, '')) = 'consultant'")
                    ->orWhereRaw("LOWER(COALESCE(employment_type, '')) = 'consultant'");
            });
        } elseif (! empty($validated['employee_type'])) {
            $type = str_replace('_', '-', strtolower((string) $validated['employee_type']));
            $query->where(function ($q) use ($type) {
                $q->whereRaw("LOWER(REPLACE(COALESCE(employment_status, ''), '_', '-')) = ?", [$type])
                    ->orWhereRaw("LOWER(REPLACE(COALESCE(employment_type, ''), '_', '-')) = ?", [$type]);
            });
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => collect($paginator->items())->map(function (User $employee) {
                return [
                    'id' => (int) $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->display_name,
                    'employment_status' => $employee->employment_status,
                    'employment_type' => $employee->employment_type,
                    'is_execom' => (bool) $employee->is_execom,
                    'company_id' => $employee->company_id,
                    'company' => $employee->company?->name,
                    'department_id' => $employee->department_id,
                    'department' => $employee->departmentRelation?->name,
                    'settings' => $this->settingsService->defaultPayload($employee->governmentDeductionSetting),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'deduction_types' => ['required', 'array', 'min:1'],
            'deduction_types.*' => ['string', 'in:sss,philhealth,pagibig,withholding_tax'],
            'action' => ['required', 'string', 'in:exempt,restore'],
            'applies_to_regular_payroll' => ['nullable', 'boolean'],
            'applies_to_execom_payroll' => ['nullable', 'boolean'],
            'exemption_reason' => ['required_if:action,exempt', 'nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updated = $this->settingsService->bulkSet(
            $validated['employee_ids'],
            $validated['deduction_types'],
            $validated['action'] === 'restore',
            $validated,
            $request->user()
        );

        foreach ($validated['employee_ids'] as $id) {
            $this->forgetPayrollCaches((int) $id);
        }

        return response()->json([
            'message' => $validated['action'] === 'restore'
                ? 'Selected deductions restored.'
                : 'Selected deductions marked exempt.',
            'updated_count' => $updated,
        ]);
    }

    private function findAccessibleEmployee(Request $request, int $userId): User|JsonResponse
    {
        $employee = User::query()->find($userId);
        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        try {
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        }

        return $employee;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'deduct_sss' => ['required', 'boolean'],
            'deduct_philhealth' => ['required', 'boolean'],
            'deduct_pagibig' => ['required', 'boolean'],
            'deduct_withholding_tax' => ['required', 'boolean'],
            'exempt_sss' => ['sometimes', 'boolean'],
            'exempt_philhealth' => ['sometimes', 'boolean'],
            'exempt_pagibig' => ['sometimes', 'boolean'],
            'exempt_withholding_tax' => ['sometimes', 'boolean'],
            'exempt_all_government_deductions' => ['sometimes', 'boolean'],
            'applies_to_regular_payroll' => ['required', 'boolean'],
            'applies_to_execom_payroll' => ['required', 'boolean'],
            'exemption_reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSettingsPayload(array $payload): array
    {
        $mapping = [
            'exempt_sss' => 'deduct_sss',
            'exempt_philhealth' => 'deduct_philhealth',
            'exempt_pagibig' => 'deduct_pagibig',
            'exempt_withholding_tax' => 'deduct_withholding_tax',
        ];

        if (! empty($payload['exempt_all_government_deductions'])) {
            foreach ($mapping as $deductField) {
                $payload[$deductField] = false;
            }
        }
        foreach ($mapping as $exemptField => $deductField) {
            if (array_key_exists($exemptField, $payload)) {
                $payload[$deductField] = ! (bool) $payload[$exemptField];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertExemptionReason(array $payload): void
    {
        $hasExemption = ! (bool) ($payload['deduct_sss'] ?? true)
            || ! (bool) ($payload['deduct_philhealth'] ?? true)
            || ! (bool) ($payload['deduct_pagibig'] ?? true)
            || ! (bool) ($payload['deduct_withholding_tax'] ?? true);

        if (! $hasExemption || (array_key_exists('is_active', $payload) && ! (bool) $payload['is_active'])) {
            return;
        }

        $errors = [];
        if (trim((string) ($payload['exemption_reason'] ?? '')) === '') {
            $errors['exemption_reason'] = ['Exemption reason is required.'];
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    private function forgetPayrollCaches(int $employeeId): void
    {
        EmployeeProfileCache::forgetForUser($employeeId);
        $this->payrollCalculator->forgetCompensationSummaryCacheForUser($employeeId);
        GovernmentExemptionCache::clearPayrollCaches($employeeId);
    }
}
