<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExecomEmployeeProfile;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\PayrollCalculatorService;
use App\Support\PayrollCacheInvalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecomManagementController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollCalculatorService $payrollCalculator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'string', 'in:active,inactive,all'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ExecomEmployeeProfile::query()
            ->with([
                'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,profile_image,position,company_id,branch_id,department_id',
                'company:id,name',
                'branch:id,name',
                'department:id,name',
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('id');

        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['branch_id'])) {
            $query->where('branch_id', (int) $validated['branch_id']);
        }
        if (! empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }
        if (($validated['status'] ?? 'active') === 'active') {
            $query->where('is_active', true);
        } elseif (($validated['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }
        if (! empty($validated['q'])) {
            $needle = '%'.trim((string) $validated['q']).'%';
            $query->whereHas('employee', function ($employee) use ($needle): void {
                $employee->where('name', 'like', $needle)
                    ->orWhere('first_name', 'like', $needle)
                    ->orWhere('last_name', 'like', $needle)
                    ->orWhere('employee_code', 'like', $needle);
            });
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'execom_employees' => $paginator->getCollection()->map(fn (ExecomEmployeeProfile $profile) => $this->profilePayload($profile))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        $employee = User::query()->payrollEmployees()->active()->findOrFail((int) $validated['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        $fixedSalary = array_key_exists('fixed_salary', $validated)
            ? (float) $validated['fixed_salary']
            : (float) ($employee->monthly_salary ?? $employee->monthly_rate ?? 0);

        $profile = ExecomEmployeeProfile::query()->create([
            ...$validated,
            'company_id' => $validated['company_id'] ?? $employee->getEffectiveCompanyId(),
            'branch_id' => $validated['branch_id'] ?? $employee->branch_id,
            'department_id' => $validated['department_id'] ?? $employee->department_id,
            'fixed_salary' => $fixedSalary,
            'pay_schedule' => $validated['pay_schedule'] ?? ExecomEmployeeProfile::PAY_SCHEDULE_PER_PERIOD,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);
        PayrollCacheInvalidator::clear('execom_employee_tagged', [
            'employee_id' => (int) $profile->employee_id,
            'company_id' => $profile->company_id ? (int) $profile->company_id : null,
            'effective_from' => $profile->effective_from?->toDateString(),
            'effective_to' => $profile->effective_to?->toDateString(),
        ]);
        $this->syncEmployeeExecomFlag((int) $profile->employee_id);

        return response()->json([
            'message' => 'EXECOM employee added.',
            'execom_employee' => $this->profilePayload($profile->fresh(['employee', 'company', 'branch', 'department'])),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $profile = ExecomEmployeeProfile::query()->findOrFail($id);
        $validated = $this->validatedPayload($request, updating: true);
        $original = $profile->replicate();
        $profile->fill([
            ...$validated,
            'updated_by' => $request->user()?->id,
        ])->save();
        if ($profile->wasChanged(['employee_id', 'company_id', 'branch_id', 'department_id', 'is_active', 'effective_from', 'effective_to'])) {
            PayrollCacheInvalidator::clear('execom_employee_profile_changed', [
                'employee_id' => (int) $profile->employee_id,
                'previous_employee_id' => (int) $original->employee_id,
                'company_id' => $profile->company_id ? (int) $profile->company_id : null,
                'effective_from' => $profile->effective_from?->toDateString(),
                'effective_to' => $profile->effective_to?->toDateString(),
            ]);
        }
        $this->syncEmployeeExecomFlag((int) $profile->employee_id);
        if ((int) $original->employee_id !== (int) $profile->employee_id) {
            $this->syncEmployeeExecomFlag((int) $original->employee_id);
        }

        return response()->json([
            'message' => 'EXECOM employee updated.',
            'execom_employee' => $this->profilePayload($profile->fresh(['employee', 'company', 'branch', 'department'])),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $profile = ExecomEmployeeProfile::query()->findOrFail($id);
        $profile->update([
            'is_active' => false,
            'effective_to' => $profile->effective_to ?? now()->toDateString(),
            'updated_by' => $request->user()?->id,
        ]);
        PayrollCacheInvalidator::clear('execom_employee_removed', [
            'employee_id' => (int) $profile->employee_id,
            'company_id' => $profile->company_id ? (int) $profile->company_id : null,
            'effective_from' => $profile->effective_from?->toDateString(),
            'effective_to' => $profile->effective_to?->toDateString(),
        ]);
        $this->syncEmployeeExecomFlag((int) $profile->employee_id);

        return response()->json(['message' => 'EXECOM employee deactivated.']);
    }

    public function history(int $id): JsonResponse
    {
        $profile = ExecomEmployeeProfile::query()->with('employee:id,name,first_name,last_name')->findOrFail($id);
        $rows = Payslip::query()
            ->with('company:id,name')
            ->where('user_id', (int) $profile->employee_id)
            ->where('payroll_module', PayrollBatchRun::MODULE_EXECOM)
            ->orderByDesc('pay_period_end')
            ->limit(50)
            ->get()
            ->map(fn (Payslip $payslip) => [
                'payslip_id' => (int) $payslip->id,
                'payroll_batch_run_id' => $payslip->payroll_batch_run_id ? (int) $payslip->payroll_batch_run_id : null,
                'company_id' => $payslip->company_id ? (int) $payslip->company_id : null,
                'company_name' => $payslip->company?->name,
                'pay_period_start' => $payslip->pay_period_start?->toDateString(),
                'pay_period_end' => $payslip->pay_period_end?->toDateString(),
                'gross_pay' => (float) $payslip->gross_pay,
                'total_deductions' => (float) $payslip->total_deductions,
                'net_pay' => (float) $payslip->net_pay,
                'status' => (string) $payslip->status,
            ])
            ->values();

        return response()->json([
            'execom_employee' => $this->profilePayload($profile),
            'history' => $rows,
        ]);
    }

    private function validatedPayload(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'employee_id' => [$updating ? 'sometimes' : 'required', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'fixed_salary' => ['nullable', 'numeric', 'min:0'],
            'pay_schedule' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function profilePayload(?ExecomEmployeeProfile $profile): ?array
    {
        if (! $profile instanceof ExecomEmployeeProfile) {
            return null;
        }
        $compensationTotals = $profile->employee instanceof User
            ? $this->currentOtherCompensationTotals($profile->employee)
            : ['other_allowance_total' => 0.0, 'other_deduction_total' => 0.0];

        return [
            'id' => (int) $profile->id,
            'employee_id' => (int) $profile->employee_id,
            'employee_name' => $profile->employee?->display_name,
            'employee_code' => $profile->employee?->employee_code,
            'position' => $profile->employee?->position,
            'company_id' => $profile->company_id ? (int) $profile->company_id : null,
            'company_name' => $profile->company?->name,
            'branch_id' => $profile->branch_id ? (int) $profile->branch_id : null,
            'branch_name' => $profile->branch?->name,
            'department_id' => $profile->department_id ? (int) $profile->department_id : null,
            'department_name' => $profile->department?->name,
            'fixed_salary' => (float) $profile->fixed_salary,
            'other_allowance_total' => $compensationTotals['other_allowance_total'],
            'other_deduction_total' => $compensationTotals['other_deduction_total'],
            'pay_schedule' => $profile->pay_schedule,
            'is_active' => (bool) $profile->is_active,
            'effective_from' => $profile->effective_from?->toDateString(),
            'effective_to' => $profile->effective_to?->toDateString(),
            'remarks' => $profile->remarks,
        ];
    }

    private function syncEmployeeExecomFlag(int $employeeId): void
    {
        $hasActiveProfile = ExecomEmployeeProfile::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->exists();

        User::query()->whereKey($employeeId)->update(['is_execom' => $hasActiveProfile]);
    }

    /**
     * @return array{other_allowance_total: float, other_deduction_total: float}
     */
    private function currentOtherCompensationTotals(User $employee): array
    {
        $summary = $this->payrollCalculator->buildEmployeeCompensationSummary($employee, [
            'as_of_date' => now()->toDateString(),
            'proration_factor' => 1.0,
            'hours_worked' => 0.0,
            'include_deduction_schedule_catalog' => false,
            'cache' => true,
        ]);

        $allowanceTotal = 0.0;
        foreach ((array) ($summary['earnings'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $code = strtoupper(trim((string) ($line['code'] ?? '')));
            if ($code === 'BASIC_SALARY') {
                continue;
            }
            $allowanceTotal += max(0.0, (float) ($line['computed_amount'] ?? 0));
        }

        $deductionTotal = 0.0;
        foreach ((array) ($summary['deductions'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $deductionTotal += max(0.0, (float) ($line['computed_amount'] ?? 0));
        }

        return [
            'other_allowance_total' => round($allowanceTotal, 2),
            'other_deduction_total' => round($deductionTotal, 2),
        ];
    }
}
