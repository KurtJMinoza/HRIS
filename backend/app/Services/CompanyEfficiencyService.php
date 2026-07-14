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
     * @return array{company: array<string, mixed>, summary: array<string, mixed>, breakdown: array<string, mixed>, employees: list<array<string, mixed>>, employees_meta: array<string, int>}|null
     */
    public function companyDetails(
        Carbon $fromDate,
        Carbon $toDate,
        User $actor,
        int $companyId,
        int $page = 1,
        int $perPage = 25,
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

        $slots = $this->buildEmployeeSlots($ctx, $companyId);
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

        $efficiency = (float) ($companyRow['efficiency'] ?? 0);
        $attendanceEfficiency = (float) ($companyRow['attendance_efficiency'] ?? $efficiency);
        $evaluationAvg = $companyRow['evaluation_avg'] ?? null;
        $totalScheduledHours = (float) ($companyRow['total_scheduled_hours'] ?? 0);
        $totalPayrollImpactHours = (float) ($companyRow['total_payroll_impact_hours'] ?? 0);

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => $company->logo ? $this->companyLogoUrl($company->logo) : null,
                'employees' => $uniqueEmployees,
                'date' => $ctx['from_date_key'],
                'from_date' => $ctx['from_date_key'],
                'to_date' => $ctx['to_date_key'],
            ],
            'summary' => [
                'employees' => $uniqueEmployees,
                'employee_days' => $uniqueEmployees * $numDays,
                'present' => (int) ($agg['present'] ?? 0),
                'absent' => (int) ($agg['absent'] ?? 0),
                'late' => (int) ($agg['late'] ?? 0),
                'undertime' => (int) ($agg['undertime'] ?? 0),
                'efficiency' => $efficiency,
                'attendance_efficiency' => $attendanceEfficiency,
                'evaluation_avg' => $evaluationAvg,
            ],
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
            ],
            'employees' => $employeeRows,
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
            ->where('approved', true)
            ->whereIn('user_id', $employeeIds)
            ->get()
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
                'logo_url' => $bucket['logo_url'],
                'present' => (int) $agg['present'],
                'late' => (int) $agg['late'],
                'absent' => (int) $agg['absent'],
                'undertime' => (int) $agg['undertime'],
                'on_leave' => (int) $agg['on_leave'],
                'scheduled_employees' => (int) $agg['scheduled_employees'],
                'headcount' => $headcount,
                'present_pct' => $expectedDays > 0 ? round(100 * $agg['present'] / $expectedDays, 1) : 0,
                'absent_pct' => $expectedDays > 0 ? round(100 * $agg['absent'] / $expectedDays, 1) : 0,
                'late_pct' => $expectedDays > 0 ? round(100 * $agg['late'] / $expectedDays, 1) : 0,
                'on_leave_pct' => $expectedDays > 0 ? round(100 * $agg['on_leave'] / $expectedDays, 1) : 0,
                'total_scheduled_hours' => $totalScheduledHours,
                'total_payroll_impact_hours' => $totalPayrollImpactHours,
                'attendance_efficiency' => $attendanceEfficiency,
                'evaluation_avg' => $evaluationAvg,
                'efficiency' => $efficiency,
                'agg' => $agg,
            ];
        }

        usort($rows, fn (array $a, array $b) => ($b['efficiency'] <=> $a['efficiency']) ?: ($b['present'] <=> $a['present']));

        return $rows;
    }

    /** @param array<string, mixed> $ctx @return list<array{employee: User, date: Carbon, date_key: string, day_key: string}> */
    private function buildEmployeeSlots(array $ctx, int $companyId): array
    {
        $slots = [];
        $employees = $ctx['employees_by_company']->get((int) $companyId, collect());
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

        return [
            'id' => $employee->id,
            'employee_name' => $employee->display_name ?: '—',
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
            'evaluation' => $hrEvaluationPct,
            'evaluation_pct' => $hrEvaluationPct,
            'evaluation_rating' => $latestEvaluation?->overall_rating,
            'attendance_efficiency_pct' => $attendanceEfficiencyPct,
            'combined_efficiency_pct' => $combinedEfficiencyPct,
            // ponytail: Performance column is Coming Soon — wire when product defines the metric
            'efficiency_performance' => null,
            'performance' => null,
        ];
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
            $agg['total_scheduled_hours'] += $scheduledHours;
            $agg['total_payroll_impact_hours'] += $payrollImpactHours;
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
        if ($status === 'upcoming' || $status === 'rest' || ! $isScheduled) {
            return 0.0;
        }

        return $this->computeScheduleHours($daySched);
    }

    private function computeScheduleHours(array $daySchedule): float
    {
        $expectedMinutes = (int) ($daySchedule['expected_paid_minutes'] ?? 0);
        if ($expectedMinutes > 0) {
            return round($expectedMinutes / 60, 2);
        }

        $in = trim((string) ($daySchedule['in'] ?? ''));
        $out = trim((string) ($daySchedule['out'] ?? ''));
        if ($in === '' && $out === '') {
            return 0.0;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        if ($in === '') {
            return 0.0;
        }

        try {
            $inDt = Carbon::parse('2000-01-01 '.$in, $tz);
        } catch (\Exception) {
            return 0.0;
        }

        if ($out === '') {
            return 8.0;
        }

        try {
            $outDt = Carbon::parse('2000-01-01 '.$out, $tz);
        } catch (\Exception) {
            return 8.0;
        }

        $inMinutes = (int) $inDt->format('G') * 60 + (int) $inDt->format('i');
        $outMinutes = (int) $outDt->format('G') * 60 + (int) $outDt->format('i');
        if ($outMinutes <= $inMinutes) {
            $outMinutes += 1440;
        }
        $breakMinutes = max(0, (int) ($daySchedule['break_minutes'] ?? 0));
        $diffMinutes = $outMinutes - $inMinutes;

        return $diffMinutes > $breakMinutes ? round(($diffMinutes - $breakMinutes) / 60, 2) : 8.0;
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
