<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HrRole;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Services\AttendancePresenceDisplayService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\PayrollRulesEngineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AttendanceMonitoringController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $computed = $this->computeMonitoringRows($request, $validated);
        if ($computed instanceof JsonResponse) {
            return $computed;
        }

        $rows = $computed['rows'];

        $response = [
            'from_date' => $computed['from_date'],
            'to_date' => $computed['to_date'],
            'rows' => $rows,
        ];

        if (isset($validated['page']) || isset($validated['per_page'])) {
            $perPage = (int) ($validated['per_page'] ?? 50);
            $page = (int) ($validated['page'] ?? 1);
            $total = count($response['rows']);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;
            $response['rows'] = array_slice($response['rows'], $offset, $perPage);
            $response['meta'] = [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ];
        }

        Log::info('Attendance monitoring response prepared', [
            'actor_user_id' => (int) $request->user()->id,
            'from_date' => (string) $computed['from_date'],
            'to_date' => (string) $computed['to_date'],
            'rows_count' => count($response['rows']),
            'paginated' => isset($response['meta']),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

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
        ]);

        $computed = $this->computeMonitoringRows($request, $validated);
        if ($computed instanceof JsonResponse) {
            return $computed;
        }

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
                'Time In',
                'Time Out',
                'Status',
                'Scheduled In',
                'Scheduled Out',
                'Scheduled Regular Hours',
                'Total Worked Hours',
                'Overtime Hours (Approved)',
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
                    $r['time_in'] ?? null,
                    $r['time_out'] ?? null,
                    $r['status'] ?? null,
                    $r['schedule_in'] ?? null,
                    $r['schedule_out'] ?? null,
                    $r['scheduled_regular_hours'] ?? null,
                    $r['total_rendered_hours'] ?? ($r['total_hours'] ?? null),
                    $r['approved_overtime_hours'] ?? ($r['overtime_hours'] ?? null),
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
     * @return array{from_date:string,to_date:string,rows:list<array<string,mixed>>}|JsonResponse
     */
    private function computeMonitoringRows(Request $request, array $validated): array|JsonResponse
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

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        $employeesQuery = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true);

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
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->user_id.'|'.$c->date->toDateString());

        $approvedOvertimesByUserDate = Overtime::query()
            ->whereIn('user_id', $userIds)
            ->where('status', Overtime::STATUS_APPROVED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($o) => $o->user_id.'|'.$o->date->toDateString());

        $approvedLeaves = LeaveRequest::query()
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
                $effectiveSchedule = $this->rulesEngine->resolveEffectiveSchedule($employee);
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
                            $earlyTimeout = isset($todaySchedule['early_timeout_minutes']) ? (int) $todaySchedule['early_timeout_minutes'] : null;
                            $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $outCarbon, $earlyTimeout);

                            $overtimeBuffer = isset($todaySchedule['overtime_buffer_minutes'])
                                ? (int) $todaySchedule['overtime_buffer_minutes']
                                : (int) config('attendance.overtime_buffer_minutes', 15);
                            $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                            if ($outCarbon->greaterThan($otStart)) {
                                $overtimeMinutes = (int) $otStart->diffInMinutes($outCarbon);
                            }
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
                $logRenderedOtHours = $clockOutLog?->overtime_hours;
                $renderedOvertimeHours = null;
                if ($hasClockOut) {
                    if ($logRenderedOtHours !== null) {
                        $renderedOvertimeHours = round((float) $logRenderedOtHours, 2);
                    } elseif ($overtimeMinutes !== null && $overtimeMinutes > 0) {
                        $renderedOvertimeHours = round($overtimeMinutes / 60, 2);
                    }
                }
                $approvedOvertimeHours = $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : null;

                $scheduledRegularMinutes = null;
                if (is_array($todaySchedule) && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                    $scheduledRegularMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $tz);
                }

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
                    'schedule_in' => $scheduleIn,
                    'schedule_out' => $scheduleOut,
                    'time_in' => $effectiveTimeIn
                        ? ($effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn->copy()->timezone($tz)->format('H:i')
                            : Carbon::parse($effectiveTimeIn)->timezone($tz)->format('H:i'))
                        : null,
                    'time_out' => $effectiveTimeOut
                        ? ($effectiveTimeOut instanceof Carbon
                            ? $effectiveTimeOut->copy()->timezone($tz)->format('H:i')
                            : Carbon::parse($effectiveTimeOut)->timezone($tz)->format('H:i'))
                        : null,
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
                    'overtime_minutes' => $hasClockOut ? $overtimeMinutes : null,
                    'overtime_hours' => $hasClockOut ? $approvedOvertimeHours : null,
                    'rendered_overtime_hours' => $hasClockOut ? $renderedOvertimeHours : null,
                    'approved_overtime_hours' => $approvedOvertimeHours,
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
                    'has_approved_overtime' => $approvedOtHours > 0.0001,
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

        return [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $rows,
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
}
