<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Services\DataScopeService;
use App\Services\RefundCalculationService;
use App\Services\RefundWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundCalculationService $calculationService,
        private readonly RefundWorkflowService $workflow,
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * Tabbed list for the Refunds & Adjustments module.
     * Tabs: requests | pending_approval | processed | history (client-side mapping of statuses).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'string', 'max:32'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $query = RefundRequest::query()
            ->with(['employee:id,first_name,last_name,employee_code,company_id,branch_id,department_id,employment_type'])
            ->orderByDesc('id');

        $statuses = $this->statusesForFilter($validated['status'] ?? null);
        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }
        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (! empty($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }
        if (! empty($validated['employee_id'])) {
            $query->where('employee_id', (int) $validated['employee_id']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('affected_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('affected_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('refund_number', 'like', $term)
                    ->orWhereHas('employee', function ($eq) use ($term) {
                        $eq->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('employee_code', 'like', $term);
                    });
            });
        }

        $this->applyDataScope($actor, $query);

        $perPage = in_array((int) ($validated['per_page'] ?? 25), [10, 25, 50], true) ? (int) ($validated['per_page'] ?? 25) : 25;

        return response()->json([
            'data' => $query->paginate($perPage)->through(fn (RefundRequest $r) => $this->serialize($r)),
            'counts' => $this->countsByTab($actor),
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json($this->countsByTab($actor));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validatePayload($request);
        /** @var User $actor */
        $actor = $request->user();
        $submit = $request->boolean('submit');

        try {
            $refund = $this->workflow->createOrUpdate($actor, null, $input, $submit);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $submit ? 'Refund request submitted for review.' : 'Refund draft saved.',
            'data' => $this->detailPayload($refund),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $refund = RefundRequest::findOrFail($id);
        $input = $this->validatePayload($request);
        /** @var User $actor */
        $actor = $request->user();
        $submit = $request->boolean('submit');

        try {
            $updated = $this->workflow->createOrUpdate($actor, $refund, $input, $submit);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $submit ? 'Refund request submitted for review.' : 'Refund draft updated.',
            'data' => $this->detailPayload($updated),
        ]);
    }

    /** Engine-backed calculation preview shown BEFORE submission. */
    public function preview(Request $request): JsonResponse
    {
        $input = $this->validatePayload($request);

        try {
            $preview = $this->calculationService->preview($request->user(), $input);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $preview['employee'] = [
            'id' => $preview['employee']->id,
            'name' => trim(($preview['employee']->first_name ?? '').' '.($preview['employee']->last_name ?? '')),
            'employee_code' => $preview['employee']->employee_code,
            'company_id' => $preview['employee']->getEffectiveCompanyId(),
            'branch_id' => $preview['employee']->branch_id,
            'department_id' => $preview['employee']->department_id,
            'employment_type' => $preview['employee']->employment_type,
        ];

        return response()->json(['data' => $preview]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $refund = RefundRequest::with(['audits.user:id,first_name,last_name', 'employee'])->findOrFail($id);
        $scoped = RefundRequest::query()->whereKey($id);
        $this->applyDataScope($request->user(), $scoped);
        if (! $scoped->exists()) {
            return response()->json(['message' => 'Refund request not found or not in your scope.'], 403);
        }

        return response()->json(['data' => $this->detailPayload($refund)]);
    }

    public function transition(Request $request, int $id, string $action): JsonResponse
    {
        if (! array_key_exists($action, RefundWorkflowService::TRANSITIONS)) {
            return response()->json(['message' => "Unknown refund action '{$action}'."], 404);
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'batch_run_id' => ['nullable', 'integer', 'exists:payroll_batch_runs,id'],
        ]);

        $refund = RefundRequest::findOrFail($id);

        try {
            $updated = $this->workflow->transition($request->user(), $refund, $action, $validated);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('refund.transition_failed', ['refund_id' => $id, 'action' => $action, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to update refund request.'], 500);
        }

        return response()->json([
            'message' => ucfirst(str_replace('-', ' ', $action)).' successful.',
            'data' => $this->detailPayload($updated),
        ]);
    }

    /** Approved OT / leave rows for the create-refund pickers. */
    public function correctionContext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'affected_date' => ['required', 'date'],
        ]);

        /** @var User $employee */
        $employee = User::query()->findOrFail((int) $validated['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        $date = (string) $validated['affected_date'];

        $overtimes = Overtime::query()
            ->where('user_id', $employee->id)
            ->where('status', Overtime::STATUS_APPROVED)
            ->whereDate('date', $date)
            ->orderByDesc('id')
            ->get(['id', 'date', 'approved_ot_hours', 'approved_ot_start', 'approved_ot_end', 'reason'])
            ->map(fn (Overtime $ot) => [
                'id' => $ot->id,
                'date' => optional($ot->date)->toDateString(),
                'hours' => (float) ($ot->approved_ot_hours ?? 0),
                'start_time' => $ot->approved_ot_start,
                'end_time' => $ot->approved_ot_end,
                'reason' => $ot->reason,
                'label' => sprintf('OT #%d — %.2f hr', $ot->id, (float) ($ot->approved_ot_hours ?? 0)),
            ]);

        $leaves = LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->orderByDesc('id')
            ->get(['id', 'type', 'start_date', 'end_date', 'notes'])
            ->map(fn (LeaveRequest $leave) => [
                'id' => $leave->id,
                'type' => $leave->type,
                'leave_type' => $leave->type,
                'start_date' => optional($leave->start_date)->toDateString(),
                'end_date' => optional($leave->end_date)->toDateString(),
                'reason' => $leave->notes,
                'label' => sprintf(
                    'Leave #%d — %s (%s → %s)',
                    $leave->id,
                    $leave->type,
                    optional($leave->start_date)->toDateString(),
                    optional($leave->end_date)->toDateString()
                ),
            ]);

        return response()->json([
            'data' => [
                'approved_overtimes' => $overtimes->values()->all(),
                'approved_leaves' => $leaves->values()->all(),
            ],
        ]);
    }

    /** Employee self-service view: My Payroll → Payroll Adjustments (spec §13). */
    public function myAdjustments(Request $request): JsonResponse
    {
        $refunds = RefundRequest::query()
            ->where('employee_id', $request->user()->id)
            ->whereIn('status', [
                ...RefundRequest::PAYROLL_PENDING_STATUSES,
                RefundRequest::STATUS_PROCESSED,
                RefundRequest::STATUS_VOIDED,
            ])
            ->with('processedBatchRun:id,pay_period_start,pay_period_end,status')
            ->orderByDesc('processed_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $refunds->map(fn (RefundRequest $r) => [
                'id' => $r->id,
                'refund_number' => $r->refund_number,
                'reason_label' => $r->reasonLabel(),
                'category' => $r->category,
                'affected_date' => $r->affected_date?->toDateString(),
                'amount' => (float) $r->refund_amount,
                'direction' => $r->direction,
                'status' => $r->status,
                'processed_at' => $r->processed_at?->toIso8601String(),
                'applied_payroll_period' => $r->processedBatchRun ? [
                    'start' => $r->processedBatchRun->pay_period_start?->toDateString(),
                    'end' => $r->processedBatchRun->pay_period_end?->toDateString(),
                    'status' => $r->processedBatchRun->status,
                ] : null,
            ]),
        ]);
    }

    private function validatePayload(Request $request): array
    {
        $reasons = array_column(RefundRequest::reasonOptions(), 'value');
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', Rule::in($reasons)],
            'affected_date' => ['required', 'date'],
            'affected_date_to' => ['nullable', 'date', 'after_or_equal:affected_date'],
            'cutoff_start_date' => ['nullable', 'date'],
            'cutoff_end_date' => ['nullable', 'date', 'after_or_equal:cutoff_start_date'],
            'direct_refund_amount' => ['nullable', 'numeric', 'not_in:0', 'min:-99999999', 'max:99999999'],
            'correction_payload' => ['nullable', 'array'],
            'correction_payload.time_in' => ['nullable', 'date_format:H:i'],
            'correction_payload.time_out' => ['nullable', 'date_format:H:i'],
            'correction_payload.overtime_id' => ['nullable', 'integer', 'exists:overtimes,id'],
            'correction_payload.leave_request_id' => ['nullable', 'integer', 'exists:leave_requests,id'],
            'manual_corrected_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason_notes' => ['nullable', 'string', 'max:65535'],
            'submit' => ['nullable', 'boolean'],
        ]);

        unset($validated['submit']);

        return $validated;
    }

    /**
     * Map a tab/filter token onto the concrete status list.
     *
     * @return list<string>|null
     */
    private function statusesForFilter(?string $filter): ?array
    {
        return match ($filter) {
            'requests' => [
                RefundRequest::STATUS_DRAFT,
                RefundRequest::STATUS_SUBMITTED,
                RefundRequest::STATUS_PAYROLL_REVIEW,
            ],
            'pending_approval' => [RefundRequest::STATUS_PAYROLL_REVIEW],
            'approved' => [
                RefundRequest::STATUS_APPROVED,
                RefundRequest::STATUS_QUEUED_FOR_PAYROLL,
            ],
            'processed' => [RefundRequest::STATUS_PROCESSED],
            'history' => [
                RefundRequest::STATUS_PROCESSED,
                RefundRequest::STATUS_REJECTED,
                RefundRequest::STATUS_CANCELLED,
                RefundRequest::STATUS_VOIDED,
            ],
            default => null,
        };
    }

    private function countsByTab(User $actor): array
    {
        $base = RefundRequest::query();
        $this->applyDataScope($actor, $base);

        $rows = (clone $base)->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $get = fn (array $statuses) => collect($statuses)->sum(fn ($s) => (int) ($rows[$s] ?? 0));

        return [
            'all' => (int) $rows->sum(),
            'requests' => $get([RefundRequest::STATUS_DRAFT, RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_PAYROLL_REVIEW]),
            'pending_approval' => $get([RefundRequest::STATUS_SUBMITTED, RefundRequest::STATUS_PAYROLL_REVIEW]),
            'approved' => $get([
                RefundRequest::STATUS_APPROVED,
                RefundRequest::STATUS_QUEUED_FOR_PAYROLL,
            ]),
            'processed' => $get([RefundRequest::STATUS_PROCESSED]),
            'history' => $get([
                RefundRequest::STATUS_PROCESSED,
                RefundRequest::STATUS_REJECTED,
                RefundRequest::STATUS_CANCELLED,
                RefundRequest::STATUS_VOIDED,
            ]),
        ];
    }

    private function applyDataScope(User $actor, $query): void
    {
        // null = unrestricted (Admin HR / super admin) scope; otherwise restrict to visible employees.
        $scopeUserIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'refunds');
        if ($scopeUserIds !== null) {
            $query->whereIn('employee_id', $scopeUserIds);
        }
    }

    private function serialize(RefundRequest $r): array
    {
        $employee = $r->employee;

        return [
            'id' => $r->id,
            'refund_number' => $r->refund_number,
            'employee_id' => $r->employee_id,
            'employee_name' => $employee ? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')) : null,
            'employee_code' => $employee?->employee_code,
            'company_id' => $r->company_id,
            'branch_id' => $r->branch_id,
            'department_id' => $r->department_id,
            'direction' => $r->direction,
            'direction_label' => $r->directionLabel(),
            'category' => $r->category,
            'reason' => $r->reason,
            'reason_label' => $r->reasonLabel(),
            'affected_date' => $r->affected_date?->toDateString(),
            'affected_date_to' => $r->affected_date_to?->toDateString(),
            'cutoff_start_date' => $r->cutoff_start_date?->toDateString(),
            'cutoff_end_date' => $r->cutoff_end_date?->toDateString(),
            'original_amount' => (float) $r->original_amount,
            'corrected_amount' => (float) $r->corrected_amount,
            'refund_amount' => (float) $r->refund_amount,
            'status' => $r->status,
            'status_label' => $r->statusLabel(),
            'finalized_original_payroll' => $r->calculation['finalized'] ?? false,
            'created_by_name' => $r->createdBy ? trim(($r->createdBy->first_name ?? '').' '.($r->createdBy->last_name ?? '')) : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'approved_by_name' => $r->approvedBy ? trim(($r->approvedBy->first_name ?? '').' '.($r->approvedBy->last_name ?? '')) : null,
            'queued_at' => $r->queued_at?->toIso8601String(),
            'processed_at' => $r->processed_at?->toIso8601String(),
            'processed_batch_run_id' => $r->processed_batch_run_id,
            'rejected_at' => $r->rejected_at?->toIso8601String(),
            'rejection_reason' => $r->rejection_reason,
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'voided_at' => $r->voided_at?->toIso8601String(),
        ];
    }

    private function detailPayload(RefundRequest $r): array
    {
        $payload = $this->serialize($r);
        $payload['reason_notes'] = $r->reason_notes;
        $payload['correction_payload'] = $r->correction_payload;
        $payload['calculation'] = $r->calculation;

        $payload['audit_trail'] = $r->audits->map(fn ($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'from_status' => $a->from_status,
            'to_status' => $a->to_status,
            'remarks' => $a->remarks,
            'snapshot' => $a->snapshot,
            'user_name' => $a->user ? trim(($a->user->first_name ?? '').' '.($a->user->last_name ?? '')) : null,
            'user_id' => $a->user_id,
            'created_at' => $a->created_at?->toIso8601String(),
        ])->values()->all();

        return $payload;
    }
}
