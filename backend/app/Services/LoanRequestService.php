<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\DeductionScheduleSetting;
use App\Models\DeductionType;
use App\Models\EmployeeDeduction;
use App\Models\LoanRequest;
use App\Models\User;
use App\Support\HrApprovalStages;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phase 1: loan requests queue for Admin (HR) only — no line-manager approval step.
 */
class LoanRequestService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly DataScopeService $dataScopeService,
        private readonly LoanAmortizationService $loanAmortizationService,
        private readonly DeductionAuditService $deductionAuditService,
    ) {}

    public function canApprove(User $actor, LoanRequest $loan): bool
    {
        if (! $loan->pending_approval || $loan->status !== LoanRequest::STATUS_PENDING || $loan->rejected_at) {
            return false;
        }

        if ($this->hrRoleResolver->resolve($actor) !== HrRole::AdminHr) {
            return false;
        }

        if ((int) $actor->id === (int) $loan->user_id) {
            return false;
        }

        $stage = $loan->approval_stage ?? HrApprovalStages::PENDING_SECOND;

        return $stage === HrApprovalStages::PENDING_SECOND;
    }

    public function canReject(User $actor, LoanRequest $loan): bool
    {
        return $this->canApprove($actor, $loan);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildApprovalProgress(LoanRequest $loan): array
    {
        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $loan->loadMissing(['user', 'requestedByUser']);
        } else {
            $loan->loadMissing(['user']);
        }
        $employee = $loan->user;
        if (! $employee) {
            return [];
        }

        $filer = (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id') && $loan->requestedByUser)
            ? $loan->requestedByUser
            : $employee;

        $rejected = $loan->rejected_at !== null;
        $finalOk = $loan->status === LoanRequest::STATUS_APPROVED;

        $hrStatus = match (true) {
            $finalOk => 'completed',
            $rejected => 'rejected',
            $loan->pending_approval && ! $rejected => 'current',
            default => 'pending',
        };

        $secondApprover = $loan->second_approver_id ? $loan->secondApprover : null;

        return [
            [
                'key' => 'submitted',
                'label' => 'Request submitted',
                'status' => 'completed',
                'approver_role_label' => null,
                'submitter_name' => $filer->name,
                'approver_name' => null,
                'profile_image_url' => $filer->profile_image_url,
                'acted_at' => $loan->created_at?->toIso8601String(),
                'remarks' => (int) $filer->id !== (int) $employee->id
                    ? 'Borrower: '.$employee->name
                    : null,
            ],
            [
                'key' => 'hr_review',
                'label' => 'Admin (HR) approval',
                'status' => $hrStatus,
                'approver_role_label' => 'Admin (HR)',
                'submitter_name' => null,
                'approver_name' => $secondApprover?->name,
                'profile_image_url' => $secondApprover?->profile_image_url,
                'acted_at' => $loan->second_approved_at?->toIso8601String(),
                'remarks' => null,
            ],
        ];
    }

    public function submit(
        User $borrower,
        DeductionType $loanType,
        float $requestedAmount,
        ?string $reason,
        ?float $preferredMonthlyDeduction = null,
        ?int $termMonths = null,
        ?int $payComponentId = null,
        ?User $requestedBy = null,
        ?string $deductionSchedule = null,
    ): LoanRequest {
        if ($loanType->type !== DeductionType::TYPE_LOAN) {
            throw ValidationException::withMessages(['deduction_type_id' => ['Selected type is not a loan.']]);
        }

        $filer = $requestedBy ?? $borrower;

        $sched = $this->normalizeDeductionSchedule($deductionSchedule);

        $withInterest = (bool) ($loanType->with_interest ?? false);
        $interestRatePercent = $withInterest ? round(max(0.0, (float) ($loanType->interest_rate_percent ?? 0)), 4) : null;
        $interestType = $withInterest
            ? (in_array((string) ($loanType->interest_type ?? ''), ['simple', 'compound'], true) ? (string) $loanType->interest_type : 'simple')
            : null;

        $attributes = [
            'user_id' => $borrower->id,
            'deduction_type_id' => $loanType->id,
            'pay_component_id' => $payComponentId,
            'requested_amount' => round(max(0.01, $requestedAmount), 2),
            'installment_amount' => null,
            'preferred_monthly_deduction' => $preferredMonthlyDeduction !== null && $preferredMonthlyDeduction > 0
                ? round($preferredMonthlyDeduction, 2)
                : null,
            'term_months' => $termMonths !== null && $termMonths > 0 ? (int) $termMonths : null,
            'reason' => $reason ? trim($reason) : null,
            'status' => LoanRequest::STATUS_PENDING,
            'approval_stage' => HrApprovalStages::PENDING_SECOND,
            'pending_approval' => true,
        ];

        if (Schema::hasColumn('pay_loan_requests', 'with_interest')) {
            $attributes['with_interest'] = $withInterest;
        }
        if (Schema::hasColumn('pay_loan_requests', 'interest_rate_percent')) {
            $attributes['interest_rate_percent'] = $interestRatePercent;
        }
        if (Schema::hasColumn('pay_loan_requests', 'interest_type')) {
            $attributes['interest_type'] = $interestType;
        }

        if (Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            $attributes['requested_by_user_id'] = $filer->id;
        }

        if (Schema::hasColumn('pay_loan_requests', 'deduction_schedule') && $sched !== null) {
            $attributes['deduction_schedule'] = $sched;
        }

        return LoanRequest::create($attributes);
    }

    /**
     * Admin (HR) approval: sets monthly deduction and creates employee_deduction.
     */
    public function approveFinal(User $actor, LoanRequest $loan, float $installmentAmount, ?string $notes = null): LoanRequest
    {
        if (! $this->canApprove($actor, $loan)) {
            abort(403, 'You are not authorized to approve this loan request.');
        }

        $stage = $loan->approval_stage ?? HrApprovalStages::PENDING_SECOND;
        if ($stage !== HrApprovalStages::PENDING_SECOND) {
            throw ValidationException::withMessages(['request' => ['Loan request is not awaiting HR approval.']]);
        }

        $resolved = $installmentAmount > 0
            ? $installmentAmount
            : (float) ($loan->preferred_monthly_deduction ?? 0);
        $installment = round(max(0.01, $resolved), 2);
        if ($installment <= 0) {
            throw ValidationException::withMessages([
                'installment_amount' => ['Enter approved monthly deduction, or ensure the employee provided a preferred monthly amount.'],
            ]);
        }
        if ($installment > (float) $loan->requested_amount) {
            throw ValidationException::withMessages(['installment_amount' => ['Monthly installment cannot exceed requested principal.']]);
        }

        return DB::transaction(function () use ($actor, $loan, $installment, $notes) {
            $loan->refresh();
            $loan->loadMissing('deductionType');

            $deductionAttrs = [
                'user_id' => $loan->user_id,
                'deduction_type_id' => $loan->deduction_type_id,
                'pay_component_id' => $loan->pay_component_id ?? $loan->deductionType?->pay_component_id,
                'amount' => $installment,
                'start_date' => Carbon::now()->toDateString(),
                'end_date' => null,
                'remaining_balance' => round((float) $loan->requested_amount, 2),
                'total_loan_amount' => round((float) $loan->requested_amount, 2),
                'is_amortized' => true,
                'is_active' => true,
                'source' => EmployeeDeduction::SOURCE_LOAN_REQUEST,
                'loan_request_id' => $loan->id,
                'notes' => $notes ? trim($notes) : null,
            ];
            $withInterest = (bool) ($loan->with_interest ?? false);
            if (Schema::hasColumn('pay_employee_deductions', 'with_interest')) {
                $deductionAttrs['with_interest'] = $withInterest;
            }
            if (Schema::hasColumn('pay_employee_deductions', 'interest_rate_annual')) {
                $deductionAttrs['interest_rate_annual'] = $withInterest ? (float) ($loan->interest_rate_percent ?? 0) : null;
            }
            if (Schema::hasColumn('pay_employee_deductions', 'interest_type')) {
                $deductionAttrs['interest_type'] = $withInterest
                    ? (in_array((string) ($loan->interest_type ?? ''), ['simple', 'compound'], true) ? (string) $loan->interest_type : 'simple')
                    : null;
            }
            $normalizedLoanSchedule = $this->normalizeDeductionSchedule($loan->deduction_schedule);
            if (Schema::hasColumn('pay_employee_deductions', 'deduction_schedule') && $normalizedLoanSchedule !== null) {
                $deductionAttrs['deduction_schedule'] = $normalizedLoanSchedule;
            }
            $deduction = EmployeeDeduction::create($deductionAttrs);
            $this->deductionAuditService->log(
                $deduction,
                'loan_approved_and_assigned',
                $actor->id,
                $installment,
                (float) $deduction->remaining_balance,
                null,
                $deduction->toArray(),
                'Loan request approved and deduction assignment created.',
                ['loan_request_id' => $loan->id]
            );

            $this->loanAmortizationService->generateSchedule($deduction->fresh(['deductionType', 'payComponent']), $loan->fresh());

            $loan->installment_amount = $installment;
            $loan->second_approver_id = $actor->id;
            $loan->second_approved_at = now();
            $loan->approved_by = $actor->id;
            $loan->approved_at = now();
            $loan->approval_stage = HrApprovalStages::APPROVED;
            $loan->status = LoanRequest::STATUS_APPROVED;
            $loan->pending_approval = false;
            $loan->reviewed_at = now();
            $loan->reviewed_by = $actor->id;
            $loan->employee_deduction_id = $deduction->id;
            $loan->save();

            return $loan->fresh(['user', 'deductionType', 'firstApprover', 'secondApprover', 'approvedByUser', 'employeeDeduction']);
        });
    }

    public function reject(User $actor, LoanRequest $loan, ?string $rejectionNote = null): LoanRequest
    {
        if (! $this->canReject($actor, $loan)) {
            abort(403, 'You are not authorized to reject this loan request.');
        }

        $loan->status = LoanRequest::STATUS_REJECTED;
        $loan->approval_stage = HrApprovalStages::REJECTED;
        $loan->pending_approval = false;
        $loan->rejected_at = now();
        $loan->rejected_by = $actor->id;
        $loan->rejection_note = $rejectionNote ? trim($rejectionNote) : null;
        $loan->save();

        return $loan->fresh(['user', 'deductionType', 'rejectedByUser']);
    }

    public function ensureLoanAccessible(User $actor, LoanRequest $loan): void
    {
        if ($loan->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $loan->user);
        }
    }

    private function normalizeDeductionSchedule(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $raw = strtolower(trim($value));
        if ($raw === '') {
            return null;
        }

        return match ($raw) {
            '15', '15th', 'first', 'first_half', 'first-half' => DeductionScheduleSetting::SCHEDULE_15TH,
            '30', '30th', 'second', 'second_half', 'second-half', 'end_of_month', 'eom' => DeductionScheduleSetting::SCHEDULE_30TH,
            'both', '50/50', 'half', 'split', 'semi-monthly', 'semi_monthly' => DeductionScheduleSetting::SCHEDULE_BOTH,
            default => null,
        };
    }
}
