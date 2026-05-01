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
use Illuminate\Support\Facades\Log;

class ReportsController extends Controller
{
    /** Fixed page size for detailed report list responses; incoming per_page query is ignored. */
    private const DETAILED_ROWS_PER_PAGE = 10;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollComputationService $payrollComputation,
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

    /**
     * Summary reports per employee for a given date range.
     *
     * Supports:
     * - Late report (total late count + late minutes)
     * - Undertime report (total undertime count)
     * - Half day report (count of half days)
     * - Absences report (count of absences)
     * - Monthly-style summary (all metrics + total hours)
     */
    public function summary(Request $request): JsonResponse
    {
        $routeName = $request->route()?->getName();
        $isEmployeeSelfRoute = $routeName === 'employee.reports.summary';
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
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,incomplete,all'],
        ]);

        // Normalize filters so matching is consistent (trim, lowercase status).
        $validated['department'] = isset($validated['department']) && $validated['department'] !== ''
            ? trim($validated['department'])
            : null;
        $validated['status'] = isset($validated['status']) && $validated['status'] !== ''
            ? strtolower(trim($validated['status']))
            : null;

        // Parse dates as calendar dates in attendance timezone so the UTC log range is correct.
        $attendanceTz = $this->attendanceTimezone();
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

        if ($from->greaterThan($to)) {
            $from = $to->copy()->startOfDay();
        }

        // "All statuses": treat as no status filter so all records are included.
        $statusFilter = $validated['status'] ?? null;
        if ($statusFilter === 'all' || $statusFilter === '' || $statusFilter === null) {
            $statusFilter = null;
        }

        $employeesQuery = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);

        // Department filter: match employees by departmentRelation.name or legacy department.
        if (! empty($validated['department'])) {
            $deptName = $validated['department'];
            $employeesQuery->where(function ($q) use ($deptName) {
                $q->where('department', $deptName)
                    ->orWhereHas('departmentRelation', fn ($d) => $d->where('name', $deptName));
            });
        }

        // Company filter: match employees who belong to the given company via any path.
        if (! empty($validated['company_id'])) {
            $cid = (int) $validated['company_id'];
            $employeesQuery->where(function ($q) use ($cid) {
                $q->where('company_id', $cid)
                    ->orWhereHas('branch', fn ($b) => $b->where('company_id', $cid))
                    ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $cid)))
                    ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $cid));
            });
        }

        if (! empty($validated['employee_id'])) {
            $employeesQuery->where('id', $validated['employee_id']);
        }

        if ($isEmployeeSelfRoute) {
            $employeesQuery->where('id', $request->user()->id);
        } else {
            $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeesQuery);
        }

        /** @var \Illuminate\Support\Collection<int, User> $employees */
        $employees = $employeesQuery
            ->orderBy('name')
            ->with([
                'workingSchedule',
                'companyHeadships:id,name,company_head_id',
                'company:id,name',
                'branch:id,company_id',
                'branch.company:id,name',
                'departmentRelation:id,name,branch_id',
                'departmentRelation.branch:id,company_id',
                'departmentRelation.branch.company:id,name',
            ])
            ->get();

        $viewer = $request->user();

        // Pre-compute company names and IDs for all employees.
        $employeeCompanyNames = [];
        $employeeCompanyIds = [];
        foreach ($employees as $emp) {
            $co = $emp->companyHeadships->first() ?? $emp->company ?? $emp->branch?->company ?? $emp->departmentRelation?->branch?->company;
            $employeeCompanyNames[$emp->id] = $co?->name ?? null;
            $employeeCompanyIds[$emp->id] = $co?->id ?? null;
        }

        if ($employees->isEmpty()) {
            return response()->json([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'employees' => [],
            ]);
        }

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        $userIds = $employees->pluck('id')->all();

        // verified_at is stored in UTC; convert the attendance-TZ range to UTC
        // so that e.g. "Feb 26" in Manila includes logs from 2026-02-25 16:00 UTC to 2026-02-26 15:59 UTC.
        $fromUtc = $from->copy()->setTimezone('UTC');
        $toUtc = $to->copy()->setTimezone('UTC');

        /** @var \Illuminate\Support\Collection<int, AttendanceLog> $logs */
        $logsQuery = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            // Strictly constrain by the punch timestamp (`verified_at`, UTC in DB)
            // so seeded attendance + attendance module match exactly.
            ->whereBetween('verified_at', [$fromUtc, $toUtc]);

        if (! empty($validated['employee_id'])) {
            // When a specific employee is selected, hard-filter by that user
            // so that no other employees' logs are ever included.
            $logsQuery->where('user_id', $validated['employee_id']);
        }

        $logs = $logsQuery
            ->orderBy('verified_at')
            ->get();

        $attendanceTz = $this->attendanceTimezone();
        /** @var array<int, array<string, \Illuminate\Support\Collection>> $logsByUserDate */
        $logsByUserDate = [];
        foreach ($logs as $log) {
            $userId = $log->user_id;
            $stamp = $log->verified_at ?? $log->created_at;
            $dateKey = $stamp->copy()->timezone($attendanceTz)->toDateString();
            $logsByUserDate[$userId][$dateKey] = ($logsByUserDate[$userId][$dateKey] ?? collect())->push($log);
        }

        // Preload approved corrections so manual attendance entries are included in summary metrics.
        $correctionsSummary = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('approved', true)
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        // Preload approved leaves for range to include them in reports (e.g. payroll impact).
        $approvedLeavesQuery = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $to->toDateString().' 23:59:59')
            ->where('end_date', '>=', $from->toDateString().' 00:00:00');

        if (! empty($validated['employee_id'])) {
            $approvedLeavesQuery->where('user_id', $validated['employee_id']);
        }

        $approvedLeaves = $approvedLeavesQuery->get();

        /** @var array<int, array<string, array{type: string, undertime_time?: string|null}>> $leaveDatesByUser */
        $leaveDatesByUser = [];
        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date->copy()->max($from);
            $leaveEnd = $leave->end_date->copy()->min($to);
            $cursorLeave = $leaveStart->copy();
            while ($cursorLeave->lessThanOrEqualTo($leaveEnd)) {
                $d = $cursorLeave->toDateString();
                $leaveDatesByUser[$leave->user_id][$d] = [
                    'type' => $leave->type,
                    'undertime_time' => $leave->undertime_time ? substr((string) $leave->undertime_time, 0, 5) : null,
                ];
                $cursorLeave->addDay();
            }
        }

        $results = [];
        $departmentAggregates = [];

        /** @var User $employee */
        foreach ($employees as $employee) {
            $deptKey = $employee->departmentRelation?->name ?? $employee->department ?? 'Unassigned';
            if (! isset($departmentAggregates[$deptKey])) {
                $departmentAggregates[$deptKey] = [
                    'department' => $deptKey,
                    'scheduled_days' => 0,
                    'present_days' => 0,
                    'total_worked_minutes' => 0,
                    'late_counts_by_employee' => [],
                ];
            }

            $metrics = array_merge([
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'profile_image' => $employee->profile_image_url,
                'department' => $employee->departmentRelation?->name ?? $employee->department,
                'company_id' => $employeeCompanyIds[$employee->id] ?? null,
                'company_name' => $employeeCompanyNames[$employee->id] ?? null,
                'late_count' => 0,
                'late_minutes' => 0,
                'undertime_count' => 0,
                'undertime_minutes' => 0,
                'halfday_count' => 0,
                'absent_count' => 0,
                'present_count' => 0,
                'total_worked_minutes' => 0,
                'overtime_count' => 0,
                'overtime_minutes' => 0,
                'leave_days' => 0,
            ], $this->employmentFieldsForReport($employee, $viewer));

            $effectiveSchedule = $this->resolveEffectiveSchedule($employee);

            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
                $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;
                $dateKey = $cursor->toDateString();

                /** @var Collection<int, AttendanceLog>|null $dayLogs */
                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? null;

                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                // Overlay approved correction: overrides log-based times and recalculates
                // worked minutes subtracting the schedule break period.
                $corrKeyS = $employee->id.'|'.$dateKey;
                $correctionS = $correctionsSummary->get($corrKeyS)?->first();
                if ($correctionS) {
                    if ($correctionS->time_in) {
                        $timeIn = $correctionS->time_in;
                    }
                    if ($correctionS->time_out) {
                        $timeOut = $correctionS->time_out;
                    }
                    if ($correctionS->time_in && $correctionS->time_out) {
                        $workedMinutes = $todaySchedule
                            ? AttendanceStatusService::getNetWorkedMinutes(
                                $correctionS->time_in,
                                $correctionS->time_out,
                                $todaySchedule,
                                $dateKey,
                                $attendanceTz
                            )
                            : (int) $correctionS->time_in->diffInMinutes($correctionS->time_out);
                    }
                }

                // For regular clock-in/out logs (no correction covering both times), deduct the
                // schedule's unpaid break window so worked minutes are consistent across all views.
                if (! ($correctionS && $correctionS->time_in && $correctionS->time_out)) {
                    if ($todaySchedule && $timeIn && $timeOut && $workedMinutes !== null) {
                        $tIn = $timeIn instanceof Carbon ? $timeIn : Carbon::parse($timeIn);
                        $tOut = $timeOut instanceof Carbon ? $timeOut : Carbon::parse($timeOut);
                        $workedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                            $tIn, $tOut, $todaySchedule, $dateKey, $attendanceTz
                        );
                    }
                }

                // Rest day / not scheduled: never surface punches (e.g., Sundays) in reports.
                // If the day has no scheduled "in" time, treat it as rest day and blank out any punches/corrections.
                if (! (is_array($todaySchedule) && ! empty($todaySchedule['in']))) {
                    $timeIn = null;
                    $timeOut = null;
                    $workedMinutes = null;
                }

                $hasTimeIn = $timeIn !== null;

                // Per-day metric deltas so that we can apply the status filter
                // before accumulating into the employee and department totals.
                $dayLeave = 0;
                $dayAbsent = 0;
                $dayHalfday = 0;
                $dayLate = 0;
                $dayLateMinutes = 0;
                $dayUndertimeCount = 0;
                $dayUndertimeMinutes = 0;
                $dayOvertimeCount = 0;
                $dayOvertimeMinutes = 0;
                $dayPresentIncrement = 0;
                $dayScheduledIncrement = 0;
                $dayLateCountForDept = 0;

                $status = '—';
                $leaveOnDate = $leaveDatesByUser[$employee->id][$dateKey] ?? null;
                $isOnLeave = $leaveOnDate !== null;
                $leaveTypeOnDate = $leaveOnDate['type'] ?? null;

                if ($isOnLeave) {
                    if ($leaveTypeOnDate === 'half_day') {
                        $status = 'halfday';
                        $dayHalfday = 1;
                        $dayLeave = 0.5;
                    } elseif ($leaveTypeOnDate === 'undertime') {
                        $status = 'undertime';
                        $dayUndertimeCount = 1;
                        // Undertime is time-based (minutes), not a fraction of a day.
                        $dayLeave = 0;
                        if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                            $utTime = $leaveOnDate['undertime_time'] ?? null;
                            if ($utTime) {
                                $computed = $this->computeUndertimeMinutesFromEarlyOut($dateKey, $todaySchedule, (string) $utTime, $attendanceTz);
                                if ($computed !== null) {
                                    $dayUndertimeMinutes = $computed;
                                }
                            }
                        }
                    } else {
                        $status = 'leave';
                        $dayLeave = 1;
                    }
                } elseif ($todaySchedule && ! empty($todaySchedule['in'])) {
                    $dayScheduledIncrement = 1;

                    if (! $hasTimeIn) {
                        // Do not auto-mark future dates as absent. Only dates
                        // before today, or today after the configured cutoff,
                        // are considered true absences.
                        $todayDate = now($attendanceTz)->toDateString();
                        $isFuture = $dateKey > $todayDate;
                        if (! $isFuture) {
                            $isToday = $dateKey === $todayDate;
                            $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now());
                            if ($pastCutoff) {
                                $dayAbsent = 1;
                                $status = 'absent';
                            }
                        }
                    } else {
                        $timeInCarbon = $timeIn instanceof Carbon ? $timeIn : Carbon::parse($timeIn);
                        $clockInResult = AttendanceStatusService::getClockInStatus(
                            $todaySchedule,
                            $dateKey,
                            $timeInCarbon
                        );

                        if ($clockInResult['status'] === 'half_day') {
                            $dayHalfday = 1;
                            $status = 'halfday';
                        } elseif ($clockInResult['status'] === 'late') {
                            $dayLate = 1;
                            // Store and display actual late minutes (Actual Time-In − 8:00 AM) in reports
                            $dayLateMinutes = $clockInResult['late_minutes'];
                            $status = 'late';
                            $dayLateCountForDept = 1;
                        } else {
                            $status = 'present';
                        }
                    }

                    if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $attendanceTz);
                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);

                        if ($workedMinutes !== null && $status !== 'halfday' && $scheduledEnd && $requiredMinutes > 0) {
                            $outCarbon = $timeOut instanceof Carbon ? $timeOut : ($timeOut ? Carbon::parse($timeOut) : null);
                            $earlyTimeout = isset($todaySchedule['early_timeout_minutes']) ? (int) $todaySchedule['early_timeout_minutes'] : null;
                            /** Early leave vs scheduled end (AttendanceController / kiosk parity). */
                            $undertimeFromEarlyOut = $outCarbon
                                ? AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $outCarbon, $earlyTimeout)
                                : 0;
                            /**
                             * Net shortfall vs required shift minutes after break netting (Attendance summary parity).
                             * Catches gaps not expressed by scheduled-end − clock-out alone (e.g. within early-timeout tolerance).
                             */
                            $undertimeFromNetShortfall = max(0, $requiredMinutes - (int) $workedMinutes);
                            $effectiveUndertime = max((int) $undertimeFromEarlyOut, $undertimeFromNetShortfall);
                            if (
                                $effectiveUndertime > 0
                                || $undertimeFromNetShortfall > $undertimeThresholdMinutes
                            ) {
                                $dayUndertimeCount = 1;
                                $dayUndertimeMinutes = $effectiveUndertime;
                                $status = 'undertime';
                            }

                            $postShiftOtSum = 0;
                            $overtimeBuffer = isset($todaySchedule['overtime_buffer_minutes']) ? (int) $todaySchedule['overtime_buffer_minutes'] : (int) config('attendance.overtime_buffer_minutes', 15);
                            $otStartSum = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                            if ($outCarbon && $outCarbon->greaterThan($otStartSum)) {
                                $postShiftOtSum = (int) $otStartSum->diffInMinutes($outCarbon);
                            }

                            $preShiftOtSum = 0;
                            $scheduledStartSum = AttendanceStatusService::getScheduledStartForDate($dateKey, $todaySchedule, $attendanceTz);
                            $inCarbonSum = $timeIn instanceof Carbon ? $timeIn : ($timeIn ? Carbon::parse($timeIn) : null);
                            if ($scheduledStartSum && $inCarbonSum && $inCarbonSum->lessThan($scheduledStartSum)) {
                                $preShiftOtSum = (int) $inCarbonSum->diffInMinutes($scheduledStartSum);
                            }

                            $overtimeForDay = $preShiftOtSum + $postShiftOtSum;
                            if ($overtimeForDay > 0) {
                                $dayOvertimeCount = 1;
                                $dayOvertimeMinutes = $overtimeForDay;
                            }
                        }

                        // Clock-in but no clock-out after shift end ⇒ incomplete record — same semantics as Employee Attendance /
                        // {@see AttendancePresenceDisplayService} (Absent was wrong here and wiped undertime in reports).
                        if ($timeIn && ! $timeOut && $scheduledEnd && isset($requiredMinutes) && $requiredMinutes > 0 && $status !== 'halfday') {
                            $nowTz = Carbon::now($attendanceTz);
                            $todayDate = $nowTz->toDateString();
                            $pastShiftEnd = $dateKey < $todayDate || ($dateKey === $todayDate && $nowTz->greaterThan($scheduledEnd));
                            if ($pastShiftEnd) {
                                // No paired clock-out → no countable rendered shift minutes toward the day's requirement.
                                $actualRenderedTowardRequired = ($workedMinutes !== null && $workedMinutes > 0) ? (int) $workedMinutes : 0;
                                $missingMinutes = max(0, $requiredMinutes - $actualRenderedTowardRequired);
                                $status = 'incomplete';
                                if ($missingMinutes > 0) {
                                    $dayUndertimeCount = 1;
                                    $dayUndertimeMinutes = $missingMinutes;
                                }
                                $dayAbsent = 0;
                                // Final day label is Incomplete, not Late — mirrors employee summary rollups.
                                $dayLate = 0;
                                $dayLateMinutes = 0;
                                $dayLateCountForDept = 0;
                            }
                        }
                    }
                }

                if ($hasTimeIn && $status === '—') {
                    // Only treat punches as presence on scheduled workdays.
                    if (is_array($todaySchedule) && ! empty($todaySchedule['in'])) {
                        $status = 'present';
                    }
                }

                if ($status !== 'absent' && $status !== '—' && $status !== 'leave' && $todaySchedule && ! empty($todaySchedule['in'])) {
                    $dayPresentIncrement = 1;
                }

                // Apply status filter at the day level: if a specific status is selected,
                // only days matching that status contribute to any aggregates.
                // Compare in lowercase so "Late" and "late" both match.
                if ($statusFilter && strtolower((string) $status) !== $statusFilter) {
                    $cursor->addDay();

                    continue;
                }

                if ($dayLeave) {
                    $metrics['leave_days'] += $dayLeave;
                }

                if ($dayAbsent) {
                    $metrics['absent_count'] += $dayAbsent;
                }

                if ($dayHalfday) {
                    $metrics['halfday_count'] += $dayHalfday;
                }

                if ($dayLate) {
                    $metrics['late_count'] += $dayLate;
                    $metrics['late_minutes'] += $dayLateMinutes;
                    $departmentAggregates[$deptKey]['late_counts_by_employee'][$employee->id] =
                        ($departmentAggregates[$deptKey]['late_counts_by_employee'][$employee->id] ?? 0) + $dayLateCountForDept;
                }

                if ($dayUndertimeCount) {
                    $metrics['undertime_count'] += $dayUndertimeCount;
                    $metrics['undertime_minutes'] += $dayUndertimeMinutes;
                }

                if ($dayOvertimeCount) {
                    $metrics['overtime_count'] += $dayOvertimeCount;
                    $metrics['overtime_minutes'] += $dayOvertimeMinutes;
                }

                if ($dayScheduledIncrement) {
                    $departmentAggregates[$deptKey]['scheduled_days'] += $dayScheduledIncrement;
                }

                if ($dayPresentIncrement) {
                    $departmentAggregates[$deptKey]['present_days'] += $dayPresentIncrement;
                }

                // Employee-facing summary collapses incomplete to "present" for counts; mirrors that here so monthly reports
                // agree with Employee Attendance.
                if ($status === 'present' || $status === 'incomplete') {
                    $metrics['present_count']++;
                }

                if ($workedMinutes !== null) {
                    $metrics['total_worked_minutes'] += $workedMinutes;
                    $departmentAggregates[$deptKey]['total_worked_minutes'] += $workedMinutes;
                } elseif ($isOnLeave && $leaveTypeOnDate === 'undertime' && $todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                    // For undertime leave, keep computing worked minutes from schedule minus approved undertime.
                    // Half-day leave no longer auto-credits any fixed hours; it must rely on actual attendance logs.
                    $scheduledMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);
                    if ($scheduledMinutes > 0) {
                        $utTime = $leaveOnDate['undertime_time'] ?? null;
                        $computed = $utTime
                            ? $this->computeUndertimeMinutesFromEarlyOut($dateKey, $todaySchedule, (string) $utTime, $attendanceTz)
                            : null;
                        if ($computed !== null) {
                            $metrics['total_worked_minutes'] += max(0, $scheduledMinutes - $computed);
                            $departmentAggregates[$deptKey]['total_worked_minutes'] += max(0, $scheduledMinutes - $computed);
                        } else {
                            // If undertime time is missing, keep scheduled minutes as baseline instead of guessing a fractional value.
                            $metrics['total_worked_minutes'] += $scheduledMinutes;
                            $departmentAggregates[$deptKey]['total_worked_minutes'] += $scheduledMinutes;
                        }
                    }
                }

                $cursor->addDay();
            }

            $metrics['total_hours'] = $metrics['total_worked_minutes'] > 0
                ? round($metrics['total_worked_minutes'] / 60, 2)
                : 0;
            $metrics['undertime_hours'] = $metrics['undertime_minutes'] > 0
                ? round($metrics['undertime_minutes'] / 60, 2)
                : 0;
            $metrics['overtime_hours'] = $metrics['overtime_minutes'] > 0
                ? round($metrics['overtime_minutes'] / 60, 2)
                : 0;

            $results[] = $metrics;
        }

        // Build department-level summary with attendance rate and most late employees.
        $departmentSummary = [];
        foreach ($departmentAggregates as $dept => $agg) {
            $attendanceRate = 0.0;
            if ($agg['scheduled_days'] > 0) {
                $attendanceRate = $agg['present_days'] / $agg['scheduled_days'];
            }

            $lateCounts = $agg['late_counts_by_employee'];
            arsort($lateCounts);
            $topLate = [];
            foreach (array_slice($lateCounts, 0, 3, true) as $empId => $lateCount) {
                /** @var User|null $emp */
                $emp = $employees->firstWhere('id', $empId);
                if (! $emp) {
                    continue;
                }
                $topLate[] = [
                    'employee_id' => $emp->id,
                    'employee_name' => $emp->name,
                    'late_count' => $lateCount,
                ];
            }

            $departmentSummary[] = [
                'department' => $agg['department'],
                'attendance_rate' => $attendanceRate,
                'attendance_rate_percent' => round($attendanceRate * 100, 2),
                'total_hours' => $agg['total_worked_minutes'] > 0 ? round($agg['total_worked_minutes'] / 60, 2) : 0,
                'most_late_employees' => $topLate,
            ];
        }

        // Response dates always YYYY-MM-DD (toDateString()) for consistent filtering with frontend.
        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'employees' => $results,
            'departments' => $departmentSummary,
        ]);
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
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,incomplete,all'],
            'leave_type' => ['nullable', 'string', 'max:50'],
            'overtime_status' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'], // ignored — fixed DETAILED_ROWS_PER_PAGE
            'search' => ['nullable', 'string', 'max:200'],
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
        $queryEmployeeId = $request->query('employee_id');
        if ($queryEmployeeId !== null && $queryEmployeeId !== '') {
            $validated['employee_id'] = (int) $queryEmployeeId;
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
        if ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 31) {
            return response()->json([
                'message' => 'Date range cannot exceed 31 days for detailed report.',
            ], 422);
        }

        $attendanceTz = $attendanceTzForValidation;

        // Apply both department AND employee filters together (AND condition).
        // When both are set: only the selected employee in the selected department is included.
        $requestedEmployeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        $employeesQuery = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);

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

        if ($requestedEmployeeId > 0) {
            $employeesQuery->where('id', $requestedEmployeeId);
        }

        if ($isEmployeeSelfRoute) {
            $employeesQuery->where('id', $request->user()->id);
        } else {
            $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeesQuery);
        }

        /** @var \Illuminate\Support\Collection<int, User> $employees */
        $employees = $employeesQuery
            ->orderBy('name')
            ->with([
                'workingSchedule',
                'companyHeadships:id,name,company_head_id',
                'company:id,name',
                'branch:id,company_id',
                'branch.company:id,name',
                'departmentRelation:id,name,branch_id',
                'departmentRelation.branch:id,company_id',
                'departmentRelation.branch.company:id,name',
            ])
            ->get();

        $viewer = $request->user();

        // Pre-compute company names and IDs for all employees.
        $detailedEmployeeCompanyNames = [];
        $detailedEmployeeCompanyIds = [];
        foreach ($employees as $emp) {
            $co = $emp->companyHeadships->first() ?? $emp->company ?? $emp->branch?->company ?? $emp->departmentRelation?->branch?->company;
            $detailedEmployeeCompanyNames[$emp->id] = $co?->name ?? null;
            $detailedEmployeeCompanyIds[$emp->id] = $co?->id ?? null;
        }

        if ($employees->isEmpty()) {
            return response()->json([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'rows' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => self::DETAILED_ROWS_PER_PAGE,
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
            ->whereIn('user_id', $userIds)
            // Use an inclusive whereBetween on verified_at (UTC in DB) so
            // that logs are strictly limited to the requested date window.
            ->whereBetween('verified_at', [$fromUtc, $toUtc]);

        if (! empty($validated['employee_id'])) {
            $logsQuery->where('user_id', $validated['employee_id']);
        }

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

        // Preload approved corrections so manual attendance entries appear in detailed rows.
        $correctionsDetailed = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('approved', true)
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        $correctionsMeta = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        // Leaves by user/date with type + status + duration (all statuses).
        $leavesQuery = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString());

        if (! empty($validated['employee_id'])) {
            $leavesQuery->where('user_id', $validated['employee_id']);
        }

        $leaves = $leavesQuery->get();

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

        $overtimes = $overtimesQuery->get();

        /** @var array<int, array<string, Overtime>> $overtimeByUserDate */
        $overtimeByUserDate = [];
        foreach ($overtimes as $ot) {
            $overtimeByUserDate[$ot->user_id][$ot->date->toDateString()] = $ot;
        }

        // Pre-compute premium pay (ND, OT) per employee per date for detailed rows.
        $premiumReport = app(PremiumReportService::class);
        $premiumByEmployeeDate = [];
        foreach ($employees as $emp) {
            $result = $premiumReport->computeForEmployee($emp, $from->copy(), $to->copy());
            foreach ($result['days'] ?? [] as $day) {
                $premiumByEmployeeDate[$emp->id][$day['date']] = $day;
            }
        }

        $rows = [];

        /** @var User $employee */
        foreach ($employees as $employee) {
            $effectiveSchedule = $this->resolveEffectiveSchedule($employee);

            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
                $dateKey = $cursor->toDateString();

                $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;

                /** @var Collection<int, AttendanceLog>|null $dayLogs */
                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? null;

                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                // Overlay approved correction: overrides log times and recalculates worked minutes
                // deducting the schedule's break period (e.g. 08:00–17:00 = 8 h net, not 9 h raw).
                $correctionKey = $employee->id.'|'.$dateKey;
                $correctionD = $correctionsDetailed->get($correctionKey)?->first();
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

                $approvedOtForDetailedRow = $overtimeByUserDate[$employee->id][$dateKey] ?? null;
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

                $leaveInfo = $leaveByUserDate[$employee->id][$dateKey] ?? null;
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
                            $todayDate = now($attendanceTz)->toDateString();
                            $isFuture = $dateKey > $todayDate;
                            if (! $isFuture) {
                                $isToday = $dateKey === $todayDate;
                                $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now($attendanceTz));
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
                            $nowTzIncomplete = Carbon::now($attendanceTz);
                            $todayDateIncomplete = $nowTzIncomplete->toDateString();
                            $pastShiftEndIncomplete = $dateKey < $todayDateIncomplete || ($dateKey === $todayDateIncomplete && $nowTzIncomplete->greaterThan($scheduledEnd));
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
                            $nowTz = Carbon::now($attendanceTz);
                            $todayDate = $nowTz->toDateString();
                            $pastShiftEnd = $dateKey < $todayDate || ($dateKey === $todayDate && $nowTz->greaterThan($scheduledEnd));
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

                $correctionMeta = $correctionsMeta->get($correctionKey)?->first();
                $todayDateRow = now($attendanceTz)->toDateString();
                $isFutureRow = $dateKey > $todayDateRow;
                $qualifiedRow = $this->presenceDisplay->qualify(
                    $dateKey,
                    $todayDateRow,
                    Carbon::now($attendanceTz),
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

                $ot = $overtimeByUserDate[$employee->id][$dateKey] ?? null;

                // Apply filters: status, leave type, overtime status.
                // Status comparison uses lowercase so "Late" and "late" both match; late_minutes is numeric and already set above.
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

                if ($leaveTypeFilter !== null && $leaveTypeFilter !== '' && $leaveTypeFilter !== 'all') {
                    if (! $leaveInfo || $leaveInfo['type'] !== $leaveTypeFilter) {
                        $cursor->addDay();

                        continue;
                    }
                }

                if ($overtimeStatusFilter !== null && $overtimeStatusFilter !== '' && $overtimeStatusFilter !== 'all') {
                    if (! $ot || $ot->status !== $overtimeStatusFilter) {
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
                // — payslip-parity (paid regular + paid OT). Avoids schedule caps / synthetic undertime-leave hours.
                $payrollImpactMinutes = 0;
                $payrollImpactHours = 0.0;
                if ($todaySchedule && ! empty($todaySchedule['in'])) {
                    $tInPayroll = $effectiveTimeIn
                        ? ($effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn))
                        : null;
                    $tOutPayroll = $effectiveTimeOut
                        ? ($effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut))
                        : null;
                    $payrollImpactMinutes = $this->payrollComputation->payrollImpactMinutesForAttendanceDisplay(
                        $employee,
                        $dateKey,
                        $tInPayroll,
                        $tOutPayroll,
                        $attendanceTz
                    );
                    $payrollImpactHours = round($payrollImpactMinutes / 60, 2);
                }
                $clockVal = $clockOtHours ?? 0.0;
                $approvedOtHours = $approvedFromFiling;
                $unapprovedOtHours = max(0.0, round($clockVal - min($approvedOtHours, $clockVal), 2));
                if ($clockVal <= 0.0001 && $approvedOtHours > 0) {
                    $unapprovedOtHours = 0.0;
                }

                $rows[] = array_merge([
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'department' => $employee->departmentRelation?->name ?? $employee->department,
                    'company_id' => $detailedEmployeeCompanyIds[$employee->id] ?? null,
                    'company_name' => $detailedEmployeeCompanyNames[$employee->id] ?? null,
                    'profile_image' => $employee->profile_image_url,
                    'date' => $dateKey,
                    'schedule' => $scheduleLabel,
                    'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                    'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
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
                    'night_hours' => $premiumByEmployeeDate[$employee->id][$dateKey]['night_hours'] ?? null,
                    'night_differential_pay' => $premiumByEmployeeDate[$employee->id][$dateKey]['night_differential_pay'] ?? null,
                    'overtime_hours' => $premiumByEmployeeDate[$employee->id][$dateKey]['overtime_hours'] ?? null,
                    'overtime_pay' => $premiumByEmployeeDate[$employee->id][$dateKey]['overtime_pay'] ?? null,
                    'total_premium_pay' => isset($premiumByEmployeeDate[$employee->id][$dateKey])
                        ? (($premiumByEmployeeDate[$employee->id][$dateKey]['overtime_pay'] ?? 0) + ($premiumByEmployeeDate[$employee->id][$dateKey]['night_differential_pay'] ?? 0))
                        : null,
                    'work_condition' => $this->ruleLabelForRow($premiumByEmployeeDate[$employee->id][$dateKey] ?? null, 0),
                    'pay_rule' => $this->ruleLabelForRow($premiumByEmployeeDate[$employee->id][$dateKey] ?? null, 1),
                    'multiplier' => $this->ruleLabelForRow($premiumByEmployeeDate[$employee->id][$dateKey] ?? null, 2),
                ], $this->employmentFieldsForReport($employee, $viewer));

                $cursor->addDay();
            }
        }

        // Ensure only one detailed row per employee per date, even if
        // upstream data (e.g. joins or duplicated logs) would otherwise
        // produce duplicates.
        $rows = collect($rows)
            ->unique(fn (array $row): string => $row['employee_id'].'|'.$row['date'])
            ->values()
            ->all();

        // When a specific employee was requested, return only that employee's rows (strict filter).
        if ($requestedEmployeeId > 0) {
            $rows = array_values(array_filter($rows, function (array $row) use ($requestedEmployeeId) {
                return (int) ($row['employee_id'] ?? 0) === $requestedEmployeeId;
            }));
        }

        $rows = $this->applyDetailedReportSearchFilter($rows, $validated['search'] ?? null);

        // Response dates always YYYY-MM-DD so the frontend filter and report period match.
        $perPage = self::DETAILED_ROWS_PER_PAGE;
        $page = max(1, (int) ($validated['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);

        $response = [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $pagedRows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];

        Log::info('Detailed attendance report prepared', [
            'actor_user_id' => (int) $request->user()->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'employee_count' => count($userIds),
            'rows_count' => count($response['rows']),
            'paginated' => true,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($response);
    }

    /**
     * Narrow detailed rows before pagination (substring match employee, department, company).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function applyDetailedReportSearchFilter(array $rows, ?string $search): array
    {
        $q = $search !== null ? strtolower(trim($search)) : '';
        if ($q === '') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($q): bool {
            $haystack = strtolower(implode(' ', [
                (string) ($row['employee_name'] ?? ''),
                (string) ($row['department'] ?? ''),
                (string) ($row['company_name'] ?? ''),
                (string) ($row['date'] ?? ''),
            ]));

            return str_contains($haystack, $q);
        }));
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
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
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
