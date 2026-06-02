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
use App\Services\AttendanceRollupService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\EmployeeLevelResolver;
use App\Services\HrRoleResolver;
use App\Services\RbacService;
use App\Services\LeaveCreditService;
use App\Services\OvertimePayrollService;
use App\Services\PayrollComputationService;
use App\Services\PremiumReportService;
use App\Services\ReportsCacheService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ReportsController extends Controller
{
    /** Maximum calendar span for detailed report (admin dashboards; exports use separate flows). */
    private const DETAILED_MAX_RANGE_DAYS = 186;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly RbacService $rbacService,
        private readonly PayrollComputationService $payrollComputation,
        private readonly PremiumReportService $premiumReport,
        private readonly OvertimePayrollService $overtimePayroll,
        private readonly AttendanceRollupService $attendanceRollup,
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
        $employee = app(\App\Services\EmployeeStatusService::class)->syncAutomaticEmploymentStatus($employee);
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
            ...$this->employeeLevelFieldsForReport($employee),
            'hire_date' => $employee->hire_date?->toDateString(),
            'employment_status_effective_date' => $employee->employment_status_effective_date?->toDateString(),
            'regularization_date' => $employee->regularization_date?->toDateString(),
            'status_override' => (bool) ($employee->status_override ?? false),
            'contract_start_date' => $employee->contract_start_date?->toDateString(),
            'contract_end_date' => $employee->contract_end_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeLevelFieldsForReport(User $employee): array
    {
        $resolved = app(EmployeeLevelResolver::class)->resolveEmployeeLevel($employee);

        return [
            'employee_level' => (int) $resolved['level_number'],
            'employee_level_name' => $resolved['level_name'],
            'employee_level_label' => $resolved['level_label'],
            'employee_level_source_module' => $resolved['source_module'],
            'employee_level_source_assignment_id' => $resolved['source_assignment_id'],
            'employee_level_organization_path' => $resolved['organization_path'],
        ];
    }

    /** Rule code → [work_condition, pay_rule, multiplier] for detailed report columns. */
    private const RULE_LABELS = [
        'ORD' => ['Ordinary working day (first 8 hrs)', '100% of basic hourly rate', '1.00'],
        'RD' => ['Rest Day (first 8 hrs)', '130% of daily/hourly rate', '1.30'],
        'RH' => ['Regular holiday worked (first 8 hrs)', '200%', '2.00'],
        'RHRD' => ['Regular holiday + rest day (first 8 hrs)', '260%', '2.60'],
        'SH' => ['Special holiday worked (first 8 hrs)', '130%', '1.30'],
        'SHRD' => ['Special holiday + rest day (first 8 hrs)', '150%', '1.50'],
        'DH' => ['Double holiday worked (first 8 hrs)', '300%', '3.00'],
        'DHRD' => ['Double holiday + rest day (first 8 hrs)', '300%', '3.00'],
    ];

    /** Rule code -> [OT pay rule, OT multiplier] for overtime request lines. */
    private const OT_RULE_LABELS = [
        'ORD' => ['Ordinary Day OT', '1.25'],
        'RD' => ['Rest Day OT', '1.69'],
        'RH' => ['Regular Holiday OT', '2.60'],
        'RHRD' => ['Regular Holiday + Rest Day OT', '3.38'],
        'SH' => ['Special Holiday OT', '1.69'],
        'SHRD' => ['Special Holiday + Rest Day OT', '1.95'],
        'DH' => ['Double Holiday OT', '3.90'],
        'DHRD' => ['Double Holiday + Rest Day OT', '3.90'],
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
        $actor = $request->user();
        if ($isEmployeeSelfRoute) {
            if (! $this->rbacService->canAccessReportsModule($actor)) {
                $this->logReportsAccessDenied($actor, 'no_reports_module_access');

                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $request->merge(['employee_id' => $actor->id]);
        }

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,incomplete,rest,holiday,all'],
            'leave_type' => ['nullable', 'string', 'max:50'],
            'overtime_status' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in(ReportsCacheService::ALLOWED_PER_PAGE)],
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
            $validated['department_id'] = null;
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

        $perPage = ReportsCacheService::normalizePerPage($validated['per_page'] ?? null);
        $pagePref = max(1, (int) ($validated['page'] ?? 1));

        $filtersParseMs = (int) round((microtime(true) - $startedAt) * 1000);

        $statusForCache = isset($validated['status']) ? strtolower(trim((string) $validated['status'])) : null;
        $searchForCache = strtolower(trim((string) ($validated['search'] ?? '')));

        $employeeDetailedCacheKey = null;
        $adminDetailedCacheKey = null;

        if ($isEmployeeSelfRoute) {
            $employeeDetailedCacheKey = ReportsCacheService::employeeListKey([
                'visibility_version' => 3,
                'employee_id' => (int) $request->user()->id,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'status' => $statusForCache ?? 'all',
                'page' => $pagePref,
                'per_page' => $perPage,
                'search' => $searchForCache,
            ]);
            $cachedDetailed = ReportsCacheService::get($employeeDetailedCacheKey);
            if (is_array($cachedDetailed) && isset($cachedDetailed['rows'], $cachedDetailed['meta'])) {
                $cacheHitMs = (int) round((microtime(true) - $startedAt) * 1000);
                $cachedDetailed['meta']['cache_hit'] = true;
                $cachedDetailed['meta']['total_response_ms'] = $cacheHitMs;
                Log::info('Employee detailed attendance report cache hit', [
                    'actor_user_id' => (int) $request->user()->id,
                    'cache_key_suffix' => substr((string) $employeeDetailedCacheKey, -32),
                    'rows_returned' => count($cachedDetailed['rows'] ?? []),
                    'total_response_ms' => $cacheHitMs,
                ]);

                return response()->json($cachedDetailed);
            }
        } else {
            $adminDetailedCacheKey = ReportsCacheService::adminListKey([
                'visibility_version' => 3,
                'scope' => (int) $request->user()->id,
                'company_id' => $validated['company_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'employee_id' => $validated['employee_id'] ?? null,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
                'status' => $statusForCache,
                'page' => $pagePref,
                'per_page' => $perPage,
                'department' => $validated['department'] ?? null,
                'leave_type' => $validated['leave_type'] ?? null,
                'overtime_status' => $validated['overtime_status'] ?? null,
                'search' => $searchForCache,
                'include_deactivated' => ! empty($validated['include_deactivated']) ? 1 : 0,
            ]);
            $cachedAdminDetailed = ReportsCacheService::get($adminDetailedCacheKey);
            if (is_array($cachedAdminDetailed) && isset($cachedAdminDetailed['rows'], $cachedAdminDetailed['meta'])) {
                $cacheHitMs = (int) round((microtime(true) - $startedAt) * 1000);
                $cachedAdminDetailed['meta']['cache_hit'] = true;
                $cachedAdminDetailed['meta']['total_response_ms'] = $cacheHitMs;
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

        $scopedEmployeeIds = null;

        if ($isEmployeeSelfRoute) {
            $scopedEmployeeIds = $this->dataScopeService->getReportScopedEmployeeIds($actor);
            if ($scopedEmployeeIds === []) {
                $this->logReportsAccessDenied($actor, 'own_reports_not_visible');

                return response()->json(['message' => 'Forbidden.'], 403);
            }
            /** @var \Illuminate\Support\Collection<int, User> $employees */
            $employees = User::query()
                ->whereKey($actor->id)
                ->reportableEmployees()
                ->active()
                ->with($employeeWithRelations)
                ->get();
        } else {
            $scopedEmployeeIds = $this->dataScopeService->getReportScopedEmployeeIds($actor);
            if ($scopedEmployeeIds === []) {
                $this->logReportsAccessDenied($actor, 'no_reports_module_access');

                return response()->json(['message' => 'Forbidden.'], 403);
            }
            $employeesQuery = User::query()
                ->reportableEmployees();
            if ($scopedEmployeeIds !== null) {
                $employeesQuery->whereIn('id', $scopedEmployeeIds);
            }

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

            if (! empty($validated['department_id'])) {
                $employeesQuery->where('department_id', (int) $validated['department_id']);
            }

            if ($requestedEmployeeId > 0) {
                $employeesQuery->where('id', $requestedEmployeeId);
            }

            /** @var \Illuminate\Support\Collection<int, User> $employees */
            $employees = $employeesQuery
                ->orderByLastName()
                ->with($employeeWithRelations)
                ->get();
        }

        Log::info('admin_reports: scoped employee query resolved', [
            'current_user_id' => (int) $actor->id,
            'current_employee_id' => (int) $actor->id,
            'current_employee_found' => User::query()->whereKey($actor->id)->exists(),
            'is_system_user' => (bool) $actor->is_system_user,
            'employee_is_hidden' => (bool) $actor->is_hidden,
            'employee_exclude_from_attendance' => (bool) $actor->exclude_from_attendance,
            'employee_exclude_from_reports' => (bool) $actor->exclude_from_reports,
            'own_employee_added' => $scopedEmployeeIds === null || in_array((int) $actor->id, $scopedEmployeeIds, true),
            'final_scoped_employee_ids' => $scopedEmployeeIds,
            'final_reports_query_count' => $employees->count(),
        ]);

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

        if (Schema::hasColumn('overtimes', 'voided_at')) {
            $overtimesQuery->whereNull('voided_at');
        }
        if (Schema::hasColumn('overtimes', 'deleted_at')) {
            $overtimesQuery->whereNull('deleted_at');
        }

        if (! empty($validated['employee_id'])) {
            $overtimesQuery->where('user_id', $validated['employee_id']);
        }

        $overtimes = $overtimesQuery->get([
            'id', 'user_id', 'date', 'schedule_end', 'time_out', 'expected_end_time',
            'computed_minutes', 'computed_hours', 'ot_type', 'ph_ot_rule', 'status',
        ]);

        /** @var array<int, array<string, list<Overtime>>> $overtimeByUserDate */
        $overtimeByUserDate = [];
        foreach ($overtimes as $ot) {
            $overtimeByUserDate[$ot->user_id][$ot->date->toDateString()][] = $ot;
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
                $isRestDayRow = $this->attendanceRollup->isScheduledRestDay(
                    $effectiveSchedule,
                    is_array($todaySchedule) ? $todaySchedule : null
                );
                $holidayOnDate = $leaveInfo === null
                    ? $this->payrollComputation->getHolidayForUserDate($employee, $dateKey)
                    : null;

                if (! $todaySchedule && ! $leaveInfo && ! $isRestDayRow && $holidayOnDate === null
                    && ($dayLogs === null || $dayLogs->isEmpty())) {
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

                $otRecords = $overtimeByUserDate[$employee->id][$dateKey] ?? [];
                $otStatusRaw = $this->overtimeStatusFromRecords($otRecords, false);
                if ($overtimeStatusFilter !== null && $overtimeStatusFilter !== '' && $overtimeStatusFilter !== 'all') {
                    if ($otStatusRaw !== strtolower((string) $overtimeStatusFilter)) {
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

                $approvedOvertimeRecords = $this->approvedOvertimeRecords($otRecords);
                $approvedOtForDetailedRow = $this->pickOvertimeForVirtualEnd($approvedOvertimeRecords);
                $virtualClockOutFromOt = false;

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

                // Rest day / not scheduled: suppress incidental punches, but keep approved OT filings visible.
                if (! (is_array($todaySchedule) && ! empty($todaySchedule['in']))) {
                    $timeIn = null;
                    $timeOut = null;
                    $workedMinutes = null;
                    $virtualClockOutFromOt = false;
                    if ($approvedOvertimeRecords === []) {
                        $approvedOtForDetailedRow = null;
                        $otRecords = [];
                    }
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
                } elseif ($holidayOnDate !== null) {
                    $status = 'holiday';
                } elseif ($isRestDayRow) {
                    $status = 'rest';
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
                $attendanceOtStatus = null;
                if ($effectiveTimeIn && ! $effectiveTimeOut && $approvedOvertimeRecords !== []) {
                    $approvedOtEnd = $approvedOtForDetailedRow
                        ? AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                            $approvedOtForDetailedRow,
                            $dateKey,
                            is_array($todaySchedule) ? $todaySchedule : null,
                            $attendanceTz
                        )
                        : null;
                    $attendanceOtStatus = ($approvedOtEnd instanceof Carbon && $dateKey === $todayDateRow && $nowTz->lessThanOrEqualTo($approvedOtEnd))
                        ? 'Working OT'
                        : 'Missing Clock Out';
                    $presenceLabel = $attendanceOtStatus;
                    $presenceIssue = $attendanceOtStatus === 'Working OT'
                        ? 'approved_ot_working'
                        : 'approved_ot_missing_clock_out';
                }

                if (! $todaySchedule && ! $leaveInfo && ! $effectiveTimeIn && ! $effectiveTimeOut
                    && $status !== 'rest' && $status !== 'holiday') {
                    // No schedule, no leave, no logs — skip blank days (rest/holiday rows are kept).
                    $cursor->addDay();

                    continue;
                }

                $scheduleLabel = ($isRestDayRow || $status === 'rest')
                    ? 'Rest Day'
                    : ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])
                        ? sprintf('%s – %s', $todaySchedule['in'], $todaySchedule['out'])
                        : '—');

                // Apply filters: status (must run after presence qualification). Search / leave type / OT already gated earlier.
                if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
                    $sf = strtolower((string) $statusFilter);
                    if ($sf === 'incomplete') {
                        if (! in_array($presenceIssue, ['incomplete_pair', 'correction_pending', 'approved_ot_missing_clock_out'], true)) {
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
                $actualRenderedOtHours = $virtualClockOutFromOt ? 0.0 : ($clockOtHours ?? 0.0);
                $actualRenderedOtMinutes = (int) round($actualRenderedOtHours * 60);
                $approvedOtRequestedHours = $this->sumOvertimeHours($approvedOvertimeRecords);
                $approvedOtRequestedMinutes = (int) round($approvedOtRequestedHours * 60);
                $payableOtMinutes = $approvedOtRequestedMinutes > 0
                    ? $this->overtimePayroll->resolvePayableOtMinutes($actualRenderedOtMinutes, $approvedOtRequestedMinutes)
                    : 0;
                $payableOtHours = round($payableOtMinutes / 60, 2);
                $otPayableBasis = $this->overtimePayroll->payableBasis();
                $otReductionReason = $this->overtimeReductionReason(
                    $otPayableBasis,
                    $approvedOtRequestedMinutes,
                    $actualRenderedOtMinutes,
                    $payableOtMinutes
                );
                $pendingOtHours = $this->sumOvertimeHours($this->overtimeRecordsByStatus($otRecords, Overtime::STATUS_PENDING));
                $rejectedOtHours = $this->sumOvertimeHours($this->overtimeRecordsByStatus($otRecords, Overtime::STATUS_REJECTED));

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
                        $tOutPayroll = $effectiveTimeOut && ! $virtualClockOutFromOt
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
                        $virtualClockOutFromOt ? null : $effectiveTimeOut,
                        $approvedOtForPremium,
                        $attendanceTz
                    );
                    $premiumDay = $this->applyApprovedOvertimePayToPremiumDay(
                        $premiumDay,
                        $premiumCtxCache[$employee->id],
                        $approvedOvertimeRecords,
                        $actualRenderedOtMinutes,
                    );
                }
                $clockVal = $actualRenderedOtHours;
                $approvedOtHours = $approvedOtRequestedHours;
                $unapprovedOtHours = $approvedOtRequestedHours > 0 || $clockVal > 0.0001
                    ? abs(round($clockVal - $approvedOtRequestedHours, 2))
                    : 0.0;
                if ($clockVal <= 0.0001 && $approvedOtRequestedHours > 0) {
                    $unapprovedOtHours = 0.0;
                }

                if ($needsHeavy) {
                    $pagedRows[] = array_merge([
                        'employee_id' => $employee->id,
                        'employee_name' => $employee->display_name,
                        'employee_formatted_name' => $employee->formatted_name,
                        'employee_sort_key' => $employee->employeeListingSortKey(),
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
                        'actual_rendered_overtime_hours' => round($actualRenderedOtHours, 2),
                        'rendered_overtime_hours' => round($actualRenderedOtHours, 2),
                        'approved_overtime_hours' => $approvedOtHours,
                        'approved_ot_requested_hours' => $approvedOtRequestedHours,
                        'payable_overtime_hours' => $payableOtHours,
                        'ot_payable_basis' => $otPayableBasis,
                        'overtime_reduction_reason' => $otReductionReason,
                        'pending_overtime_hours' => $pendingOtHours,
                        'rejected_overtime_hours' => $rejectedOtHours,
                        'virtual_time_out_from_ot' => $virtualClockOutFromOt,
                        'status' => $status,
                        'presence_label' => $presenceLabel,
                        'presence_issue' => $presenceIssue,
                        'attendance_time_out_status' => $attendanceOtStatus,
                        'leave_type' => $leaveInfo['type'] ?? null,
                        'leave_status' => $leaveInfo['status'] ?? null,
                        'undertime_filing_status' => $undertimeFilingStatus,
                        'leave_start_date' => $leaveInfo['start_date'] ?? null,
                        'leave_end_date' => $leaveInfo['end_date'] ?? null,
                        'leave_duration_days' => $leaveDurationDays,
                        // Clean status from the Overtime module; approved beats pending/rejected for the same date.
                        'overtime_filed' => $otRecords !== [],
                        'overtime_status' => $this->overtimeStatusFromRecords($otRecords),
                        'overtime_hours_requested' => $otRecords !== [] ? round($this->sumOvertimeHours($otRecords), 2) : null,
                        'approved_overtime_request_ids' => array_map(static fn (Overtime $row): int => (int) $row->id, $approvedOvertimeRecords),
                        'approved_ot_start_time' => $this->formatOvertimeRequestStart($approvedOvertimeRecords, $dateKey, is_array($todaySchedule) ? $todaySchedule : null, $attendanceTz),
                        'approved_ot_end_time' => $this->formatOvertimeRequestEnd($approvedOvertimeRecords),
                        'night_hours' => $premiumDay['night_hours'] ?? null,
                        'night_differential_pay' => $premiumDay['night_differential_pay'] ?? null,
                        'overtime_hours' => $premiumDay['overtime_hours'] ?? null,
                        'overtime_pay' => $premiumDay['overtime_pay'] ?? null,
                        'total_premium_pay' => $premiumDay !== null
                            ? (($premiumDay['overtime_pay'] ?? 0) + ($premiumDay['night_differential_pay'] ?? 0))
                            : null,
                        'work_condition' => $this->ruleLabelForRow($premiumDay, 0),
                        'pay_rule' => $this->otPayRuleLabelForRecords($approvedOvertimeRecords) ?? $this->ruleLabelForRow($premiumDay, 1),
                        'multiplier' => $this->otMultiplierLabelForRecords($approvedOvertimeRecords) ?? $this->ruleLabelForRow($premiumDay, 2),
                    ], $this->employmentFieldsForReport($employee, $viewer));
                    $this->logDetailedOvertimeDebug(
                        $employee,
                        $dateKey,
                        $effectiveTimeIn,
                        $effectiveTimeOut,
                        $approvedOvertimeRecords,
                        $actualRenderedOtHours,
                        $otPayableBasis,
                        $payableOtHours,
                        $premiumCtxCache[$employee->id]['hourly_rate'] ?? 0,
                        $this->otMultiplierLabelForRecords($approvedOvertimeRecords),
                        (float) ($premiumDay['overtime_pay'] ?? 0),
                        $payrollImpactHours,
                        $this->overtimeStatusFromRecords($otRecords) ?? '—',
                    );
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
                'cache_hit' => false,
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
            ReportsCacheService::put(
                $adminDetailedCacheKey,
                $response,
                ReportsCacheService::TABLE_TTL_SECONDS,
            );
        }

        if ($employeeDetailedCacheKey !== null) {
            ReportsCacheService::put(
                $employeeDetailedCacheKey,
                $response,
                ReportsCacheService::TABLE_TTL_SECONDS,
                (int) $request->user()->id,
            );
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

    /** @param list<Overtime> $records @return list<Overtime> */
    private function overtimeRecordsByStatus(array $records, string $status): array
    {
        return array_values(array_filter(
            $records,
            static fn (Overtime $ot): bool => strtolower((string) $ot->status) === $status
        ));
    }

    /** @param list<Overtime> $records @return list<Overtime> */
    private function approvedOvertimeRecords(array $records): array
    {
        return $this->overtimeRecordsByStatus($records, Overtime::STATUS_APPROVED);
    }

    /** @param list<Overtime> $records */
    private function sumOvertimeHours(array $records): float
    {
        $hours = 0.0;
        foreach ($records as $ot) {
            $hours += max(0.0, (float) ($ot->computed_hours ?? 0));
        }

        return round($hours, 2);
    }

    /** @param list<Overtime> $records */
    private function overtimeStatusFromRecords(array $records, bool $display = true): ?string
    {
        $statuses = array_map(static fn (Overtime $ot): string => strtolower((string) $ot->status), $records);
        $status = null;
        if (in_array(Overtime::STATUS_APPROVED, $statuses, true)) {
            $status = Overtime::STATUS_APPROVED;
        } elseif (in_array(Overtime::STATUS_PENDING, $statuses, true)) {
            $status = Overtime::STATUS_PENDING;
        } elseif (in_array(Overtime::STATUS_REJECTED, $statuses, true)) {
            $status = Overtime::STATUS_REJECTED;
        }
        if ($status === null) {
            return null;
        }

        return $display ? ucfirst($status) : $status;
    }

    /** @param list<Overtime> $records */
    private function pickOvertimeForVirtualEnd(array $records): ?Overtime
    {
        $best = null;
        foreach ($records as $ot) {
            if ($best === null) {
                $best = $ot;
                continue;
            }
            $candidate = $ot->expected_end_time ?? $ot->time_out;
            $current = $best->expected_end_time ?? $best->time_out;
            if ($candidate && (! $current || $candidate->format('H:i:s') > $current->format('H:i:s'))) {
                $best = $ot;
            }
        }

        return $best;
    }

    /** @param list<Overtime> $records */
    private function primaryOtRuleCode(array $records): ?string
    {
        foreach ($records as $ot) {
            $code = strtoupper(trim((string) ($ot->ph_ot_rule ?? '')));
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    /** @param list<Overtime> $records */
    private function otPayRuleLabelForRecords(array $records): ?string
    {
        $code = $this->primaryOtRuleCode($records);
        if ($code === null) {
            return null;
        }

        return self::OT_RULE_LABELS[$code][0] ?? ($code.' OT');
    }

    /** @param list<Overtime> $records */
    private function otMultiplierLabelForRecords(array $records): ?string
    {
        $code = $this->primaryOtRuleCode($records);
        if ($code === null) {
            return null;
        }
        $configured = config("payroll.rules.{$code}.ot");
        if (is_numeric($configured)) {
            return number_format((float) $configured, 2, '.', '');
        }

        return self::OT_RULE_LABELS[$code][1] ?? null;
    }

    private function overtimeReductionReason(string $basis, int $approvedMinutes, int $actualMinutes, int $payableMinutes): ?string
    {
        if ($approvedMinutes <= 0) {
            return null;
        }
        if ($actualMinutes < $approvedMinutes) {
            return 'Clocked out before approved OT end';
        }
        if ($actualMinutes > $approvedMinutes) {
            return 'Rendered OT exceeded approved OT window';
        }

        return null;
    }

    /**
     * @param array{hourly_rate?: float} $context
     * @param list<Overtime> $approvedRecords
     */
    private function applyApprovedOvertimePayToPremiumDay(?array $premiumDay, array $context, array $approvedRecords, int $actualRenderedOtMinutes): ?array
    {
        if ($premiumDay === null && $approvedRecords === []) {
            return null;
        }

        $row = $premiumDay ?? [
            'night_hours' => null,
            'night_differential_pay' => null,
            'regular_pay' => 0.0,
            'total_pay' => 0.0,
            'rule_code' => $this->primaryOtRuleCode($approvedRecords) ?? 'ORD',
        ];
        $dateKey = null;
        $daySchedule = null;
        $first = $approvedRecords[0] ?? null;
        if ($first instanceof Overtime) {
            $dateKey = $first->date instanceof Carbon
                ? $first->date->toDateString()
                : Carbon::parse((string) $first->date)->toDateString();
            $effectiveSchedule = $context['effective_schedule'] ?? null;
            if (is_array($effectiveSchedule) && $dateKey !== null) {
                $dayKey = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][(int) Carbon::parse($dateKey, $this->attendanceTimezone())->format('w')];
                $daySchedule = $effectiveSchedule[$dayKey] ?? null;
            }
        }
        $otComp = $this->overtimePayroll->computeCompensationFromRecords(
            $approvedRecords,
            (float) ($context['hourly_rate'] ?? 0.0),
            null,
            (string) ($row['rule_code'] ?? 'ORD'),
            $actualRenderedOtMinutes,
            $dateKey,
            is_array($daySchedule) ? $daySchedule : null,
            $this->attendanceTimezone()
        );

        $approvedNdHours = (float) ($otComp['nd_hours'] ?? 0);
        $approvedNdPay = (float) ($otComp['nd_pay'] ?? 0);
        $clockNdHours = (float) ($row['night_hours'] ?? 0);
        $clockNdPay = (float) ($row['night_differential_pay'] ?? 0);

        $row['approved_overtime_hours'] = (float) ($otComp['approved_hours'] ?? 0);
        $row['actual_rendered_overtime_hours'] = round($actualRenderedOtMinutes / 60, 2);
        $row['payable_overtime_hours'] = (float) ($otComp['payable_hours'] ?? 0);
        $row['overtime_hours'] = (float) ($otComp['payable_hours'] ?? 0);
        $row['overtime_pay'] = (float) ($otComp['ot_pay'] ?? 0);
        $row['ot_payable_basis'] = $this->overtimePayroll->payableBasis();
        $row['ot_multiplier'] = $this->otMultiplierLabelForRecords($approvedRecords);
        $row['ot_pay_rule'] = $this->otPayRuleLabelForRecords($approvedRecords);
        $row['night_hours'] = round($clockNdHours + $approvedNdHours, 2);
        $row['night_differential_pay'] = round($clockNdPay + $approvedNdPay, 2);
        $row['total_premium_pay'] = round((float) ($row['overtime_pay'] ?? 0) + (float) ($row['night_differential_pay'] ?? 0), 2);
        $row['total_pay'] = round(
            (float) ($row['regular_pay'] ?? 0)
            + (float) ($row['overtime_pay'] ?? 0)
            + (float) ($row['night_differential_pay'] ?? 0),
            2
        );

        Log::debug('reports_overtime_night_differential', [
            'approved_overtime_request_ids' => array_map(static fn (Overtime $row): int => (int) $row->id, $approvedRecords),
            'nd_hours' => $approvedNdHours,
            'nd_pay' => $approvedNdPay,
            'ot_pay' => (float) ($row['overtime_pay'] ?? 0),
            'total_premium' => (float) ($row['total_premium_pay'] ?? 0),
            'report_nd_column_created' => $approvedNdPay > 0,
            'payslip_nd_line_created' => $approvedNdHours > 0,
        ]);

        return $row;
    }

    /** @param list<Overtime> $records */
    private function formatOvertimeRequestStart(array $records, string $dateKey, ?array $daySchedule, string $tz): ?string
    {
        $first = $records[0] ?? null;
        if (! $first) {
            return null;
        }
        if ($first->schedule_end) {
            return $first->schedule_end->format('H:i');
        }
        if ($daySchedule && ! empty($daySchedule['out'])) {
            return AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz)?->format('H:i');
        }

        return null;
    }

    /** @param list<Overtime> $records */
    private function formatOvertimeRequestEnd(array $records): ?string
    {
        $best = $this->pickOvertimeForVirtualEnd($records);
        $end = $best?->expected_end_time ?? $best?->time_out;

        return $end?->format('H:i');
    }

    /** @param list<Overtime> $approvedRecords */
    private function logDetailedOvertimeDebug(
        User $employee,
        string $dateKey,
        mixed $timeIn,
        mixed $timeOut,
        array $approvedRecords,
        float $actualRenderedOtHours,
        string $basis,
        float $payableOtHours,
        float $hourlyRate,
        ?string $otMultiplier,
        float $otPay,
        float $payrollImpactHours,
        string $statusDisplayed
    ): void {
        if ($approvedRecords === [] && $actualRenderedOtHours <= 0.0001) {
            return;
        }

        Log::debug('reports_overtime_computation', [
            'employee_id' => (int) $employee->id,
            'date' => $dateKey,
            'attendance_time_in' => $timeIn ? $this->formatTimeInAttendanceTz($timeIn) : null,
            'attendance_time_out' => $timeOut ? $this->formatTimeInAttendanceTz($timeOut) : null,
            'approved_overtime_request_ids' => array_map(static fn (Overtime $row): int => (int) $row->id, $approvedRecords),
            'approved_start_time' => $this->formatOvertimeRequestStart($approvedRecords, $dateKey, null, $this->attendanceTimezone()),
            'approved_end_time' => $this->formatOvertimeRequestEnd($approvedRecords),
            'approved_hours' => $this->sumOvertimeHours($approvedRecords),
            'actual_rendered_ot_hours' => round($actualRenderedOtHours, 2),
            'ot_payable_basis' => $basis,
            'payable_ot_hours' => round($payableOtHours, 2),
            'hourly_rate' => round($hourlyRate, 4),
            'ot_multiplier' => $otMultiplier !== null ? (float) $otMultiplier : null,
            'ot_pay' => round($otPay, 2),
            'payroll_impact_hours' => round($payrollImpactHours, 2),
            'status_displayed' => $statusDisplayed,
        ]);
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
        if (! $this->rbacService->canViewAllReports($actor) && ! $this->rbacService->canViewSubordinateReports($actor)) {
            $this->logReportsAccessDenied($actor, 'leave_credits_requires_all_or_subordinate_reports');

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $scopedEmployeeIds = $this->dataScopeService->getReportScopedEmployeeIds($actor);
        if ($scopedEmployeeIds === []) {
            $this->logReportsAccessDenied($actor, 'no_reports_module_access');

            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $query = User::query()
            ->reportableEmployees()
            ->active()
            ->orderByLastName();
        if ($scopedEmployeeIds !== null) {
            $query->whereIn('id', $scopedEmployeeIds);
        }
        $users = $query->get([
            'id', 'name', 'employee_code', 'department_id', 'company_id', 'branch_id',
            'leave_credits', 'employment_status', 'hire_date',
        ]);

        Log::info('leave_credit_reports: scoped employee query resolved', [
            'current_user_id' => (int) $actor->id,
            'current_employee_id' => (int) $actor->id,
            'current_employee_found' => User::query()->whereKey($actor->id)->exists(),
            'is_system_user' => (bool) $actor->is_system_user,
            'employee_is_hidden' => (bool) $actor->is_hidden,
            'employee_exclude_from_attendance' => (bool) $actor->exclude_from_attendance,
            'employee_exclude_from_reports' => (bool) $actor->exclude_from_reports,
            'own_employee_added' => $scopedEmployeeIds === null || in_array((int) $actor->id, $scopedEmployeeIds, true),
            'final_scoped_employee_ids' => $scopedEmployeeIds,
            'final_reports_query_count' => $users->count(),
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
        $actor = $request->user();
        if (! $this->rbacService->canViewAllReports($actor) && ! $this->rbacService->canViewSubordinateReports($actor)) {
            $this->logReportsAccessDenied($actor, 'export_requires_all_or_subordinate_reports');

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $scopedEmployeeIds = $this->dataScopeService->getReportScopedEmployeeIds($actor);
        if ($scopedEmployeeIds === []) {
            $this->logReportsAccessDenied($actor, 'no_reports_module_access');

            return response()->json(['message' => 'Forbidden.'], 403);
        }

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

    private function logReportsAccessDenied(User $actor, string $reason): void
    {
        $flags = $this->rbacService->accessFlagsForUser($actor);

        Log::warning('reports_access: denied', [
            'current_user_id' => (int) $actor->id,
            'current_employee_id' => (int) $actor->id,
            'role' => $this->hrRoleResolver->resolve($actor)->value,
            'permissions' => $this->rbacService->getPermissionsForUser($actor)->values()->all(),
            'can_access_reports_module' => (bool) ($flags['can_access_reports_module'] ?? false),
            'can_view_own_reports' => (bool) ($flags['can_view_own_reports'] ?? false),
            'can_view_subordinate_reports' => (bool) ($flags['can_view_subordinate_reports'] ?? false),
            'can_view_all_reports' => (bool) ($flags['can_view_all_reports'] ?? false),
            'scoped_employee_ids' => null,
            'forbidden_reason' => $reason,
        ]);
    }
}
