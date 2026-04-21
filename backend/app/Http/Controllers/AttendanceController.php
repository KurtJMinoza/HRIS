<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\FailedFaceAttempt;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Services\AttendancePresenceDisplayService;
use App\Services\AttendanceStatusService;
use App\Services\FaceAuthService;
use App\Services\LeaveCreditService;
use App\Services\OvertimeService;
use App\Services\PremiumPayCalculatorService;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtimeService,
        private readonly PremiumPayCalculatorService $premiumPayCalculator,
        private readonly AttendancePresenceDisplayService $presenceDisplay,
        private readonly PresenceFilingCorrectionFormatter $presenceFilingFormatter,
        private readonly LeaveCreditService $leaveCreditService,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** No schedule assigned at all (Admin → Schedule). */
    private const NO_SCHEDULE_ASSIGNED_MESSAGE = 'No schedule assigned. Please contact the administrator.';

    /** Schedule exists but today is not a workday. */
    private const NOT_SCHEDULED_TODAY_MESSAGE = 'You are not scheduled to work today.';

    /** Rest day / no 'in' time for today (legacy alias for NOT_SCHEDULED_TODAY_MESSAGE). */
    private const SCHEDULE_ERROR_MESSAGE = self::NOT_SCHEDULED_TODAY_MESSAGE;

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
            ->select(['id', 'name', 'username', 'email', 'phone_number', 'schedule', 'working_schedule_id', 'qr_token', 'is_active', 'role', 'profile_image'])
            ->where('id', $user->id)
            ->first();

        return $fresh ?? $user;
    }

    /**
     * Resolve user from QR token with company mismatch validation.
     * Throws ValidationException on outdated QR, company mismatch, or unknown token.
     */
    private function resolveUserFromQrToken(string $qrToken): User
    {
        if (! preg_match('/^DTR-EMP-(\d+)(?:-CO-(\d+))?$/', trim($qrToken), $m)) {
            throw ValidationException::withMessages([
                'qr_token' => ['Invalid QR code. Please scan again.'],
            ]);
        }

        $employeeId = (int) $m[1];
        $tokenCompanyId = isset($m[2]) ? (int) $m[2] : null;

        $user = User::query()
            ->where('id', $employeeId)
            ->where('is_active', true)
            ->first();

        if (! $user || ! $user->canRecordOwnAttendanceViaQrOrFace()) {
            throw ValidationException::withMessages([
                'qr_token' => ['Invalid QR code. Please scan again.'],
            ]);
        }

        if (! hash_equals((string) $user->qr_token, (string) $qrToken)) {
            throw ValidationException::withMessages([
                'qr_token' => [
                    'This QR code is outdated. Employee has been transferred. Please refresh to get the latest QR.',
                ],
            ]);
        }

        $currentCompanyId = $user->getEffectiveCompanyId();

        if ($tokenCompanyId !== null) {
            if ($currentCompanyId === null) {
                throw ValidationException::withMessages([
                    'qr_token' => [
                        'This QR code is outdated. Employee is no longer assigned to a company. Please refresh to get the latest QR.',
                    ],
                ]);
            }
            if ((int) $currentCompanyId !== $tokenCompanyId) {
                // Token matched DB (hash_equals above) but -CO- segment is stale—e.g. admin changed
                // company/branch/dept without regenerating QR. Heal DB and allow this scan; next scan
                // must use the new token from Profile → My QR.
                $user->forceFill([
                    'qr_token' => User::generateQrTokenFor($user),
                    'qr_token_generated_at' => now(),
                ])->save();
                $user->refresh();
            }
        } else {
            if ($currentCompanyId !== null) {
                throw ValidationException::withMessages([
                    'qr_token' => [
                        'This QR code is outdated. Employee has been assigned to a company. Please refresh to get the latest QR.',
                    ],
                ]);
            }
        }

        return $user;
    }

    /**
     * Resolve active employee via username or email (kiosk fallback).
     */
    private function resolveUserFromLoginIdentifier(string $login): User
    {
        $normalized = mb_strtolower(trim($login));
        $user = User::query()
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(email) = ?', [$normalized])
                    ->orWhereRaw('LOWER(username) = ?', [$normalized]);
            })
            ->where('is_active', true)
            ->first();

        if (! $user || ! $user->canRecordOwnAttendanceViaQrOrFace()) {
            throw ValidationException::withMessages([
                'login' => ['Account not recognized. Please use your username or email.'],
            ]);
        }

        return $user;
    }

    /**
     * Enforce leave-based attendance restrictions for today.
     *
     * Rules:
     * - Full-day leave (vacation/sick/emergency/other) blocks all attendance.
     * - Half Day (half_day + half_type):
     *   - AM half (leave morning, work afternoon): prevent first time-in before 12:00 PM.
     *   - PM half (work morning, leave afternoon): prevent time-out before scheduled shift end.
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
                    // AM half: employee is on leave for the morning and works only the afternoon.
                    // Block first time-in before 12:00 PM so they can only start in the afternoon.
                    $noon = Carbon::parse($today.' 12:00:00', $tz);
                    if ($now->lessThan($noon)) {
                        throw ValidationException::withMessages([
                            'type' => ['Attendance not allowed before 12:00 PM for AM half-day leave.'],
                        ]);
                    }
                }

                if (in_array($halfType, ['am', 'pm'], true) && $attendanceType === AttendanceLog::TYPE_CLOCK_OUT) {
                    // For both AM and PM half-day:
                    // Business rule: do NOT allow early clock-out before scheduled shift end
                    // (e.g. 17:00) unless a separate undertime is approved.

                    $schedule = $user->schedule;
                    if (is_array($schedule) && $schedule !== []) {
                        $dayKey = self::DAY_KEYS[(int) now($tz)->format('w')];
                        $daySchedule = $schedule[$dayKey] ?? null;
                        if (is_array($daySchedule)) {
                            $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($today, $daySchedule, $tz);
                            if ($scheduledEnd instanceof Carbon && $now->lessThan($scheduledEnd)) {
                                $endTimeStr = $this->formatTimeInAttendanceTz($scheduledEnd);
                                throw ValidationException::withMessages([
                                    'type' => ["Logout is not allowed yet. Your shift ends at {$endTimeStr}."],
                                ]);
                            }
                        }
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
     * Build a per-day schedule array from a WorkingSchedule model, matching the shape stored on users.schedule.
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    private function buildScheduleFromWorkingSchedule(?\App\Models\WorkingSchedule $schedule): ?array
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
     * Default shift for kiosk Present/Late/Half Day labels when the employee has no usable
     * per-day row (missing schedule JSON, rest day, or empty time-in) — 8:00 + grace rules.
     *
     * @return array<string, mixed>
     */
    private function defaultKioskDayScheduleForStatus(): array
    {
        return [
            'in' => '08:00',
            'out' => '17:00',
            'grace_period_minutes' => (int) config('attendance.grace_period_minutes', 5),
        ];
    }

    /**
     * Validates that the user has an assigned schedule for today. Blocks attendance if not.
     * Schedule may come from admin/schedules (working_schedule_id) or admin/employees (manual).
     * Either is valid as long as today has an 'in' time.
     *
     * @throws ValidationException
     */
    private function ensureUserHasScheduleForToday(User $user): void
    {
        $schedule = $user->schedule;

        // Fallback: if schedule JSON is missing but a working_schedule_id is present,
        // derive the effective schedule from the related WorkingSchedule so attendance
        // still works even when only working_schedule_id was set (e.g. via profile UI).
        if ((! is_array($schedule) || $schedule === null || $schedule === []) && $user->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $schedule = $derived;
                // Set on the in-memory User instance so subsequent checks in this request use it.
                $user->schedule = $schedule;
            }
        }

        if ($schedule === null || ! is_array($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => [self::NO_SCHEDULE_ASSIGNED_MESSAGE],
            ]);
        }
        if ($schedule === []) {
            throw ValidationException::withMessages([
                'schedule' => [self::NO_SCHEDULE_ASSIGNED_MESSAGE],
            ]);
        }

        $today = now($this->attendanceTimezone());
        $dayKey = self::DAY_KEYS[(int) $today->format('w')];
        $todaySchedule = isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
        if ($todaySchedule === null || ! is_array($todaySchedule)) {
            throw ValidationException::withMessages([
                'schedule' => [self::NOT_SCHEDULED_TODAY_MESSAGE],
            ]);
        }
        $inTime = isset($todaySchedule['in']) ? trim((string) $todaySchedule['in']) : '';
        if ($inTime === '') {
            throw ValidationException::withMessages([
                'schedule' => [self::NOT_SCHEDULED_TODAY_MESSAGE],
            ]);
        }
    }

    /** Record a failed attempt for Admin panel. Spoof attempts and denial reasons are logged. */
    private function recordFailedAttempt(?int $userId, Request $request, bool $isSpoof = false, ?string $failureReason = null): void
    {
        $ip = $request->ip() ?? '0.0.0.0';
        FailedFaceAttempt::create([
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
            'is_spoof' => $isSpoof,
            'failure_reason' => $failureReason,
        ]);
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

    /**
     * @param  array{similarity_score?: float|null, liveness_score?: float|null, authentication_method?: string|null}  $faceContext
     */
    private function attendanceLogData(Request $request, User $user, string $type, array $faceContext = []): array
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
        if (isset($faceContext['similarity_score'])) {
            $data['similarity_score'] = $faceContext['similarity_score'];
        }
        if (isset($faceContext['liveness_score'])) {
            $data['liveness_score'] = $faceContext['liveness_score'];
        }
        if (isset($faceContext['authentication_method'])) {
            $data['authentication_method'] = $faceContext['authentication_method'];
        }

        return $data;
    }

    /**
     * Ensure that the current time (in attendance timezone) is within the allowed
     * window for attendance actions based on schedule (supports night shift).
     *
     * Time-in rules:
     * - Allowed Start = schedule_start − early_timein_minutes.
     * - Blocked if current time is after scheduled shift end.
     *
     * Time-out rules:
     * - Blocked if current time is far outside the shift window (after a configurable cutoff).
     *
     * @throws ValidationException
     */
    private function ensureWithinWorkingHours(User $user, string $type): void
    {
        $user->loadMissing('workingSchedule');
        $schedule = $user->schedule;
        if ((! is_array($schedule) || $schedule === []) && $user->working_schedule_id) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if (is_array($derived) && $derived !== []) {
                $schedule = $derived;
            }
        }
        if (! is_array($schedule) || $schedule === []) {
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

        $start = Carbon::parse($now->toDateString().' '.$inTime, $tz);
        $endSameDay = Carbon::parse($now->toDateString().' '.$outTime, $tz);

        // Night shift: time_out <= time_in means shift ends next day (e.g. 22:00 - 06:00)
        $end = $outTime <= $inTime
            ? Carbon::parse($now->copy()->addDay()->toDateString().' '.$outTime, $tz)
            : $endSameDay;

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            $earliestBefore = isset($daySchedule['early_timein_minutes'])
                ? (int) $daySchedule['early_timein_minutes']
                : (int) config('attendance.earliest_clockin_before_minutes', 60);
            $earliestAllowed = $start->copy()->subMinutes($earliestBefore);

            if ($now->lessThan($earliestAllowed) || $now->greaterThan($end)) {
                throw ValidationException::withMessages([
                    'type' => ['Clock-in is not allowed at this time.'],
                ]);
            }

            return;
        }

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            // Latest allowed time to record a clock-out after scheduled end.
            $latestAfter = isset($daySchedule['latest_clockout_after_minutes'])
                ? (int) $daySchedule['latest_clockout_after_minutes']
                : (int) config('attendance.latest_clockout_after_minutes', 60);
            $latestAllowed = $end->copy()->addMinutes($latestAfter);

            // Approved OT: allow real clock-out up to expected end + grace (do not synthetic time-out).
            $grace = (int) config('attendance.overtime_approved_clockout_grace_minutes', 30);
            $todayYmd = $now->toDateString();
            $approvedOts = Overtime::query()
                ->where('user_id', $user->id)
                ->where('status', Overtime::STATUS_APPROVED)
                ->whereDate('date', $todayYmd)
                ->orderByDesc('id')
                ->get();

            $scheduleMap = EmployeeScheduleResolver::resolve($user);
            if (is_array($scheduleMap) && $scheduleMap !== []) {
                foreach ($approvedOts as $ot) {
                    if (! $ot->expected_end_time) {
                        continue;
                    }
                    $otDateYmd = $ot->date->toDateString();
                    $carbonOt = Carbon::parse($otDateYmd, $tz);
                    $otDayKey = EmployeeScheduleResolver::dayKeyForDate($carbonOt);
                    $otDay = $scheduleMap[$otDayKey] ?? null;
                    if (! is_array($otDay) || empty(trim((string) ($otDay['in'] ?? ''))) || empty(trim((string) ($otDay['out'] ?? '')))) {
                        continue;
                    }
                    $schStart = Carbon::parse($otDateYmd.' '.trim((string) $otDay['in']), $tz);
                    $schEnd = Carbon::parse($otDateYmd.' '.trim((string) $otDay['out']), $tz);
                    if ($schEnd->lessThanOrEqualTo($schStart)) {
                        $schEnd->addDay();
                    }
                    $overnightOt = $schEnd->toDateString() !== $schStart->toDateString();
                    $expectedEnd = Carbon::parse($otDateYmd.' '.$ot->expected_end_time->format('H:i:s'), $tz);
                    if ($overnightOt && $expectedEnd->lessThanOrEqualTo($schStart)) {
                        $expectedEnd->addDay();
                    }
                    $withGrace = $expectedEnd->copy()->addMinutes($grace);
                    if ($withGrace->greaterThan($latestAllowed)) {
                        $latestAllowed = $withGrace;
                    }
                }
            }

            if ($now->greaterThan($latestAllowed)) {
                throw ValidationException::withMessages([
                    'type' => ['Clock-out is not allowed at this time. Your shift for today has already ended.'],
                ]);
            }
        }
    }

    /**
     * Block attendance if today is a configured holiday (prevents QR scan on holidays).
     */
    private function ensureNotHolidayForAttendance(): void
    {
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $today = Carbon::now($tz)->toDateString();
        $holidays = config('attendance.holidays', []);
        if (! is_array($holidays)) {
            $holidays = [];
        }
        if (in_array($today, $holidays, true)) {
            throw ValidationException::withMessages([
                'type' => ['Attendance not allowed. Today is a holiday.'],
            ]);
        }
    }

    /**
     * Send attendance SMS immediately via PhilSMS (synchronous).
     * Queue is bypassed to make delivery and logging easier to verify.
     */
    private function sendAttendanceSms(User $user, string $message, string $context): void
    {
        app(\App\Services\SmsService::class)->sendToUser($user, $message);
    }

    /**
     * Build attendance SMS message with authentication method (QR Code or Face Recognition).
     *
     * @param  string  $type  clock_in or clock_out
     * @param  string  $timeOnly  Formatted time (e.g. "8:30 AM")
     * @param  string|null  $classification  Present, Late, Half Day, etc. (for clock_in only)
     * @param  string|null  $authMethod  AttendanceLog::AUTH_METHOD_QR, AUTH_METHOD_FACE, or null
     */
    private function buildAttendanceSmsMessage(string $type, string $timeOnly, ?string $classification, ?string $authMethod): string
    {
        $methodPhrase = $authMethod === AttendanceLog::AUTH_METHOD_FACE
            ? ' via Face Recognition'
            : ($authMethod === AttendanceLog::AUTH_METHOD_QR
                ? ' via QR Code'
                : ($authMethod === AttendanceLog::AUTH_METHOD_CREDENTIALS
                    ? ' via Credentials'
                    : ''));

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            if ($classification === 'Present' || ($classification !== null && str_starts_with((string) $classification, 'Present'))) {
                return "You have successfully logged in at {$timeOnly}{$methodPhrase}. Status: {$classification}.";
            }

            return "You logged in at {$timeOnly}{$methodPhrase}. Status: {$classification}.";
        }

        return "You have successfully logged out at {$timeOnly}{$methodPhrase}. Total hours will be reflected in your DTR.";
    }

    /**
     * Kiosk: clock in or clock out by QR only (no login).
     * Identifies the employee by matching qr_token (string scanned from QR code).
     */
    public function recordKiosk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['nullable', 'string', 'min:8', 'required_without:login'],
            'login' => ['nullable', 'string', 'max:255', 'required_without:qr_token'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim((string) ($validated['qr_token'] ?? ''));
        $login = trim((string) ($validated['login'] ?? ''));

        $this->validateGeo($request);

        try {
            if ($qrToken !== '') {
                $user = $this->resolveUserFromQrToken($qrToken);
            } else {
                $user = $this->resolveUserFromLoginIdentifier($login);
            }
        } catch (ValidationException $e) {
            $this->recordFailedAttempt(null, $request);
            throw $e;
        }

        $user = $this->refreshUserForScheduleCheck($user);
        $type = $validated['type'];
        $this->enforceLeaveRestrictionsForToday($user, $type);
        $this->ensureUserHasScheduleForToday($user);
        $this->ensureNotHolidayForAttendance();
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

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, [
            'authentication_method' => $qrToken !== '' ? AttendanceLog::AUTH_METHOD_QR : AttendanceLog::AUTH_METHOD_CREDENTIALS,
        ]));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
            $this->premiumPayCalculator->computeAndStore($log);
        }

        $attendanceTime = $log->created_at
            ->copy()
            ->timezone($this->attendanceTimezone());
        $timeOnly = $attendanceTime->format('g:i A');
        $context = $type === AttendanceLog::TYPE_CLOCK_IN ? 'attendance_clock_in' : 'attendance_clock_out';

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            // Compute DTR classification (Present / Late buckets / Half Day) for SMS.
            $schedule = $user->schedule;
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            $classification = 'Present';
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );

                if ($clockInResult['status'] === 'half_day') {
                    $classification = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $classification = $clockInResult['late_label'] ?? 'Late';
                } else {
                    $classification = $clockInResult['late_label'] ?? 'Present';
                }
            }

            $msg = $this->buildAttendanceSmsMessage(
                $type,
                $timeOnly,
                $classification,
                $qrToken !== '' ? AttendanceLog::AUTH_METHOD_QR : AttendanceLog::AUTH_METHOD_CREDENTIALS
            );
        } else {
            $msg = $this->buildAttendanceSmsMessage(
                $type,
                $timeOnly,
                null,
                $qrToken !== '' ? AttendanceLog::AUTH_METHOD_QR : AttendanceLog::AUTH_METHOD_CREDENTIALS
            );
        }
        $this->sendAttendanceSms($user, $msg, $context);

        $payload = [
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'employee_profile_image_url' => $user->profile_image_url,
            'employee_profile_image' => $user->profile_image,
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

                // Derive final status from the day's first clock-in classification,
                // not only from undertime. This prevents "On time" when the day started late.
                $status = 'present';
                // Use same UTC range as hasCompletedAttendanceToday / summaries.
                // Use the same "today" range logic as User::attendanceTodayRangeUtc(), but duplicated here
                // because the model method is protected.
                $tzToday = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                $todayStartTz = Carbon::now($tzToday)->startOfDay();
                $startUtc = $todayStartTz->copy()->setTimezone('UTC');
                $endUtc = $todayStartTz->copy()->endOfDay()->setTimezone('UTC');
                $firstIn = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->whereBetween('created_at', [$startUtc, $endUtc])
                    ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                    ->orderBy('created_at')
                    ->first();

                if ($firstIn && $daySchedule && ! empty($daySchedule['in'])) {
                    $tz = $this->attendanceTimezone();
                    $firstInDateKey = $firstIn->created_at->copy()->timezone($tz)->toDateString();
                    $clockInResult = AttendanceStatusService::getClockInStatus(
                        $daySchedule,
                        $firstInDateKey,
                        $firstIn->created_at
                    );
                    // Map clock-in status directly; UI can combine late + undertime if needed.
                    $status = $clockInResult['status'];
                    $payload['attendance']['late_minutes'] = $clockInResult['late_minutes'];
                    $payload['attendance']['late_label'] = $clockInResult['late_label'];
                } elseif ($undertimeMinutes > 0) {
                    $status = 'undertime';
                }

                $payload['attendance']['status'] = $status;
            }
        }

        return response()->json($payload, 201);
    }

    /**
     * Clock in or clock out with QR verification (qr_token must match logged-in employee).
     */
    public function record(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['required', 'string', 'min:8'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim($validated['qr_token']);

        $this->validateGeo($request);

        $user = $request->user();

        if (! $user->canRecordOwnAttendanceViaQrOrFace()) {
            throw ValidationException::withMessages([
                'type' => ['Your account cannot record attendance this way.'],
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
        $this->ensureNotHolidayForAttendance();

        if (empty($user->qr_token)) {
            throw ValidationException::withMessages([
                'qr_token' => ['No QR code enrolled for your account. Please ask an admin to generate your QR code.'],
            ]);
        }

        if (! hash_equals((string) $user->qr_token, (string) $qrToken)) {
            $this->recordFailedAttempt($user->id, $request);
            throw ValidationException::withMessages([
                'qr_token' => ['Invalid QR code. Please scan again.'],
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

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, [
            'authentication_method' => AttendanceLog::AUTH_METHOD_QR,
        ]));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
            $this->premiumPayCalculator->computeAndStore($log);
        }

        $attendanceTime = $log->created_at
            ->copy()
            ->timezone($this->attendanceTimezone());
        $timeOnly = $attendanceTime->format('g:i A');
        $context = $type === AttendanceLog::TYPE_CLOCK_IN ? 'attendance_clock_in' : 'attendance_clock_out';

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            $schedule = $user->schedule;
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            $classification = 'Present';
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );

                if ($clockInResult['status'] === 'half_day') {
                    $classification = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $classification = $clockInResult['late_label'] ?? 'Late';
                } else {
                    $classification = $clockInResult['late_label'] ?? 'Present';
                }
            }

            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, $classification, AttendanceLog::AUTH_METHOD_QR);
        } else {
            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, null, AttendanceLog::AUTH_METHOD_QR);
        }
        $this->sendAttendanceSms($user, $msg, $context);

        return response()->json([
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'employee_profile_image_url' => $user->profile_image_url,
            'employee_profile_image' => $user->profile_image,
            'attendance' => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Record clock-in or clock-out for the given user (e.g. after face login).
     * If user has timed in but not out → clock out; otherwise → clock in.
     * Does not throw; returns result so caller can include in response.
     *
     * @param  array{similarity_score?: float|null, liveness_score?: float|null, authentication_method?: string|null}  $faceContext
     * @return array{recorded: bool, message?: string, attendance?: array}
     */
    public function recordClockInForUser(User $user, Request $request, array $faceContext = []): array
    {
        try {
            $user = $this->refreshUserForScheduleCheck($user);

            if ($user->hasCompletedAttendanceToday()) {
                return ['recorded' => false, 'message' => 'Your attendance for today is already completed.'];
            }

            $type = $user->canClockOutToday()
                ? AttendanceLog::TYPE_CLOCK_OUT
                : AttendanceLog::TYPE_CLOCK_IN;

            $this->enforceLeaveRestrictionsForToday($user, $type);
            $this->ensureUserHasScheduleForToday($user);
            $this->ensureNotHolidayForAttendance();
            $this->ensureWithinWorkingHours($user, $type);

            if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
                return ['recorded' => false, 'message' => 'You have already timed in today.'];
            }
            if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
                return ['recorded' => false, 'message' => 'Cannot clock out without clocking in first.'];
            }

            $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, $faceContext));
            $attendanceTime = $log->created_at->copy()->timezone($this->attendanceTimezone());
            $timeOnly = $attendanceTime->format('g:i A');

            if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
                $this->overtimeService->createOrUpdateFromClockOut($user, $log);
                $this->premiumPayCalculator->computeAndStore($log);
                $authMethod = $faceContext['authentication_method'] ?? null;
                $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, null, $authMethod);
                $this->sendAttendanceSms($user, $msg, 'attendance_clock_out');

                return [
                    'recorded' => true,
                    'message' => 'Clocked out successfully.',
                    'attendance' => [
                        'id' => $log->id,
                        'type' => $log->type,
                        'created_at' => $log->created_at->toIso8601String(),
                        'status' => 'Clocked Out',
                    ],
                ];
            }

            $schedule = $user->schedule;
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            $classification = 'Present';
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );
                if ($clockInResult['status'] === 'half_day') {
                    $classification = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $classification = $clockInResult['late_label'] ?? 'Late';
                } else {
                    $classification = $clockInResult['late_label'] ?? 'Present';
                }
            }
            $authMethod = $faceContext['authentication_method'] ?? null;
            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, $classification, $authMethod);
            $this->sendAttendanceSms($user, $msg, 'attendance_clock_in');

            return [
                'recorded' => true,
                'message' => 'Clocked in successfully.',
                'attendance' => [
                    'id' => $log->id,
                    'type' => $log->type,
                    'created_at' => $log->created_at->toIso8601String(),
                    'status' => $classification,
                ],
            ];
        } catch (ValidationException $e) {
            $messages = $e->validator->errors()->all();

            return ['recorded' => false, 'message' => $messages[0] ?? 'Attendance could not be recorded.'];
        } catch (\Throwable $e) {
            return ['recorded' => false, 'message' => 'Attendance could not be recorded.'];
        }
    }

    /**
     * Unified scan endpoint: decode QR → validate in real time → record → JSON.
     *
     * Real-time validations (422 on failure):
     * - No schedule assigned → "No schedule assigned. Please contact the administrator."
     * - Not scheduled today → "You are not scheduled to work today."
     * - Outside clock-in window → "Clock-in is not allowed at this time."
     * - Already timed in = Cannot time in again → "You have already timed in today."
     * - Completed attendance = Cannot scan again → "Your attendance for today is already completed."
     * - Clock out without clock-in → "Cannot clock out without clocking in first."
     * Late status and Half Day are computed automatically when recording clock-in.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'qr_token' => ['required', 'string', 'min:8'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $qrToken = trim($validated['qr_token']);
        $this->validateGeo($request);

        try {
            $user = $this->resolveUserFromQrToken($qrToken);
        } catch (ValidationException $e) {
            $this->recordFailedAttempt(null, $request);
            throw $e;
        }

        $user = $this->refreshUserForScheduleCheck($user);
        $type = $validated['type'];
        $this->enforceLeaveRestrictionsForToday($user, $type);
        $this->ensureUserHasScheduleForToday($user);
        $this->ensureNotHolidayForAttendance();
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

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, [
            'authentication_method' => AttendanceLog::AUTH_METHOD_QR,
        ]));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
            $this->premiumPayCalculator->computeAndStore($log);
        }

        $attendanceTime = $log->created_at
            ->copy()
            ->timezone($this->attendanceTimezone());
        $timeOnly = $attendanceTime->format('g:i A');
        $context = $type === AttendanceLog::TYPE_CLOCK_IN ? 'attendance_clock_in' : 'attendance_clock_out';

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            $schedule = $user->schedule;
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            $classification = 'Present';
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );

                if ($clockInResult['status'] === 'half_day') {
                    $classification = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $classification = $clockInResult['late_label'] ?? 'Late';
                } else {
                    $classification = $clockInResult['late_label'] ?? 'Present';
                }
            }

            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, $classification, AttendanceLog::AUTH_METHOD_QR);
        } else {
            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, null, AttendanceLog::AUTH_METHOD_QR);
        }
        $this->sendAttendanceSms($user, $msg, $context);

        $payload = [
            'message' => $type === 'clock_in' ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'employee_profile_image_url' => $user->profile_image_url,
            'employee_profile_image' => $user->profile_image,
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

                // Derive final status from the day's first clock-in classification,
                // not only from undertime, so a late day is never labeled "on time".
                $status = 'present';
                $tzToday = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                $todayStartTz = Carbon::now($tzToday)->startOfDay();
                $startUtc = $todayStartTz->copy()->setTimezone('UTC');
                $endUtc = $todayStartTz->copy()->endOfDay()->setTimezone('UTC');
                $firstIn = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->whereBetween('created_at', [$startUtc, $endUtc])
                    ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                    ->orderBy('created_at')
                    ->first();

                if ($firstIn && $daySchedule && ! empty($daySchedule['in'])) {
                    $tz = $this->attendanceTimezone();
                    $firstInDateKey = $firstIn->created_at->copy()->timezone($tz)->toDateString();
                    $clockInResult = AttendanceStatusService::getClockInStatus(
                        $daySchedule,
                        $firstInDateKey,
                        $firstIn->created_at
                    );
                    $status = $clockInResult['status'];
                    $payload['attendance']['late_minutes'] = $clockInResult['late_minutes'];
                    $payload['attendance']['late_label'] = $clockInResult['late_label'];
                } elseif ($undertimeMinutes > 0) {
                    $status = 'undertime';
                }

                $payload['attendance']['status'] = $status;
            }
        }

        return response()->json($payload, 201);
    }

    /**
     * Verify-only: run face verification on a frame (legacy; prefer Rekognition Face Liveness for login/kiosk).
     */
    public function verifyFaceOnly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image_base64' => ['required', 'string'],
        ]);

        $result = FaceAuthService::verifyFace($validated['image_base64']);
        if ($result === null) {
            return response()->json([
                'is_live' => false,
                'spoof_confidence' => null,
                'message' => 'Face verification service unavailable.',
            ], 422);
        }

        return response()->json([
            'is_live' => $result['is_live'],
            'spoof_confidence' => $result['spoof_confidence'] ?? null,
            'message' => $result['message'] ?? '',
        ]);
    }

    /**
     * Kiosk face scan: Amazon Rekognition Face Liveness session or legacy image.
     * Verify liveness → extract descriptor → identify user → record attendance.
     */
    public function scanFace(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:clock_in,clock_out'],
            'liveness_session_id' => ['nullable', 'string', 'max:255'],
            'image_base64' => ['nullable', 'string'],
            'client_capture_started_at_ms' => ['nullable', 'numeric'],
        ]);
        $sessionId = $validated['liveness_session_id'] ?? null;
        $imageBase64 = $validated['image_base64'] ?? null;
        if (! $sessionId && ! $imageBase64) {
            throw ValidationException::withMessages([
                'face' => ['Perform face liveness first, then submit the session.'],
            ]);
        }

        $result = $sessionId
            ? FaceAuthService::verifyFaceWithLivenessSession($sessionId)
            : FaceAuthService::verifyFace($imageBase64);
        if ($result === null) {
            $this->recordFailedAttempt(null, $request, false, 'service_unavailable');

            return response()->json([
                'message' => 'Face verification service unavailable. Please try again or use QR.',
                'errors' => ['face' => ['Face verification service unavailable. Please try again or use QR.']],
                'error_code' => 'service_unavailable',
            ], 422);
        }
        if (! $result['is_live']) {
            $this->recordFailedAttempt(null, $request, true, 'spoof_detected');

            return response()->json([
                'message' => 'Spoof attempt detected. Please perform a live face scan.',
                'errors' => ['face' => ['Spoof attempt detected. Please perform a live face scan.']],
                'error_code' => 'spoof_detected',
            ], 422);
        }

        // Rekognition session path: liveness confidence already validated in RekognitionLivenessService.
        if (! $sessionId) {
            $minLiveness = (float) config('attendance.face_min_liveness_score', 0.52);
            $spoofConfidence = isset($result['spoof_confidence']) ? (float) $result['spoof_confidence'] : null;
            if ($spoofConfidence === null || $spoofConfidence < $minLiveness) {
                $this->recordFailedAttempt(null, $request, true, 'liveness_failed');

                return response()->json([
                    'message' => 'Liveness confidence too low. Please complete the face liveness check again.',
                    'errors' => ['face' => ['Liveness confidence too low. Please complete the face liveness check again.']],
                    'error_code' => 'spoof_detected',
                ], 422);
            }
        }

        if (empty($result['descriptor']) || count($result['descriptor']) !== 128) {
            $this->recordFailedAttempt(null, $request, false, 'no_face_detected');

            return response()->json([
                'message' => $result['message'] ?: 'No face detected. Position your face in the frame.',
                'errors' => ['face' => [$result['message'] ?: 'No face detected. Position your face in the frame.']],
                'error_code' => 'no_face_detected',
            ], 422);
        }

        $matchStarted = microtime(true);
        $identified = FaceAuthService::identifyUserWithScore($result['descriptor'], kioskMode: true);
        $matchMs = round((microtime(true) - $matchStarted) * 1000, 1);
        if (! $identified) {
            $this->recordFailedAttempt(null, $request, false, 'face_not_recognized');

            return response()->json([
                'message' => 'We could not match your face to an enrolled profile this time. Face the camera straight-on, use even lighting (avoid backlight), then try again—or scan your QR code below.',
                'errors' => ['face' => ['No match this time. Try again with better lighting, or use your QR code on this kiosk.']],
                'error_code' => 'face_not_recognized',
                'hint' => 'If you are enrolled, try once more; automatic retries may help. QR is always available below.',
                'fallback' => 'qr',
                'performance' => ['match_ms' => $matchMs],
            ], 422);
        }

        $identifiedUser = $identified['user'];
        if (! $identifiedUser->hasRegisteredFace()) {
            $this->recordFailedAttempt($identifiedUser->id, $request, false, 'face_not_registered');

            return response()->json([
                'message' => 'No registered face found. Please register your face before using facial recognition clock-in.',
                'errors' => ['face' => ['No registered face found. Please register your face before using facial recognition clock-in.']],
                'error_code' => 'face_not_registered',
            ], 422);
        }

        $user = $this->refreshUserForScheduleCheck($identifiedUser);
        $type = $validated['type'];
        $faceContext = [
            'similarity_score' => $identified['similarity_score'],
            'liveness_score' => $result['spoof_confidence'] ?? null,
            'authentication_method' => AttendanceLog::AUTH_METHOD_FACE,
        ];

        try {
            $this->enforceLeaveRestrictionsForToday($user, $type);
            $this->ensureUserHasScheduleForToday($user);
            $this->ensureNotHolidayForAttendance();
            $this->ensureWithinWorkingHours($user, $type);
        } catch (ValidationException $e) {
            $reason = $e->getMessage() && str_contains($e->getMessage(), 'schedule') ? 'schedule_validation_failed' : 'leave_restriction';
            $this->recordFailedAttempt($user->id, $request, false, $reason);
            throw $e;
        }
        if ($user->hasCompletedAttendanceToday()) {
            $this->recordFailedAttempt($user->id, $request, false, 'attendance_already_completed');
            throw ValidationException::withMessages([
                'type' => ['Your attendance for today is already completed.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
            $this->recordFailedAttempt($user->id, $request, false, 'already_timed_in');
            throw ValidationException::withMessages([
                'type' => ['You have already timed in today.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            $this->recordFailedAttempt($user->id, $request, false, 'cannot_clock_out');
            throw ValidationException::withMessages([
                'type' => ['Cannot clock out without clocking in first.'],
            ]);
        }

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, $faceContext));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->createOrUpdateFromClockOut($user, $log);
            $this->premiumPayCalculator->computeAndStore($log);
        }

        $attendanceTime = $log->created_at
            ->copy()
            ->timezone($this->attendanceTimezone());
        $timeOnly = $attendanceTime->format('g:i A');
        $context = $type === AttendanceLog::TYPE_CLOCK_IN ? 'attendance_clock_in' : 'attendance_clock_out';

        if ($type === AttendanceLog::TYPE_CLOCK_IN) {
            $schedule = $user->schedule;
            $dayKey = self::DAY_KEYS[(int) $log->created_at->format('w')];
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            $classification = 'Present';
            if ($daySchedule && ! empty($daySchedule['in'])) {
                $clockInResult = AttendanceStatusService::getClockInStatus(
                    $daySchedule,
                    $log->created_at->toDateString(),
                    $log->created_at
                );
                if ($clockInResult['status'] === 'half_day') {
                    $classification = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $classification = $clockInResult['late_label'] ?? 'Late';
                } else {
                    $classification = $clockInResult['late_label'] ?? 'Present';
                }
            }
            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, $classification, AttendanceLog::AUTH_METHOD_FACE);
        } else {
            $msg = $this->buildAttendanceSmsMessage($type, $timeOnly, null, AttendanceLog::AUTH_METHOD_FACE);
        }
        $this->sendAttendanceSms($user, $msg, $context);

        $payload = [
            'message' => $type === AttendanceLog::TYPE_CLOCK_IN ? 'Clocked in successfully.' : 'Clocked out successfully.',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'employee_profile_image_url' => $user->profile_image_url,
            /** Storage path fallback — SPA resolves via same logic as Employee Profile (`profile_image` + `profile_image_url`). */
            'employee_profile_image' => $user->profile_image,
            'attendance' => [
                'id' => $log->id,
                'type' => $log->type,
                'verified_at' => $log->verified_at?->toIso8601String(),
                'created_at' => $log->created_at?->toIso8601String(),
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

                // Derive final status from the day's first clock-in classification,
                // not only from undertime.
                $status = 'present';
                $tzToday = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                $todayStartTz = Carbon::now($tzToday)->startOfDay();
                $startUtc = $todayStartTz->copy()->setTimezone('UTC');
                $endUtc = $todayStartTz->copy()->endOfDay()->setTimezone('UTC');
                $firstIn = AttendanceLog::query()
                    ->where('user_id', $user->id)
                    ->whereBetween('created_at', [$startUtc, $endUtc])
                    ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                    ->orderBy('created_at')
                    ->first();

                if ($firstIn && $daySchedule && ! empty($daySchedule['in'])) {
                    $tz = $this->attendanceTimezone();
                    $firstInDateKey = $firstIn->created_at->copy()->timezone($tz)->toDateString();
                    $clockInResult = AttendanceStatusService::getClockInStatus(
                        $daySchedule,
                        $firstInDateKey,
                        $firstIn->created_at
                    );
                    $status = $clockInResult['status'];
                    $payload['attendance']['late_minutes'] = $clockInResult['late_minutes'];
                    $payload['attendance']['late_label'] = $clockInResult['late_label'];
                } elseif ($undertimeMinutes > 0) {
                    $status = 'undertime';
                }

                $payload['attendance']['status'] = $status;
            }
        }

        $payload['performance'] = [
            'server_processing_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'match_ms' => $matchMs,
            'client_capture_started_at_ms' => isset($validated['client_capture_started_at_ms']) ? (int) $validated['client_capture_started_at_ms'] : null,
        ];

        Log::info('Kiosk face scan performance', [
            'user_id' => $user->id,
            'type' => $type,
            'uses_liveness_session' => ! empty($sessionId),
            'server_processing_ms' => $payload['performance']['server_processing_ms'],
            'match_ms' => $matchMs,
            'similarity_score' => $identified['similarity_score'],
            'client_capture_started_at_ms' => $payload['performance']['client_capture_started_at_ms'],
        ]);

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

        // Preload logs and corrections for range.
        // IMPORTANT: Attendance views are based on the punch timestamp (`verified_at`), not row insertion time (`created_at`).
        $logs = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$from->copy()->setTimezone('UTC'), $to->copy()->setTimezone('UTC')])
            ->orderBy('verified_at')
            ->get();

        $logsByDate = [];
        foreach ($logs as $log) {
            $stamp = $log->verified_at ?? $log->created_at;
            if (! $stamp) {
                continue;
            }
            $dateKey = $stamp->copy()->timezone($attendanceTz)->toDateString();
            $logsByDate[$dateKey] = ($logsByDate[$dateKey] ?? collect())->push($log);
        }

        $corrections = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'rejectedBy',
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
            ])
            ->get()
            ->groupBy(fn ($c) => $c->date->toDateString());

        // Preload approved leaves for range to mark days as "Filed Leave"
        $approvedLeaves = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get();

        $this->leaveCreditService->ensureAnnualRechargeForUserId((int) $user->id);

        /** @var array<string, LeaveRequest> $leaveByDate Most recent overlapping request wins (matches payroll tie-break). */
        $leaveByDate = [];
        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date->copy()->max($from);
            $leaveEnd = $leave->end_date->copy()->min($to);
            $cursorLeave = $leaveStart->copy();
            while ($cursorLeave->lessThanOrEqualTo($leaveEnd)) {
                $ds = $cursorLeave->toDateString();
                $existing = $leaveByDate[$ds] ?? null;
                if ($existing === null || (int) $leave->id > (int) $existing->id) {
                    $leaveByDate[$ds] = $leave;
                }
                $cursorLeave->addDay();
            }
        }

        $overtimeByDate = Overtime::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Overtime $o) => $o->date->toDateString());

        $metrics = [
            'present_count' => 0,
            'late_count' => 0,
            'late_minutes' => 0,
            'halfday_count' => 0,
            'absent_count' => 0,
            'undertime_count' => 0,
            'total_worked_minutes' => 0,
            'approved_overtime_minutes' => 0,
            'leave_count' => 0,
        ];

        $days = [];

        $todayNow = now($attendanceTz);
        $todayDate = $todayNow->toDateString();
        $todayStatus = null;
        $todayTimeIn = null;
        $todayTimeOut = null;
        $todayLateMinutes = null;
        $todayLateLabel = null;
        $todayUndertimeMinutes = null;
        $todayVirtualTimeOutFromOt = false;
        $todayEmployeeStatusLabel = null;
        $todayPresenceLabel = null;
        $todayPresenceIssue = null;
        $todayLeavePayStatus = null;

        // Resolve effective schedule once: prefer legacy JSON, fall back to working_schedule_id.
        $user->loadMissing('workingSchedule');
        $effectiveSchedule = $user->schedule;
        if ((! is_array($effectiveSchedule) || $effectiveSchedule === []) && $user->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $effectiveSchedule = $derived;
            }
        }

        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $dateKey = $cursor->toDateString();
            $isToday = $dateKey === $todayDate;
            $isFuture = $cursor->greaterThan($todayNow->copy()->startOfDay());

            $schedule = $effectiveSchedule;
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            /** @var \Illuminate\Support\Collection<int, AttendanceLog>|null $dayLogs */
            $dayLogs = $logsByDate[$dateKey] ?? null;

            [$timeIn, $timeOut, $workedMinutes] = $this->extractTimesAndWorkedMinutesForUser($dayLogs);

            $hasTimeIn = $timeIn !== null;
            $hasTimeOut = $timeOut !== null;

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
                    $effectiveWorkedMinutes = $daySchedule
                        ? AttendanceStatusService::getNetWorkedMinutes(
                            $correction->time_in,
                            $correction->time_out,
                            $daySchedule,
                            $dateKey,
                            $attendanceTz
                        )
                        : (int) $correction->time_in->diffInMinutes($correction->time_out);
                }
            }

            // For regular clock-in/out logs (no correction covering both times), deduct the
            // schedule's unpaid break window so worked hours are consistent with Admin → Reports.
            if (! ($correction && $correction->approved && $correction->time_in && $correction->time_out)) {
                if ($daySchedule && $effectiveTimeIn && $effectiveTimeOut && $effectiveWorkedMinutes !== null) {
                    $tIn = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                    $tOut = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                    $effectiveWorkedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                        $tIn, $tOut, $daySchedule, $dateKey, $attendanceTz
                    );
                }
            }

            $virtualTimeOutFromOt = false;
            if ($effectiveTimeIn && $effectiveTimeOut === null) {
                $otApproved = $overtimeByDate->get($dateKey);
                if ($otApproved && $otApproved->status === Overtime::STATUS_APPROVED) {
                    $resolvedOut = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                        $otApproved,
                        $dateKey,
                        is_array($daySchedule) ? $daySchedule : null,
                        $attendanceTz
                    );
                    if ($resolvedOut !== null) {
                        $effectiveTimeOut = $resolvedOut;
                        $virtualTimeOutFromOt = true;
                        $tIn = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                        $tOut = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                        $effectiveWorkedMinutes = $daySchedule
                            ? AttendanceStatusService::getNetWorkedMinutes(
                                $tIn,
                                $tOut,
                                $daySchedule,
                                $dateKey,
                                $attendanceTz
                            )
                            : max(0, (int) $tIn->diffInMinutes($tOut));
                    }
                }
            }

            $status = '—';
            $dayLateMinutes = null;
            $dayLateLabel = null;
            $dayUndertimeMinutes = null;
            $leaveOnDate = $leaveByDate[$dateKey] ?? null;
            $isOnLeave = $leaveOnDate !== null;

            $leavePayStatus = null;
            if ($isOnLeave && $leaveOnDate) {
                $lt = strtolower((string) $leaveOnDate->type);
                if ($lt !== 'undertime' && $this->leaveCreditService->consumesCredits($lt)) {
                    $leavePayStatus = $this->leaveCreditService->isPaidLeaveDay($user, $leaveOnDate, $dateKey)
                        ? 'paid'
                        : 'unpaid';
                }
            }

            if ($isOnLeave) {
                $status = 'leave';
                $metrics['leave_count']++;
            } else {
                // Rest day / not scheduled: never surface punches (e.g., Sundays) in employee attendance.
                // Even if logs exist, these days must show no time in/out and no "present/absent".
                $isWorkday = is_array($daySchedule) && ! empty($daySchedule['in']);
                if (! $isWorkday) {
                    $effectiveTimeIn = null;
                    $effectiveTimeOut = null;
                    $effectiveWorkedMinutes = null;
                    $hasTimeIn = false;
                    $hasTimeOut = false;
                }
            }

            if (! $isOnLeave && ($daySchedule && ! empty($daySchedule['in']))) {
                if (! $effectiveTimeIn) {
                    if ($effectiveTimeOut) {
                        // Clock-out without clock-in: still Present (incomplete pair), never Absent.
                        $status = 'present';
                    } elseif (! $isFuture) {
                        // Absent only after cutoff (e.g. 5 PM). Do NOT mark future dates as absent.
                        $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, $todayNow);
                        if ($pastCutoff) {
                            $metrics['absent_count']++;
                            $status = 'absent';
                        }
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
                        $dayLateLabel = $clockInResult['late_label'] ?? 'Half Day';
                    } elseif ($clockInResult['status'] === 'late') {
                        $metrics['late_count']++;
                        $dayLateMinutes = $clockInResult['late_minutes'];
                        $dayLateLabel = $clockInResult['late_label'] ?? 'Late';
                        // Store and display actual late minutes (Actual Time-In − 8:00 AM)
                        $metrics['late_minutes'] += $clockInResult['late_minutes'];
                        $status = 'late';
                    } else {
                        $status = 'present';
                        // Expose grace-period classification (e.g. "Present") for DTR / employee UI parity with admin
                        $dayLateLabel = $clockInResult['late_label'] ?? 'Present';
                    }
                }

                if (! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
                    $scheduledStart = Carbon::parse($dateKey.' '.$daySchedule['in'], $attendanceTz);
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
                    }

                    // If there is a clock-in but no clock-out after the shift window has ended,
                    // align with admin attendance/reports and mark it as incomplete for the employee view.
                    if ($effectiveTimeIn && ! $effectiveTimeOut && $scheduledEnd) {
                        $pastShiftEnd = $dateKey < $todayDate || ($dateKey === $todayDate && $todayNow->greaterThan($scheduledEnd));
                        if ($pastShiftEnd) {
                            $status = 'incomplete';
                        }
                    }

                    // Still on duty: primary status is Clocked In (late info may still show in late_minutes).
                    if ($effectiveTimeIn && ! $effectiveTimeOut && $status !== 'incomplete' && $status !== 'halfday' && $status !== 'leave') {
                        if ($status === 'late') {
                            $metrics['late_count'] = max(0, $metrics['late_count'] - 1);
                            $metrics['late_minutes'] = max(0, $metrics['late_minutes'] - (int) ($dayLateMinutes ?? 0));
                        }
                        $status = 'clocked_in';
                    }
                }
            }

            // Treat any past or cutoff-passed day without a specific status as absent,
            // but never mark future dates as absent.
            if ($status === '—' && ! $isOnLeave && ! $isFuture) {
                $pastCutoff = ! $isToday || AttendanceStatusService::isPastAbsentCutoff($dateKey, $todayNow);
                if ($pastCutoff) {
                    $metrics['absent_count']++;
                    $status = 'absent';
                }
            }

            if (($hasTimeIn || $hasTimeOut) && $status === '—') {
                // Only treat punches as presence on scheduled workdays.
                if (is_array($daySchedule) && ! empty($daySchedule['in'])) {
                    $status = 'present';
                }
            }

            $qualified = $this->presenceDisplay->qualify(
                $dateKey,
                $todayDate,
                $todayNow,
                is_array($daySchedule) ? $daySchedule : null,
                $status,
                $effectiveTimeIn,
                $effectiveTimeOut,
                $correction,
                $isFuture,
            );
            $status = $qualified['status'];
            $presenceLabel = $qualified['presence_label'];
            $presenceIssue = $qualified['presence_issue'];

            if ($status === 'present') {
                $metrics['present_count']++;
            }

            if ($effectiveWorkedMinutes !== null) {
                $metrics['total_worked_minutes'] += $effectiveWorkedMinutes;
            }

            $dayLogsForDate = $logsByDate[$dateKey] ?? null;
            $clockOutForDay = $dayLogsForDate ? $dayLogsForDate->first(fn ($l) => $l->type === AttendanceLog::TYPE_CLOCK_OUT) : null;

            $otRow = $overtimeByDate->get($dateKey);
            $hasEffectiveTimeOut = $effectiveTimeOut !== null;

            $approvedOtHours = ($otRow && $otRow->status === Overtime::STATUS_APPROVED)
                ? (float) ($otRow->computed_hours ?? 0)
                : 0.0;
            $logOtHours = $clockOutForDay?->overtime_hours;
            $renderedOtHours = $logOtHours !== null ? round((float) $logOtHours, 2) : null;
            // Payable OT in attendance views = approved OT module hours only (rendered OT is separate).
            $displayOvertimeHours = $approvedOtHours > 0.0001 ? round($approvedOtHours, 2) : null;
            if ($approvedOtHours > 0.0001) {
                $metrics['approved_overtime_minutes'] += (int) round($approvedOtHours * 60);
            }
            $displayNightHours = $clockOutForDay?->night_hours;
            $premiumTypeForDesc = $clockOutForDay?->premium_type;
            if ($premiumTypeForDesc === null && $otRow && $otRow->ph_ot_rule) {
                $premiumTypeForDesc = match ((string) $otRow->ph_ot_rule) {
                    'ORD' => 'ordinary',
                    'RD' => 'rest_day',
                    'RH', 'RHRD' => 'regular_holiday',
                    'SH', 'SHRD' => 'special_holiday',
                    'DH', 'DHRD' => 'regular_holiday',
                    default => 'ordinary',
                };
            }

            $showOtPremiumFields = $hasEffectiveTimeOut || $approvedOtHours > 0.0001;
            $employeeStatusLabel = ($approvedOtHours > 0.0001 && in_array($status, ['present', 'late'], true))
                ? ($status === 'late' ? 'Late + OT' : 'Present + OT')
                : null;

            $scheduledRegularMinutes = null;
            if (is_array($daySchedule) && ! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
                $scheduledRegularMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $attendanceTz);
            }

            $scheduleInDay = is_array($daySchedule) && ! empty($daySchedule['in'])
                ? (string) $daySchedule['in']
                : null;
            $scheduleOutDay = is_array($daySchedule) && ! empty($daySchedule['out'])
                ? (string) $daySchedule['out']
                : null;

            $scheduleAssignedForRow = is_array($effectiveSchedule) && $effectiveSchedule !== [];
            $isRestDayRow = false;
            if (! $isOnLeave && $scheduleAssignedForRow) {
                $isRestDayRow = ! (is_array($daySchedule) && ! empty(trim((string) ($daySchedule['in'] ?? ''))));
            }

            $days[] = [
                'date' => $dateKey,
                'status' => $status,
                'is_rest_day' => $isRestDayRow,
                'employee_status_label' => $employeeStatusLabel,
                'schedule_in' => $scheduleInDay,
                'schedule_out' => $scheduleOutDay,
                // Match Admin → Attendance: plain H:i in attendance TZ (avoids UI parsing UTC ISO as wall clock).
                'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                'virtual_time_out_from_ot' => $virtualTimeOutFromOt,
                'scheduled_regular_hours' => $scheduledRegularMinutes !== null && $scheduledRegularMinutes > 0
                    ? round($scheduledRegularMinutes / 60, 2)
                    : null,
                'total_rendered_hours' => $effectiveWorkedMinutes !== null
                    ? round($effectiveWorkedMinutes / 60, 2)
                    : null,
                'total_hours' => $effectiveWorkedMinutes !== null
                    ? round($effectiveWorkedMinutes / 60, 2)
                    : null,
                'late_minutes' => $dayLateMinutes,
                'late_label' => $dayLateLabel,
                'undertime_minutes' => $dayUndertimeMinutes,
                'overtime_minutes' => $showOtPremiumFields && $renderedOtHours !== null && $renderedOtHours > 0
                    ? (int) round($renderedOtHours * 60)
                    : null,
                'rendered_overtime_hours' => $showOtPremiumFields && $renderedOtHours !== null ? $renderedOtHours : null,
                'overtime_hours' => $showOtPremiumFields ? $displayOvertimeHours : null,
                'night_hours' => $hasEffectiveTimeOut ? $displayNightHours : null,
                'premium_type' => $showOtPremiumFields ? ($clockOutForDay?->premium_type ?? $premiumTypeForDesc) : null,
                'premium_description' => $showOtPremiumFields
                    ? AttendanceStatusService::getPremiumDescription(
                        $renderedOtHours ?? $displayOvertimeHours,
                        $hasEffectiveTimeOut ? $displayNightHours : null,
                        $premiumTypeForDesc
                    )
                    : null,
                'calculated_pay_factor' => $hasEffectiveTimeOut ? $clockOutForDay?->calculated_pay_factor : null,
                'overtime_status' => $otRow?->status,
                'approved_overtime_hours' => $approvedOtHours > 0 ? round($approvedOtHours, 2) : null,
                'has_approved_overtime' => $approvedOtHours > 0.0001,
                'presence_label' => $presenceLabel,
                'presence_issue' => $presenceIssue,
                'presence_filing' => $this->presenceFilingPayloadForSummary($correction, $attendanceTz),
                'leave_pay_status' => $leavePayStatus,
            ];

            if ($dateKey === $todayDate) {
                $todayStatus = $status;
                $todayTimeIn = $this->formatTimeInAttendanceTz($effectiveTimeIn);
                $todayTimeOut = $this->formatTimeInAttendanceTz($effectiveTimeOut);
                $todayLateMinutes = $dayLateMinutes;
                $todayLateLabel = $dayLateLabel;
                $todayUndertimeMinutes = $dayUndertimeMinutes;
                $todayVirtualTimeOutFromOt = $virtualTimeOutFromOt;
                $todayEmployeeStatusLabel = $employeeStatusLabel;
                $todayPresenceLabel = $presenceLabel;
                $todayPresenceIssue = $presenceIssue;
                $todayLeavePayStatus = $leavePayStatus;
            }

            $cursor->addDay();
        }

        $scheduleAssigned = is_array($effectiveSchedule) && $effectiveSchedule !== [];

        $summary = [
            'schedule_assigned' => $scheduleAssigned,
            'present_count' => $metrics['present_count'],
            'late_count' => $metrics['late_count'],
            'late_minutes' => $metrics['late_minutes'],
            'halfday_count' => $metrics['halfday_count'],
            'absent_count' => $metrics['absent_count'],
            'undertime_count' => $metrics['undertime_count'],
            'total_hours' => $metrics['total_worked_minutes'] > 0
                ? round($metrics['total_worked_minutes'] / 60, 2)
                : 0,
            'overtime_hours' => $metrics['approved_overtime_minutes'] > 0
                ? round($metrics['approved_overtime_minutes'] / 60, 2)
                : 0,
            'leave_count' => $metrics['leave_count'],
            'today' => [
                'date' => $todayDate,
                'status' => $todayStatus,
                'employee_status_label' => $todayEmployeeStatusLabel,
                'time_in' => $todayTimeIn,
                'time_out' => $todayTimeOut,
                'virtual_time_out_from_ot' => $todayVirtualTimeOutFromOt,
                'late_minutes' => $todayLateMinutes,
                'late_label' => $todayLateLabel ?? null,
                'undertime_minutes' => $todayUndertimeMinutes,
                'presence_label' => $todayPresenceLabel,
                'presence_issue' => $todayPresenceIssue,
                'presence_filing' => $this->presenceFilingPayloadForSummary($corrections->get($todayDate)?->first(), $attendanceTz),
                'leave_pay_status' => $todayLeavePayStatus,
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
     * Full presence filing payload (same shape as employee/admin presence-filing APIs), including
     * approval_progress with profile_image_url for each step — used by attendance summary so the
     * employee calendar modal matches the admin corrections view.
     *
     * @return array<string, mixed>|null
     */
    private function presenceFilingPayloadForSummary(?AttendanceCorrection $correction, string $tz): ?array
    {
        if ($correction === null) {
            return null;
        }
        if ($correction->reason_code === null && ! $correction->pending_approval && $correction->rejected_at === null) {
            return null;
        }

        return $this->presenceFilingFormatter->format(
            $correction,
            $tz,
            includeEmployee: true,
            actor: null,
            includeDisplayFields: true
        );
    }

    /**
     * Extract first time in, last time out, and worked minutes from logs.
     *
     * @param  \Illuminate\Support\Collection<int, AttendanceLog>|null  $logs
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
            // IMPORTANT: attendance views must use the punch timestamp (`verified_at`),
            // not insertion time (`created_at`). Admin Attendance and payroll are based on `verified_at`.
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
     * Kiosk: list recent attendance logs (for display on login page DTR panel).
     * Public, no auth. Returns merged logs + manual corrections (newest first) with employee name, time, status, and late_label.
     */
    public function recentKiosk(Request $request): JsonResponse
    {
        $tz = $this->attendanceTimezone();
        $todayDate = Carbon::now($tz)->toDateString();

        // Regular attendance logs (fetch enough rows to merge with manual rows before take()).
        $logEntries = AttendanceLog::query()
            ->with([
                'user:id,name,schedule,working_schedule_id,profile_image,department_id,company_id,branch_id',
                'user.workingSchedule:id,time_in,time_out,break_start,break_end,grace_period_minutes,early_timein_minutes,late_allowance_minutes,early_timeout_minutes,overtime_buffer_minutes,rest_days',
                'user.companyHeadships:id,name,logo,company_head_id',
                'user.company:id,name,logo',
                'user.branch:id,company_id',
                'user.branch.company:id,name,logo',
                'user.departmentRelation:id,branch_id',
                'user.departmentRelation.branch:id,company_id',
                'user.departmentRelation.branch.company:id,name,logo',
            ])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(function (AttendanceLog $log) {
                $info = $this->kioskLogStatus($log);
                $user = $log->user;
                $company = $user?->companyHeadships->first() ?? $user?->company ?? $user?->branch?->company ?? $user?->departmentRelation?->branch?->company;
                $companyLogoUrl = $company && is_string($company->logo) && trim($company->logo) !== ''
                    ? $this->publicMediaUrl($company->logo)
                    : null;

                return [
                    'id' => $log->id,
                    'type' => $log->type,
                    'employee_id' => $user?->id,
                    'employee_name' => $log->user?->name ?? '—',
                    'employee_profile_image_url' => $user?->profile_image_url,
                    'employee_profile_image' => $user?->profile_image,
                    'company' => ['name' => $company?->name, 'logo_url' => $companyLogoUrl],
                    'created_at' => $log->created_at->toIso8601String(),
                    'status' => $info['status'] ?? null,
                    'late_minutes' => $info['late_minutes'] ?? null,
                    'late_label' => $info['late_label'] ?? null,
                    '_ts' => $log->created_at->timestamp,
                ];
            });

        // Manual corrections as synthetic feed rows. Sort key uses updated_at (when saved) so
        // backdated times still surface as "recent" after admin entry; punch times stay in created_at for display.
        $correctionRecentCutoff = Carbon::now('UTC')->subHours(48);
        $correctionEntries = AttendanceCorrection::query()
            ->where(function ($q) use ($todayDate, $correctionRecentCutoff) {
                $q->whereDate('date', $todayDate)
                    ->orWhere('updated_at', '>=', $correctionRecentCutoff);
            })
            // Critical integrity rule: kiosk should only reflect fully approved (HR-final) corrections.
            // Pending or partially approved filings must NOT appear here.
            ->where('approved', true)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->with([
                'user:id,name,schedule,working_schedule_id,profile_image,department_id,company_id,branch_id',
                'user.workingSchedule:id,time_in,time_out,break_start,break_end,grace_period_minutes,early_timein_minutes,late_allowance_minutes,early_timeout_minutes,overtime_buffer_minutes,rest_days',
                'user.companyHeadships:id,name,logo,company_head_id',
                'user.company:id,name,logo',
                'user.branch:id,company_id',
                'user.branch.company:id,name,logo',
                'user.departmentRelation:id,branch_id',
                'user.departmentRelation.branch:id,company_id',
                'user.departmentRelation.branch.company:id,name,logo',
            ])
            ->get()
            ->flatMap(function (AttendanceCorrection $corr) use ($tz) {
                $user = $corr->user;
                $company = $user?->companyHeadships->first() ?? $user?->company ?? $user?->branch?->company ?? $user?->departmentRelation?->branch?->company;
                $companyLogoUrl = $company && is_string($company->logo) && trim($company->logo) !== ''
                    ? $this->publicMediaUrl($company->logo)
                    : null;
                $sortTs = ($corr->updated_at ?? $corr->created_at ?? Carbon::now('UTC'))->timestamp;
                $inStatus = null;
                if ($user && $corr->time_in) {
                    $inStatus = $this->kioskClockInDisplayStatus($user, $corr->time_in);
                }
                $baseCommon = [
                    'employee_id' => $user?->id,
                    'employee_name' => $user?->name ?? '—',
                    'employee_profile_image_url' => $user?->profile_image_url,
                    'employee_profile_image' => $user?->profile_image,
                    'company' => ['name' => $company?->name, 'logo_url' => $companyLogoUrl],
                ];
                $entries = collect();
                if ($corr->time_in) {
                    $entries->push(array_merge($baseCommon, [
                        'id' => 'manual-in-'.$corr->id,
                        'type' => AttendanceLog::TYPE_CLOCK_IN,
                        'created_at' => $corr->time_in->copy()->timezone($tz)->toIso8601String(),
                        '_ts' => $sortTs,
                        'status' => $inStatus['status'] ?? 'manual',
                        'late_minutes' => $inStatus['late_minutes'] ?? null,
                        'late_label' => $inStatus['late_label'] ?? null,
                    ]));
                }
                if ($corr->time_out) {
                    $entries->push(array_merge($baseCommon, [
                        'id' => 'manual-out-'.$corr->id,
                        'type' => AttendanceLog::TYPE_CLOCK_OUT,
                        'created_at' => $corr->time_out->copy()->timezone($tz)->toIso8601String(),
                        '_ts' => $sortTs,
                        'status' => null,
                        'late_minutes' => null,
                        'late_label' => null,
                    ]));
                }

                return $entries;
            });

        // Merge both sources, sort newest first, keep top 25, strip internal sort key.
        $merged = $logEntries->concat($correctionEntries)
            ->sortByDesc('_ts')
            ->unique(function (array $e) use ($tz) {
                $employeeId = (int) ($e['employee_id'] ?? 0);
                $type = (string) ($e['type'] ?? '');
                $dateKey = Carbon::parse((string) ($e['created_at'] ?? now()->toIso8601String()))
                    ->timezone($tz)
                    ->toDateString();

                return $employeeId.'|'.$dateKey.'|'.$type;
            })
            ->take(25)
            ->map(function (array $e) {
                unset($e['_ts']);

                return $e;
            })
            ->values();

        return response()->json(['attendance' => $merged]);
    }

    private function publicMediaUrl(string $rawPath): string
    {
        $rawPath = trim($rawPath);
        if ($rawPath === '') {
            return '';
        }
        if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
            return $rawPath;
        }

        $normalized = ltrim($rawPath, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);
        $encodedPath = implode('/', $encoded);

        return url('/api/media/public/'.$encodedPath);
    }

    /**
     * Present / Late / Half Day for kiosk using employee schedule, or default 8:00 shift if missing.
     *
     * @return array{status: string, late_minutes: int, late_label: string}
     */
    private function kioskClockInDisplayStatus(User $user, Carbon $clockInAt): array
    {
        $schedule = is_array($user->schedule) ? $user->schedule : [];
        if ($schedule === [] && $user->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $schedule = $derived;
            }
        }

        $tz = $this->attendanceTimezone();
        $clockInAt = $clockInAt->copy()->timezone($tz);
        $dayKey = self::DAY_KEYS[(int) $clockInAt->format('w')];
        $daySchedule = $schedule[$dayKey] ?? null;
        if (! is_array($daySchedule) || empty(trim((string) ($daySchedule['in'] ?? '')))) {
            $daySchedule = $this->defaultKioskDayScheduleForStatus();
        }
        $dateKey = $clockInAt->toDateString();

        return AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $clockInAt);
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
        if (! $user) {
            return null;
        }

        $result = $this->kioskClockInDisplayStatus($user, $log->created_at);

        return [
            'status' => $result['status'],
            'late_minutes' => $result['late_minutes'],
            'late_label' => $result['late_label'],
        ];
    }
}
