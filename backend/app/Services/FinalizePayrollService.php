<?php

namespace App\Services;

use App\Models\PayCycle;
use App\Models\PayrollBatchRun;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\UserAdminActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
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
                ->whereIn('id', $scopedEmployeeIds);
            $employees = $employeesQuery
                // IMPORTANT: do not under-select here. Payroll computation and deduction schedules rely on
                // core employee columns (company_id, pay_cycle_id, etc). Missing columns can silently zero out
                // statutory deductions and break pay-date resolution.
                ->select([
                    'id',
                    'name',
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
                ->with('payCycle:id,name')
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
                    $gross = round((float) ($stored->gross_pay ?? 0), 2);
                    $ded = round((float) ($stored->total_deductions ?? 0), 2);
                    $net = round((float) ($stored->net_pay ?? 0), 2);
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
                    $gross = round(
                        (float) ($summary['gross_pay_this_period']
                            ?? ((float) ($summary['total_pay'] ?? 0) + (float) ($summary['non_basic_earnings_this_period'] ?? 0))),
                        2
                    );
                    $ded = round(
                        (float) ($summary['employee_statutory_this_period'] ?? 0)
                        + (float) ($summary['custom_deductions_this_period'] ?? 0)
                        + (float) ($summary['withholding_tax_this_period_estimate'] ?? 0),
                        2
                    );
                    $net = round((float) ($summary['net_pay_after_withholding_estimate'] ?? 0), 2);
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
                        'name' => (string) $employee->name,
                        'employee_code' => $employee->employee_code,
                        'department' => $employee->department,
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
                        'name' => (string) $employee->name,
                        'employee_code' => $employee->employee_code,
                        'department' => $employee->department,
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

            // Use SQL aggregates on stored payslips for totals instead of recomputing
            // payroll for every employee in scope (the main bottleneck for 100+ employees).
            $totalsQuery = Payslip::query()
                ->whereIn('user_id', $scopedEmployeeIds);
            $this->applyPreviewPeriodFiltersEloquent($totalsQuery, $periodInput);
            $agg = $totalsQuery->selectRaw('COALESCE(SUM(gross_pay), 0) as total_gross, COALESCE(SUM(total_deductions), 0) as total_ded, COALESCE(SUM(net_pay), 0) as total_net, COUNT(*) as cnt')
                ->first();

            if ($agg && (int) $agg->cnt > 0) {
                $resolvedTotals = [
                    'gross' => round((float) $agg->total_gross, 2),
                    'ded' => round((float) $agg->total_ded, 2),
                    'net' => round((float) $agg->total_net, 2),
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

        return [
            'totals' => $cached['totals'] ?? [
                'total_gross' => 0.0,
                'total_deductions' => 0.0,
                'total_net' => 0.0,
                'employee_count' => 0,
            ],
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

        $query = $this->payslipQueryForBatchRun($run)
            ->with(['employee:id,name,email,employee_code,department,position,profile_image,role,company_id,branch_id,department_id']);

        if ($search !== null && trim($search) !== '') {
            $like = '%'.trim($search).'%';
            $query->whereHas('employee', function ($employeeQuery) use ($like) {
                $employeeQuery->where('name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $storedCount = (clone $query)->count();
        $payslips = $query
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($payslips as $stored) {
            $employee = $stored->employee;
            if (! $employee instanceof User) {
                continue;
            }
            $summary = is_array($stored->snapshot['summary'] ?? null)
                ? (array) $stored->snapshot['summary']
                : [];
            $resolvedHrRole = $this->hrRoleResolver->resolveForApprovalSubject($employee);
            $rows[] = [
                'payslip_id' => (int) $stored->id,
                'user_id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department,
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
                'daily_computation_earning_lines' => is_array($summary['daily_computation_earning_lines'] ?? null)
                    ? array_values($summary['daily_computation_earning_lines'])
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
                'gross_pay' => round((float) ($stored->gross_pay ?? 0), 2),
                'total_deductions' => round((float) ($stored->total_deductions ?? 0), 2),
                'net_pay' => round((float) ($stored->net_pay ?? 0), 2),
                'status' => (string) ($stored->status ?? Payslip::STATUS_DRAFT),
                'delivered_at' => $stored->delivered_at !== null ? $stored->delivered_at->toIso8601String() : null,
                'is_sent' => (bool) ($stored->is_sent ?? false),
                'sent_at' => isset($stored->sent_at) && $stored->sent_at !== null ? $stored->sent_at->toIso8601String() : null,
                'has_stored_payslip' => true,
            ];
        }

        $agg = $this->payslipQueryForBatchRun($run)
            ->selectRaw('COALESCE(SUM(gross_pay), 0) as total_gross, COALESCE(SUM(total_deductions), 0) as total_ded, COALESCE(SUM(net_pay), 0) as total_net, COUNT(*) as cnt')
            ->first();
        $totalEmployees = max((int) ($batchRun['total_employees'] ?? 0), (int) ($run->total_employees ?? 0), (int) ($run->employee_count ?? 0), $storedCount);

        return [
            'totals' => [
                'total_gross' => round((float) ($agg->total_gross ?? $run->total_gross ?? 0), 2),
                'total_deductions' => round((float) ($agg->total_ded ?? $run->total_deductions ?? 0), 2),
                'total_net' => round((float) ($agg->total_net ?? $run->total_net ?? 0), 2),
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
        $probe = (clone $scopedEmployeesQuery)->orderBy('id')->first();
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
            ->orderBy('id')
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
        $employees = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor)
            ->orderBy('id')
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

        if ($existingBatchRunId !== null
            && $this->canFinalizeUsingDraftPayslipsOnly(
                $employees,
                $fromDate,
                $toDate,
                $companyId,
                $branchId,
                $departmentId,
                $singleEmployeeId
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

        $this->payrollComputation->flushRuntimeCaches();

        if (! $skipExistingBatchKeyCheck) {
            $existingKeyRun = PayrollBatchRun::query()->where('batch_key', $batchKey)->first();
            if ($existingKeyRun !== null) {
                $st = (string) $existingKeyRun->status;
                if ($st === PayrollBatchRun::STATUS_FINALIZED) {
                    throw new \RuntimeException('This payroll period has already been finalized for the selected scope.');
                }
                if ($st === PayrollBatchRun::STATUS_QUEUED || $st === PayrollBatchRun::STATUS_PROCESSING) {
                    throw new \RuntimeException('A payroll finalize job is already queued or running for this scope.');
                }
                // failed / draft / other: allow retry (queued finalize uses skipExistingBatchKeyCheck=true)
            }
        }

        $totals = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0];
        $payslipIds = [];
        $fromDate = $periodStart->toDateString();
        $toDate = $periodEnd->toDateString();

        try {
            DB::transaction(function () use (
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
                &$payslipIds,
                &$totals,
                $fromDate,
                $toDate
            ) {
                $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
                $periodIds = [];
                $existingPayslipsByUser = Payslip::query()
                    ->whereIn('user_id', $employeeIds)
                    ->whereDate('pay_period_start', $fromDate)
                    ->whereDate('pay_period_end', $toDate)
                    ->orderByDesc('id')
                    ->get()
                    ->unique('user_id')
                    ->keyBy('user_id');

                foreach ($employees as $user) {
                    $existingPayslip = $existingPayslipsByUser->get((int) $user->id);
                    if ($existingPayslip instanceof Payslip && $this->canReuseExistingPayslipForFinalize($existingPayslip)) {
                        $totals['gross'] += round((float) ($existingPayslip->gross_pay ?? 0), 2);
                        $totals['ded'] += round((float) ($existingPayslip->total_deductions ?? 0), 2);
                        $totals['net'] += round((float) ($existingPayslip->net_pay ?? 0), 2);

                        $existingPayslip->update([
                            'status' => Payslip::STATUS_FINALIZED,
                            'finalized_at' => now(),
                            'finalized_by_user_id' => $adminUserId,
                        ]);
                        $payslipIds[] = (int) $existingPayslip->id;

                        continue;
                    }

                    [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($user, $periodInput);
                    $computed = $this->payrollComputation->computeEmployeePayroll(
                        $user,
                        $from,
                        $to,
                        null,
                        [
                            'pay_period_start' => $from->toDateString(),
                            'pay_period_end' => $to->toDateString(),
                            'selected_pay_date' => is_array($cyclePreview) ? ($cyclePreview['pay_date'] ?? null) : null,
                        ]
                    );
                    $summary = $computed['summary'] ?? [];

                    $gross = round(
                        (float) ($summary['gross_pay_this_period']
                            ?? ((float) ($summary['total_pay'] ?? 0) + (float) ($summary['non_basic_earnings_this_period'] ?? 0))),
                        2
                    );
                    $empStat = (float) ($summary['employee_statutory_this_period'] ?? 0);
                    $custDed = (float) ($summary['custom_deductions_this_period'] ?? 0);
                    $wh = (float) ($summary['withholding_tax_this_period_estimate'] ?? 0);
                    $ded = round($empStat + $custDed + $wh, 2);
                    $net = round((float) ($summary['net_pay_after_withholding_estimate'] ?? 0), 2);

                    $totals['gross'] += $gross;
                    $totals['ded'] += $ded;
                    $totals['net'] += $net;

                    $period = $this->payrollPersistService->persistComputedPayroll(
                        $user,
                        $from,
                        $to,
                        $computed,
                        $cyclePreview,
                        $cycle
                    );

                    $payslipInput = $periodInput;
                    if ($period) {
                        $payslipInput['payroll_period_id'] = $period->id;
                        $periodIds[] = (int) $period->id;
                    }

                    try {
                        $generated = $this->generatePayslipForFinalize($user, $payslipInput, $computed, is_array($summary) ? $summary : [], $cyclePreview, $cycle);
                    } catch (Throwable $e) {
                        Log::error('Finalize payroll: payslip generation failed for employee', [
                            'user_id' => (int) $user->id,
                            'employee_code' => $user->employee_code,
                            'batch_key' => $batchKey,
                            'payslip_input' => $payslipInput,
                            'payslip_finalize_diagnostics' => $this->buildPayslipFinalizeSendDiagnostics(
                                $payslipInput,
                                is_array($summary) ? $summary : []
                            ),
                            'underlying_file' => $e->getFile(),
                            'underlying_line' => $e->getLine(),
                            'message' => $e->getMessage(),
                            'exception_class' => $e::class,
                        ]);
                        throw new \RuntimeException(
                            'Payslip PDF failed for user_id='.(int) $user->id.': '.$e->getMessage(),
                            0,
                            $e
                        );
                    }
                    $generated->update([
                        'status' => Payslip::STATUS_FINALIZED,
                        'finalized_at' => now(),
                        'finalized_by_user_id' => $adminUserId,
                    ]);
                    $payslipIds[] = $generated->id;

                    if ($period) {
                        $period->refresh()->update(['status' => PayrollPeriod::STATUS_LOCKED]);
                    }
                }

                // Ensure previously generated draft payslips for this exact scope + period
                // are promoted to finalized and related payroll periods are locked.
                if ($companyId !== null) {
                    $uniquePeriodIds = array_values(array_unique(array_filter($periodIds, fn ($id) => $id > 0)));
                    foreach ($uniquePeriodIds as $pid) {
                        $this->payslipService->finalizePayrollPeriod(
                            $companyId,
                            (int) $pid,
                            $employeeIds,
                            $adminUserId
                        );
                    }
                    $this->payslipService->finalizePayrollWindow(
                        $companyId,
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                        null,
                        $employeeIds,
                        $adminUserId
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
                $payload = $this->filterBatchRunPayload($payload);
                if ($existingBatchRunId !== null) {
                    PayrollBatchRun::query()->whereKey($existingBatchRunId)->update($payload);
                } else {
                    PayrollBatchRun::create($payload);
                }
            });
        } catch (Throwable $e) {
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

        $run = $existingBatchRunId !== null
            ? PayrollBatchRun::query()->findOrFail($existingBatchRunId)
            : PayrollBatchRun::query()->where('batch_key', $batchKey)->firstOrFail();

        Log::info('Payroll FINALIZED', [
            'batch_run_id' => (int) $run->id,
            'batch_key' => $batchKey,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $singleEmployeeId,
            'pay_period_start' => $periodStart->toDateString(),
            'pay_period_end' => $periodEnd->toDateString(),
            'payslips_created_count' => count($payslipIds),
            'employee_count' => (int) $run->employee_count,
            'status' => (string) $run->status,
        ]);
        Log::info('Payslips created count', [
            'batch_run_id' => (int) $run->id,
            'count' => count($payslipIds),
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
        ];
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
            ->orderBy('id')
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
        if ($existing) {
            $status = (string) $existing->status;
            if ($status === PayrollBatchRun::STATUS_FINALIZED) {
                $scopedUserIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
                $hasFinalizedPayslips = Payslip::query()
                    ->where('company_id', $companyId)
                    ->whereDate('pay_period_start', $periodStart->toDateString())
                    ->whereDate('pay_period_end', $periodEnd->toDateString())
                    ->when(count($scopedUserIds) > 0, fn ($q) => $q->whereIn('user_id', $scopedUserIds))
                    ->exists();
                if ($hasFinalizedPayslips) {
                    throw new \RuntimeException('This payroll period has already been finalized for the selected scope.');
                }

                Log::warning('Payroll finalize re-queue: stale finalized batch without payslips', [
                    'batch_run_id' => $existing->id,
                    'batch_key' => $batchKey,
                    'company_id' => $companyId,
                    'pay_period_start' => $periodStart->toDateString(),
                    'pay_period_end' => $periodEnd->toDateString(),
                    'scoped_employee_count' => count($scopedUserIds),
                ]);
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
        $periodInput = [
            'from_date' => $run->pay_period_start?->toDateString(),
            'to_date' => $run->pay_period_end?->toDateString(),
            'pay_cycle_id' => $run->pay_cycle_id,
            'reference_date' => $run->reference_date?->toDateString(),
            'payroll_period_id' => $run->payroll_period_id,
            'is_final_pay' => (bool) $run->is_final_pay,
            'password_protect' => (bool) $run->password_protect,
        ];

        $adminUserId = (int) ($run->finalized_by_user_id ?? 0);
        if ($adminUserId <= 0 && $actor !== null) {
            $adminUserId = (int) $actor->id;
        }

        return $this->finalizeBatch(
            $run->company_id ? (int) $run->company_id : null,
            $run->branch_id ? (int) $run->branch_id : null,
            $run->department_id ? (int) $run->department_id : null,
            $run->employee_id ? (int) $run->employee_id : null,
            $periodInput,
            $adminUserId,
            $actor,
            (int) $run->id,
            true
        );
    }

    /**
     * Delete a finalized payroll batch and all generated artifacts so the same period can be regenerated.
     *
     * @return array{deleted_payslips: int, deleted_pdfs: int, deleted_payroll_periods: int}
     */
    public function deleteFinalizedPayrollBatch(int $batchId, User $actor): array
    {
        $run = PayrollBatchRun::query()->findOrFail($batchId);
        $status = strtolower(trim((string) $run->status));
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

        $deletedPayslips = 0;
        $deletedPdfs = 0;
        $deletedPayrollPeriods = 0;

        DB::transaction(function () use (
            $run,
            $targetUserIds,
            &$deletedPayslips,
            &$deletedPdfs,
            &$deletedPayrollPeriods
        ) {
            $rows = $this->payslipQueryForBatchRun($run)
                ->lockForUpdate()
                ->get(['id', 'pdf_path', 'payroll_period_id']);
            $payslipIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $pdfPaths = $rows->pluck('pdf_path')
                ->filter(fn ($p) => is_string($p) && trim($p) !== '')
                ->map(fn ($p) => trim((string) $p))
                ->unique()
                ->values()
                ->all();
            $payrollPeriodIds = $rows->pluck('payroll_period_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($payslipIds !== []) {
                $deletedPayslips = Payslip::query()->whereIn('id', $payslipIds)->delete();
            }

            if ($payrollPeriodIds !== []) {
                $deletedPayrollPeriods = PayrollPeriod::query()
                    ->whereIn('id', $payrollPeriodIds)
                    ->delete();
            } elseif ($targetUserIds !== [] && $run->pay_period_start !== null && $run->pay_period_end !== null) {
                $deletedPayrollPeriods = PayrollPeriod::query()
                    ->whereIn('user_id', $targetUserIds)
                    ->whereDate('from_date', $run->pay_period_start->toDateString())
                    ->whereDate('to_date', $run->pay_period_end->toDateString())
                    ->delete();
            }

            $run->delete();

            foreach ($pdfPaths as $relativePath) {
                // Payslip pdf_path is stored relative to storage/app/private.
                if (Storage::disk('local')->exists('private/'.$relativePath)) {
                    if (Storage::disk('local')->delete('private/'.$relativePath)) {
                        $deletedPdfs++;
                    }
                }
            }
        });

        UserAdminActivityLog::query()->create([
            'subject_user_id' => (int) $actor->id,
            'actor_user_id' => (int) $actor->id,
            'action' => 'finalized_payroll_batch_deleted',
            'meta' => [
                'payroll_batch_run_id' => $batchId,
                'batch_key' => $run->batch_key,
                'company_id' => $run->company_id,
                'branch_id' => $run->branch_id,
                'department_id' => $run->department_id,
                'employee_id' => $run->employee_id,
                'pay_period_start' => $run->pay_period_start?->toDateString(),
                'pay_period_end' => $run->pay_period_end?->toDateString(),
                'deleted_payslips' => $deletedPayslips,
                'deleted_pdfs' => $deletedPdfs,
                'deleted_payroll_periods' => $deletedPayrollPeriods,
            ],
            'ip_address' => request()->ip(),
        ]);

        Log::warning('Finalized payroll batch deleted', [
            'actor_user_id' => (int) $actor->id,
            'payroll_batch_run_id' => $batchId,
            'batch_key' => $run->batch_key,
            'deleted_payslips' => $deletedPayslips,
            'deleted_pdfs' => $deletedPdfs,
            'deleted_payroll_periods' => $deletedPayrollPeriods,
        ]);

        return [
            'deleted_payslips' => (int) $deletedPayslips,
            'deleted_pdfs' => (int) $deletedPdfs,
            'deleted_payroll_periods' => (int) $deletedPayrollPeriods,
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
        $employee = User::query()
            ->activeRoster()
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
            $periodInput,
            $from,
            $to,
            $cyclePreview,
            $cycle,
            $effectiveCompanyId,
            $employeeUserId,
            $adminUserId,
            &$payslipId
        ) {
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

            $period = $this->payrollPersistService->persistComputedPayroll(
                $employee,
                $from,
                $to,
                $computed,
                $cyclePreview,
                $cycle
            );

            $payslipInput = $periodInput;
            if ($period) {
                $payslipInput['payroll_period_id'] = $period->id;
            }

            try {
                $generated = $this->generatePayslipForFinalize(
                    $employee,
                    $payslipInput,
                    $computed,
                    is_array($computed['summary'] ?? null) ? $computed['summary'] : [],
                    $cyclePreview,
                    $cycle
                );
            } catch (Throwable $e) {
                Log::error('Finalize payroll: payslip generation failed for employee', [
                    'user_id' => (int) $employee->id,
                    'employee_code' => $employee->employee_code,
                    'pay_period' => $from->toDateString().'..'.$to->toDateString(),
                    'payslip_input' => $payslipInput,
                    'payslip_finalize_diagnostics' => $this->buildPayslipFinalizeSendDiagnostics(
                        $payslipInput,
                        is_array($computed['summary'] ?? null) ? $computed['summary'] : []
                    ),
                    'underlying_file' => $e->getFile(),
                    'underlying_line' => $e->getLine(),
                    'message' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
                throw new \RuntimeException(
                    'Payslip PDF failed for user_id='.(int) $employee->id.': '.$e->getMessage(),
                    0,
                    $e
                );
            }
            $payslipId = (int) $generated->id;

            $generated->update([
                'status' => Payslip::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by_user_id' => $adminUserId,
            ]);

            if ($period) {
                $this->payslipService->finalizePayrollPeriod(
                    $effectiveCompanyId,
                    (int) $period->id,
                    [$employeeUserId],
                    $adminUserId
                );
            }

            $this->payslipService->finalizePayrollWindow(
                $effectiveCompanyId,
                $from->toDateString(),
                $to->toDateString(),
                null,
                [$employeeUserId],
                $adminUserId
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
    ): \Illuminate\Database\Eloquent\Builder {
        $q = Payslip::query()
            ->whereIn('user_id', $employeeIds)
            ->whereDate('pay_period_start', $fromDate)
            ->whereDate('pay_period_end', $toDate)
            ->where('status', Payslip::STATUS_DRAFT)
            ->whereNotNull('snapshot');
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
            $singleEmployeeId
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
            $singleEmployeeId
        )->get()->keyBy(fn (Payslip $p) => (int) $p->user_id);
        $timings['load_employees_ms'] = round((microtime(true) - $t0) * 1000, 2);

        $totals = ['gross' => 0.0, 'ded' => 0.0, 'net' => 0.0];
        $payslipIds = [];

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
                &$totals,
                &$payslipIds
            ) {
                foreach ($employees as $user) {
                    $payslip = $draftPayslips->get((int) $user->id);
                    if (! $payslip instanceof Payslip) {
                        throw new \RuntimeException('Missing draft payslip for user_id='.(int) $user->id);
                    }

                    $totals['gross'] += round((float) ($payslip->gross_pay ?? 0), 2);
                    $totals['ded'] += round((float) ($payslip->total_deductions ?? 0), 2);
                    $totals['net'] += round((float) ($payslip->net_pay ?? 0), 2);
                    $payslipIds[] = (int) $payslip->id;

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

                    $computed = $this->computedPayloadFromPayslipSnapshot($payslip, $snapshot);
                    $preview = is_array($snapshot['pay_cycle_preview'] ?? null) ? $snapshot['pay_cycle_preview'] : [];
                    $cycle = $payslip->pay_cycle_id ? PayCycle::query()->find((int) $payslip->pay_cycle_id) : null;

                    $from = Carbon::parse((string) ($computed['from_date'] !== '' ? $computed['from_date'] : $fromDate))->startOfDay();
                    $to = Carbon::parse((string) ($computed['to_date'] !== '' ? $computed['to_date'] : $toDate))->startOfDay();

                    $existingPeriodId = (int) ($payslip->payroll_period_id ?? 0);
                    $totalPay = (float) ($computed['summary']['total_pay'] ?? 0);

                    if ($existingPeriodId <= 0 && $totalPay > 0) {
                        $period = $this->payrollPersistService->persistComputedPayroll(
                            $user,
                            $from,
                            $to,
                            $computed,
                            $preview,
                            $cycle
                        );
                        if ($period instanceof PayrollPeriod) {
                            Payslip::query()->whereKey($payslip->id)->update(['payroll_period_id' => $period->id]);
                        }
                    }
                }

                if ($companyId !== null) {
                    $this->payslipService->finalizePayrollWindow(
                        $companyId,
                        $periodStart->toDateString(),
                        $periodEnd->toDateString(),
                        null,
                        $employeeIds,
                        $adminUserId
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
        // Finalize is the lock point, so it must recompute from current salary,
        // deductions, attendance, and tax settings instead of reusing an older
        // draft/generated snapshot.
        return false;
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

    private function payslipQueryForBatchRun(PayrollBatchRun $run): \Illuminate\Database\Eloquent\Builder
    {
        $query = Payslip::query()
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

        return $query;
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
            $query->whereHas('employee', function ($employeeQuery) use ($needle) {
                $employeeQuery->where(function ($sub) use ($needle) {
                    $sub->where('name', 'like', '%'.$needle.'%')
                        ->orWhere('employee_code', 'like', '%'.$needle.'%')
                        ->orWhere('department', 'like', '%'.$needle.'%');
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
        ?string $search = null
    ): \Illuminate\Database\Eloquent\Builder {
        $q = User::query()->activeRoster();

        if ($actor !== null) {
            $this->dataScopeService->restrictEmployeeQuery($actor, $q);
        }

        if ($singleEmployeeId) {
            $q->where('id', $singleEmployeeId);

            return $q;
        }

        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        if ($departmentId) {
            $q->where('department_id', $departmentId);
        }
        if (is_string($search) && trim($search) !== '') {
            $needle = trim($search);
            $q->where(function ($sub) use ($needle) {
                $sub->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('employee_code', 'like', '%'.$needle.'%')
                    ->orWhere('department', 'like', '%'.$needle.'%');
            });
        }

        return $q;
    }
}
