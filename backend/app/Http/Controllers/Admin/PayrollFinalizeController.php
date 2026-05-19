<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizePayrollJob;
use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Services\FinalizePayrollService;
use App\Services\HrRoleResolver;
use App\Services\PayslipDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Finalize payroll batch + payslip release (delivered_at) — Laravel admins and HR-panel org heads
 * (see {@see ensurePayrollFinalizeAccess}).
 */
class PayrollFinalizeController extends Controller
{
    public function __construct(
        private readonly FinalizePayrollService $finalizePayrollService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayslipDeliveryService $payslipDeliveryService,
    ) {}

    /**
     * DRAFT review: totals + per-employee lines from {@see PayrollComputationService} (no DB writes).
     */
    public function preview(Request $request): JsonResponse
    {
        $previewStartedAt = microtime(true);
        $this->ensurePayrollFinalizeAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
            'refresh_token' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $v = $this->applyCompanyHeadDefaultScope($request, $v);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id'])
            || ! empty($v['employee_id']);
        if ($request->user()?->isAdmin()) {
            abort_unless($scoped, 422, 'Select company, branch, department, or a single employee.');
        }

        $periodInput = [
            'from_date' => $v['from_date'] ?? null,
            'to_date' => $v['to_date'] ?? null,
            'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
            'reference_date' => $v['reference_date'] ?? null,
            'use_company_default' => (bool) ($v['use_company_default'] ?? false),
            'payroll_period_id' => $v['payroll_period_id'] ?? null,
            'is_final_pay' => $v['is_final_pay'] ?? false,
            'password_protect' => (bool) ($v['password_protect'] ?? false),
            'refresh_token' => $v['refresh_token'] ?? null,
        ];

        // Reference date normalization is handled inside FinalizePayrollService (scope-safe).

        try {
            Log::info('Payroll finalize preview: request accepted', [
                'actor_id' => $request->user()?->id,
                'company_id' => $v['company_id'] ?? null,
                'branch_id' => $v['branch_id'] ?? null,
                'department_id' => $v['department_id'] ?? null,
                'employee_id' => $v['employee_id'] ?? null,
                'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
                'payroll_period_id' => $v['payroll_period_id'] ?? null,
                'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
            ]);

            $data = $this->finalizePayrollService->preview(
                isset($v['company_id']) ? (int) $v['company_id'] : null,
                isset($v['branch_id']) ? (int) $v['branch_id'] : null,
                isset($v['department_id']) ? (int) $v['department_id'] : null,
                isset($v['employee_id']) ? (int) $v['employee_id'] : null,
                $periodInput,
                $request->user(),
                isset($v['page']) ? (int) $v['page'] : 1,
                isset($v['per_page']) ? (int) $v['per_page'] : 12,
                isset($v['search']) ? (string) $v['search'] : null,
            );

            Log::info('Payroll finalize preview: response ready', [
                'employee_count' => (int) (($data['totals']['employee_count'] ?? 0)),
                'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Payroll finalize preview: runtime exception', [
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'preview' => 'payroll_finalize',
            ...$data,
        ]);
    }

    /**
     * Queue payroll finalization (persist periods, lock window, batch audit). Payslip PDFs are generated asynchronously on `payslip-pdf`.
     */
    public function execute(Request $request): JsonResponse
    {
        $executeStartedAt = microtime(true);
        $this->ensurePayrollFinalizeAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
            'review_confirmed' => ['required', 'accepted'],
        ]);

        $v = $this->applyCompanyHeadDefaultScope($request, $v);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id'])
            || ! empty($v['employee_id']);
        if ($request->user()?->isAdmin()) {
            abort_unless($scoped, 422, 'Select company, branch, department, or a single employee.');
        }

        $periodInput = [
            'from_date' => $v['from_date'] ?? null,
            'to_date' => $v['to_date'] ?? null,
            'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
            'reference_date' => $v['reference_date'] ?? null,
            'use_company_default' => (bool) ($v['use_company_default'] ?? false),
            'payroll_period_id' => $v['payroll_period_id'] ?? null,
            'is_final_pay' => $v['is_final_pay'] ?? false,
            'password_protect' => (bool) ($v['password_protect'] ?? false),
        ];

        // Reference date normalization is handled inside FinalizePayrollService (scope-safe).

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            Log::info('Payroll finalize execute: request accepted', [
                'actor_id' => $request->user()?->id,
                'company_id' => $v['company_id'] ?? null,
                'branch_id' => $v['branch_id'] ?? null,
                'department_id' => $v['department_id'] ?? null,
                'employee_id' => $v['employee_id'] ?? null,
                'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
                'payroll_period_id' => $v['payroll_period_id'] ?? null,
                'elapsed_ms' => round((microtime(true) - $executeStartedAt) * 1000, 2),
            ]);
            $queued = $this->finalizePayrollService->queueFinalizeBatch(
                isset($v['company_id']) ? (int) $v['company_id'] : null,
                isset($v['branch_id']) ? (int) $v['branch_id'] : null,
                isset($v['department_id']) ? (int) $v['department_id'] : null,
                isset($v['employee_id']) ? (int) $v['employee_id'] : null,
                $periodInput,
                (int) $user->id,
                $request->user(),
            );
            $run = $queued['run'];
            if (($queued['should_dispatch'] ?? false) === true) {
                FinalizePayrollJob::dispatch((int) $run->id, (int) $user->id)
                    ->onConnection('redis')
                    ->onQueue('payroll');
            }
            Log::info('Payroll finalize execute queued', [
                'batch_run_id' => (int) $run->id,
                'batch_key' => $run->batch_key,
                'status' => $run->status,
                'should_dispatch' => (bool) ($queued['should_dispatch'] ?? false),
                'company_id' => $v['company_id'] ?? null,
                'branch_id' => $v['branch_id'] ?? null,
                'department_id' => $v['department_id'] ?? null,
                'employee_id' => $v['employee_id'] ?? null,
                'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
                'elapsed_ms' => round((microtime(true) - $executeStartedAt) * 1000, 2),
            ]);
        } catch (\RuntimeException $e) {
            Log::warning('Payroll finalize execute: runtime exception', [
                'message' => $e->getMessage(),
                'elapsed_ms' => round((microtime(true) - $executeStartedAt) * 1000, 2),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Finalizing in background...',
            'queued' => true,
            'payroll_batch_run_id' => (int) $run->id,
            'status' => $run->status,
            'progress_status' => $this->progressStatusForRun($run),
            'progress' => $this->progressPayloadForRun($run),
        ], 202);
    }

    public function executeStatus(Request $request, int $batchRunId): JsonResponse
    {
        $this->ensurePayrollFinalizeAccess($request);
        $run = PayrollBatchRun::query()->findOrFail($batchRunId);
        Log::info('Payroll finalize execute status fetch', [
            'batch_run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'company_id' => $run->company_id,
            'pay_period_start' => $run->pay_period_start?->toDateString(),
            'pay_period_end' => $run->pay_period_end?->toDateString(),
        ]);

        return response()->json([
            'payroll_batch_run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'progress_status' => $this->progressStatusForRun($run),
            'error_message' => $run->error_message,
            'progress' => $this->progressPayloadForRun($run),
            'totals' => [
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'employee_count' => max((int) $run->employee_count, (int) ($run->total_employees ?? 0)),
            ],
            'queued_at' => $run->queued_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'finalized_at' => $run->finalized_at?->toIso8601String(),
        ]);
    }

    private function progressPayloadForRun(PayrollBatchRun $run): array
    {
        $total = max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0));
        $processed = max(0, (int) ($run->processed_employees ?? 0));
        $failed = max(0, (int) ($run->failed_employees ?? 0));

        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED || (string) $run->status === PayrollBatchRun::STATUS_DRAFT) {
            $processed = max($processed, $total, (int) ($run->employee_count ?? 0));
            $total = max($total, $processed);
        }

        return [
            'total_employees' => $total,
            'processed_employees' => $processed,
            'failed_employees' => $failed,
            'percent' => $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0,
        ];
    }

    private function progressStatusForRun(PayrollBatchRun $run): string
    {
        return match ((string) $run->status) {
            PayrollBatchRun::STATUS_QUEUED => 'pending',
            PayrollBatchRun::STATUS_PROCESSING => 'processing',
            PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FINALIZED => 'completed',
            PayrollBatchRun::STATUS_VOIDED => 'voided',
            PayrollBatchRun::STATUS_FAILED => 'failed',
            default => 'pending',
        };
    }

    public function deleteBatch(Request $request, int $batchRunId): JsonResponse
    {
        $this->ensurePayrollFinalizeAccess($request);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $v = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->finalizePayrollService->deleteFinalizedPayrollBatch(
                $batchRunId,
                $actor,
                isset($v['reason']) ? (string) $v['reason'] : null
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Finalized payroll batch voided. Snapshot values were preserved and the batch was not converted back to draft.',
            ...$result,
        ]);
    }

    /**
     * Finalize a single employee payslip for the current scope + pay window (sync; no queue).
     */
    public function finalizeEmployee(Request $request): JsonResponse
    {
        $this->ensurePayrollFinalizeAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
            'confirm' => ['required', 'accepted'],
        ]);

        $v = $this->applyCompanyHeadDefaultScope($request, $v);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id']);
        if ($request->user()?->isAdmin()) {
            abort_unless($scoped, 422, 'Select company, branch, or department scope.');
        }

        $periodInput = [
            'from_date' => $v['from_date'] ?? null,
            'to_date' => $v['to_date'] ?? null,
            'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
            'reference_date' => $v['reference_date'] ?? null,
            'use_company_default' => (bool) ($v['use_company_default'] ?? false),
            'payroll_period_id' => $v['payroll_period_id'] ?? null,
            'is_final_pay' => $v['is_final_pay'] ?? false,
            'password_protect' => (bool) ($v['password_protect'] ?? false),
        ];

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $result = $this->finalizePayrollService->finalizeEmployeePayslip(
                isset($v['company_id']) ? (int) $v['company_id'] : null,
                isset($v['branch_id']) ? (int) $v['branch_id'] : null,
                isset($v['department_id']) ? (int) $v['department_id'] : null,
                (int) $v['employee_id'],
                $periodInput,
                (int) $user->id,
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Employee payslip finalized.',
            'payslip_id' => $result['payslip_id'],
            'user_id' => $result['user_id'],
            'batch_all_finalized' => (bool) $result['batch_all_finalized'],
        ]);
    }

    /**
     * Mark finalized payslips as delivered (`delivered_at`) and status {@see \App\Models\Payslip::STATUS_SENT_FINALIZED} for My Payslips.
     * Same access as finalize; respects org scope (company / branch / department).
     */
    public function deliverPayslips(Request $request): JsonResponse
    {
        $this->ensurePayrollFinalizeAccess($request);
        $v = $request->validate([
            'payslip_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payslip_ids.*' => ['integer', 'exists:payslips,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $v = $this->applyCompanyHeadDefaultScope($request, $v);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $this->payslipDeliveryService->deliverPayslips(
            $v['payslip_ids'],
            isset($v['company_id']) ? (int) $v['company_id'] : null,
            isset($v['branch_id']) ? (int) $v['branch_id'] : null,
            isset($v['department_id']) ? (int) $v['department_id'] : null,
            $actor
        );

        return response()->json([
            'message' => 'Payslips sent (visible in My Payslips).',
            'delivered' => $result['delivered'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Send all payslips for one finalized payroll batch run.
     */
    public function bulkSendPayslips(Request $request, int $batchId): JsonResponse
    {
        $this->ensurePayrollFinalizeAccess($request);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $result = $this->payslipDeliveryService->deliverFinalizedBatchPayslips($batchId, $actor);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payslips sent for finalized batch.',
            'batch_id' => $result['batch_id'],
            'targeted' => $result['targeted'],
            'delivered' => $result['delivered'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    private function ensurePayrollFinalizeAccess(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 403);
        if ($u->isAdmin()) {
            return;
        }
        abort_unless($this->hrRoleResolver->resolve($u)->canAccessHrPanel(), 403);
    }

    /**
     * Company Head fallback: when no explicit scope is sent, default to effective company.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyCompanyHeadDefaultScope(Request $request, array $validated): array
    {
        $u = $request->user();
        if (! $u || $u->isAdmin()) {
            return $validated;
        }

        $hasExplicitScope = ! empty($validated['company_id'])
            || ! empty($validated['branch_id'])
            || ! empty($validated['department_id'])
            || ! empty($validated['employee_id']);
        if ($hasExplicitScope) {
            return $validated;
        }

        if ($this->hrRoleResolver->resolve($u)->value !== 'company_head') {
            return $validated;
        }

        $effectiveCompanyId = $u->getEffectiveCompanyId();
        if ($effectiveCompanyId !== null && $effectiveCompanyId > 0) {
            $validated['company_id'] = (int) $effectiveCompanyId;
        }

        return $validated;
    }
}
