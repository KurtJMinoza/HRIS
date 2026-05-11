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
use App\Services\FaceVerificationService;
use App\Services\LeaveCreditService;
use App\Services\OtDetectionService;
use App\Services\OvertimeService;
use App\Services\PayrollComputationService;
use App\Services\PremiumPayCalculatorService;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        private readonly OtDetectionService $otDetectionService,
        private readonly PayrollComputationService $payrollComputation,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** Employee `/attendance/summary`: max rows per page when `per_page` is sent (caps payload + payroll hydration). */
    private const EMPLOYEE_SUMMARY_MAX_PER_PAGE = 124;

    /** Log slow employee attendance summary responses (ms). */
    private const EMPLOYEE_SUMMARY_SLOW_WARN_MS = 200;

    /** Full JSON payload cache for employee summary (revision bumps when source rows change). */
    private const EMPLOYEE_SUMMARY_RESPONSE_CACHE_SECONDS = 900;

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

    /**
     * SmartDTR kiosk sends X-Kiosk-Attendance: 1 so we do not treat the request as the employee app
     * when a Sanctum session exists but no Bearer token is sent (same browser logged in elsewhere).
     */
    private function isKioskAttendanceClient(Request $request): bool
    {
        return strtoupper((string) $request->header('X-Kiosk-Attendance', '')) === '1';
    }

    /**
     * Kiosk duplicate clock-in (or similar): employee should use Presence / Attendance Correction workflow instead.
     */
    private function kioskAttendanceCorrectionConflictResponse(User $user, string $reason, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['type' => [$message]],
            'error_code' => 'kiosk_attendance_correction',
            'kiosk_correction' => [
                'reason' => $reason,
                'employee_id' => $user->id,
                'employee_name' => $user->name,
                'employee_profile_image_url' => $user->profile_image_url,
                'employee_profile_image' => $user->profile_image,
            ],
        ], 422);
    }

    /**
     * After kiosk clock-out with no recorded clock-in for the day, prompt correction filing so Daily Computation / DTR stay coherent.
     */
    private function appendKioskClockOutWithoutClockInHint(array &$payload, User $user): void
    {
        $payload['kiosk_correction'] = [
            'suggested' => true,
            'reason' => 'clock_out_without_clock_in',
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'employee_profile_image_url' => $user->profile_image_url,
            'employee_profile_image' => $user->profile_image,
        ];
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
     * Hydrate the user's schedule from working_schedule_id if the JSON field is empty.
     * Non-blocking: always allows attendance regardless of schedule presence.
     *
     * Per Enhanced Attendance Logic Section 2: "The system must not block any clock action."
     * Employees can clock in/out on rest days, holidays, unscheduled days, or any time.
     */
    private function hydrateUserSchedule(User $user): void
    {
        $schedule = $user->schedule;

        if ((! is_array($schedule) || $schedule === null || $schedule === []) && $user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $user->schedule = $derived;
            }
        }
    }

    /**
     * @deprecated Replaced by hydrateUserSchedule() which is non-blocking.
     * Kept temporarily for reference during migration.
     */
    private function ensureUserHasScheduleForToday(User $user): void
    {
        $this->hydrateUserSchedule($user);
    }

    /** Record a failed attempt for Admin panel. Spoof attempts and denial reasons are logged. */
    private function recordFailedAttempt(?int $userId, Request $request, bool $isSpoof = false, ?string $failureReason = null, array $context = []): void
    {
        $ip = $request->ip() ?? '0.0.0.0';
        FailedFaceAttempt::create([
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
            'is_spoof' => $isSpoof,
            'failure_reason' => $failureReason,
        ]);

        Log::warning('Face attendance verification failed', [
            'employee_id' => $userId,
            'failure_reason' => $failureReason,
            'is_spoof' => $isSpoof,
            'similarity_score' => $context['similarity_score'] ?? null,
            'distance' => $context['distance'] ?? null,
            'liveness_score' => $context['liveness_score'] ?? null,
            'ip_address' => $ip,
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
    /**
     * @deprecated Per Enhanced Attendance Logic Section 2:
     * "The system must not block any clock action."
     * Working-hours restrictions have been removed. Employees can clock in/out
     * at any time — before shift, after shift, rest day, holiday, OT hours.
     * Method kept as no-op for call-site compatibility.
     */
    private function ensureWithinWorkingHours(User $user, string $type): void
    {
    }

    /**
     * @deprecated Per Enhanced Attendance Logic Section 2:
     * "The system must not block any clock action."
     * Holiday restrictions have been removed. Employees can clock in/out on holidays.
     * Method kept as no-op for call-site compatibility.
     */
    private function ensureNotHolidayForAttendance(): void
    {
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
        // Kiosk-only endpoint: duplicate clock-in → structured response so SmartDTR can offer Attendance Correction filing.
        if ($type === AttendanceLog::TYPE_CLOCK_IN && $user->hasTimedInToday()) {
            $this->recordFailedAttempt($user->id, $request, false, 'already_timed_in');

            return $this->kioskAttendanceCorrectionConflictResponse(
                $user,
                'already_timed_in',
                'You have already timed in today. File an attendance correction if your DTR needs fixing.'
            );
        }
        // Allow one clock-out without a same-day clock-in (missing punch); block repeat orphan outs — aligns with Daily Computation / correction flows.
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            $hasTimedInToday = $user->hasTimedInToday();
            $hasClockedOutToday = $user->hasClockOutToday();
            $allowKioskOrphanOut = ! $hasTimedInToday && ! $hasClockedOutToday;
            if (! $allowKioskOrphanOut) {
                throw ValidationException::withMessages([
                    'type' => [$hasClockedOutToday ? 'You have already clocked out today.' : 'Cannot clock out without clocking in first.'],
                ]);
            }
        }

        $suggestCorrectionAfterClockOut = $type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->hasTimedInToday();

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, [
            'authentication_method' => $qrToken !== '' ? AttendanceLog::AUTH_METHOD_QR : AttendanceLog::AUTH_METHOD_CREDENTIALS,
        ]));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->syncClockOutToFiledOvertime($user, $log);
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
                $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;

                // Derive final status from the day's first clock-in classification,
                // not only from undertime. This prevents "On time" when the day started late.
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

                $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                    $dateKey,
                    $daySchedule,
                    $firstIn?->created_at,
                    $log->created_at,
                    $tzToday,
                    $earlyTimeout
                );
                $payload['attendance']['undertime_minutes'] = $undertimeMinutes > 0 ? $undertimeMinutes : 0;

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

        if ($suggestCorrectionAfterClockOut) {
            $this->appendKioskClockOutWithoutClockInHint($payload, $user);
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
            $this->overtimeService->syncClockOutToFiledOvertime($user, $log);
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
                $this->overtimeService->syncClockOutToFiledOvertime($user, $log);
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
     * - Already timed in = Cannot time in again → "You have already timed in today." (employee app).
     * - SmartDTR kiosk (header X-Kiosk-Attendance): duplicate clock-in returns kiosk_attendance_correction; orphan clock-out allowed once without clock-in.
     * - Completed attendance = Cannot scan again → "Your attendance for today is already completed."
     * - Clock out without clock-in → blocked unless kiosk orphan rule applies (see above).
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
        $isKioskFlow = $this->isKioskAttendanceClient($request);

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
            if ($isKioskFlow) {
                $this->recordFailedAttempt($user->id, $request, false, 'already_timed_in');

                return $this->kioskAttendanceCorrectionConflictResponse(
                    $user,
                    'already_timed_in',
                    'You have already timed in today. File an attendance correction if your DTR needs fixing.'
                );
            }
            throw ValidationException::withMessages([
                'type' => ['You have already timed in today.'],
            ]);
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            $hasTimedInToday = $user->hasTimedInToday();
            $hasClockedOutToday = $user->hasClockOutToday();
            $allowKioskOrphanOut = $isKioskFlow && ! $hasTimedInToday && ! $hasClockedOutToday;
            if (! $allowKioskOrphanOut) {
                throw ValidationException::withMessages([
                    'type' => [$hasClockedOutToday ? 'You have already clocked out today.' : 'Cannot clock out without clocking in first.'],
                ]);
            }
        }

        $suggestCorrectionAfterClockOut = $isKioskFlow && $type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->hasTimedInToday();

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, [
            'authentication_method' => AttendanceLog::AUTH_METHOD_QR,
        ]));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->syncClockOutToFiledOvertime($user, $log);
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
                $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;

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

                $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                    $dateKey,
                    $daySchedule,
                    $firstIn?->created_at,
                    $log->created_at,
                    $tzToday,
                    $earlyTimeout
                );
                $payload['attendance']['undertime_minutes'] = $undertimeMinutes > 0 ? $undertimeMinutes : 0;

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

        if ($suggestCorrectionAfterClockOut) {
            $this->appendKioskClockOutWithoutClockInHint($payload, $user);
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
            'login' => ['nullable', 'string', 'max:255'],
            'liveness_session_id' => ['nullable', 'string', 'max:255'],
            'image_base64' => ['nullable', 'string'],
            'client_capture_started_at_ms' => ['nullable', 'numeric'],
        ]);
        $login = trim((string) ($validated['login'] ?? ''));
        $sessionId = $validated['liveness_session_id'] ?? null;
        $imageBase64 = $validated['image_base64'] ?? null;
        if (! $sessionId && ! $imageBase64) {
            throw ValidationException::withMessages([
                'face' => ['Perform face liveness first, then submit the session.'],
            ]);
        }

        $claimedUser = null;
        if ($login !== '') {
            try {
                $claimedUser = $this->resolveUserFromLoginIdentifier($login);
            } catch (ValidationException $e) {
                $this->recordFailedAttempt(null, $request, false, 'login_not_found');
                throw $e;
            }

            if (! $claimedUser->hasRegisteredFace()) {
                $this->recordFailedAttempt($claimedUser->id, $request, false, 'face_not_registered');

                return response()->json([
                    'message' => 'Face not registered. Please register your face in My QR & Face first.',
                    'errors' => ['face' => ['Face not registered. Please register your face in My QR & Face first.']],
                    'error_code' => 'face_not_registered',
                ], 422);
            }

            if ($claimedUser->needsFaceReregistration()) {
                return response()->json([
                    'message' => 'Your face data needs to be updated. Please re-register your face in My QR & Face.',
                    'errors' => ['face' => ['Your face data needs to be updated. Please re-register your face in My QR & Face.']],
                    'error_code' => 'face_needs_reregistration',
                ], 422);
            }

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
                'message' => 'Face not clear. Please face the camera straight with good lighting and hold still.',
                'errors' => ['face' => ['Face not clear. Please face the camera straight with good lighting and hold still.']],
                'error_code' => 'spoof_detected',
            ], 422);
        }

        // Keep Amplify/Rekognition liveness mandatory and enforce a strict clock-in/out confidence floor.
        $minLiveness = (float) config('attendance.face_clock_min_liveness_score', 0.60);
        $spoofConfidence = isset($result['spoof_confidence']) ? (float) $result['spoof_confidence'] : null;
        if ($spoofConfidence === null || $spoofConfidence < $minLiveness) {
            $this->recordFailedAttempt($claimedUser?->id, $request, true, 'liveness_failed', [
                'liveness_score' => $spoofConfidence,
            ]);

            return response()->json([
                'message' => 'Face not clear. Please face the camera straight with good lighting and hold still.',
                'errors' => ['face' => ['Face not clear. Please face the camera straight with good lighting and hold still.']],
                'error_code' => 'spoof_detected',
            ], 422);
        }

        if (empty($result['descriptor']) || count($result['descriptor']) !== FaceVerificationService::EMBEDDING_DIM) {
            $this->recordFailedAttempt(null, $request, false, 'no_face_detected');

            return response()->json([
                'message' => 'Face not clear. Please face the camera straight with good lighting and hold still.',
                'errors' => ['face' => ['Face not clear. Please face the camera straight with good lighting and hold still.']],
                'error_code' => 'no_face_detected',
            ], 422);
        }

        $matchStarted = microtime(true);
        $similarityScore = null;
        if ($claimedUser) {
            $strictMatch = FaceVerificationService::verifySpecificUserByFaceWithScore($claimedUser, $result['descriptor'], $spoofConfidence);
            $matchMs = round((microtime(true) - $matchStarted) * 1000, 1);
            if (! $strictMatch || ! $strictMatch['passes']) {
                $mismatchMinSimilarity = (float) config('attendance.face_kiosk_account_mismatch_min_similarity', 0.60);
                $identifiedOther = FaceAuthService::identifyUserWithScore($result['descriptor'], kioskMode: true, livenessScore: $spoofConfidence);
                if (
                    $identifiedOther
                    && (int) $identifiedOther['user']->id !== (int) $claimedUser->id
                    && (float) ($identifiedOther['similarity_score'] ?? 0.0) >= $mismatchMinSimilarity
                ) {
                    $otherUser = $identifiedOther['user'];
                    $this->recordFailedAttempt($claimedUser->id, $request, false, 'face_account_mismatch', [
                        'claimed_user_id' => $claimedUser->id,
                        'matched_user_id' => $otherUser->id,
                        'matched_similarity_score' => $identifiedOther['similarity_score'] ?? null,
                        'liveness_score' => $spoofConfidence,
                    ]);
                    Log::warning('Kiosk face attendance rejected: claimed account does not match scanned face', [
                        'claimed_user_id' => $claimedUser->id,
                        'matched_user_id' => $otherUser->id,
                        'matched_similarity_score' => $identifiedOther['similarity_score'] ?? null,
                        'threshold' => $mismatchMinSimilarity,
                    ]);

                    return response()->json([
                        'message' => 'Face and account do not match. Please use the correct account or register your own face.',
                        'errors' => ['face' => ['Face and account do not match.']],
                        'error_code' => 'face_account_mismatch',
                        'fallback' => 'qr',
                        'performance' => [
                            'match_ms' => $matchMs,
                            'claimed_similarity_score' => $strictMatch['similarity_score'] ?? null,
                            'matched_similarity_score' => $identifiedOther['similarity_score'] ?? null,
                        ],
                    ], 422);
                }

                $this->recordFailedAttempt($claimedUser->id, $request, false, 'face_not_recognized', [
                    'similarity_score' => $strictMatch['similarity_score'] ?? null,
                    'distance' => $strictMatch['distance'] ?? null,
                    'liveness_score' => $spoofConfidence,
                ]);
                Log::warning('Identity-bound face attendance rejected', [
                    'employee_id' => $claimedUser->id,
                    'similarity_score' => $strictMatch['similarity_score'] ?? null,
                    'distance' => $strictMatch['distance'] ?? null,
                    'min_similarity_required' => (float) config('attendance.face_identity_min_similarity_score', 0.55),
                    'max_distance_allowed' => (float) config('attendance.face_identity_max_euclidean_distance', 1.0),
                ]);

                return response()->json([
                    'message' => 'Face not recognized. Please try again.',
                    'errors' => ['face' => ['Face not recognized. Please try again.']],
                    'error_code' => 'face_not_recognized',
                    'hint' => 'If this keeps happening, re-register your face in My QR & Face.',
                    'fallback' => 'qr',
                    'performance' => [
                        'match_ms' => $matchMs,
                        'similarity_score' => $strictMatch['similarity_score'] ?? null,
                    ],
                ], 422);
            }
            $similarityScore = $strictMatch['similarity_score'];
            $user = $this->refreshUserForScheduleCheck($claimedUser);
        } else {
            $identified = FaceAuthService::identifyUserWithScore($result['descriptor'], kioskMode: true, livenessScore: $spoofConfidence);
            $matchMs = round((microtime(true) - $matchStarted) * 1000, 1);
            if (! $identified) {
                $this->recordFailedAttempt(null, $request, false, 'face_not_recognized');

                return response()->json([
                    'message' => 'Face not recognized. Please try again.',
                    'errors' => ['face' => ['Face not recognized. Please try again.']],
                    'error_code' => 'face_not_recognized',
                    'performance' => ['match_ms' => $matchMs],
                ], 422);
            }

            $identifiedUser = $identified['user'];
            $strictMinSimilarity = (float) config('attendance.face_identity_min_similarity_score', 0.55);
            if ((float) ($identified['similarity_score'] ?? 0.0) < $strictMinSimilarity) {
                $this->recordFailedAttempt($identifiedUser->id, $request, false, 'face_not_recognized', [
                    'similarity_score' => $identified['similarity_score'] ?? null,
                    'liveness_score' => $spoofConfidence,
                ]);
                Log::warning('Kiosk face attendance rejected: similarity below strict minimum', [
                    'employee_id' => $identifiedUser->id,
                    'similarity_score' => $identified['similarity_score'] ?? null,
                    'min_similarity_required' => $strictMinSimilarity,
                ]);

                return response()->json([
                    'message' => 'Face not recognized. Please try again.',
                    'errors' => ['face' => ['Face not recognized. Please try again.']],
                    'error_code' => 'face_not_recognized',
                    'performance' => ['match_ms' => $matchMs],
                ], 422);
            }
            if (! $identifiedUser->hasRegisteredFace()) {
                $this->recordFailedAttempt($identifiedUser->id, $request, false, 'face_not_registered');

                return response()->json([
                    'message' => 'Face not registered. Please register your face in My QR & Face first.',
                    'errors' => ['face' => ['Face not registered. Please register your face in My QR & Face first.']],
                    'error_code' => 'face_not_registered',
                ], 422);
            }
            if ($identifiedUser->needsFaceReregistration()) {
                return response()->json([
                    'message' => 'Your face data needs to be updated. Please re-register your face in My QR & Face.',
                    'errors' => ['face' => ['Your face data needs to be updated. Please re-register your face in My QR & Face.']],
                    'error_code' => 'face_needs_reregistration',
                ], 422);
            }
            $similarityScore = $identified['similarity_score'];
            $user = $this->refreshUserForScheduleCheck($identifiedUser);
        }
        $type = $validated['type'];
        $faceContext = [
            'similarity_score' => $similarityScore,
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

            return $this->kioskAttendanceCorrectionConflictResponse(
                $user,
                'already_timed_in',
                'You have already timed in today. File an attendance correction if your DTR needs fixing.'
            );
        }
        if ($type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->canClockOutToday()) {
            $hasTimedInToday = $user->hasTimedInToday();
            $hasClockedOutToday = $user->hasClockOutToday();
            $allowKioskOrphanOut = ! $hasTimedInToday && ! $hasClockedOutToday;
            if (! $allowKioskOrphanOut) {
                $this->recordFailedAttempt($user->id, $request, false, 'cannot_clock_out');
                throw ValidationException::withMessages([
                    'type' => [$hasClockedOutToday ? 'You have already clocked out today.' : 'Cannot clock out without clocking in first.'],
                ]);
            }
        }

        $suggestCorrectionAfterClockOut = $type === AttendanceLog::TYPE_CLOCK_OUT && ! $user->hasTimedInToday();

        $log = AttendanceLog::create($this->attendanceLogData($request, $user, $type, $faceContext));

        if ($type === AttendanceLog::TYPE_CLOCK_OUT) {
            $this->overtimeService->syncClockOutToFiledOvertime($user, $log);
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
                $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;

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

                $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                    $dateKey,
                    $daySchedule,
                    $firstIn?->created_at,
                    $log->created_at,
                    $tzToday,
                    $earlyTimeout
                );
                $payload['attendance']['undertime_minutes'] = $undertimeMinutes > 0 ? $undertimeMinutes : 0;

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

        if ($suggestCorrectionAfterClockOut) {
            $this->appendKioskClockOutWithoutClockInHint($payload, $user);
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
            'similarity_score' => $similarityScore,
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
     * Cheap fingerprint so cached `/attendance/summary` payloads invalidate when punches, corrections,
     * overlapping leaves, overtime rows, or the employee profile (schedule) change.
     */
    private function employeeAttendanceSummaryResponseCacheRevision(User $user, Carbon $from, Carbon $to): string
    {
        $fromUtc = $from->copy()->timezone('UTC');
        $toUtc = $to->copy()->timezone('UTC');
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $parts = [
            AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereBetween('verified_at', [$fromUtc, $toUtc])
                ->max('updated_at'),
            AttendanceCorrection::query()
                ->where('user_id', $user->id)
                ->whereBetween('date', [$fromStr, $toStr])
                ->max('updated_at'),
            LeaveRequest::query()
                ->where('user_id', $user->id)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->where('start_date', '<=', $toStr)
                ->where('end_date', '>=', $fromStr)
                ->max('updated_at'),
            Overtime::query()
                ->where('user_id', $user->id)
                ->whereBetween('date', [$fromStr, $toStr])
                ->max('updated_at'),
            User::query()->whereKey($user->id)->value('updated_at'),
        ];

        return substr(md5(implode('|', array_map(fn ($v) => (string) ($v ?? ''), $parts))), 0, 24);
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
        $startedAt = microtime(true);
        $user = $request->user();

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::EMPLOYEE_SUMMARY_MAX_PER_PAGE],
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

        $perPageInput = isset($validated['per_page']) ? (int) $validated['per_page'] : null;
        $pageInput = max(1, (int) ($validated['page'] ?? 1));
        $ppCacheKey = ($perPageInput === null || $perPageInput <= 0)
            ? 'all'
            : (string) min(self::EMPLOYEE_SUMMARY_MAX_PER_PAGE, max(1, $perPageInput));

        $revision = $this->employeeAttendanceSummaryResponseCacheRevision($user, $from, $to);
        $responseCacheKey = sprintf(
            'employee_attendance_summary:v3:%d:%s:%s:%s:%s:%d',
            $user->id,
            $from->toDateString(),
            $to->toDateString(),
            $revision,
            $ppCacheKey,
            $pageInput,
        );

        if (($cachedPayload = Cache::get($responseCacheKey)) && is_array($cachedPayload)) {
            $cachedPayload['summary']['today']['ot_detection'] = $this->otDetectionService->detectForToday($user, $attendanceTz);
            $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
            if (! isset($cachedPayload['meta']) || ! is_array($cachedPayload['meta'])) {
                $cachedPayload['meta'] = [];
            }
            if (! isset($cachedPayload['meta']['performance']) || ! is_array($cachedPayload['meta']['performance'])) {
                $cachedPayload['meta']['performance'] = [];
            }
            $cachedPayload['meta']['performance']['cache_hit'] = true;
            $cachedPayload['meta']['performance']['total_ms'] = $totalMs;
            Log::info('Employee attendance summary cache hit', [
                'user_id' => (int) $user->id,
                'cache_key_suffix' => $revision.'|'.$ppCacheKey.'|'.$pageInput,
                'total_ms' => $totalMs,
            ]);

            return response()->json($cachedPayload);
        }

        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);

        // Preload logs and corrections for range.
        // IMPORTANT: Attendance views are based on the punch timestamp (`verified_at`), not row insertion time (`created_at`).
        $bulkFetchStart = microtime(true);
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
                'rejected_at',
                'rejected_by',
                'rejection_note',
                'approval_stage',
                'first_approver_id',
                'second_approver_id',
                'first_approved_at',
                'second_approved_at',
                'approved_by',
                'approved_at',
                'filed_by',
                'issue_kind',
                'is_incomplete_record',
                'attendance_logs_synced_at',
                'attendance_logs_synced_by',
                'updated_at',
            ])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($c) => $c->date->toDateString());

        // Preload approved leaves for range to mark days as "Filed Leave"
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
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get();

        $this->leaveCreditService->ensureAnnualRechargeForUser($user);

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
            ->select([
                'id',
                'user_id',
                'date',
                'computed_hours',
                'expected_end_time',
                'status',
                'ph_ot_rule',
            ])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Overtime $o) => $o->date->toDateString());

        $bulkFetchMs = (int) round((microtime(true) - $bulkFetchStart) * 1000);

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
                        $status = 'incomplete';
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
                        $inCarbon = $effectiveTimeIn instanceof Carbon
                            ? $effectiveTimeIn
                            : ($effectiveTimeIn ? Carbon::parse($effectiveTimeIn) : null);
                        $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                        $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                            $dateKey,
                            $daySchedule,
                            $inCarbon,
                            $outCarbon,
                            $attendanceTz,
                            $earlyTimeout
                        );
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

            // Raw-log possible OT for dashboard/unfiled OT:
            // mirror Admin Reports by counting pre-shift minutes before scheduled start
            // plus post-shift minutes after scheduled end + OT buffer, even if no OT request exists yet.
            // Expose explicit time ranges so the employee dashboard can show "06:00 - 08:00 (2h)" style hints.
            $rawPreOtSegment = null;
            $rawPostOtSegment = null;
            $rawOtMinutes = 0;
            if (
                is_array($daySchedule)
                && ! empty($daySchedule['in'])
                && ! empty($daySchedule['out'])
                && $effectiveTimeIn
                && $effectiveTimeOut
                && $effectiveWorkedMinutes !== null
                && $status !== 'halfday'
            ) {
                $inCarbonForOt = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                $outCarbonForOt = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                $scheduledStartForOt = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $attendanceTz);
                $scheduledEndForOt = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $attendanceTz);

                if ($scheduledStartForOt && $inCarbonForOt->lessThan($scheduledStartForOt)) {
                    $preM = (int) $inCarbonForOt->diffInMinutes($scheduledStartForOt);
                    if ($preM > 0) {
                        $rawOtMinutes += $preM;
                        $rawPreOtSegment = [
                            'kind' => 'pre_shift',
                            'start' => $this->formatTimeInAttendanceTz($inCarbonForOt),
                            'end' => $this->formatTimeInAttendanceTz($scheduledStartForOt),
                            'minutes' => $preM,
                            'hours' => round($preM / 60, 2),
                        ];
                    }
                }

                if ($scheduledEndForOt) {
                    $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
                        ? (int) $daySchedule['overtime_buffer_minutes']
                        : (int) config('attendance.overtime_buffer_minutes', 15);
                    $postShiftOtStart = $scheduledEndForOt->copy()->addMinutes($overtimeBuffer);
                    if ($outCarbonForOt->greaterThan($postShiftOtStart)) {
                        $postM = (int) $postShiftOtStart->diffInMinutes($outCarbonForOt);
                        if ($postM > 0) {
                            $rawOtMinutes += $postM;
                            $rawPostOtSegment = [
                                'kind' => 'post_shift',
                                'start' => $this->formatTimeInAttendanceTz($postShiftOtStart),
                                'end' => $this->formatTimeInAttendanceTz($outCarbonForOt),
                                'minutes' => $postM,
                                'hours' => round($postM / 60, 2),
                            ];
                        }
                    }
                }
            }

            $rawOtHours = $rawOtMinutes > 0 ? round($rawOtMinutes / 60, 2) : null;
            // Reports-parity OT source: use schedule-vs-clock rendered OT only.
            // Do not fall back to AttendanceLog::overtime_hours snapshots for core OT/payroll columns.
            $otMinutesForRow = $hasEffectiveTimeOut ? $rawOtMinutes : null;
            $renderedOtHours = $otMinutesForRow !== null ? round($otMinutesForRow / 60, 2) : null;
            // Parity with Admin → Reports detailed: clock OT vs approved filing.
            $clockOtHours = $otMinutesForRow !== null
                ? round($otMinutesForRow / 60, 2)
                : null;
            $clockVal = $clockOtHours ?? 0.0;
            $approvedFromFiling = round((float) $approvedOtHours, 2);
            $approvedOtAppliedHours = $clockVal > 0.0001
                ? min($approvedFromFiling, $clockVal)
                : 0.0;
            $unapprovedOtHours = max(0.0, round($clockVal - min($approvedFromFiling, $clockVal), 2));
            if ($clockVal <= 0.0001 && $approvedFromFiling > 0) {
                $unapprovedOtHours = 0.0;
            }
            // Payable OT in attendance views = approved OT that was actually rendered by the logs.
            // The raw request remains available as overtime_hours_requested.
            $displayOvertimeHours = $approvedOtAppliedHours > 0.0001 ? round($approvedOtAppliedHours, 2) : null;
            if ($approvedOtAppliedHours > 0.0001) {
                $metrics['approved_overtime_minutes'] += (int) round($approvedOtAppliedHours * 60);
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

            $showOtPremiumFields = $hasEffectiveTimeOut || $approvedOtAppliedHours > 0.0001;
            $employeeStatusLabel = ($approvedOtAppliedHours > 0.0001 && in_array($status, ['present', 'late'], true))
                ? ($status === 'late' ? 'Late + OT' : 'Present + OT')
                : null;

            $scheduledRegularMinutes = null;
            if (is_array($daySchedule) && ! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
                $scheduledRegularMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $attendanceTz);
            }
            // Payroll impact is payslip-parity via computeDayPayroll — deferred to pagination hydrate only.
            $payrollImpactMinutes = 0;
            $payrollImpactHours = 0.0;

            $scheduleInDay = is_array($daySchedule) && ! empty($daySchedule['in'])
                ? (string) $daySchedule['in']
                : null;
            $scheduleOutDay = is_array($daySchedule) && ! empty($daySchedule['out'])
                ? (string) $daySchedule['out']
                : null;

            $effectiveTimeOutDateForPayroll = $effectiveTimeOut
                ? ($effectiveTimeOut instanceof Carbon
                    ? $effectiveTimeOut->copy()->timezone($attendanceTz)->toDateString()
                    : Carbon::parse($effectiveTimeOut)->timezone($attendanceTz)->toDateString())
                : null;
            $timeOutNextDay = $effectiveTimeOutDateForPayroll !== null && $effectiveTimeOutDateForPayroll !== $dateKey;

            $scheduleAssignedForRow = is_array($effectiveSchedule) && $effectiveSchedule !== [];
            $isRestDayRow = false;
            if (! $isOnLeave && $scheduleAssignedForRow) {
                $isRestDayRow = ! (is_array($daySchedule) && ! empty(trim((string) ($daySchedule['in'] ?? ''))));
            }

            $isIncomplete = $status === 'incomplete'
                || ($effectiveTimeIn && ! $effectiveTimeOut && ! $isFuture && $status !== 'clocked_in')
                || (! $effectiveTimeIn && $effectiveTimeOut);

            $days[] = [
                'date' => $dateKey,
                'status' => $status,
                'is_rest_day' => $isRestDayRow,
                'is_incomplete' => $isIncomplete,
                'employee_status_label' => $employeeStatusLabel,
                'schedule_in' => $scheduleInDay,
                'schedule_out' => $scheduleOutDay,
                'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                'virtual_time_out_from_ot' => $virtualTimeOutFromOt,
                'time_out_next_day' => $timeOutNextDay,
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
                // Reports parity: OT minutes only when we have an actual/virtual time-out anchor.
                'overtime_minutes' => $otMinutesForRow,
                'rendered_overtime_hours' => $showOtPremiumFields && $renderedOtHours !== null ? $renderedOtHours : null,
                'raw_overtime_minutes' => $rawOtMinutes > 0 ? $rawOtMinutes : null,
                'raw_overtime_hours' => $rawOtHours,
                'raw_pre_ot' => $rawPreOtSegment,
                'raw_post_ot' => $rawPostOtSegment,
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
                'overtime_status' => $this->normalizeOvertimeModuleStatusForDisplay($otRow),
                'approved_overtime_hours' => $approvedOtAppliedHours > 0 ? round($approvedOtAppliedHours, 2) : null,
                'overtime_hours_requested' => $otRow !== null ? round((float) ($otRow->computed_hours ?? 0), 2) : null,
                /** Same derivation as {@see ReportsController::detailed()} `approved_overtime_hours` / `unapproved_overtime_hours`. */
                'unapproved_overtime_hours' => $unapprovedOtHours > 0.0001 ? round($unapprovedOtHours, 2) : null,
                'payroll_impact_minutes' => $payrollImpactMinutes,
                'payroll_impact_hours' => $payrollImpactHours,
                'has_approved_overtime' => $approvedOtAppliedHours > 0.0001,
                'presence_label' => $presenceLabel,
                'presence_issue' => $presenceIssue,
                'presence_filing' => null,
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

        $transformStart = microtime(true);
        $this->attachPresenceFilingsToEmployeeSummaryDays($corrections, $days, $attendanceTz);

        usort($days, fn (array $a, array $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        $todayPresenceFilingPayload = null;
        foreach ($days as $d) {
            if (($d['date'] ?? '') === $todayDate) {
                $todayPresenceFilingPayload = $d['presence_filing'] ?? null;
                break;
            }
        }

        $totalDaysInRange = count($days);

        $hydratePayrollStart = microtime(true);
        if ($perPageInput === null || $perPageInput <= 0) {
            $responseDays = $days;
            $this->hydrateEmployeeSummaryPayrollImpact($user, $responseDays, $attendanceTz);
            $daysMeta = [
                'paginated' => false,
                'total' => $totalDaysInRange,
            ];
        } else {
            $perPage = min(self::EMPLOYEE_SUMMARY_MAX_PER_PAGE, max(1, $perPageInput));
            $lastPage = max(1, (int) ceil($totalDaysInRange / $perPage));
            $page = min($pageInput, $lastPage);
            $offset = ($page - 1) * $perPage;
            $responseDays = array_slice($days, $offset, $perPage);
            $this->hydrateEmployeeSummaryPayrollImpact($user, $responseDays, $attendanceTz);
            $daysMeta = [
                'paginated' => true,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalDaysInRange,
            ];
        }
        $hydratePayrollMs = (int) round((microtime(true) - $hydratePayrollStart) * 1000);
        $transformMs = (int) round((microtime(true) - $transformStart) * 1000);

        $scheduleAssigned = is_array($effectiveSchedule) && $effectiveSchedule !== [];

        $otDetection = $this->otDetectionService->detectForToday($user, $attendanceTz);

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
                'presence_filing' => $todayPresenceFilingPayload,
                'leave_pay_status' => $todayLeavePayStatus,
                'ot_detection' => $otDetection,
            ],
        ];

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        $performancePayload = [
            'user_id' => (int) $user->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'bulk_fetch_ms' => $bulkFetchMs,
            'transform_slice_ms' => $transformMs,
            'hydrate_payroll_ms' => $hydratePayrollMs,
            'total_response_ms' => $totalMs,
            'days_meta' => $daysMeta,
            'cache_hit' => false,
        ];
        Log::info('Employee attendance summary prepared', $performancePayload);
        if ($totalMs >= self::EMPLOYEE_SUMMARY_SLOW_WARN_MS) {
            Log::warning('Slow employee attendance summary', $performancePayload);
        }

        $payload = [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'summary' => $summary,
            'days' => $responseDays,
            'meta' => [
                'days' => $daysMeta,
                'performance' => [
                    'bulk_fetch_ms' => $bulkFetchMs,
                    'transform_slice_ms' => $transformMs,
                    'hydrate_payroll_ms' => $hydratePayrollMs,
                    'total_ms' => $totalMs,
                    'cache_hit' => false,
                ],
            ],
        ];

        $cachePayload = $payload;
        $cachePayload['summary']['today']['ot_detection'] = null;
        $cachePayload['meta']['performance']['total_ms'] = null;
        Cache::put($responseCacheKey, $cachePayload, self::EMPLOYEE_SUMMARY_RESPONSE_CACHE_SECONDS);

        return response()->json($payload);
    }

    private function correctionNeedsPresenceFilingPayload(?AttendanceCorrection $correction): bool
    {
        if ($correction === null) {
            return false;
        }

        return ! ($correction->reason_code === null && ! $correction->pending_approval && $correction->rejected_at === null);
    }

    /**
     * Avoid eager-loading audits / approvers for every correction in-range; hydrate only calendar rows that need a filing card.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, AttendanceCorrection>>  $correctionsByDate
     * @param  list<array<string, mixed>>  $days
     */
    private function attachPresenceFilingsToEmployeeSummaryDays($correctionsByDate, array &$days, string $tz): void
    {
        $ids = [];
        foreach ($days as $day) {
            $dateKey = (string) ($day['date'] ?? '');
            $correction = $correctionsByDate->get($dateKey)?->first();
            if (! $this->correctionNeedsPresenceFilingPayload($correction)) {
                continue;
            }
            $ids[(int) $correction->id] = true;
        }
        if ($ids === []) {
            return;
        }

        $loaded = AttendanceCorrection::query()
            ->whereIn('id', array_keys($ids))
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'rejectedBy',
                'attendanceLogsSyncedBy',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                'audits' => fn ($r) => $r->orderBy('created_at')->with('admin'),
            ])
            ->get()
            ->keyBy('id');

        foreach ($days as $i => $day) {
            $dateKey = (string) ($day['date'] ?? '');
            $correction = $correctionsByDate->get($dateKey)?->first();
            if (! $this->correctionNeedsPresenceFilingPayload($correction)) {
                continue;
            }
            $full = $loaded->get((int) $correction->id);
            if ($full instanceof AttendanceCorrection) {
                $days[$i]['presence_filing'] = $this->presenceFilingFormatter->format(
                    $full,
                    $tz,
                    includeEmployee: true,
                    actor: null,
                    includeDisplayFields: true
                );
            }
        }
    }

    /**
     * Payslip-parity payroll impact only for rows returned to the client (paginated slice when requested).
     *
     * @param  list<array<string, mixed>>  $daysSlice
     */
    private function hydrateEmployeeSummaryPayrollImpact(User $user, array &$daysSlice, string $tz): void
    {
        foreach ($daysSlice as &$day) {
            $scheduleIn = $day['schedule_in'] ?? null;
            $scheduleOut = $day['schedule_out'] ?? null;
            if (! $scheduleIn || ! $scheduleOut) {
                continue;
            }

            $dateKey = (string) ($day['date'] ?? '');
            if ($dateKey === '') {
                continue;
            }

            $tInPayroll = null;
            if (! empty($day['time_in'])) {
                $tInPayroll = Carbon::parse($dateKey.' '.$day['time_in'], $tz);
            }

            $tOutPayroll = null;
            if (! empty($day['time_out'])) {
                $outDay = $dateKey;
                if (! empty($day['time_out_next_day'])) {
                    $outDay = Carbon::parse($dateKey.' 00:00:00', $tz)->addDay()->toDateString();
                }
                $tOutPayroll = Carbon::parse($outDay.' '.$day['time_out'], $tz);
            }

            $minutes = $this->payrollComputation->payrollImpactMinutesForAttendanceDisplay(
                $user,
                $dateKey,
                $tInPayroll,
                $tOutPayroll,
                $tz
            );
            $day['payroll_impact_minutes'] = $minutes;
            $day['payroll_impact_hours'] = round($minutes / 60, 2);
        }
        unset($day);
    }

    /**
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
        if (! $this->correctionNeedsPresenceFilingPayload($correction)) {
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
     * Overtime module status for attendance rows: never show approved when payable hours are zero.
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
     * Public, no auth. Returns real kiosk/employee punch activity only.
     *
     * Approved attendance corrections still update DTR/payroll, but they are intentionally
     * hidden from the login Recent Activity feed so old approved filings do not look like
     * fresh kiosk scans.
     */
    public function recentKiosk(Request $request): JsonResponse
    {
        $tz = $this->attendanceTimezone();

        // Regular attendance logs only. Exclude synthetic HR-approved correction punches from the kiosk login feed.
        $logEntries = AttendanceLog::query()
            ->where(function ($q) {
                $q->whereNull('authentication_method')
                    ->orWhere('authentication_method', '!=', AttendanceLog::AUTH_METHOD_HR_APPROVED_CORRECTION);
            })
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
            ->map(function (AttendanceLog $log) use ($tz) {
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
                    '_dedup_key' => ((int)($user?->id ?? 0)).'|'.$log->created_at->copy()->timezone($tz)->toDateString().'|'.$log->type,
                ];
            });

        // Keep the login Recent Activity feed limited to actual kiosk/employee punches.
        // Approved correction requests are intentionally hidden here.
        $merged = $logEntries
            ->sortByDesc('_ts')
            ->unique('_dedup_key')
            ->take(25)
            ->map(function (array $e) {
                unset($e['_ts'], $e['_dedup_key']);
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
