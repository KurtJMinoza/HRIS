<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDailySummary;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Evaluation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for company efficiency (chart + modal summary).
 * Attendance = payroll impact hours / scheduled hours.
 * Final efficiency averages that with latest completed HR Evaluation % when present.
 */
class CompanyEfficiencyService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly AttendanceDailySummaryService $attendanceDailySummaryService,
        private readonly EvaluationScoringService $evaluationScoringService,
        private readonly DataScopeService $dataScopeService,
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
            'employee_days' => $uniqueEmployees * $numDays,
            'present' => (int) ($agg['present'] ?? 0),
            'present_count' => (int) ($agg['present'] ?? 0),
            'absent' => (int) ($agg['absent'] ?? 0),
            'absent_count' => (int) ($agg['absent'] ?? 0),
            'late' => (int) ($agg['late'] ?? 0),
            'late_count' => (int) ($agg['late'] ?? 0),
            'undertime' => (int) ($agg['undertime'] ?? 0),
            'undertime_count' => (int) ($agg['undertime'] ?? 0),
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
            ])
            ->orderByLastName();
        $this->dataScopeService->restrictEmployeeQuery($actor, $employeesQuery);
        $employees = $employeesQuery->get();

        if ($companyIds !== null && $companyIds !== []) {
            $scopedCompanyIds = $employees->map(fn (User $u) => $u->getEffectiveCompanyId())->filter()->unique()->values()->all();
            $companyIds = array_values(array_intersect($companyIds, $scopedCompanyIds));
            $employees = $employees->filter(
                fn (User $u) => in_array($u->getEffectiveCompanyId(), $companyIds, true)
            )->values();
        }

        $employeeIds = $employees->pluck('id')->all();
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

        $latestEvaluationsByEmployee = Evaluation::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', Evaluation::STATUS_COMPLETED)
            ->orderByDesc('evaluated_at')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

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
            $totalScheduledHours = round((float) $agg['total_scheduled_hours'], 2);
            $totalPayrollImpactHours = round((float) $agg['total_payroll_impact_hours'], 2);
            $attendanceEfficiency = $totalScheduledHours > 0
                ? round(($totalPayrollImpactHours / $totalScheduledHours) * 100, 2)
                : 0.0;
            $evaluationAvg = $this->averageCompanyEvaluationPct($ctx, $companyId);
            $efficiency = $this->combineEfficiency($attendanceEfficiency, $evaluationAvg) ?? 0.0;

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
                'total_scheduled_hours' => $totalScheduledHours,
                'scheduled_hours' => $totalScheduledHours,
                'total_payroll_impact_hours' => $totalPayrollImpactHours,
                'payroll_impact_hours' => $totalPayrollImpactHours,
                'attendance_efficiency' => $attendanceEfficiency,
                'evaluation_avg' => $evaluationAvg,
                'efficiency' => $efficiency,
                'efficiency_percentage' => $efficiency,
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
            return $this->dailySummaryFromStoredRow($storedSummary);
        }

        // Today still uses the full attendance engine.
        if ($isToday) {
            return $this->attendanceDailySummaryService->computeForDate(
                user: $employee,
                dateKey: $dateKey,
                todayDate: $ctx['today_date'],
                nowTz: $ctx['now_tz'],
                effectiveSchedule: $ctx['schedule_cache'][$employee->id] ?? null,
                preloadedLogs: $ctx['logs_by_employee_date'][$employee->id][$dateKey] ?? null,
                correction: $ctx['approved_corrections']->get($storeKey),
            );
        }

        // ponytail: historical custom/month ranges without dense daily summaries must not call
        // computeForDate per employee-day (O(employees×days) ~60s+). Use log+schedule estimate;
        // upgrade by backfilling attendance_daily_summaries then prefer stored rows.
        return $this->estimateHistoricalDaySummary($ctx, $employee, $dateKey, $dayKey);
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

        $lateMinutes = 0;
        $lateLabel = null;
        if ($timeIn !== null) {
            $clockIn = AttendanceStatusService::getClockInStatus($daySched, $dateKey, $timeIn);
            if (($clockIn['status'] ?? '') === 'late') {
                $lateMinutes = (int) ($clockIn['late_minutes'] ?? 0);
                $lateLabel = $clockIn['late_label'] ?? null;
            }
        }

        $presenceLabel = null;
        $presenceIssue = 'none';
        if ($correction && $correction->pending_approval) {
            $presenceLabel = 'Present (Pending Correction)';
            $presenceIssue = 'correction_pending';
        } elseif ($correction && $correction->approved) {
            $presenceLabel = 'Present (Approved)';
            $presenceIssue = 'approved_correction';
        } elseif ($timeIn !== null && $timeOut === null) {
            $presenceLabel = 'Present (Incomplete Records)';
            $presenceIssue = 'incomplete_pair';
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
        $latestEvaluation = $ctx['latest_evaluations']->get($employee->id);

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
        $attendanceEfficiencyPct = $scheduledHours > 0
            ? round(($payrollImpactHours / $scheduledHours) * 100, 2)
            : null;
        $hrEvaluationPct = $latestEvaluation
            ? $this->evaluationScoringService->resolveOverallPercentage(
                $latestEvaluation->scores,
                $latestEvaluation->overall_score,
            )
            : null;
        $combinedEfficiencyPct = $this->combineEfficiency($attendanceEfficiencyPct, $hrEvaluationPct);
        $companyId = (int) ($employee->getEffectiveCompanyId() ?? 0);
        $company = $companyId > 0 ? $ctx['companies']->get($companyId) : null;
        $correction = $ctx['approved_corrections']->get($employee->id.'|'.$dateKey);
        $remarks = $this->resolveAttendanceRemarks($dailySummary, $storedSummary, $correction);

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
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'payroll_impact' => $payrollImpactHours,
            'scheduled_hours' => round($scheduledHours, 2),
            'remarks' => $remarks,
            'presence_label' => $dailySummary['presence_label'] ?? null,
            'presence_issue' => $dailySummary['presence_issue'] ?? null,
            'evaluation' => null,
            'evaluation_pct' => null,
            'evaluation_percentage' => null,
            'evaluation_rating' => null,
            'attendance_efficiency_pct' => $attendanceEfficiencyPct,
            'combined_efficiency_pct' => $combinedEfficiencyPct,
            // ponytail: Performance column is Coming Soon — wire when product defines the metric
            'efficiency_performance' => null,
            'performance' => null,
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
                'incomplete_pair' => 'Present (Incomplete Records)',
                default => '',
            };
        }
        if ($presenceLabel === '' && $correctionApproved) {
            $presenceLabel = 'Present (Approved)';
        } elseif ($presenceLabel === '' && $hasCorrection && ! $correctionApproved) {
            $presenceLabel = 'Present (Pending Correction)';
        }
        if ($presenceLabel === '' && in_array(($dailySummary['status'] ?? ''), ['incomplete'], true)) {
            $presenceLabel = 'Present (Incomplete Records)';
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

    /** @param array<string, mixed> $ctx */
    private function averageCompanyEvaluationPct(array $ctx, int $companyId): ?float
    {
        $employees = $ctx['employees_by_company']->get($companyId, collect());
        $pcts = [];
        foreach ($employees as $employee) {
            $latest = $ctx['latest_evaluations']->get($employee->id);
            if (! $latest) {
                continue;
            }
            $pct = $this->evaluationScoringService->resolveOverallPercentage(
                $latest->scores,
                $latest->overall_score,
            );
            if ($pct !== null) {
                $pcts[] = $pct;
            }
        }

        if ($pcts === []) {
            return null;
        }

        return round(array_sum($pcts) / count($pcts), 2);
    }

    /**
     * Blend attendance efficiency with HR evaluation %.
     * No evaluation → attendance-only; no attendance → evaluation-only; both → plain average.
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
        $isPresent = in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime', 'incomplete', 'clocked_in'], true);
        $isAbsent = $status === 'absent' && ! ($dailySummary['is_leave'] ?? false) && ! ($dailySummary['is_rest_day'] ?? false) && ! ($dailySummary['is_holiday'] ?? false);
        $isLate = $lateMinutes > 0 && $isPresent;
        $isUndertime = $undertimeMinutes > 0 && $isPresent;
        $isLeave = ($dailySummary['is_leave'] ?? false) || $status === 'leave';

        if ($isScheduled) {
            $agg['scheduled_employees']++;
        }
        if ($isLeave) {
            $agg['on_leave']++;
        } elseif ($isAbsent) {
            $agg['absent']++;
        } elseif ($isPresent) {
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
            $schedule = $scheduleCache[$employee->id] ?? null;
            $daySched = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            if ($daySched && ! empty($daySched['in']) && ! empty($daySched['out'])) {
                $schedHrs = $this->computeScheduleHours($daySched);
                $agg['total_scheduled_hours'] += $schedHrs;
                $agg['total_payroll_impact_hours'] += $schedHrs;
            }

            return;
        }

        if ($status === 'upcoming' || $status === '—' || $status === '-' || $status === 'rest' || ! $isScheduled) {
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
            $payHours = $payrollImpactHours;
            // Open / incomplete days: payroll engine returns 0 without time_out, which
            // zeros the whole chart for Today/Yesterday. Credit provisional impact.
            // ponytail: ceiling = full schedule until time-out; switch to live worked minutes if dashboards need mid-shift accuracy.
            if ($payHours <= 0 && $isPresent) {
                $payHours = max(0.0, $scheduledHours - ($lateMinutes / 60));
            }
            $agg['total_scheduled_hours'] += $scheduledHours;
            $agg['total_payroll_impact_hours'] += $payHours;
        }
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
                && in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime', 'incomplete', 'clocked_in'], true),
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
        if ($status === 'upcoming' || $status === '—' || $status === '-' || $status === 'rest' || ! $isScheduled) {
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
