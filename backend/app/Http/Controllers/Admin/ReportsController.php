<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Services\AttendanceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ReportsController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

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

        $scheduledStart = Carbon::parse($dateKey . ' ' . $in, $tz);
        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            return null;
        }

        $earlyOut = Carbon::parse($dateKey . ' ' . substr($undertimeTime, 0, 5), $tz);
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

        $breakStart = Carbon::parse($dateKey . ' ' . substr($breakStartStr, 0, 5), $tz);
        $breakEnd = Carbon::parse($dateKey . ' ' . substr($breakEndStr, 0, 5), $tz);
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
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,all'],
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

        // Reject future "To" date: reporting is only allowed up to today (in attendance timezone).
        $todayCarbon = Carbon::today($attendanceTz);
        $todayDate = $todayCarbon->toDateString();
        $toDateOnly = $to->copy()->timezone($attendanceTz)->toDateString();
        if ($toDateOnly > $todayDate) {
            return response()->json([
                'message' => 'The To date cannot be in the future. Maximum allowed date is today.',
            ], 422);
        }

        if ($from->greaterThan($to)) {
            $from = $to->copy()->startOfDay();
        }

        // Clamp From date to today so we never include or iterate future dates.
        $fromDateOnly = $from->copy()->timezone($attendanceTz)->toDateString();
        if ($fromDateOnly > $todayDate) {
            $from = $todayCarbon->copy()->startOfDay();
        }

        // "All statuses": treat as no status filter so all records are included.
        $statusFilter = $validated['status'] ?? null;
        if ($statusFilter === 'all' || $statusFilter === '' || $statusFilter === null) {
            $statusFilter = null;
        }

        $employeesQuery = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true);

        // Department filter: exact match on trimmed value (no inner join; employees listed first).
        if (! empty($validated['department'])) {
            $employeesQuery->where('department', $validated['department']);
        }

        if (! empty($validated['employee_id'])) {
            $employeesQuery->where('id', $validated['employee_id']);
        }

        /** @var \Illuminate\Support\Collection<int, User> $employees */
        $employees = $employeesQuery
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            return response()->json([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'employees' => [],
            ]);
        }

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        $userIds = $employees->pluck('id')->all();

        // created_at is stored in UTC; convert the attendance-TZ range to UTC
        // so that e.g. "Feb 26" in Manila includes logs from 2026-02-25 16:00 UTC to 2026-02-26 15:59 UTC.
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        /** @var \Illuminate\Support\Collection<int, AttendanceLog> $logs */
        $logsQuery = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            // Strictly constrain by the created_at timestamp (UTC in DB), using an
            // inclusive whereBetween so that no logs fall outside the requested date window.
            ->whereBetween('created_at', [$fromUtc, $toUtc]);

        if (! empty($validated['employee_id'])) {
            // When a specific employee is selected, hard-filter by that user
            // so that no other employees' logs are ever included.
            $logsQuery->where('user_id', $validated['employee_id']);
        }

        $logs = $logsQuery
            ->orderBy('created_at')
            ->get();

        $attendanceTz = $this->attendanceTimezone();
        /** @var array<int, array<string, \Illuminate\Support\Collection>> $logsByUserDate */
        $logsByUserDate = [];
        foreach ($logs as $log) {
            $userId = $log->user_id;
            $dateKey = $log->created_at->copy()->timezone($attendanceTz)->toDateString();
            $logsByUserDate[$userId][$dateKey] = ($logsByUserDate[$userId][$dateKey] ?? collect())->push($log);
        }

        // Preload approved leaves for range to include them in reports (e.g. payroll impact).
        $approvedLeavesQuery = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString());

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
                if ($d <= $todayDate) {
                    $leaveDatesByUser[$leave->user_id][$d] = [
                        'type' => $leave->type,
                        'undertime_time' => $leave->undertime_time ? substr((string) $leave->undertime_time, 0, 5) : null,
                    ];
                }
                $cursorLeave->addDay();
            }
        }

        $results = [];
        $departmentAggregates = [];

        /** @var User $employee */
        foreach ($employees as $employee) {
            $deptKey = $employee->department ?? 'Unassigned';
            if (! isset($departmentAggregates[$deptKey])) {
                $departmentAggregates[$deptKey] = [
                    'department' => $deptKey,
                    'scheduled_days' => 0,
                    'present_days' => 0,
                    'total_worked_minutes' => 0,
                    'late_counts_by_employee' => [],
                ];
            }

            $metrics = [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'department' => $employee->department,
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
            ];

            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
                $schedule = $employee->schedule;
                $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
                $dateKey = $cursor->toDateString();

                /** @var Collection<int, AttendanceLog>|null $dayLogs */
                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? null;

                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

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
                        $scheduledStart = Carbon::parse($dateKey . ' ' . $todaySchedule['in'], $attendanceTz);
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $attendanceTz);
                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);

                        if ($workedMinutes !== null && $status !== 'halfday' && $scheduledEnd) {
                            $outCarbon = $timeOut instanceof Carbon ? $timeOut : ($timeOut ? Carbon::parse($timeOut) : null);
                            $earlyTimeout = isset($todaySchedule['early_timeout_minutes']) ? (int) $todaySchedule['early_timeout_minutes'] : null;
                            $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $outCarbon, $earlyTimeout);
                            if ($undertimeMinutes > 0 || $workedMinutes < $requiredMinutes - $undertimeThresholdMinutes) {
                                $dayUndertimeCount = 1;
                                $dayUndertimeMinutes = max(0, $undertimeMinutes);
                                $status = 'undertime';
                            }

                            if ($workedMinutes > $requiredMinutes) {
                                $overtimeForDay = $workedMinutes - $requiredMinutes;
                                $dayOvertimeCount = 1;
                                $dayOvertimeMinutes = $overtimeForDay;
                            }
                        }
                    }
                }

                if ($hasTimeIn && $status === '—') {
                    $status = 'present';
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
     * @param \Illuminate\Support\Collection<int, AttendanceLog>|null $logs
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
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                if (! $timeIn) {
                    $timeIn = $log->created_at;
                }
                $clockIn = $log->created_at;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $timeOut = $log->created_at;
                if ($clockIn) {
                    $total += $clockIn->diffInMinutes($log->created_at);
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
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,leave,undertime,all'],
            'leave_type' => ['nullable', 'string', 'max:50'],
            'overtime_status' => ['nullable', 'string', 'max:50'],
        ]);

        // Normalize filters: trim department, lowercase status for consistent matching.
        $validated['department'] = isset($validated['department']) && $validated['department'] !== ''
            ? trim($validated['department'])
            : null;
        $validated['status'] = isset($validated['status']) && $validated['status'] !== ''
            ? strtolower(trim($validated['status']))
            : null;

        // Explicitly read department and employee_id from query string so filters are never missed (GET).
        $queryDepartment = $request->query('department');
        if ($queryDepartment !== null && $queryDepartment !== '') {
            $validated['department'] = trim((string) $queryDepartment);
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

        $attendanceTz = $attendanceTzForValidation;

        // Reject future "To" date: reporting only allowed up to today (attendance timezone).
        $todayCarbon = Carbon::today($attendanceTz);
        $todayDate = $todayCarbon->toDateString();
        $toDateOnly = $to->copy()->timezone($attendanceTz)->toDateString();
        if ($toDateOnly > $todayDate) {
            return response()->json([
                'message' => 'The To date cannot be in the future. Maximum allowed date is today.',
            ], 422);
        }

        // Clamp From date to today so we never include or iterate future dates.
        $fromDateOnly = $from->copy()->timezone($attendanceTz)->toDateString();
        if ($fromDateOnly > $todayDate) {
            $from = $todayCarbon->copy()->startOfDay();
        }

        // Apply both department AND employee filters together (AND condition).
        // When both are set: only the selected employee in the selected department is included.
        $requestedEmployeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        $employeesQuery = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true);

        if ($validated['department'] !== null && $validated['department'] !== '') {
            $employeesQuery->where('department', $validated['department']);
        }

        if ($requestedEmployeeId > 0) {
            $employeesQuery->where('id', $requestedEmployeeId);
        }

        /** @var \Illuminate\Support\Collection<int, User> $employees */
        $employees = $employeesQuery
            ->orderBy('name')
            ->get();

        if ($employees->isEmpty()) {
            return response()->json([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'rows' => [],
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

        // created_at is stored in UTC; convert the attendance-TZ range to UTC
        // so that e.g. "Feb 26" in Manila includes logs from 2026-02-25 16:00 UTC to 2026-02-26 15:59 UTC.
        $fromUtc = $from->copy()->utc();
        $toUtc = $to->copy()->utc();

        /** @var \Illuminate\Support\Collection<int, AttendanceLog> $logs */
        $logsQuery = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            // Use an inclusive whereBetween on created_at (UTC in DB) so
            // that logs are strictly limited to the requested date window.
            ->whereBetween('created_at', [$fromUtc, $toUtc]);

        if (! empty($validated['employee_id'])) {
            $logsQuery->where('user_id', $validated['employee_id']);
        }

        $logs = $logsQuery
            ->orderBy('created_at')
            ->get();

        /** @var array<int, array<string, \Illuminate\Support\Collection>> $logsByUserDate */
        $logsByUserDate = [];
        foreach ($logs as $log) {
            $userId = $log->user_id;
            $dateKey = $log->created_at->copy()->timezone($attendanceTz)->toDateString();
            $logsByUserDate[$userId][$dateKey] = ($logsByUserDate[$userId][$dateKey] ?? collect())->push($log);
        }

        // Leaves by user/date with type + status + duration (all statuses).
        $leavesQuery = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString());

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

        $rows = [];

        /** @var User $employee */
        foreach ($employees as $employee) {
            $cursor = $from->copy();
            while ($cursor->lessThanOrEqualTo($to)) {
                $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
                $dateKey = $cursor->toDateString();

                $schedule = $employee->schedule;
                $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

                /** @var Collection<int, AttendanceLog>|null $dayLogs */
                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? null;

                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                $correctionKey = $employee->id . '|' . $dateKey;
                // For detailed view we want to show corrections, but AttendanceCorrection is
                // already applied inside AttendanceMonitoringController; here we rely only on logs
                // to keep the implementation focused on primary data.

                $effectiveTimeIn = $timeIn;
                $effectiveTimeOut = $timeOut;
                $effectiveWorkedMinutes = $workedMinutes;

                $status = '—';
                $lateMinutes = null;
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
                        // For detailed rows, future dates should never be marked
                        // as absences. Only dates strictly before today, or
                        // today after the cutoff, are considered absent.
                        $todayDate = now($attendanceTz)->toDateString();
                        $isFuture = $dateKey > $todayDate;
                        if (! $isFuture) {
                            $isToday = $dateKey === $todayDate;
                            $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now());
                            if ($pastCutoff) {
                                $status = 'absent';
                            }
                        }
                    } else {
                        $scheduledStart = Carbon::parse($dateKey . ' ' . $todaySchedule['in'], $attendanceTz);
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $attendanceTz);

                        $timeInCarbon = $effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn
                            : Carbon::parse($effectiveTimeIn);
                        $clockInResult = AttendanceStatusService::getClockInStatus(
                            $todaySchedule,
                            $dateKey,
                            $timeInCarbon
                        );

                        $isLate = $clockInResult['status'] === 'late';
                        if ($isLate) {
                            $lateMinutes = $clockInResult['late_minutes'] ?? null;
                        }

                        if ($scheduledEnd && $effectiveTimeOut) {
                            $outCarbon = $effectiveTimeOut instanceof Carbon
                                ? $effectiveTimeOut
                                : Carbon::parse($effectiveTimeOut);
                            $earlyTimeout = isset($todaySchedule['early_timeout_minutes']) ? (int) $todaySchedule['early_timeout_minutes'] : null;
                            $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $outCarbon, $earlyTimeout);

                            $overtimeBuffer = isset($todaySchedule['overtime_buffer_minutes']) ? (int) $todaySchedule['overtime_buffer_minutes'] : (int) config('attendance.overtime_buffer_minutes', 15);
                            $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                            if ($outCarbon->greaterThan($otStart)) {
                                $overtimeMinutes = (int) $outCarbon->diffInMinutes($otStart);
                            }
                        }

                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $attendanceTz);

                        $isUndertime = $undertimeMinutes !== null && $undertimeMinutes > 0;
                        // For strict half-day leave, do not infer Half Day from time-in/total hours.
                        // Only approved half_day leave (handled above) should produce a halfday status.
                        if ($isUndertime) {
                            $status = 'undertime';
                        } elseif ($isLate) {
                            $status = 'late';
                        } else {
                            $status = 'present';
                        }
                    }
                } elseif ($effectiveTimeIn || $effectiveTimeOut) {
                    $status = 'present';
                }

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
                    if (strtolower((string) $status) !== $statusFilter) {
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

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'department' => $employee->department,
                    'profile_image' => $employee->profile_image ?? null,
                    'date' => $dateKey,
                    'schedule' => $scheduleLabel,
                    'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                    'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                    'total_hours' => $effectiveWorkedMinutes !== null
                        ? round($effectiveWorkedMinutes / 60, 2)
                        : null,
                    'late_minutes' => $lateMinutes,
                    'undertime_minutes' => $undertimeMinutes,
                    'overtime_minutes' => $ot?->computed_minutes ?? $overtimeMinutes,
                    'status' => $status,
                    'leave_type' => $leaveInfo['type'] ?? null,
                    'leave_status' => $leaveInfo['status'] ?? null,
                    'leave_start_date' => $leaveInfo['start_date'] ?? null,
                    'leave_end_date' => $leaveInfo['end_date'] ?? null,
                    'leave_duration_days' => $leaveDurationDays,
                    'overtime_status' => $ot?->status ?? null,
                ];

                $cursor->addDay();
            }
        }

        // Ensure only one detailed row per employee per date, even if
        // upstream data (e.g. joins or duplicated logs) would otherwise
        // produce duplicates.
        $rows = collect($rows)
            ->unique(fn (array $row): string => $row['employee_id'] . '|' . $row['date'])
            ->values()
            ->all();

        // When a specific employee was requested, return only that employee's rows (strict filter).
        if ($requestedEmployeeId > 0) {
            $rows = array_values(array_filter($rows, function (array $row) use ($requestedEmployeeId) {
                return (int) ($row['employee_id'] ?? 0) === $requestedEmployeeId;
            }));
        }

        // Response dates always YYYY-MM-DD so the frontend filter and report period match.
        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $rows,
        ]);
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
}

