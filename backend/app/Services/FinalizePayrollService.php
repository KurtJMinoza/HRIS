<?php

namespace App\Services;

use App\Models\PayCycle;
use App\Models\Overtime;
use App\Models\PayrollBatchRun;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\UserAdminActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Finalize payroll batch: preview totals (computed from {@see PayrollComputationService}) and
 * finalize (persist {@see PayrollPeriod} + loan/statutory hooks, lock periods, audit batch).
 *
 * Queued finalize jobs reuse draft {@see Payslip} snapshots when every employee already has a draft row:
 * no {@see PayrollComputationService::computeEmployeePayroll} rerun and no synchronous PDF generation
 * (PDFs are built on the `payslip-pdf` queue via {@see GeneratePayslipsJob}).
 *
 * Uses the same pay window as {@see PayslipService::generatePayslip} via {@see PayslipService::resolveComputationWindow}.
 */
class FinalizePayrollService
{
    private const PREVIEW_MAX_EMPLOYEES = 500;

    /** Hard cap per finalize run to avoid long DB transactions and timeouts. */
    private const FINALIZE_MAX_EMPLOYEES = 500;

    /** @var list<string>|null */
    private static ?array $payrollBatchRunColumns = null;

    public function __construct(
        private readonly PayrollComputationService $payrollComputation,
        private readonly PayslipService $payslipService,
        private readonly PayCycleService $payCycleService,
        private readonly PayrollPersistService $payrollPersistService,
        private readonly OvertimePayrollService $overtimePayroll,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly PayrollEmployeeEligibilityService $payrollEligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $periodInput  from_date, to_date, pay_cycle_id?, reference_date?, payroll_period_id?, is_final_pay?, password_protect?
     * @return array{
     *   totals: array{total_gross: float, total_deductions: float, total_net: float, employee_count: int},
     *   employees: list<array<string, mixed>>,
     *   period_preview?: array<string, mixed>
     * }
     */
    public function preview(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor = null,
        int $page = 1,
        int $perPage = 25,
        ?string $search = null
    ): array {
        $previewStartedAt = microtime(true);
        $liveBatchRun = $this->resolvePayrollBatchRunSnapshot(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodInput,
            $actor
        );
        if ($liveBatchRun !== null && in_array((string) ($liveBatchRun['status'] ?? ''), [
            PayrollBatchRun::STATUS_QUEUED,
            PayrollBatchRun::STATUS_PROCESSING,
            PayrollBatchRun::STATUS_DRAFT,
            PayrollBatchRun::STATUS_FINALIZED,
        ], true)) {
            return $this->processingPreviewFromStoredPayslips(
                $liveBatchRun,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $periodInput,
                $actor,
                $page,
                $perPage,
                $search
            );
        }

        $cached = (function () use (
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodInput,
            $actor,
            $search,
            $page,
            $perPage,
            $previewStartedAt
        ) {
            $t0 = microtime(true);
            $scopedEmployeesQuery = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor, $search);
            $activeEmployeeIds = (clone $scopedEmployeesQuery)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $storedPayslipEmployeeIds = $this->previewStoredPayslipEmployeeIds(
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $periodInput,
                $actor,
                $search
            );
            $scopedEmployeeIds = array_values(array_unique(array_merge($activeEmployeeIds, $storedPayslipEmployeeIds)));
            $scopedCount = count($scopedEmployeeIds);
            $employeeQueryMs = round((microtime(true) - $t0) * 1000, 2);
            $currentPage = max(1, $page);
            $limit = max(1, min(100, $perPage));
            $offset = ($currentPage - 1) * $limit;
            $employeesQuery = User::query()
                ->whereIn('id', $scopedEmployeeIds)
                ->with(['departmentRelation:id,name']);
            $employees = $employeesQuery
                // IMPORTANT: do not under-select here. Payroll computation and deduction schedules rely on
                // core employee columns (company_id, pay_cycle_id, etc). Missing columns can silently zero out
                // statutory deductions and break pay-date resolution.
                ->select([
                    'id',
                    'name',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'suffix',
                    'employee_code',
                    'department',
                    'position',
                    'profile_image',
                    'company_id',
                    'branch_id',
                    'department_id',
                    'pay_cycle_id',
                    'monthly_salary',
                    'monthly_rate',
                    'daily_rate',
                    'working_schedule_id',
                    'schedule',
                    'employment_status',
                    'is_active',
                    'role',
                ])
                ->orderByLastName()
                ->offset($offset)
                ->limit($limit)
                ->get();

            if ($employees->isEmpty()) {
                return [
                    'totals' => [
                        'total_gross' => 0.0,
                        'total_deductions' => 0.0,
                        'total_net' => 0.0,
                        'employee_count' => 0,
                    ],
                    'period_preview' => null,
                    'employees' => [],
                    'scoped_count' => 0,
                    'pagination' => [
                        'page' => 1,
                        'per_page' => $limit,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ];
            }

            $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
            $payslipQuery = Payslip::query()
                ->with(['payCycle:id,name', 'employee:id,employee_code'])
                ->whereIn('user_id', $employeeIds);
            $this->applyPreviewPeriodFiltersEloquent($payslipQuery, $periodInput);
            /** @var \Illuminate\Support\Collection<int, Payslip> $payslips */
            $payslips = $payslipQuery->orderByDesc('id')->get();
            $payslipByUser = $payslips->keyBy('user_id');
            $periodFromPayslip = $payslips->first();

            $pageTotals = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0];
            $rows = [];
            $tLoop = microtime(true);

            // Lazy-load computation-heavy relations only for employees that need payroll recomputation.
            $needsComputeIds = [];
            foreach ($employees as $employee) {
                if (! $payslipByUser->has((int) $employee->id)) {
                    $needsComputeIds[] = (int) $employee->id;
                }
            }
            if ($needsComputeIds !== []) {
                $employees->filter(fn ($e) => in_array((int) $e->id, $needsComputeIds, true))
                    ->loadMissing([
                        'company',
                        'branch',
                        'payCycle',
                        'governmentIds',
                        'workingSchedule',
                    ]);
            }

            foreach ($employees as $employee) {
                $stored = $payslipByUser->get((int) $employee->id);
                $summary = is_array($stored?->snapshot['summary'] ?? null)
                    ? (array) $stored->snapshot['summary']
                    : [];

                if ($stored instanceof Payslip) {
                    if (! in_array((string) $stored->status, Payslip::lockingStatuses(), true)) {
                        try {
                            $stored = $this->payslipService->refreshDraftPayslipFromLiveComputation($stored, $employee);
                        } catch (Throwable $e) {
                            Log::warning('Payroll preview draft live refresh failed; falling back to stored snapshot', [
                                'payslip_id' => (int) $stored->id,
                                'employee_id' => (int) $employee->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    $lineTotals = $this->payslipService->payslipTotalsForDisplay($stored);
                    $summary = is_array($stored->snapshot['summary'] ?? null)
                        ? (array) $stored->snapshot['summary']
                        : $summary;
                    $gross = $lineTotals['gross_pay'];
                    $ded = $lineTotals['total_deductions'];
                    $net = $lineTotals['net_pay'];
                    $dailyRate = round((float) ($summary['daily_rate'] ?? 0), 2);
                    $basicPay = round((float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)), 2);
                    $baseMonthly = round((float) (data_get($summary, 'compensation_breakdown.basic_salary', 0)), 2);
                    $basicScheduleFactor = (float) ($summary['basic_salary_schedule_factor'] ?? 1);
                    $basicSalaryThisPeriod = round(max(0.0, $baseMonthly * $basicScheduleFactor), 2);
                    $actualDaysWorked = round((float) ($summary['actual_days_worked'] ?? 0), 2);
                    $dailyEarningLines = is_array($summary['daily_computation_earning_lines'] ?? null)
                        ? array_values($summary['daily_computation_earning_lines'])
                        : [];
                    $attendanceDisplaySummary = is_array($summary['attendance_display_summary'] ?? null)
                        ? $summary['attendance_display_summary']
                        : [
                            'working_days_count' => 0,
                            'presence_days_count' => 0,
                            'lines' => [],
                            'total_regular_hours' => 0.0,
                            'total_presence_regular_hours' => 0.0,
                        ];

                    [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($employee, $periodInput);
                } else {
                    $employee->loadMissing([
                        'company',
                        'branch',
                        'payCycle',
                        'governmentIds',
                        'workingSchedule',
                    ]);
                    [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($employee, $periodInput);
                    $computed = $this->payrollComputation->computeEmployeePayroll(
                        $employee,
                        $from,
                        $to,
                        null,
                        [
                            'pay_period_start' => $from->toDateString(),
                            'pay_period_end' => $to->toDateString(),
                            'selected_pay_date' => is_array($cyclePreview) ? ($cyclePreview['pay_date'] ?? null) : null,
                        ]
                    );
                    $summary = is_array($computed['summary'] ?? null) ? $computed['summary'] : [];
                    $lineTotals = $this->payslipService->payslipLineTotalsFromSnapshot([
                        'summary' => $summary,
                        'daily_rate' => $computed['daily_rate'] ?? null,
                        'daily_computation_days' => is_array($computed['days'] ?? null) ? $computed['days'] : [],
                    ]);
                    $gross = $lineTotals['gross_pay'];
                    $ded = $lineTotals['total_deductions'];
                    $net = $lineTotals['net_pay'];
                    $dailyRate = round((float) ($summary['daily_rate'] ?? ($computed['daily_rate'] ?? 0)), 2);
                    $basicPay = round((float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)), 2);
                    $baseMonthly = round((float) ($computed['basic_salary_used'] ?? data_get($summary, 'compensation_breakdown.basic_salary', 0)), 2);
                    $basicScheduleFactor = (float) ($summary['basic_salary_schedule_factor'] ?? 1);
                    $basicSalaryThisPeriod = round(max(0.0, $baseMonthly * $basicScheduleFactor), 2);
                    $actualDaysWorked = round((float) ($summary['actual_days_worked'] ?? 0), 2);
                    $dailyEarningLines = is_array($summary['daily_computation_earning_lines'] ?? null)
                        ? array_values($summary['daily_computation_earning_lines'])
                        : [];
                    $attendanceDisplaySummary = is_array($summary['attendance_display_summary'] ?? null)
                        ? $summary['attendance_display_summary']
                        : [
                            'working_days_count' => 0,
                            'presence_days_count' => 0,
                            'lines' => [],
                            'total_regular_hours' => 0.0,
                            'total_presence_regular_hours' => 0.0,
                        ];
                }
                if ($stored instanceof Payslip) {
                    $resolvedHrRole = $this->hrRoleResolver->resolveForApprovalSubject($employee);
                    $rows[] = [
                        'payslip_id' => (int) $stored->id,
                        'user_id' => (int) $employee->id,
                        ...$this->employeePreviewIdentityFields($employee),
                        'employee_code' => $employee->employee_code,
                        'department' => $this->employeeDepartmentName($employee),
                        'position' => $employee->position,
                        'profile_image_url' => $employee->profile_image_url,
                        'employee_hr_role' => $resolvedHrRole->value,
                        'employee_role_label' => $resolvedHrRole->badgeLabel(),
                        'basic_salary' => $basicSalaryThisPeriod,
                        'basic_salary_monthly' => $baseMonthly,
                        'basic_salary_schedule_factor' => $basicScheduleFactor,
                        'daily_rate' => $dailyRate,
                        'basic_pay' => $basicPay,
                        'actual_days_worked' => $actualDaysWorked,
                        'daily_computation_earning_lines' => $dailyEarningLines,
                        'attendance_display_summary' => $attendanceDisplaySummary,
                        'gross_pay' => $gross,
                        'total_deductions' => $ded,
                        'net_pay' => $net,
                        'status' => (string) ($stored->status ?? Payslip::STATUS_DRAFT),
                        'delivered_at' => $stored->delivered_at !== null
                            ? $stored->delivered_at->toIso8601String()
                            : null,
                        'is_sent' => (bool) ($stored->is_sent ?? false),
                        'sent_at' => isset($stored->sent_at) && $stored->sent_at !== null
                            ? $stored->sent_at->toIso8601String()
                            : null,
                        'has_stored_payslip' => true,
                    ];
                } else {
                    $resolvedHrRole = $this->hrRoleResolver->resolveForApprovalSubject($employee);
                    $rows[] = [
                        'payslip_id' => null,
                        'user_id' => (int) $employee->id,
                        ...$this->employeePreviewIdentityFields($employee),
                        'employee_code' => $employee->employee_code,
                        'department' => $this->employeeDepartmentName($employee),
                        'position' => $employee->position,
                        'profile_image_url' => $employee->profile_image_url,
                        'employee_hr_role' => $resolvedHrRole->value,
                        'employee_role_label' => $resolvedHrRole->badgeLabel(),
                        'basic_salary' => $basicSalaryThisPeriod,
                        'basic_salary_monthly' => $baseMonthly,
                        'basic_salary_schedule_factor' => $basicScheduleFactor,
                        'daily_rate' => $dailyRate,
                        'basic_pay' => $basicPay,
                        'actual_days_worked' => $actualDaysWorked,
                        'daily_computation_earning_lines' => $dailyEarningLines,
                        'attendance_display_summary' => $attendanceDisplaySummary,
                        'gross_pay' => $gross,
                        'total_deductions' => $ded,
                        'net_pay' => $net,
                        'status' => Payslip::STATUS_DRAFT,
                        'has_stored_payslip' => false,
                        'computed_preview' => [
                            'pay_period_start' => $from->toDateString(),
                            'pay_period_end' => $to->toDateString(),
                            'cycle_label' => is_array($cyclePreview) ? ($cyclePreview['cycle_label'] ?? $cycle?->name) : $cycle?->name,
                            'pay_date' => is_array($cyclePreview) ? ($cyclePreview['pay_date'] ?? null) : null,
                        ],
                    ];
                }

                $pageTotals['gross'] += $gross;
                $pageTotals['ded'] += $ded;
                $pageTotals['net'] += $net;
            }
            $generationLoopMs = round((microtime(true) - $tLoop) * 1000, 2);

            // One row per employee (latest active payslip) — never SUM duplicate payslip rows.
            $totalsProbe = $employees->first();
            [$totalsFrom, $totalsTo] = $this->payslipService->resolveComputationWindow($totalsProbe, $periodInput);
            $totalsQuery = Payslip::query()
                ->whereIn('user_id', $scopedEmployeeIds)
                ->whereDate('pay_period_start', $totalsFrom->toDateString())
                ->whereDate('pay_period_end', $totalsTo->toDateString());
            if ($companyId) {
                $totalsQuery->where('company_id', $companyId);
            }
            if ($branchId) {
                $totalsQuery->where('branch_id', $branchId);
            }
            if ($departmentId) {
                $totalsQuery->where('department_id', $departmentId);
            }
            if ($singleEmployeeId) {
                $totalsQuery->where('user_id', $singleEmployeeId);
            }
            $uniquePayslipIds = $this->payslipService->latestUniquePayslipIdsForQuery($totalsQuery);
            $uniqueSums = $this->payslipService->sumUniquePayslipsByIds($uniquePayslipIds);

            if ($uniqueSums['employee_count'] > 0) {
                $resolvedTotals = [
                    'gross' => $uniqueSums['total_gross'],
                    'ded' => $uniqueSums['total_deductions'],
                    'net' => $uniqueSums['total_net'],
                ];
            } else {
                $resolvedTotals = [
                    'gross' => round((float) $pageTotals['gross'], 2),
                    'ded' => round((float) $pageTotals['ded'], 2),
                    'net' => round((float) $pageTotals['net'], 2),
                ];
            }

            $periodPreview = null;
            if ($periodFromPayslip instanceof Payslip) {
                $probe = $employees->first();
                [$probeFrom, $probeTo, $probeCyclePreview, $probeCycle] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
                $resolvedCycleName = $periodFromPayslip->payCycle?->name
                    ?? ($probeCycle?->name)
                    ?? null;
                $resolvedCycleLabel = $periodFromPayslip->cycle_label
                    ?? ($probeCyclePreview['cycle_label'] ?? null);
                $resolvedStart = $periodFromPayslip->pay_period_start?->toDateString()
                    ?? $probeFrom->toDateString();
                $resolvedEnd = $periodFromPayslip->pay_period_end?->toDateString()
                    ?? $probeTo->toDateString();
                $resolvedPayDate = $periodFromPayslip->pay_date?->toDateString()
                    ?? ($probeCyclePreview['pay_date'] ?? null);
                $sourceLabel = ! empty($periodInput['pay_cycle_id'])
                    ? 'Selected pay cycle'
                    : ($this->payCycleService->isPayCycleInheritedFromCompany($probe) ? 'Inherited from company' : 'Inherited from employee');
                $periodPreview = [
                    'pay_cycle_name' => $resolvedCycleName,
                    'cycle_label' => $resolvedCycleLabel,
                    'cut_off_start_date' => $resolvedStart,
                    'cut_off_end_date' => $resolvedEnd,
                    'next_cut_off_start' => $resolvedStart,
                    'next_cut_off_end' => $resolvedEnd,
                    'pay_date' => $resolvedPayDate,
                    'next_pay_date' => $resolvedPayDate,
                    'pay_cycle_source_label' => $sourceLabel,
                    // Requirement: Reference Date must be the Pay Date.
                    'reference_date' => $resolvedPayDate,
                ];
            } else {
                $probe = $employees->first();
                [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
                $sourceLabel = ! empty($periodInput['pay_cycle_id'])
                    ? 'Selected pay cycle'
                    : ($this->payCycleService->isPayCycleInheritedFromCompany($probe) ? 'Inherited from company' : 'Inherited from employee');
                $resolvedPayDate = is_array($cyclePreview) ? ($cyclePreview['pay_date'] ?? null) : null;
                $periodPreview = [
                    'pay_cycle_name' => $cycle?->name,
                    'cycle_label' => is_array($cyclePreview) ? ($cyclePreview['cycle_label'] ?? $cycle?->name) : $cycle?->name,
                    'cut_off_start_date' => $from->toDateString(),
                    'cut_off_end_date' => $to->toDateString(),
                    'next_cut_off_start' => $from->toDateString(),
                    'next_cut_off_end' => $to->toDateString(),
                    'pay_date' => $resolvedPayDate,
                    'next_pay_date' => $resolvedPayDate,
                    'pay_cycle_source_label' => $sourceLabel,
                    // Requirement: Reference Date must be the Pay Date.
                    'reference_date' => $resolvedPayDate,
                ];
            }

            $totalResponseMs = round((microtime(true) - $previewStartedAt) * 1000, 2);
            Log::info('Payroll finalize preview: timings', [
                'employee_query_ms' => $employeeQueryMs,
                'generation_loop_ms' => $generationLoopMs,
                'total_response_ms' => $totalResponseMs,
                'rows_returned' => count($rows),
                'scoped_count' => $scopedCount,
                'needs_compute' => count($needsComputeIds),
            ]);

            return [
                'totals' => [
                    'total_gross' => $resolvedTotals['gross'],
                    'total_deductions' => $resolvedTotals['ded'],
                    'total_net' => $resolvedTotals['net'],
                    'employee_count' => $scopedCount,
                ],
                'period_preview' => $periodPreview,
                'employees' => $rows,
                'scoped_count' => $scopedCount,
                'pagination' => [
                    'page' => $currentPage,
                    'per_page' => $limit,
                    'total' => $scopedCount,
                    'last_page' => max(1, (int) ceil($scopedCount / max(1, $limit))),
                ],
            ];
        })();

        Log::info('Payroll finalize preview: summary served', [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $singleEmployeeId,
            'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
        ]);

        $employees = $this->mergeFreshPayslipStatusesIntoPreviewRows(
            $cached['employees'] ?? [],
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodInput,
            $actor
        );
        $batchRun = $this->resolvePayrollBatchRunSnapshot(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodInput,
            $actor
        );
        $periodLocked = $this->computePeriodLockedForPreview($batchRun, $employees);

        $totals = $cached['totals'] ?? [
            'total_gross' => 0.0,
            'total_deductions' => 0.0,
            'total_net' => 0.0,
            'employee_count' => 0,
        ];
        if ($batchRun !== null && (string) ($batchRun['status'] ?? '') === PayrollBatchRun::STATUS_FINALIZED) {
            $runModel = PayrollBatchRun::query()->find((int) ($batchRun['payroll_batch_run_id'] ?? 0));
            if ($runModel instanceof PayrollBatchRun) {
                $agg = $this->payslipService->aggregateForBatchRun($runModel);
                $totals = [
                    'total_gross' => $agg['total_gross_pay'],
                    'total_deductions' => $agg['total_deductions'],
                    'total_net' => $agg['total_net_pay'],
                    'employee_count' => max(
                        (int) ($cached['totals']['employee_count'] ?? 0),
                        (int) $agg['payslip_count'],
                        (int) ($runModel->employee_count ?? 0)
                    ),
                ];
            }
        }

        return [
            'totals' => $totals,
            'period_preview' => $cached['period_preview'] ?? null,
            'employees' => $employees,
            'batch_run' => $batchRun,
            'period_locked' => $periodLocked,
            'pagination' => $cached['pagination'] ?? [
                'page' => 1,
                'per_page' => 25,
                'total' => (int) ($cached['totals']['employee_count'] ?? 0),
                'last_page' => 1,
            ],
        ];
    }

    /**
     * Lightweight preview while the Redis payroll worker is still filling draft rows.
     *
     * This avoids recomputing missing employees synchronously on the Finalize Payroll page;
     * rows appear here only after the background job persists them.
     *
     * @param  array<string, mixed>  $batchRun
     * @return array<string, mixed>
     */
    private function processingPreviewFromStoredPayslips(
        array $batchRun,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor,
        int $page,
        int $perPage,
        ?string $search
    ): array {
        $runId = (int) ($batchRun['payroll_batch_run_id'] ?? 0);
        $run = $runId > 0 ? PayrollBatchRun::query()->find($runId) : null;
        $limit = max(1, min(100, $perPage));
        $currentPage = max(1, $page);
        $offset = ($currentPage - 1) * $limit;

        if (! $run instanceof PayrollBatchRun) {
            return [
                'totals' => [
                    'total_gross' => 0.0,
                    'total_deductions' => 0.0,
                    'total_net' => 0.0,
                    'employee_count' => 0,
                ],
                'period_preview' => null,
                'employees' => [],
                'batch_run' => $batchRun,
                'period_locked' => false,
                'pagination' => [
                    'page' => 1,
                    'per_page' => $limit,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        $this->attachMatchingPayslipsToBatchRun($run);

        $query = $this->payslipQueryForBatchRun($run)
            ->with([
                'employee:id,name,first_name,middle_name,last_name,suffix,email,employee_code,department,position,profile_image,role,company_id,branch_id,department_id,pay_cycle_id,monthly_salary,monthly_rate,daily_rate,working_schedule_id,schedule,employment_status,is_active',
                'employee.departmentRelation:id,name',
            ]);
        $expectedStatuses = (string) $run->status === PayrollBatchRun::STATUS_FINALIZED
            ? Payslip::lockingStatuses()
            : $this->draftSnapshotStatuses();
        $staleRowsExcluded = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where(function ($stale) use ($run, $expectedStatuses): void {
                $stale->whereNotNull('voided_at')
                    ->orWhereNotIn('status', $expectedStatuses);
                if ($run->company_id !== null) {
                    $stale->orWhere('company_id', '!=', (int) $run->company_id);
                }
            })
            ->count();

        if ($search !== null && trim($search) !== '') {
            $like = '%'.trim($search).'%';
            $query->whereHas('employee', function ($employeeQuery) use ($like) {
                $this->applyEmployeeNameSearch($employeeQuery, $like);
            });
        }

        $storedCount = (clone $query)->count();
        if ($staleRowsExcluded > 0) {
            Log::warning('Payroll table excluded stale rows', [
                'payroll_run_id' => (int) $run->id,
                'selected_company_id' => $run->company_id !== null ? (int) $run->company_id : null,
                'stale_rows_excluded_count' => $staleRowsExcluded,
            ]);
        }
        $payslips = $this->orderPayslipQueryByEmployeeName($query)
            ->offset($offset)
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($payslips as $stored) {
            $employee = $stored->employee;
            if (! $employee instanceof User) {
                continue;
            }
            if ((string) $run->status !== PayrollBatchRun::STATUS_FINALIZED
                && $this->shouldRepairStoredDraftForPreview($stored)) {
                try {
                    $employee->loadMissing([
                        'company',
                        'branch',
                        'payCycle',
                        'governmentIds',
                        'workingSchedule',
                    ]);
                    $stored = $this->payslipService->refreshDraftPayslipFromLiveComputation($stored, $employee);
                    $stored->setRelation('employee', $employee);
                } catch (Throwable $e) {
                    Log::warning('Payroll table draft live refresh failed; falling back to stored snapshot', [
                        'payroll_run_id' => (int) $run->id,
                        'payslip_id' => (int) $stored->id,
                        'employee_id' => (int) $employee->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $summary = is_array($stored->snapshot['summary'] ?? null)
                ? (array) $stored->snapshot['summary']
                : [];
            $lineTotals = $this->payslipService->payslipTotalsForDisplay($stored);
            $summary = is_array($stored->snapshot['summary'] ?? null)
                ? (array) $stored->snapshot['summary']
                : $summary;
            $viewSnapshot = $this->payslipService->frozenSnapshotForPayslipView(
                is_array($stored->snapshot) ? $stored->snapshot : []
            );
            $viewSummary = is_array($viewSnapshot['summary'] ?? null) ? $viewSnapshot['summary'] : [];
            $tableGross = $lineTotals['gross_pay'];
            $tableDeductions = $lineTotals['total_deductions'];
            $tableNet = $lineTotals['net_pay'];
            $payslipGross = $tableGross;
            $payslipDeductions = $tableDeductions;
            $payslipNet = $tableNet;
            $mismatch = $tableGross !== $payslipGross
                || $tableDeductions !== $payslipDeductions
                || $tableNet !== $payslipNet;
            $logContext = [
                'payroll_run_id' => (int) $run->id,
                'selected_company_id' => $run->company_id !== null ? (int) $run->company_id : null,
                'employee_id' => (int) $employee->id,
                'payroll_employee_company_id' => $stored->company_id !== null ? (int) $stored->company_id : null,
                'payslip_company_id' => $stored->company_id !== null ? (int) $stored->company_id : null,
                'table_gross' => $tableGross,
                'table_deductions' => $tableDeductions,
                'table_net' => $tableNet,
                'payslip_gross' => $payslipGross,
                'payslip_deductions' => $payslipDeductions,
                'payslip_net' => $payslipNet,
                'mismatch' => $mismatch,
            ];
            $mismatch
                ? Log::warning('Payroll table summary mismatch', $logContext)
                : Log::debug('Payroll table summary matched payslip snapshot', $logContext);
            $resolvedHrRole = $this->hrRoleResolver->resolveForApprovalSubject($employee);
            $rows[] = [
                'payslip_id' => (int) $stored->id,
                'user_id' => (int) $employee->id,
                ...$this->employeePreviewIdentityFields($employee),
                'employee_code' => $employee->employee_code,
                'department' => $this->employeeDepartmentName($employee),
                'position' => $employee->position,
                'profile_image_url' => $employee->profile_image_url,
                'employee_hr_role' => $resolvedHrRole->value,
                'employee_role_label' => $resolvedHrRole->badgeLabel(),
                'basic_salary' => round((float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)), 2),
                'basic_salary_monthly' => round((float) (data_get($summary, 'compensation_breakdown.basic_salary', 0)), 2),
                'basic_salary_schedule_factor' => (float) ($summary['basic_salary_schedule_factor'] ?? 1),
                'daily_rate' => round((float) ($summary['daily_rate'] ?? 0), 2),
                'basic_pay' => round((float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)), 2),
                'actual_days_worked' => round((float) ($summary['actual_days_worked'] ?? 0), 2),
                'daily_computation_earning_lines' => is_array($viewSummary['daily_computation_earning_lines'] ?? null)
                    ? array_values($viewSummary['daily_computation_earning_lines'])
                    : [],
                'payslip_earning_lines' => is_array($viewSummary['payslip_earning_lines'] ?? null)
                    ? array_values($viewSummary['payslip_earning_lines'])
                    : [],
                'payslip_deduction_lines' => is_array($viewSummary['payslip_deduction_lines'] ?? null)
                    ? array_values($viewSummary['payslip_deduction_lines'])
                    : [],
                'payslip_custom_deduction_lines' => is_array($viewSummary['payslip_custom_deduction_lines'] ?? null)
                    ? array_values($viewSummary['payslip_custom_deduction_lines'])
                    : [],
                'attendance_display_summary' => is_array($summary['attendance_display_summary'] ?? null)
                    ? $summary['attendance_display_summary']
                    : [
                        'working_days_count' => 0,
                        'presence_days_count' => 0,
                        'lines' => [],
                        'total_regular_hours' => 0.0,
                        'total_presence_regular_hours' => 0.0,
                    ],
                'gross_pay' => $tableGross,
                'total_deductions' => $tableDeductions,
                'net_pay' => $tableNet,
                'status' => (string) ($stored->status ?? Payslip::STATUS_DRAFT),
                'delivered_at' => $stored->delivered_at !== null ? $stored->delivered_at->toIso8601String() : null,
                'is_sent' => (bool) ($stored->is_sent ?? false),
                'sent_at' => isset($stored->sent_at) && $stored->sent_at !== null ? $stored->sent_at->toIso8601String() : null,
                'has_stored_payslip' => true,
            ];
        }

        $uniqueIds = $this->payslipService->latestUniquePayslipIdsForQuery($this->payslipQueryForBatchRun($run));
        $uniqueSums = $this->payslipService->sumUniquePayslipsByIds($uniqueIds);
        $totalEmployees = max(
            (int) ($batchRun['total_employees'] ?? 0),
            (int) ($run->total_employees ?? 0),
            (int) ($run->employee_count ?? 0),
            $uniqueSums['employee_count'],
            $storedCount
        );

        return [
            'totals' => [
                'total_gross' => $uniqueSums['employee_count'] > 0
                    ? $uniqueSums['total_gross']
                    : round((float) ($run->total_gross ?? 0), 2),
                'total_deductions' => $uniqueSums['employee_count'] > 0
                    ? $uniqueSums['total_deductions']
                    : round((float) ($run->total_deductions ?? 0), 2),
                'total_net' => $uniqueSums['employee_count'] > 0
                    ? $uniqueSums['total_net']
                    : round((float) ($run->total_net ?? 0), 2),
                'employee_count' => $totalEmployees,
            ],
            'period_preview' => [
                'pay_cycle_name' => null,
                'cycle_label' => null,
                'cut_off_start_date' => $run->pay_period_start?->toDateString(),
                'cut_off_end_date' => $run->pay_period_end?->toDateString(),
                'next_cut_off_start' => $run->pay_period_start?->toDateString(),
                'next_cut_off_end' => $run->pay_period_end?->toDateString(),
                'pay_date' => $run->reference_date?->toDateString(),
                'next_pay_date' => $run->reference_date?->toDateString(),
                'pay_cycle_source_label' => $run->pay_cycle_id ? 'Selected pay cycle' : 'Company default',
                'reference_date' => $run->reference_date?->toDateString(),
            ],
            'employees' => $rows,
            'batch_run' => $batchRun,
            'period_locked' => false,
            'pagination' => [
                'page' => $currentPage,
                'per_page' => $limit,
                'total' => $totalEmployees,
                'last_page' => max(1, (int) ceil(max(1, $totalEmployees) / $limit)),
                'computed_rows' => $storedCount,
            ],
        ];
    }

    private function shouldRepairStoredDraftForPreview(Payslip $payslip): bool
    {
        $snapshot = is_array($payslip->snapshot)
            ? $payslip->snapshot
            : (is_string($payslip->snapshot) ? json_decode($payslip->snapshot, true) : []);
        if (! is_array($snapshot)) {
            return true;
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        if ($summary === []) {
            return true;
        }

        foreach ([
            'daily_computation_earning_lines',
            'payslip_earning_lines',
            'payslip_deduction_lines',
            'payslip_custom_deduction_lines',
        ] as $key) {
            if (count(is_array($summary[$key] ?? null) ? $summary[$key] : []) > 0) {
                return false;
            }
        }

        return abs((float) ($payslip->gross_pay ?? 0)) >= 0.01
            || abs((float) ($payslip->total_deductions ?? 0)) >= 0.01
            || abs((float) ($payslip->net_pay ?? 0)) >= 0.01;
    }

    /**
     * Current {@see PayrollBatchRun} for this scope + pay window.
     *
     * @param  array<string, mixed>  $periodInput
     * @return array<string, mixed>|null
     */
    public function resolvePayrollBatchRunSnapshot(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor = null
    ): ?array {
        $scopedEmployeesQuery = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor);
        if (! (clone $scopedEmployeesQuery)->exists()) {
            return null;
        }
        $probe = (clone $scopedEmployeesQuery)->orderByLastName()->first();
        if ($probe === null) {
            return null;
        }
        $pi = $periodInput;
        [$periodStart, $periodEnd, $probeCyclePreview] = $this->payslipService->resolveComputationWindow($probe, $pi);
        if (empty($pi['reference_date']) && is_array($probeCyclePreview) && ! empty($probeCyclePreview['pay_date'])) {
            $pi['reference_date'] = (string) $probeCyclePreview['pay_date'];
        }
        $batchKey = $this->makeBatchKey(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            $pi['pay_cycle_id'] ?? null
        );
        $run = PayrollBatchRun::query()->where('batch_key', $batchKey)->first();
        if ($run === null) {
            return null;
        }
        $total = max((int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0));
        $processed = max(0, (int) ($run->processed_employees ?? 0));
        $failed = max(0, (int) ($run->failed_employees ?? 0));
        if (in_array((string) $run->status, [PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FINALIZED], true)) {
            $processed = max($processed, $total, (int) ($run->employee_count ?? 0));
            $total = max($total, $processed);
        }

        return [
            'payroll_batch_run_id' => (int) $run->id,
            'batch_key' => $batchKey,
            'status' => (string) $run->status,
            'progress_status' => match ((string) $run->status) {
                PayrollBatchRun::STATUS_QUEUED => 'pending',
                PayrollBatchRun::STATUS_PROCESSING => 'processing',
                PayrollBatchRun::STATUS_DRAFT, PayrollBatchRun::STATUS_FINALIZED => 'completed',
                PayrollBatchRun::STATUS_FAILED => 'failed',
                default => 'pending',
            },
            'total_employees' => $total,
            'processed_employees' => $processed,
            'failed_employees' => $failed,
            'progress_percent' => $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0,
            'finalized_at' => $run->finalized_at?->toIso8601String(),
            'finalized_by_user_id' => $run->finalized_by_user_id ? (int) $run->finalized_by_user_id : null,
            'error_message' => $run->error_message,
            'pay_period_start' => $run->pay_period_start?->toDateString(),
            'pay_period_end' => $run->pay_period_end?->toDateString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $periodInput
     * @return list<array<string, mixed>>
     */
    private function mergeFreshPayslipStatusesIntoPreviewRows(
        array $rows,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor
    ): array {
        if ($rows === []) {
            return $rows;
        }
        $userIds = [];
        foreach ($rows as $r) {
            if (! empty($r['user_id'])) {
                $userIds[] = (int) $r['user_id'];
            }
        }
        $userIds = array_values(array_unique(array_filter($userIds, fn ($id) => $id > 0)));
        if ($userIds === []) {
            return $rows;
        }
        $probe = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor)
            ->orderByLastName()
            ->first();
        if ($probe === null) {
            return $rows;
        }
        [$from, $to] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
        $statusByUserId = Payslip::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('pay_period_start', $from->toDateString())
            ->whereDate('pay_period_end', $to->toDateString())
            ->orderByDesc('id')
            ->get(['user_id', 'status', 'delivered_at', 'is_sent', 'sent_at'])
            ->unique('user_id')
            ->keyBy('user_id');

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid > 0 && isset($statusByUserId[$uid])) {
                $p = $statusByUserId[$uid];
                $row['status'] = (string) $p->status;
                $row['delivered_at'] = $p->delivered_at !== null
                    ? $p->delivered_at->toIso8601String()
                    : null;
                $row['is_sent'] = (bool) ($p->is_sent ?? false);
                $row['sent_at'] = isset($p->sent_at) && $p->sent_at !== null
                    ? $p->sent_at->toIso8601String()
                    : null;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $batchRun
     * @param  list<array<string, mixed>>  $employees
     */
    private function computePeriodLockedForPreview(?array $batchRun, array $employees): bool
    {
        if ($batchRun !== null && (($batchRun['status'] ?? '') === PayrollBatchRun::STATUS_FAILED)) {
            return false;
        }
        if ($batchRun !== null && (($batchRun['status'] ?? '') === PayrollBatchRun::STATUS_FINALIZED)) {
            return true;
        }
        if ($employees === []) {
            return false;
        }
        $done = array_map(static fn (string $s): string => strtolower($s), Payslip::lockingStatuses());
        foreach ($employees as $row) {
            $s = strtolower(trim((string) ($row['status'] ?? '')));
            if (! in_array($s, $done, true)) {
                return false;
            }
        }

        return true;
    }

    private function applyPreviewPeriodFiltersEloquent(
        \Illuminate\Database\Eloquent\Builder $query,
        array $periodInput
    ): \Illuminate\Database\Eloquent\Builder {
        if (! empty($periodInput['payroll_period_id'])) {
            $query->where('payroll_period_id', (int) $periodInput['payroll_period_id']);
        }
        if (! empty($periodInput['pay_cycle_id'])) {
            $query->where('pay_cycle_id', (int) $periodInput['pay_cycle_id']);
        }
        if (array_key_exists('is_final_pay', $periodInput) && $periodInput['is_final_pay'] !== null) {
            $query->where('is_final_pay', (bool) $periodInput['is_final_pay']);
        }
        if (! empty($periodInput['from_date'])) {
            $query->whereDate('pay_period_start', '>=', (string) $periodInput['from_date']);
        }
        if (! empty($periodInput['to_date'])) {
            $query->whereDate('pay_period_end', '<=', (string) $periodInput['to_date']);
        }

        return $query;
    }

    private function applyPreviewPeriodFilters(
        \Illuminate\Database\Query\Builder $query,
        array $periodInput,
        string $prefix = ''
    ): \Illuminate\Database\Query\Builder {
        $col = static fn (string $name): string => $prefix !== '' ? "{$prefix}.{$name}" : $name;

        if (! empty($periodInput['payroll_period_id'])) {
            $query->where($col('payroll_period_id'), (int) $periodInput['payroll_period_id']);
        }
        if (! empty($periodInput['pay_cycle_id'])) {
            $query->where($col('pay_cycle_id'), (int) $periodInput['pay_cycle_id']);
        }
        if (array_key_exists('is_final_pay', $periodInput) && $periodInput['is_final_pay'] !== null) {
            $query->where($col('is_final_pay'), (bool) $periodInput['is_final_pay']);
        }
        if (! empty($periodInput['from_date'])) {
            $query->whereDate($col('pay_period_start'), '>=', (string) $periodInput['from_date']);
        }
        if (! empty($periodInput['to_date'])) {
            $query->whereDate($col('pay_period_end'), '<=', (string) $periodInput['to_date']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $periodInput
     * @return array<string, mixed>
     */
    public function finalizeBatch(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        int $adminUserId,
        ?User $actor = null,
        ?int $existingBatchRunId = null,
        bool $skipExistingBatchKeyCheck = false
    ): array {
        if ($companyId !== null) {
            $periodInput['company_id'] = $companyId;
        }
        if ($branchId !== null) {
            $periodInput['branch_id'] = $branchId;
        }
        if ($departmentId !== null) {
            $periodInput['department_id'] = $departmentId;
        }

        $payrollModule = PayrollBatchRun::MODULE_STANDARD;
        $eligibilityPeriodStart = null;
        $eligibilityPeriodEnd = null;
        if ($existingBatchRunId !== null) {
            $existingRunForModule = PayrollBatchRun::query()->find($existingBatchRunId);
            if ($existingRunForModule instanceof PayrollBatchRun) {
                $payrollModule = $this->normalizePayrollModule((string) ($existingRunForModule->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
                $eligibilityPeriodStart = $existingRunForModule->pay_period_start;
                $eligibilityPeriodEnd = $existingRunForModule->pay_period_end;
                $this->assertFinalizeModuleGuards($existingRunForModule);
                $this->payslipService->cleanupStaleBatchModulePayslips($existingRunForModule);
            }
        }

        $employees = $this->scopedEmployees(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $actor,
            null,
            $eligibilityPeriodStart,
            $eligibilityPeriodEnd,
            $payrollModule
        )
            ->orderByLastName()
            ->get();

        if ($employees->isEmpty()) {
            throw new \RuntimeException('No active employees in scope.');
        }

        if ($employees->count() > self::FINALIZE_MAX_EMPLOYEES) {
            throw new \RuntimeException('Too many employees in scope (max '.self::FINALIZE_MAX_EMPLOYEES.'). Narrow by branch or department.');
        }

        // Avoid repeated lazy-loading lookups while finalizing each employee.
        $employees->loadMissing([
            'company',
            'branch',
            'payCycle',
            'governmentIds',
            'workingSchedule',
        ]);

        $probe = $employees->first();
        [$periodStart, $periodEnd, $probeCyclePreview] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
        // Requirement: Reference Date must be the Pay Date.
        if (empty($periodInput['reference_date']) && is_array($probeCyclePreview) && ! empty($probeCyclePreview['pay_date'])) {
            $periodInput['reference_date'] = (string) $probeCyclePreview['pay_date'];
        }
        $batchKey = $this->makeBatchKey(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            $periodInput['pay_cycle_id'] ?? null
        );

        $fromDate = $periodStart->toDateString();
        $toDate = $periodEnd->toDateString();

        if ($existingBatchRunId !== null) {
            $existingRun = PayrollBatchRun::query()->find($existingBatchRunId);
            if ($existingRun instanceof PayrollBatchRun && (string) $existingRun->status === PayrollBatchRun::STATUS_FINALIZED) {
                $this->logDuplicateFinalizeAttempt($existingRun, 'finalizeBatch');

                return $this->idempotentFinalizeResult($existingRun);
            }
            if ($existingRun instanceof PayrollBatchRun) {
                $this->attachMatchingPayslipsToBatchRun($existingRun);
                $draftUserIds = $this->payslipQueryForBatchRun($existingRun)
                    ->whereNotNull('snapshot')
                    ->pluck('user_id')
                    ->unique()
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();
                $expectedSavedRosterCount = (int) ($existingRun->employee_count ?? 0);
                if ($draftUserIds !== [] && ($expectedSavedRosterCount <= 0 || count($draftUserIds) === $expectedSavedRosterCount)) {
                    if (count($draftUserIds) !== $employees->count()) {
                        Log::info('Payroll finalize using saved draft roster instead of changed live eligibility roster', [
                            'payroll_run_id' => (int) $existingRun->id,
                            'selected_company_id' => $companyId,
                            'saved_draft_employee_count' => count($draftUserIds),
                            'live_eligible_employee_count' => $employees->count(),
                        ]);
                    }
                    $employees = User::query()
                        ->whereIn('id', $draftUserIds)
                        ->orderByLastName()
                        ->get();
                } elseif ($draftUserIds !== []) {
                    Log::warning('Payroll finalize saved draft roster count does not match batch employee_count', [
                        'payroll_run_id' => (int) $existingRun->id,
                        'selected_company_id' => $companyId,
                        'saved_draft_employee_count' => count($draftUserIds),
                        'batch_employee_count' => $expectedSavedRosterCount,
                        'live_eligible_employee_count' => $employees->count(),
                    ]);
                }
            }
        }

        $employees->loadMissing([
            'company',
            'branch',
            'payCycle',
            'governmentIds',
            'workingSchedule',
        ]);

        if ($existingBatchRunId !== null
            && $this->canFinalizeUsingDraftPayslipsOnly(
                $employees,
                $fromDate,
                $toDate,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $existingBatchRunId
            )) {
            return $this->finalizeBatchUsingDraftPayslipsWithoutRecompute(
                $employees,
                $periodInput,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $batchKey,
                $periodStart,
                $periodEnd,
                $adminUserId,
                $existingBatchRunId,
                $fromDate,
                $toDate
            );
        }

        if ($existingBatchRunId !== null) {
            throw new \RuntimeException(
                'Cannot finalize: draft payslip snapshots are missing or incomplete. Regenerate the payroll draft first.'
            );
        }

        $existingKeyRun = PayrollBatchRun::query()
            ->where('batch_key', $batchKey)
            ->orderByDesc('id')
            ->first();
        if ($existingKeyRun instanceof PayrollBatchRun) {
            $status = (string) $existingKeyRun->status;
            if ($status === PayrollBatchRun::STATUS_FINALIZED) {
                throw new \RuntimeException('This payroll batch is already finalized.');
            }
            if ($status === PayrollBatchRun::STATUS_VOIDED) {
                throw new \RuntimeException('This payroll period was voided. Regenerate a new payroll draft to continue.');
            }
            if ($status === PayrollBatchRun::STATUS_PROCESSING) {
                throw new \RuntimeException('A payroll finalize job is already queued or running for this scope.');
            }
        }

        $existingDraftRun = null;
        if ($existingKeyRun instanceof PayrollBatchRun
            && in_array((string) $existingKeyRun->status, [
                PayrollBatchRun::STATUS_DRAFT,
                PayrollBatchRun::STATUS_QUEUED,
                PayrollBatchRun::STATUS_FAILED,
            ], true)) {
            $existingDraftRun = $existingKeyRun;
        }
        if ($existingDraftRun instanceof PayrollBatchRun
            && $this->canFinalizeUsingDraftPayslipsOnly(
                $employees,
                $fromDate,
                $toDate,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                (int) $existingDraftRun->id
            )) {
            return $this->finalizeBatchUsingDraftPayslipsWithoutRecompute(
                $employees,
                $periodInput,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $batchKey,
                $periodStart,
                $periodEnd,
                $adminUserId,
                (int) $existingDraftRun->id,
                $fromDate,
                $toDate
            );
        }

        throw new \RuntimeException(
            'Cannot finalize: draft payslip snapshots are missing or incomplete. Regenerate the payroll draft first.'
        );
    }

    /**
     * @param  array<string, mixed>  $periodInput
     */
    public function queueFinalizeBatch(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        int $adminUserId,
        ?User $actor = null
    ): array {
        $employees = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor)
            ->orderByLastName()
            ->get();
        if ($employees->isEmpty()) {
            throw new \RuntimeException('No active employees in scope.');
        }
        if ($employees->count() > self::FINALIZE_MAX_EMPLOYEES) {
            throw new \RuntimeException('Too many employees in scope (max '.self::FINALIZE_MAX_EMPLOYEES.'). Narrow by branch or department.');
        }

        $probe = $employees->first();
        [$periodStart, $periodEnd, $probeCyclePreview] = $this->payslipService->resolveComputationWindow($probe, $periodInput);
        // Requirement: Reference Date must be the Pay Date.
        if (empty($periodInput['reference_date']) && is_array($probeCyclePreview) && ! empty($probeCyclePreview['pay_date'])) {
            $periodInput['reference_date'] = (string) $probeCyclePreview['pay_date'];
        }
        $batchKey = $this->makeBatchKey(
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
            $periodInput['pay_cycle_id'] ?? null
        );
        $existing = PayrollBatchRun::query()->where('batch_key', $batchKey)->first();
        if ($existing && (string) $existing->status === PayrollBatchRun::STATUS_VOIDED) {
            $existing = null;
        }
        if ($existing) {
            $status = (string) $existing->status;
            if ($status === PayrollBatchRun::STATUS_FINALIZED) {
                $this->logDuplicateFinalizeAttempt($existing, 'queueFinalizeBatch');
                throw new \RuntimeException('This payroll batch is already finalized.');
            }
            if ($status === PayrollBatchRun::STATUS_PROCESSING) {
                return ['run' => $existing, 'should_dispatch' => false];
            }
            if ($status === PayrollBatchRun::STATUS_QUEUED) {
                return ['run' => $existing, 'should_dispatch' => true];
            }

            // failed/legacy/unknown statuses: recycle same run id for retry
            if ($status === PayrollBatchRun::STATUS_FAILED) {
                // Job later sees QUEUED, not FAILED — reconcile here so retry is not blocked by stale locks.
                $this->recoverLocksAfterFailedBatchRun($existing, $actor);
            }
            $retryPayload = $this->filterBatchRunPayload([
                'status' => PayrollBatchRun::STATUS_QUEUED,
                'error_message' => null,
                'queued_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'total_employees' => $employees->count(),
                'processed_employees' => 0,
                'failed_employees' => 0,
                'finalized_at' => null,
                'finalized_by_user_id' => $adminUserId,
            ]);
            PayrollBatchRun::query()->whereKey($existing->id)->update($retryPayload);

            return ['run' => $existing->fresh(), 'should_dispatch' => true];
        }

        $payload = [
            'batch_key' => $batchKey,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $singleEmployeeId,
            'pay_period_start' => $periodStart->toDateString(),
            'pay_period_end' => $periodEnd->toDateString(),
            'pay_cycle_id' => $periodInput['pay_cycle_id'] ?? null,
            'payroll_period_id' => $periodInput['payroll_period_id'] ?? null,
            'is_final_pay' => (bool) ($periodInput['is_final_pay'] ?? false),
            'password_protect' => (bool) ($periodInput['password_protect'] ?? false),
            'reference_date' => isset($periodInput['reference_date'])
                ? Carbon::parse((string) $periodInput['reference_date'])->toDateString()
                : null,
            'status' => PayrollBatchRun::STATUS_QUEUED,
            'employee_count' => $employees->count(),
            'total_employees' => $employees->count(),
            'processed_employees' => 0,
            'failed_employees' => 0,
            'queued_at' => now(),
            'finalized_by_user_id' => $adminUserId,
        ];

        $run = PayrollBatchRun::create($this->filterBatchRunPayload($payload));

        return ['run' => $run, 'should_dispatch' => true];
    }

    /**
     * After a queued job failed, payslips/periods can still be finalized without a successful batch row.
     * Runs orphan reconcile for every employee in this batch scope (ignores PAYROLL_AUTO_RECONCILE_ORPHAN_LOCKS).
     */
    public function recoverLocksAfterFailedBatchRun(PayrollBatchRun $run, ?User $actor = null): void
    {
        $companyId = $run->company_id ? (int) $run->company_id : null;
        if ($companyId === null || $companyId <= 0 || $run->pay_period_start === null || $run->pay_period_end === null) {
            return;
        }

        $from = Carbon::parse($run->pay_period_start)->startOfDay();
        $to = Carbon::parse($run->pay_period_end)->startOfDay();

        $userIds = $this->scopedEmployees(
            $companyId,
            $run->branch_id ? (int) $run->branch_id : null,
            $run->department_id ? (int) $run->department_id : null,
            $run->employee_id ? (int) $run->employee_id : null,
            $actor
        )->pluck('id');

        foreach ($userIds as $uid) {
            PayrollPeriodOrphanLockService::reconcileForUserWindow((int) $uid, $from, $to, true);
        }

        Log::notice('Payroll finalize: reconciled locks after failed batch run (pre-retry)', [
            'batch_run_id' => (int) $run->id,
            'batch_key' => $run->batch_key,
            'employees_reconciled' => $userIds->count(),
        ]);
    }

    public function finalizeQueuedRun(PayrollBatchRun $run, ?User $actor = null): array
    {
        return DB::transaction(function () use ($run, $actor) {
            $locked = PayrollBatchRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked instanceof PayrollBatchRun) {
                throw new \RuntimeException('Payroll batch run not found.');
            }

            $statusBefore = (string) $locked->status;
            $netBefore = (float) ($locked->total_net ?? 0);
            $scopedQuery = $this->payslipQueryForBatchRun($locked);
            $payslipRowsBefore = (clone $scopedQuery)->count();
            $uniqueBefore = count($this->payslipService->latestUniquePayslipIdsForQuery($scopedQuery));

            if ($statusBefore === PayrollBatchRun::STATUS_FINALIZED) {
                $this->logDuplicateFinalizeAttempt($locked, 'finalizeQueuedRun');
                $result = $this->idempotentFinalizeResult($locked);
                Log::info('Finalize payroll skipped: batch already finalized', [
                    'batch_run_id' => (int) $locked->id,
                    'status_before' => $statusBefore,
                    'status_after' => PayrollBatchRun::STATUS_FINALIZED,
                    'payslip_rows_count' => $payslipRowsBefore,
                    'unique_payslip_rows_count' => $uniqueBefore,
                    'duplicate_payslip_rows_detected' => max(0, $payslipRowsBefore - $uniqueBefore),
                    'total_net_before' => $netBefore,
                    'total_net_after' => (float) ($result['totals']['total_net'] ?? $netBefore),
                ]);

                return $result;
            }

            $periodInput = [
                'from_date' => $locked->pay_period_start?->toDateString(),
                'to_date' => $locked->pay_period_end?->toDateString(),
                'pay_cycle_id' => $locked->pay_cycle_id,
                'reference_date' => $locked->reference_date?->toDateString(),
                'company_id' => $locked->company_id ? (int) $locked->company_id : null,
                'branch_id' => $locked->branch_id ? (int) $locked->branch_id : null,
                'department_id' => $locked->department_id ? (int) $locked->department_id : null,
                'payroll_period_id' => $locked->payroll_period_id,
                'is_final_pay' => (bool) $locked->is_final_pay,
                'password_protect' => (bool) $locked->password_protect,
            ];

            $adminUserId = (int) ($locked->finalized_by_user_id ?? 0);
            if ($adminUserId <= 0 && $actor !== null) {
                $adminUserId = (int) $actor->id;
            }

            $result = $this->finalizeBatch(
                $locked->company_id ? (int) $locked->company_id : null,
                $locked->branch_id ? (int) $locked->branch_id : null,
                $locked->department_id ? (int) $locked->department_id : null,
                $locked->employee_id ? (int) $locked->employee_id : null,
                $periodInput,
                $adminUserId,
                $actor,
                (int) $locked->id,
                true
            );

            $lockedAfter = PayrollBatchRun::query()->find((int) $locked->id);
            $scopedAfter = $lockedAfter instanceof PayrollBatchRun
                ? $this->payslipQueryForBatchRun($lockedAfter)
                : $scopedQuery;
            $payslipRowsAfter = (clone $scopedAfter)->count();
            $uniqueAfter = count($this->payslipService->latestUniquePayslipIdsForQuery($scopedAfter));

            Log::info('Finalize payroll completed', [
                'batch_run_id' => (int) $locked->id,
                'status_before' => $statusBefore,
                'status_after' => (string) ($lockedAfter->status ?? $statusBefore),
                'payslip_rows_count' => $payslipRowsAfter,
                'unique_payslip_rows_count' => $uniqueAfter,
                'duplicate_payslip_rows_detected' => max(0, $payslipRowsAfter - $uniqueAfter),
                'total_net_before' => $netBefore,
                'total_net_after' => (float) ($result['totals']['total_net'] ?? 0),
                'skipped' => (bool) ($result['skipped'] ?? false),
            ]);

            return $result;
        });
    }

    /**
     * Void a finalized payroll batch: preserve snapshots, do not revert to draft or recompute.
     *
     * @return array{voided_payslips: int, unlocked_payroll_periods: int, payroll_batch_run_id: int, status: string}
     */
    public function deleteFinalizedPayrollBatch(int $batchId, User $actor, ?string $reason = null): array
    {
        $run = PayrollBatchRun::query()->findOrFail($batchId);
        $status = strtolower(trim((string) $run->status));
        if ($status === PayrollBatchRun::STATUS_VOIDED) {
            throw new \RuntimeException('This payroll batch has already been voided.');
        }
        if ($status !== PayrollBatchRun::STATUS_FINALIZED) {
            throw new \RuntimeException('Only finalized payroll batches can be deleted.');
        }

        $baseQuery = $this->payslipQueryForBatchRun($run);
        $targetUserIds = (clone $baseQuery)->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($targetUserIds !== [] && ! $actor->isAdmin()) {
            $accessibleIds = $this->scopedEmployees(
                $run->company_id ? (int) $run->company_id : null,
                $run->branch_id ? (int) $run->branch_id : null,
                $run->department_id ? (int) $run->department_id : null,
                $run->employee_id ? (int) $run->employee_id : null,
                $actor
            )->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($targetUserIds, $accessibleIds);
            if ($missing !== []) {
                throw new \RuntimeException('You do not have access to delete this finalized batch.');
            }
        }

        $voidedPayslips = 0;
        $unlockedPayrollPeriods = 0;
        $previousStatus = (string) $run->status;
        $originalBatchKey = (string) $run->batch_key;

        DB::transaction(function () use (
            $run,
            $targetUserIds,
            $actor,
            $reason,
            $originalBatchKey,
            &$voidedPayslips,
            &$unlockedPayrollPeriods
        ) {
            $rows = $this->payslipQueryForBatchRun($run)
                ->lockForUpdate()
                ->get(['id', 'payroll_period_id']);
            $payslipIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $payrollPeriodIds = $rows->pluck('payroll_period_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $now = now();
            foreach ($payslipIds as $payslipId) {
                $updated = Payslip::query()->whereKey($payslipId)->update([
                    'status' => Payslip::STATUS_VOIDED,
                    'voided_at' => $now,
                    'period_slot' => $payslipId,
                    'is_sent' => false,
                    'delivered_at' => null,
                    'sent_at' => null,
                ]);
                if ($updated) {
                    $voidedPayslips++;
                }
            }

            if ($payrollPeriodIds !== []) {
                $unlockedPayrollPeriods = PayrollPeriod::query()
                    ->whereIn('id', $payrollPeriodIds)
                    ->where('status', PayrollPeriod::STATUS_LOCKED)
                    ->update(['status' => PayrollPeriod::STATUS_DRAFT]);
            } elseif ($targetUserIds !== [] && $run->pay_period_start !== null && $run->pay_period_end !== null) {
                $unlockedPayrollPeriods = PayrollPeriod::query()
                    ->whereIn('user_id', $targetUserIds)
                    ->whereDate('from_date', $run->pay_period_start->toDateString())
                    ->whereDate('to_date', $run->pay_period_end->toDateString())
                    ->where('status', PayrollPeriod::STATUS_LOCKED)
                    ->update(['status' => PayrollPeriod::STATUS_DRAFT]);
            }

            $run->update([
                'batch_key' => $originalBatchKey.':voided:'.$run->id,
                'status' => PayrollBatchRun::STATUS_VOIDED,
                'voided_at' => $now,
                'voided_by_user_id' => (int) $actor->id,
                'void_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            ]);

            if (\Illuminate\Support\Facades\Schema::hasColumn('overtimes', 'paid_payroll_run_id')) {
                Overtime::query()
                    ->where('paid_payroll_run_id', (int) $run->id)
                    ->update([
                        'paid_payroll_run_id' => null,
                        'paid_at' => null,
                    ]);
            }
        });

        UserAdminActivityLog::query()->create([
            'subject_user_id' => (int) $actor->id,
            'actor_user_id' => (int) $actor->id,
            'action' => 'finalized_payroll_batch_voided',
            'meta' => [
                'payroll_batch_run_id' => $batchId,
                'batch_key' => $originalBatchKey,
                'previous_status' => $previousStatus,
                'new_status' => PayrollBatchRun::STATUS_VOIDED,
                'action' => 'voided',
                'performed_by' => (int) $actor->id,
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'company_id' => $run->company_id,
                'branch_id' => $run->branch_id,
                'department_id' => $run->department_id,
                'employee_id' => $run->employee_id,
                'pay_period_start' => $run->pay_period_start?->toDateString(),
                'pay_period_end' => $run->pay_period_end?->toDateString(),
                'voided_payslips' => $voidedPayslips,
                'unlocked_payroll_periods' => $unlockedPayrollPeriods,
                'finalized_at' => $run->finalized_at?->toIso8601String(),
                'finalized_by_user_id' => $run->finalized_by_user_id,
            ],
            'ip_address' => request()->ip(),
        ]);

        Log::warning('Finalized payroll batch voided', [
            'actor_user_id' => (int) $actor->id,
            'payroll_batch_run_id' => $batchId,
            'batch_key' => $originalBatchKey,
            'previous_status' => $previousStatus,
            'new_status' => PayrollBatchRun::STATUS_VOIDED,
            'voided_payslips' => $voidedPayslips,
            'unlocked_payroll_periods' => $unlockedPayrollPeriods,
            'reason' => $reason,
        ]);

        return [
            'voided_payslips' => (int) $voidedPayslips,
            'unlocked_payroll_periods' => (int) $unlockedPayrollPeriods,
            'payroll_batch_run_id' => $batchId,
            'status' => PayrollBatchRun::STATUS_VOIDED,
        ];
    }

    /**
     * Finalize one employee: same pipeline as batch (daily computation → persist → payslip PDF → lock period).
     * Batch run row stays draft until every payslip in scope is finalized.
     *
     * @param  array<string, mixed>  $periodInput
     * @return array{payslip_id: int, user_id: int, batch_all_finalized: bool}
     */
    public function finalizeEmployeePayslip(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        int $employeeUserId,
        array $periodInput,
        int $adminUserId,
        ?User $actor = null
    ): array {
        if ($companyId !== null) {
            $periodInput['company_id'] = $companyId;
        }
        if ($branchId !== null) {
            $periodInput['branch_id'] = $branchId;
        }
        if ($departmentId !== null) {
            $periodInput['department_id'] = $departmentId;
        }

        $employee = User::query()
            ->payrollEmployees()
            ->active()
            ->findOrFail($employeeUserId);

        $inScope = $this->scopedEmployees($companyId, $branchId, $departmentId, null, $actor)
            ->whereKey($employeeUserId)
            ->exists();
        if (! $inScope) {
            throw new \RuntimeException('Employee is not in the selected scope.');
        }

        $effectiveCompanyId = $companyId ? (int) $companyId : (int) ($employee->getEffectiveCompanyId() ?? 0);
        if ($effectiveCompanyId <= 0) {
            throw new \RuntimeException('Company context is required to finalize a payslip.');
        }

        [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($employee, $periodInput);

        $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $from, $to);

        $payslipId = 0;
        DB::transaction(function () use (
            $employee,
            $from,
            $to,
            $effectiveCompanyId,
            $employeeUserId,
            $adminUserId,
            $companyId,
            $branchId,
            $departmentId,
            &$payslipId
        ) {
            $draftPayslip = $this->queryDraftPayslipsForScope(
                [$employeeUserId],
                $from->toDateString(),
                $to->toDateString(),
                $companyId ?? $effectiveCompanyId,
                $branchId,
                $departmentId,
                $employeeUserId,
                null
            )->orderByDesc('id')->first();

            if (! $draftPayslip instanceof Payslip) {
                throw new \RuntimeException('Cannot finalize: draft payslip snapshot is missing. Regenerate the payroll draft first.');
            }

            $snapshotRaw = $draftPayslip->snapshot;
            $snapshot = is_array($snapshotRaw)
                ? $snapshotRaw
                : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
            if (! is_array($snapshot) || ! is_array($snapshot['summary'] ?? null) || $snapshot['summary'] === []) {
                throw new \RuntimeException('Draft payslip snapshot is missing summary data for user_id='.$employeeUserId);
            }

            $draftPayslip = $this->payslipService->refreshDraftPayslipFromLiveComputation($draftPayslip, $employee);
            $snapshot = is_array($draftPayslip->snapshot)
                ? $draftPayslip->snapshot
                : (is_string($draftPayslip->snapshot) ? json_decode($draftPayslip->snapshot, true) : []);
            if (! is_array($snapshot)) {
                $snapshot = [];
            }

            $draftPayslip = $this->assertDraftPayslipLinesArePersisted($draftPayslip);
            $draftMetrics = $this->payslipService->frozenPayslipLineMetrics($draftPayslip);
            $snapshot = $this->snapshotArrayFromPayslip($draftPayslip);
            $draftCatalog = $this->payslipService->payrollDeductionLineCatalog($snapshot);

            $this->payslipService->freezePayslipSnapshotForFinalization($draftPayslip);
            $payslipId = (int) $draftPayslip->id;

            $this->payslipService->finalizePayrollWindow(
                $effectiveCompanyId,
                $from->toDateString(),
                $to->toDateString(),
                null,
                [$employeeUserId],
                $adminUserId
            );

            $finalized = Payslip::query()->findOrFail($payslipId);
            $finalCatalog = $this->payslipService->payrollDeductionLineCatalog(
                is_array($finalized->snapshot)
                    ? $finalized->snapshot
                    : (is_string($finalized->snapshot) ? json_decode($finalized->snapshot, true) : [])
            );
            $deductionMismatches = $this->payslipService->deductionLineMismatches($draftCatalog, $finalCatalog);
            if ($deductionMismatches !== []) {
                throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
            }

            $this->validateDraftFinalizedFrozenMetrics(
                (int) ($draftPayslip->payroll_batch_run_id ?? 0),
                $effectiveCompanyId,
                $employeeUserId,
                $draftMetrics,
                $this->payslipService->frozenPayslipLineMetrics($finalized),
                false
            );
        });

        $batchKey = $this->makeBatchKey(
            $companyId,
            $branchId,
            $departmentId,
            null,
            $from->toDateString(),
            $to->toDateString(),
            $periodInput['pay_cycle_id'] ?? null
        );

        $batchAllFinalized = false;
        $run = PayrollBatchRun::query()->where('batch_key', $batchKey)->first();
        if ($run) {
            $this->payslipService->syncBatchRunTotals($run);
            $agg = $this->payslipService->aggregateForBatchRun($run);
            if ($agg['payslip_count'] > 0 && $agg['finalized_count'] >= $agg['payslip_count']) {
                $batchAllFinalized = true;
                $run->update($this->filterBatchRunPayload([
                    'status' => PayrollBatchRun::STATUS_FINALIZED,
                    'error_message' => null,
                    'completed_at' => now(),
                    'finalized_at' => now(),
                    'finalized_by_user_id' => $adminUserId,
                ]));
            }
        }

        return [
            'payslip_id' => $payslipId,
            'user_id' => $employeeUserId,
            'batch_all_finalized' => $batchAllFinalized,
        ];
    }

    /**
     * Shape of earnings/deduction/holiday line arrays before PDF (keys + first row keys).
     *
     * @param  array<string, mixed>  $computedSummary
     * @return array<string, mixed>
     */
    private function summarizeComputedSummaryTableArrays(array $computedSummary): array
    {
        $lineKeys = [
            'payslip_earning_lines',
            'daily_computation_earning_lines',
            'payslip_deduction_lines',
            'payslip_custom_deduction_lines',
            'holiday_premium_breakdown',
        ];
        $out = [];
        foreach ($lineKeys as $k) {
            $list = $computedSummary[$k] ?? [];
            if (! is_array($list)) {
                $out[$k] = ['valid' => false, 'type' => gettype($list)];

                continue;
            }
            $phpKeys = array_keys($list);
            $n = count($phpKeys);
            $expected = $n > 0 ? range(0, $n - 1) : [];
            $first = $n > 0 ? reset($list) : null;
            $out[$k] = [
                'valid' => true,
                'count' => $n,
                'php_keys_head' => array_slice($phpKeys, 0, 8),
                'sequential_zero_based' => $phpKeys === $expected,
                'first_row_keys' => is_array($first) ? array_keys($first) : null,
            ];
        }

        return $out;
    }

    /**
     * Structured payload for logs: full payslip input keys + payroll summary table arrays (shape, keys, row preview).
     * {@see PayslipService::generatePayslip} recomputes the snapshot internally; this reflects the computation
     * already done in the finalize transaction right before the call.
     *
     * @param  array<string, mixed>  $payslipInput
     * @param  array<string, mixed>  $computedSummary
     * @return array<string, mixed>
     */
    private function buildPayslipFinalizeSendDiagnostics(array $payslipInput, array $computedSummary): array
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $maxRows = 40;
        $tableKeys = [
            'payslip_earning_lines',
            'daily_computation_earning_lines',
            'payslip_deduction_lines',
            'payslip_custom_deduction_lines',
            'holiday_premium_breakdown',
        ];
        $tables = [];
        foreach ($tableKeys as $key) {
            $list = $computedSummary[$key] ?? [];
            if (! is_array($list)) {
                $tables[$key] = [
                    'valid' => false,
                    'type' => gettype($list),
                ];

                continue;
            }
            $phpKeys = array_keys($list);
            $n = count($phpKeys);
            $expected = $n > 0 ? range(0, $n - 1) : [];
            $slice = array_slice(array_values($list), 0, $maxRows, true);
            $json = json_encode($slice, $flags);
            $tables[$key] = [
                'valid' => true,
                'row_count' => $n,
                'php_keys_head' => array_slice($phpKeys, 0, 12),
                'php_keys_tail' => $n > 12 ? array_slice($phpKeys, -8) : [],
                'sequential_zero_based' => $phpKeys === $expected,
                'array_is_list' => array_is_list($list),
                'rows_preview_json' => is_string($json) ? $json : '',
            ];
        }

        return [
            'payslip_input_keys' => array_keys($payslipInput),
            'payslip_input_full' => $payslipInput,
            'computed_summary_top_level_keys' => array_keys($computedSummary),
            'computed_summary_table_diagnostics' => $this->summarizeComputedSummaryTableArrays($computedSummary),
            'computed_summary_tables' => $tables,
        ];
    }

    /**
     * @param  array<string, mixed>  $payslipInput
     * @param  array<string, mixed>  $computedSummary  Payroll snapshot summary (for diagnostic counts before PDF).
     */
    private function generatePayslipForFinalize(
        User $user,
        array $payslipInput,
        array $computed = [],
        array $computedSummary = [],
        ?array $cyclePreview = null,
        ?\App\Models\PayCycle $cycle = null
    ): Payslip
    {
        $verboseDiagnostics = (bool) env('PAYROLL_FINALIZE_VERBOSE_LOGS', false) || (bool) config('app.debug');
        $baseLogContext = [
            'user_id' => (int) $user->id,
            'payslip_input' => [
                'from_date' => $payslipInput['from_date'] ?? null,
                'to_date' => $payslipInput['to_date'] ?? null,
                'pay_cycle_id' => $payslipInput['pay_cycle_id'] ?? null,
                'payroll_period_id' => $payslipInput['payroll_period_id'] ?? null,
                'reference_date' => $payslipInput['reference_date'] ?? null,
                'is_final_pay' => $payslipInput['is_final_pay'] ?? null,
                'password_protect' => $payslipInput['password_protect'] ?? null,
                'use_company_default' => $payslipInput['use_company_default'] ?? null,
            ],
            'computed_summary_line_counts' => [
                'payslip_earning_lines' => count(is_array($computedSummary['payslip_earning_lines'] ?? null) ? $computedSummary['payslip_earning_lines'] : []),
                'daily_computation_earning_lines' => count(is_array($computedSummary['daily_computation_earning_lines'] ?? null) ? $computedSummary['daily_computation_earning_lines'] : []),
                'payslip_deduction_lines' => count(is_array($computedSummary['payslip_deduction_lines'] ?? null) ? $computedSummary['payslip_deduction_lines'] : []),
                'payslip_custom_deduction_lines' => count(is_array($computedSummary['payslip_custom_deduction_lines'] ?? null) ? $computedSummary['payslip_custom_deduction_lines'] : []),
                'holiday_premium_breakdown' => count(is_array($computedSummary['holiday_premium_breakdown'] ?? null) ? $computedSummary['holiday_premium_breakdown'] : []),
            ],
        ];
        Log::info('Finalize payroll: calling PayslipService::generatePayslip', [
            ...$baseLogContext,
            ...($verboseDiagnostics ? [
                'payslip_finalize_diagnostics' => $this->buildPayslipFinalizeSendDiagnostics($payslipInput, $computedSummary),
                'computed_summary_table_diagnostics' => $this->summarizeComputedSummaryTableArrays($computedSummary),
            ] : []),
        ]);
        try {
            // Finalize should lock payroll quickly. PDF rendering is intentionally deferred to
            // download/send paths because Chromium startup per employee is the slow part.
            if ($computed !== []) {
                return $this->payslipService
                    ->generatePayslipFromComputedPayroll($user, $payslipInput, $computed, $cyclePreview, $cycle, withPdf: false)['payslip'];
            }

            return $this->payslipService->generatePayslip($user, $payslipInput, withPdf: false)['payslip'];
        } catch (Throwable $e) {
            Log::error('Finalize payroll: PayslipService::generatePayslip failed', [
                ...$baseLogContext,
                ...($verboseDiagnostics ? [
                    'payslip_finalize_diagnostics' => $this->buildPayslipFinalizeSendDiagnostics($payslipInput, $computedSummary),
                ] : []),
                'message' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * @param  list<int>  $employeeIds
     */
    private function queryDraftPayslipsForScope(
        array $employeeIds,
        string $fromDate,
        string $toDate,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        ?int $payrollBatchRunId = null,
    ): \Illuminate\Database\Eloquent\Builder {
        $q = Payslip::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('voided_at')
            ->whereDate('pay_period_start', $fromDate)
            ->whereDate('pay_period_end', $toDate)
            ->whereIn('status', $this->draftSnapshotStatuses())
            ->where('period_slot', 0)
            ->whereNotNull('snapshot');
        if ($payrollBatchRunId !== null && $payrollBatchRunId > 0) {
            $q->where(function ($scope) use ($payrollBatchRunId): void {
                $scope->where('payroll_batch_run_id', $payrollBatchRunId)
                    ->orWhereNull('payroll_batch_run_id');
            });
        }
        if ($companyId !== null && $companyId > 0) {
            $q->where('company_id', $companyId);
        }
        if ($branchId !== null && $branchId > 0) {
            $q->where('branch_id', $branchId);
        }
        if ($departmentId !== null && $departmentId > 0) {
            $q->where('department_id', $departmentId);
        }
        if ($singleEmployeeId !== null && $singleEmployeeId > 0) {
            $q->where('user_id', $singleEmployeeId);
        }

        return $q;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $employees
     */
    private function canFinalizeUsingDraftPayslipsOnly(
        \Illuminate\Database\Eloquent\Collection $employees,
        string $fromDate,
        string $toDate,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        ?int $payrollBatchRunId = null,
    ): bool {
        if ($employees->isEmpty()) {
            return false;
        }

        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $expected = count($employeeIds);

        $count = $this->queryDraftPayslipsForScope(
            $employeeIds,
            $fromDate,
            $toDate,
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $payrollBatchRunId
        )->pluck('user_id')
            ->unique()
            ->count();

        return $count === $expected;
    }

    /**
     * Rebuild {@see PayrollComputationService::computeEmployeePayroll}-shaped payload from a draft payslip snapshot
     * so {@see PayrollPersistService::persistComputedPayroll} can run without recomputation.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function computedPayloadFromPayslipSnapshot(Payslip $payslip, array $snapshot): array
    {
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $days = is_array($snapshot['daily_computation_days'] ?? null) ? $snapshot['daily_computation_days'] : [];

        return [
            'user_id' => (int) $payslip->user_id,
            'from_date' => (string) ($snapshot['from_date'] ?? $payslip->pay_period_start?->toDateString() ?? ''),
            'to_date' => (string) ($snapshot['to_date'] ?? $payslip->pay_period_end?->toDateString() ?? ''),
            'daily_rate' => (float) ($snapshot['daily_rate'] ?? $summary['daily_rate'] ?? 0),
            'daily_rate_divisor_days' => (int) ($snapshot['daily_rate_divisor_days'] ?? $summary['daily_rate_divisor_days'] ?? 0),
            'basic_salary_used' => $this->resolveBasicSalaryUsedFromSnapshot($summary, $snapshot),
            'days' => $days,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveBasicSalaryUsedFromSnapshot(array $summary, array $snapshot): float
    {
        $direct = (float) ($snapshot['basic_salary_used'] ?? 0);
        if ($direct > 0) {
            return round($direct, 2);
        }

        $fromComp = (float) data_get($summary, 'compensation_breakdown.basic_salary', 0);
        if ($fromComp > 0) {
            return round($fromComp, 2);
        }

        $fromTotals = (float) data_get($summary, 'compensation_breakdown.totals.basic_salary', 0);
        if ($fromTotals > 0) {
            return round($fromTotals, 2);
        }

        return round((float) ($summary['basic_pay_this_period'] ?? 0), 2);
    }

    /**
     * Queued finalize fast path: reuse draft payslip snapshots + persist payroll periods + bulk lock window.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, User>  $employees
     * @return array<string, mixed>
     */
    private function finalizeBatchUsingDraftPayslipsWithoutRecompute(
        \Illuminate\Database\Eloquent\Collection $employees,
        array $periodInput,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        string $batchKey,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $adminUserId,
        int $existingBatchRunId,
        string $fromDate,
        string $toDate,
    ): array {
        $runGuard = PayrollBatchRun::query()->find($existingBatchRunId);
        if ($runGuard instanceof PayrollBatchRun && (string) $runGuard->status === PayrollBatchRun::STATUS_FINALIZED) {
            $this->logDuplicateFinalizeAttempt($runGuard, 'finalizeBatchUsingDraftPayslipsWithoutRecompute');

            return $this->idempotentFinalizeResult($runGuard);
        }

        $jobStartedAt = microtime(true);
        $timings = [
            'load_employees_ms' => 0.0,
            'load_attendance_ms' => 0.0,
            'load_schedules_ms' => 0.0,
            'load_pay_components_ms' => 0.0,
            'load_deductions_ms' => 0.0,
            'load_overtime_ms' => 0.0,
            'compute_loop_ms' => 0.0,
            'bulk_insert_ms' => 0.0,
            'pdf_generation_ms' => 0.0,
        ];

        $t0 = microtime(true);
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $draftPayslips = $this->queryDraftPayslipsForScope(
            $employeeIds,
            $fromDate,
            $toDate,
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $existingBatchRunId
        )->orderByDesc('id')->get()->unique('user_id')->keyBy(fn (Payslip $p) => (int) $p->user_id);
        $timings['load_employees_ms'] = round((microtime(true) - $t0) * 1000, 2);

        $totals = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0];
        $payslipIds = [];
        $draftMetricsByUser = [];
        $draftDeductionCatalogByUser = [];

        $persistStartedAt = microtime(true);
        try {
            DB::transaction(function () use (
                $employees,
                $draftPayslips,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId,
                $periodStart,
                $periodEnd,
                $adminUserId,
                $existingBatchRunId,
                $employeeIds,
                $batchKey,
                $periodInput,
                $fromDate,
                $toDate,
                &$draftMetricsByUser,
                &$draftDeductionCatalogByUser,
                &$totals,
                &$payslipIds
            ) {
                $lockedRun = PayrollBatchRun::query()->whereKey($existingBatchRunId)->lockForUpdate()->firstOrFail();
                if ((string) $lockedRun->status === PayrollBatchRun::STATUS_FINALIZED) {
                    throw new \RuntimeException('__PAYROLL_BATCH_ALREADY_FINALIZED__');
                }

                $this->payslipService->clearBlockingPayrollPeriodLinksForUsers($employeeIds);
                $this->assertFinalizeModuleGuards($lockedRun);
                $this->payslipService->cleanupStaleBatchModulePayslips($lockedRun);
                $isExecomBatch = $this->normalizePayrollModule((string) ($lockedRun->payroll_module ?? PayrollBatchRun::MODULE_STANDARD)) === PayrollBatchRun::MODULE_EXECOM;

                foreach ($employees as $user) {
                    $payslip = $draftPayslips->get((int) $user->id);
                    if (! $payslip instanceof Payslip) {
                        throw new \RuntimeException('Missing draft payslip for user_id='.(int) $user->id);
                    }

                    if (! $isExecomBatch) {
                        $payslip = $this->payslipService->refreshDraftPayslipFromLiveComputation($payslip, $user);
                    }

                    $snapshotRaw = $payslip->snapshot;
                    $snapshot = is_array($snapshotRaw)
                        ? $snapshotRaw
                        : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);
                    if (! is_array($snapshot)) {
                        $snapshot = [];
                    }
                    $summary = $snapshot['summary'] ?? null;
                    if (! is_array($summary) || $summary === []) {
                        throw new \RuntimeException('Draft payslip snapshot is missing summary data for user_id='.(int) $user->id);
                    }

                    $payslip = $this->assertDraftPayslipLinesArePersisted($payslip);
                    $draftMetrics = $this->payslipService->frozenPayslipLineMetrics($payslip);
                    $snapshot = $this->snapshotArrayFromPayslip($payslip);
                    $draftDeductionCatalogByUser[(int) $user->id] = $this->payslipService->payrollDeductionLineCatalog($snapshot);
                    $draftMetricsByUser[(int) $user->id] = $draftMetrics;
                    $totals['gross'] += $draftMetrics['gross_pay'];
                    $totals['ded'] += $draftMetrics['total_deductions'];
                    $totals['net'] += $draftMetrics['net_pay'];
                    $payslipIds[] = (int) $payslip->id;

                    $this->payslipService->freezePayslipSnapshotForFinalization($payslip);
                }

                $payrollModule = $this->normalizePayrollModule((string) ($lockedRun->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
                $finalizedCount = $this->payslipService->finalizePayslipsByIds(
                    $payslipIds,
                    $adminUserId,
                    (int) $existingBatchRunId
                );
                if ($finalizedCount < count($payslipIds)) {
                    Log::warning('Payroll finalize: not all draft payslip ids were promoted by id', [
                        'payroll_run_id' => (int) $existingBatchRunId,
                        'expected' => count($payslipIds),
                        'updated' => $finalizedCount,
                        'payroll_module' => $payrollModule,
                    ]);
                }

                $this->payslipService->finalizePayrollWindow(
                    $companyId,
                    $periodStart->toDateString(),
                    $periodEnd->toDateString(),
                    null,
                    $employeeIds,
                    $adminUserId,
                    $payrollModule
                );

                $finalizedPayslipsByUser = Payslip::query()
                    ->whereIn('id', $payslipIds)
                    ->get()
                    ->keyBy(fn (Payslip $p) => (int) $p->user_id);
                foreach ($employees as $user) {
                    $finalized = $finalizedPayslipsByUser->get((int) $user->id);
                    if (! $finalized instanceof Payslip) {
                        throw new \RuntimeException('Finalized payslip row missing for user_id='.(int) $user->id);
                    }
                    if ((string) $finalized->status !== Payslip::STATUS_FINALIZED) {
                        $this->payslipService->finalizePayslipsByIds([(int) $finalized->id], $adminUserId, (int) $existingBatchRunId);
                        $finalized = $finalized->fresh();
                    }
                    if (! $finalized instanceof Payslip || (string) $finalized->status !== Payslip::STATUS_FINALIZED) {
                        throw new \RuntimeException('Finalized payslip row missing for user_id='.(int) $user->id);
                    }

                    $draftCatalog = $draftDeductionCatalogByUser[(int) $user->id] ?? [];
                    $finalCatalog = $this->payslipService->payrollDeductionLineCatalog(
                        is_array($finalized->snapshot)
                            ? $finalized->snapshot
                            : (is_string($finalized->snapshot) ? json_decode($finalized->snapshot, true) : [])
                    );
                    $deductionMismatches = $this->payslipService->deductionLineMismatches($draftCatalog, $finalCatalog);
                    if ($deductionMismatches !== []) {
                        foreach ($deductionMismatches as $mismatch) {
                            Log::error('Payroll finalize deduction mismatch', [
                                'payroll_run_id' => (int) $existingBatchRunId,
                                'employee_id' => (int) $user->id,
                                'company_id' => $companyId,
                                'component_code' => $mismatch['component_code'] ?? ($mismatch['line_key'] ?? null),
                                'component_name' => $mismatch['component_name'] ?? null,
                                'schedule' => data_get($mismatch, 'fields.schedule.draft') ?? data_get($mismatch, 'draft.schedule'),
                                'calculation_standard' => data_get($mismatch, 'fields.calculation_standard.draft') ?? data_get($mismatch, 'draft.calculation_standard'),
                                'configured_amount' => data_get($mismatch, 'draft.configured_amount'),
                                'draft_amount' => $mismatch['draft_amount'] ?? data_get($mismatch, 'fields.amount.draft'),
                                'finalized_amount' => $mismatch['finalized_amount'] ?? data_get($mismatch, 'fields.amount.finalized'),
                                'mismatch' => $mismatch,
                                'recompute_attempted' => false,
                            ]);
                        }

                        throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
                    }

                    $this->validateDraftFinalizedFrozenMetrics(
                        (int) $existingBatchRunId,
                        $companyId,
                        (int) $user->id,
                        $draftMetricsByUser[(int) $user->id] ?? [],
                        $this->payslipService->frozenPayslipLineMetrics($finalized),
                        false
                    );
                }

                $payload = [
                    'batch_key' => $batchKey,
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'department_id' => $departmentId,
                    'employee_id' => $singleEmployeeId,
                    'pay_period_start' => $periodStart->toDateString(),
                    'pay_period_end' => $periodEnd->toDateString(),
                    'pay_cycle_id' => $periodInput['pay_cycle_id'] ?? null,
                    'payroll_period_id' => $periodInput['payroll_period_id'] ?? null,
                    'is_final_pay' => (bool) ($periodInput['is_final_pay'] ?? false),
                    'password_protect' => (bool) ($periodInput['password_protect'] ?? false),
                    'reference_date' => isset($periodInput['reference_date'])
                        ? Carbon::parse((string) $periodInput['reference_date'])->toDateString()
                        : null,
                    'status' => PayrollBatchRun::STATUS_FINALIZED,
                    'error_message' => null,
                    'total_gross' => round($totals['gross'], 2),
                    'total_deductions' => round($totals['ded'], 2),
                    'total_net' => round($totals['net'], 2),
                    'employee_count' => $employees->count(),
                    'total_employees' => $employees->count(),
                    'processed_employees' => $employees->count(),
                    'failed_employees' => 0,
                    'completed_at' => now(),
                    'finalized_by_user_id' => $adminUserId,
                    'finalized_at' => now(),
                ];
                PayrollBatchRun::query()->whereKey($existingBatchRunId)->update($this->filterBatchRunPayload($payload));
            });
        } catch (Throwable $e) {
            if ($e->getMessage() === '__PAYROLL_BATCH_ALREADY_FINALIZED__') {
                $run = PayrollBatchRun::query()->findOrFail($existingBatchRunId);

                return $this->idempotentFinalizeResult($run);
            }
            report($e);
            $prev = $e->getPrevious();
            throw new \RuntimeException(
                'Finalize payroll failed: '.$e->getMessage()
                .' at '.$e->getFile().':'.$e->getLine()
                .($prev instanceof Throwable ? ' (caused by: '.$prev->getMessage().' @ '.$prev->getFile().':'.$prev->getLine().')' : ''),
                0,
                $e
            );
        }

        $timings['bulk_insert_ms'] = round((microtime(true) - $persistStartedAt) * 1000, 2);
        $timings['total_ms'] = round((microtime(true) - $jobStartedAt) * 1000, 2);

        $run = PayrollBatchRun::query()->findOrFail($existingBatchRunId);
        $this->payslipService->syncBatchRunTotals($run);
        $run = $run->fresh();
        $this->markOvertimePaidForBatch($run, $employees, $periodStart, $periodEnd, $companyId);

        Log::info('Payroll FINALIZED (draft snapshot fast path)', [
            'batch_run_id' => (int) $run->id,
            'batch_key' => $batchKey,
            'timings_ms' => $timings,
            'payslips_finalized_count' => count($payslipIds),
        ]);

        return [
            'payroll_batch_run_id' => $run->id,
            'payslip_ids' => $payslipIds,
            'totals' => [
                'total_gross' => (float) $run->total_gross,
                'total_deductions' => (float) $run->total_deductions,
                'total_net' => (float) $run->total_net,
                'employee_count' => (int) $run->employee_count,
            ],
            'timings' => $timings,
        ];
    }

    private function canReuseExistingPayslipForFinalize(Payslip $payslip): bool
    {
        // Queued finalization must use the dedicated draft-snapshot path, where
        // validation compares draft lines to finalized lines before commit.
        return false;
    }

    /**
     * Sync draft payroll_lines with the payslip snapshot (may normalize summary lines).
     */
    private function assertDraftPayslipLinesArePersisted(Payslip $payslip): Payslip
    {
        $payslip = $this->payslipService->ensureDraftPayrollLinesSynced($payslip);
        $payslip = $payslip->fresh() ?? $payslip;
        $metrics = $this->payslipService->frozenPayslipLineMetrics($payslip);

        $snapshotLineCount = (int) ($metrics['line_count'] ?? 0);
        $persistedLineCount = $this->payslipService->draftPayrollLineRowCount($payslip);
        if ($snapshotLineCount > 0 || $persistedLineCount > 0) {
            return $payslip;
        }

        $gross = round((float) ($metrics['gross_pay'] ?? 0), 2);
        $deductions = round((float) ($metrics['total_deductions'] ?? 0), 2);
        $net = round((float) ($metrics['net_pay'] ?? 0), 2);
        if (abs($gross) < 0.01 && abs($deductions) < 0.01 && abs($net) < 0.01) {
            Log::info('Payroll finalize allowing zero-total draft payslip with no line rows', [
                'payroll_run_id' => $payslip->payroll_batch_run_id !== null ? (int) $payslip->payroll_batch_run_id : null,
                'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
                'employee_id' => (int) $payslip->user_id,
            ]);

            return $payslip;
        }

        Log::error('Payroll finalize blocked: draft payslip has no persisted line items', [
            'payroll_run_id' => $payslip->payroll_batch_run_id !== null ? (int) $payslip->payroll_batch_run_id : null,
            'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
            'employee_id' => (int) $payslip->user_id,
            'draft_line_ids' => [],
            'draft_line_categories' => [],
            'finalized_line_ids' => [],
            'finalized_line_categories' => [],
            'missing_categories' => [],
            'missing_source_ids' => [],
            'draft_gross' => $gross,
            'finalized_gross' => null,
            'draft_deductions' => $deductions,
            'finalized_deductions' => null,
            'draft_net' => $net,
            'finalized_net' => null,
            'snapshot_line_count' => $snapshotLineCount,
            'persisted_line_count' => $persistedLineCount,
            'cache_cleared' => false,
        ]);

        throw new \RuntimeException('Cannot finalize: draft payslip line items are missing for user_id='.(int) $payslip->user_id.'. Regenerate the payroll draft first.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotArrayFromPayslip(Payslip $payslip): array
    {
        $snapshotRaw = $payslip->snapshot;
        $snapshot = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_string($snapshotRaw) ? json_decode($snapshotRaw, true) : []);

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $finalized
     */
    private function validateDraftFinalizedFrozenMetrics(
        int $payrollRunId,
        ?int $companyId,
        int $employeeId,
        array $draft,
        array $finalized,
        bool $cacheCleared
    ): void {
        $requiredFields = [
            'line_count',
            'gross_pay',
            'total_deductions',
            'net_pay',
            'regular_pay',
            'holiday_pay',
            'overtime_pay',
            'night_differential',
            'paid_leave',
            'allowances',
            'other_deductions',
        ];

        $mismatches = [];
        foreach ($requiredFields as $field) {
            $draftValue = $draft[$field] ?? 0;
            $finalValue = $finalized[$field] ?? 0;
            if ($field === 'line_count') {
                if ((int) $draftValue !== (int) $finalValue) {
                    $mismatches[$field] = ['draft' => (int) $draftValue, 'finalized' => (int) $finalValue];
                }

                continue;
            }

            if (abs(round((float) $draftValue, 2) - round((float) $finalValue, 2)) >= 0.01) {
                $mismatches[$field] = ['draft' => round((float) $draftValue, 2), 'finalized' => round((float) $finalValue, 2)];
            }
        }

        $draftCategories = array_values(array_unique(array_map('strval', (array) ($draft['categories'] ?? []))));
        $finalizedCategories = array_values(array_unique(array_map('strval', (array) ($finalized['categories'] ?? []))));
        $draftLineIds = array_values(array_unique(array_map('strval', (array) ($draft['line_ids'] ?? []))));
        $finalizedLineIds = array_values(array_unique(array_map('strval', (array) ($finalized['line_ids'] ?? []))));
        $missingCategories = array_values(array_diff($draftCategories, $finalizedCategories));
        $missingSourceIds = array_values(array_diff($draftLineIds, $finalizedLineIds));

        $context = [
            'payroll_run_id' => $payrollRunId,
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'draft_line_ids' => $draftLineIds,
            'draft_line_categories' => $draftCategories,
            'finalized_line_ids' => $finalizedLineIds,
            'finalized_line_categories' => $finalizedCategories,
            'missing_categories' => $missingCategories,
            'missing_source_ids' => $missingSourceIds,
            'draft_gross' => (float) ($draft['gross_pay'] ?? 0),
            'finalized_gross' => (float) ($finalized['gross_pay'] ?? 0),
            'draft_deductions' => (float) ($draft['total_deductions'] ?? 0),
            'finalized_deductions' => (float) ($finalized['total_deductions'] ?? 0),
            'draft_net' => (float) ($draft['net_pay'] ?? 0),
            'finalized_net' => (float) ($finalized['net_pay'] ?? 0),
            'cache_cleared' => $cacheCleared,
        ];

        if ($mismatches !== [] || $missingCategories !== [] || $missingSourceIds !== []) {
            Log::error('Payroll finalize validation failed: finalized lines differ from draft snapshot', [
                ...$context,
                'mismatches' => $mismatches,
            ]);

            throw new \RuntimeException('Finalization failed: finalized payroll does not match draft payroll.');
        }

        Log::info('Payroll finalize validation passed: finalized lines match draft snapshot', $context);
    }

    /**
     * Keep async finalize rollout backward-compatible when DB migration is pending.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterBatchRunPayload(array $payload): array
    {
        if (self::$payrollBatchRunColumns === null) {
            self::$payrollBatchRunColumns = Schema::hasTable('payroll_batch_runs')
                ? Schema::getColumnListing('payroll_batch_runs')
                : [];
        }
        if (self::$payrollBatchRunColumns === []) {
            return $payload;
        }

        $allowed = array_flip(self::$payrollBatchRunColumns);

        return array_intersect_key($payload, $allowed);
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
     * @return array<string, mixed>
     */
    private function idempotentFinalizeResult(PayrollBatchRun $run): array
    {
        $this->payslipService->syncBatchRunTotals($run);
        $run = $run->fresh();
        if (! $run instanceof PayrollBatchRun) {
            throw new \RuntimeException('Payroll batch run not found.');
        }
        $agg = $this->payslipService->aggregateForBatchRun($run);

        return [
            'payroll_batch_run_id' => (int) $run->id,
            'payslip_ids' => $agg['payslip_ids'],
            'totals' => [
                'total_gross' => (float) ($run->total_gross ?? $agg['total_gross_pay']),
                'total_deductions' => (float) ($run->total_deductions ?? $agg['total_deductions']),
                'total_net' => (float) ($run->total_net ?? $agg['total_net_pay']),
                'employee_count' => max((int) ($run->employee_count ?? 0), (int) $agg['payslip_count']),
            ],
            'skipped' => true,
            'already_finalized' => true,
        ];
    }

    private function logDuplicateFinalizeAttempt(PayrollBatchRun $run, string $source): void
    {
        $scopedQuery = $this->payslipQueryForBatchRun($run);
        $allCount = (clone $scopedQuery)->count();
        $uniqueCount = count($this->payslipService->latestUniquePayslipIdsForQuery($scopedQuery));

        Log::notice('Payroll finalize ignored: duplicate finalize attempt on finalized batch', [
            'batch_run_id' => (int) $run->id,
            'batch_key' => $run->batch_key,
            'source' => $source,
            'status' => (string) $run->status,
            'total_net_pay' => (float) ($run->total_net ?? 0),
            'payslip_rows_count' => $allCount,
            'unique_payslip_rows_count' => $uniqueCount,
            'duplicate_payslip_rows_detected' => max(0, $allCount - $uniqueCount),
        ]);
    }

    private function payslipQueryForBatchRun(PayrollBatchRun $run): \Illuminate\Database\Eloquent\Builder
    {
        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));

        $query = Payslip::query()
            ->where('payroll_batch_run_id', (int) $run->id)
            ->where('payroll_module', $expectedModule)
            ->whereNull('voided_at')
            ->where('period_slot', 0)
            ->when(
                $run->pay_period_start !== null,
                fn ($q) => $q->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            )
            ->when(
                $run->pay_period_end !== null,
                fn ($q) => $q->whereDate('pay_period_end', $run->pay_period_end->toDateString())
            );

        if ($run->company_id !== null) {
            $query->where('company_id', (int) $run->company_id);
        }
        if ($run->branch_id !== null) {
            $query->where('branch_id', (int) $run->branch_id);
        }
        if ($run->department_id !== null) {
            $query->where('department_id', (int) $run->department_id);
        }
        if ($run->employee_id !== null) {
            $query->where('user_id', (int) $run->employee_id);
        }
        if ((string) $run->status === PayrollBatchRun::STATUS_FINALIZED) {
            $query->whereIn('status', Payslip::lockingStatuses());
        } else {
            $query->whereIn('status', $this->draftSnapshotStatuses());
        }

        return $query;
    }

    private function orderPayslipQueryByEmployeeName(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->orderBy(
                User::query()
                    ->select('last_name')
                    ->whereColumn('users.id', 'payslips.user_id')
                    ->limit(1)
            )
            ->orderBy(
                User::query()
                    ->select('first_name')
                    ->whereColumn('users.id', 'payslips.user_id')
                    ->limit(1)
            )
            ->orderBy(
                User::query()
                    ->select('middle_name')
                    ->whereColumn('users.id', 'payslips.user_id')
                    ->limit(1)
            )
            ->orderBy('user_id')
            ->orderBy('id');
    }

    private function attachMatchingPayslipsToBatchRun(PayrollBatchRun $run): int
    {
        if ($run->pay_period_start === null || $run->pay_period_end === null || $run->company_id === null) {
            return 0;
        }

        $expectedStatuses = (string) $run->status === PayrollBatchRun::STATUS_FINALIZED
            ? Payslip::lockingStatuses()
            : $this->draftSnapshotStatuses();

        $query = Payslip::query()
            ->whereNull('payroll_batch_run_id')
            ->whereNull('voided_at')
            ->whereIn('status', $expectedStatuses)
            ->where('period_slot', 0)
            ->where('company_id', (int) $run->company_id)
            ->whereDate('pay_period_start', $run->pay_period_start->toDateString())
            ->whereDate('pay_period_end', $run->pay_period_end->toDateString());

        if ($run->branch_id !== null) {
            $query->where('branch_id', (int) $run->branch_id);
        }
        if ($run->department_id !== null) {
            $query->where('department_id', (int) $run->department_id);
        }
        if ($run->employee_id !== null) {
            $query->where('user_id', (int) $run->employee_id);
        }

        $updated = (int) $query->update(['payroll_batch_run_id' => (int) $run->id]);
        if ($updated > 0) {
            Log::info('Payroll table attached matching saved payslips to batch run', [
                'payroll_run_id' => (int) $run->id,
                'selected_company_id' => (int) $run->company_id,
                'statuses' => $expectedStatuses,
                'attached_count' => $updated,
            ]);
        }

        return $updated;
    }

    /**
     * Draft payroll snapshots have historically used both statuses before finalization.
     *
     * @return list<string>
     */
    private function draftSnapshotStatuses(): array
    {
        return [Payslip::STATUS_DRAFT, Payslip::STATUS_GENERATED];
    }

    /**
     * Include employees represented by finalized/draft payslip rows for the selected window.
     * Finalized payroll search must not depend only on the current active roster query because
     * users can be deactivated, moved, or filtered differently after the payroll was locked.
     *
     * @param  array<string, mixed>  $periodInput
     * @return list<int>
     */
    private function previewStoredPayslipEmployeeIds(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor = null,
        ?string $search = null
    ): array {
        $query = Payslip::query()
            ->whereNotNull('user_id')
            ->whereNull('voided_at')
            ->where('status', '!=', Payslip::STATUS_VOIDED)
            ->select('user_id');

        $this->applyPreviewPeriodFiltersEloquent($query, $periodInput);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }
        if ($singleEmployeeId) {
            $query->where('user_id', $singleEmployeeId);
        }
        if ($actor !== null) {
            $query->whereHas('employee', function ($employeeQuery) use ($actor) {
                $this->dataScopeService->restrictEmployeeQuery($actor, $employeeQuery);
            });
        }
        if (is_string($search) && trim($search) !== '') {
            $needle = trim($search);
            $like = '%'.$needle.'%';
            $query->whereHas('employee', function ($employeeQuery) use ($like) {
                $employeeQuery->where(function ($sub) use ($like) {
                    $this->applyEmployeeNameSearch($sub, $like);
                    $sub->orWhere('employee_code', 'like', $like)
                        ->orWhere('department', 'like', $like);
                });
            });
        }

        return $query
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    private function scopedEmployees(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        ?User $actor = null,
        ?string $search = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
    ): \Illuminate\Database\Eloquent\Builder {
        $q = $this->payrollEligibility->query(
            $companyId,
            $branchId,
            $departmentId,
            $periodStart,
            $periodEnd,
            $actor,
            $this->dataScopeService,
            $payrollModule
        );

        if ($singleEmployeeId) {
            $q->where('id', $singleEmployeeId);

            return $q;
        }
        if (is_string($search) && trim($search) !== '') {
            $needle = trim($search);
            $like = '%'.$needle.'%';
            $q->where(function ($sub) use ($like) {
                $this->applyEmployeeNameSearch($sub, $like);
                $sub->orWhere('employee_code', 'like', $like)
                    ->orWhere('department', 'like', $like);
            });
        }

        return $q;
    }

    /**
     * @return array{name: string, formatted_name: string, first_name: ?string, middle_name: ?string, last_name: ?string, suffix: ?string, employee_sort_key: string}
     */
    private function employeePreviewIdentityFields(User $employee): array
    {
        return [
            'name' => (string) $employee->display_name,
            'formatted_name' => (string) $employee->formatted_name,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'suffix' => $employee->suffix,
            'employee_sort_key' => $employee->employeeListingSortKey(),
        ];
    }

    private function employeeDepartmentName(User $employee): ?string
    {
        $departmentName = $employee->departmentRelation?->name;
        if (is_string($departmentName) && trim($departmentName) !== '') {
            return $departmentName;
        }

        $legacyDepartment = $employee->department;

        return is_string($legacyDepartment) && trim($legacyDepartment) !== ''
            ? $legacyDepartment
            : null;
    }

    private function normalizePayrollModule(string $module): string
    {
        return strtolower(trim($module)) === PayrollBatchRun::MODULE_EXECOM
            ? PayrollBatchRun::MODULE_EXECOM
            : PayrollBatchRun::MODULE_STANDARD;
    }

    private function assertFinalizeModuleGuards(PayrollBatchRun $run): void
    {
        $expectedModule = $this->normalizePayrollModule((string) ($run->payroll_module ?? PayrollBatchRun::MODULE_STANDARD));
        $draftUserIds = $this->payslipQueryForBatchRun($run)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($expectedModule === PayrollBatchRun::MODULE_EXECOM) {
            if ($draftUserIds === []) {
                throw new \RuntimeException('Cannot finalize: no execom draft payslips are linked to this batch.');
            }

            return;
        }

        if ($draftUserIds === []) {
            return;
        }

        $execomEligibleIds = $this->payrollEligibility->getExecomPayrollEligibleEmployeeIds(
            $run->company_id ? (int) $run->company_id : null,
            $run->branch_id ? (int) $run->branch_id : null,
            $run->department_id ? (int) $run->department_id : null,
            $run->pay_period_start,
            $run->pay_period_end
        );
        $execomInDraft = array_values(array_intersect($draftUserIds, $execomEligibleIds));
        if ($execomInDraft !== []) {
            throw new \RuntimeException('Regular payroll contains EXECOM employees. Please regenerate Regular Payroll.');
        }
    }

    private function applyEmployeeNameSearch(\Illuminate\Database\Eloquent\Builder $query, string $like): void
    {
        $query->where('name', 'like', $like)
            ->orWhere('first_name', 'like', $like)
            ->orWhere('middle_name', 'like', $like)
            ->orWhere('last_name', 'like', $like)
            ->orWhere('suffix', 'like', $like);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>|\Illuminate\Database\Eloquent\Collection<int, User>  $employees
     */
    private function markOvertimePaidForBatch(
        PayrollBatchRun $run,
        $employees,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $companyId
    ): void {
        if ($employees->isEmpty()) {
            return;
        }

        $snapshotIds = $this->includedOvertimeIdsFromFinalizedPayslips($run);
        if ($snapshotIds !== []) {
            $this->overtimePayroll->markIncludedIdsAsPaid((int) $run->id, $snapshotIds);

            return;
        }

        $this->overtimePayroll->markIncludedAsPaid(
            (int) $run->id,
            $employees->pluck('id')->map(static fn ($id) => (int) $id)->all(),
            $periodStart,
            $periodEnd,
            $companyId
        );
    }

    /**
     * @return list<int>
     */
    private function includedOvertimeIdsFromFinalizedPayslips(PayrollBatchRun $run): array
    {
        $ids = [];
        $payslips = $this->payslipQueryForBatchRun($run)->get(['id', 'snapshot']);
        foreach ($payslips as $payslip) {
            $snapshot = $payslip->snapshot;
            if (is_string($snapshot)) {
                $snapshot = json_decode($snapshot, true);
            }
            if (! is_array($snapshot)) {
                continue;
            }
            foreach ((array) data_get($snapshot, 'summary.overtime_breakdown', []) as $item) {
                $id = (int) ($item['source_id'] ?? $item['overtime_id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
