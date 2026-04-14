<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoanRequest;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\DeductionApplicationService;
use App\Services\LoanRequestService;
use App\Services\PayCycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanRequestController extends Controller
{
    public function __construct(
        private readonly LoanRequestService $loanRequestService,
        private readonly DataScopeService $dataScopeService,
        private readonly PayCycleService $payCycleService,
        private readonly DeductionApplicationService $deductionApplicationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        // Ensure list reflects latest installment settlement after finalized payroll runs.
        $this->deductionApplicationService->autoProcessDueInstallments(now());
        $userQuery = User::query();
        $this->dataScopeService->restrictEmployeeQuery($actor, $userQuery);
        $userIds = $userQuery->pluck('id');

        $rows = LoanRequest::query()
            ->whereIn('user_id', $userIds)
            ->with([
                // Full user so profile_image / profile_image_url (appended) resolve like Employees list.
                'user',
                'requestedByUser:id,name,employee_code',
                'deductionType',
                'payComponent:id,name,code,category,is_loan',
                'approvedByUser:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'loan_requests' => $rows,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $loan = LoanRequest::query()
            ->with([
                'user',
                'requestedByUser:id,name,employee_code',
                'deductionType',
                'payComponent:id,name,code,category,is_loan',
                'firstApprover:id,name',
                'secondApprover:id,name',
                'approvedByUser:id,name',
                'rejectedByUser:id,name',
                'employeeDeduction.amortizationSchedule',
            ])
            ->findOrFail($id);
        $this->loanRequestService->ensureLoanAccessible($request->user(), $loan);
        $scheduleType = (string) ($loan->deduction_schedule ?: 'both');
        $nextDeduction = $this->payCycleService->getNextDeductionDate($loan->user, $scheduleType);
        $payCyclePreview = $this->payCycleService->previewForUser($loan->user);

        return response()->json([
            'loan_request' => $loan,
            'approval_progress' => $this->loanRequestService->buildApprovalProgress($loan),
            'can_approve' => $this->loanRequestService->canApprove($request->user(), $loan),
            'can_reject' => $this->loanRequestService->canReject($request->user(), $loan),
            'next_deduction_dates' => $nextDeduction['next_dates'] ?? [],
            'pay_cycle_preview' => $payCyclePreview,
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $loan = LoanRequest::query()->with('user')->findOrFail($id);
        $this->loanRequestService->ensureLoanAccessible($request->user(), $loan);

        $validated = $request->validate([
            /** May omit when employee provided `preferred_monthly_deduction` (service fallback). */
            'installment_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        $updated = $this->loanRequestService->approveFinal(
            $actor,
            $loan,
            (float) ($validated['installment_amount'] ?? 0),
            $validated['notes'] ?? null
        );

        return response()->json([
            'loan_request' => $updated,
            'approval_progress' => $this->loanRequestService->buildApprovalProgress($updated),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $loan = LoanRequest::query()->with('user')->findOrFail($id);
        $this->loanRequestService->ensureLoanAccessible($request->user(), $loan);

        $validated = $request->validate([
            'rejection_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->loanRequestService->reject($request->user(), $loan, $validated['rejection_note'] ?? null);

        return response()->json(['loan_request' => $updated]);
    }
}
