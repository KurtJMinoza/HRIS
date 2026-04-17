<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GeneratePayslipsJob;
use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\PayslipService;
use App\Support\PayslipStoredSnapshotViewPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Payslip admin API — generation, bulk, zip.
 * RBAC: {@see User::isAdmin()} only (Laravel `users.role = admin`). Org heads cannot call these routes.
 */
class PayslipController extends Controller
{
    public function __construct(
        private readonly PayslipService $payslipService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly DataScopeService $dataScopeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            /** Comma-separated payslip ids (for batch detail / ZIP by selection). */
            'ids' => ['nullable', 'string', 'max:8000'],
        ]);

        $q = Payslip::query()
            ->with(['employee:id,name,employee_code,department,profile_image', 'company:id,name'])
            ->orderByDesc('pay_period_end')
            ->orderByDesc('id');

        $scopedByIds = false;
        if (! empty($v['ids'])) {
            $idList = array_values(array_filter(array_map('intval', explode(',', $v['ids']))));
            if (count($idList) > 0) {
                $q->whereIn('id', array_slice($idList, 0, 500));
                $scopedByIds = true;
            }
        }

        if (! $scopedByIds) {
            if (! empty($v['company_id'])) {
                $q->where('company_id', (int) $v['company_id']);
            }
            if (! empty($v['branch_id'])) {
                $q->where('branch_id', (int) $v['branch_id']);
            }
            if (! empty($v['department_id'])) {
                $q->where('department_id', (int) $v['department_id']);
            }
            if (! empty($v['from_date'])) {
                $q->where('pay_period_end', '>=', $v['from_date'].' 00:00:00');
            }
            if (! empty($v['to_date'])) {
                $q->where('pay_period_start', '<=', $v['to_date'].' 23:59:59');
            }
        }

        $paginated = $q->paginate((int) ($v['per_page'] ?? 20));

        return response()->json($paginated);
    }

    /**
     * One row per {@see PayrollBatchRun} (bulk generate / finalize audit), with payslip totals scoped like generation.
     *
     * @return JsonResponse Laravel paginator JSON with `data` rows including payroll_batch_run_id, status, can_delete
     */
    public function recentByCompany(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($v['per_page'] ?? 20);
        $page = max(1, (int) ($v['page'] ?? $request->query('page', 1)));

        $q = PayrollBatchRun::query()
            ->with(['company'])
            ->orderByDesc('created_at');

        // Non-admin org heads: restrict to their own org scope.
        $actor = $request->user();
        if ($actor && ! $actor->isAdmin()) {
            $scopeCompanyId = $actor->getEffectiveCompanyId();
            if ($scopeCompanyId) {
                $q->where(function ($sub) use ($scopeCompanyId) {
                    $sub->where('company_id', (int) $scopeCompanyId)
                        ->orWhereNull('company_id');
                });
            }
            if ($actor->branch_id) {
                $q->where(function ($sub) use ($actor) {
                    $sub->where('branch_id', (int) $actor->branch_id)
                        ->orWhereNull('branch_id');
                });
            }
            if ($actor->department_id) {
                $q->where(function ($sub) use ($actor) {
                    $sub->where('department_id', (int) $actor->department_id)
                        ->orWhereNull('department_id');
                });
            }
        }

        $requestedCompanyId = ! empty($v['company_id']) ? (int) $v['company_id'] : null;
        if ($requestedCompanyId !== null) {
            // Include legacy runs with null company_id only when a payslip for the same period
            // exists for the requested company. This avoids pagination pages being filled by
            // unrelated null-company runs from other companies.
            $q->where(function ($sub) use ($requestedCompanyId) {
                $sub->where('company_id', $requestedCompanyId)
                    ->orWhere(function ($legacy) use ($requestedCompanyId) {
                        $legacy->whereNull('company_id')
                            ->whereExists(function ($exists) use ($requestedCompanyId) {
                                $exists->selectRaw('1')
                                    ->from('payslips')
                                    ->whereColumn('payslips.pay_period_start', 'payroll_batch_runs.pay_period_start')
                                    ->whereColumn('payslips.pay_period_end', 'payroll_batch_runs.pay_period_end')
                                    ->where('payslips.company_id', $requestedCompanyId);
                            });
                    });
            });
        }
        if (! empty($v['branch_id'])) {
            $q->where('branch_id', (int) $v['branch_id']);
        }
        if (! empty($v['department_id'])) {
            $q->where('department_id', (int) $v['department_id']);
        }
        if (! empty($v['from_date'])) {
            $q->whereDate('pay_period_end', '>=', $v['from_date']);
        }
        if (! empty($v['to_date'])) {
            $q->whereDate('pay_period_start', '<=', $v['to_date']);
        }

        $rows = $q->get()->map(function (PayrollBatchRun $run) {
            $agg = $this->payslipService->aggregateForBatchRun($run);
            $resolvedCompanyId = $run->company_id !== null
                ? (int) $run->company_id
                : (isset($agg['company_id']) ? (int) $agg['company_id'] : null);
            $resolvedCompanyName = $run->company?->name;
            $resolvedCompanyLogo = $run->company?->logo;
            if ((! $resolvedCompanyName || ! $resolvedCompanyLogo) && $resolvedCompanyId) {
                $resolvedCompany = Company::query()->find($resolvedCompanyId);
                if ($resolvedCompany) {
                    $resolvedCompanyName = $resolvedCompanyName ?: $resolvedCompany->name;
                    $resolvedCompanyLogo = $resolvedCompanyLogo ?: $resolvedCompany->logo;
                }
            }
            $batchStatus = (string) $run->status;
            $payslipCount = (int) $agg['payslip_count'];
            $finalizedCount = (int) $agg['finalized_count'];

            $allPayslipsFinalized = $payslipCount > 0 && $finalizedCount >= $payslipCount;
            $runFinalized = $batchStatus === PayrollBatchRun::STATUS_FINALIZED;
            $isFinalized = $runFinalized || $allPayslipsFinalized;
            $partiallyFinalized = $finalizedCount > 0 && ! $allPayslipsFinalized && ! $runFinalized;

            // UI status must match batch run state so "Draft" is not shown while queued/processing.
            if ($isFinalized) {
                $displayStatus = Payslip::STATUS_FINALIZED;
                $displayLabel = 'Finalized';
            } elseif ($partiallyFinalized) {
                $displayStatus = 'partial';
                $displayLabel = 'Partially finalized';
            } elseif ($batchStatus === PayrollBatchRun::STATUS_QUEUED) {
                $displayStatus = 'queued';
                $displayLabel = 'Queued';
            } elseif ($batchStatus === PayrollBatchRun::STATUS_PROCESSING) {
                $displayStatus = 'processing';
                $displayLabel = 'Generating';
            } elseif ($batchStatus === PayrollBatchRun::STATUS_FAILED) {
                $displayStatus = 'failed';
                $displayLabel = 'Failed';
            } else {
                $displayStatus = Payslip::STATUS_DRAFT;
                $displayLabel = 'Draft';
            }

            // Delete for draft (generated) or queued (job not started); not while processing; never when any payslip is finalized.
            $canDelete = ! $isFinalized
                && $finalizedCount === 0
                && (
                    $batchStatus === PayrollBatchRun::STATUS_DRAFT
                    || $batchStatus === PayrollBatchRun::STATUS_QUEUED
                );

            return [
                'payroll_batch_run_id' => (int) $run->id,
                'company_id' => $resolvedCompanyId,
                'company_name' => $resolvedCompanyName,
                'company_logo_url' => $this->publicCompanyLogoUrl($resolvedCompanyLogo),
                'branch_id' => $run->branch_id,
                'department_id' => $run->department_id,
                'pay_period_start' => $run->pay_period_start?->toDateString(),
                'pay_period_end' => $run->pay_period_end?->toDateString(),
                'pay_cycle_id' => $run->pay_cycle_id,
                'pay_cycle_source' => $run->pay_cycle_id ? 'template' : 'company_default',
                'pay_cycle_source_label' => $run->pay_cycle_id ? 'Pay cycle template' : 'Company default',
                'employee_count' => max((int) $agg['payslip_count'], (int) ($run->employee_count ?? 0)),
                'total_net_pay' => $agg['payslip_count'] > 0
                    ? round((float) $agg['total_net_pay'], 2)
                    : round((float) ($run->total_net ?? 0), 2),
                'generated_at' => $agg['generated_at']
                    ? \Carbon\Carbon::parse($agg['generated_at'])->toIso8601String()
                    : ($run->created_at?->toIso8601String()),
                'status' => $displayStatus,
                'status_label' => $displayLabel,
                'batch_run_status' => $batchStatus,
                'can_delete' => $canDelete,
                'payslip_ids' => $agg['payslip_ids'],
            ];
        })->values();

        // One clean row per company + pay period across template/company-default reruns.
        $grouped = [];
        foreach ($rows as $row) {
            $key = $this->recentSummaryGroupKey($row);
            if (! isset($grouped[$key])) {
                $grouped[$key] = $row;

                continue;
            }
            if ($this->shouldReplaceRecentSummaryRow($grouped[$key], $row)) {
                $grouped[$key] = $row;
            }
        }

        $deduped = array_values($grouped);
        usort($deduped, function (array $a, array $b): int {
            $aTs = strtotime((string) ($a['generated_at'] ?? '')) ?: 0;
            $bTs = strtotime((string) ($b['generated_at'] ?? '')) ?: 0;
            if ($aTs !== $bTs) {
                return $bTs <=> $aTs;
            }

            return ((int) ($b['payroll_batch_run_id'] ?? 0)) <=> ((int) ($a['payroll_batch_run_id'] ?? 0));
        });

        $total = count($deduped);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($deduped, $offset, $perPage);
        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginator);
    }

    /**
     * Stable dedupe key for "Recent Payslips by Company":
     * one row per resolved company + pay period.
     */
    private function recentSummaryGroupKey(array $row): string
    {
        $company = $row['company_id'] ?? null;
        $companyPart = $company !== null ? (string) $company : 'run:'.((int) ($row['payroll_batch_run_id'] ?? 0));
        $start = (string) ($row['pay_period_start'] ?? '');
        $end = (string) ($row['pay_period_end'] ?? '');

        return $companyPart.'|'.$start.'|'.$end;
    }

    /**
     * Prefer the row with more advanced status, then latest generated_at/id.
     */
    private function shouldReplaceRecentSummaryRow(array $existing, array $candidate): bool
    {
        $existingRank = $this->recentSummaryStatusRank((string) ($existing['status'] ?? ''));
        $candidateRank = $this->recentSummaryStatusRank((string) ($candidate['status'] ?? ''));
        if ($candidateRank !== $existingRank) {
            return $candidateRank > $existingRank;
        }

        $existingTs = strtotime((string) ($existing['generated_at'] ?? '')) ?: 0;
        $candidateTs = strtotime((string) ($candidate['generated_at'] ?? '')) ?: 0;
        if ($candidateTs !== $existingTs) {
            return $candidateTs > $existingTs;
        }

        return ((int) ($candidate['payroll_batch_run_id'] ?? 0)) > ((int) ($existing['payroll_batch_run_id'] ?? 0));
    }

    private function recentSummaryStatusRank(string $status): int
    {
        return match (strtolower(trim($status))) {
            Payslip::STATUS_FINALIZED => 500,
            'processing' => 400,
            'queued' => 300,
            Payslip::STATUS_DRAFT => 200,
            'failed' => 100,
            default => 0,
        };
    }

    /**
     * Delete a draft or queued batch run and its draft payslips (queued jobs no-op if the run is already gone).
     */
    public function destroyDraftBatch(Request $request, int $id): JsonResponse
    {
        $this->ensureAdmin($request);
        $run = PayrollBatchRun::query()->findOrFail($id);
        try {
            $this->payslipService->deleteDraftBatchRun($run);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['message' => 'Batch deleted.']);
    }

    /**
     * Estimated employee counts for the same scope as {@see PayslipService::generateBulkPayslips} (active employees only).
     */
    public function previewScope(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        if ($request->user()?->isAdmin()) {
            abort_unless(
                ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id']),
                422,
                'Select at least one of company, branch, or department.'
            );
        }

        $q = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $q);

        if (! empty($v['company_id'])) {
            $q->where('company_id', (int) $v['company_id']);
        }
        if (! empty($v['branch_id'])) {
            $q->where('branch_id', (int) $v['branch_id']);
        }
        if (! empty($v['department_id'])) {
            $q->where('department_id', (int) $v['department_id']);
        }

        $total = (clone $q)->count();

        if (! empty($v['branch_id'])) {
            $branchesFiltered = 1;
        } else {
            $branchesFiltered = (int) (clone $q)->whereNotNull('branch_id')->distinct()->count('branch_id');
        }

        $regular = (clone $q)->where(function ($sub) {
            $sub->whereRaw('LOWER(TRIM(COALESCE(employment_status, ""))) IN (?, ?)', [
                EmploymentStatus::Regular->value,
                EmploymentStatus::Probationary->value,
            ])->orWhereNull('employment_status');
        })->count();

        $contractualProject = (clone $q)->whereRaw(
            'LOWER(TRIM(COALESCE(employment_status, ""))) IN (?, ?)',
            [
                EmploymentStatus::Contractual->value,
                EmploymentStatus::ProjectBased->value,
            ]
        )->count();

        $other = max(0, $total - $regular - $contractualProject);

        return response()->json([
            'total_employees' => $total,
            'regular' => $regular,
            'contractual_or_project' => $contractualProject,
            'other' => $other,
            'branches_filtered' => $branchesFiltered,
        ]);
    }

    /**
     * Generate for one employee or bulk (company / branch / department) — integrates Pay Cycle + payroll computation.
     */
    public function generate(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
        ]);

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

        if (empty($v['employee_id'])) {
            $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id'])
                || (is_array($v['employee_ids'] ?? null) && count($v['employee_ids']) > 0);
            if ($request->user()?->isAdmin()) {
                abort_unless($scoped, 422, 'Provide employee_id or scope (company, branch, department) or employee_ids.');
            }
        }

        if (! empty($v['employee_id'])) {
            $user = User::query()->findOrFail((int) $v['employee_id']);
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $user);
            // Single-employee generation should be quick and usable for preview immediately.
            // PDFs are not generated here to keep the endpoint responsive.
            $result = $this->payslipService->generatePayslip($user, $periodInput, withPdf: false);

            return response()->json([
                'message' => 'Payslip generated.',
                'payslip_id' => $result['payslip']->id,
                'pdf_password' => $result['pdf_password'],
            ]);
        }

        // Bulk generation is queued to avoid PHP timeouts. We create a Draft batch run immediately
        // so the "Recent Payslips by Company" table can show it right away.
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id'])
            || (is_array($v['employee_ids'] ?? null) && count($v['employee_ids']) > 0);
        if ($actor->isAdmin()) {
            abort_unless($scoped, 422, 'Provide employee_id or scope (company, branch, department) or employee_ids.');
        }

        // Probe the scope to resolve pay period dates (pay cycle + cut-off integration).
        $probeQ = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);
        $this->dataScopeService->restrictEmployeeQuery($actor, $probeQ);
        if (is_array($v['employee_ids'] ?? null) && count($v['employee_ids']) > 0) {
            $probeQ->whereIn('id', $v['employee_ids']);
        } else {
            if (! empty($v['company_id'])) {
                $probeQ->where('company_id', (int) $v['company_id']);
            }
            if (! empty($v['branch_id'])) {
                $probeQ->where('branch_id', (int) $v['branch_id']);
            }
            if (! empty($v['department_id'])) {
                $probeQ->where('department_id', (int) $v['department_id']);
            }
        }
        /** @var User|null $probe */
        $probe = (clone $probeQ)->orderBy('id')->first();
        abort_unless($probe, 422, 'No active employees in the selected scope.');

        [$periodStart, $periodEnd] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
        $resolvedCompanyId = isset($v['company_id']) && $v['company_id'] !== null
            ? (int) $v['company_id']
            : ($probe->getEffectiveCompanyId() !== null ? (int) $probe->getEffectiveCompanyId() : null);

        $batchKey = $this->makeBatchKey(
            $resolvedCompanyId,
            isset($v['branch_id']) ? (int) $v['branch_id'] : null,
            isset($v['department_id']) ? (int) $v['department_id'] : null,
            null,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            $periodInput['pay_cycle_id'] ?? null
        );

        $existing = PayrollBatchRun::query()->where('batch_key', $batchKey)->first();
        if ($existing && (string) $existing->status === PayrollBatchRun::STATUS_FINALIZED) {
            abort(422, 'This payroll period is already finalized for the selected scope.');
        }

        $employeeCount = (int) (clone $probeQ)->count();
        $runPayload = [
            'batch_key' => $batchKey,
            'company_id' => $resolvedCompanyId,
            'branch_id' => isset($v['branch_id']) ? (int) $v['branch_id'] : null,
            'department_id' => isset($v['department_id']) ? (int) $v['department_id'] : null,
            'employee_id' => null,
            'pay_period_start' => $periodStart->toDateString(),
            'pay_period_end' => $periodEnd->toDateString(),
            'pay_cycle_id' => $periodInput['pay_cycle_id'] ?? null,
            'payroll_period_id' => $periodInput['payroll_period_id'] ?? null,
            'is_final_pay' => (bool) ($periodInput['is_final_pay'] ?? false),
            'password_protect' => (bool) ($periodInput['password_protect'] ?? false),
            'reference_date' => isset($periodInput['reference_date']) ? \Carbon\Carbon::parse((string) $periodInput['reference_date'])->toDateString() : null,
            'status' => PayrollBatchRun::STATUS_QUEUED,
            'employee_count' => $employeeCount,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
            'finalized_by_user_id' => $actor->id,
        ];

        $run = $existing
            ? tap($existing)->update($runPayload)->fresh()
            : PayrollBatchRun::query()->create($runPayload);

        // If already processing, don't double-dispatch.
        $shouldDispatch = (string) $run->status !== PayrollBatchRun::STATUS_PROCESSING;
        if ($shouldDispatch) {
            GeneratePayslipsJob::dispatch((int) $run->id, (int) $actor->id)->onQueue('default');
        }

        return response()->json([
            'message' => 'Payslip generation queued.',
            'queued' => true,
            'payroll_batch_run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'pay_period_start' => $periodStart->toDateString(),
            'pay_period_end' => $periodEnd->toDateString(),
            'employee_count' => $employeeCount,
        ], 202);
    }

    /**
     * Structured payslip payload for the admin preview modal — reads the stored snapshot (no recomputation).
     */
    public function showData(Request $request, int $id): JsonResponse
    {
        $this->ensurePayslipAccess($request);

        $payslip = Payslip::query()
            ->with(['employee.company', 'employee.branch', 'employee.departmentRelation', 'employee.governmentIds', 'company'])
            ->findOrFail($id);

        $employee = $payslip->employee;
        abort_unless($employee instanceof User, 404);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        $status = strtolower(trim((string) ($payslip->status ?? '')));
        $immutableStatuses = Payslip::lockingStatuses();
        $isFinalized = in_array($status, $immutableStatuses, true);

        // Keep finalized payslips immutable (snapshot parity), but recompute draft/queued/processing
        // so preview aligns with current Daily Computation / Finalize Payroll / Salary-tab rate sources.
        if (! $isFinalized) {
            $periodInput = [
                'from_date' => $payslip->pay_period_start?->toDateString(),
                'to_date' => $payslip->pay_period_end?->toDateString(),
                'pay_cycle_id' => $payslip->pay_cycle_id !== null ? (int) $payslip->pay_cycle_id : null,
                'reference_date' => $payslip->pay_date?->toDateString(),
                'use_company_default' => $payslip->pay_cycle_id === null,
                'payroll_period_id' => $payslip->payroll_period_id !== null ? (int) $payslip->payroll_period_id : null,
                'is_final_pay' => (bool) $payslip->is_final_pay,
                'password_protect' => false,
            ];

            try {
                $live = $this->payslipService->previewDataForEmployee($employee, $periodInput);
                if (isset($live['company']) && is_array($live['company'])) {
                    $live['company']['logo_url'] = $this->publicCompanyLogoUrl($payslip->company?->logo ?? $employee->company?->logo ?? null);
                }
                if (isset($live['employee']) && is_array($live['employee'])) {
                    $live['employee']['profile_image_url'] = $employee->profile_image_url;
                }
                $live['payslip_id'] = (int) $payslip->id;
                $live['batch_scope'] = [
                    'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
                    'branch_id' => $payslip->branch_id !== null ? (int) $payslip->branch_id : null,
                    'department_id' => $payslip->department_id !== null ? (int) $payslip->department_id : null,
                ];
                if (! isset($live['payroll']) || ! is_array($live['payroll'])) {
                    $live['payroll'] = [];
                }
                $live['payroll']['status'] = (string) ($payslip->status ?? Payslip::STATUS_DRAFT);
                $live['payroll']['cycle_label'] = $payslip->cycle_label ?: ($live['payroll']['cycle_label'] ?? null);

                return response()->json($live);
            } catch (\RuntimeException) {
                // Fallback to stored snapshot payload below if live recomputation fails.
            }
        }

        $company = $payslip->company ?? $employee->company;

        return response()->json(PayslipStoredSnapshotViewPayload::fromStoredPayslip(
            $payslip,
            $employee,
            $this->payslipService,
            $this->publicCompanyLogoUrl($company?->logo)
        ));
    }

    /**
     * Dedicated full-view payload endpoint for admin payslip module.
     * Reuses stored snapshot data to ensure exact parity with generated payslip values.
     */
    public function viewData(Request $request, int $id): JsonResponse
    {
        return $this->showData($request, $id);
    }

    /**
     * Dedicated full-view preview payload for admin payslip module.
     * Returns computed on-the-fly data when no stored payslip record exists yet.
     */
    public function viewPreviewData(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
        ]);

        $employee = User::query()->findOrFail((int) $v['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

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

        try {
            $payload = $this->payslipService->previewDataForEmployee($employee, $periodInput);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $employee->loadMissing('company');
        $company = $employee->company;
        if (isset($payload['company']) && is_array($payload['company'])) {
            $payload['company']['logo_url'] = $this->publicCompanyLogoUrl($company?->logo);
        }
        if (isset($payload['employee']) && is_array($payload['employee'])) {
            $payload['employee']['profile_image_url'] = $employee->profile_image_url;
        }

        return response()->json($payload);
    }

    /**
     * Lightweight sample preview for UI (JSON only; no PDF). Cached briefly to avoid UI stalls.
     */
    public function previewSampleData(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
        ]);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id']);
        if ($request->user()?->isAdmin()) {
            abort_unless($scoped, 422, 'Select at least one of company, branch, or department.');
        }

        $periodInput = [
            'from_date' => $v['from_date'] ?? null,
            'to_date' => $v['to_date'] ?? null,
            'pay_cycle_id' => $v['pay_cycle_id'] ?? null,
            'reference_date' => $v['reference_date'] ?? null,
            'use_company_default' => (bool) ($v['use_company_default'] ?? false),
            'payroll_period_id' => $v['payroll_period_id'] ?? null,
            'is_final_pay' => $v['is_final_pay'] ?? false,
            'password_protect' => false,
        ];

        $actor = $request->user();
        $q = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);
        if ($actor instanceof User) {
            $this->dataScopeService->restrictEmployeeQuery($actor, $q);
        }
        if (! empty($v['company_id'])) {
            $q->where('company_id', (int) $v['company_id']);
        }
        if (! empty($v['branch_id'])) {
            $q->where('branch_id', (int) $v['branch_id']);
        }
        if (! empty($v['department_id'])) {
            $q->where('department_id', (int) $v['department_id']);
        }
        $employee = $q->orderBy('id')->first();
        if (! $employee) {
            return response()->json(['message' => 'No active employee in the selected scope.'], 422);
        }

        try {
            $payload = $this->payslipService->previewDataForEmployee($employee, $periodInput);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    private function makeBatchKey(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        string $payPeriodStart,
        string $payPeriodEnd,
        mixed $payCycleId
    ): string {
        return hash('sha256', implode('|', [
            (string) ($companyId ?? 'x'),
            (string) ($branchId ?? 'x'),
            (string) ($departmentId ?? 'x'),
            (string) ($singleEmployeeId ?? 'x'),
            $payPeriodStart,
            $payPeriodEnd,
            (string) ($payCycleId ?? 'x'),
        ]));
    }

    /**
     * Sample payslip PDF for the **current form scope + period** — same pipeline as bulk generate, no DB row.
     * First active employee in scope; {@see PayslipService::previewSamplePdfForScope}.
     *
     * Response: PDF binary. When password protection is requested, plain password is echoed in `X-Payslip-Pdf-Password`
     * (exposed for SPA fetch — see `config/cors.php`).
     */
    public function previewSample(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
        ]);

        $scoped = ! empty($v['company_id']) || ! empty($v['branch_id']) || ! empty($v['department_id']);
        if ($request->user()?->isAdmin()) {
            abort_unless($scoped, 422, 'Select at least one of company, branch, or department.');
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

        try {
            $result = $this->payslipService->previewSamplePdfForScope(
                isset($v['company_id']) ? (int) $v['company_id'] : null,
                isset($v['branch_id']) ? (int) $v['branch_id'] : null,
                isset($v['department_id']) ? (int) $v['department_id'] : null,
                $periodInput,
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $full = storage_path('app/private/'.$result['relative_path']);
        abort_unless(is_file($full), 404);

        $headers = ['Content-Type' => 'application/pdf'];
        $pwd = $result['pdf_password'] ?? null;
        if (is_string($pwd) && $pwd !== '') {
            $headers['X-Payslip-Pdf-Password'] = $pwd;
        }

        return response()->download($full, 'payslip-sample-preview.pdf', $headers)->deleteFileAfterSend(true);
    }

    /**
     * Preview payslip PDF for a specific employee in the selected period — no DB write.
     * Response is a PDF binary; optional password is in `X-Payslip-Pdf-Password`.
     */
    public function previewEmployee(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
        ]);

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

        $employee = User::query()->findOrFail((int) $v['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        try {
            $result = $this->payslipService->previewPdfForEmployee($employee, $periodInput);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $full = storage_path('app/private/'.$result['relative_path']);
        abort_unless(is_file($full), 404);

        $headers = ['Content-Type' => 'application/pdf'];
        $pwd = $result['pdf_password'] ?? null;
        if (is_string($pwd) && $pwd !== '') {
            $headers['X-Payslip-Pdf-Password'] = $pwd;
        }

        return response()->download($full, 'payslip-preview-'.$employee->id.'.pdf', $headers)->deleteFileAfterSend(true);
    }

    /**
     * Structured preview payload for in-app payslip modal (same computation as PDF preview).
     */
    public function previewEmployeeData(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'pay_cycle_id' => ['nullable', 'integer', 'exists:pay_cycles,id'],
            'reference_date' => ['nullable', 'date'],
            'use_company_default' => ['nullable', 'boolean'],
            'payroll_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id'],
            'is_final_pay' => ['nullable', 'boolean'],
            'password_protect' => ['nullable', 'boolean'],
        ]);

        $employee = User::query()->findOrFail((int) $v['employee_id']);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

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

        try {
            $payload = $this->payslipService->previewDataForEmployee($employee, $periodInput);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Company default cut-off + pay-date calculator for the SPA Advanced section.
     * Reference date is always the (weekend-adjusted) pay date.
     *
     * Pay date rule (PH semi-monthly, any year):
     *   - Cut-off 11–25 → pay date = last calendar day of the month (weekend-adjusted)
     *   - Cut-off 26–10 → pay date = 15th of the month (weekend-adjusted)
     */
    public function companyDefaultDates(Request $request): JsonResponse
    {
        $this->ensurePayslipAccess($request);
        $v = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'anchor_date' => ['nullable', 'date'],
            'pay_date' => ['nullable', 'date'],
        ]);

        $payCycleService = app(\App\Services\PayCycleService::class);
        $company = ! empty($v['company_id']) ? Company::query()->with('defaultPayCycle')->find((int) $v['company_id']) : null;
        $cycle = $company?->defaultPayCycle;
        $preview = null;

        if ($cycle && $cycle->code === \App\Models\PayCycle::CODE_SEMI_MONTHLY) {
            // Semi-monthly template: use the canonical PH rule via buildCompanyDefaultPreview
            // to ensure 15th / last-day-of-month + weekend adjustment is always applied.
            $preview = ! empty($v['pay_date'])
                ? $payCycleService->buildCompanyDefaultPreviewFromPayDate((string) $v['pay_date'])
                : $payCycleService->buildCompanyDefaultPreview($v['anchor_date'] ?? null);
        } elseif ($cycle) {
            // Non-semi-monthly templates (weekly, daily, project, etc.) use template logic.
            $preview = ! empty($v['pay_date'])
                ? $payCycleService->buildCyclePreviewFromPayDate($cycle, (string) $v['pay_date'])
                : $payCycleService->buildCyclePreview($cycle, $v['anchor_date'] ?? null);
        } else {
            $preview = ! empty($v['pay_date'])
                ? $payCycleService->buildCompanyDefaultPreviewFromPayDate((string) $v['pay_date'])
                : $payCycleService->buildCompanyDefaultPreview($v['anchor_date'] ?? null);
        }

        return response()->json([
            'from_date' => $preview['cut_off_start_date'] ?? null,
            'to_date' => $preview['cut_off_end_date'] ?? null,
            'pay_date' => $preview['pay_date'] ?? null,
            'reference_date' => $preview['pay_date'] ?? null,
            'cycle_label' => $preview['cycle_label'] ?? null,
            'weekend_adjusted' => (bool) ($preview['weekend_adjusted'] ?? false),
            'weekend_adjustment_note' => $preview['weekend_adjustment_note'] ?? null,
        ]);
    }

    public function download(Request $request, int $id): BinaryFileResponse
    {
        $this->ensurePayslipAccess($request);

        $payslip = Payslip::query()
            ->with(['employee.company', 'employee.branch', 'employee.departmentRelation', 'employee.governmentIds'])
            ->findOrFail($id);

        $employee = $payslip->employee;
        abort_unless($employee instanceof User, 404);
        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        // Always regenerate on download so all modules use the latest clean PDF template.
        $relative = $this->payslipService->generatePdf($payslip, $employee);
        $payslip->update(['pdf_path' => $relative]);
        $employeeCode = trim((string) ($employee->employee_code ?? ''));
        $filename = $employeeCode !== ''
            ? 'Payslip-'.$employeeCode.'.pdf'
            : 'payslip-'.$payslip->id.'.pdf';

        return response()->download(
            storage_path('app/private/'.$relative),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Zip multiple payslips by id list (must already have pdf_path).
     */
    public function downloadZip(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->ensureAdmin($request);
        if (! class_exists(ZipArchive::class)) {
            return response()->json([
                'message' => 'ZIP export is unavailable: PHP extension "zip" (ZipArchive) is not enabled on this server.',
            ], 503);
        }

        $v = $request->validate([
            'payslip_ids' => ['required', 'array', 'min:1', 'max:500'],
            'payslip_ids.*' => ['integer', 'exists:payslips,id'],
        ]);

        $payslips = Payslip::query()
            ->with('employee:id,employee_code,name')
            ->whereIn('id', $v['payslip_ids'])
            ->get();

        $tmp = tempnam(sys_get_temp_dir(), 'psl');
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        foreach ($payslips as $p) {
            if (! $p->pdf_path) {
                continue;
            }
            $full = storage_path('app/private/'.$p->pdf_path);
            if (! is_file($full)) {
                continue;
            }
            $code = $p->employee?->employee_code ?: 'EMP';
            $zip->addFile($full, $code.'-'.$p->id.'-payslip.pdf');
        }
        $zip->close();

        return response()->download($zipPath, 'payslips-'.now()->format('Y-m-d-His').'.zip')->deleteFileAfterSend(true);
    }

    private function ensurePayslipAccess(Request $request): void
    {
        $u = $request->user();
        abort_unless($u, 403);
        if ($u->isAdmin()) {
            return;
        }
        abort_unless($this->hrRoleResolver->resolve($u)->canAccessHrPanel(), 403);
    }

    /**
     * Strict admin-only gate for high-impact actions (listing all generated payslips, ZIP, etc).
     */
    private function ensureAdmin(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && $u->isAdmin(), 403);
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    /** Same convention as {@see CompanyController} public media URLs. */
    private function publicCompanyLogoUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }
}
