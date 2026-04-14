<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeCompensationSummaryJob;
use App\Models\DeductionScheduleSetting;
use App\Models\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\DeductionApplicationService;
use App\Services\DeductionAuditService;
use App\Services\LoanAmortizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class EmployeeDeductionController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly LoanAmortizationService $loanAmortizationService,
        private readonly DeductionAuditService $deductionAuditService,
        private readonly DeductionApplicationService $deductionApplicationService,
    ) {}

    /**
     * Active recurring deductions for all employees visible to the actor (org scope).
     */
    public function activeInScope(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Keep admin loans table aligned with finalized payroll results (paid installments / balances).
        $this->deductionApplicationService->autoProcessDueInstallments(now());
        $userQuery = User::query();
        $this->dataScopeService->restrictEmployeeQuery($actor, $userQuery);
        $userIds = $userQuery->pluck('id');

        $rows = EmployeeDeduction::query()
            ->whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->with([
                // Full user row so profile_image_url accessor + job fields (position, etc.) serialize like other admin endpoints.
                'user',
                'deductionType',
                'payComponent:id,name,code,category,is_loan',
                'amortizationSchedule',
            ])
            ->orderByDesc('start_date')
            ->limit(500)
            ->get();

        foreach ($rows as $ed) {
            $this->loanAmortizationService->overlayNextDueForResponse($ed);
        }

        return response()->json(['employee_deductions' => $rows]);
    }

    public function index(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $rows = EmployeeDeduction::query()
            ->where('user_id', $userId)
            ->with(['deductionType', 'payComponent:id,name,code', 'amortizationSchedule'])
            ->orderByDesc('start_date')
            ->get();
        foreach ($rows as $ed) {
            $this->loanAmortizationService->overlayNextDueForResponse($ed, $user);
        }

        return response()->json(['employee_deductions' => $rows]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $validated = $request->validate([
            'deduction_type_id' => ['required', 'integer', 'exists:pay_deduction_types,id'],
            'pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'remaining_balance' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'deduction_schedule' => ['nullable', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'is_court_ordered_garnishment' => ['nullable', 'boolean'],
            'is_legally_allowed' => ['nullable', 'boolean'],
            'priority_override' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $type = DeductionType::query()->findOrFail((int) $validated['deduction_type_id']);
        $isLoan = $type->type === DeductionType::TYPE_LOAN;

        $attrs = [
            'user_id' => $userId,
            'deduction_type_id' => $type->id,
            'pay_component_id' => $validated['pay_component_id'] ?? $type->pay_component_id,
            'amount' => round((float) $validated['amount'], 2),
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'remaining_balance' => $isLoan
                ? round((float) ($validated['remaining_balance'] ?? $validated['amount']), 2)
                : null,
            'is_active' => true,
            'source' => EmployeeDeduction::SOURCE_MANUAL,
            'loan_request_id' => null,
            'notes' => $validated['notes'] ?? null,
            'is_court_ordered_garnishment' => (bool) ($validated['is_court_ordered_garnishment'] ?? false),
            'is_legally_allowed' => $validated['is_legally_allowed'] ?? true,
            'priority_override' => $validated['priority_override'] ?? null,
        ];
        if (Schema::hasColumn('pay_employee_deductions', 'deduction_schedule') && ! empty($validated['deduction_schedule'])) {
            $attrs['deduction_schedule'] = $validated['deduction_schedule'];
        }

        $row = EmployeeDeduction::create($attrs);
        $this->deductionAuditService->log(
            $row,
            'deduction_assigned',
            $actor?->id,
            (float) $row->amount,
            $row->remaining_balance !== null ? (float) $row->remaining_balance : null,
            null,
            $row->toArray(),
            'Manual deduction assignment created via admin module.'
        );

        try {
            ComputeCompensationSummaryJob::dispatch((int) $userId)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to queue compensation summary after deduction create', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['employee_deduction' => $row->load(['deductionType', 'payComponent:id,name,code'])], 201);
    }

    public function update(Request $request, int $userId, int $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $row = EmployeeDeduction::query()->where('user_id', $userId)->findOrFail($id);
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date'],
            'remaining_balance' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_court_ordered_garnishment' => ['sometimes', 'boolean'],
            'is_legally_allowed' => ['sometimes', 'boolean'],
            'priority_override' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $before = $row->toArray();
        $row->fill($validated);
        $row->save();
        $this->deductionAuditService->log(
            $row,
            'deduction_updated',
            $actor?->id,
            isset($validated['amount']) ? (float) $validated['amount'] : null,
            $row->remaining_balance !== null ? (float) $row->remaining_balance : null,
            $before,
            $row->toArray(),
            'Deduction assignment updated.'
        );

        try {
            ComputeCompensationSummaryJob::dispatch((int) $userId)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to queue compensation summary after deduction update', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['employee_deduction' => $row->fresh(['deductionType', 'payComponent:id,name,code'])]);
    }

    /**
     * Close an amortized loan early (remaining balance zeroed; pending installments skipped).
     */
    public function earlyPayoff(Request $request, int $userId, int $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $row = EmployeeDeduction::query()->where('user_id', $userId)->findOrFail($id);
        if (! $row->is_amortized) {
            return response()->json(['message' => 'Early payoff applies to amortized loans only.'], 422);
        }

        $this->loanAmortizationService->earlyPayoff($row, $actor?->id);

        try {
            ComputeCompensationSummaryJob::dispatch((int) $userId)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to queue compensation summary after early payoff', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'employee_deduction' => $row->fresh(['deductionType', 'payComponent:id,name,code', 'amortizationSchedule']),
        ]);
    }

    /**
     * Manual balance correction for amortized loans (Phase 2 basic admin control).
     */
    public function adjustBalance(Request $request, int $userId, int $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $row = EmployeeDeduction::query()->where('user_id', $userId)->findOrFail($id);
        if (! $row->is_amortized) {
            return response()->json(['message' => 'Balance adjustment applies to amortized loans only.'], 422);
        }

        $validated = $request->validate([
            'remaining_balance' => ['required', 'numeric', 'min:0'],
        ]);

        $this->loanAmortizationService->adjustRemainingBalance($row, (float) $validated['remaining_balance'], $actor?->id);

        try {
            ComputeCompensationSummaryJob::dispatch((int) $userId)->onQueue('default');
        } catch (\Throwable $e) {
            Log::warning('Failed to queue compensation summary after balance adjustment', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'employee_deduction' => $row->fresh(['deductionType', 'payComponent:id,name,code', 'amortizationSchedule']),
        ]);
    }

    /**
     * Admin audit trail viewer for a scoped employee deduction.
     */
    public function auditLogs(Request $request, int $userId, int $id): JsonResponse
    {
        $actor = $request->user();
        $user = User::query()->findOrFail($userId);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $user);

        $row = EmployeeDeduction::query()->where('user_id', $userId)->findOrFail($id);
        $logs = $row->auditLogs()
            ->with(['actor:id,name,email'])
            ->limit(200)
            ->get();

        return response()->json([
            'employee_deduction' => $row->load(['deductionType', 'payComponent:id,name,code']),
            'audit_logs' => $logs,
        ]);
    }
}
