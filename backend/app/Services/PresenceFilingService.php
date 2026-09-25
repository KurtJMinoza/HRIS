<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/**
 * Employee presence filing (no punch) → pending correction → approval.
 * Approved corrections feed {@see AttendanceSessionService} like other manual attendance.
 */
class PresenceFilingService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const REASON_FORGOT_PUNCH = 'forgot_punch';

    public const REASON_SYSTEM_ISSUE = 'system_issue';

    public const REASON_FIELD_WORK = 'field_work';

    public const REASON_MANUAL_OVERRIDE = 'manual_override';

    public const REASON_OTHERS = 'others';

    /** @return array<string, string> */
    public static function reasonLabels(): array
    {
        return [
            self::REASON_FORGOT_PUNCH => 'Forgot to clock in/out',
            self::REASON_SYSTEM_ISSUE => 'System issue',
            self::REASON_FIELD_WORK => 'Field work',
            self::REASON_MANUAL_OVERRIDE => 'Manual override',
            self::REASON_OTHERS => 'Others',
        ];
    }

    /** Reasons that require at least one supporting document on file. */
    public static function reasonCodesRequiringDocuments(): array
    {
        return [
            self::REASON_SYSTEM_ISSUE,
            self::REASON_FIELD_WORK,
            self::REASON_MANUAL_OVERRIDE,
            self::REASON_OTHERS,
        ];
    }

    public static function requiresSupportingDocument(?string $reasonCode): bool
    {
        $code = is_string($reasonCode) ? trim($reasonCode) : '';

        return $code !== '' && in_array($code, self::reasonCodesRequiringDocuments(), true);
    }

    /**
     * @return array<int, array{value: string, label: string, requires_document: bool}>
     */
    public static function reasonOptionsForApi(): array
    {
        $labels = self::reasonLabels();
        $requires = array_flip(self::reasonCodesRequiringDocuments());
        $out = [];
        foreach ($labels as $value => $label) {
            $out[] = [
                'value' => $value,
                'label' => $label,
                'requires_document' => isset($requires[$value]),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public function storeUploadedSupportingDocuments(Request $request, string $inputKey = 'attachments'): array
    {
        $files = $this->collectUploadedFiles($request, $inputKey);
        $stored = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $stored[] = $file->store('attendance-correction-documents', 'public');
            }
        }

        return $stored;
    }

    /**
     * @return array<int, UploadedFile>
     */
    private function collectUploadedFiles(Request $request, string $inputKey): array
    {
        $raw = $request->file($inputKey);
        if ($raw instanceof UploadedFile) {
            return [$raw];
        }
        if (is_array($raw)) {
            return array_values(array_filter($raw, static fn ($f) => $f instanceof UploadedFile));
        }

        $out = [];
        foreach ($request->allFiles() as $key => $value) {
            if ($key !== $inputKey && ! str_starts_with((string) $key, $inputKey.'.')) {
                continue;
            }
            if ($value instanceof UploadedFile) {
                $out[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $file) {
                    if ($file instanceof UploadedFile) {
                        $out[] = $file;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{url: string, filename: string}>
     */
    public function serializeSupportingDocuments(AttendanceCorrection $correction): array
    {
        $out = [];
        foreach ($correction->resolveDocumentPaths() as $path) {
            $url = $this->publicMediaUrl($path);
            if ($url === null) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'filename' => basename($path),
            ];
        }

        return $out;
    }

    /**
     * @return array{documents: array<int, array{url: string, filename: string}>, documents_count: int, attachment_count: int}
     */
    public function documentsListFields(AttendanceCorrection $correction): array
    {
        $documents = $this->serializeSupportingDocuments($correction);
        $count = count($documents);

        return [
            'documents' => $documents,
            'documents_count' => $count,
            'attachment_count' => $count,
        ];
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        $segments = explode('/', $normalized);
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    public function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * @return array<string, mixed>|null day schedule array or null if rest / no schedule
     */
    public function dayScheduleForUser(User $employee, string $dateKey): ?array
    {
        $tz = $this->attendanceTimezone();
        $dateCarbon = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayKey = self::DAY_KEYS[(int) $dateCarbon->format('w')];

        $schedule = $employee->schedule;
        if ((! is_array($schedule) || $schedule === []) && $employee->working_schedule_id !== null) {
            $employee->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($employee->workingSchedule);
            if ($derived !== null) {
                $schedule = $derived;
            }
        }
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }
        $day = $schedule[$dayKey] ?? null;

        return is_array($day) && ! empty($day['in']) ? $day : null;
    }

    /**
     * Schedule-aligned in/out Carbons for a regular workday (no OT padding).
     *
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}|null
     */
    public function resolveScheduleRegularPunches(User $employee, string $dateKey): ?array
    {
        $daySchedule = $this->dayScheduleForUser($employee, $dateKey);
        if ($daySchedule === null) {
            return null;
        }
        $tz = $this->attendanceTimezone();
        $timeInStr = trim((string) ($daySchedule['in'] ?? ''));
        $timeOutStr = trim((string) ($daySchedule['out'] ?? ''));
        if ($timeInStr === '' || $timeOutStr === '') {
            return null;
        }
        $timeIn = Carbon::parse($dateKey.' '.substr($timeInStr, 0, 5), $tz);
        $timeOut = Carbon::parse($dateKey.' '.substr($timeOutStr, 0, 5), $tz);
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = $timeOut->copy()->addDay();
        }

        return [$timeIn, $timeOut];
    }

    /**
     * Whether the employee may file a presence request for this calendar date (today only for self-service).
     *
     * @return array{ok: bool, message?: string}
     */
    public function employeeCanFile(User $employee, string $dateKey): array
    {
        $tz = $this->attendanceTimezone();
        $today = Carbon::now($tz)->toDateString();
        if ($dateKey !== $today) {
            return ['ok' => false, 'message' => 'Presence filing is only available for today.'];
        }

        if ($this->dayScheduleForUser($employee, $dateKey) === null) {
            return ['ok' => false, 'message' => 'You are not scheduled to work on this date.'];
        }

        $blockingLeave = LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->whereNotIn('type', ['half_day', 'undertime'])
            ->exists();
        if ($blockingLeave) {
            return ['ok' => false, 'message' => 'You have approved leave covering this date.'];
        }

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $hasIn = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = Schema::hasTable('attendance_logs') && AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        $correction = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->first();

        if ($correction && $correction->approved) {
            return ['ok' => false, 'message' => 'Attendance for this date is already finalized.'];
        }

        if ($hasIn && $hasOut) {
            return ['ok' => false, 'message' => 'Clock-in and clock-out are already recorded for today.'];
        }

        return ['ok' => true];
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $restDays = is_array($schedule->rest_days) ? $schedule->rest_days : [];

        $breaks = [];
        foreach ($schedule->getAllBreaks() as $b) {
            $breaks[] = [
                'start' => $b['start'],
                'end' => $b['end'],
                'is_paid' => $b['is_paid'] ?? false,
            ];
        }

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
                'breaks' => $breaks,
                'work_blocks' => $schedule->getWorkBlocks(),
                'shift_type' => $schedule->shift_type ?? 'fixed',
                'crosses_midnight' => (bool) ($schedule->crosses_midnight ?? false),
                'expected_paid_minutes' => $schedule->expected_paid_minutes,
                'half_day_threshold_minutes' => $schedule->effective_half_day_threshold,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
                'rest_days' => $restDays,
                'flexible_required_minutes' => $schedule->flexible_required_minutes,
            ];
        }

        return $baseDayConfig;
    }
}
