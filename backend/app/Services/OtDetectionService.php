<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

/**
 * Passive OT / ND / Premium detection — computes potential extra hours from
 * clock logs vs schedule without creating any database records.
 *
 * Used exclusively for Employee Dashboard notices. Unfiled detections never
 * appear in Admin panels or payroll.
 *
 * Detection threshold (Section 7 of Enhanced Attendance Logic):
 * - Pre-shift: clock-in more than 1 hour before scheduled start (e.g. before 7:00 AM for 8:00 AM shift).
 * - Post-shift: clock-out more than 1 hour after scheduled end (e.g. after 6:00 PM for 5:00 PM shift).
 */
class OtDetectionService
{
    /**
     * Threshold (minutes) before scheduled start for pre-shift OT notice.
     * Default 60 minutes (1 hour).
     */
    private function preShiftThresholdMinutes(): int
    {
        return (int) config('attendance.ot_detection_pre_shift_threshold_minutes', 60);
    }

    /**
     * Grace period (minutes) after scheduled end before flagging post-shift OT.
     * Default 60 minutes (1 hour) per spec Section 7.
     */
    private function postShiftGraceMinutes(): int
    {
        return (int) config('attendance.ot_detection_grace_minutes', 60);
    }

    /**
     * Detect potential unfiled overtime for a user on a given date.
     *
     * Checks both pre-shift (clock-in more than 1 hour before schedule start)
     * and post-shift (clock-out more than 1 hour after schedule end) conditions.
     *
     * @return array{
     *   date: string,
     *   schedule_start: string,
     *   schedule_end: string,
     *   pre_shift: array{clock_in: string, threshold_at: string, minutes: int, label: string}|null,
     *   post_shift: array{work_end: string, minutes: int, label: string}|null,
     *   total_extra_minutes: int,
     *   total_extra_label: string,
     *   has_filed_ot: bool,
     *   filed_ot_status: string|null,
     *   can_file: bool
     * }|null
     */
    public function detectForDate(User $user, string $dateKey, ?string $tz = null): ?array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        $hasApprovedLeave = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $dateKey)
            ->where('end_date', '>=', $dateKey)
            ->exists();

        if ($hasApprovedLeave) {
            return null;
        }

        $user->loadMissing('workingSchedule');
        $schedule = EmployeeScheduleResolver::resolve($user);
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }

        $carbonDate = Carbon::parse($dateKey, $tz);
        $dayKey = EmployeeScheduleResolver::dayKeyForDate($carbonDate);
        $daySchedule = $schedule[$dayKey] ?? null;

        if (! is_array($daySchedule) || empty($daySchedule['out']) || empty($daySchedule['in'])) {
            return null;
        }

        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $logs = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('verified_at', [$dayStartUtc, $dayEndUtc])
            ->orderBy('verified_at')
            ->get();

        if ($logs->isEmpty()) {
            return null;
        }

        $firstClockIn = null;
        $lastClockOut = null;

        foreach ($logs as $log) {
            $stamp = $log->verified_at ?? $log->created_at;
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN && $firstClockIn === null) {
                $firstClockIn = $stamp;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $lastClockOut = $stamp;
            }
        }

        if ($firstClockIn === null) {
            return null;
        }

        $scheduleStart = Carbon::parse($dateKey.' '.trim((string) $daySchedule['in']), $tz);
        $scheduleEnd = Carbon::parse($dateKey.' '.trim((string) $daySchedule['out']), $tz);
        if ($scheduleEnd->lessThanOrEqualTo($scheduleStart)) {
            $scheduleEnd->addDay();
        }

        $firstClockInLocal = $firstClockIn->copy()->timezone($tz);
        $preShiftThresholdAt = $scheduleStart->copy()->subMinutes($this->preShiftThresholdMinutes());
        $preShift = null;
        if ($firstClockInLocal->lessThan($preShiftThresholdAt)) {
            $preMinutes = (int) $firstClockInLocal->diffInMinutes($scheduleStart);
            if ($preMinutes > 0) {
                $preShift = [
                    'clock_in' => $firstClockInLocal->format('H:i'),
                    'threshold_at' => $preShiftThresholdAt->format('H:i'),
                    'minutes' => $preMinutes,
                    'label' => $this->formatDurationLabel($preMinutes),
                ];
            }
        }

        $workEnd = $lastClockOut
            ? $lastClockOut->copy()->timezone($tz)
            : now($tz);

        $postShift = null;
        if ($workEnd->greaterThan($scheduleEnd)) {
            $postMinutes = (int) $scheduleEnd->diffInMinutes($workEnd);
            $grace = $this->postShiftGraceMinutes();
            if ($postMinutes > $grace) {
                $postShift = [
                    'work_end' => $workEnd->format('H:i'),
                    'minutes' => $postMinutes,
                    'label' => $this->formatDurationLabel($postMinutes),
                ];
            }
        }

        if ($preShift === null && $postShift === null) {
            return null;
        }

        $totalExtra = ($preShift['minutes'] ?? 0) + ($postShift['minutes'] ?? 0);

        $existingOt = Overtime::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->first();

        $hasFiledOt = $existingOt !== null;
        $filedOtStatus = $existingOt?->status;
        $canFile = ! $hasFiledOt;

        return [
            'date' => $dateKey,
            'schedule_start' => $scheduleStart->format('H:i'),
            'schedule_end' => $scheduleEnd->format('H:i'),
            'pre_shift' => $preShift,
            'post_shift' => $postShift,
            'total_extra_minutes' => $totalExtra,
            'total_extra_label' => $this->formatDurationLabel($totalExtra),
            'has_filed_ot' => $hasFiledOt,
            'filed_ot_status' => $filedOtStatus,
            'can_file' => $canFile,
        ];
    }

    /**
     * Detect potential OT for today (convenience wrapper for dashboard).
     */
    public function detectForToday(User $user, ?string $tz = null): ?array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $todayKey = Carbon::now($tz)->toDateString();

        return $this->detectForDate($user, $todayKey, $tz);
    }

    private function formatDurationLabel(int $minutes): string
    {
        $hrs = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $parts = [];
        if ($hrs > 0) {
            $parts[] = $hrs.' hr'.($hrs === 1 ? '' : 's');
        }
        if ($mins > 0) {
            $parts[] = $mins.' min'.($mins === 1 ? '' : 's');
        }

        return implode(' ', $parts) ?: '0 mins';
    }
}
