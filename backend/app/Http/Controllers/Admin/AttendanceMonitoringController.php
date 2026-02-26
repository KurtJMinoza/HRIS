<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceMonitoringController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'], // legacy single-date filter
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,undertime'],
        ]);

        // Determine range: prefer from/to if provided; fall back to single date or today.
        if (! empty($validated['from_date']) || ! empty($validated['to_date'])) {
            $from = isset($validated['from_date'])
                ? Carbon::parse($validated['from_date'])->startOfDay()
                : Carbon::parse($validated['to_date'])->startOfDay();
            $to = isset($validated['to_date'])
                ? Carbon::parse($validated['to_date'])->endOfDay()
                : Carbon::parse($validated['from_date'])->endOfDay();
            if ($to->lessThan($from)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }
        } else {
            $from = isset($validated['date']) ? Carbon::parse($validated['date'])->startOfDay() : today()->startOfDay();
            $to = $from->copy()->endOfDay();
        }

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        $employeesQuery = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true);

        if (! empty($validated['department'])) {
            $employeesQuery->where('department', $validated['department']);
        }

        if (! empty($validated['employee_id'])) {
            $employeesQuery->where('id', $validated['employee_id']);
        }

        $employees = $employeesQuery
            ->orderBy('name')
            ->get();

        $rows = [];

        // Preload logs and corrections for efficiency over ranges.
        $userIds = $employees->pluck('id')->all();

        $logs = AttendanceLog::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $logsByUserDate = [];
        foreach ($logs as $log) {
            $dateKey = $log->created_at->toDateString();
            $logsByUserDate[$log->user_id][$dateKey] = ($logsByUserDate[$log->user_id][$dateKey] ?? collect())->push($log);
        }

        $corrections = AttendanceCorrection::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->user_id . '|' . $c->date->toDateString());

        // Preload approved leaves for range to mark days as "On Leave" / "Half Day" in the admin table.
        $approvedLeaves = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
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
                $schedule = $employee->schedule;
                $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

                $dayLogs = $logsByUserDate[$employee->id][$dateKey] ?? collect();
                [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutes($dayLogs);

                $correctionKey = $employee->id . '|' . $dateKey;
                $correction = $corrections->get($correctionKey)?->first();

                // Apply approved correction override if present.
                $effectiveTimeIn = $timeIn;
                $effectiveTimeOut = $timeOut;
                $effectiveWorkedMinutes = $workedMinutes;
                $remarks = null;
                $approved = false;

                if ($correction && $correction->approved) {
                    if ($correction->time_in) {
                        $effectiveTimeIn = $correction->time_in;
                    }
                    if ($correction->time_out) {
                        $effectiveTimeOut = $correction->time_out;
                    }
                    if ($correction->time_in && $correction->time_out) {
                        $effectiveWorkedMinutes = $correction->time_in->diffInMinutes($correction->time_out);
                    }
                    $remarks = $correction->remarks;
                    $approved = true;
                }

                $status = '—';
                $lateLabel = null;
                $lateMinutes = null;
                $undertimeMinutes = null;
                $overtimeMinutes = null;

                $leaveInfo = $leaveDatesByUser[$employee->id][$dateKey] ?? null;
                $isOnLeave = $leaveInfo !== null;
                $leaveType = $leaveInfo['type'] ?? null;

                if ($isOnLeave) {
                    if ($leaveType === 'half_day') {
                        // Approved half-day leave: status reflects Half Day;
                        // actual rendered hours still come only from logs.
                        $status = 'halfday';
                    } else {
                        // All other approved leave types (vacation, sick, etc.) are treated as full-day leave.
                        $status = 'leave';
                    }
                } elseif ($todaySchedule && ! empty($todaySchedule['in'])) {
                    if (! $effectiveTimeIn) {
                        // Mark absent only after cutoff (e.g. 5:00 PM) — not present until then
                        $isToday = $dateKey === now()->toDateString();
                        $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now());
                        $status = $pastCutoff ? 'absent' : '—';
                    } else {
                        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                        $scheduledStart = Carbon::parse($dateKey . ' ' . $todaySchedule['in'], $tz);
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $todaySchedule, $tz);

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
                            $lateLabel = $clockInResult['late_label'];
                            $lateMinutes = $clockInResult['late_minutes'] ?? null;
                        }

                        $undertimeMinutes = null;
                        $overtimeMinutes = null;
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

                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $todaySchedule, $tz);

                        // Undertime: any early time-out before scheduled end counts as undertime.
                        $isUndertime = $undertimeMinutes !== null && $undertimeMinutes > 0;

                        // With strict half-day leave policy, inferred half-day based on time-in/total hours
                        // is no longer used. Days without an approved half_day leave are classified as
                        // present/late/undertime only.
                        if ($undertimeMinutes > 0 && $isUndertime) {
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

                if (! empty($validated['status']) && $status !== $validated['status']) {
                    continue;
                }

                $profileImageUrl = $employee->profile_image
                    ? asset('storage/' . $employee->profile_image)
                    : null;

                $tz = $this->attendanceTimezone();

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'profile_image' => $profileImageUrl,
                    'department' => $employee->department,
                    'date' => $dateKey,
                    // Send simple "HH:MM" strings to avoid double timezone conversions in the UI.
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
                    'total_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
                    'status' => $status,
                    'late_label' => $lateLabel,
                    'late_minutes' => $lateMinutes,
                    'undertime_minutes' => $undertimeMinutes,
                    'overtime_minutes' => $overtimeMinutes,
                    'has_correction' => (bool) $correction,
                    'correction_id' => $correction?->id,
                    'correction_approved' => $approved,
                    'correction_remarks' => $remarks,
                ];
            }

            $cursor->addDay();
        }

        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'rows' => $rows,
        ]);
    }

    private function extractTimesAndWorkedMinutes($logs): array
    {
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

        $workedMinutes = $clockIn === null ? $total : null;

        return [$timeIn, $timeOut, $workedMinutes];
    }
}

