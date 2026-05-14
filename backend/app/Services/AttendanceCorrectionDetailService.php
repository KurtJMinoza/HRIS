<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceCorrectionDetailService
{
    public function __construct(
        private readonly HolidayService $holidayService,
    ) {}

    public function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $employee, string $dateKey, string $issueType, bool $adminContext = false): array
    {
        $tz = $this->attendanceTimezone();
        $date = Carbon::parse($dateKey, $tz)->startOfDay();
        $dateKey = $date->toDateString();
        $dayStartUtc = $date->copy()->startOfDay()->setTimezone('UTC');
        $dayEndUtc = $date->copy()->endOfDay()->setTimezone('UTC');

        $employee->loadMissing('workingSchedule');
        $schedule = EmployeeScheduleResolver::resolve($employee);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($date);
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;
        $hasSchedule = is_array($daySchedule) && ! empty($daySchedule['in']);
        $isRestDay = is_array($schedule) && ! $hasSchedule;
        $holiday = $this->holidayService->getEffectiveHolidayForEmployee($employee, $dateKey);

        if (! Schema::hasTable('attendance_logs')) {
            return $this->emptyDetail($employee, $dateKey, $issueType, $adminContext, $daySchedule, $hasSchedule, $isRestDay, $holiday);
        }

        $clockIn = AttendanceLog::query()
            ->where('user_id', $employee->id)
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('verified_at')
            ->first();

        $clockInAt = $clockIn?->verified_at?->copy()->timezone($tz);
        $clockOutAt = null;

        if ($clockInAt) {
            $clockOutSearchEnd = $this->clockOutSearchEndUtc($daySchedule, $dateKey, $tz, $date->copy()->endOfDay());
            $clockOut = AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                ->where('verified_at', '>=', $clockInAt->copy()->setTimezone('UTC'))
                ->where('verified_at', '<=', $clockOutSearchEnd)
                ->orderBy('verified_at')
                ->first();
            $clockOutAt = $clockOut?->verified_at?->copy()->timezone($tz);
        } else {
            $clockOut = AttendanceLog::query()
                ->where('user_id', $employee->id)
                ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
                ->orderBy('verified_at')
                ->first();
            $clockOutAt = $clockOut?->verified_at?->copy()->timezone($tz);
        }

        $missingClockIn = $clockInAt === null;
        $missingClockOut = $clockOutAt === null;
        $isAbsent = $missingClockIn && $missingClockOut;
        $recommendedIssueType = $this->recommendedIssueType($missingClockIn, $missingClockOut);
        $attendanceStatus = $this->attendanceStatus($daySchedule, $dateKey, $clockInAt, $clockOutAt, $isAbsent);
        $message = $this->message($issueType, $missingClockIn, $missingClockOut, $adminContext);
        $tone = $this->tone($issueType, $missingClockIn, $missingClockOut, $hasSchedule, $isRestDay, $holiday !== null);

        return [
            'date' => $dateKey,
            'employee_id' => (int) $employee->id,
            'schedule_start' => $hasSchedule ? $this->normalizeScheduleTime($daySchedule['in'] ?? null) : null,
            'schedule_end' => $hasSchedule ? $this->normalizeScheduleTime($daySchedule['out'] ?? null) : null,
            'clock_in' => $clockInAt?->toIso8601String(),
            'clock_out' => $clockOutAt?->toIso8601String(),
            'attendance_status' => $attendanceStatus,
            'missing_clock_in' => $missingClockIn,
            'missing_clock_out' => $missingClockOut,
            'is_absent' => $isAbsent,
            'is_rest_day' => $isRestDay,
            'is_holiday' => $holiday !== null,
            'holiday_name' => $holiday['name'] ?? null,
            'message' => $message,
            'recommended_issue_type' => $recommendedIssueType,
            'detail_tone' => $tone,
            'notes' => $this->notes($hasSchedule, $isRestDay, $holiday),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $daySchedule
     * @param  array<string, mixed>|null  $holiday
     * @return array<string, mixed>
     */
    private function emptyDetail(
        User $employee,
        string $dateKey,
        string $issueType,
        bool $adminContext,
        ?array $daySchedule,
        bool $hasSchedule,
        bool $isRestDay,
        ?array $holiday
    ): array {
        return [
            'date' => $dateKey,
            'employee_id' => (int) $employee->id,
            'schedule_start' => $hasSchedule ? $this->normalizeScheduleTime($daySchedule['in'] ?? null) : null,
            'schedule_end' => $hasSchedule ? $this->normalizeScheduleTime($daySchedule['out'] ?? null) : null,
            'clock_in' => null,
            'clock_out' => null,
            'attendance_status' => 'no logs',
            'missing_clock_in' => true,
            'missing_clock_out' => true,
            'is_absent' => true,
            'is_rest_day' => $isRestDay,
            'is_holiday' => $holiday !== null,
            'holiday_name' => $holiday['name'] ?? null,
            'message' => $this->message($issueType, true, true, $adminContext),
            'recommended_issue_type' => 'both',
            'detail_tone' => 'neutral-warning',
            'notes' => array_merge(
                ['Attendance logs table is missing. Run database migrations to restore attendance log lookups.'],
                $this->notes($hasSchedule, $isRestDay, $holiday)
            ),
        ];
    }

    private function clockOutSearchEndUtc(?array $daySchedule, string $dateKey, string $tz, Carbon $defaultEnd): Carbon
    {
        $end = $defaultEnd->copy();
        if (is_array($daySchedule)) {
            $in = trim((string) ($daySchedule['in'] ?? ''));
            $out = trim((string) ($daySchedule['out'] ?? ''));
            if ($in !== '' && $out !== '') {
                $scheduledIn = Carbon::parse($dateKey.' '.substr($in, 0, 5), $tz);
                $scheduledOut = Carbon::parse($dateKey.' '.substr($out, 0, 5), $tz);
                if ($scheduledOut->lessThanOrEqualTo($scheduledIn)) {
                    $scheduledOut->addDay();
                    $end = $end->max($scheduledOut->copy()->addHours(4));
                }
            }
        }

        return $end->setTimezone('UTC');
    }

    private function recommendedIssueType(bool $missingClockIn, bool $missingClockOut): ?string
    {
        if ($missingClockIn && $missingClockOut) {
            return 'both';
        }
        if ($missingClockIn) {
            return 'missing_in';
        }
        if ($missingClockOut) {
            return 'missing_out';
        }

        return null;
    }

    private function attendanceStatus(?array $daySchedule, string $dateKey, ?Carbon $clockIn, ?Carbon $clockOut, bool $isAbsent): string
    {
        if ($isAbsent) {
            return 'absent';
        }
        if (! $clockIn) {
            return 'Missing Clock In';
        }
        if (! $clockOut) {
            return 'Missing Clock Out';
        }
        if (! is_array($daySchedule) || empty($daySchedule['in'])) {
            return 'present';
        }

        $clockInStatus = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $clockIn);

        return match ($clockInStatus['status'] ?? null) {
            'late' => $clockInStatus['late_label'] ?? 'Late',
            'half_day' => 'Half Day',
            default => 'present',
        };
    }

    private function message(string $issueType, bool $missingClockIn, bool $missingClockOut, bool $adminContext): string
    {
        $subject = $adminContext ? 'This employee' : 'You';
        $verb = $adminContext ? 'did not' : 'did not';

        if ($issueType === 'missing_in') {
            if ($missingClockIn) {
                return "{$subject} {$verb} clock in on this date.";
            }

            return 'Clock in already exists for this date. Please verify if correction is still needed.';
        }

        if ($issueType === 'missing_out') {
            if ($missingClockOut) {
                return "{$subject} {$verb} clock out on this date.";
            }

            return 'Clock out already exists for this date. Please verify if correction is still needed.';
        }

        if ($missingClockIn && $missingClockOut) {
            return "{$subject} {$verb} clock in and clock out on this date.";
        }

        if (! $missingClockIn && ! $missingClockOut) {
            return 'Clock in and clock out already exist for this date. Please verify if correction is still needed.';
        }

        return $missingClockIn
            ? "{$subject} {$verb} clock in on this date."
            : "{$subject} {$verb} clock out on this date.";
    }

    private function tone(string $issueType, bool $missingClockIn, bool $missingClockOut, bool $hasSchedule, bool $isRestDay, bool $isHoliday): string
    {
        if (! $hasSchedule || $isRestDay || $isHoliday) {
            return 'neutral-warning';
        }
        if (
            ($issueType === 'missing_in' && $missingClockIn)
            || ($issueType === 'missing_out' && $missingClockOut)
            || ($issueType === 'both' && ($missingClockIn || $missingClockOut))
        ) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * @param  array<string, mixed>|null  $holiday
     * @return list<string>
     */
    private function notes(bool $hasSchedule, bool $isRestDay, ?array $holiday): array
    {
        $notes = [];
        if (! $hasSchedule) {
            $notes[] = $isRestDay ? 'This date is a scheduled rest day.' : 'No schedule was found for this date.';
        }
        if ($holiday !== null) {
            $name = trim((string) ($holiday['name'] ?? 'Holiday'));
            $notes[] = "This date is marked as a holiday: {$name}.";
        }

        return $notes;
    }

    private function normalizeScheduleTime(mixed $value): ?string
    {
        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }
}
