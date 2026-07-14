<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for company efficiency (chart + modal summary).
 * Attendance efficiency excludes EXECom. Evaluation avg includes EXECom.
 * Workforce efficiency = average of attendance % and HR evaluation % when both exist.
 */
class CompanyEfficiencyService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly AttendanceDailySummaryService $attendanceDailySummaryService,
        private readonly DataScopeService $dataScopeService,
        private readonly EmployeeClassificationService $employeeClassificationService,
        private readonly EmployeeEvaluationResultService $employeeEvaluationResultService,
        private readonly EvaluationScoringService $evaluationScoringService,
    ) {}

    /**
     * @param  array<int>|null  $companyIds
     * @return list<array<string, mixed>>
     */
    public function companiesForRange(Carbon $fromDate, Carbon $toDate, User $actor, ?array $companyIds = null): array
    {
        $ctx = $this->buildContext($fromDate, $toDate, $actor, $companyIds);

        return array_map(static function (array $row): array {
            unset($row['agg']);

            return $row;
        }, $this->finalizeCompanyRows($ctx));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function calculate(int $companyId, Carbon $fromDate, Carbon $toDate, User $actor): ?array
    {
        $rows = $this->companiesForRange($fromDate, $toDate, $actor, [$companyId]);

        return collect($rows)->first(
            fn (array $row) => (int) ($row['company_id'] ?? 0) === $companyId
        );
    }

    /**
     * @return array{company: array<string, mixed>, summary: array<string, mixed>, breakdown: array<string, mixed>, employees: list<array<string, mixed>>, employees_meta: array<string, int>}|null
     */
    public function companyDetails(
        Carbon $fromDate,
        Carbon $toDate,
        User $actor,
        int $companyId,
        int $page = 1,
        int $perPage = 25,
        ?string $search = null,
        ?string $status = null,
        ?int $departmentId = null,
        string $sort = 'date',
        string $direction = 'asc',
    ): ?array {
        $ctx = $this->buildContext($fromDate, $toDate, $actor, [$companyId]);
        $company = Company::find($companyId);
        if (! $company) {
            return null;
        }

        $companyRows = $this->finalizeCompanyRows($ctx);
        $companyRow = collect($companyRows)->first(
            fn (array $row) => (int) ($row['company_id'] ?? 0) === (int) $companyId
        );

        $employees = $ctx['employees_by_company']->get((int) $companyId, collect());
        $uniqueEmployees = $employees->count();
        $numDays = $ctx['num_days'];
        $agg = $companyRow['agg'] ?? $this->emptyAgg();

        $slots = $this->buildEmployeeSlots($ctx, $companyId, $search, $departmentId, $sort, $direction);
        if ($status !== null && trim($status) !== '') {
            $wantedStatus = strtolower(trim($status));
            $slots = array_values(array_filter($slots, function (array $slot) use ($ctx, $wantedStatus): bool {
                $dailySummary = $this->resolveDailySummary(
                    $ctx,
                    $slot['employee'],
                    $slot['date_key'],
                    $slot['day_key'],
                    $slot['date_key'] === $ctx['today_date'],
                );

                return strtolower((string) ($dailySummary['status'] ?? '')) === $wantedStatus;
            }));
        }
        $totalSlots = count($slots);
        $lastPage = max(1, (int) ceil($totalSlots / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;
        $pageSlots = array_slice($slots, $offset, $perPage);

        $employeeRows = [];
        foreach ($pageSlots as $slot) {
            $employeeRows[] = $this->buildEmployeeRow(
                $ctx,
                $slot['employee'],
                $slot['date'],
                $slot['date_key'],
                $slot['day_key'],
            );
        }
        $efficiency = (float) ($companyRow['efficiency_percentage'] ?? $companyRow['efficiency'] ?? 0);
        $attendanceEfficiency = (float) ($companyRow['attendance_efficiency'] ?? $efficiency);
        $evaluationAvg = $companyRow['evaluation_avg'] ?? null;
        $totalScheduledHours = (float) ($companyRow['scheduled_hours'] ?? $companyRow['total_scheduled_hours'] ?? 0);
        $totalPayrollImpactHours = (float) ($companyRow['payroll_impact_hours'] ?? $companyRow['total_payroll_impact_hours'] ?? 0);
        $summary = [
            'company_id' => $companyId,
            'company_name' => $company->name,
            'start_date' => $ctx['from_date_key'],
            'end_date' => $ctx['to_date_key'],
            'employees' => $uniqueEmployees,
            'employee_count' => $uniqueEmployees,
            'included_employee_count' => $uniqueEmployees,
            'excluded_execom_count' => (int) ($companyRow['excluded_execom_count'] ?? 0),
            'employee_days' => $uniqueEmployees * $numDays,
            'present' => (int) ($agg['present'] ?? 0),
            'present_count' => (int) ($agg['present'] ?? 0),
            'absent' => (int) ($agg['absent'] ?? 0),
            'absent_count' => (int) ($agg['absent'] ?? 0),
            'late' => (int) ($agg['late'] ?? 0),
            'late_count' => (int) ($agg['late'] ?? 0),
            'undertime' => (int) ($agg['undertime'] ?? 0),
            'undertime_count' => (int) ($agg['undertime'] ?? 0),
            'scheduled_minutes' => (int) ($companyRow['scheduled_minutes'] ?? 0),
            'expected_scheduled_minutes' => (int) ($companyRow['expected_scheduled_minutes'] ?? 0),
            'payroll_impact_minutes' => (int) ($companyRow['payroll_impact_minutes'] ?? 0),
            'scheduled_hours' => $totalScheduledHours,
            'payroll_impact_hours' => $totalPayrollImpactHours,
            'efficiency' => $efficiency,
            'efficiency_percentage' => $efficiency,
            'attendance_efficiency' => $attendanceEfficiency,
            'evaluation_avg' => $evaluationAvg,
        ];

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => $company->logo ? $this->companyLogoUrl($company->logo) : null,
                'employees' => $uniqueEmployees,
                'included_employee_count' => $uniqueEmployees,
                'excluded_execom_count' => (int) ($companyRow['excluded_execom_count'] ?? 0),
                'date' => $ctx['from_date_key'],
                'from_date' => $ctx['from_date_key'],
                'to_date' => $ctx['to_date_key'],
                'start_date' => $ctx['from_date_key'],
                'end_date' => $ctx['to_date_key'],
            ],
            'summary' => $summary,
            'breakdown' => [
                'total_employees' => $uniqueEmployees,
                'employee_days' => $uniqueEmployees * $numDays,
                'scheduled_employees' => (int) ($agg['scheduled_employees'] ?? 0),
                'present' => (int) ($agg['present'] ?? 0),
                'absent' => (int) ($agg['absent'] ?? 0),
                'late' => (int) ($agg['late'] ?? 0),
                'undertime' => (int) ($agg['undertime'] ?? 0),
                'scheduled_minutes' => (int) ($companyRow['scheduled_minutes'] ?? 0),
                'expected_scheduled_minutes' => (int) ($companyRow['expected_scheduled_minutes'] ?? 0),
                'payroll_impact_minutes' => (int) ($companyRow['payroll_impact_minutes'] ?? 0),
                'total_scheduled_hours' => $totalScheduledHours,
                'total_payroll_impact_hours' => $totalPayrollImpactHours,
                'attendance_efficiency' => $attendanceEfficiency,
                'evaluation_avg' => $evaluationAvg,
                'company_efficiency' => $efficiency,
                'efficiency_percentage' => $efficiency,
            ],
            'data' => $employeeRows,
            'employees' => $employeeRows,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $totalSlots,
                'last_page' => $lastPage,
                'from' => $totalSlots > 0 ? $offset + 1 : 0,
                'to' => $totalSlots > 0 ? $offset + count($employeeRows) : 0,
            ],
            'employees_meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalSlots,
            ],
        ];
    }

    /**
     * @param  array<int>|null  $companyIds
     * @return array<string, mixed>
     */
    private function buildContext(Carbon $fromDate, Carbon $toDate, User $actor, ?array $companyIds): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = Carbon::now($tz)->startOfDay();
        if ($toDate->greaterThan($today)) {
            $toDate = $today->copy();
        }
        if ($fromDate->greaterThan($toDate)) {
            $fromDate = $toDate->copy();
        }
        $fromDateKey = $fromDate->toDateString();
        $toDateKey = $toDate->toDateString();
        $todayDate = $today->toDateString();
        $numDays = (int) $fromDate->diffInDays($toDate) + 1;

        $companies = Company::orderBy('name')->get(['id', 'name', 'logo'])->keyBy('id');

        $employeesQuery = User::activeRoster()
            ->with([
                'workingSchedule',
                'companyHeadships:id,company_head_id',
                'company:id,name',
                'branch:id,company_id',
                'departmentRelation:id,branch_id',
                'departmentRelation.branch:id,company_id',
                'execomProfiles',
            ])
            ->orderByLastName();
        $this->dataScopeService->restrictEmployeeQuery($actor, $employeesQuery);
        $allEmployees = $employeesQuery->get();

        if ($companyIds !== null && $companyIds !== []) {
            $scopedCompanyIds = $allEmployees->map(fn (User $u) => $u->getEffectiveCompanyId())->filter()->unique()->values()->all();
            $companyIds = array_values(array_intersect($companyIds, $scopedCompanyIds));
            $allEmployees = $allEmployees->filter(
                fn (User $u) => in_array($u->getEffectiveCompanyId(), $companyIds, true)
            )->values();
        }

        $excludedExecom = $allEmployees->filter(
            fn (User $employee): bool => $this->employeeClassificationService->isExecom($employee, $fromDate, $toDate)
        )->values();
        $employees = $allEmployees->reject(
            fn (User $employee): bool => $this->employeeClassificationService->isExecom($employee, $fromDate, $toDate)
        )->values();

        $employeeIds = $employees->pluck('id')->all();
        $allEmployeeIds = $allEmployees->pluck('id')->all();
        $allEmployeesByCompany = $allEmployees->groupBy(fn (User $u) => (int) ($u->getEffectiveCompanyId() ?? 0));
        $excludedExecomByCompany = $excludedExecom->groupBy(fn (User $u) => (int) ($u->getEffectiveCompanyId() ?? 0));
        $employeesByCompany = $employees->groupBy(fn (User $u) => (int) ($u->getEffectiveCompanyId() ?? 0));

        $scheduleCache = [];
        foreach ($employees as $employee) {
            $scheduleCache[$employee->id] = $this->attendanceDailySummaryService->resolveEffectiveSchedule($employee);
        }

        $storedSummaries = AttendanceDailySummary::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$fromDateKey, $toDateKey])
            ->get()
            ->keyBy(fn (AttendanceDailySummary $row) => $row->employee_id.'|'.$row->date->toDateString());

        $rangeStart = $fromDate->copy()->startOfDay()->setTimezone('UTC');
        $rangeEnd = $toDate->copy()->endOfDay()->setTimezone('UTC');
        $allLogs = AttendanceLog::query()
            ->whereIn('user_id', $employeeIds)
            ->whereRaw(
                'COALESCE(verified_at, created_at) between ? and ?',
                [$rangeStart->format('Y-m-d H:i:s'), $rangeEnd->format('Y-m-d H:i:s')]
            )
            ->orderByRaw('COALESCE(verified_at, created_at)')
            ->get();

        $logsByEmployeeDate = [];
        foreach ($allLogs as $log) {
            $rawStamp = $log->verified_at ?? $log->created_at;
            if ($rawStamp === null) {
                continue;
            }
            $logDateKey = ($rawStamp instanceof Carbon ? $rawStamp->copy() : Carbon::parse($rawStamp))
                ->timezone($tz)
                ->toDateString();
            $logsByEmployeeDate[$log->user_id][$logDateKey][] = $log;
        }

        $approvedCorrections = AttendanceCorrection::query()
            ->whereBetween('date', [$fromDateKey, $toDateKey])
            ->whereIn('user_id', $employeeIds)
            ->where(function ($query): void {
                $query->where('approved', true)->orWhere('pending_approval', true);
            })
            ->orderByDesc('approved')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (AttendanceCorrection $row) => $row->user_id.'|'.Carbon::parse($row->date)->toDateString())
            ->keyBy(fn (AttendanceCorrection $row) => $row->user_id.'|'.Carbon::parse($row->date)->toDateString());

        // Evaluations include EXECom employees; attendance aggregation above does not.
        // Use latest applicable result as of period end so short ranges (Today/Yesterday)
        // still connect to the Performance Evaluation module.
        $latestEvaluationsByEmployee = $this->employeeEvaluationResultService
            ->getLatestApplicableResultsForEmployees(
                $allEmployeeIds,
                $fromDateKey,
                $toDateKey,
                EmployeeEvaluationResultService::MODE_LATEST_AS_OF_PERIOD_END,
            );

        return [
            'tz' => $tz,
            'from_date' => $fromDate->copy(),
            'to_date' => $toDate->copy(),
            'from_date_key' => $fromDateKey,
            'to_date_key' => $toDateKey,
            'today_date' => $todayDate,
            'now_tz' => Carbon::now($tz),
            'num_days' => $numDays,
            'companies' => $companies,
            'employees' => $employees,
            'employees_by_company' => $employeesByCompany,
            'schedule_cache' => $scheduleCache,
            'stored_summaries' => $storedSummaries,
            'logs_by_employee_date' => $logsByEmployeeDate,
            'approved_corrections' => $approvedCorrections,
            'all_employees_by_company' => $allEmployeesByCompany,
            'excluded_execom_by_company' => $excludedExecomByCompany,
            'latest_evaluations' => $latestEvaluationsByEmployee,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return list<array<string, mixed>>
     */
    private function finalizeCompanyRows(array $ctx): array
    {
        $buckets = [];
        $dateCursor = $ctx['from_date']->copy();

        while ($dateCursor->lessThanOrEqualTo($ctx['to_date'])) {
            $dateKey = $dateCursor->toDateString();
            $dayKey = self::DAY_KEYS[(int) $dateCursor->format('w')];
            $isToday = $dateKey === $ctx['today_date'];

            foreach ($ctx['employees'] as $employee) {
                $companyId = $employee->getEffectiveCompanyId() ?? 0;
                if (! isset($buckets[$companyId])) {
                    $companyModel = $companyId > 0 ? $ctx['companies']->get($companyId) : null;
                    $buckets[$companyId] = [
                        'company_id' => $companyId > 0 ? $companyId : null,
                        'company' => $companyModel?->name ?? 'Unassigned',
                        'logo_url' => $companyModel?->logo ? $this->companyLogoUrl($companyModel->logo) : null,
                        'headcount' => $ctx['employees_by_company']->get($companyId, collect())->count(),
                        'agg' => $this->emptyAgg(),
                    ];
                }

                $dailySummary = $this->resolveDailySummary($ctx, $employee, $dateKey, $dayKey, $isToday);
                // Pending today belongs only in single-day Today; past no-punch rows are absences.
                $status = (string) ($dailySummary['status'] ?? '');
                if (($status === '—' || $status === '-') && $isToday && (int) $ctx['num_days'] > 1) {
                    continue;
                }
                $this->accumulateDayStats(
                    $buckets[$companyId]['agg'],
                    $employee,
                    $dayKey,
                    $dailySummary,
                    $ctx['schedule_cache'],
                    $ctx['stored_summaries']->get($employee->id.'|'.$dateKey),
                );
            }

            $dateCursor->addDay();
        }

        $numDays = $ctx['num_days'];
        $rows = [];
        foreach ($buckets as $bucket) {
            $agg = $bucket['agg'];
            $headcount = (int) $bucket['headcount'];
            $companyId = (int) ($bucket['company_id'] ?? 0);
            $expectedDays = $headcount * $numDays;
            $scheduledMinutes = (int) round(((float) $agg['total_scheduled_hours']) * 60);
            $payrollImpactMinutes = (int) round(((float) $agg['total_payroll_impact_hours']) * 60);
            $totalScheduledHours = round($scheduledMinutes / 60, 2);
            $totalPayrollImpactHours = round($payrollImpactMinutes / 60, 2);
            $attendanceEfficiency = $scheduledMinutes > 0
                ? round(($payrollImpactMinutes / $scheduledMinutes) * 100, 2)
                : null;
            $evaluationAvg = $this->averageCompanyEvaluationPct($ctx, $companyId);
            $efficiency = $this->combineEfficiency($attendanceEfficiency, $evaluationAvg) ?? 0.0;
            $excludedExecomCount = (int) ($ctx['excluded_execom_by_company']->get($companyId, collect())->count());
            $totalCompanyEmployees = (int) ($ctx['all_employees_by_company']->get($companyId, collect())->count());

            Log::info('[CompanyEfficiency] attendance aggregation', [
                'company_id' => $companyId,
                'start_date' => $ctx['from_date_key'],
                'end_date' => $ctx['to_date_key'],
                'total_company_employees' => $totalCompanyEmployees,
                'excluded_execom_employees' => $excludedExecomCount,
                'included_employees' => $headcount,
                'scheduled_workdays' => (int) $agg['scheduled_employees'],
                'absent_workdays' => (int) $agg['absent'],
                'expected_scheduled_minutes' => $scheduledMinutes,
                'payroll_impact_minutes' => $payrollImpactMinutes,
                'attendance_efficiency' => $attendanceEfficiency,
                'evaluation_avg' => $evaluationAvg,
                'efficiency_percentage' => $efficiency,
                'cache_hit' => false,
            ]);

            $rows[] = [
                'company_id' => $bucket['company_id'],
                'company' => $bucket['company'],
                'company_name' => $bucket['company'],
                'logo_url' => $bucket['logo_url'],
                'present' => (int) $agg['present'],
                'present_count' => (int) $agg['present'],
                'late' => (int) $agg['late'],
                'late_count' => (int) $agg['late'],
                'absent' => (int) $agg['absent'],
                'absent_count' => (int) $agg['absent'],
                'undertime' => (int) $agg['undertime'],
                'undertime_count' => (int) $agg['undertime'],
                'on_leave' => (int) $agg['on_leave'],
                'scheduled_employees' => (int) $agg['scheduled_employees'],
                'headcount' => $headcount,
                'employee_count' => $headcount,
                'present_pct' => $expectedDays > 0 ? round(100 * $agg['present'] / $expectedDays, 1) : 0,
                'absent_pct' => $expectedDays > 0 ? round(100 * $agg['absent'] / $expectedDays, 1) : 0,
                'late_pct' => $expectedDays > 0 ? round(100 * $agg['late'] / $expectedDays, 1) : 0,
                'on_leave_pct' => $expectedDays > 0 ? round(100 * $agg['on_leave'] / $expectedDays, 1) : 0,
                'scheduled_minutes' => $scheduledMinutes,
                'expected_scheduled_minutes' => $scheduledMinutes,
                'payroll_impact_minutes' => $payrollImpactMinutes,
                'total_scheduled_hours' => $totalScheduledHours,
                'scheduled_hours' => $totalScheduledHours,
                'total_payroll_impact_hours' => $totalPayrollImpactHours,
                'payroll_impact_hours' => $totalPayrollImpactHours,
                'attendance_efficiency' => $attendanceEfficiency ?? 0.0,
                'evaluation_avg' => $evaluationAvg,
                'efficiency' => $efficiency,
                'efficiency_percentage' => $efficiency,
                'included_employee_count' => $headcount,
                'excluded_execom_count' => $excludedExecomCount,
                'agg' => $agg,
            ];
        }

        usort($rows, fn (array $a, array $b) => ($b['efficiency'] <=> $a['efficiency']) ?: ($b['present'] <=> $a['present']));

        return $rows;
    }

    /** @param array<string, mixed> $ctx @return list<array{employee: User, date: Carbon, date_key: string, day_key: string}> */
    private function buildEmployeeSlots(
        array $ctx,
        int $companyId,
        ?string $search,
        ?int $departmentId,
        string $sort,
        string $direction,
    ): array
    {
        $slots = [];
        $employees = $ctx['employees_by_company']->get((int) $companyId, collect());
        $search = strtolower(trim((string) $search));
        if ($search !== '') {
            $employees = $employees->filter(static function (User $employee) use ($search): bool {
                return str_contains(strtolower((string) ($employee->display_name ?: '')), $search)
                    || str_contains(strtolower((string) ($employee->email ?? '')), $search);
            })->values();
        }
        if ($departmentId !== null && $departmentId > 0) {
            $employees = $employees->filter(
                static fn (User $employee): bool => (int) ($employee->department_id ?? 0) === $departmentId
            )->values();
        }
        $dateCursor = $ctx['from_date']->copy();

        while ($dateCursor->lessThanOrEqualTo($ctx['to_date'])) {
            $dateKey = $dateCursor->toDateString();
            $dayKey = self::DAY_KEYS[(int) $dateCursor->format('w')];
            foreach ($employees as $employee) {
                $slots[] = [
                    'employee' => $employee,
                    'date' => $dateCursor->copy(),
                    'date_key' => $dateKey,
                    'day_key' => $dayKey,
                ];
            }
            $dateCursor->addDay();
        }

        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        usort($slots, static function (array $a, array $b) use ($sort, $direction): int {
            $employeeCmp = strcmp(
                strtolower((string) ($a['employee']->last_name ?? $a['employee']->display_name ?? '')),
                strtolower((string) ($b['employee']->last_name ?? $b['employee']->display_name ?? '')),
            );
            $cmp = $sort === 'employee'
                ? ($employeeCmp ?: strcmp($a['date_key'], $b['date_key']))
                : (strcmp($a['date_key'], $b['date_key']) ?: $employeeCmp);

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return $slots;
    }

    /** @param array<string, mixed> $ctx */
    private function resolveDailySummary(
        array $ctx,
        User $employee,
        string $dateKey,
        string $dayKey,
        bool $isToday,
    ): array {
        $storeKey = $employee->id.'|'.$dateKey;
        $storedSummary = $ctx['stored_summaries']->get($storeKey);

        if ($storedSummary !== null && ! $isToday) {
            return $this->normalizeIncompletePunches($this->dailySummaryFromStoredRow($storedSummary));
        }

        // Today still uses the full attendance engine.
        if ($isToday) {
            return $this->normalizeIncompletePunches(
                $this->attendanceDailySummaryService->computeForDate(
                    user: $employee,
                    dateKey: $dateKey,
                    todayDate: $ctx['today_date'],
                    nowTz: $ctx['now_tz'],
                    effectiveSchedule: $ctx['schedule_cache'][$employee->id] ?? null,
                    preloadedLogs: $ctx['logs_by_employee_date'][$employee->id][$dateKey] ?? null,
                    correction: $ctx['approved_corrections']->get($storeKey),
                ),
            );
        }

        // ponytail: historical custom/month ranges without dense daily summaries must not call
        // computeForDate per employee-day (O(employees×days) ~60s+). Use log+schedule estimate;
        // upgrade by backfilling attendance_daily_summaries then prefer stored rows.
        return $this->normalizeIncompletePunches(
            $this->estimateHistoricalDaySummary($ctx, $employee, $dateKey, $dayKey),
        );
    }

    /**
     * Fast historical day estimate from preloaded logs + schedule (chart/modal summary path).
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function estimateHistoricalDaySummary(
        array $ctx,
        User $employee,
        string $dateKey,
        string $dayKey,
    ): array {
        $tz = $ctx['tz'];
        $schedule = $ctx['schedule_cache'][$employee->id] ?? null;
        $daySched = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
        $hasSchedule = is_array($daySched) && ! empty($daySched['in']) && ! empty($daySched['out']);

        if (! $hasSchedule) {
            return [
                'status' => 'rest',
                'status_label' => 'Rest Day',
                'schedule_in' => null,
                'schedule_out' => null,
                'formatted_time_in' => null,
                'formatted_time_out' => null,
                'time_in' => null,
                'time_out' => null,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 0.0,
                'is_rest_day' => true,
                'is_rest_day_worked' => false,
                'is_leave' => false,
                'is_holiday' => false,
                'presence_label' => null,
                'presence_issue' => 'none',
            ];
        }

        $storeKey = $employee->id.'|'.$dateKey;
        $correction = $ctx['approved_corrections']->get($storeKey);
        $logs = $ctx['logs_by_employee_date'][$employee->id][$dateKey] ?? [];

        $timeIn = null;
        $timeOut = null;
        foreach ($logs as $log) {
            if (! $log instanceof AttendanceLog) {
                continue;
            }
            $rawStamp = $log->verified_at ?? $log->created_at;
            if ($rawStamp === null) {
                continue;
            }
            $stamp = ($rawStamp instanceof Carbon ? $rawStamp->copy() : Carbon::parse($rawStamp))
                ->timezone($tz)
                ->second(0);
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                if ($timeIn === null) {
                    $timeIn = $stamp;
                }
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $timeOut = $stamp;
            }
        }

        if ($correction && $correction->approved && $correction->pending_approval !== true) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in instanceof Carbon
                    ? $correction->time_in->copy()->timezone($tz)->second(0)
                    : Carbon::parse($correction->time_in)->timezone($tz)->second(0);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out instanceof Carbon
                    ? $correction->time_out->copy()->timezone($tz)->second(0)
                    : Carbon::parse($correction->time_out)->timezone($tz)->second(0);
            }
        }

        $scheduleIn = (string) $daySched['in'];
        $scheduleOut = (string) $daySched['out'];
        $scheduledHours = $this->computeScheduleHours($daySched, $dateKey);

        if ($timeIn === null && $timeOut === null) {
            return [
                'status' => 'absent',
                'status_label' => 'Absent',
                'schedule_in' => $scheduleIn,
                'schedule_out' => $scheduleOut,
                'formatted_time_in' => null,
                'formatted_time_out' => null,
                'time_in' => null,
                'time_out' => null,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 0.0,
                'is_rest_day' => false,
                'is_rest_day_worked' => false,
                'is_leave' => false,
                'is_holiday' => false,
                'presence_label' => null,
                'presence_issue' => 'none',
                'scheduled_regular_hours' => $scheduledHours,
            ];
        }

        // Incomplete shift: one punch only must not count as Present or full-day payroll impact.
        if ($timeIn === null || $timeOut === null) {
            $missing = $this->missingPunchMeta($timeIn !== null, $timeOut !== null);

            return [
                'status' => 'incomplete',
                'status_label' => $missing['status_label'],
                'schedule_in' => $scheduleIn,
                'schedule_out' => $scheduleOut,
                'formatted_time_in' => $timeIn?->format('g:i A'),
                'formatted_time_out' => $timeOut?->format('g:i A'),
                'time_in' => $timeIn?->format('H:i:s'),
                'time_out' => $timeOut?->format('H:i:s'),
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'payroll_impact_hours' => 0.0,
                'is_rest_day' => false,
                'is_rest_day_worked' => false,
                'is_leave' => false,
                'is_holiday' => false,
                'presence_label' => $missing['status_label'],
                'presence_issue' => $missing['presence_issue'],
                'scheduled_regular_hours' => $scheduledHours,
                'correction_remarks' => is_string($correction?->remarks) ? trim($correction->remarks) : null,
            ];
        }

        $lateMinutes = 0;
        $lateLabel = null;
        $clockIn = AttendanceStatusService::getClockInStatus($daySched, $dateKey, $timeIn);
        if (($clockIn['status'] ?? '') === 'late') {
            $lateMinutes = (int) ($clockIn['late_minutes'] ?? 0);
            $lateLabel = $clockIn['late_label'] ?? null;
        }

        $presenceLabel = null;
        $presenceIssue = 'none';
        if ($correction && $correction->pending_approval) {
            $presenceLabel = 'Present (Pending Correction)';
            $presenceIssue = 'correction_pending';
        } elseif ($correction && $correction->approved) {
            $presenceLabel = 'Present (Approved)';
            $presenceIssue = 'approved_correction';
        }

        $status = $lateMinutes > 0 ? 'late' : 'present';
        $payHours = max(0.0, $scheduledHours - ($lateMinutes / 60));

        return [
            'status' => $status,
            'status_label' => $lateLabel ?: ($status === 'late' ? 'Late' : 'Present'),
            'schedule_in' => $scheduleIn,
            'schedule_out' => $scheduleOut,
            'formatted_time_in' => $timeIn?->format('g:i A'),
            'formatted_time_out' => $timeOut?->format('g:i A'),
            'time_in' => $timeIn?->format('H:i:s'),
            'time_out' => $timeOut?->format('H:i:s'),
            'late_minutes' => $lateMinutes,
            'late_label' => $lateLabel,
            'undertime_minutes' => 0,
            'payroll_impact_hours' => round($payHours, 2),
            'is_rest_day' => false,
            'is_rest_day_worked' => false,
            'is_leave' => false,
            'is_holiday' => false,
            'presence_label' => $presenceLabel,
            'presence_issue' => $presenceIssue,
            'scheduled_regular_hours' => $scheduledHours,
            'correction_remarks' => is_string($correction?->remarks) ? trim($correction->remarks) : null,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private function buildEmployeeRow(
        array $ctx,
        User $employee,
        Carbon $date,
        string $dateKey,
        string $dayKey,
    ): array {
        $isToday = $dateKey === $ctx['today_date'];
        $storedSummary = $ctx['stored_summaries']->get($employee->id.'|'.$dateKey);
        $dailySummary = $this->resolveDailySummary($ctx, $employee, $dateKey, $dayKey, $isToday);

        $status = $dailySummary['status'] ?? '';
        $lateMinutes = (int) ($dailySummary['late_minutes'] ?? 0);
        $undertimeMinutes = (int) ($dailySummary['undertime_minutes'] ?? 0);
        $payrollImpactHours = (float) ($dailySummary['payroll_impact_hours'] ?? 0);
        $scheduleIn = $dailySummary['schedule_in'] ?? null;
        $scheduleOut = $dailySummary['schedule_out'] ?? null;
        $profileImageUrl = $storedSummary?->profile_image ?: $employee->profile_image_url;
        $scheduledHours = $this->resolveEmployeeDayScheduledHours(
            $employee,
            $dayKey,
            $dailySummary,
            $ctx['schedule_cache'],
            $storedSummary,
        );
        // Complete present rows; missing-out / live clocked-in get provisional payroll for efficiency.
        $isPresentComplete = in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime'], true);
        $isMissingOut = $status === 'incomplete' && ($dailySummary['presence_issue'] ?? '') === 'missing_out';
        $payForEff = $isPresentComplete ? $payrollImpactHours : 0.0;
        if ($payForEff <= 0 && $scheduledHours > 0 && ($status === 'clocked_in' || $isMissingOut)) {
            $payForEff = $payrollImpactHours > 0
                ? $payrollImpactHours
                : max(0.0, $scheduledHours - ($lateMinutes / 60));
        }
        $attendanceEfficiencyPct = $scheduledHours > 0
            ? round(($payForEff / $scheduledHours) * 100, 2)
            : null;
        $companyId = (int) ($employee->getEffectiveCompanyId() ?? 0);
        $company = $companyId > 0 ? $ctx['companies']->get($companyId) : null;
        $correction = $ctx['approved_corrections']->get($employee->id.'|'.$dateKey);
        $remarks = $this->resolveAttendanceRemarks($dailySummary, $storedSummary, $correction);
        $latestEvaluation = $ctx['latest_evaluations']->get($employee->id);
        $hrEvaluationPct = isset($latestEvaluation['evaluation_percentage'])
            ? (float) $latestEvaluation['evaluation_percentage']
            : null;
        $evaluationRating = is_string($latestEvaluation['performance_level'] ?? null)
            && trim((string) $latestEvaluation['performance_level']) !== ''
            ? trim((string) $latestEvaluation['performance_level'])
            : ($hrEvaluationPct !== null
                ? $this->evaluationScoringService->ratingLabelFromPercentage($hrEvaluationPct)
                : null);
        $combinedEfficiencyPct = $this->combineEfficiency($attendanceEfficiencyPct, $hrEvaluationPct);

        return [
            'id' => $employee->id,
            'employee_name' => $employee->display_name ?: '—',
            'company' => $company?->name ?? 'Unassigned',
            'company_name' => $company?->name ?? 'Unassigned',
            'profile_image' => $profileImageUrl,
            'profile_image_url' => $profileImageUrl,
            'department' => $employee->department ?? '-',
            'date' => $dateKey,
            'day' => $date->format('D'),
            'schedule' => $this->formatScheduleLabel(
                $scheduleIn,
                $scheduleOut,
                $storedSummary?->schedule_label,
                (bool) ($dailySummary['is_rest_day'] ?? false),
            ),
            'time_in' => $dailySummary['formatted_time_in'] ?? ($dailySummary['time_in'] ? date('g:i A', strtotime((string) $dailySummary['time_in'])) : '—'),
            'time_out' => $dailySummary['formatted_time_out'] ?? ($dailySummary['time_out'] ? date('g:i A', strtotime((string) $dailySummary['time_out'])) : '—'),
            'status' => $dailySummary['status_label'] ?? $status,
            'status_code' => $status,
            'late_minutes' => $status === 'incomplete' ? 0 : $lateMinutes,
            'undertime_minutes' => $status === 'incomplete' ? 0 : $undertimeMinutes,
            'payroll_impact' => $payForEff,
            'scheduled_hours' => round($scheduledHours, 2),
            'remarks' => $remarks,
            'presence_label' => $dailySummary['presence_label'] ?? null,
            'presence_issue' => $dailySummary['presence_issue'] ?? null,
            'classification' => $this->employeeClassificationService->label($employee, $ctx['from_date'], $ctx['to_date']),
            'is_execom' => $this->employeeClassificationService->isExecom($employee, $ctx['from_date'], $ctx['to_date']),
            'evaluation_id' => $latestEvaluation['evaluation_id'] ?? null,
            'evaluation_status' => $latestEvaluation['status'] ?? null,
            'evaluation_pct' => $hrEvaluationPct,
            'evaluation_percentage' => $hrEvaluationPct,
            'evaluation_rating' => $evaluationRating,
            'attendance_efficiency_pct' => $attendanceEfficiencyPct,
            'combined_efficiency_pct' => $combinedEfficiencyPct,
            'efficiency_performance' => $evaluationRating,
            'performance' => $evaluationRating,
        ];
    }

    /**
     * Build Remarks from presence annotations (Present (Approved), incomplete, pending, etc.).
     */
    private function resolveAttendanceRemarks(
        array $dailySummary,
        ?AttendanceDailySummary $storedSummary = null,
        ?AttendanceCorrection $correction = null,
    ): ?string {
        $parts = [];

        $presenceLabel = trim((string) ($dailySummary['presence_label'] ?? $storedSummary?->presence_label ?? ''));
        $presenceIssue = strtolower(trim((string) ($dailySummary['presence_issue'] ?? $storedSummary?->presence_issue ?? '')));
        $hasCorrection = (bool) ($dailySummary['has_correction'] ?? $storedSummary?->has_correction ?? false);
        $correctionApproved = (bool) ($dailySummary['correction_approved'] ?? $storedSummary?->correction_approved ?? false)
            || ($correction !== null && (bool) $correction->approved);

        if ($presenceLabel === '') {
            $presenceLabel = match ($presenceIssue) {
                'approved_correction' => 'Present (Approved)',
                'correction_pending' => 'Present (Pending Correction)',
                'incomplete_pair', 'missing_in', 'missing_out' => (string) ($dailySummary['status_label'] ?? 'Incomplete'),
                default => '',
            };
        }
        if ($presenceLabel === '' && $correctionApproved) {
            $presenceLabel = 'Present (Approved)';
        } elseif ($presenceLabel === '' && $hasCorrection && ! $correctionApproved) {
            $presenceLabel = 'Present (Pending Correction)';
        }
        if ($presenceLabel === '' && in_array(($dailySummary['status'] ?? ''), ['incomplete'], true)) {
            $presenceLabel = (string) ($dailySummary['status_label'] ?? 'Incomplete');
        }

        if ($presenceLabel !== '') {
            $parts[] = $presenceLabel;
        }

        $holidayName = trim((string) ($dailySummary['holiday_name'] ?? $storedSummary?->holiday_name ?? ''));
        if ($holidayName !== '' && ! in_array($holidayName, $parts, true)) {
            $parts[] = $holidayName;
        }

        $lateLabel = trim((string) ($dailySummary['late_label'] ?? ''));
        if ($lateLabel !== '' && ! in_array($lateLabel, $parts, true) && $presenceLabel === '') {
            $parts[] = $lateLabel;
        }

        $note = trim((string) (
            $dailySummary['correction_remarks']
            ?? (is_array($storedSummary?->extra) ? ($storedSummary->extra['correction_remarks'] ?? null) : null)
            ?? $correction?->remarks
            ?? ''
        ));
        if ($note !== '' && ! in_array($note, $parts, true)) {
            $parts[] = $note;
        }

        if ($parts === []) {
            return null;
        }

        return implode(' — ', $parts);
    }

    /**
     * Company evaluation average — includes EXECom employees.
     * Attendance efficiency intentionally uses the non-EXECom roster only.
     *
     * @param  array<string, mixed>  $ctx
     */
    private function averageCompanyEvaluationPct(array $ctx, int $companyId): ?float
    {
        $employees = $ctx['all_employees_by_company']->get($companyId, collect());
        $evaluations = $ctx['latest_evaluations'] ?? collect();
        $pcts = [];
        foreach ($employees as $employee) {
            $latest = $evaluations->get($employee->id);
            if (! is_array($latest) || ! isset($latest['evaluation_percentage'])) {
                continue;
            }
            $pcts[] = (float) $latest['evaluation_percentage'];
        }

        if ($pcts === []) {
            return null;
        }

        return round(array_sum($pcts) / count($pcts), 2);
    }

    /** @return array<string, int|float> */
    private function emptyAgg(): array
    {
        return [
            'scheduled_employees' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'undertime' => 0,
            'on_leave' => 0,
            'total_scheduled_hours' => 0.0,
            'total_payroll_impact_hours' => 0.0,
        ];
    }

    /** @param array<string, int|float> $agg @param array<string, mixed> $dailySummary @param array<int, array<string, mixed>|null> $scheduleCache */
    private function accumulateDayStats(
        array &$agg,
        User $employee,
        string $dayKey,
        array $dailySummary,
        array $scheduleCache,
        ?AttendanceDailySummary $storedSummary = null,
    ): void {
        $status = $dailySummary['status'] ?? '';
        $lateMinutes = (int) ($dailySummary['late_minutes'] ?? 0);
        $undertimeMinutes = (int) ($dailySummary['undertime_minutes'] ?? 0);
        $payrollImpactHours = (float) ($dailySummary['payroll_impact_hours'] ?? 0);
        $scheduleIn = $dailySummary['schedule_in'] ?? null;
        $scheduleOut = $dailySummary['schedule_out'] ?? null;

        $isScheduled = $scheduleIn !== null && $scheduleOut !== null;
        $isPresentComplete = in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime'], true);
        $isClockedIn = $status === 'clocked_in';
        $isIncomplete = $status === 'incomplete';
        $isMissingOut = $isIncomplete && ($dailySummary['presence_issue'] ?? '') === 'missing_out';
        $isPendingNoPunch = $status === '—' || $status === '-';
        $isAbsent = ($status === 'absent' || $isPendingNoPunch)
            && $isScheduled
            && ! ($dailySummary['is_leave'] ?? false)
            && ! ($dailySummary['is_rest_day'] ?? false)
            && ! ($dailySummary['is_holiday'] ?? false);
        $isLate = $lateMinutes > 0 && $isPresentComplete;
        $isUndertime = $undertimeMinutes > 0 && $isPresentComplete;
        $isLeave = ($dailySummary['is_leave'] ?? false) || $status === 'leave';

        if ($isScheduled) {
            $agg['scheduled_employees']++;
        }
        if ($isLeave) {
            $agg['on_leave']++;
        } elseif ($isAbsent) {
            $agg['absent']++;
        } elseif ($isPresentComplete || $isClockedIn || $isMissingOut) {
            $agg['present']++;
        }
        if ($isLate) {
            $agg['late']++;
        }
        if ($isUndertime) {
            $agg['undertime']++;
        }

        if (($dailySummary['is_rest_day'] ?? false) && ! ($dailySummary['is_rest_day_worked'] ?? false)) {
            return;
        }

        if ($dailySummary['is_leave'] ?? false) {
            return;
        }

        if ($status === 'upcoming' || $status === 'rest' || ! $isScheduled) {
            return;
        }

        $scheduledHours = $this->resolveEmployeeDayScheduledHours(
            $employee,
            $dayKey,
            $dailySummary,
            $scheduleCache,
            $storedSummary,
        );
        if ($scheduledHours > 0) {
            $payHours = 0.0;
            if ($isPresentComplete) {
                $payHours = $payrollImpactHours;
            } elseif ($isClockedIn || $isMissingOut) {
                // Open live shift / missing clock-out: employees showed up — credit provisional pay.
                // ponytail: ceiling = full schedule until time-out; switch to live worked minutes if dashboards need mid-shift accuracy.
                $payHours = $payrollImpactHours > 0
                    ? $payrollImpactHours
                    : max(0.0, $scheduledHours - ($lateMinutes / 60));
            }
            // Missing-in (and other incomplete): keep scheduled hours in the denominator, zero payroll impact.
            // Status "—" (before absent cutoff, no punch yet): scheduled only, zero pay.
            $agg['total_scheduled_hours'] += $scheduledHours;
            $agg['total_payroll_impact_hours'] += ($isIncomplete && ! $isMissingOut) ? 0.0 : $payHours;
        }
    }

    /**
     * Workforce efficiency = average of attendance % and HR evaluation % when both exist.
     */
    private function combineEfficiency(?float $attendancePct, ?float $evaluationPct): ?float
    {
        if ($attendancePct === null && $evaluationPct === null) {
            return null;
        }
        if ($evaluationPct === null) {
            return round((float) $attendancePct, 2);
        }
        if ($attendancePct === null) {
            return round((float) $evaluationPct, 2);
        }

        return round(($attendancePct + $evaluationPct) / 2, 2);
    }

    private function dailySummaryFromStoredRow(AttendanceDailySummary $stored): array
    {
        $status = (string) ($stored->status ?? '');

        return [
            'status' => $status,
            'status_label' => $stored->presence_label ?: $status,
            'schedule_in' => $stored->schedule_in,
            'schedule_out' => $stored->schedule_out,
            'formatted_time_in' => $stored->formatted_time_in,
            'formatted_time_out' => $stored->formatted_time_out,
            'time_in' => $stored->time_in,
            'time_out' => $stored->time_out,
            'late_minutes' => (int) ($stored->late_minutes ?? 0),
            'undertime_minutes' => (int) ($stored->undertime_minutes ?? 0),
            'payroll_impact_hours' => (float) ($stored->payroll_impact_hours ?? 0),
            'is_rest_day' => (bool) $stored->is_rest_day,
            'is_rest_day_worked' => (bool) $stored->is_rest_day
                && in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime', 'clocked_in'], true),
            'is_leave' => $status === 'leave',
            'is_holiday' => $status === 'holiday',
            'scheduled_regular_hours' => $stored->scheduled_regular_hours,
            'presence_label' => $stored->presence_label,
            'presence_issue' => $stored->presence_issue,
            'has_correction' => (bool) $stored->has_correction,
            'correction_approved' => (bool) $stored->correction_approved,
            'holiday_name' => $stored->holiday_name,
            'late_label' => is_array($stored->extra) ? ($stored->extra['late_label'] ?? null) : null,
            'correction_remarks' => is_array($stored->extra) ? ($stored->extra['correction_remarks'] ?? null) : null,
            'remarks' => null,
        ];
    }

    /**
     * One-sided punches must not look like Present or earn full-day payroll impact.
     * Live mid-shift `clocked_in` stays as-is (attendance engine owns that label).
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function normalizeIncompletePunches(array $summary): array
    {
        $status = (string) ($summary['status'] ?? '');
        if (in_array($status, ['leave', 'holiday', 'rest', 'upcoming', 'absent', 'clocked_in'], true)) {
            return $summary;
        }

        $hasIn = $this->hasPunchTime($summary['time_in'] ?? $summary['formatted_time_in'] ?? null);
        $hasOut = $this->hasPunchTime($summary['time_out'] ?? $summary['formatted_time_out'] ?? null);
        $missing = $this->missingPunchMeta($hasIn, $hasOut);
        if ($missing === null) {
            return $summary;
        }

        $summary['status'] = 'incomplete';
        $summary['status_label'] = $missing['status_label'];
        $summary['presence_label'] = $missing['status_label'];
        $summary['presence_issue'] = $missing['presence_issue'];
        $summary['payroll_impact_hours'] = 0.0;
        $summary['late_minutes'] = 0;
        $summary['undertime_minutes'] = 0;

        return $summary;
    }

    /**
     * @return array{status_label: string, presence_issue: string}|null
     */
    private function missingPunchMeta(bool $hasIn, bool $hasOut): ?array
    {
        if ($hasIn && $hasOut) {
            return null;
        }
        if (! $hasIn && ! $hasOut) {
            return null;
        }

        if ($hasIn && ! $hasOut) {
            return [
                'status_label' => 'Missing out',
                'presence_issue' => 'missing_out',
            ];
        }

        return [
            'status_label' => 'Missing in',
            'presence_issue' => 'missing_in',
        ];
    }

    private function hasPunchTime(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        $raw = trim((string) $value);

        return $raw !== ''
            && $raw !== '—'
            && $raw !== '-'
            && $raw !== '--'
            && strtolower($raw) !== 'null';
    }

    /** @param array<string, mixed> $dailySummary @param array<int, array<string, mixed>|null> $scheduleCache */
    private function resolveEmployeeDayScheduledHours(
        User $employee,
        string $dayKey,
        array $dailySummary,
        array $scheduleCache,
        ?AttendanceDailySummary $storedSummary = null,
    ): float {
        if ($storedSummary?->scheduled_regular_hours !== null && (float) $storedSummary->scheduled_regular_hours > 0) {
            return (float) $storedSummary->scheduled_regular_hours;
        }

        if (($dailySummary['is_rest_day'] ?? false) && ! ($dailySummary['is_rest_day_worked'] ?? false)) {
            return 0.0;
        }

        $schedule = $scheduleCache[$employee->id] ?? null;
        $daySched = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
        if (! $daySched || empty($daySched['in']) || empty($daySched['out'])) {
            return 0.0;
        }

        if ($dailySummary['is_leave'] ?? false) {
            return $this->computeScheduleHours($daySched);
        }

        $status = $dailySummary['status'] ?? '';
        $scheduleIn = $dailySummary['schedule_in'] ?? null;
        $scheduleOut = $dailySummary['schedule_out'] ?? null;
        $isScheduled = $scheduleIn !== null && $scheduleOut !== null;
        if ($status === 'upcoming' || $status === 'rest' || ! $isScheduled) {
            return 0.0;
        }

        return $this->computeScheduleHours($daySched);
    }

    /**
     * Net scheduled paid hours (shift span minus unpaid breaks).
     * Uses ScheduleComputationService via getRequiredWorkingMinutes so breaks[] /
     * break_start-end match attendance/payroll calendar math — not gross in→out.
     */
    private function computeScheduleHours(array $daySchedule, ?string $dateKey = null): float
    {
        $in = trim((string) ($daySchedule['in'] ?? ''));
        $out = trim((string) ($daySchedule['out'] ?? ''));
        $shiftType = (string) ($daySchedule['shift_type'] ?? 'fixed');
        if ($in === '' && $out === '' && $shiftType !== 'split') {
            return 0.0;
        }

        $minutes = AttendanceStatusService::getRequiredWorkingMinutes(
            $dateKey ?: '2000-01-03',
            $daySchedule,
        );
        if ($minutes > 0) {
            return round($minutes / 60, 2);
        }

        // Open-ended schedule (in only): assume a standard paid day.
        if ($in !== '' && $out === '') {
            return 8.0;
        }

        return 0.0;
    }

    private function formatScheduleLabel(
        ?string $scheduleIn,
        ?string $scheduleOut,
        ?string $scheduleLabel = null,
        bool $isRestDay = false,
    ): string {
        if ($isRestDay) {
            return 'Rest Day';
        }

        if (is_string($scheduleLabel) && trim($scheduleLabel) !== '') {
            $label = trim($scheduleLabel);
            if (preg_match('/^(\d{1,2}:\d{2}(?::\d{2})?)\s*[-\x{2013}]\s*(\d{1,2}:\d{2}(?::\d{2})?)$/u', $label, $matches)) {
                return $this->formatClockTime12h($matches[1]).' - '.$this->formatClockTime12h($matches[2]);
            }

            return $label;
        }

        $in = trim((string) ($scheduleIn ?? ''));
        $out = trim((string) ($scheduleOut ?? ''));

        return $in !== '' && $out !== '' ? $this->formatClockTime12h($in).' - '.$this->formatClockTime12h($out) : '—';
    }

    private function formatClockTime12h(string $time): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches)) {
            return trim($time);
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $period = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12 ?: 12;

        return sprintf('%d:%02d %s', $hour12, $minute, $period);
    }

    private function companyLogoUrl(?string $path): ?string
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
        if (! Storage::disk('public')->exists($normalized)) {
            return null;
        }
        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $s) => rawurlencode($s), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }
}
