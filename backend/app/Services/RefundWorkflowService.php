<?php

namespace App\Services;

use App\Models\RefundRequest;
use App\Models\RefundRequestAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * State machine for the dedicated admin payroll-approval workflow:
 * Draft → Submitted → Payroll Review → Approved → Processed (auto on payroll finalize)
 * with Rejected / Cancelled / Voided side-exits. Every transition writes an
 * immutable audit row; processed refunds are never hard-deleted.
 */
class RefundWorkflowService
{
    public function __construct(
        private readonly RefundCalculationService $calculationService,
        private readonly DataScopeService $dataScopeService,
        private readonly PayslipService $payslipService,
        private readonly RbacService $rbac,
    ) {}

    public const TRANSITIONS = [
        'submit' => [
            'from' => [RefundRequest::STATUS_DRAFT],
            'to' => RefundRequest::STATUS_SUBMITTED,
            'permission' => 'refunds.create',
        ],
        'start-review' => [
            'from' => [RefundRequest::STATUS_SUBMITTED],
            'to' => RefundRequest::STATUS_PAYROLL_REVIEW,
            'permission' => 'refunds.approve',
        ],
        'approve' => [
            'from' => [RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_PAYROLL_REVIEW],
            'to' => RefundRequest::STATUS_APPROVED,
            'permission' => 'refunds.approve',
        ],
        'reject' => [
            'from' => [RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_PAYROLL_REVIEW, RefundRequest::STATUS_APPROVED],
            'to' => RefundRequest::STATUS_REJECTED,
            'permission' => 'refunds.approve',
        ],
        'cancel' => [
            'from' => [
                RefundRequest::STATUS_DRAFT,
                RefundRequest::STATUS_SUBMITTED,
                RefundRequest::STATUS_PAYROLL_REVIEW,
                RefundRequest::STATUS_APPROVED,
            ],
            'to' => RefundRequest::STATUS_CANCELLED,
            'permission' => 'refunds.create',
        ],
        'void' => [
            'from' => [RefundRequest::STATUS_APPROVED, RefundRequest::STATUS_PROCESSED],
            'to' => RefundRequest::STATUS_VOIDED,
            'permission' => 'refunds.approve',
        ],
    ];

    /**
     * Recompute through the shared engine and persist a new/updated refund request.
     *
     * @param  array<string, mixed>  $input  see RefundCalculationService::preview()
     */
    public function createOrUpdate(User $actor, ?RefundRequest $refund, array $input, bool $submit): RefundRequest
    {
        $preview = $this->calculationService->preview($actor, $input);
        /** @var User $employee */
        $employee = $preview['employee'];

        return DB::transaction(function () use ($actor, $refund, $input, $preview, $employee, $submit) {
            if ($refund === null) {
                $refund = new RefundRequest;
                $refund->refund_number = RefundRequest::generateRefundNumber();
                $refund->created_by = $actor->id;
            } else {
                if (! in_array($refund->status, RefundRequest::EDITABLE_STATUSES, true)) {
                    throw new RuntimeException('Only Draft or Submitted refund requests can be edited.');
                }
                if (! $this->canManage($actor, $refund)) {
                    throw new RuntimeException('You cannot modify this refund request.');
                }
            }

            $payload = is_array($input['correction_payload'] ?? null) ? $input['correction_payload'] : [];

            $refund->fill([
                'employee_id' => $employee->id,
                'company_id' => $employee->getEffectiveCompanyId(),
                'branch_id' => $employee->branch_id,
                'department_id' => $employee->department_id ?? null,
                'direction' => $preview['direction'],
                'category' => $preview['category'],
                'reason' => $input['reason'],
                'affected_date' => $preview['affected_date'],
                'affected_date_to' => $preview['affected_date_to'],
                'cutoff_start_date' => $preview['cutoff_start_date'],
                'cutoff_end_date' => $preview['cutoff_end_date'],
                'original_payroll_batch_run_id' => $preview['original_batch_run_id'],
                'correction_payload' => $payload,
                'calculation' => $this->calculationService->snapshotForPersist($preview),
                'original_amount' => $preview['original_amount'],
                'corrected_amount' => $preview['corrected_amount'],
                'refund_amount' => abs((float) ($preview['refund_signed_amount'] ?? $preview['refund_amount'])),
                'reason_notes' => $input['reason_notes'] ?? null,
                'status' => $refund->exists ? $refund->status : RefundRequest::STATUS_DRAFT,
            ]);

            $fromStatus = $refund->getOriginal('status') ?? $refund->status;
            $refund->save();

            $this->writeAudit($refund, $actor, $refund->wasRecentlyCreated ? 'created' : 'updated', $fromStatus, $refund->status, null);

            if ($submit) {
                $refund = $this->transition($actor, $refund, 'submit', [
                    'remarks' => $input['reason_notes'] ?? null,
                ]);
            }

            return $refund->fresh(['employee', 'audits']);
        });
    }

    /**
     * Execute a workflow transition with guard checks + audit trail.
     *
     * @param  array{remarks?:?string, reason?:?string, batch_run_id?:?int}  $data
     */
    public function transition(User $actor, RefundRequest $refund, string $action, array $data = []): RefundRequest
    {
        if (! isset(self::TRANSITIONS[$action])) {
            throw new InvalidArgumentException("Unknown refund action '{$action}'.");
        }
        $spec = self::TRANSITIONS[$action];

        if (! $this->rbac->can($actor, $spec['permission'])) {
            throw new RuntimeException('You do not have permission to perform this action.');
        }
        if (! in_array($refund->status, $spec['from'], true)) {
            throw new RuntimeException(
                "Cannot {$action} a refund that is currently '{$refund->status}'."
            );
        }

        $remarks = trim((string) ($data['remarks'] ?? $data['reason'] ?? ''));

        return DB::transaction(function () use ($refund, $actor, $action, $spec, $remarks, $data) {
            // Re-lock the row and re-verify status to avoid racing transitions.
            /** @var RefundRequest $fresh */
            $fresh = RefundRequest::query()->whereKey($refund->id)->lockForUpdate()->first();
            if ($fresh === null || ! in_array($fresh->status, $spec['from'], true)) {
                throw new RuntimeException('This refund was already updated by someone else. Refresh and try again.');
            }
            $refund = $fresh;
            $fromStatus = $refund->status;

            $now = now();
            switch ($action) {
                case 'submit':
                    $refund->status = $spec['to'];
                    $refund->submitted_at = $now;
                    $refund->submitted_by = $actor->id;
                    break;
                case 'start-review':
                    $refund->status = $spec['to'];
                    $refund->review_started_at = $now;
                    $refund->reviewed_by = $actor->id;
                    break;
                case 'approve':
                    $refund->status = $spec['to'];
                    $refund->approved_at = $now;
                    $refund->approved_by = $actor->id;
                    break;
                case 'reject':
                    $refund->status = $spec['to'];
                    $refund->rejected_at = $now;
                    $refund->rejected_by = $actor->id;
                    if ($remarks === '') {
                        throw new InvalidArgumentException('A rejection reason is required.');
                    }
                    $refund->rejection_reason = $remarks;
                    break;
                case 'cancel':
                    $refund->status = $spec['to'];
                    $refund->cancelled_at = $now;
                    $refund->cancelled_by = $actor->id;
                    $refund->cancellation_reason = $remarks !== '' ? $remarks : null;
                    break;
                case 'void':
                    $refund->status = $spec['to'];
                    $refund->voided_at = $now;
                    $refund->voided_by = $actor->id;
                    if ($remarks === '') {
                        throw new InvalidArgumentException('A void reason is required.');
                    }
                    $refund->void_reason = $remarks;
                    break;
            }

            $refund->save();
            $this->writeAudit($refund, $actor, $action, $fromStatus, $refund->status, $remarks !== '' ? $remarks : null);

            if ($action === 'approve') {
                $this->payslipService->refreshDraftPayslipsForRefund($refund);
            }

            return $refund->fresh(['employee', 'audits']);
        });
    }

    public function canManage(User $actor, RefundRequest $refund): bool
    {
        try {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $refund->employee()->firstOrFail());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function writeAudit(RefundRequest $refund, ?User $actor, string $action, ?string $fromStatus, ?string $toStatus, ?string $remarks): RefundRequestAudit
    {
        $audit = RefundRequestAudit::create([
            'refund_request_id' => $refund->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'remarks' => $remarks,
            'snapshot' => [
                'amounts' => [
                    'original' => (float) $refund->original_amount,
                    'corrected' => (float) $refund->corrected_amount,
                    'refund' => (float) $refund->refund_amount,
                ],
                'reason' => $refund->reason,
                'direction' => $refund->direction,
                'affected_date' => optional($refund->affected_date)->toDateString(),
            ],
        ]);
        $audit->setRelation('refundRequest', $refund);
        $audit->setRelation('user', $actor);

        return $audit;
    }
}
