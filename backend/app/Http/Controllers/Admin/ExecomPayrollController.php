<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizePayrollJob;
use App\Jobs\GeneratePayrollBatchJob;
use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\PayrollEmployeeEligibilityService;
use App\Services\PayrollReportService;
use App\Services\PayslipService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ExecomPayrollController extends Controller
{
    public function __construct(
        private readonly PayslipService $payslipService,
        private readonly PayrollEmployeeEligibilityService $payrollEligibility,
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollReportService $payrollReportService,
    ) {}

    public function generateDraft(Request $request): JsonResponse
    {
        $validated = $this->validatedPayrollScope($request);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $from = Carbon::parse((string) $validated['from_date'])->startOfDay();
        $to = Carbon::parse((string) $validated['to_date'])->startOfDay();
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $employeeIds = ! empty($validated['employee_id']) ? [(int) $validated['employee_id']] : null;

        $eligible = $this->payrollEligibility->query(
            $companyId,
            $branchId,
            $departmentId,
            $from,
            $to,
            $actor,
            $this->dataScopeService,
            PayrollBatchRun::MODULE_EXECOM
        );
        if ($employeeIds !== null) {
            $eligible->whereIn('users.id', $employeeIds);
        }
        $employeeCount = (int) (clone $eligible)->distinct('users.id')->count('users.id');
        abort_if($employeeCount < 1, 422, 'No active EXECOM employees in the selected scope.');

        $batchKey = 'execom_'.$this->makeBatchKey($companyId, $branchId, $departmentId, $employeeIds[0] ?? null, $from, $to, $validated['pay_cycle_id'] ?? null);
        $scopeBatchKey = PayrollBatchRun::buildScopeBatchKey(
            PayrollBatchRun::MODULE_EXECOM,
            $companyId,
            $branchId,
            $departmentId,
            $employeeIds[0] ?? null,
            $from,
            $to,
            $validated['pay_cycle_id'] ?? null
        );
        $legacyScopeBatchKey = PayrollBatchRun::buildLegacyScopeBatchKey(
            $companyId,
            $branchId,
            $departmentId,
            $employeeIds[0] ?? null,
            $from,
            $to,
            $validated['pay_cycle_id'] ?? null
        );

        $conflictingFinalized = PayrollBatchRun::findConflictingFinalizedBatch(
            PayrollBatchRun::MODULE_EXECOM,
            $companyId,
            $branchId,
            $departmentId,
            $employeeIds[0] ?? null,
            $from,
            $to
        );
        if ($conflictingFinalized instanceof PayrollBatchRun) {
            abort(
                422,
                'This EXECOM payroll period is already finalized (batch #'.$conflictingFinalized->id.'). Void that batch before generating a new draft.'
            );
        }

        $existing = PayrollBatchRun::query()
            ->whereIn('batch_key', [$batchKey, $scopeBatchKey, $legacyScopeBatchKey])
            ->where('status', '!=', PayrollBatchRun::STATUS_VOIDED)
            ->orderByDesc('id')
            ->first();
        if ($existing instanceof PayrollBatchRun && (string) $existing->status === PayrollBatchRun::STATUS_FINALIZED) {
            abort(422, 'This EXECOM payroll period is already finalized. Void the finalized batch before generating a new draft.');
        }
        if ($existing instanceof PayrollBatchRun
            && $existing->isActiveBackgroundGeneration()
            && ! $existing->isStaleBackgroundGeneration()) {
            $isProcessing = (string) $existing->status === PayrollBatchRun::STATUS_PROCESSING;

            return response()->json([
                'message' => $isProcessing
                    ? 'EXECOM payroll draft is already processing.'
                    : 'EXECOM payroll draft generation is already queued.',
                'queued' => true,
                'payroll_batch_run_id' => (int) $existing->id,
                'status' => (string) $existing->status,
                'progress_status' => $isProcessing ? 'processing' : 'pending',
                'pay_period_start' => $from->toDateString(),
                'pay_period_end' => $to->toDateString(),
                'employee_count' => max((int) ($existing->employee_count ?? 0), (int) ($existing->total_employees ?? 0)),
                'total_employees' => max((int) ($existing->total_employees ?? 0), (int) ($existing->employee_count ?? 0)),
                'processed_employees' => (int) ($existing->processed_employees ?? 0),
                'generated_count' => (int) ($existing->processed_employees ?? 0),
                'skipped_count' => 0,
                'failed_count' => (int) ($existing->failed_employees ?? 0),
            ], 202);
        }

        $run = PayrollBatchRun::query()->updateOrCreate(
            ['batch_key' => $scopeBatchKey],
            [
                'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'employee_id' => $employeeIds[0] ?? null,
                'pay_period_start' => $from->toDateString(),
                'pay_period_end' => $to->toDateString(),
                'pay_cycle_id' => $validated['pay_cycle_id'] ?? null,
                'payroll_period_id' => $validated['payroll_period_id'] ?? null,
                'is_final_pay' => (bool) ($validated['is_final_pay'] ?? false),
                'password_protect' => (bool) ($validated['password_protect'] ?? false),
                'include_thirteenth_month' => (bool) ($validated['include_13th_month_pay'] ?? $validated['include_thirteenth_month'] ?? false),
                'include_13th_month_pay' => (bool) ($validated['include_13th_month_pay'] ?? $validated['include_thirteenth_month'] ?? false),
                'reference_date' => isset($validated['reference_date']) ? Carbon::parse((string) $validated['reference_date'])->toDateString() : null,
                'status' => PayrollBatchRun::STATUS_QUEUED,
                'employee_count' => $employeeCount,
                'total_employees' => $employeeCount,
                'processed_employees' => 0,
                'failed_employees' => 0,
                'queued_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'finalized_by_user_id' => (int) $actor->id,
                'finalized_at' => null,
                'voided_at' => null,
                'voided_by_user_id' => null,
                'void_reason' => null,
                'total_gross' => 0,
                'total_deductions' => 0,
                'total_net' => 0,
            ]
        );

        GeneratePayrollBatchJob::dispatch((int) $run->id, (int) $actor->id)
            ->onConnection('redis')
            ->onQueue('payroll');

        return response()->json([
            'message' => 'EXECOM payroll draft generation queued.',
            'queued' => true,
            'payroll_batch_run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'progress_status' => 'pending',
            'pay_period_start' => $from->toDateString(),
            'pay_period_end' => $to->toDateString(),
            'employee_count' => $employeeCount,
            'total_employees' => $employeeCount,
            'processed_employees' => 0,
            'generated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
        ], 202);
    }

    public function recomputeDraft(Request $request, int $batchRunId): JsonResponse
    {
        $run = $this->execomRun($batchRunId);
        abort_if((string) $run->status === PayrollBatchRun::STATUS_FINALIZED, 422, 'Cannot recompute finalized EXECOM payroll.');

        Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', PayrollBatchRun::MODULE_EXECOM)
            ->where('status', Payslip::STATUS_DRAFT)
            ->delete();

        $run->update([
            'status' => PayrollBatchRun::STATUS_QUEUED,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'processed_employees' => 0,
            'failed_employees' => 0,
            'error_message' => null,
        ]);

        GeneratePayrollBatchJob::dispatch((int) $run->id, (int) $request->user()?->id)
            ->onConnection('redis')
            ->onQueue('payroll');

        return response()->json([
            'message' => 'EXECOM payroll draft recompute queued.',
            'payroll_batch_run_id' => (int) $run->id,
            'status' => PayrollBatchRun::STATUS_QUEUED,
        ], 202);
    }

    public function viewDraft(Request $request): JsonResponse
    {
        return $this->payslipList($request, Payslip::STATUS_DRAFT);
    }

    public function viewFinalized(Request $request): JsonResponse
    {
        return $this->payslipList($request, Payslip::lockingStatuses());
    }

    public function batchPayslips(Request $request, int $batchRunId): JsonResponse
    {
        $run = $this->execomRun($batchRunId);

        return $this->payslipListForRun($request, $run);
    }

    public function finalize(Request $request, int $batchRunId): JsonResponse
    {
        $run = $this->execomRun($batchRunId);
        abort_if((string) $run->status === PayrollBatchRun::STATUS_FINALIZED, 422, 'EXECOM payroll is already finalized.');
        abort_unless(
            in_array((string) $run->status, [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FAILED], true),
            422,
            'Only draft or failed EXECOM payroll can be finalized.'
        );

        $run->forceFill([
            'status' => PayrollBatchRun::STATUS_QUEUED,
            'error_message' => null,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'processed_employees' => 0,
            'failed_employees' => 0,
            'total_employees' => max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0)),
            'finalized_by_user_id' => (int) $request->user()?->id,
            'finalized_at' => null,
        ])->save();

        FinalizePayrollJob::dispatch((int) $run->id, (int) $request->user()?->id)
            ->onConnection('redis')
            ->onQueue('payroll');

        $run = $run->fresh() ?? $run;

        return response()->json([
            'message' => 'Finalizing in background...',
            'queued' => true,
            'payroll_batch_run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'progress_status' => $this->progressStatusForRun($run),
            'progress' => $this->progressPayloadForRun($run),
        ], 202);
    }

    public function batchStatus(int $batchRunId): JsonResponse
    {
        $run = $this->execomRun($batchRunId);
        $aggregate = $this->payslipService->aggregateForBatchRun($run, false);
        $payslipCount = (int) ($aggregate['payslip_count'] ?? 0);
        $isVoided = (string) $run->status === PayrollBatchRun::STATUS_VOIDED;
        $useStoredTotals = ! $isVoided && $payslipCount === 0 && in_array(
            (string) $run->status,
            [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_QUEUED, PayrollBatchRun::STATUS_PROCESSING, PayrollBatchRun::STATUS_FAILED],
            true
        );

        return response()->json([
            ...$run->toArray(),
            'aggregate' => $aggregate,
            'progress_status' => $this->progressStatusForRun($run),
            'error_message' => $run->error_message,
            'progress' => $this->progressPayloadForRun($run),
            'totals' => [
                'total_gross' => $payslipCount > 0
                    ? (float) $aggregate['total_gross_pay']
                    : ($useStoredTotals ? (float) ($run->total_gross ?? 0) : 0.0),
                'total_deductions' => $payslipCount > 0
                    ? (float) $aggregate['total_deductions']
                    : ($useStoredTotals ? (float) ($run->total_deductions ?? 0) : 0.0),
                'total_net' => $payslipCount > 0
                    ? (float) $aggregate['total_net_pay']
                    : ($useStoredTotals ? (float) ($run->total_net ?? 0) : 0.0),
                'employee_count' => $payslipCount > 0
                    ? $payslipCount
                    : ($useStoredTotals ? max((int) ($run->employee_count ?? 0), (int) ($run->total_employees ?? 0)) : 0),
            ],
        ]);
    }

    private function progressPayloadForRun(PayrollBatchRun $run): array
    {
        $status = (string) $run->status;
        $total = max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0));
        $processed = max(0, (int) ($run->processed_employees ?? 0));
        $failed = max(0, (int) ($run->failed_employees ?? 0));

        if (in_array($status, [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FINALIZED], true)) {
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

    public function recentBatches(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PayrollBatchRun::query()
            ->with('company:id,name,logo')
            ->where('payroll_module', PayrollBatchRun::MODULE_EXECOM)
            ->orderByDesc('created_at');

        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 20));
        $paginator->getCollection()->transform(function (PayrollBatchRun $run): array {
            $aggregate = $this->payslipService->aggregateForBatchRun($run, false);

            return [
                ...$run->toArray(),
                'payroll_batch_run_id' => (int) $run->id,
                'payroll_module' => PayrollBatchRun::MODULE_EXECOM,
                'module_label' => 'EXECOM Payroll',
                'company_name' => $run->company?->name,
                'payslip_count' => (int) $aggregate['payslip_count'],
                'finalized_count' => (int) $aggregate['finalized_count'],
                'total_gross_pay' => (float) $aggregate['total_gross_pay'],
                'total_deductions' => (float) $aggregate['total_deductions'],
                'total_net_pay' => (float) $aggregate['total_net_pay'],
            ];
        });

        return response()->json($paginator);
    }

    public function downloadReport(Request $request, int $batchRunId): mixed
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $run = $this->execomRun($batchRunId);
        abort_if((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED, 422, 'Payroll report is only available for finalized EXECOM batches.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        try {
            $result = $this->payrollReportService->pdfForRun($run, $actor);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $result['pdf']->download('EXECOM_'.$result['filename']);
    }

    /**
     * @param  list<string>|string  $status
     */
    private function payslipList(Request $request, array|string $status): JsonResponse
    {
        $validated = $request->validate([
            'batch_run_id' => ['nullable', 'integer', 'exists:payroll_batch_runs,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payslip::query()
            ->with([
                'employee:id,name,first_name,last_name,employee_code,department,department_id',
                'employee.departmentRelation:id,name',
                'company:id,name',
                'payCycle:id,name',
            ])
            ->where('payroll_module', PayrollBatchRun::MODULE_EXECOM);

        $statuses = is_array($status) ? $status : [$status];
        $query->whereIn('status', $statuses);

        if (! in_array(Payslip::STATUS_VOIDED, $statuses, true)) {
            $query->where('period_slot', 0)->whereNull('voided_at');
        }

        $query->orderByDesc('pay_period_end')->orderByDesc('id');

        if (! empty($validated['batch_run_id'])) {
            $query->where('payroll_batch_run_id', (int) $validated['batch_run_id']);
        }
        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['from_date'])) {
            $query->whereDate('pay_period_end', '>=', $validated['from_date']);
        }
        if (! empty($validated['to_date'])) {
            $query->whereDate('pay_period_start', '<=', $validated['to_date']);
        }

        return $this->paginatedPayslipListResponse($query, (int) ($validated['per_page'] ?? 25));
    }

    private function payslipListForRun(Request $request, PayrollBatchRun $run): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->payslipService->payslipDisplayQueryForBatch($run)
            ->with([
                'employee:id,name,first_name,last_name,employee_code,department,department_id',
                'employee.departmentRelation:id,name',
                'company:id,name',
                'payCycle:id,name',
            ])
            ->orderByDesc('id');

        return $this->paginatedPayslipListResponse($query, (int) ($validated['per_page'] ?? 25));
    }

    private function paginatedPayslipListResponse(\Illuminate\Database\Eloquent\Builder $query, ?int $perPage = null): JsonResponse
    {
        $paginator = $query->paginate($perPage ?? (int) request()->integer('per_page', 25));
        $paginator->getCollection()->transform(function (Payslip $payslip): array {
            $employee = $payslip->employee instanceof User ? $payslip->employee : null;
            $snapshot = $employee
                ? $this->payslipService->snapshotForPayslipRender($payslip, $employee)
                : $this->payslipService->normalizeSnapshotForPayslipView(is_array($payslip->snapshot) ? $payslip->snapshot : []);
            $lineTotals = $this->payslipService->payslipLineTotalsFromSnapshot($snapshot);
            $row = $payslip->toArray();
            $row['snapshot'] = $snapshot;
            $row['gross_pay'] = $lineTotals['gross_pay'];
            $row['total_deductions'] = $lineTotals['total_deductions'];
            $row['net_pay'] = $lineTotals['net_pay'];

            return $row;
        });

        return response()->json([
            'payslips' => $paginator,
            'totals' => [
                'total_gross' => round((float) (clone $query)->sum('gross_pay'), 2),
                'total_deductions' => round((float) (clone $query)->sum('total_deductions'), 2),
                'total_net' => round((float) (clone $query)->sum('net_pay'), 2),
                'employee_count' => (int) (clone $query)->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayrollScope(Request $request): array
    {
        return $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
            'include_thirteenth_month' => ['nullable', 'boolean'],
            'include_13th_month_pay' => ['nullable', 'boolean'],
        ]);
    }

    private function execomRun(int $batchRunId): PayrollBatchRun
    {
        /** @var PayrollBatchRun $run */
        $run = PayrollBatchRun::query()
            ->where('payroll_module', PayrollBatchRun::MODULE_EXECOM)
            ->findOrFail($batchRunId);

        return $run;
    }

    private function makeBatchKey(?int $companyId, ?int $branchId, ?int $departmentId, ?int $employeeId, Carbon $from, Carbon $to, mixed $payCycleId): string
    {
        return implode(':', [
            $companyId ?: 'all',
            $branchId ?: 'all',
            $departmentId ?: 'all',
            $employeeId ?: 'all',
            $from->toDateString(),
            $to->toDateString(),
            $payCycleId ?: 'default',
            Str::slug((string) config('app.name', 'hr')),
        ]);
    }
}
