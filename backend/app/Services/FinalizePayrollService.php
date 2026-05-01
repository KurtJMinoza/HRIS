<?php

namespace App\Services;

use App\Models\PayrollBatchRun;
use App\Models\PayrollPeriod;
use App\Models\Payslip;
use App\Models\UserAdminActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Finalize payroll batch: preview totals (computed from {@see PayrollComputationService}) and
 * finalize (persist {@see PayrollPeriod} + loan/statutory hooks, generate payslip PDFs, lock periods, audit batch).
 *
 * Uses the same pay window as {@see PayslipService::generatePayslip} via {@see PayslipService::resolveComputationWindow}.
 */
class FinalizePayrollService
{
    private const PREVIEW_MAX_EMPLOYEES = 500;

    private const PREVIEW_CACHE_TTL_SECONDS = 45;

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
        $cacheKey = $this->previewCacheKey(
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

        $cached = Cache::remember($cacheKey, now()->addSeconds(self::PREVIEW_CACHE_TTL_SECONDS), function () use (
            $companyId,
            $branchId,
            $departmentId,
            $singleEmployeeId,
            $periodInput,
            $actor,
            $search,
            $page,
            $perPage
        ) {
            $scopedEmployeesQuery = $this->scopedEmployees($companyId, $branchId, $departmentId, $singleEmployeeId, $actor, $search);
            $scopedCount = (int) (clone $scopedEmployeesQuery)->count();
            $scopedEmployeeIds = (clone $scopedEmployeesQuery)
                ->orderBy('name', 'asc')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $currentPage = max(1, $page);
            $limit = max(1, min(100, $perPage));
            $offset = ($currentPage - 1) * $limit;
            $employees = (clone $scopedEmployeesQuery)
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
                ->orderBy('name', 'asc')
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
            $lockingStatuses = array_map('strtolower', Payslip::lockingStatuses());
            // Ensure relations required by computation are available without N+1.
            $employees->loadMissing([
                'company',
                'branch',
                'payCycle',
                'governmentIds',
                'workingSchedule',
            ]);

            foreach ($employees as $employee) {
                $stored = $payslipByUser->get((int) $employee->id);
                $storedStatus = strtolower(trim((string) ($stored?->status ?? '')));
                $storedIsPublished = $stored instanceof Payslip && in_array($storedStatus, $lockingStatuses, true);
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

                    // Always recompute preview rows from attendance/daily computation, even when a
                    // payslip already exists. Stored snapshots can contain stale Regular Pay units
                    // (for example "2 days") after undertime corrections; preview must show actual
                    // worked minutes for the selected pay period.
                    $shouldRecomputeStoredPreview = true;
                    if ($shouldRecomputeStoredPreview || (($baseMonthly <= 0 && $gross <= 0 && $net <= 0) || empty($dailyEarningLines))) {
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
                    } else {
                        [$from, $to, $cyclePreview, $cycle] = $this->payslipService->resolveComputationWindow($employee, $periodInput);
                    }
                } else {
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

            $fullTotals = null;
            if (count($scopedEmployeeIds) > 0) {
                $aggregateQuery = Payslip::query()->whereIn('user_id', $scopedEmployeeIds);
                $this->applyPreviewPeriodFiltersEloquent($aggregateQuery, $periodInput);
                $aggregateRows = (clone $aggregateQuery)
                    ->selectRaw('COUNT(*) as rows_count, SUM(gross_pay) as gross_sum, SUM(total_deductions) as deductions_sum')
                    ->first();
                $aggregateCount = (int) ($aggregateRows?->rows_count ?? 0);
                if ($aggregateCount === $scopedCount && $scopedCount > 0) {
                    $grossSum = round((float) ($aggregateRows?->gross_sum ?? 0), 2);
                    $deductionsSum = round((float) ($aggregateRows?->deductions_sum ?? 0), 2);
                    $fullTotals = [
                        'gross' => $grossSum,
                        'ded' => $deductionsSum,
                        // Keep preview totals real-time even when persisted net_pay has not been synchronized yet.
                        'net' => round($grossSum - $deductionsSum, 2),
                    ];
                }
            }
            $resolvedTotals = $fullTotals ?? [
                'gross' => round((float) $pageTotals['gross'], 2),
                'ded' => round((float) $pageTotals['ded'], 2),
                'net' => round((float) $pageTotals['net'], 2),
            ];

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
        });

        Log::info('Payroll finalize preview: summary served', [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $singleEmployeeId,
            'elapsed_ms' => round((microtime(true) - $previewStartedAt) * 1000, 2),
            'cache_ttl_seconds' => self::PREVIEW_CACHE_TTL_SECONDS,
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
     * Current {@see PayrollBatchRun} for this scope + pay window (not cached — reflects finalize queue state).
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

        return [
            'payroll_batch_run_id' => (int) $run->id,
            'batch_key' => $batchKey,
            'status' => (string) $run->status,
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

    /**
     * @param  array<string, mixed>  $periodInput
     */
    private function previewCacheKey(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        array $periodInput,
        ?User $actor,
        int $page = 1,
        int $perPage = 25,
        ?string $search = null
    ): string {
        $normalizedPeriodInput = [
            'from_date' => $periodInput['from_date'] ?? null,
            'to_date' => $periodInput['to_date'] ?? null,
            'pay_cycle_id' => $periodInput['pay_cycle_id'] ?? null,
            'reference_date' => $periodInput['reference_date'] ?? null,
            'payroll_period_id' => $periodInput['payroll_period_id'] ?? null,
            'is_final_pay' => (bool) ($periodInput['is_final_pay'] ?? false),
            // Optional caller-provided cache buster for real-time attendance/correction refresh from UI.
            'refresh_token' => $periodInput['refresh_token'] ?? null,
        ];

        return 'payroll_finalize_preview:'.hash('sha256', json_encode([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $singleEmployeeId,
            'actor_id' => $actor?->id,
            'period' => $normalizedPeriodInput,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
            'search' => $search !== null ? trim((string) $search) : null,
        ]));
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
                        $generated = $this->generatePayslipForFinalize($user, $payslipInput, is_array($summary) ? $summary : []);
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
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
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
                    is_array($computed['summary'] ?? null) ? $computed['summary'] : []
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
    private function generatePayslipForFinalize(User $user, array $payslipInput, array $computedSummary = []): Payslip
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
            return $this->payslipService->generatePayslip($user, $payslipInput)['payslip'];
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

    private function canReuseExistingPayslipForFinalize(Payslip $payslip): bool
    {
        $snapshot = is_array($payslip->snapshot ?? null) ? $payslip->snapshot : [];
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $hasSnapshotSummary = count($summary) > 0;
        if (! $hasSnapshotSummary) {
            return false;
        }

        $dailyLines = is_array($summary['daily_computation_earning_lines'] ?? null)
            ? $summary['daily_computation_earning_lines']
            : [];
        foreach ($dailyLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $key = strtolower(trim((string) ($line['key'] ?? '')));
            $label = strtolower(trim((string) ($line['label'] ?? '')));
            if (str_contains($key, 'regular_pay') || $label === 'regular pay') {
                // Regular Pay is attendance-sensitive. Recompute existing payslips instead of
                // reusing a PDF/snapshot that may still contain scheduled-day units after undertime.
                return false;
            }
        }

        $hasRequiredTotals = is_numeric($payslip->gross_pay)
            && is_numeric($payslip->total_deductions)
            && is_numeric($payslip->net_pay);
        if (! $hasRequiredTotals) {
            return false;
        }

        $relativePdfPath = trim((string) ($payslip->pdf_path ?? ''));
        if ($relativePdfPath === '') {
            return false;
        }

        return Storage::disk('local')->exists('private/'.$relativePdfPath);
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

    private function scopedEmployees(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $singleEmployeeId,
        ?User $actor = null,
        ?string $search = null
    ): \Illuminate\Database\Eloquent\Builder {
        $q = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);

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
