<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceCorrection;
use App\Models\FailedFaceAttempt;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use App\Services\OvertimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtimeService,
    ) {
    }

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private const SCHEDULE_ERROR_MESSAGE = 'No schedule assigned. Please contact the administrator.';
    private const LEAVE_BLOCK_MESSAGE = 'Attendance not allowed. You have an approved full-day leave for this date.';

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /** Format a Carbon as time (H:i) in attendance timezone for consistent display. */
    private function formatTimeInAttendanceTz($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $carbon = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);

        return $carbon->timezone($this->attendanceTimezone())->format('H:i');
    }

    /**
     * Refreshes the user from the database so schedule validation uses current data (no cache/stale).
     */
    private function refreshUserForScheduleCheck(User $user): User
    {
        $fresh = User::query()
            ->select(['id', 'name', 'schedule', 'working_schedule_id', 'qr_token', 'is_active', 'role'])
            ->where('id', $user->id)
            ->first();

        return $fresh ?? $user;
    }

    /**
     * Enforce leave-based attendance restrictions for today.
     *
     * Rules:
     * - Full-day leave (vacation/sick/emergency/other) blocks all attendance.
     * - Half Day (half_day + half_type):
     *   - AM half (work morning, leave afternoon): prevent time-in at or after 12:00 PM.
     *   - PM half (leave morning, work afternoon): prevent time-out before 1:00 PM.
     * - Undertime leave does not block attendance; it only affects reports.
     *
     * @throws ValidationException
     */
    private function enforceLeaveRestrictionsForToday(User $user, string $attendanceType): void
    {
        $tz = $this->attendanceTimezone();
        $today = now($tz)->toDateString();
        $now = now($tz);

        $leaves = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->get();

        if ($leaves->isEmpty()) {
            return;
        }

        foreach ($leaves as $leave) {
            // Undertime leave never blocks time-in/out; it is handled in reports.
            if ($leave->type === 'undertime') {
                continue;
            }

            if ($leave->type === 'half_day') {
                $halfType = $leave->half_type;

                if ($halfType === 'am' && $attendanceType === AttendanceLog::TYPE_CLOCK_IN) {
                    // AM half: work morning only; block first clock-in at or after 12:00 PM.
                    $noon = Carbon::parse($today . ' 12:00:00', $tz);
                    if ($now->greaterThanOrEqualTo($noon)) {
                        throw ValidationException::withMessages([
                            'type' => ['Attendance not allowed after 12:00 PM for AM half-day leave.'],
                        ]);
                    }
                }

                if ($halfType === 'pm' && $attendanceType === AttendanceLog::TYPE_CLOCK_OUT) {
                    // PM half: work afternoon only; ensure they do not complete attendance before 1:00 PM.
                    $onePm = Carbon::parse($today . ' 13:00:00', $tz);
                    if ($now->lessThan($onePm)) {
                        throw ValidationException::withMessages([
                            'type' => ['Attendance not allowed before 1:00 PM for PM half-day leave.'],
                        ]);
                    }
                }

                // For half-day leave we never fully block attendance; continue checking other records if any.
                continue;
            }

            // Any non-half-day, non-undertime approved leave blocks attendance entirely.
            throw ValidationException::withMessages([
                'type' => [self::LEAVE_BLOCK_MESSAGE],
            ]);
        }
    }

    /**
     * Validates that the user has an assigned schedule for today. Blocks attendance if not.
     * Schedule must be assigned from admin/schedules (working_schedule_id set); manual schedule
     * from admin/employees alone is not enough. Attendance must NEVER be recorded without this.
     *
     * @throws ValidationException
     */
    private function ensureUserHasScheduleForToday(User $user): void
    {
        if ($user->working_schedule_id === null) {
            throw ValidationException::withMessages([
                'schedule' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }
        $schedule = $user->schedule;
        if ($schedule === null || ! is_array($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }
        if ($schedule === []) {
            throw ValidationException::withMessages([
                'schedule' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }
        $today = now();
        $dayKey = self::DAY_KEYS[(int) $today->format('w')];
        $todaySchedule = isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
        if ($todaySchedule === null || ! is_array($todaySchedule)) {
            throw ValidationException::withMessages([
                'schedule' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }
        $inTime = isset($todaySchedule['in']) ? trim((string) $todaySchedule['in']) : '';
        if ($inTime === '') {
            throw ValidationException::withMessages([
                'schedule' => [self::SCHEDULE_ERROR_MESSAGE],
            ]);
        }
    }

    private function scanCooldownKey(string $ip): string
    {
        return 'scan_cooldown:' . $ip;
    }

    private function scanFailCountKey(string $ip): string
    {
        return 'scan_fail_count:' . $ip;
    }

    /** Check cooldown; return seconds remaining or 0 if not locked. */
    private function getCooldownRemaining(string $ip): int
    {
        $until = Cache::get($this->scanCooldownKey($ip));
        if ($until === null) {
            return 0;
        }
        $rem = (int) $until - time();

        return $rem > 0 ? $rem : 0;
    }

    /** Record a failed attempt and optionally start cooldown. */
    private function recordFailedAttempt(?int $userId, Request $request): void
    {
        $ip = $request->ip() ?? '0.0.0.0';
        FailedFaceAttempt::create([
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
        ]);

        $key = $this->scanFailCountKey($ip);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinutes(5));

        $maxAttempts = (int) config('attendance.face_cooldown_attempts', 3);
        $cooldownSeconds = (int) config('attendance.face_cooldown_seconds', 30);
        if ($count >= $maxAttempts) {
            Cache::put($this->scanCooldownKey($ip), time() + $cooldownSeconds, now()->addSeconds($cooldownSeconds + 10));
        }
    }

    /** Validate optional geo; throw if outside radius when geo is enabled. */
    private function validateGeo(Request $request): void
    {
        if (! config('attendance.geo_enabled', false)) {
            return;
        }

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        if ($lat === null && $lng === null) {
            return;
        }

        $officeLat = config('attendance.office_latitude');
        $officeLng = config('attendance.office_longitude');
        $radiusMeters = config('attendance.office_radius_meters', 100);

        $distance = $this->haversineMeters((float) $officeLat, (float) $officeLng, (float) $lat, (float) $lng);
        if ($distance > $radiusMeters) {
            throw ValidationException::withMessages([
                'latitude' => ['Attendance must be recorded within office radius.'],
            ]);
        }
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371000; // Earth radius in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $R * $c;
    }

    private function attendanceLogData(Request $request, User $user, string $type): array
    {
        $data = [
            'user_id' => $user->id,
            'type' => $type,
            'verified_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        if ($lat !== null && $lng !== null) {
            $data['latitude'] = $lat;
            $data['longitude'] = $lng;
        }

        return $data;
    }

    /**
     * Ensure that the current time (in attendance timezone) is within the allowed
     * window for clock-in. Uses the employee's schedule for the current weekday
     * (dynamic per schedule: any start/end, including night shift).
     *
     * Time-in rules (per schedule):
     * - Allowed Start = schedule_start − early_timein_minutes (e.g. 7:00 AM when start 8:00, allowance 60).
     * - Late threshold = schedule_start + grace_period (status handled elsewhere).
     * - Blocked if current time is after scheduled shift end.
     *
     * Night shift: if time_out <= time_in (e.g. 22:00 - 06:00), shift end is next day at time_out.
     *
     * Clock-out: not restricted here; undertime and overtime use schedule's early_timeout and overtime_buffer.
     *
     * @throws ValidationException
     */
    private function ensureWithinWorkingHours(User $user, string $type): void
    {
        $schedule = $user->schedule;
        if (! is_array($schedule) || $schedule === []) {
            return;
        }

        if ($type !== AttendanceLog::TYPE_CLOCK_IN) {
            return;
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $now = Carbon::now($tz);
        $dayKey = self::DAY_KEYS[(int) $now->format('w')];
        $daySchedule = $schedule[$dayKey] ?? null;
        if (! is_array($daySchedule)) {
            return;
        }

        $inTime = isset($daySchedule['in']) ? trim((string) $daySchedule['in']) : '';
        $outTime = isset($daySchedule['out']) ? trim((string) $daySchedule['out']) : '';

        if ($inTime === '' || $outTime === '') {
            return;
        }

        $start = Carbon::parse($now->toDateString() . ' ' . $inTime, $tz);
        $endSameDay = Carbon::parse($now->toDateString() . ' ' . $outTime, $tz);

        // Night shift: time_out <= time_in means shift ends next day (e.g. 22:00 - 06:00)
        $end = $outTime <= $inTime
            ? Carbon::parse($now->copy()->addDay()->toDateString() . ' ' . $outTime, $tz)
            : $endSameDay;

        $earliestBefore = isset($daySchedule['early_timein_minutes'])
            ? (int) $daySchedule['early_timein_minutes']
            : (int) config('attendance.earliest_clockin_before_minutes', 60);
        $earliestAllowed = $start->copy()->subMinutes($earliestBefore);

        if ($now->lessThan($earliestAllowed)) {
            throw ValidationException::withMessages([
                'type' => ['Attendance not allowed. You are outside your scheduled working hours.'],
            ]);
        }

        if ($now->greaterThan($end)) {
            throw ValidationException::withMessages([
                'type' => ['Attendance not allowed. You are outside your scheduled working hours.'],
            ]);
        }
    }

    /**
     * Kiosk: clock in or clock out by QR only (no login).
     * Identifies the employee by matching qr_token (string scanned from QR code).
     */
    public function recordKiosk(Request $request): JsonResponse
    {
        $ip = $request->ip() ?? '0.0.0.0';
        $remaining = $this->getCooldownRemaining($ip);
        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'qr_token' => ["Too many failed attempts. Try again in {$remaining} seconds."],
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['required', 'string', 'min:8'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim($validated['qr_token']);

        $this->validateGeo($request);

        $user = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->where('qr_token', $qrToken)
            ->first();

        if (! $user) {
            $this->recordFailedAttempt(null, $request);
            throw ValidationException::withMessages([
                'qr_token' => ['QR code not recognized.'],
            ]);
        }

        $user = $this->refreshUserForScheduleCheck($user);
        $type = $validated['type'];
        $this->enforceLeaveRestrictionsForToday($user, $type);
        $this->ensureUserHasScheduleForToday($user);
        $this->ensureWithinWorkingHours($user, $type);
        if ($user->hasCompletedAttendanceToday()) {
            throw ValidationException::withMessages([
                'type' => ['Your attendance for today is already completed.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
            throw ValidationException::withMessages([
                'type' => ['You have already timed in today.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            throw ValidationException::withMessages([
                'type' => ['Cannot clock out without clocking in first.'],
            ]);
        }

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
        }

        return response()->json([
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_name' => $user->name,
            'attendance' => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Clock in or clock out with QR verification (qr_token must match logged-in employee).
     */
    public function record(Request $request): JsonResponse
    {
        $ip = $request->ip() ?? '0.0.0.0';
        $remaining = $this->getCooldownRemaining($ip);
        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'qr_token' => ["Too many failed attempts. Try again in {$remaining} seconds."],
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['required', 'string', 'min:8'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim($validated['qr_token']);

        $this->validateGeo($request);

        $user = $request->user();

        if ($user->role !== User::ROLE_EMPLOYEE) {
            throw ValidationException::withMessages([
                'type' => ['Only employees can record attendance.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'type' => ['Account is deactivated.'],
            ]);
        }

        $user = $this->refreshUserForScheduleCheck($user);
        $type = $validated['type'];
        $this->enforceLeaveRestrictionsForToday($user, $type);
        $this->ensureUserHasScheduleForToday($user);

        if (empty($user->qr_token)) {
            throw ValidationException::withMessages([
                'qr_token' => ['No QR code enrolled for your account. Please ask an admin to generate your QR code.'],
            ]);
        }

        if (! hash_equals((string) $user->qr_token, (string) $qrToken)) {
            $this->recordFailedAttempt($user->id, $request);
            throw ValidationException::withMessages([
                'qr_token' => ['QR code not recognized.'],
            ]);
        }

        $type = $validated['type'];
        $this->ensureWithinWorkingHours($user, $type);
        if ($user->hasCompletedAttendanceToday()) {
            throw ValidationException::withMessages([
                'type' => ['Your attendance for today is already completed.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
            throw ValidationException::withMessages([
                'type' => ['You have already timed in today.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            throw ValidationException::withMessages([
                'type' => ['Cannot clock out without clocking in first.'],
            ]);
        }

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
        }

        return response()->json([
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'attendance' => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Unified scan endpoint: decode QR → validate in real time → record → JSON.
     *
     * Real-time validations (422 on failure):
     * - No schedule = No scan allowed → "No schedule assigned. Please contact the administrator."
     * - Already timed in = Cannot time in again → "You have already timed in today."
     * - Completed attendance = Cannot scan again → "Your attendance for today is already completed."
     * - Clock out without clock-in → "Cannot clock out without clocking in first."
     * Late status and Half Day are computed automatically when recording clock-in.
     */
    public function scan(Request $request): JsonResponse
    {
        $ip = $request->ip() ?? '0.0.0.0';
        $remaining = $this->getCooldownRemaining($ip);
        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'qr_token' => ["Too many failed attempts. Try again in {$remaining} seconds."],
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['required', 'string', 'min:8'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim($validated['qr_token']);
        $this->validateGeo($request);

        $user = null;
        $isEmployeeContext = false;
        if ($token = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && $accessToken->tokenable instanceof User) {
                $authUser = $accessToken->tokenable;
                if ($authUser->role === User::ROLE_EMPLOYEE && $authUser->is_active) {
                    $isEmployeeContext = true;
                    if (! empty($authUser->qr_token) && hash_equals((string) $authUser->qr_token, (string) $qrToken)) {
                        $user = $authUser;
                    }
                }
            }
        }

        if ($isEmployeeContext && ! $user) {
            $this->recordFailedAttempt($accessToken->tokenable->id ?? null, $request);
            throw ValidationException::withMessages([
                'qr_token' => ['QR code not recognized.'],
            ]);
        }

        if (! $user) {
            $user = User::query()
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('is_active', true)
                ->where('qr_token', $qrToken)
                ->first();

            if (! $user) {
                $this->recordFailedAttempt(null, $request);
                throw ValidationException::withMessages([
                    'qr_token' => ['QR code not recognized.'],
                ]);
            }
        }

        $user = $this->refreshUserForScheduleCheck($user);
        $type = $validated['type'];
        $this->enforceLeaveRestrictionsForToday($user, $type);
        $this->ensureUserHasScheduleForToday($user);
        $this->ensureWithinWorkingHours($user, $type);
        if ($user->hasCompletedAttendanceToday()) {
            throw ValidationException::withMessages([
                'type' => ['Your attendance for today is already completed.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
            throw ValidationException::withMessages([
                'type' => ['You have already timed in today.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            throw ValidationException::withMessages([
                'type' => ['Cannot clock out without clocking in first.'],
            ]);
        }

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
        }

        $payload = [
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_name' => $user->name,
            'attendance' => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ];

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $schedule = $user->schedule;
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );
                $payload['attendance']['status'] = $clockInResult['status'];
                $payload['attendance']['late_minutes'] = $clockInResult['late_minutes'];
                $payload['attendance']['late_label'] = $clockInResult['late_label'];
            }
        }

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $schedule = $user->schedule;
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            $dateKey = $log->created_at->toDateString();
            if ($daySchedule && ! empty($daySchedule['out'])) {
                $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule);
                $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $log->created_at, $earlyTimeout);
                $payload['attendance']['undertime_minutes'] = $undertimeMinutes > 0 ? $undertimeMinutes : 0;
                $payload['attendance']['status'] = $undertimeMinutes > 0 ? 'undertime' : 'present';
            }
        }

        return response()->json($payload, 201);
    }

    /**
     * List attendance logs for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $logs = AttendanceLog::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AttendanceLog $log) => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at->toIso8601String(),
            ]);

        return response()->json(['attendance' => $logs]);
    }

    /**
     * Monthly-style attendance summary for the authenticated user.
     *
     * Returns:
     * - today: status + first time in / last time out
     * - monthly counts: late, absent, half day, undertime, total hours, overtime hours
     * - per-day statuses for calendar (days[])
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        // Default: current month
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
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
        }

        $attendanceTz = $this->attendanceTimezone();
        $from = $from->copy()->timezone($attendanceTz)->startOfDay();
        $to = $to->copy()->timezone($attendanceTz)->endOfDay();

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        // Preload logs and corrections for range
        $logs = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $logsByDate = [];
        foreach ($logs as $log) {
            $dateKey = $log->created_at->copy()->timezone($attendanceTz)->toDateString();
            $logsByDate[$dateKey] = ($logsByDate[$dateKey] ?? collect())->push($log);
        }

        $corrections = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->date->toDateString());

        // Preload approved leaves for range to mark days as "Filed Leave"
        $approvedLeaves = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $leaveDates = [];
        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date->copy()->max($from);
            $leaveEnd = $leave->end_date->copy()->min($to);
            $cursorLeave = $leaveStart->copy();
            while ($cursorLeave->lessThanOrEqualTo($leaveEnd)) {
                $leaveDates[$cursorLeave->toDateString()] = true;
                $cursorLeave->addDay();
            }
        }

        $metrics = [
            'present_count' => 0,
            'late_count' => 0,
            'late_minutes' => 0,
            'halfday_count' => 0,
            'absent_count' => 0,
            'undertime_count' => 0,
            'total_worked_minutes' => 0,
            'overtime_minutes' => 0,
            'leave_count' => 0,
        ];

        $days = [];

        $todayDate = now($attendanceTz)->toDateString();
        $todayStatus = null;
        $todayTimeIn = null;
        $todayTimeOut = null;
        $todayLateMinutes = null;
        $todayUndertimeMinutes = null;

        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $dateKey = $cursor->toDateString();

            $schedule = $user->schedule;
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            /** @var \Illuminate\Support\Collection<int, AttendanceLog>|null $dayLogs */
            $dayLogs = $logsByDate[$dateKey] ?? null;

            [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutesForUser($dayLogs);

            $hasTimeIn = $timeIn !== null;

            $correction = $corrections->get($dateKey)?->first();

            $effectiveTimeIn = $timeIn;
            $effectiveTimeOut = $timeOut;
            $effectiveWorkedMinutes = $workedMinutes;

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
            }

            $status = '—';
            $dayLateMinutes = null;
            $dayUndertimeMinutes = null;
            $isOnLeave = isset($leaveDates[$dateKey]);

            if ($isOnLeave) {
                $status = 'leave';
                $metrics['leave_count']++;
            } elseif ($daySchedule && ! empty($daySchedule['in'])) {
                if (! $effectiveTimeIn) {
                    // Absent only after cutoff (e.g. 5 PM) — for today use cutoff, past days always absent
                    $isToday = $dateKey === $todayDate;
                    $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now());
                    if ($pastCutoff) {
                        $metrics['absent_count']++;
                        $status = 'absent';
                    }
                } else {
                    $timeInCarbon = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                    $clockInResult = AttendanceStatusService::getClockInStatus(
                        $daySchedule,
                        $dateKey,
                        $timeInCarbon
                    );

                    if ($clockInResult['status'] === 'half_day') {
                        $metrics['halfday_count']++;
                        $status = 'halfday';
                    } elseif ($clockInResult['status'] === 'late') {
                        $metrics['late_count']++;
                        $dayLateMinutes = $clockInResult['late_minutes'];
                        // Store and display actual late minutes (Actual Time-In − 8:00 AM)
                        $metrics['late_minutes'] += $clockInResult['late_minutes'];
                        $status = 'late';
                    } else {
                        $status = 'present';
                    }
                }

                if (! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
                    $scheduledStart = Carbon::parse($dateKey . ' ' . $daySchedule['in'], $attendanceTz);
                    $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $attendanceTz);
                    $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $attendanceTz);

                    if ($effectiveWorkedMinutes !== null && $status !== 'halfday' && $scheduledEnd) {
                        $outCarbon = $effectiveTimeOut instanceof Carbon
                            ? $effectiveTimeOut
                            : ($effectiveTimeOut ? Carbon::parse($effectiveTimeOut) : null);
                        $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                        $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $outCarbon, $earlyTimeout);
                        $dayUndertimeMinutes = $undertimeMinutes > 0 ? $undertimeMinutes : null;
                        if ($undertimeMinutes > 0 || $effectiveWorkedMinutes < $requiredMinutes - $undertimeThresholdMinutes) {
                            $metrics['undertime_count']++;
                            $status = 'undertime';
                        }

                        if ($effectiveWorkedMinutes > $requiredMinutes) {
                            $metrics['overtime_minutes'] += $effectiveWorkedMinutes - $requiredMinutes;
                        }
                    }
                }
            }

            // Treat any past or cutoff-passed day without a specific status as absent
            if ($status === '—' && ! $isOnLeave) {
                $isToday = $dateKey === $todayDate;
                $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, now());
                if ($pastCutoff) {
                    $metrics['absent_count']++;
                    $status = 'absent';
                }
            }

            if ($hasTimeIn && $status === '—') {
                $status = 'present';
            }

            if ($effectiveWorkedMinutes !== null) {
                $metrics['total_worked_minutes'] += $effectiveWorkedMinutes;
            }

            $days[] = [
                'date' => $dateKey,
                'status' => $status,
                'time_in' => $effectiveTimeIn
                    ? ($effectiveTimeIn instanceof Carbon
                        ? $effectiveTimeIn
                        : Carbon::parse($effectiveTimeIn)
                    )->toIso8601String()
                    : null,
                'time_out' => $effectiveTimeOut
                    ? ($effectiveTimeOut instanceof Carbon
                        ? $effectiveTimeOut
                        : Carbon::parse($effectiveTimeOut)
                    )->toIso8601String()
                    : null,
                'total_hours' => $effectiveWorkedMinutes !== null
                    ? round($effectiveWorkedMinutes / 60, 2)
                    : null,
                'late_minutes' => $dayLateMinutes,
                'undertime_minutes' => $dayUndertimeMinutes,
            ];

            if ($dateKey === $todayDate) {
                $todayStatus = $status;
                $todayTimeIn = $this->formatTimeInAttendanceTz($effectiveTimeIn);
                $todayTimeOut = $this->formatTimeInAttendanceTz($effectiveTimeOut);
                $todayLateMinutes = $dayLateMinutes;
                $todayUndertimeMinutes = $dayUndertimeMinutes;
            }

            $cursor->addDay();
        }

        $summary = [
            'present_count' => $metrics['present_count'],
            'late_count' => $metrics['late_count'],
            'late_minutes' => $metrics['late_minutes'],
            'halfday_count' => $metrics['halfday_count'],
            'absent_count' => $metrics['absent_count'],
            'undertime_count' => $metrics['undertime_count'],
            'total_hours' => $metrics['total_worked_minutes'] > 0
                ? round($metrics['total_worked_minutes'] / 60, 2)
                : 0,
            'overtime_hours' => $metrics['overtime_minutes'] > 0
                ? round($metrics['overtime_minutes'] / 60, 2)
                : 0,
                'leave_count' => $metrics['leave_count'],
            'today' => [
                'date' => $todayDate,
                'status' => $todayStatus,
                'time_in' => $todayTimeIn,
                'time_out' => $todayTimeOut,
                'late_minutes' => $todayLateMinutes,
                'undertime_minutes' => $todayUndertimeMinutes,
            ],
        ];

        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'summary' => $summary,
            'days' => $days,
        ]);
    }

    /**
     * Extract first time in, last time out, and worked minutes from logs.
     *
     * @param \Illuminate\Support\Collection<int, AttendanceLog>|null $logs
     * @return array{0: ?\Carbon\CarbonInterface, 1: ?\Carbon\CarbonInterface, 2: ?int}
     */
    private function extractTimesAndWorkedMinutesForUser($logs): array
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

        $workedMinutes = $clockIn === null ? $total : null;

        return [$timeIn, $timeOut, $workedMinutes];
    }

    /**
     * Kiosk: list recent attendance logs (for display on login page DTR panel).
     * Public, no auth. Returns last 20 logs with employee name, time, status (on_time/late/half_day), and late_label.
     */
    public function recentKiosk(Request $request): JsonResponse
    {
        $logs = AttendanceLog::query()
            ->with('user:id,name,schedule')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (AttendanceLog $log) {
                $info = $this->kioskLogStatus($log);
                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'employee_name' => $log->user?->name ?? '—',
                    'created_at' => $log->created_at->toIso8601String(),
                    'status' => $info['status'] ?? null,
                    'late_minutes' => $info['late_minutes'] ?? null,
                    'late_label' => $info['late_label'] ?? null,
                ];
            });

        return response()->json(['attendance' => $logs]);
    }

    /**
     * Compute display status for a kiosk log (Present / Late / Half-Day per schedule 8:00–5:00 rules).
     * Clock-in time is converted to attendance timezone so 9:35 AM local = Late (2 hours).
     *
     * @return array{status: string, late_minutes: int, late_label: string}|null
     */
    private function kioskLogStatus(AttendanceLog $log): ?array
    {
        if ($log->type !== AttendanceLog::TYPE_CLOCK_IN) {
            return null;
        }
        $user = $log->user;
        if (! $user || empty($user->schedule) || ! is_array($user->schedule)) {
            return null;
        }
        $tz = $this->attendanceTimezone();
        $clockInAt = $log->created_at->copy()->timezone($tz);
        $dayKey = self::DAY_KEYS[(int) $clockInAt->format('w')];
        $daySchedule = $user->schedule[$dayKey] ?? null;
        if (! $daySchedule || empty($daySchedule['in'])) {
            return null;
        }
        $dateKey = $clockInAt->toDateString();
        $result = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $clockInAt);

        return [
            'status' => $result['status'],
            'late_minutes' => $result['late_minutes'],
            'late_label' => $result['late_label'],
        ];
    }
}
