<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendancePresenceDisplayService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\PayrollComputationService;
use App\Services\HrRoleResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AttendanceMonitoringController extends Controller
{
    /** Fixed page size for admin attendance list (pagination + payloads). Client cannot override. */
    private const ROWS_PER_PAGE = 20;

    /** Skip payslip-parity payroll impact during bulk row materialization; hydrate only for paginated rows (index). */
    private const SLOW_MONITORING_MS = 500;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollComputationService $payrollComputation,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    private function dayNameForDate(string|\DateTimeInterface $date): string
    {
        return Carbon::parse($date)->timezone($this->attendanceTimezone())->format('l');
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
     * Build a per-day schedule map from a WorkingSchedule row (same shape used in ReportsController).
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $workingSchedule): ?array
    {
        if (! $workingSchedule || ! $workingSchedule->time_in || ! $workingSchedule->time_out) {
            return null;
        }

        $dayConfig = [];
        foreach (self::DAY_KEYS as $key) {
            if ($key === 'sun') {
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
     * Resolve effective per-day schedule with the same precedence as Reports:
     * JSON schedule first, then working_schedule fallback.
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

    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'date' => ['nullable', 'date'], // legacy single-date filter
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,undertime,incomplete'],
            'premium_type' => ['nullable', 'string', 'in:ordinary,rest_day,special_holiday,regular_holiday,special_holiday_rest_day,regular_holiday_rest_day'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'], // ignored — fixed at ROWS_PER_PAGE
            'search' => ['nullable', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:255'],
            'pending_attention' => ['sometimes', 'boolean'],
        ]);

        $computeStart = microtime(true);
        $computed = $this->computeMonitoringRows($request, $validated, includePayrollImpact: false);
        $computeMs = (int) round((microtime(true) - $computeStart) * 1000);
        if ($computed instanceof JsonResponse) {
            return $computed;
        }

        $employeesById = $computed['employees_by_id'] ?? null;
        unset($computed['employees_by_id']);

        $filterStart = microtime(true);
        $rows = $computed['rows'];
        $rows = $this->applyAttendanceMonitoringFilters($rows, $validated);
        $filterMs = (int) round((microtime(true) - $filterStart) * 1000);

        $totalsStart = microtime(true);
        $totals = $this->attendanceMonitoringTotals($rows);
        $totalsMs = (int) round((microtime(true) - $totalsStart) * 1000);

        $perPage = self::ROWS_PER_PAGE;
        $page = max(1, (int) ($validated['page'] ?? 1));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $pageRows = array_slice($rows, $offset, $perPage);

        $hydrateStart = microtime(true);
        $tz = $this->attendanceTimezone();
        if ($employeesById !== null && $employeesById->isNotEmpty()) {
            $this->hydratePayrollImpactForMonitoringRows($employeesById, $pageRows, $tz);
        }
        $hydrateMs = (int) round((microtime(true) - $hydrateStart) * 1000);

        $response = [
            'from_date' => $computed['from_date'],
            'to_date' => $computed['to_date'],
            'rows' => $pageRows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'totals' => $totals,
            ],
        ];

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = [
            'actor_user_id' => (int) $request->user()->id,
            'from_date' => (string) $computed['from_date'],
            'to_date' => (string) $computed['to_date'],
            'matched_rows' => $total,
            'page_rows' => count($response['rows']),
            'compute_rows_ms' => $computeMs,
            'filter_ms' => $filterMs,
            'totals_ms' => $totalsMs,
            'hydrate_payroll_ms' => $hydrateMs,
            'total_response_ms' => $totalMs,
        ];
        Log::info('Attendance monitoring response prepared', $payload);
        if ($totalMs >= self::SLOW_MONITORING_MS) {
            Log::warning('Slow attendance monitoring index', $payload);
        }

        return response()->json($response);
    }

    /**
     * Export attendance monitoring report for the same filters/date range as {@see index()}.
     *
     * Query params: same as index + `format=csv|json` (default csv).
     * - csv: streams a downloadable CSV with a stable column set (aligned with Attendance UI + Reports exports).
     * - json: returns { from_date, to_date, rows } so the frontend can build Excel via XLSX.
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,undertime,incomplete'],
            'premium_type' => ['nullable', 'string', 'in:ordinary,rest_day,special_holiday,regular_holiday,special_holiday_rest_day,regular_holiday_rest_day'],
            'format' => ['nullable', 'string', 'in:csv,json'],
            'search' => ['nullable', 'string', 'max:200'],
            'company' => ['nullable', 'string', 'max:255'],
            'pending_attention' => ['sometimes', 'boolean'],
        ]);

        $computed = $this->computeMonitoringRows($request, $validated, includePayrollImpact: true);
        if ($computed instanceof JsonResponse) {
            return $computed;
        }

        $filteredRows = $this->applyAttendanceMonitoringFilters($computed['rows'], $validated);
        $computed['rows'] = $filteredRows;

        $format = (string) ($validated['format'] ?? 'csv');
        if ($format === 'json') {
            return response()->json($computed);
        }

        $from = (string) ($computed['from_date'] ?? '');
        $to = (string) ($computed['to_date'] ?? '');
        $file = 'attendance-export-'.$from.'-to-'.$to.'.csv';

        $rows = is_array($computed['rows'] ?? null) ? $computed['rows'] : [];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Employee ID',
                'Employee Name',
                'Department',
                'Company',
                'Date',
                'Day',
                'Time In',
                'Time Out',
                'Status',
                'Overtime Status',
                'Scheduled In',
                'Scheduled Out',
                'Scheduled Regular Hours',
                'Total Worked Hours',
                'Payroll Impact (hrs)',
                'Overtime Hours (Approved)',
                'Unapproved OT (hrs)',
                'Overtime Hours (Rendered)',
                'Night Differential Hours',
                'Premium Type',
                'Has Correction',
                'Correction Approved',
                'Correction Remarks',
            ]);

            foreach ($rows as $r) {
                if (! is_array($r)) {
                    continue;
                }
                fputcsv($out, [
                    $r['employee_id'] ?? null,
                    $r['employee_name'] ?? null,
                    $r['department'] ?? null,
                    $r['company_name'] ?? null,
                    $r['date'] ?? null,
                    $r['day_name'] ?? (! empty($r['date']) ? $this->dayNameForDate((string) $r['date']) : null),
                    $r['formatted_time_in'] ?? ($r['time_in'] ?? null),
                    $r['formatted_time_out'] ?? ($r['time_out'] ?? null),
                    $r['status'] ?? null,
                    $r['overtime_status'] ?? null,
                    $r['schedule_in'] ?? null,
                    $r['schedule_out'] ?? null,
                    $r['scheduled_regular_hours'] ?? null,
                    $r['total_rendered_hours'] ?? ($r['total_hours'] ?? null),
                    $r['payroll_impact_hours'] ?? null,
                    $r['approved_overtime_hours'] ?? ($r['overtime_hours'] ?? null),
                    $r['unapproved_overtime_hours'] ?? null,
                    $r['rendered_overtime_hours'] ?? null,
                    $r['night_hours'] ?? null,
                    $r['premium_type'] ?? null,
                    ! empty($r['has_correction']) ? 'Yes' : 'No',
                    ! empty($r['correction_approved']) ? 'Yes' : 'No',
                    $r['correction_remarks'] ?? null,
                ]);
            }

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Shared attendance monitoring computation used by index + export.
     *
     * @param  array<string,mixed>  $validated
     * @return array{from_date:string,to_date:string,rows:list<array<string,mixed>>,employees_by_id?:\Illuminate\Support\Collection<int, User>}|JsonResponse
     */
    private function computeMonitoringRows(Request $request, array $validated, bool $includePayrollImpact = true): array|JsonResponse
    {
        $tz = $this->attendanceTimezone();

        // Determine range: prefer from/to if provided; fall back to single date or today (in attendance tz).
        if (! empty($validated['from_date']) || ! empty($validated['to_date'])) {
            $fromDateStr = $validated['from_date'] ?? $validated['to_date'];
            $toDateStr = $validated['to_date'] ?? $validated['from_date'];
            if ($toDateStr < $fromDateStr) {
                [$fromDateStr, $toDateStr] = [$toDateStr, $fromDateStr];
            }
            $from = Carbon::parse($fromDateStr.' 00:00:00', $tz)->startOfDay();
            $to = Carbon::parse($toDateStr.' 23:59:59', $tz)->endOfDay();
        } else {
            $todayStr = isset($validated['date'])
                ? Carbon::parse($validated['date'])->timezone($tz)->toDateString()
                : Carbon::now($tz)->toDateString();
            $from = Carbon::parse($todayStr.' 00:00:00', $tz)->startOfDay();
            $to = Carbon::parse($todayStr.' 23:59:59', $tz)->endOfDay();
        }

        if ($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) > 31) {
            return response()->json([
                'message' => 'Date range cannot exceed 31 days for attendance monitoring.',
            ], 422);
        }

        // UTC bounds for DB query (timestamps are stored in UTC).
        $fromUtc = $from->copy()->setTimezone('UTC');
        $toUtc = $to->copy()->setTimezone('UTC');

        $employeesQuery = User::query()->activeRoster();

        if (! empty($validated['department'])) {
            $deptName = $validated['department'];
            $employeesQuery->where(function ($q) use ($deptName) {
                $q->where('department', $deptName)
                    ->orWhereHas('departmentRelation', fn ($d) => $d->where('name', $deptName));
            });
        }

        if (! empty($validated['employee_id'])) {
            $employeesQuery->where('id', $validated['employee_id']);
        }

        $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeesQuery);

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

        $rows = [];

        // Pre-compute company names for all employees (once, not per-day).
        $employeeCompanyNames = [];
        foreach ($employees as $emp) {
            $co = $emp->companyHeadships->first() ?? $emp->company ?? $emp->branch?->company ?? $emp->departmentRelation?->branch?->company;
            $employeeCompanyNames[$emp->id] = $co?->name ?? null;
        }

        $userIds = $employees->pluck('id')->all();

        $logs = AttendanceLog::query()
            ->select([
                'id',
                'user_id',
                'type',
                'verified_at',
                'created_at',
                'night_hours',
                'premium_type',
                'calculated_pay_factor',
            ])
            ->whereIn('user_id', $userIds)
            ->whereBetween('verified_at', [$fromUtc, $toUtc])
            ->orderBy('verified_at')
            ->get();

        $logsByUserDate = [];
        foreach ($logs as $log) {
            $stamp = $log->verified_at ?? $log->created_at;
            if (! $stamp) {
                continue;
            }
            $dateKey = $stamp->copy()->setTimezone($tz)->toDateString();
            $logsByUserDate[$log->user_id][$dateKey] = ($logsByUserDate[$log->user_id][$dateKey] ?? collect())->push($log);
        }

        $corrections = AttendanceCorrection::query()
            ->select([
                'id',
                'user_id',
                'date',
                'time_in',
                'time_out',
                'remarks',
                'approved',
                'pending_approval',
                'reason_code',
                'filed_at',
            ])
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        $approvedOvertimesByUserDate = Overtime::query()
            ->select([
                'id',
                'user_id',
                'date',
                'computed_hours',
                'expected_end_time',
                'status',
            ])
            ->whereIn('user_id', $userIds)
            ->where('status', Overtime::STATUS_APPROVED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($o) => $o->user_id.'|'.$o->date->toDateString());

        $approvedLeaves = LeaveRequest::query()
            ->select([
                'id',
                'user_id',
                'type',
                'half_type',
                'start_date',
                'end_date',
                'status',
            ])
            ->whereIn('user_id', $userIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();

        $leaveDatesByUser = [];
        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date->copy()->max($from);
            $leaveEnd = $leave->end_date->copy()->min($to);
            $cursorLeave = $leaveStart->copy();
            while ($cursorLeave->lessThanOrEqualTo($leaveEnd)) {
                $leaveDatesByUser[$leave->user_id][$cursorLeave->toDateString()] = [
                    'type' => $leave->type,
                    'half_type' => $leave->half_type,
                ];
                $cursorLeave->addDay();
            }
        }

        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $dateKey = $cursor->toDateString();

            foreach ($employees as $employee) {
                $effectiveSchedule = $this->resolveEffectiveSchedule($employee);
                $todaySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey])
                    ? $effectiveSchedule[$dayKey]
                    : null;

                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? collect();
                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                $correctionKey = $employee->id.'|'.$dateKey;
                $correction = $corrections->get($correctionKey)?->first();

                $effectiveTimeIn = $timeIn;
                $effectiveTimeOut = $timeOut;
                $effectiveWorkedMinutes = $workedMinutes;
                $remarks = null;
                $approved = false;

                // Rest day / not scheduled: never surface punches in attendance monitoring.
                // Sundays (or any rest day in Schedule module) must show no time in/out and no "present/absent" status.
                $isWorkday = is_array($todaySchedule) && ! empty($todaySchedule['in']);
                if (! $isWorkday) {
                    $effectiveTimeIn = null;
                    $effectiveTimeOut = null;
                    $effectiveWorkedMinutes = null;
                    $virtualClockOutFromOt = false;
                }

                if ($correction && $correction->approved) {
                    if ($correction->time_in) {
                        $effectiveTimeIn = $correction->time_in;
                    }
                    if ($correction->time_out) {
                        $effectiveTimeOut = $correction->time_out;
                    }
                    if ($correction->time_in && $correction->time_out) {
                        $effectiveWorkedMinutes = $todaySchedule
                            ? AttendanceStatusService::getNetWorkedMinutes(
                                $correction->time_in,
                                $correction->time_out,
                                $todaySchedule,
                                $dateKey,
                                $tz
                            )
                            : (int) $correction->time_in->diffInMinutes($correction->time_out);
                    }
                    $remarks = $correction->remarks;
                    $approved = true;
                }

                $approvedOvertimeForRow = $approvedOvertimesByUserDate->get($employee->id.'|'.$dateKey);
                $virtualClockOutFromOt = false;
                if ($effectiveTimeOut === null && $approvedOvertimeForRow) {
                    $resolvedOut = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                        $approvedOvertimeForRow,
                        $dateKey,
                        is_array($todaySchedule) ? $todaySchedule : null,
                        $tz
                    );
                    if ($resolvedOut !== null) {
                        $effectiveTimeOut = $resolvedOut;
                        $virtualClockOutFromOt = true;
                    }
                }

                if (! ($correction && $correction->approved && $correction->time_in && $correction->time_out)) {
                    if ($todaySchedule && $effectiveTimeIn && $effectiveTimeOut) {
                        $tIn = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                        $tOut = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                        $effectiveWorkedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                            $tIn, $tOut, $todaySchedule, $dateKey, $tz
                        );
                    }
                }

                $status = '—';
                $lateLabel = null;
                $lateMinutes = null;
                $undertimeMinutes = null;
                $overtimeMinutes = null;

                $leaveInfo = $leaveDatesByUser[$employee->id][$dateKey] ?? null;
                $isOnLeave = $leaveInfo !== null;
                $leaveType = $leaveInfo['type'] ?? null;
                $isApprovedUndertime = $isOnLeave && $leaveType === 'undertime';

                if ($isOnLeave && ! $isApprovedUndertime) {
                    $status = $leaveType === 'half_day' ? 'halfday' : 'leave';
                } elseif ($todaySchedule && ! empty($todaySchedule['in'])) {
                    if (! $effectiveTimeIn) {
                        if ($effectiveTimeOut) {
                            $status = 'present';
                        } else {
                            $tzLocal = $this->attendanceTimezone();
                            $isToday = $dateKey === Carbon::now($tzLocal)->toDateString();
                            $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, Carbon::now($tzLocal));
                            $status = $pastCutoff ? 'absent' : '—';
                        }
                    } else {
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $tz);
                        $timeInCarbon = $effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn
                            : Carbon::parse($effectiveTimeIn);
                        $clockInResult = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $timeInCarbon);

                        $isHalfDay = $clockInResult['status'] === 'half_day';
                        $isLate = $clockInResult['status'] === 'late';
                        if ($isLate) {
                            $lateLabel = $clockInResult['late_label'];
                            $lateMinutes = $clockInResult['late_minutes'] ?? null;
                        }

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
                                $tz,
                                $earlyTimeout
                            );

                            $overtimeBuffer = isset($todaySchedule['overtime_buffer_minutes'])
                                ? (int) $todaySchedule['overtime_buffer_minutes']
                                : (int) config('attendance.overtime_buffer_minutes', 15);

                            $postShiftOt = 0;
                            $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                            if ($outCarbon->greaterThan($otStart)) {
                                $postShiftOt = (int) $otStart->diffInMinutes($outCarbon);
                            }

                            $preShiftOt = 0;
                            $scheduledStart = AttendanceStatusService::getScheduledStartForDate($dateKey, $todaySchedule, $tz);
                            if ($scheduledStart && $inCarbon->lessThan($scheduledStart)) {
                                $preShiftOt = (int) $inCarbon->diffInMinutes($scheduledStart);
                            }

                            $overtimeMinutes = $preShiftOt + $postShiftOt;
                        }

                        $isUndertime = $undertimeMinutes !== null && $undertimeMinutes > 0;
                        if ($isHalfDay) {
                            $status = 'halfday';
                        } elseif ($isUndertime) {
                            $status = 'undertime';
                        } elseif ($isLate) {
                            $status = 'late';
                        } else {
                            $status = 'present';
                        }
                    }
                } elseif ($effectiveTimeIn || $effectiveTimeOut) {
                    // If not scheduled today, punches are ignored (rest day).
                    // Keep status as "—" instead of fabricating Present.
                    if ($isWorkday) {
                        $status = 'present';
                    }
                }

                if ($effectiveTimeIn && ! $effectiveTimeOut) {
                    $tzNow = $this->attendanceTimezone();
                    $todayTz = Carbon::now($tzNow)->toDateString();
                    $pastShiftEnd = false;
                    if ($todaySchedule && ! empty($todaySchedule['out'])) {
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $tzNow);
                        if ($scheduledEnd instanceof Carbon) {
                            $nowTz = Carbon::now($tzNow);
                            $pastShiftEnd = $dateKey < $todayTz || ($dateKey === $todayTz && $nowTz->greaterThan($scheduledEnd));
                        }
                    }
                    if (! $pastShiftEnd && $dateKey < $todayTz) {
                        $pastShiftEnd = true;
                    }
                    if ($pastShiftEnd) {
                        $status = 'incomplete';
                    }
                }

                $todayTzStr = Carbon::now($tz)->toDateString();
                $isFuture = $dateKey > $todayTzStr;
                $qualified = $this->presenceDisplay->qualify(
                    $dateKey,
                    $todayTzStr,
                    Carbon::now($tz),
                    is_array($todaySchedule) ? $todaySchedule : null,
                    $status,
                    $effectiveTimeIn,
                    $effectiveTimeOut,
                    $correction,
                    $isFuture,
                );
                $status = $qualified['status'];
                $presenceLabel = $qualified['presence_label'];
                $presenceIssue = $qualified['presence_issue'];

                if (! empty($validated['status'])) {
                    $want = $validated['status'];
                    if ($want === 'incomplete') {
                        if (! in_array($presenceIssue, ['incomplete_pair', 'correction_pending'], true)) {
                            continue;
                        }
                    } elseif ($status !== $want) {
                        continue;
                    }
                }

                $clockOutLog = $dayLogs ? $dayLogs->first(fn ($l) => $l->type === AttendanceLog::TYPE_CLOCK_OUT) : null;
                if (! empty($validated['premium_type']) && ($clockOutLog?->premium_type ?? '') !== $validated['premium_type']) {
                    continue;
                }

                $hasClockOut = $effectiveTimeOut !== null;

                $approvedOtHours = $approvedOvertimeForRow ? (float) ($approvedOvertimeForRow->computed_hours ?? 0) : 0.0;
                // Reports-parity OT source:
                // derive rendered OT only from schedule-vs-clock minutes (pre-shift + post-shift buffer),
                // not AttendanceLog::overtime_hours snapshots.
                $otMinutesForRow = $hasClockOut ? ($overtimeMinutes ?? 0) : null;
                $renderedOvertimeHours = $otMinutesForRow !== null ? round($otMinutesForRow / 60, 2) : null;
                $approvedOvertimeHours = $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : null;
                // Match Reports detailed: clock OT vs approved filing hours.
                $clockOtHours = $otMinutesForRow !== null ? round($otMinutesForRow / 60, 2) : null;
                $clockVal = $clockOtHours ?? 0.0;
                $approvedFromFiling = $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : 0.0;
                $approvedOtAppliedHours = $clockVal > 0.0001
                    ? min($approvedFromFiling, $clockVal)
                    : ($virtualClockOutFromOt ? $approvedFromFiling : 0.0);
                $unapprovedOvertimeHours = max(0.0, round($clockVal - min($approvedFromFiling, $clockVal), 2));
                if ($clockVal <= 0.0001 && $approvedFromFiling > 0) {
                    $unapprovedOvertimeHours = 0.0;
                }
                $unapprovedOvertimeHours = $unapprovedOvertimeHours > 0.0001 ? round($unapprovedOvertimeHours, 2) : null;

                $scheduledRegularMinutes = null;
                if (is_array($todaySchedule) && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                    $scheduledRegularMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $tz);
                }
                // Payroll Impact (hrs): payslip-parity via PayrollComputationService (paid regular + paid OT),
                // not min(net-worked, schedule) + raw approved OT (pre-shift "total hours" skewed Impact).
                $payrollImpactMinutes = 0;
                if ($isWorkday && $includePayrollImpact) {
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
                        $tz
                    );
                }
                $payrollImpactHours = round($payrollImpactMinutes / 60, 2);

                $effectiveTimeOutDate = $effectiveTimeOut
                    ? ($effectiveTimeOut instanceof Carbon
                        ? $effectiveTimeOut->copy()->timezone($tz)->toDateString()
                        : Carbon::parse($effectiveTimeOut)->timezone($tz)->toDateString())
                    : null;
                $timeOutNextDay = $effectiveTimeOutDate && $effectiveTimeOutDate !== $dateKey;

                $scheduleIn = is_array($todaySchedule) && ! empty($todaySchedule['in']) ? (string) $todaySchedule['in'] : null;
                $scheduleOut = is_array($todaySchedule) && ! empty($todaySchedule['out']) ? (string) $todaySchedule['out'] : null;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'profile_image' => $employee->profile_image_url,
                    'department' => $employee->departmentRelation?->name ?? $employee->department,
                    'company_name' => $employeeCompanyNames[$employee->id] ?? null,
                    'date' => $dateKey,
                    'day_name' => $this->dayNameForDate($dateKey),
                    'schedule_in' => $scheduleIn,
                    'schedule_out' => $scheduleOut,
                    'time_in' => $effectiveTimeIn
                        ? ($effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn->copy()->timezone($tz)->format('H:i')
                            : Carbon::parse($effectiveTimeIn)->timezone($tz)->format('H:i'))
                        : null,
                    'formatted_time_in' => $this->formatTimeForDisplay($effectiveTimeIn),
                    'time_out' => $effectiveTimeOut
                        ? ($effectiveTimeOut instanceof Carbon
                            ? $effectiveTimeOut->copy()->timezone($tz)->format('H:i')
                            : Carbon::parse($effectiveTimeOut)->timezone($tz)->format('H:i'))
                        : null,
                    'formatted_time_out' => $this->formatTimeForDisplay($effectiveTimeOut),
                    'time_out_next_day' => $timeOutNextDay,
                    'scheduled_regular_hours' => $scheduledRegularMinutes !== null && $scheduledRegularMinutes > 0
                        ? round($scheduledRegularMinutes / 60, 2)
                        : null,
                    'total_rendered_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
                    'total_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
                    'status' => $status,
                    'late_label' => $lateLabel,
                    'late_minutes' => $lateMinutes,
                    'undertime_minutes' => $undertimeMinutes,
                    'overtime_minutes' => $otMinutesForRow,
                    'overtime_hours' => $hasClockOut && $approvedOtAppliedHours > 0.0001 ? round($approvedOtAppliedHours, 2) : null,
                    'rendered_overtime_hours' => $hasClockOut ? $renderedOvertimeHours : null,
                    'approved_overtime_hours' => $approvedOtAppliedHours > 0.0001 ? round($approvedOtAppliedHours, 2) : null,
                    'unapproved_overtime_hours' => $unapprovedOvertimeHours,
                    'overtime_status' => $this->normalizeOvertimeModuleStatusForDisplay($approvedOvertimeForRow),
                    'payroll_impact_minutes' => $payrollImpactMinutes,
                    'payroll_impact_hours' => $payrollImpactHours,
                    'night_hours' => $hasClockOut ? $clockOutLog?->night_hours : null,
                    'premium_type' => $hasClockOut ? $clockOutLog?->premium_type : null,
                    'premium_description' => $hasClockOut
                        ? AttendanceStatusService::getPremiumDescription(
                            $renderedOvertimeHours ?? ($virtualClockOutFromOt ? (float) ($approvedOvertimeForRow?->computed_hours ?? 0) : null),
                            $clockOutLog?->night_hours,
                            $clockOutLog?->premium_type
                        )
                        : null,
                    'calculated_pay_factor' => $hasClockOut ? $clockOutLog?->calculated_pay_factor : null,
                    'is_approved_undertime' => $isApprovedUndertime && $undertimeMinutes !== null && $undertimeMinutes > 0,
                    'has_correction' => (bool) $correction,
                    'correction_id' => $correction?->id,
                    'correction_approved' => $approved,
                    'correction_remarks' => $remarks,
                    'has_approved_overtime' => $approvedOtAppliedHours > 0.0001,
                    'approved_ot_end_time' => $approvedOvertimeForRow?->expected_end_time?->format('H:i'),
                    'effective_expected_out' => $approvedOvertimeForRow?->expected_end_time
                        ? $approvedOvertimeForRow->expected_end_time->format('H:i')
                        : $scheduleOut,
                    'virtual_time_out_from_ot' => $virtualClockOutFromOt,
                    'presence_label' => $presenceLabel,
                    'presence_issue' => $presenceIssue,
                ];
            }

            $cursor->addDay();
        }

        $viewerRole = $this->hrRoleResolver->resolveForApprovalSubject($request->user());
        if (in_array($viewerRole, [HrRole::DepartmentHead, HrRole::BranchHead], true)) {
            foreach ($rows as $i => $_) {
                unset($rows[$i]['company_name']);
            }
        }

        $result = [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $rows,
        ];

        if (! $includePayrollImpact) {
            $result['employees_by_id'] = $employees->keyBy('id');
        }

        return $result;
    }

    /**
     * Payslip-parity payroll impact is expensive ({@see PayrollComputationService::computeDayPayroll}).
     * Index loads hydrate only the visible page after filtering.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $employeesById
     * @param  list<array<string, mixed>>  $pageRows
     */
    private function hydratePayrollImpactForMonitoringRows(
        \Illuminate\Support\Collection $employeesById,
        array &$pageRows,
        string $tz
    ): void {
        foreach ($pageRows as &$r) {
            $scheduleIn = $r['schedule_in'] ?? null;
            $scheduleOut = $r['schedule_out'] ?? null;
            if (! $scheduleIn || ! $scheduleOut) {
                continue;
            }

            $employee = $employeesById->get((int) ($r['employee_id'] ?? 0));
            if (! $employee instanceof User) {
                continue;
            }

            $dateKey = (string) ($r['date'] ?? '');
            if ($dateKey === '') {
                continue;
            }

            $tInPayroll = null;
            if (! empty($r['time_in'])) {
                $tInPayroll = Carbon::parse($dateKey.' '.$r['time_in'], $tz);
            }

            $tOutPayroll = null;
            if (! empty($r['time_out'])) {
                $outDay = $dateKey;
                if (! empty($r['time_out_next_day'])) {
                    $outDay = Carbon::parse($dateKey.' 00:00:00', $tz)->addDay()->toDateString();
                }
                $tOutPayroll = Carbon::parse($outDay.' '.$r['time_out'], $tz);
            }

            $minutes = $this->payrollComputation->payrollImpactMinutesForAttendanceDisplay(
                $employee,
                $dateKey,
                $tInPayroll,
                $tOutPayroll,
                $tz
            );
            $r['payroll_impact_minutes'] = $minutes;
            $r['payroll_impact_hours'] = round($minutes / 60, 2);
        }
        unset($r);
    }

    /** Mirrors frontend {@see attendanceRecordUtils isPendingAttentionRow} for parity. */
    private function isPendingAttentionMonitoringRow(array $r): bool
    {
        if (($r['status'] ?? '') === 'incomplete') {
            return true;
        }
        if (($r['presence_issue'] ?? '') === 'correction_pending') {
            return true;
        }
        if (! empty($r['has_correction']) && empty($r['correction_approved']) && ! empty($r['correction_id'])) {
            return true;
        }

        return false;
    }

    private function attendanceMonitoringSearchHaystack(array $r): string
    {
        $ref = strtolower((string) ($r['employee_id'] ?? '').'|'.(string) ($r['date'] ?? ''));

        return strtolower(implode(' ', array_filter([
            $ref,
            (string) ($r['employee_name'] ?? ''),
            (string) ($r['department'] ?? ''),
            (string) ($r['company_name'] ?? ''),
            (string) ($r['date'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['presence_label'] ?? ''),
            (string) ($r['late_label'] ?? ''),
        ], fn ($x) => $x !== '')));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $validated
     * @return list<array<string,mixed>>
     */
    private function applyAttendanceMonitoringFilters(array $rows, array $validated): array
    {
        $company = isset($validated['company']) ? trim((string) $validated['company']) : '';
        if ($company !== '') {
            $rows = array_values(array_filter($rows, fn (array $r) => (($r['company_name'] ?? '')) === $company));
        }

        if (! empty($validated['pending_attention'])) {
            $rows = array_values(array_filter($rows, fn (array $r) => $this->isPendingAttentionMonitoringRow($r)));
        }

        $q = isset($validated['search']) ? strtolower(trim((string) $validated['search'])) : '';
        if ($q !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r) => str_contains($this->attendanceMonitoringSearchHaystack($r), $q)
            ));
        }

        return $rows;
    }

    /**
     * KPI rollups for the admin attendance page (computed on the filtered full list, before pagination).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{present_count:int,absent_count:int,late_count:int,leave_or_halfday_count:int,total_hours_rendered:float}
     */
    private function attendanceMonitoringTotals(array $rows): array
    {
        $present = 0;
        $absent = 0;
        $late = 0;
        $leaveHalf = 0;
        $totalHours = 0.0;

        foreach ($rows as $r) {
            $st = (string) ($r['status'] ?? '');
            if ($st === 'present') {
                $present++;
            } elseif ($st === 'absent') {
                $absent++;
            } elseif ($st === 'late') {
                $late++;
            } elseif ($st === 'leave' || $st === 'halfday') {
                $leaveHalf++;
            }

            $raw = $r['total_rendered_hours'] ?? $r['total_hours'] ?? 0;
            $totalHours += is_numeric($raw) ? (float) $raw : 0.0;
        }

        return [
            'present_count' => $present,
            'absent_count' => $absent,
            'late_count' => $late,
            'leave_or_halfday_count' => $leaveHalf,
            'total_hours_rendered' => $totalHours,
        ];
    }

    private function extractTimesAndWorkedMinutes($logs): array
    {
        $timeIn = null;
        $timeOut = null;
        $total = 0;
        $clockIn = null;

        foreach ($logs as $log) {
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

        $workedMinutes = $clockIn === null ? $total : null;

        return [$timeIn, $timeOut, $workedMinutes];
    }

    /**
     * Overtime module status for attendance/report rows: only show approved when payable hours exist.
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
}
