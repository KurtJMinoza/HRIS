<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceCorrectionAudit;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\PresenceFilingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AttendanceCorrectionController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly PresenceFilingService $presenceFilingService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
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

    /**
     * Build a per-day schedule array from a WorkingSchedule model so that employees
     * assigned via the Schedule module (working_schedule_id) are handled the same as
     * those with a manually-set schedule JSON column.
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $restDays = $schedule->rest_days ?? [];
        $baseDayConfig = [];

        foreach (self::DAY_KEYS as $dayKey) {
            if (in_array($dayKey, $restDays, true)) {
                $baseDayConfig[$dayKey] = null;

                continue;
            }

            $baseDayConfig[$dayKey] = [
                'in' => $schedule->time_in,
                'out' => $schedule->time_out,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $baseDayConfig;
    }

    /**
     * Normalize time string to H:i (e.g. "12:00:00" -> "12:00"). Accepts H:i or H:i:s.
     */
    private function normalizeTimeToHi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        $v = trim($value);
        if (strlen($v) >= 5 && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        return $v;
    }

    /**
     * Create or update a manual attendance correction for a given employee and date.
     */
    public function store(Request $request): JsonResponse
    {
        // Accept both "HH:MM" and "HH:MM:SS" coming from browsers, normalize to H:i.
        $toMerge = [];
        foreach (['time_in', 'time_out'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                $normalized = $this->normalizeTimeToHi(is_string($val) ? $val : (string) $val);
                if ($normalized !== null) {
                    $toMerge[$key] = $normalized;
                }
            }
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'time_in' => ['required_without:preset_schedule_regular', 'nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:65535'],
            'approved' => ['nullable', 'boolean'],
            // When true, admin explicitly wants to override full-day leave restrictions for this date.
            'override_leave' => ['nullable', 'boolean'],
            // Fill time_in/time_out from employee schedule (regular hours, no OT padding).
            'preset_schedule_regular' => ['nullable', 'boolean'],
            'manual_presence_reason' => ['nullable', 'string', 'max:65535'],
        ], [
            'employee_id.required' => 'Employee is required.',
            'employee_id.exists' => 'Selected employee does not exist.',
            'date.required' => 'Date is required.',
        ]);

        $tz = $this->attendanceTimezone();

        /** @var User $employee */
        $employee = User::where('id', $validated['employee_id'])
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->with('workingSchedule')
            ->firstOrFail();

        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);

        // Store manual attendance using the attendance timezone date so schedule/leave checks are consistent.
        $dateCarbon = Carbon::parse($validated['date'], $tz)->startOfDay();
        try {
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                (int) $employee->id,
                $dateCarbon->copy(),
                $dateCarbon->copy()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $date = $dateCarbon->toDateString();

        $timeInStr = $validated['time_in'] ?? null;
        $timeOutStr = $validated['time_out'] ?? null;
        if ($request->boolean('preset_schedule_regular')) {
            $pair = $this->presenceFilingService->resolveScheduleRegularPunches($employee, $date);
            if ($pair === null) {
                throw ValidationException::withMessages([
                    'preset_schedule_regular' => ['Could not resolve schedule times (rest day or no schedule).'],
                ]);
            }
            $timeInStr = $pair[0]->format('H:i');
            $timeOutStr = $pair[1]->format('H:i');
        }

        // 1) Validate that the employee has a working schedule for this specific calendar date.
        // Prefer the JSON schedule column; fall back to working_schedule_id relationship so that
        // employees assigned via Admin → Schedules module are handled correctly.
        $schedule = $employee->schedule;
        if ((! is_array($schedule) || $schedule === []) && $employee->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($employee->workingSchedule);
            if ($derived !== null) {
                $schedule = $derived;
            }
        }
        if (! is_array($schedule) || $schedule === []) {
            throw ValidationException::withMessages([
                'schedule' => ['Employee has no working schedule assigned.'],
            ]);
        }
        $dayKey = self::DAY_KEYS[(int) $dateCarbon->format('w')];
        $daySchedule = $schedule[$dayKey] ?? null;
        if (! is_array($daySchedule) || empty($daySchedule['in'])) {
            throw ValidationException::withMessages([
                'schedule' => ['Employee is not scheduled to work on this date.'],
            ]);
        }

        // Normalize time_in and time_out to full datetimes. For night shift (out <= in), time_out before time_in means next day.
        $scheduleIn = trim((string) ($daySchedule['in'] ?? ''));
        $scheduleOut = trim((string) ($daySchedule['out'] ?? ''));
        $isNightShift = $scheduleOut !== '' && $scheduleIn !== '' && $scheduleOut <= $scheduleIn;

        $timeIn = null;
        $timeOut = null;
        if (! empty($timeInStr)) {
            $timeIn = Carbon::parse($date.' '.$timeInStr, $tz);
        }
        if (! empty($timeOutStr)) {
            $timeOut = Carbon::parse($date.' '.$timeOutStr, $tz);
            if ($timeIn && $timeOut->lessThanOrEqualTo($timeIn)) {
                if ($isNightShift) {
                    $timeOut = $timeOut->copy()->addDay();
                } else {
                    throw ValidationException::withMessages([
                        'time_out' => ['Time out must be after time in for day shifts.'],
                    ]);
                }
            }
        }

        // 2) Validate against approved leave for the same date.
        $overrideLeave = (bool) ($validated['override_leave'] ?? false);
        $leaves = LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get();

        $hasHalfDayLeave = false;
        $halfType = null;
        $undertimeLeave = null;

        foreach ($leaves as $leave) {
            if ($leave->type === 'undertime') {
                $undertimeLeave = $leave;

                continue;
            }

            if ($leave->type === 'half_day') {
                $hasHalfDayLeave = true;
                $halfType = $leave->half_type;

                continue;
            }

            // Any other leave type is treated as full-day leave.
            if (! $overrideLeave) {
                throw ValidationException::withMessages([
                    'date' => ['Employee has an approved full-day leave on this date. Enable override to record manual attendance.'],
                ]);
            }
        }

        // 2a) If there is an approved half-day leave, only allow attendance in the working half.
        if ($hasHalfDayLeave && $timeIn && $timeOut) {
            $noon = Carbon::parse($date.' 12:00:00', $tz);
            if ($halfType === 'am') {
                // AM half: employee is on leave in the morning, works only afternoon.
                if ($timeIn->lessThan($noon)) {
                    throw ValidationException::withMessages([
                        'time_in' => ['For AM half-day leave, time in must be at or after 12:00 PM.'],
                    ]);
                }
            } elseif ($halfType === 'pm') {
                // PM half: employee works morning only. Use time-of-day so night shift (e.g. 06:00 next day) is allowed.
                if ($timeOut->format('H:i') > '12:00') {
                    throw ValidationException::withMessages([
                        'time_out' => ['For PM half-day leave, time out must be at or before 12:00 PM.'],
                    ]);
                }
            }
        }

        // 3) If there is an approved undertime for this date, enforce that manual time_out matches the approved time.
        if ($undertimeLeave && $timeOut) {
            $approvedUndertime = trim((string) ($undertimeLeave->undertime_time ?? ''));
            if ($approvedUndertime !== '') {
                $approvedStr = substr($approvedUndertime, 0, 5);
                $manualOutStr = $timeOut->copy()->timezone($tz)->format('H:i');
                if ($manualOutStr !== $approvedStr) {
                    throw ValidationException::withMessages([
                        'time_out' => ["Time out must match the approved undertime time of {$approvedStr}."],
                    ]);
                }
            }
        }

        // 4) If there is an approved overtime for this date, require that time_out is not earlier than the approved OT end.
        if ($timeOut) {
            $approvedOvertime = Overtime::query()
                ->where('user_id', $employee->id)
                ->whereDate('date', $date)
                ->where('status', Overtime::STATUS_APPROVED)
                ->orderByDesc('id')
                ->first();

            if ($approvedOvertime) {
                $otEndCarbon = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                    $approvedOvertime,
                    $date,
                    is_array($daySchedule) ? $daySchedule : null,
                    $tz
                );
                if ($otEndCarbon) {
                    if ($isNightShift && $timeIn && $otEndCarbon->lessThanOrEqualTo($timeIn)) {
                        $otEndCarbon = $otEndCarbon->copy()->addDay();
                    }
                    if ($timeOut->lessThan($otEndCarbon)) {
                        $otEndStr = $otEndCarbon->format('H:i');
                        $dayHint = $isNightShift ? ' (+1 day)' : '';
                        throw ValidationException::withMessages([
                            'time_out' => ["Time out must not be earlier than the approved overtime end of {$otEndStr}{$dayHint}."],
                        ]);
                    }
                }
            }
        }

        // 5) Optional: if logs already contain a completed in/out pair and there is no existing correction,
        // prevent creating a second, conflicting manual record.
        [$dayStartUtc, $dayEndUtc] = [
            $dateCarbon->copy()->setTimezone('UTC'),
            $dateCarbon->copy()->endOfDay()->setTimezone('UTC'),
        ];
        $hasIn = Schema::hasTable('attendance_logs') && AttendanceLog::where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = Schema::hasTable('attendance_logs') && AttendanceLog::where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        $existingCorrection = AttendanceCorrection::where('user_id', $employee->id)
            ->whereDate('date', $date)
            ->first();

        if ($hasIn && $hasOut && ! $existingCorrection) {
            throw ValidationException::withMessages([
                'date' => ['This employee already has a completed clock-in and clock-out record for this date. Edit or delete existing records instead of adding a duplicate.'],
            ]);
        }

        $approved = (bool) ($validated['approved'] ?? false);
        $now = now();
        $admin = $request->user();

        $remarks = $validated['remarks'] ?? null;
        if (! empty($validated['manual_presence_reason'])) {
            $prefix = $remarks !== null && trim((string) $remarks) !== '' ? trim((string) $remarks)."\n\n" : '';
            $remarks = $prefix.'[Manual presence] '.trim((string) $validated['manual_presence_reason']);
        }

        // Store times in UTC so that Eloquent's datetime cast reads them back as UTC Carbon objects,
        // matching the format used by AttendanceLog::created_at. The monitoring controller then
        // converts UTC → attendance timezone for display. Storing Manila-time strings here would
        // cause Eloquent (app.timezone = UTC) to re-interpret them as UTC, shifting the displayed
        // time by +8 hours (e.g. 08:00 AM input → 04:00 PM displayed).
        $correction = AttendanceCorrection::updateOrCreate(
            [
                'user_id' => $employee->id,
                'date' => $date,
            ],
            [
                'time_in' => $timeIn ? $timeIn->copy()->setTimezone('UTC') : null,
                'time_out' => $timeOut ? $timeOut->copy()->setTimezone('UTC') : null,
                'remarks' => $remarks,
                'manual_presence_reason' => $validated['manual_presence_reason'] ?? null,
                'pending_approval' => false,
                'approved' => $approved,
                'approved_by' => $approved ? $admin?->id : null,
                'approved_at' => $approved ? $now : null,
            ]
        );

        // Audit log: record who changed what and why.
        AttendanceCorrectionAudit::create([
            'attendance_correction_id' => $correction->id,
            'admin_id' => $admin?->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'previous_time_in' => $existingCorrection?->time_in,
            'previous_time_out' => $existingCorrection?->time_out,
            'new_time_in' => $correction->time_in,
            'new_time_out' => $correction->time_out,
            'reason' => $remarks,
        ]);

        // Compute derived metrics so the client can show an immediate summary.
        $lateMinutes = null;
        $undertimeMinutes = null;
        $overtimeMinutes = null;
        $workedMinutes = null;

        if ($timeIn && $timeOut) {
            // Subtract schedule break period so 08:00–17:00 = 8 h worked, not 9 h.
            $workedMinutes = AttendanceStatusService::getNetWorkedMinutes($timeIn, $timeOut, $daySchedule, $date, $tz);
        }

        if ($timeIn) {
            $tz = $this->attendanceTimezone();
            $dateKey = $date;
            $clockInResult = AttendanceStatusService::getClockInStatus(
                $daySchedule,
                $dateKey,
                $timeIn->copy()->timezone($tz)
            );
            if ($clockInResult['status'] === 'late') {
                $lateMinutes = $clockInResult['late_minutes'] ?? null;
            }
        }

        $scheduledRegularMinutes = null;
        if (is_array($daySchedule) && ! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
            $scheduledRegularMinutes = AttendanceStatusService::getRequiredWorkingMinutes($date, $daySchedule, $tz);
        }

        if ($timeIn && $timeOut) {
            $tz = $this->attendanceTimezone();
            $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
            $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                $date,
                $daySchedule,
                $timeIn->copy()->timezone($tz),
                $timeOut->copy()->timezone($tz),
                $tz,
                $earlyTimeout
            );

            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($date, $daySchedule, $tz);
            if ($scheduledEnd instanceof Carbon) {
                $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
                    ? (int) $daySchedule['overtime_buffer_minutes']
                    : (int) config('attendance.overtime_buffer_minutes', 15);

                $postShiftOt = 0;
                $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                if ($timeOut->greaterThan($otStart)) {
                    $postShiftOt = (int) $otStart->diffInMinutes($timeOut);
                }

                $preShiftOt = 0;
                $scheduledStart = AttendanceStatusService::getScheduledStartForDate($date, $daySchedule, $tz);
                if ($scheduledStart && $timeIn->lessThan($scheduledStart)) {
                    $preShiftOt = (int) $timeIn->diffInMinutes($scheduledStart);
                }

                $overtimeMinutes = $preShiftOt + $postShiftOt;
            }
        } elseif ($timeOut) {
            $tz = $this->attendanceTimezone();
            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($date, $daySchedule, $tz);
            if ($scheduledEnd instanceof Carbon) {
                $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $timeOut, $earlyTimeout);

                $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
                    ? (int) $daySchedule['overtime_buffer_minutes']
                    : (int) config('attendance.overtime_buffer_minutes', 15);
                $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
                if ($timeOut->greaterThan($otStart)) {
                    $overtimeMinutes = (int) $otStart->diffInMinutes($timeOut);
                }
            }
        }

        $responseTz = $this->attendanceTimezone();

        $approvedOtRow = Overtime::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $date)
            ->where('status', Overtime::STATUS_APPROVED)
            ->first();
        $approvedOtHours = $approvedOtRow
            ? (float) ($approvedOtRow->computed_hours ?? 0)
            : 0.0;
        $renderedOtHours = $overtimeMinutes !== null ? round($overtimeMinutes / 60, 2) : null;
        $payableOtHours = $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : null;

        return response()->json([
            'message' => 'Attendance correction saved.',
            'correction' => [
                'id' => $correction->id,
                'employee_id' => $correction->user_id,
                'date' => $correction->date?->toDateString(),
                'day_name' => $correction->date ? $this->dayNameForDate($correction->date) : null,
                'time_in' => $correction->time_in?->copy()->timezone($responseTz)->toIso8601String(),
                'time_out' => $correction->time_out?->copy()->timezone($responseTz)->toIso8601String(),
                'remarks' => $correction->remarks,
                'approved' => $correction->approved,
                'approved_by' => $correction->approved_by,
                'approved_at' => $correction->approved_at?->toIso8601String(),
                'metrics' => [
                    'late_minutes' => $lateMinutes,
                    'undertime_minutes' => $undertimeMinutes,
                    'overtime_minutes' => $overtimeMinutes,
                    'rendered_overtime_hours' => $renderedOtHours,
                    'approved_overtime_hours' => $payableOtHours,
                    'overtime_hours' => $payableOtHours,
                    'scheduled_regular_hours' => $scheduledRegularMinutes !== null && $scheduledRegularMinutes > 0
                        ? round($scheduledRegularMinutes / 60, 2)
                        : null,
                    'total_rendered_hours' => $workedMinutes !== null ? round($workedMinutes / 60, 2) : null,
                    'worked_minutes' => $workedMinutes,
                    'worked_hours' => $workedMinutes !== null ? round($workedMinutes / 60, 2) : null,
                ],
            ],
        ]);
    }

    /**
     * Delete an attendance correction by id.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $correction = AttendanceCorrection::findOrFail($id);
        $employee = User::query()
            ->where('id', $correction->user_id)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->first();
        if ($employee) {
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $employee);
        }
        $correction->delete();

        return response()->json([
            'message' => 'Attendance correction removed.',
        ]);
    }
}
