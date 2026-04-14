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
     * Persist payroll periods, generate payslip PDFs, lock periods, record {@see \App\Models\PayrollBatchRun}.
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
                FinalizePayrollJob::dispatch((int) $run->id, (int) $user->id)->onQueue('default');
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
            'error_message' => $run->error_message,
            'totals' => [
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'employee_count' => (int) $run->employee_count,
            ],
            'queued_at' => $run->queued_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'finalized_at' => $run->finalized_at?->toIso8601String(),
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
