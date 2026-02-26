<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeOvertimeController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    private const SCHEDULE_ERROR_MESSAGE = 'No schedule assigned. Please contact the administrator.';

    /**
     * Submit a manual overtime request for the authenticated employee.
     *
     * Validations:
     * - Employee exists and is active
     * - Date is valid and not after today
     * - Employee has an assigned schedule with an "out" time for that date
     * - No approved leave on that date
     * - No existing overtime record for the same date
     * - Expected end time is after scheduled end time
     * - Attachment (if present) respects type and size limits
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== User::ROLE_EMPLOYEE) {
            throw ValidationException::withMessages([
                'user' => ['Only employees can submit overtime requests.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => ['Account is deactivated.'],
            ]);
        }

        $validated = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'expected_end_time' => ['required', 'date_format:H:i'],
            'category' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'min:10'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], // 5MB
        ]);

        $date = Carbon::parse($validated['date'])->toDateString();

        // Ensure employee has an assigned schedule with an "out" time for this date.
        $schedule = $user->schedule;
        if (! is_array($schedule) || $schedule === []) {
            throw ValidationException::withMessages([
                'date' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }

        $carbonDate = Carbon::parse($date);
        $dayKey = self::DAY_KEYS[(int) $carbonDate->format('w')];
        $daySchedule = $schedule[$dayKey] ?? null;

        if (! is_array($daySchedule) || empty($daySchedule['out'])) {
            throw ValidationException::withMessages([
                'date' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }

        // Block overtime requests on dates where the employee has approved leave.
        $hasApprovedLeave = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();

        if ($hasApprovedLeave) {
            throw ValidationException::withMessages([
                'date' => ['You have an approved leave for this date. Overtime is not allowed.'],
            ]);
        }

        // Require that the employee has both a clock-in and a clock-out for this date.
        $logs = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', $date)
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) {
            throw ValidationException::withMessages([
                'date' => ['You must have a completed attendance (time in and time out) for this date before requesting overtime.'],
            ]);
        }

        $hasClockIn = false;
        $hasClockOut = false;
        foreach ($logs as $log) {
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $hasClockIn = true;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $hasClockOut = true;
            }
        }

        if (! $hasClockIn || ! $hasClockOut) {
            throw ValidationException::withMessages([
                'date' => ['You can only request overtime after you have both clocked in and clocked out for this date.'],
            ]);
        }

        // Prevent duplicate overtime records for the same user and date.
        $existing = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $date)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'date' => ['You already have an overtime record for this date.'],
            ]);
        }

        $scheduleEnd = Carbon::parse($date . ' ' . $daySchedule['out']);
        $expectedEnd = Carbon::parse($date . ' ' . $validated['expected_end_time']);

        if ($expectedEnd->lessThanOrEqualTo($scheduleEnd)) {
            throw ValidationException::withMessages([
                'expected_end_time' => ['Expected end time must be later than your scheduled end time.'],
            ]);
        }

        // Apply the same 1-hour grace period rule as automatic overtime:
        // OT starts 1 hour after scheduled end.
        $overtimeStart = $scheduleEnd->copy()->addHour();
        if ($expectedEnd->lessThanOrEqualTo($overtimeStart)) {
            $computedMinutes = 0;
        } else {
            $computedMinutes = $overtimeStart->diffInMinutes($expectedEnd);
        }

        $computedMinutes = max(0, (int) $computedMinutes);
        $computedHours = $computedMinutes > 0 ? round($computedMinutes / 60, 2) : 0.0;

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('overtime_attachments', 'public');
        }

        $overtime = Overtime::create([
            'user_id' => $user->id,
            'date' => $date,
            'schedule_end' => $scheduleEnd->format('H:i:s'),
            'time_out' => null,
            'expected_end_time' => $expectedEnd->format('H:i:s'),
            'computed_minutes' => $computedMinutes,
            'computed_hours' => $computedHours,
            'ot_type' => $validated['category'],
            'reason' => $validated['reason'],
            'attachment_path' => $attachmentPath,
            'status' => Overtime::STATUS_PENDING,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Overtime request submitted successfully.',
            'overtime' => [
                'id' => $overtime->id,
                'date' => $overtime->date?->toDateString(),
                'schedule_end' => $overtime->schedule_end?->format('H:i'),
                'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
                'computed_minutes' => $overtime->computed_minutes,
                'computed_hours' => (float) $overtime->computed_hours,
                'ot_type' => $overtime->ot_type,
                'status' => $overtime->status,
            ],
        ], 201);
    }
}

