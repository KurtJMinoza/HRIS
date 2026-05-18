<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateDetailedReportCsvJob;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\ReportExportRun;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendancePresenceDisplayService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\LeaveCreditService;
use App\Services\PayrollComputationService;
use App\Services\PremiumReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReportsController extends Controller
{
    /** Employee self-service detailed report response cache (short TTL; narrows repeated filter churn). */
    private const EMPLOYEE_DETAILED_CACHE_TTL_SECONDS = 90;

    /** Admin detailed report cache — repeat loads (pagination / refresh) avoid full recompute briefly. */
    private const ADMIN_DETAILED_CACHE_TTL_SECONDS = 45;

    /** Default page size for detailed report list responses; overridden by per_page (25–100). */
    private const DETAILED_ROWS_PER_PAGE_DEFAULT = 50;

    /** Maximum calendar span for detailed report (admin dashboards; exports use separate flows). */
    private const DETAILED_MAX_RANGE_DAYS = 186;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollComputationService $payrollComputation,
        private readonly PremiumReportService $premiumReport,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @return array{
     *   employment_status: string|null,
     *   employment_status_label: string|null,
     *   hire_date: string|null,
     *   employment_status_effective_date: string|null,
     *   contract_start_date: string|null,
     *   contract_end_date: string|null
     * }
     */
    private function employmentFieldsForReport(User $employee, User $viewer): array
    {
        $enum = EmploymentStatus::tryFrom((string) ($employee->employment_status ?? ''));
        $useCanonical = ! $viewer->isAdmin();

        if ($useCanonical) {
            $label = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status ?? null);
        } else {
            $label = $enum?->label() ?? ($employee->employment_status
                ? ucfirst(str_replace('_', ' ', (string) $employee->employment_status))
                : null);
        }

        return [
            'employment_status' => $employee->employment_status,
            'employment_status_label' => $label,
            'hire_date' => $employee->hire_date?->toDateString(),
            'employment_status_effective_date' => $employee->employment_status_effective_date?->toDateString(),
            'contract_start_date' => $employee->contract_start_date?->toDateString(),
            'contract_end_date' => $employee->contract_end_date?->toDateString(),
        ];
    }

    /** Rule code → [work_condition, pay_rule, multiplier] for detailed report columns. */
    private const RULE_LABELS = [
        'ORD' => ['Ordinary working day (first 8 hrs)', '100% of basic hourly rate', '1.00'],
        'RD' => ['Rest day (first 8 hrs)', '130% of daily/hourly rate', '1.30'],
        'RH' => ['Regular holiday worked (first 8 hrs)', '200%', '2.00'],
        'RHRD' => ['Regular holiday + rest day (first 8 hrs)', '260%', '2.60'],
        'SH' => ['Special holiday worked (first 8 hrs)', '130%', '1.30'],
        'SHRD' => ['Special holiday + rest day (first 8 hrs)', '150%', '1.50'],
        'DH' => ['Double holiday worked (first 8 hrs)', '300%', '3.00'],
        'DHRD' => ['Double holiday + rest day (first 8 hrs)', '300%', '3.00'],
    ];

    /**
     * Compute undertime (missed working) minutes based on an approved early-out time.
     * Respects break window by excluding unpaid break from missed working time.
     */
    private function computeUndertimeMinutesFromEarlyOut(string $dateKey, array $daySchedule, string $undertimeTime, string $tz): ?int
    {
        $in = trim((string) ($daySchedule['in'] ?? ''));
        $out = trim((string) ($daySchedule['out'] ?? ''));
        if ($in === '' || $out === '') {
            return null;
        }

        $scheduledStart = Carbon::parse($dateKey.' '.$in, $tz);
        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            return null;
        }

        $earlyOut = Carbon::parse($dateKey.' '.substr($undertimeTime, 0, 5), $tz);
        if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $earlyOut->lessThan($scheduledStart)) {
            $earlyOut = $earlyOut->addDay();
        }

        if ($earlyOut->lessThanOrEqualTo($scheduledStart) || $earlyOut->greaterThanOrEqualTo($scheduledEnd)) {
            return null;
        }

        $breakStartStr = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEndStr = trim((string) ($daySchedule['break_end'] ?? ''));
        if ($breakStartStr === '' || $breakEndStr === '') {
            return (int) $earlyOut->diffInMinutes($scheduledEnd);
        }

        $breakStart = Carbon::parse($dateKey.' '.substr($breakStartStr, 0, 5), $tz);
        $breakEnd = Carbon::parse($dateKey.' '.substr($breakEndStr, 0, 5), $tz);
        if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $breakStart->lessThan($scheduledStart)) {
            $breakStart->addDay();
        }
        if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $breakEnd->lessThan($scheduledStart)) {
            $breakEnd->addDay();
        }
        if ($breakEnd->lessThanOrEqualTo($breakStart)) {
            $breakEnd->addDay();
        }

        // Early-out cannot be during break (validated on filing), but guard anyway.
        if ($earlyOut->greaterThanOrEqualTo($breakStart) && $earlyOut->lessThanOrEqualTo($breakEnd)) {
            return null;
        }

        // Missed working minutes excludes unpaid break:
        // - If early-out after break: missed = end - earlyOut
        // - If early-out before break: missed = (breakStart - earlyOut) + (end - breakEnd)
        if ($earlyOut->greaterThan($breakEnd)) {
            return (int) $earlyOut->diffInMinutes($scheduledEnd);
        }
        if ($earlyOut->lessThan($breakStart)) {
            $beforeBreak = (int) $earlyOut->diffInMinutes($breakStart);
            $afterBreak = (int) $breakEnd->diffInMinutes($scheduledEnd);

            return max(0, $beforeBreak + $afterBreak);
        }

        return null;
    }

    /** Return the timezone used for attendance (display and schedule comparison). */
    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    private function dayNameForDate(string|\DateTimeInterface $date): string
    {
        return Carbon::parse($date)->timezone($this->attendanceTimezone())->format('l');
    }

    /**
     * Build a per-day schedule array from a WorkingSchedule model.
     * Mirrors the same helper in AttendanceController so schedule resolution is consistent.
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $workingSchedule): ?array
    {
        if (! $workingSchedule) {
            return null;
        }

        $restDays = $workingSchedule->rest_days ?? [];
        $dayConfig = [];

        foreach (self::DAY_KEYS as $key) {
            if (in_array($key, $restDays, true)) {
                $dayConfig[$key] = null;

                continue;
            }

            $dayConfig[$key] = [
                'in' => $workingSchedule->time_in,
                'out' => $workingSchedule->time_out,
                'break_start' => $workingSchedule->break_start,
                'break_end' => $workingSchedule->break_end,
                'grace_period_minutes' => $workingSchedule->grace_period_minutes,
                'early_timein_minutes' => $workingSchedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $workingSchedule->late_allowance_minutes,
                'early_timeout_minutes' => $workingSchedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $workingSchedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $dayConfig;
    }

    /**
     * Resolve the effective per-day schedule for a user.
     * Prefers the JSON `schedule` column; falls back to the `working_schedule_id` relationship
     * so that employees assigned via Admin → Schedule module are treated identically to
     * those with a manually-set JSON schedule.
     */
    private function resolveEffectiveSchedule(User $user): ?array
    {
        $schedule = $user->schedule;
        if (is_array($schedule) && $schedule !== []) {
            return $schedule;
        }

        if ($user->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                return $derived;
            }
        }

        return null;
    }

    /** Format a Carbon (UTC or any) as time string in attendance timezone to avoid double conversion. */
    private function formatTimeInAttendanceTz($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $carbon = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);

        return $carbon->timezone($this->attendanceTimezone())->format('H:i');
    }

    private function formatTimeForDisplay($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $carbon = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);

        return $carbon->timezone($this->attendanceTimezone())->format('g:i A');
    }

    /**
     * Reuses the same extraction logic as AttendanceMonitoringController.
     *
     * @param  \Illuminate\Support\Collection<int, AttendanceLog>|null  $logs
     * @return array{0: ?\Carbon\CarbonInterface, 1: ?\Carbon\CarbonInterface, 2: ?int}
     */
    private function extractTimesAndWorkedMinutes(?Collection $logs): array
    {
        if ($logs === null || $logs->isEmpty()) {
            return [null, null, null];
        }

        $timeIn = null;
        $timeOut = null;
        $total = 0;
        $clockIn = null;

        foreach ($logs as $log) {
            // IMPORTANT: reports must use the punch timestamp (`verified_at`),
            // not insertion time (`created_at`), to stay consistent with Admin Attendance
            // and payroll computation.
            $stamp = $log->verified_at ?? $log->created_at;
            if (! $stamp) {
                continue;
            }

            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                if (! $timeIn) {
                    $timeIn = $stamp;
                }
                $clockIn = $stamp;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $timeOut = $stamp;
                if ($clockIn) {
                    $total += $clockIn->diffInMinutes($stamp);
                    $clockIn = null;
                }
            }
        }

        // If we have at least one complete clock-in/clock-out pair, keep the
        // accumulated minutes even if there is a trailing unmatched clock-in.
        // Only return null when there are no completed pairs but an open
        // clock-in exists (so we don't fabricate hours).
        if ($total > 0) {
            $workedMinutes = $total;
        } elseif ($clockIn !== null) {
            $workedMinutes = null;
        } else {
            $workedMinutes = 0;
        }

        return [$timeIn, $timeOut, $workedMinutes];
    }

    /**
     * Detailed per-day attendance rows for a given date range.
     *
     * Each row includes:
     * - Employee, department, date, schedule
     * - Time in/out, total hours
     * - Late/undertime/overtime minutes
     * - Attendance status (present/late/halfday/absent/leave/undertime)
     * - Leave type/status (if any)
     * - Overtime status (if any overtime record exists)
     */
    public function detailed(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $routeName = $request->route()?->getName();
        $isEmployeeSelfRoute = $routeName === 'employee.reports.detailed';
        if ($isEmployeeSelfRoute) {
            if ($this->hrRoleResolver->resolve($request->user()) !== HrRole::Employee) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $request->merge(['employee_id' => $request->user()->id]);
        }

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,incomplete,all'],
            'leave_type' => ['nullable', 'string', 'max:50'],
            'overtime_status' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:25', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
            'include_deactivated' => ['nullable', 'boolean'],
        ]);

        // Normalize filters: trim department, lowercase status for consistent matching.
        $validated['department'] = isset($validated['department']) && $validated['department'] !== ''
            ? trim($validated['department'])
            : null;
        $validated['status'] = isset($validated['status']) && $validated['status'] !== ''
            ? strtolower(trim($validated['status']))
            : null;

        // Explicitly read department, company_id, and employee_id from query string (GET requests).
        $queryDepartment = $request->query('department');
        if ($queryDepartment !== null && $queryDepartment !== '') {
            $validated['department'] = trim((string) $queryDepartment);
        }
        $queryCompanyId = $request->query('company_id');
        if ($queryCompanyId !== null && $queryCompanyId !== '') {
            $validated['company_id'] = (int) $queryCompanyId;
        }
        $queryBranchId = $request->query('branch_id');
        if ($queryBranchId !== null && $queryBranchId !== '') {
            $validated['branch_id'] = (int) $queryBranchId;
        }

        // Employee self-service: never apply org-wide filters (ignore forged query params).
        if ($isEmployeeSelfRoute) {
            $validated['department'] = null;
            $validated['company_id'] = null;
            $validated['branch_id'] = null;
        }

        $attendanceTzForValidation = $this->attendanceTimezone();

        // Parse dates as calendar dates in attendance timezone so the UTC log range is correct.
        $from = $this->parseDateInAttendanceTz($validated['from_date'], false);
        $to = isset($validated['to_date'])
            ? $this->parseDateInAttendanceTz($validated['to_date'], true)
            : $this->parseDateInAttendanceTz($validated['from_date'], true);

        if ($to->lessThan($from)) {
            $fromSwap = $to->copy()->startOfDay();
            $toSwap = $from->copy()->endOfDay();
            $from = $fromSwap;
            $to = $toSwap;
        }
        $rangeDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay());
        if ($rangeDays > self::DETAILED_MAX_RANGE_DAYS) {
            return response()->json([
                'message' => 'Date range cannot exceed '.self::DETAILED_MAX_RANGE_DAYS.' days for detailed report.',
            ], 422);
        }

        $attendanceTz = $attendanceTzForValidation;

        $perPage = (int) ($validated['per_page'] ?? self::DETAILED_ROWS_PER_PAGE_DEFAULT);
        $perPage = min(100, max(25, $perPage));
        $pagePref = max(1, (int) ($validated['page'] ?? 1));

        $filtersParseMs = (int) round((microtime(true) - $startedAt) * 1000);

        $employeeDetailedCacheKey = null;
        $adminDetailedCacheKey = null;
        if ($isEmployeeSelfRoute) {
            $employeeDetailedCacheKey = sprintf(
                'reports:employee:detailed:v3:%d:%s:%s:%d:%d:%s:%s:%s:%s:%s',
                (int) $request->user()->id,
                $from->toDateString(),
                $to->toDateString(),
                $perPage,
                $pagePref,
                $validated['status'] ?? '',
                $validated['leave_type'] ?? '',
                $validated['overtime_status'] ?? '',
                strtolower(trim((string) ($validated['search'] ?? ''))),
                hash('xxh128', implode('|', [
                    (string) ($validated['from_date'] ?? ''),
                    (string) ($validated['to_date'] ?? ''),
                ]), false)
            );
            $cachedDetailed = Cache::get($employeeDetailedCacheKey);
            if (is_array($cachedDetailed)) {
                $cacheHitMs = (int) round((microtime(true) - $startedAt) * 1000);
                Log::info('Employee detailed attendance report cache hit', [
                    'actor_user_id' => (int) $request->user()->id,
                    'cache_key_suffix' => substr((string) $employeeDetailedCacheKey, -32),
                    'rows_returned' => count($cachedDetailed['rows'] ?? []),
                    'total_response_ms' => $cacheHitMs,
                ]);
                if ($cacheHitMs > 500) {
                    Log::warning('Employee detailed attendance report cache hit slow', [
                        'duration_ms' => $cacheHitMs,
                        'rows_returned' => count($cachedDetailed['rows'] ?? []),
                    ]);
                }

                return response()->json($cachedDetailed);
            }
        } else {
            $adminCacheIdentity = [
                'uid' => (int) $request->user()->id,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'company_id' => $validated['company_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'department' => $validated['department'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'status' => isset($validated['status']) ? strtolower(trim((string) $validated['status'])) : null,
                'leave_type' => $validated['leave_type'] ?? null,
                'overtime_status' => $validated['overtime_status'] ?? null,
                'search' => strtolower(trim((string) ($validated['search'] ?? ''))),
                'include_deactivated' => (bool) ($validated['include_deactivated'] ?? false),
                'page' => $pagePref,
                'per_page' => $perPage,
            ];
            $adminDetailedCacheKey = 'reports:admin:detailed:v3:'.hash('xxh128', serialize($adminCacheIdentity), false);
            $cachedAdminDetailed = Cache::get($adminDetailedCacheKey);
            if (is_array($cachedAdminDetailed) && isset($cachedAdminDetailed['rows'], $cachedAdminDetailed['meta'])) {
                $cacheHitMs = (int) round((microtime(true) - $startedAt) * 1000);
                Log::info('Admin detailed report cache hit', [
                    'actor_user_id' => (int) $request->user()->id,
                    'filters_parse_ms' => $filtersParseMs,
                    'total_response_ms' => $cacheHitMs,
                    'rows_returned' => count($cachedAdminDetailed['rows'] ?? []),
                ]);

                return response()->json($cachedAdminDetailed);
            }
        }

        // Apply both department AND employee filters together (AND condition).
        // When both are set: only the selected employee in the selected department is included.
        $requestedEmployeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        $employeeWithRelations = [
            'workingSchedule',
            'companyHeadships:id,name,company_head_id',
            'company:id,name',
            'branch:id,company_id',
            'branch.company:id,name',
            'departmentRelation:id,name,branch_id',
            'departmentRelation.branch:id,company_id',
            'departmentRelation.branch.company:id,name',
        ];

        $employeesLoadStartedAt = microtime(true);

        if ($isEmployeeSelfRoute) {
            /** @var \Illuminate\Support\Collection<int, User> $employees */
            $employees = User::query()
                ->whereKey($request->user()->id)
                ->activeRoster()
                ->with($employeeWithRelations)
                ->get();
        } else {
            $employeesQuery = User::query()
                ->roster();

            if (! (bool) ($validated['include_deactivated'] ?? false)) {
                $employeesQuery->active();
            }

            if ($validated['department'] !== null && $validated['department'] !== '') {
                $deptName = $validated['department'];
                $employeesQuery->where(function ($q) use ($deptName) {
                    $q->where('department', $deptName)
                        ->orWhereHas('departmentRelation', fn ($d) => $d->where('name', $deptName));
                });
            }

            if (! empty($validated['company_id'])) {
                $cid = (int) $validated['company_id'];
                $employeesQuery->where(function ($q) use ($cid) {
                    $q->where('company_id', $cid)
                        ->orWhereHas('branch', fn ($b) => $b->where('company_id', $cid))
                        ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $cid)))
                        ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $cid));
                });
            }

            if (! empty($validated['branch_id'])) {
                $employeesQuery->where('branch_id', (int) $validated['branch_id']);
            }

            if ($requestedEmployeeId > 0) {
                $employeesQuery->where('id', $requestedEmployeeId);
            }

            $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeesQuery);

            /** @var \Illuminate\Support\Collection<int, User> $employees */
            $employees = $employeesQuery
                ->orderBy('name')
                ->with($employeeWithRelations)
                ->get();
        }

        $employeesLoadMs = (int) round((microtime(true) - $employeesLoadStartedAt) * 1000);

        $viewer = $request->user();

        // Pre-compute company names and IDs for all employees.
        $detailedEmployeeCompanyNames = [];
        $detailedEmployeeCompanyIds = [];
        /** @var array<int, string> $employeeSearchHaystack lowercased name / dept / company for quick search */
        $employeeSearchHaystack = [];
        foreach ($employees as $emp) {
            $co = $emp->companyHeadships->first() ?? $emp->company ?? $emp->branch?->company ?? $emp->departmentRelation?->branch?->company;
            $detailedEmployeeCompanyNames[$emp->id] = $co?->name ?? null;
            $detailedEmployeeCompanyIds[$emp->id] = $co?->id ?? null;
            $employeeSearchHaystack[$emp->id] = strtolower(implode(' ', array_filter([
                $emp->name,
                $emp->departmentRelation?->name ?? $emp->department,
                $detailedEmployeeCompanyNames[$emp->id] ?? null,
            ], fn ($v) => $v !== null && $v !== '')));
        }

        if ($employees->isEmpty()) {
            return response()->json([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'rows' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ]);
        }

        // "All statuses": treat as no filter so all records (present, late, halfday, absent, leave, undertime) are included.
        $statusFilter = $validated['status'] ?? null;
        if ($statusFilter === 'all' || $statusFilter === '' || $statusFilter === null) {
            $statusFilter = null;
        }
        $leaveTypeFilter = isset($validated['leave_type']) && $validated['leave_type'] !== '' ? trim($validated['leave_type']) : null;
        $overtimeStatusFilter = isset($validated['overtime_status']) && $validated['overtime_status'] !== '' ? trim($validated['overtime_status']) : null;

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        $userIds = $employees->pluck('id')->all();

        // verified_at is stored in UTC; convert the attendance-TZ range to UTC
        // so that e.g. "Feb 26" in Manila includes logs from 2026-02-25 16:00 UTC to 2026-02-26 15:59 UTC.
        $fromUtc = $from->copy()->setTimezone('UTC');
        $toUtc = $to->copy()->setTimezone('UTC');

        /** @var \Illuminate\Support\Collection<int, AttendanceLog> $logs */
        $logsQuery = AttendanceLog::query()
            ->select(['id', 'user_id', 'type', 'verified_at', 'created_at'])
            ->whereIn('user_id', $userIds)
            // Use an inclusive whereBetween on verified_at (UTC in DB) so
            // that logs are strictly limited to the requested date window.
            ->whereBetween('verified_at', [$fromUtc, $toUtc]);

        if (! empty($validated['employee_id'])) {
            $logsQuery->where('user_id', $validated['employee_id']);
        }

        $queryStartedAt = microtime(true);

        $logs = $logsQuery
            ->orderBy('verified_at')
            ->get();

        /** @var array<int, array<string, \Illuminate\Support\Collection>> $logsByUserDate */
        $logsByUserDate = [];
        foreach ($logs as $log) {
            $userId = $log->user_id;
            $stamp = $log->verified_at ?? $log->created_at;
            $dateKey = $stamp->copy()->timezone($attendanceTz)->toDateString();
            $logsByUserDate[$userId][$dateKey] = ($logsByUserDate[$userId][$dateKey] ?? collect())->push($log);
        }

        $queryExecuteMs = (int) round((microtime(true) - $queryStartedAt) * 1000);
        if ($queryExecuteMs > 500) {
            Log::warning('Reports detailed: slow preload query', ['query_execute_ms' => $queryExecuteMs, 'user_ids' => count($userIds)]);
        }

        // Preload corrections, leaves, overtime in bounded round-trips (no per-row queries).
        $eagerRelStartedAt = microtime(true);
        $correctionsByKey = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->get([
                'id', 'user_id', 'date', 'time_in', 'time_out', 'approved', 'approved_at',
                'pending_approval', 'rejected_at', 'issue_kind', 'reason_code', 'approval_stage',
            ])
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        // Leaves by user/date with type + status + duration (all statuses).
        $leavesQuery = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString());

        if (! empty($validated['employee_id'])) {
            $leavesQuery->where('user_id', $validated['employee_id']);
        }

        $leaves = $leavesQuery->get([
            'id', 'user_id', 'type', 'status', 'start_date', 'end_date', 'undertime_time', 'half_type',
        ]);

        /** @var array<int, array<string, array{type: string, status: string, start_date: string, end_date: string, duration_days: int, undertime_time?: string|null, half_type?: string|null}>> $leaveByUserDate */
        $leaveByUserDate = [];
        foreach ($leaves as $leave) {
            // Clamp leave span to the requested reporting window so that
            // per-day mapping respects the selected filters, but compute
            // the duration from the original start/end dates (handling
            // potentially reversed start/end gracefully).
            $leaveStartInRange = $leave->start_date->copy()->max($from);
            $leaveEndInRange = $leave->end_date->copy()->min($to);

            if ($leaveEndInRange->lessThan($leaveStartInRange)) {
                continue;
            }

            $originalStart = $leave->start_date->copy();
            $originalEnd = $leave->end_date->copy();
            if ($originalEnd->lessThan($originalStart)) {
                [$originalStart, $originalEnd] = [$originalEnd->copy(), $originalStart->copy()];
            }
            // Duration based on full leave span (inclusive): end - start + 1.
            // Guard with max(1, ...) so we never surface zero/negative durations
            // even if dates are malformed or equal.
            $durationDays = max(1, $originalEnd->diffInDays($originalStart) + 1);

            $cursorLeave = $leaveStartInRange->copy();
            while ($cursorLeave->lessThanOrEqualTo($leaveEndInRange)) {
                $leaveByUserDate[$leave->user_id][$cursorLeave->toDateString()] = [
                    'type' => $leave->type,
                    'status' => $leave->status,
                    'start_date' => $leave->start_date->toDateString(),
                    'end_date' => $leave->end_date->toDateString(),
                    'duration_days' => $durationDays,
                    'undertime_time' => $leave->undertime_time ? substr((string) $leave->undertime_time, 0, 5) : null,
                    'half_type' => $leave->half_type,
                ];
                $cursorLeave->addDay();
            }
        }

        // Overtime records by user/date.
        $overtimesQuery = Overtime::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        if (! empty($validated['employee_id'])) {
            $overtimesQuery->where('user_id', $validated['employee_id']);
        }

        $overtimes = $overtimesQuery->get([
            'id', 'user_id', 'date', 'status', 'computed_hours',
        ]);

        /** @var array<int, array<string, Overtime>> $overtimeByUserDate */
        $overtimeByUserDate = [];
        foreach ($overtimes as $ot) {
            $overtimeByUserDate[$ot->user_id][$ot->date->toDateString()] = $ot;
        }

        $eagerLoadMs = (int) round((microtime(true) - $eagerRelStartedAt) * 1000);
        if ($eagerLoadMs > 500) {
            Log::warning('Reports detailed: slow related preload (correct + leave + OT)', ['related_maps_ms' => $eagerLoadMs, 'user_ids' => count($userIds)]);
        }

        $searchNorm = strtolower(trim((string) ($validated['search'] ?? '')));
        $nowTz = Carbon::now($attendanceTz);
        $todayDateRow = $nowTz->toDateString();

        $aggregateStartedAt = microtime(true);
        $totalMatches = 0;
        $pagedRows = [];
        $page = $pagePref;
        $offset = ($page - 1) * $perPage;
        /** @var array<string, int> */
        $payrollImpactMemo = [];
        /** @var array<int, array{effective_schedule: ?array, company_id: ?int, hourly_rate: float}> */
        $premiumCtxCache = [];

        /** @var User $employee */
        foreach ($employees as $employee) {
            $effectiveSchedule = $this->resolveEffectiveSchedule($employee);

            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
                $dateKey = $cursor->toDateString();

                $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;

                $leaveInfo = $leaveByUserDate[$employee->id][$dateKey] ?? null;
                /** @var Collection<int, AttendanceLog>|null $dayLogs */
                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? null;

                if (! $todaySchedule && ! $leaveInfo && ($dayLogs === null || $dayLogs->isEmpty())) {
                    $cursor->addDay();

                    continue;
                }

                if ($searchNorm !== '') {
                    $haystack = $employeeSearchHaystack[$employee->id].' '.$dateKey;
                    if (! str_contains($haystack, $searchNorm)) {
                        $cursor->addDay();

                        continue;
                    }
                }

                if ($leaveTypeFilter !== null && $leaveTypeFilter !== '' && $leaveTypeFilter !== 'all') {
                    if (! $leaveInfo || ($leaveInfo['type'] ?? null) !== $leaveTypeFilter) {
                        $cursor->addDay();

                        continue;
                    }
                }

                $ot = $overtimeByUserDate[$employee->id][$dateKey] ?? null;
                if ($overtimeStatusFilter !== null && $overtimeStatusFilter !== '' && $overtimeStatusFilter !== 'all') {
                    if (! $ot || $ot->status !== $overtimeStatusFilter) {
                        $cursor->addDay();

                        continue;
                    }
                }

                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                // Overlay approved correction: overrides log times and recalculates worked minutes
                // deducting the schedule's break period (e.g. 08:00–17:00 = 8 h net, not 9 h raw).
                $correctionKey = $employee->id.'|'.$dateKey;
                $correctionD = $this->pickApprovedDetailedCorrection($correctionsByKey, $correctionKey);
                if ($correctionD) {
                    if ($correctionD->time_in) {
                        $timeIn = $correctionD->time_in;
                    }
                    if ($correctionD->time_out) {
                        $timeOut = $correctionD->time_out;
                    }
                    if ($correctionD->time_in && $correctionD->time_out) {
                        $workedMinutes = $todaySchedule
                            ? AttendanceStatusService::getNetWorkedMinutes(
                                $correctionD->time_in,
                                $correctionD->time_out,
                                $todaySchedule,
                                $dateKey,
                                $attendanceTz
                            )
                            : (int) $correctionD->time_in->diffInMinutes($correctionD->time_out);
                    }
                }

                $approvedOtForDetailedRow = $ot;
                $virtualClockOutFromOt = false;
                if ($timeOut === null && $approvedOtForDetailedRow && $approvedOtForDetailedRow->status === Overtime::STATUS_APPROVED) {
                    $resolvedOut = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                        $approvedOtForDetailedRow,
                        $dateKey,
                        is_array($todaySchedule) ? $todaySchedule : null,
                        $attendanceTz
                    );
                    if ($resolvedOut !== null) {
                        $timeOut = $resolvedOut;
                        $virtualClockOutFromOt = true;
                    }
                }

                // For regular clock-in/out logs (no correction covering both times), deduct the
                // schedule's unpaid break window so worked minutes are consistent across all views.
                if (! ($correctionD && $correctionD->time_in && $correctionD->time_out)) {
                    if ($todaySchedule && $timeIn && $timeOut) {
                        $tIn = $timeIn instanceof Carbon ? $timeIn : Carbon::parse($timeIn);
                        $tOut = $timeOut instanceof Carbon ? $timeOut : Carbon::parse($timeOut);
                        $workedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                            $tIn, $tOut, $todaySchedule, $dateKey, $attendanceTz
                        );
                    }
                }

                // Rest day / not scheduled: never surface punches/corrections/OT (e.g., Sundays) in detailed rows.
                if (! (is_array($todaySchedule) && ! empty($todaySchedule['in']))) {
                    $timeIn = null;
                    $timeOut = null;
                    $workedMinutes = null;
                    $virtualClockOutFromOt = false;
                    $approvedOtForDetailedRow = null;
                    $ot = null;
                }

                $effectiveTimeIn = $timeIn;
                $effectiveTimeOut = $timeOut;
                $effectiveWorkedMinutes = $workedMinutes;

                $isHalfday = false;

                $status = '—';
                $lateMinutes = null;
                $lateLabel = null;
                $undertimeMinutes = null;
                $overtimeMinutes = null;

                $isOnLeaveApproved = $leaveInfo && $leaveInfo['status'] === LeaveRequest::STATUS_APPROVED;
                $leaveTypeApproved = $leaveInfo['type'] ?? null;

                if ($isOnLeaveApproved) {
                    if ($leaveTypeApproved === 'half_day') {
                        // Half-day leave uses strict attendance: status is Half Day,
                        // but total hours come only from actual clock-in/out logs.
                        $status = 'halfday';
                        $isHalfday = true;
                    } elseif ($leaveTypeApproved === 'undertime') {
                        $status = 'undertime';
                        if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                            $requiredMins = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);
                            $utTime = $leaveInfo['undertime_time'] ?? null;
                            $computedUt = ($requiredMins > 0 && $utTime)
                                ? $this->computeUndertimeMinutesFromEarlyOut($dateKey, $todaySchedule, (string) $utTime, $attendanceTz)
                                : null;
                            if ($requiredMins > 0 && $computedUt !== null) {
                                $undertimeMinutes = $computedUt;
                                $effectiveWorkedMinutes = max(0, $requiredMins - $computedUt);
                            } elseif ($requiredMins > 0) {
                                // Fallback: do not assume 0.5 day; keep required minutes as baseline if time is missing.
                                $effectiveWorkedMinutes = $requiredMins;
                            }
                        }
                    } else {
                        $status = 'leave';
                    }
                } elseif ($todaySchedule && ! empty($todaySchedule['in'])) {
                    if (! $effectiveTimeIn) {
                        if ($effectiveTimeOut) {
                            $status = 'present';
                        } else {
                            // For detailed rows, future dates should never be marked
                            // as absences. Only dates strictly before today, or
                            // today after the cutoff, are considered absent.
                            $isFuture = $dateKey > $todayDateRow;
                            if (! $isFuture) {
                                $isToday = $dateKey === $todayDateRow;
                                $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, $nowTz);
                                if ($pastCutoff) {
                                    $status = 'absent';
                                }
                            }
                        }
                    } else {
                        $scheduledStart = Carbon::parse($dateKey.' '.$todaySchedule['in'], $attendanceTz);
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $attendanceTz);

                        $timeInCarbon = $effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn
                            : Carbon::parse($effectiveTimeIn);
                        $clockInResult = AttendanceStatusService::getClockInStatus(
                            $todaySchedule,
                            $dateKey,
                            $timeInCarbon
                        );

                        $isHalfday = $clockInResult['status'] === 'half_day';
                        $isLate = $clockInResult['status'] === 'late';
                        if ($isHalfday) {
                            $lateLabel = $clockInResult['late_label'] ?? 'Half Day';
                            $lateMinutes = (int) ($clockInResult['late_minutes'] ?? 0);
                        } elseif ($isLate) {
                            $lateMinutes = $clockInResult['late_minutes'] ?? null;
                            $lateLabel = $clockInResult['late_label'] ?? null;
                        }

                        /** Required net shift minutes after breaks — used for undertime-from-shortfall parity with Attendance. */
                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);

                        if ($scheduledEnd && $effectiveTimeOut) {
                            $outCarbon = $effectiveTimeOut instanceof Carbon
                                ? $effectiveTimeOut
                                : Carbon::parse($effectiveTimeOut);
                            $inCarbon = $effectiveTimeIn instanceof Carbon
                                ? $effectiveTimeIn
                                : Carbon::parse($effectiveTimeIn);
                            $earlyTimeout = isset($todaySchedule['early_timeout_minutes']) ? (int) $todaySchedule['early_timeout_minutes'] : null;

                            $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                                $dateKey,
                                $todaySchedule,
                                $inCarbon,
                                $outCarbon,
                                $attendanceTz,
                                $earlyTimeout
                            );

                            $overtimeBuffer = isset($todaySchedule['overtime_buffer_minutes']) ? (int) $todaySchedule['overtime_buffer_minutes'] : (int) config('attendance.overtime_buffer_minutes', 15);

                            $postShiftOt = 0;
                            $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                            if ($outCarbon->greaterThan($otStart)) {
                                $postShiftOt = (int) $otStart->diffInMinutes($outCarbon);
                            }

                            $preShiftOt = 0;
                            $scheduledStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $todaySchedule, $attendanceTz);
                            if ($scheduledStart && $inCarbon->lessThan($scheduledStart)) {
                                $preShiftOt = (int) $inCarbon->diffInMinutes($scheduledStart);
                            }

                            $overtimeMinutes = $preShiftOt + $postShiftOt;
                        } elseif ($scheduledEnd && $effectiveTimeIn && ! $effectiveTimeOut && ! $isHalfday && $requiredMinutes > 0) {
                            // Missing time-out past shift end: {@see AttendancePresenceDisplayService incomplete_pair}.
                            // Undertime displayed as required net minutes − actual rendered toward that requirement (typically 0 when no pairing).
                            $pastShiftEndIncomplete = $dateKey < $todayDateRow || ($dateKey === $todayDateRow && $nowTz->greaterThan($scheduledEnd));
                            if ($pastShiftEndIncomplete) {
                                $basisRendered = (
                                    $effectiveWorkedMinutes !== null && $effectiveWorkedMinutes > 0
                                )
                                    ? (int) $effectiveWorkedMinutes : 0;
                                $undertimeMinutes = max(0, $requiredMinutes - $basisRendered);
                            }
                        }

                        // Do not classify missing time-out rows as literal "undertime" — parity with Employee Attendance
                        // ({@see AttendanceController::summary} incomplete + presence_label), but undertime MINUTES remain populated.
                        $isUndertime = $effectiveTimeOut
                            && $undertimeMinutes !== null
                            && (int) $undertimeMinutes > 0
                            && ! $isHalfday;

                        // Status precedence for detailed attendance:
                        // - Leave-based statuses are handled above.
                        // - Half Day overrides Present.
                        // - Undertime overrides Late/Present when undertime minutes exist.
                        // - Late overrides Present when clock-in is beyond grace.
                        if ($isHalfday) {
                            $status = 'halfday';
                        } elseif ($isUndertime) {
                            $status = 'undertime';
                        } elseif ($isLate) {
                            $status = 'late';
                        } else {
                            $status = 'present';
                        }

                        // If there is a clock-in but no clock-out after the shift window has ended,
                        // treat the detailed row as Incomplete so it never appears as a clean "present".
                        if ($effectiveTimeIn && ! $effectiveTimeOut && $scheduledEnd) {
                            $pastShiftEnd = $dateKey < $todayDateRow || ($dateKey === $todayDateRow && $nowTz->greaterThan($scheduledEnd));
                            if ($pastShiftEnd) {
                                $status = 'incomplete';
                            }
                        }

                        // Still clocked in: status is not final — show Clocked In (late minutes may still display as reference).
                        if ($effectiveTimeIn && ! $effectiveTimeOut && $status !== 'incomplete' && $status !== 'halfday' && $status !== 'leave') {
                            $status = 'clocked_in';
                        }
                    }
                } elseif ($effectiveTimeIn || $effectiveTimeOut) {
                    $status = 'present';
                }

                $correctionMeta = $this->pickCorrectionMetaRow($correctionsByKey, $correctionKey);
                $isFutureRow = $dateKey > $todayDateRow;
                $qualifiedRow = $this->presenceDisplay->qualify(
                    $dateKey,
                    $todayDateRow,
                    $nowTz,
                    is_array($todaySchedule) ? $todaySchedule : null,
                    $status,
                    $effectiveTimeIn,
                    $effectiveTimeOut,
                    $correctionMeta,
                    $isFutureRow,
                );
                $status = $qualifiedRow['status'];
                $presenceLabel = $qualifiedRow['presence_label'];
                $presenceIssue = $qualifiedRow['presence_issue'];

                if (! $todaySchedule && ! $leaveInfo && ! $effectiveTimeIn && ! $effectiveTimeOut) {
                    // No schedule, no leave, no logs — skip blank days
                    $cursor->addDay();

                    continue;
                }

                $scheduleLabel = $todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])
                    ? sprintf('%s – %s', $todaySchedule['in'], $todaySchedule['out'])
                    : '—';

                // Apply filters: status (must run after presence qualification). Search / leave type / OT already gated earlier.
                if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
                    $sf = strtolower((string) $statusFilter);
                    if ($sf === 'incomplete') {
                        if (! in_array($presenceIssue, ['incomplete_pair', 'correction_pending'], true)) {
                            $cursor->addDay();

                            continue;
                        }
                    } elseif ($sf !== strtolower((string) $status)) {
                        $cursor->addDay();

                        continue;
                    }
                }

                // Leave duration: half_day = 0.5 day; undertime is time-based so duration is not expressed in days.
                $leaveDurationDays = null;
                if ($leaveInfo && ($leaveInfo['status'] ?? null) === LeaveRequest::STATUS_APPROVED) {
                    $lt = $leaveInfo['type'] ?? null;
                    if ($lt === 'half_day') {
                        $leaveDurationDays = 0.5;
                    } elseif (! empty($leaveInfo['end_date'])) {
                        $rowDate = Carbon::parse($dateKey)->startOfDay();
                        $leaveEnd = Carbon::parse($leaveInfo['end_date'])->startOfDay();
                        $leaveDurationDays = max(1, $rowDate->diffInDays($leaveEnd) + 1);
                    }
                }

                $earlyOutTime = null;
                if ($leaveTypeApproved === 'undertime' && $leaveInfo) {
                    $utTime = $leaveInfo['undertime_time'] ?? null;
                    if ($utTime) {
                        $earlyOutTime = substr((string) $utTime, 0, 5);
                    }
                    /** Covered by approved undertaking leave filing — payroll impact travels with leave elsewhere. */
                } elseif ($effectiveTimeOut && ($undertimeMinutes ?? 0) > 0 && ! $isHalfday) {
                    // Clock-out-derived undertime: show employee's actual punch as "early out" anchor (status may become present after qualification).
                    $earlyOutTime = $this->formatTimeInAttendanceTz($effectiveTimeOut);
                }

                $isAutoGenerated = $effectiveTimeOut
                    && ($undertimeMinutes ?? 0) > 0
                    && ! $isHalfday
                    && ! ($leaveTypeApproved === 'undertime' && $isOnLeaveApproved);

                /** Undertime filing column: distinguishes approved leave-vs-schedule filings from log-only shortfalls/missing punches. */
                $undertimeFilingStatus = null;
                if (! $isHalfday && ($undertimeMinutes ?? 0) > 0) {
                    if ($leaveTypeApproved === 'undertime' && $isOnLeaveApproved) {
                        $undertimeFilingStatus = 'approved';
                    } else {
                        $undertimeFilingStatus = 'unfiled';
                    }
                }

                $scheduledDurationHours = null;
                if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                    $reqDur = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);
                    if ($reqDur > 0) {
                        $scheduledDurationHours = round($reqDur / 60, 2);
                    }
                }

                $otMinutesForRow = $effectiveTimeOut !== null ? ($overtimeMinutes ?? 0) : null;
                $clockOtHours = $otMinutesForRow !== null ? round($otMinutesForRow / 60, 2) : null;
                $approvedFromFiling = ($ot && $ot->status === Overtime::STATUS_APPROVED)
                    ? round((float) ($ot->computed_hours ?? 0), 2)
                    : 0.0;

                // Payroll Impact (hrs): {@see PayrollComputationService::payrollImpactMinutesForAttendanceDisplay}
                // — payslip-parity (paid regular + paid OT). Premium + payroll engine run only for the current page slice.
                $totalMatches++;
                $needsHeavy = ($totalMatches > $offset) && (count($pagedRows) < $perPage);

                $payrollImpactMinutes = 0;
                $payrollImpactHours = 0.0;
                $premiumDay = null;
                if ($needsHeavy) {
                    if ($todaySchedule && ! empty($todaySchedule['in'])) {
                        $tInPayroll = $effectiveTimeIn
                            ? ($effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn))
                            : null;
                        $tOutPayroll = $effectiveTimeOut
                            ? ($effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut))
                            : null;
                        $memoKey = $employee->id.'|'.$dateKey.'|'.($tInPayroll?->getTimestamp() ?? 'x').'|'.($tOutPayroll?->getTimestamp() ?? 'x');
                        if (! isset($payrollImpactMemo[$memoKey])) {
                            $payrollImpactMemo[$memoKey] = $this->payrollComputation->payrollImpactMinutesForAttendanceDisplay(
                                $employee,
                                $dateKey,
                                $tInPayroll,
                                $tOutPayroll,
                                $attendanceTz
                            );
                        }
                        $payrollImpactMinutes = $payrollImpactMemo[$memoKey];
                        $payrollImpactHours = round($payrollImpactMinutes / 60, 2);
                    }

                    $approvedOtForPremium = ($approvedOtForDetailedRow && $approvedOtForDetailedRow->status === Overtime::STATUS_APPROVED)
                        ? $approvedOtForDetailedRow
                        : null;
                    if (! isset($premiumCtxCache[$employee->id])) {
                        $premiumCtxCache[$employee->id] = $this->premiumReport->premiumContextForEmployee($employee);
                    }
                    $premiumDay = $this->premiumReport->computeDayPremiumFromResolvedTimes(
                        $premiumCtxCache[$employee->id],
                        $dateKey,
                        $effectiveTimeIn,
                        $effectiveTimeOut,
                        $approvedOtForPremium,
                        $attendanceTz
                    );
                }
                $clockVal = $clockOtHours ?? 0.0;
                $approvedOtRequestedHours = $approvedFromFiling;
                $approvedOtHours = $clockVal > 0.0001
                    ? min($approvedOtRequestedHours, $clockVal)
                    : ($virtualClockOutFromOt ? $approvedOtRequestedHours : 0.0);
                $unapprovedOtHours = max(0.0, round($clockVal - min($approvedOtRequestedHours, $clockVal), 2));
                if ($clockVal <= 0.0001 && $approvedOtRequestedHours > 0) {
                    $unapprovedOtHours = 0.0;
                }

                if ($needsHeavy) {
                    $pagedRows[] = array_merge([
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->name,
                        'department' => $employee->departmentRelation?->name ?? $employee->department,
                        'company_id' => $detailedEmployeeCompanyIds[$employee->id] ?? null,
                        'company_name' => $detailedEmployeeCompanyNames[$employee->id] ?? null,
                        'profile_image' => $employee->profile_image_url,
                        'date' => $dateKey,
                        'day_name' => $this->dayNameForDate($dateKey),
                        'schedule' => $scheduleLabel,
                        'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                        'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                        'formatted_time_in' => $this->formatTimeForDisplay($effectiveTimeIn),
                        'formatted_time_out' => $this->formatTimeForDisplay($effectiveTimeOut),
                        'early_out_time' => $earlyOutTime,
                        // Scheduled shift length (e.g. 8.00) — use for "Duration"; not inflated by early clock-in.
                        'scheduled_duration_hours' => $scheduledDurationHours,
                        'total_hours' => $effectiveWorkedMinutes !== null
                            ? round($effectiveWorkedMinutes / 60, 2)
                            : null,
                        'total_rendered_hours' => $effectiveWorkedMinutes !== null
                            ? round($effectiveWorkedMinutes / 60, 2)
                            : null,
                        'late_minutes' => $lateMinutes,
                        'late_label' => $lateLabel,
                        'undertime_minutes' => $undertimeMinutes,
                        'payroll_impact_minutes' => $payrollImpactMinutes,
                        'payroll_impact_hours' => $payrollImpactHours,
                        // OT minutes: from clock-out vs schedule buffer, or from approved OT end when no punch-out exists.
                        'overtime_minutes' => $otMinutesForRow,
                        'unapproved_overtime_hours' => $unapprovedOtHours,
                        'approved_overtime_hours' => $approvedOtHours,
                        'virtual_time_out_from_ot' => $virtualClockOutFromOt,
                        'status' => $status,
                        'presence_label' => $presenceLabel,
                        'presence_issue' => $presenceIssue,
                        'leave_type' => $leaveInfo['type'] ?? null,
                        'leave_status' => $leaveInfo['status'] ?? null,
                        'undertime_filing_status' => $undertimeFilingStatus,
                        'leave_start_date' => $leaveInfo['start_date'] ?? null,
                        'leave_end_date' => $leaveInfo['end_date'] ?? null,
                        'leave_duration_days' => $leaveDurationDays,
                        // Only meaningful statuses from the Overtime module (no "approved" with 0h and no ghost approvals).
                        'overtime_filed' => $ot !== null,
                        'overtime_status' => $this->normalizeOvertimeModuleStatusForDisplay($ot),
                        'overtime_hours_requested' => $ot !== null ? round((float) ($ot->computed_hours ?? 0), 2) : null,
                        'night_hours' => $premiumDay['night_hours'] ?? null,
                        'night_differential_pay' => $premiumDay['night_differential_pay'] ?? null,
                        'overtime_hours' => $premiumDay['overtime_hours'] ?? null,
                        'overtime_pay' => $premiumDay['overtime_pay'] ?? null,
                        'total_premium_pay' => $premiumDay !== null
                            ? (($premiumDay['overtime_pay'] ?? 0) + ($premiumDay['night_differential_pay'] ?? 0))
                            : null,
                        'work_condition' => $this->ruleLabelForRow($premiumDay, 0),
                        'pay_rule' => $this->ruleLabelForRow($premiumDay, 1),
                        'multiplier' => $this->ruleLabelForRow($premiumDay, 2),
                    ], $this->employmentFieldsForReport($employee, $viewer));
                }

                $cursor->addDay();
            }
        }

        $aggregateMs = (int) round((microtime(true) - $aggregateStartedAt) * 1000);

        $transformStartedAt = microtime(true);

        $pagedRows = collect($pagedRows)
            ->unique(fn (array $row): string => $row['employee_id'].'|'.$row['date'])
            ->values()
            ->all();

        if ($requestedEmployeeId > 0) {
            $pagedRows = array_values(array_filter($pagedRows, function (array $row) use ($requestedEmployeeId) {
                return (int) ($row['employee_id'] ?? 0) === $requestedEmployeeId;
            }));
        }

        $transformMs = (int) round((microtime(true) - $transformStartedAt) * 1000);

        $total = $totalMatches;
        $lastPage = max(1, (int) ceil($total / $perPage));

        $response = [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $pagedRows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'include_deactivated' => (bool) ($validated['include_deactivated'] ?? false),
            ],
        ];

        $totalResponseMs = (int) round((microtime(true) - $startedAt) * 1000);
        Log::info('Detailed attendance report prepared', [
            'actor_user_id' => (int) $request->user()->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'employee_count' => count($userIds),
            'rows_returned' => count($pagedRows),
            'matching_rows_total' => $totalMatches,
            'paginated' => true,
            'filters_parse_ms' => $filtersParseMs,
            'employees_load_ms' => $employeesLoadMs,
            'query_execute_ms' => $queryExecuteMs,
            'related_maps_ms' => $eagerLoadMs,
            'aggregate_ms' => $aggregateMs,
            'transform_ms' => $transformMs,
            'total_response_ms' => $totalResponseMs,
        ]);
        if ($queryExecuteMs > 500) {
            Log::warning('Reports detailed: slow attendance logs query', ['query_execute_ms' => $queryExecuteMs]);
        }
        if ($aggregateMs > 500) {
            Log::warning('Reports detailed: slow aggregate phase', ['aggregate_ms' => $aggregateMs, 'range_days' => $rangeDays]);
        }
        if ($totalResponseMs > 500) {
            Log::warning('Detailed attendance report slow', [
                'duration_ms' => $totalResponseMs,
                'employee_count' => count($userIds),
                'range_days' => $rangeDays,
            ]);
        }

        if ($adminDetailedCacheKey !== null) {
            Cache::put($adminDetailedCacheKey, $response, now()->addSeconds(self::ADMIN_DETAILED_CACHE_TTL_SECONDS));
        }

        if ($employeeDetailedCacheKey !== null) {
            Cache::put($employeeDetailedCacheKey, $response, now()->addSeconds(self::EMPLOYEE_DETAILED_CACHE_TTL_SECONDS));
        }

        return response()->json($response);
    }

    /**
     * Approved correction for overlaying log times (parity with attendance session / detailed rows).
     */
    private function pickApprovedDetailedCorrection(Collection $grouped, string $key): ?AttendanceCorrection
    {
        $items = $grouped->get($key);
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        return $items->first(function (AttendanceCorrection $c) {
            if (! $c->approved) {
                return false;
            }
            if ($c->rejected_at !== null) {
                return false;
            }
            if ($c->pending_approval === true) {
                return false;
            }

            return true;
        });
    }

    /**
     * Latest correction row for presence qualification / filing metadata.
     */
    private function pickCorrectionMetaRow(Collection $grouped, string $key): ?AttendanceCorrection
    {
        $items = $grouped->get($key);
        if ($items === null || $items->isEmpty()) {
            return null;
        }

        return $items->sortByDesc(function (AttendanceCorrection $c) {
            return (($c->approved_at?->getTimestamp() ?? 0) * 100000) + $c->id;
        })->first();
    }

    /**
     * Get work condition, pay rule, or multiplier label for a premium day row.
     *
     * @param  array|null  $premiumDay  Day from PremiumReportService (has rule_code)
     * @param  int  $index  0 = work_condition, 1 = pay_rule, 2 = multiplier
     */
    private function ruleLabelForRow(?array $premiumDay, int $index): ?string
    {
        if (! $premiumDay || empty($premiumDay['rule_code'])) {
            return null;
        }
        $labels = self::RULE_LABELS[$premiumDay['rule_code']] ?? null;

        return $labels[$index] ?? null;
    }

    /**
     * Overtime module status for reports: never return "approved" unless there is a real approved request
     * with payable hours. Missing filing → null (UI: "—" / "Not Filed").
     */
    private function normalizeOvertimeModuleStatusForDisplay(?Overtime $ot): ?string
    {
        if ($ot === null) {
            return null;
        }
        if ($ot->status === Overtime::STATUS_APPROVED && (float) ($ot->computed_hours ?? 0) <= 0.0001) {
            return null;
        }

        return $ot->status;
    }

    /**
     * Parse a date string as a calendar date in the attendance timezone.
     * Returns start of day or end of day so the UTC range for DB queries is correct.
     *
     * @param  string  $value  YYYY-MM-DD or MM/DD/YYYY
     * @param  bool  $endOfDay  true = end of day (23:59:59), false = start of day (00:00:00)
     */
    private function parseDateInAttendanceTz(string $value, bool $endOfDay = false): Carbon
    {
        $trimmed = trim($value);
        $tz = $this->attendanceTimezone();

        if ($trimmed === '') {
            $d = Carbon::now($tz);

            return $endOfDay ? $d->copy()->endOfDay() : $d->copy()->startOfDay();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $d = Carbon::createFromFormat('Y-m-d', $trimmed, $tz);
        } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $trimmed) === 1) {
            $d = Carbon::createFromFormat('m/d/Y', $trimmed, $tz);
        } else {
            $d = Carbon::parse($trimmed, $tz);
        }

        return $endOfDay ? $d->copy()->endOfDay() : $d->copy()->startOfDay();
    }

    /**
     * Parse a date string coming from the UI, accepting either:
     * - YYYY-MM-DD (native date input value)
     * - MM/DD/YYYY (common locale display format)
     */
    private function parseUiDateToCarbon(string $value): Carbon
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return Carbon::now();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return Carbon::createFromFormat('Y-m-d', $trimmed);
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $trimmed) === 1) {
            return Carbon::createFromFormat('m/d/Y', $trimmed);
        }

        // Fallback to Carbon's parser for any other reasonable formats.
        return Carbon::parse($trimmed);
    }

    /**
     * Leave credit balances and pending leave reservation (for HR reports).
     */
    public function leaveCredits(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = User::query()
            ->activeRoster()
            ->orderBy('name');
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);
        $users = $query->get([
            'id', 'name', 'employee_code', 'department_id', 'company_id', 'branch_id',
            'leave_credits', 'employment_status', 'hire_date',
        ]);

        $annual = LeaveCreditService::annualAllocation();
        $leaveSvc = app(LeaveCreditService::class);

        $employees = $users->map(function (User $u) use ($annual, $leaveSvc) {
            $detail = $leaveSvc->buildLeaveCreditsApiPayload($u);
            $rem = (int) ($detail['remaining'] ?? 0);
            $pending = (int) ($detail['pending_reserved_days'] ?? 0);

            return [
                'employee_id' => $u->id,
                'employee_code' => $u->employee_code,
                'name' => $u->name,
                'employment_status' => $u->employment_status,
                'leave_credits_remaining' => $rem,
                'annual_allocation' => $annual,
                'pending_reserved_days' => $pending,
                'effective_available' => max(0, (int) ($detail['effective_available'] ?? ($rem - $pending))),
                'eligible_for_paid_leave_pool' => $detail['eligible_for_paid_leave_pool'] ?? false,
                'probationary' => $detail['probationary'] ?? false,
                'display' => $detail['display'] ?? null,
            ];
        })->values();

        return response()->json([
            'annual_allocation' => $annual,
            'employees' => $employees,
        ]);
    }

    public function queueDetailedExport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $run = ReportExportRun::query()->create([
            'type' => 'detailed_report_csv',
            'filters' => [
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'] ?? $validated['from_date'],
            ],
            'status' => ReportExportRun::STATUS_QUEUED,
            'requested_by_user_id' => $request->user()?->id,
            'queued_at' => now(),
        ]);

        GenerateDetailedReportCsvJob::dispatch((int) $run->id);

        return response()->json([
            'message' => 'Detailed report export queued.',
            'queued' => true,
            'export_run_id' => (int) $run->id,
            'status' => (string) $run->status,
        ], 202);
    }

    public function detailedExportStatus(Request $request, int $id): JsonResponse
    {
        $run = ReportExportRun::query()->findOrFail($id);

        return response()->json([
            'id' => (int) $run->id,
            'type' => (string) $run->type,
            'status' => (string) $run->status,
            'file_path' => $run->file_path,
            'error_message' => $run->error_message,
            'queued_at' => optional($run->queued_at)?->toIso8601String(),
            'started_at' => optional($run->started_at)?->toIso8601String(),
            'completed_at' => optional($run->completed_at)?->toIso8601String(),
        ]);
    }
}
