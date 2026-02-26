<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Refreshes user schedule fields from DB to avoid stale schedule JSON.
     */
    private function refreshUserForScheduleCheck(User $user): User
    {
        $fresh = User::query()
            ->select(['id', 'schedule', 'working_schedule_id'])
            ->where('id', $user->id)
            ->first();

        return $fresh ?? $user;
    }

    /**
     * @return array|null Day schedule array or null when rest day / not configured.
     */
    private function getDayScheduleForDate(User $user, Carbon $date): ?array
    {
        $schedule = $user->schedule;
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }
        $dayKey = self::DAY_KEYS[(int) $date->format('w')];
        $day = $schedule[$dayKey] ?? null;
        if (! is_array($day) || $day === []) {
            return null;
        }

        return $day;
    }

    private function ensureNotHoliday(string $dateKey): void
    {
        $holidays = config('attendance.holidays', []);
        if (! is_array($holidays)) {
            $holidays = [];
        }
        $isHoliday = in_array($dateKey, $holidays, true);
        $allowOnHoliday = (bool) config('attendance.allow_undertime_on_holiday', false);
        if ($isHoliday && ! $allowOnHoliday) {
            throw ValidationException::withMessages([
                'start_date' => ['Selected date is a holiday.'],
            ]);
        }
    }

    private function ensureNotRestDay(?array $daySchedule): void
    {
        $allowOnRestDay = (bool) config('attendance.allow_undertime_on_rest_day', false);
        if ($daySchedule === null && ! $allowOnRestDay) {
            throw ValidationException::withMessages([
                'start_date' => ['Selected date is a rest day.'],
            ]);
        }
    }

    /**
     * Validate early-out time against schedule start/end and break window.
     * Returns a Carbon instance representing the early-out datetime (shift-aware).
     */
    private function validateUndertimeTimeOrThrow(string $dateKey, array $daySchedule, string $undertimeTime, string $tz): Carbon
    {
        $in = trim((string) ($daySchedule['in'] ?? ''));
        $out = trim((string) ($daySchedule['out'] ?? ''));
        if ($in === '' || $out === '') {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned. Please contact the administrator.'],
            ]);
        }

        $scheduledStart = Carbon::parse($dateKey . ' ' . $in, $tz);
        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned. Please contact the administrator.'],
            ]);
        }

        $earlyOut = Carbon::parse($dateKey . ' ' . $undertimeTime, $tz);

        // Night shift support: if schedule end is next day and earlyOut time is before schedule start (e.g. 02:00),
        // treat earlyOut as next-day time.
        if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $earlyOut->lessThan($scheduledStart)) {
            $earlyOut = $earlyOut->addDay();
        }

        if ($earlyOut->lessThanOrEqualTo($scheduledStart)) {
            throw ValidationException::withMessages([
                'undertime_time' => ['Early-out time must be after your schedule start.'],
            ]);
        }

        if ($earlyOut->greaterThanOrEqualTo($scheduledEnd)) {
            throw ValidationException::withMessages([
                'undertime_time' => ['Early-out time must be earlier than your schedule end.'],
            ]);
        }

        $breakStartStr = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEndStr = trim((string) ($daySchedule['break_end'] ?? ''));
        if ($breakStartStr !== '' && $breakEndStr !== '') {
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

            if ($earlyOut->greaterThanOrEqualTo($breakStart) && $earlyOut->lessThanOrEqualTo($breakEnd)) {
                throw ValidationException::withMessages([
                    'undertime_time' => ['Early-out time cannot be during the break period.'],
                ]);
            }
        }

        return $earlyOut;
    }

    /**
     * List leave requests for the authenticated employee with simple summary.
     *
     * Optional query params:
     * - from_date, to_date (date range)
     * - status: pending|approved|rejected
     */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
        ]);

        $query = LeaveRequest::query()
            ->where('user_id', $user->id);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

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

            $query->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString());
        }

        $leaves = $query
            ->orderByDesc('start_date')
            ->get();

        $total = $leaves->count();
        $pending = $leaves->where('status', LeaveRequest::STATUS_PENDING)->count();
        $approved = $leaves->where('status', LeaveRequest::STATUS_APPROVED)->count();
        $rejected = $leaves->where('status', LeaveRequest::STATUS_REJECTED)->count();

        $today = now()->toDateString();
        $upcoming = $leaves
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->filter(function (LeaveRequest $leave) use ($today) {
                return $leave->end_date->toDateString() >= $today;
            })
            ->count();

        $payloadLeaves = $leaves->map(function (LeaveRequest $l) {
            return [
                'id' => $l->id,
                'type' => $l->type,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
                'half_type' => $l->half_type,
                'status' => $l->status,
                'notes' => $l->notes,
                'document_url' => $l->document_path ? Storage::url($l->document_path) : null,
                'created_at' => $l->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'summary' => [
                'total' => $total,
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
                'upcoming' => $upcoming,
            ],
            'leave_requests' => $payloadLeaves,
        ]);
    }

    /**
     * Apply for leave as the authenticated employee.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = $this->attendanceTimezone();

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50', 'in:vacation,sick,emergency,other,undertime,half_day'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'undertime_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:2000'],
            // For half-day leave we require which half of the day is worked.
            'half_type' => ['nullable', 'string', 'in:am,pm'],
        ]);

        $type = $validated['type'];
        $startDateKey = Carbon::parse($validated['start_date'], $tz)->toDateString();

        if ($type === 'undertime') {
            // Undertime is time-based and must always be a single calendar date.
            $validated['end_date'] = $validated['start_date'];

            $reason = trim((string) ($validated['reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['Reason is required for undertime leave.'],
                ]);
            }
            $undertimeTime = trim((string) ($validated['undertime_time'] ?? ''));
            if ($undertimeTime === '') {
                throw ValidationException::withMessages([
                    'undertime_time' => ['Approved early-out time is required for undertime leave.'],
                ]);
            }

            $user = $this->refreshUserForScheduleCheck($user);
            if ($user->working_schedule_id === null) {
                throw ValidationException::withMessages([
                    'schedule' => ['No schedule assigned. Please contact the administrator.'],
                ]);
            }

            $this->ensureNotHoliday($startDateKey);
            $daySchedule = $this->getDayScheduleForDate($user, Carbon::parse($startDateKey, $tz));
            $this->ensureNotRestDay($daySchedule);
            if (! $daySchedule) {
                // If rest day is allowed by policy, we still require a schedule entry for time validation.
                throw ValidationException::withMessages([
                    'schedule' => ['No schedule assigned for the selected date.'],
                ]);
            }

            $this->validateUndertimeTimeOrThrow($startDateKey, $daySchedule, $undertimeTime, $tz);
        }

        if ($type === 'half_day') {
            // Half-day leave is always a single calendar date.
            $validated['end_date'] = $validated['start_date'];

            $halfType = $validated['half_type'] ?? null;
            if ($halfType === null || $halfType === '') {
                throw ValidationException::withMessages([
                    'half_type' => ['Half day type (AM or PM) is required.'],
                ]);
            }
        }

        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'type' => $type,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'undertime_time' => $type === 'undertime' ? ($validated['undertime_time'] ?? null) : null,
            'half_type' => $type === 'half_day' ? ($validated['half_type'] ?? null) : null,
            'notes' => $type === 'undertime' ? trim((string) ($validated['reason'] ?? '')) : null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Leave request submitted.',
            'leave_request' => [
                'id' => $leave->id,
                'type' => $leave->type,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'undertime_time' => $leave->undertime_time ? substr((string) $leave->undertime_time, 0, 5) : null,
                'notes' => $leave->notes,
                'status' => $leave->status,
                'created_at' => $leave->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Upload or replace supporting document for a leave request.
     */
    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leave = LeaveRequest::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $validated['document'];

        if ($leave->document_path) {
            Storage::disk('public')->delete($leave->document_path);
        }

        $path = $file->store('leave-documents', 'public');

        $leave->document_path = $path;
        $leave->save();

        return response()->json([
            'message' => 'Document uploaded.',
            'document_url' => Storage::url($path),
        ]);
    }
}

