<?php

namespace App\Http\Controllers;

use App\Models\DeductionScheduleSetting;
use App\Models\DeductionType;
use App\Models\LoanRequest;
use App\Models\PayComponent;
use App\Services\DeductionScheduleService;
use App\Services\DeductionApplicationService;
use App\Services\LoanAmortizationService;
use App\Services\LoanRequestService;
use App\Services\PayCycleService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeLoanRequestController extends Controller
{
    public function __construct(
        private readonly LoanRequestService $loanRequestService,
        private readonly LoanAmortizationService $loanAmortizationService,
        private readonly PayCycleService $payCycleService,
        private readonly DeductionScheduleService $deductionScheduleService,
        private readonly DeductionApplicationService $deductionApplicationService,
    ) {}

    public function deductionsContext(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $rbac = app(RbacService::class);
        if (! $rbac->canAny($user, ['loans.view_own', 'request-loan', 'loans.request'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $companyId = $user->getEffectiveCompanyId();
        $loanTypeSelect = ['id', 'name', 'slug', 'type', 'pay_component_id'];
        foreach (['with_interest', 'interest_rate_percent', 'interest_type'] as $col) {
            if (Schema::hasColumn('pay_deduction_types', $col)) {
                $loanTypeSelect[] = $col;
            }
        }

        $loanTypes = DeductionType::query()
            ->where('type', DeductionType::TYPE_LOAN)
            ->where('is_active', true)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');
                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->orderBy('name')
            ->get($loanTypeSelect);

        $loanPayComponents = PayComponent::query()
            ->where('type', PayComponent::TYPE_DEDUCTION)
            ->where('is_active', true)
            ->where(function ($q) {
                if (Schema::hasColumn('pay_components', 'is_loan')) {
                    $q->where('is_loan', true);
                }
                $q->orWhereRaw('LOWER(TRIM(category)) = ?', ['loan']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'is_loan', 'is_amortized', 'default_value']);

        $loanPayComponentsPayload = $loanPayComponents->map(function (PayComponent $pc) use ($companyId) {
            $row = $pc->toArray();
            $row['deduction_schedule'] = $this->deductionScheduleService->resolveScheduleType(
                'pay_component:'.((int) $pc->id),
                $companyId
            );

            return $row;
        })->values()->all();

        $payCyclePreview = $this->payCycleService->previewForUser($user);

        return response()->json([
            'loan_types' => $loanTypes,
            'loan_pay_components' => $loanPayComponentsPayload,
            'pay_cycle_preview' => $payCyclePreview,
            'scope_employees' => [],
            'can_request_for_others' => false,
        ]);
    }

    public function nextDeductionDates(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $rbac = app(RbacService::class);
        if (! $rbac->canRequestLoan($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'schedule_type' => ['required', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $payload = $this->payCycleService->getNextDeductionDate(
            $user,
            (string) $validated['schedule_type'],
            $validated['as_of_date'] ?? null
        );
        $payload['pay_cycle_preview'] = $this->payCycleService->previewForUser($user, $validated['as_of_date'] ?? null);

        return response()->json($payload);
    }

    public function myDeductions(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $rbac = app(RbacService::class);
        if (! $rbac->canAny($user, ['loans.view_own', 'request-loan', 'loans.request'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Auto-settle installments tied to already-finalized payroll periods so employee loan status
        // reflects "Paid" as soon as the pay date run is completed.
        $this->deductionApplicationService->autoProcessDueInstallments(now(), (int) $user->id);

        $assignments = $user->employeeDeductions()
            ->with(['deductionType', 'payComponent:id,name,code', 'amortizationSchedule', 'auditLogs.actor:id,name'])
            ->orderByDesc('start_date')
            ->get();

        foreach ($assignments as $ed) {
            $this->loanAmortizationService->overlayNextDueForResponse($ed, $user);
        }

        $requestsQuery = LoanRequest::query();
        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $requestsQuery->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('requested_by_user_id', $user->id);
            });
        } else {
            $requestsQuery->where('user_id', $user->id);
        }

        $with = ['deductionType', 'payComponent:id,name,code', 'user:id,name,employee_code'];
        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $with[] = 'requestedByUser:id,name';
        }

        $requests = $requestsQuery
            ->with($with)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'employee_deductions' => $assignments,
            'loan_requests' => $requests,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        $rbac = app(RbacService::class);
        if (! $rbac->canRequestLoan($user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'pay_component_id' => ['nullable', 'integer', 'exists:pay_components,id'],
            'deduction_type_id' => ['nullable', 'integer', 'exists:pay_deduction_types,id'],
            'requested_amount' => ['required', 'numeric', 'min:1'],
            'preferred_monthly_deduction' => ['nullable', 'numeric', 'min:0.01'],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'deduction_schedule' => ['nullable', 'string', Rule::in([
                DeductionScheduleSetting::SCHEDULE_15TH,
                DeductionScheduleSetting::SCHEDULE_30TH,
                DeductionScheduleSetting::SCHEDULE_BOTH,
            ])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($validated['pay_component_id']) && empty($validated['deduction_type_id'])) {
            throw ValidationException::withMessages([
                'pay_component_id' => ['Select a loan pay component or a loan deduction type.'],
            ]);
        }

        // Self-service only for all requesting roles (employee + org heads + admin).
        $borrower = $user;

        $payComponentId = null;
        if (! empty($validated['pay_component_id'])) {
            $pc = PayComponent::query()->findOrFail((int) $validated['pay_component_id']);
            $loanType = DeductionType::ensureForLoanPayComponent($pc);
            $payComponentId = (int) $pc->id;
        } else {
            $loanType = DeductionType::query()->findOrFail((int) $validated['deduction_type_id']);
            if ($loanType->type !== DeductionType::TYPE_LOAN) {
                return response()->json(['message' => 'Selected deduction type is not a loan.'], 422);
            }
            $companyId = $borrower->getEffectiveCompanyId();
            if ($loanType->company_id !== null && (int) $loanType->company_id !== (int) $companyId) {
                return response()->json(['message' => 'Invalid loan type for this employee\'s company.'], 422);
            }
            $payComponentId = $loanType->pay_component_id ? (int) $loanType->pay_component_id : null;
        }

        $loan = $this->loanRequestService->submit(
            $borrower,
            $loanType,
            (float) $validated['requested_amount'],
            $validated['reason'] ?? null,
            isset($validated['preferred_monthly_deduction']) ? (float) $validated['preferred_monthly_deduction'] : null,
            isset($validated['term_months']) ? (int) $validated['term_months'] : null,
            $payComponentId,
            $user,
            $validated['deduction_schedule'] ?? null,
        );

        $load = ['deductionType', 'payComponent:id,name,code', 'user:id,name'];
        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $load[] = 'requestedByUser:id,name';
        }

        return response()->json([
            'loan_request' => $loan->load($load),
            'approval_progress' => $this->loanRequestService->buildApprovalProgress($loan),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $withShow = ['deductionType', 'user', 'payComponent:id,name,code'];
        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $withShow[] = 'requestedByUser:id,name';
        }
        $loan = LoanRequest::query()->with($withShow)->findOrFail($id);
        $isBorrower = (int) $loan->user_id === (int) $user->id;
        $hasFilerColumn = Schema::hasColumn('pay_loan_requests', 'requested_by_user_id');
        $isFiler = $hasFilerColumn && (int) ($loan->requested_by_user_id ?? 0) === (int) $user->id;
        if (! $isBorrower && ! $isFiler) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (! app(RbacService::class)->canAny($user, ['loans.view_own', 'request-loan', 'loans.request', 'loans.view'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $scheduleType = (string) ($loan->deduction_schedule ?: 'both');
        $nextDeduction = $this->payCycleService->getNextDeductionDate($loan->user ?? $user, $scheduleType);
        $payCyclePreview = $this->payCycleService->previewForUser($loan->user ?? $user);

        return response()->json([
            'loan_request' => $loan,
            'approval_progress' => $this->loanRequestService->buildApprovalProgress($loan),
            'next_deduction_dates' => $nextDeduction['next_dates'] ?? [],
            'pay_cycle_preview' => $payCyclePreview,
        ]);
    }
}
