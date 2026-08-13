<?php

namespace App\Services;

use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Single shared schedule computation engine.
 *
 * Used by: Attendance, Employee Dashboard Calendar, Payroll, Overtime,
 * Attendance Correction, Reports. No duplicate status logic anywhere.
 *
 * Supports: fixed, flexible, split, overnight, rotating, compressed shifts;
 * multiple breaks (paid/unpaid); dynamic half-day thresholds; rest day awareness.
 */
class ScheduleComputationService
{
    /**
     * Full attendance computation for a given day.
     *
     * @param  string  $dateKey  Y-m-d date string
     * @param  array  $daySchedule  Per-day config (in, out, breaks, shift_type, etc.)
     * @param  Carbon|null  $actualTimeIn  Actual clock-in time
     * @param  Carbon|null  $actualTimeOut  Actual clock-out time
     * @return array Full computation result
     */
    public function compute(
        string $dateKey,
        array $daySchedule,
        ?Carbon $actualTimeIn = null,
        ?Carbon $actualTimeOut = null,
        ?string $tz = null,
    ): array {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $matchMetadata = null;
        if (($daySchedule['shift_type'] ?? 'fixed') === 'flexible'
            && ! empty($daySchedule['flexible_shift_options'])
            && is_array($daySchedule['flexible_shift_options'])) {
            $matched = $this->resolveFlexibleShiftForAttendance($dateKey, $daySchedule, $actualTimeIn, $actualTimeOut, $tz);
            $daySchedule = $matched['schedule'];
            $matchMetadata = $matched['metadata'];
        }
        $shiftType = $daySchedule['shift_type'] ?? 'fixed';

        $summary = $this->summarize($dateKey, $daySchedule, $tz);
        $scheduledStart = $summary['start'];
        $scheduledEnd = $summary['end'];
        $scheduledMinutes = $summary['span_minutes'];
        $scheduledBreakMinutes = $summary['break_minutes'];
        $scheduledPaidMinutes = $summary['required_minutes'];
        $halfDayThresholdMinutes = $this->halfDayThreshold($daySchedule, $scheduledPaidMinutes);

        $result = [
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'scheduled_minutes' => $scheduledMinutes,
            'scheduled_break_minutes' => $scheduledBreakMinutes,
            'scheduled_paid_minutes' => $scheduledPaidMinutes,
            'actual_worked_minutes' => 0,
            'break_deducted_minutes' => 0,
            'payable_minutes' => 0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'overtime_minutes' => 0,
            'half_day_threshold_minutes' => $halfDayThresholdMinutes,
            'status' => 'absent',
            'shift_type' => $shiftType,
            'is_rest_day' => false,
        ];
        if ($matchMetadata !== null) {
            $result = array_merge($result, $matchMetadata);
        }

        $dayName = strtolower(Carbon::parse($dateKey)->format('D'));
        $restDays = $daySchedule['rest_days'] ?? [];
        if (is_array($restDays) && in_array($dayName, $restDays, true)) {
            $result['status'] = 'rest_day';
            $result['is_rest_day'] = true;

            return $result;
        }

        if (! $actualTimeIn) {
            return $result;
        }

        $timeIn = $actualTimeIn->copy()->timezone($tz);
        $timeOut = $actualTimeOut ? $actualTimeOut->copy()->timezone($tz) : null;

        if ($shiftType === 'flexible') {
            // Per-weekday flexible schedules reuse fixed-style times after resolution.
            $fixedStyleFlexible = ! empty($daySchedule['matched_schedule_option_id'])
                || ! empty($daySchedule['flexible_shift_options'])
                || empty($daySchedule['flexible_required_minutes']);
            if (! empty($daySchedule['in']) && ! empty($daySchedule['out']) && $fixedStyleFlexible) {
                return $this->computeFixed($result, $daySchedule, $dateKey, $timeIn, $timeOut, $tz);
            }

            return $this->computeFlexible($result, $daySchedule, $dateKey, $timeIn, $timeOut, $tz);
        }

        if ($shiftType === 'split') {
            return $this->computeSplit($result, $daySchedule, $dateKey, $timeIn, $timeOut, $tz);
        }

        return $this->computeFixed($result, $daySchedule, $dateKey, $timeIn, $timeOut, $tz);
    }

    /**
     * @return array{schedule: array<string, mixed>, metadata: array<string, mixed>}
     */
    public function resolveFlexibleShiftForAttendance(
        string $dateKey,
        array $daySchedule,
        ?Carbon $actualTimeIn,
        ?Carbon $actualTimeOut,
        string $tz
    ): array {
        if (($daySchedule['shift_type'] ?? 'fixed') !== 'flexible'
            || empty($daySchedule['flexible_shift_options'])
            || ! is_array($daySchedule['flexible_shift_options'])) {
            return [
                'schedule' => $daySchedule,
                'metadata' => [
                    'matched_schedule_option_id' => $daySchedule['matched_schedule_option_id'] ?? null,
                    'matched_schedule_option_name' => $daySchedule['matched_schedule_option_name'] ?? null,
                    'match_source' => $daySchedule['match_source'] ?? null,
                    'match_score' => $daySchedule['match_score'] ?? null,
                    'resolved_schedule_snapshot' => $this->snapshotSchedule($daySchedule),
                ],
            ];
        }

        $segments = [[
            'time_in' => $actualTimeIn,
            'time_out' => $actualTimeOut,
        ]];
        $match = app(FlexibleShiftMatcher::class)->matchForAttendance(
            isset($daySchedule['employee_id']) ? (int) $daySchedule['employee_id'] : null,
            $dateKey,
            $segments,
            $daySchedule['flexible_shift_options'],
            $tz,
            isset($daySchedule['explicit_schedule_option_id']) ? (int) $daySchedule['explicit_schedule_option_id'] : null,
        );

        $option = $match['option'] ?? null;
        if (! is_array($option)) {
            return [
                'schedule' => $daySchedule,
                'metadata' => [
                    'matched_schedule_option_id' => null,
                    'matched_schedule_option_name' => null,
                    'match_source' => 'none',
                    'match_score' => null,
                    'resolved_schedule_snapshot' => $daySchedule,
                ],
            ];
        }

        $resolved = array_merge($daySchedule, $option, [
            'shift_type' => 'flexible',
            'schedule_type' => 'flexible',
            'flexible_shift_options' => $daySchedule['flexible_shift_options'],
            'match_source' => $match['source'],
            'match_score' => $match['score'],
        ]);

        return [
            'schedule' => $resolved,
            'metadata' => [
                'matched_schedule_option_id' => $option['matched_schedule_option_id'] ?? $option['id'] ?? null,
                'matched_schedule_option_name' => $option['matched_schedule_option_name'] ?? $option['option_name'] ?? null,
                'match_source' => $match['source'],
                'match_score' => $match['score'],
                'resolved_schedule_snapshot' => $this->snapshotSchedule($resolved),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSchedule(array $schedule): array
    {
        return collect($schedule)
            ->except(['flexible_shift_options'])
            ->all();
    }

    /**
     * Schedule summary: start, end, span, break, required (paid) minutes.
     *
     * @return array{start: ?Carbon, end: ?Carbon, span_minutes: int, break_minutes: int, required_minutes: int}
     */
    public function summarize(string $dateKey, array $daySchedule, ?string $tz = null): array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $shiftType = $daySchedule['shift_type'] ?? 'fixed';

        if ($shiftType === 'split') {
            return $this->summarizeSplit($dateKey, $daySchedule, $tz);
        }

        $start = $this->scheduledStart($dateKey, $daySchedule, $tz);
        $end = $this->scheduledEnd($dateKey, $daySchedule, $tz);

        if (! $start || ! $end || ! $end->greaterThan($start)) {
            return [
                'start' => $start,
                'end' => $end,
                'span_minutes' => 0,
                'break_minutes' => 0,
                'required_minutes' => 0,
            ];
        }

        $spanMinutes = (int) $start->diffInMinutes($end);

        $explicitPaid = $daySchedule['expected_paid_minutes'] ?? null;
        if ($explicitPaid !== null && (int) $explicitPaid > 0) {
            $breakMinutes = max(0, $spanMinutes - (int) $explicitPaid);

            return [
                'start' => $start,
                'end' => $end,
                'span_minutes' => $spanMinutes,
                'break_minutes' => $breakMinutes,
                'required_minutes' => (int) $explicitPaid,
            ];
        }

        $breakMinutes = $this->totalUnpaidBreakMinutes($dateKey, $daySchedule, $start, $end, $tz);

        return [
            'start' => $start,
            'end' => $end,
            'span_minutes' => $spanMinutes,
            'break_minutes' => $breakMinutes,
            'required_minutes' => max(0, $spanMinutes - $breakMinutes),
        ];
    }

    public function scheduledStart(string $dateKey, array $daySchedule, ?string $tz = null): ?Carbon
    {
        $in = $this->normalizeTime($daySchedule['in'] ?? null);
        if ($in === null) {
            return null;
        }

        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));

        return Carbon::parse($dateKey.' '.$in, $tz);
    }

    public function scheduledEnd(string $dateKey, array $daySchedule, ?string $tz = null): ?Carbon
    {
        $out = $this->normalizeTime($daySchedule['out'] ?? null);
        if ($out === null) {
            return null;
        }

        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $in = $this->normalizeTime($daySchedule['in'] ?? null);
        $end = Carbon::parse($dateKey.' '.$out, $tz);

        $crossesMidnight = (bool) ($daySchedule['crosses_midnight'] ?? false);
        if ($crossesMidnight || ($in !== null && $out <= $in)) {
            $end->addDay();
        }

        return $end;
    }

    public function requiredWorkingMinutes(string $dateKey, array $daySchedule, ?string $tz = null): int
    {
        return (int) $this->summarize($dateKey, $daySchedule, $tz)['required_minutes'];
    }

    public function netWorkedMinutes(
        Carbon $timeIn,
        Carbon $timeOut,
        array $daySchedule,
        string $dateKey,
        ?string $tz = null,
        bool $clipToScheduleStart = false
    ): int {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $in = $timeIn->copy()->timezone($tz);
        $out = $timeOut->copy()->timezone($tz);

        if ($out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        $effectiveIn = $in;
        $scheduledStart = $this->scheduledStart($dateKey, $daySchedule, $tz);
        if ($clipToScheduleStart && $scheduledStart && $effectiveIn->lessThan($scheduledStart)) {
            $effectiveIn = $scheduledStart->copy();
        }

        if (! $out->greaterThan($effectiveIn)) {
            return 0;
        }

        $rawMinutes = (int) $effectiveIn->diffInMinutes($out);
        $breakMinutes = $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $effectiveIn, $out, $tz);

        return max(0, $rawMinutes - $breakMinutes);
    }

    /**
     * Break windows resolved to Carbon instances, filtering only unpaid breaks by default.
     *
     * @return list<array{start: Carbon, end: Carbon, is_paid: bool}>
     */
    public function breakWindows(string $dateKey, array $daySchedule, ?string $tz = null): array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $scheduledStart = $this->scheduledStart($dateKey, $daySchedule, $tz);
        $scheduledEnd = $this->scheduledEnd($dateKey, $daySchedule, $tz);

        $rawBreaks = [];

        if (is_array($daySchedule['breaks'] ?? null)) {
            foreach ($daySchedule['breaks'] as $break) {
                if (! is_array($break)) {
                    continue;
                }
                $rawBreaks[] = [
                    'start' => $break['start'] ?? $break['break_start'] ?? null,
                    'end' => $break['end'] ?? $break['break_end'] ?? null,
                    'is_paid' => (bool) ($break['is_paid'] ?? false),
                ];
            }
        }

        if (($daySchedule['break_start'] ?? null) || ($daySchedule['break_end'] ?? null)) {
            $legacyStart = $daySchedule['break_start'] ?? null;
            $legacyEnd = $daySchedule['break_end'] ?? null;
            $alreadyIncluded = false;
            foreach ($rawBreaks as $rb) {
                if ($rb['start'] === $legacyStart && $rb['end'] === $legacyEnd) {
                    $alreadyIncluded = true;
                    break;
                }
            }
            if (! $alreadyIncluded) {
                $rawBreaks[] = [
                    'start' => $legacyStart,
                    'end' => $legacyEnd,
                    'is_paid' => false,
                ];
            }
        }

        $windows = [];
        foreach ($rawBreaks as $break) {
            $startText = $this->normalizeTime($break['start'] ?? null);
            $endText = $this->normalizeTime($break['end'] ?? null);
            if ($startText === null || $endText === null) {
                continue;
            }

            $start = Carbon::parse($dateKey.' '.$startText, $tz);
            $end = Carbon::parse($dateKey.' '.$endText, $tz);

            if ($scheduledStart && $scheduledEnd && $scheduledEnd->toDateString() !== $scheduledStart->toDateString()) {
                if ($start->lessThan($scheduledStart)) {
                    $start->addDay();
                }
                if ($end->lessThanOrEqualTo($scheduledStart)) {
                    $end->addDay();
                }
            }

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            if ($end->greaterThan($start)) {
                $windows[] = [
                    'start' => $start,
                    'end' => $end,
                    'is_paid' => (bool) ($break['is_paid'] ?? false),
                ];
            }
        }

        return $windows;
    }

    /**
     * Total unpaid break minutes within a given window.
     */
    public function totalUnpaidBreakOverlapMinutes(string $dateKey, array $daySchedule, Carbon $windowStart, Carbon $windowEnd, ?string $tz = null): int
    {
        $minutes = 0;
        foreach ($this->breakWindows($dateKey, $daySchedule, $tz) as $break) {
            if ($break['is_paid']) {
                continue;
            }
            $overlapStart = $windowStart->greaterThan($break['start']) ? $windowStart->copy() : $break['start']->copy();
            $overlapEnd = $windowEnd->lessThan($break['end']) ? $windowEnd->copy() : $break['end']->copy();
            if ($overlapEnd->greaterThan($overlapStart)) {
                $minutes += (int) $overlapStart->diffInMinutes($overlapEnd);
            }
        }

        return $minutes;
    }

    /**
     * Total unpaid break minutes for the full scheduled shift window.
     */
    public function totalUnpaidBreakMinutes(string $dateKey, array $daySchedule, Carbon $shiftStart, Carbon $shiftEnd, ?string $tz = null): int
    {
        return $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $shiftStart, $shiftEnd, $tz);
    }

    /**
     * Backward-compatible alias used by older callers.
     */
    public function totalBreakOverlapMinutes(string $dateKey, array $daySchedule, Carbon $windowStart, Carbon $windowEnd, ?string $tz = null): int
    {
        return $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $windowStart, $windowEnd, $tz);
    }

    /**
     * Half-day threshold in minutes: explicit from schedule, or scheduled_paid_minutes / 2.
     */
    public function halfDayThreshold(array $daySchedule, ?int $scheduledPaidMinutes = null): int
    {
        if (! empty($daySchedule['half_day_threshold_minutes']) && (int) $daySchedule['half_day_threshold_minutes'] > 0) {
            return (int) $daySchedule['half_day_threshold_minutes'];
        }

        $paid = $scheduledPaidMinutes ?? ($daySchedule['expected_paid_minutes'] ?? null);
        if ($paid === null || $paid <= 0) {
            $paid = $this->summarize(
                now()->toDateString(),
                $daySchedule,
            )['required_minutes'];
        }

        return (int) floor($paid / 2);
    }

    /**
     * Wall-clock instant after N paid work minutes from shift start (skips unpaid breaks).
     */
    public function wallClockAfterPaidMinutes(string $dateKey, array $daySchedule, int $targetPaidMinutes, ?string $tz = null): ?Carbon
    {
        if ($targetPaidMinutes <= 0) {
            return $this->scheduledStart($dateKey, $daySchedule, $tz);
        }

        if (($daySchedule['shift_type'] ?? 'fixed') === 'split') {
            return $this->wallClockAfterPaidMinutesSplit($dateKey, $daySchedule, $targetPaidMinutes, $tz);
        }

        $start = $this->scheduledStart($dateKey, $daySchedule, $tz);
        $end = $this->scheduledEnd($dateKey, $daySchedule, $tz);
        if (! $start || ! $end) {
            return null;
        }

        $breaks = $this->breakWindows($dateKey, $daySchedule, $tz);
        usort($breaks, fn (array $a, array $b): int => $a['start']->timestamp <=> $b['start']->timestamp);

        $cursor = $start->copy();
        $remaining = $targetPaidMinutes;

        while ($remaining > 0 && $cursor->lessThan($end)) {
            $nextBreak = null;
            foreach ($breaks as $break) {
                if ($break['start']->greaterThan($cursor)) {
                    if ($nextBreak === null || $break['start']->lessThan($nextBreak['start'])) {
                        $nextBreak = $break;
                    }
                }
            }

            $segmentEnd = $nextBreak ? $nextBreak['start']->copy() : $end->copy();
            if ($segmentEnd->greaterThan($end)) {
                $segmentEnd = $end->copy();
            }

            $available = (int) $cursor->diffInMinutes($segmentEnd);
            if ($available <= 0) {
                if ($nextBreak && ! ($nextBreak['is_paid'] ?? false)) {
                    $cursor = $nextBreak['end']->copy();
                } else {
                    break;
                }

                continue;
            }

            if ($remaining <= $available) {
                return $cursor->copy()->addMinutes($remaining);
            }

            $remaining -= $available;
            $cursor = $segmentEnd->copy();

            if ($nextBreak && ! ($nextBreak['is_paid'] ?? false)) {
                $cursor = $nextBreak['end']->copy();
            }
        }

        return $cursor->lessThanOrEqualTo($end) ? $cursor : $end->copy();
    }

    private function wallClockAfterPaidMinutesSplit(string $dateKey, array $daySchedule, int $targetPaidMinutes, ?string $tz = null): ?Carbon
    {
        $blocks = $daySchedule['work_blocks'] ?? [];
        if (! is_array($blocks) || $blocks === []) {
            return $this->wallClockAfterPaidMinutes(
                $dateKey,
                array_merge($daySchedule, ['shift_type' => 'fixed']),
                $targetPaidMinutes,
                $tz
            );
        }

        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        usort($blocks, fn (array $a, array $b): int => strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? '')));

        $remaining = $targetPaidMinutes;
        foreach ($blocks as $block) {
            $startText = $this->normalizeTime($block['start'] ?? null);
            $endText = $this->normalizeTime($block['end'] ?? null);
            if ($startText === null || $endText === null) {
                continue;
            }

            $blockStart = Carbon::parse($dateKey.' '.$startText, $tz);
            $blockEnd = Carbon::parse($dateKey.' '.$endText, $tz);
            if ($blockEnd->lessThanOrEqualTo($blockStart)) {
                $blockEnd->addDay();
            }

            $available = (int) $blockStart->diffInMinutes($blockEnd);
            if ($available <= 0) {
                continue;
            }

            if ($remaining <= $available) {
                return $blockStart->copy()->addMinutes($remaining);
            }

            $remaining -= $available;
        }

        $last = end($blocks);

        return $last
            ? Carbon::parse($dateKey.' '.substr((string) ($last['end'] ?? '00:00'), 0, 5), $tz)
            : null;
    }

    /**
     * Half-day leave windows derived from the employee's assigned schedule for a date.
     *
     * @return array<string, mixed>
     */
    public function halfDayLeaveWindows(string $dateKey, array $daySchedule, ?string $tz = null): array
    {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $summary = $this->summarize($dateKey, $daySchedule, $tz);
        $required = (int) ($summary['required_minutes'] ?? 0);
        $halfPaid = $this->halfDayThreshold($daySchedule, $required);
        $start = $summary['start'];
        $end = $summary['end'];
        $shiftType = $daySchedule['shift_type'] ?? 'fixed';
        $hasFixedTimes = ! empty($daySchedule['in']) && ! empty($daySchedule['out']);
        $isFlexible = $shiftType === 'flexible' && ! $hasFixedTimes;

        $splitAt = ($start && $required > 0 && ! $isFlexible)
            ? $this->wallClockAfterPaidMinutes($dateKey, $daySchedule, $halfPaid, $tz)
            : null;
        $amWorkStart = $splitAt?->copy();
        if ($amWorkStart && $start && $end) {
            foreach ($this->breakWindows($dateKey, $daySchedule, $tz) as $break) {
                if (($break['is_paid'] ?? false)) {
                    continue;
                }
                if ($amWorkStart->greaterThanOrEqualTo($break['start']) && $amWorkStart->lessThan($break['end'])) {
                    $amWorkStart = $break['end']->copy();
                    break;
                }
            }
        }

        $formatTime = static fn (?Carbon $at): ?string => $at?->copy()->timezone($tz)->format('H:i');

        return [
            'date' => $dateKey,
            'shift_type' => $shiftType,
            'required_paid_minutes' => $required,
            'half_paid_minutes' => $halfPaid,
            'scheduled_start' => $formatTime($start),
            'scheduled_end' => $formatTime($end),
            'split_at' => $formatTime($splitAt),
            'split_at_iso' => $splitAt?->copy()->timezone($tz)->toIso8601String(),
            'is_flexible' => $isFlexible,
            'am' => [
                'label' => 'Leave first half, work second half',
                'work_start' => $formatTime($amWorkStart),
                'work_end' => $formatTime($end),
                'earliest_clock_in' => $formatTime($amWorkStart),
                'latest_clock_out' => $formatTime($end),
                'suggested_half_day_time' => $formatTime($amWorkStart),
                'leave_paid_minutes' => $halfPaid,
                'work_paid_minutes' => max(0, $required - $halfPaid),
            ],
            'pm' => [
                'label' => 'Work first half, leave second half',
                'work_start' => $formatTime($start),
                'work_end' => $formatTime($splitAt),
                'earliest_clock_in' => $formatTime($start),
                'latest_clock_out' => $formatTime($splitAt),
                'suggested_half_day_time' => $formatTime($splitAt),
                'leave_paid_minutes' => $halfPaid,
                'work_paid_minutes' => max(0, $required - $halfPaid),
            ],
        ];
    }

    /**
     * Build per-day schedule array from a WorkingSchedule model.
     * Used by controllers that need to pass a day config to compute().
     */
    public function buildDayScheduleFromModel(WorkingSchedule $model, ?string $dateKey = null): array
    {
        if (Schema::hasTable('working_schedule_days')) {
            $model->loadMissing('days.options');
        }

        if ($model->isFlexiblePerDay()) {
            $dayKey = $dateKey
                ? EmployeeScheduleResolver::dayKeyForDate(Carbon::parse($dateKey))
                : $this->firstFlexibleWorkingDayKey($model);

            if ($dayKey !== null) {
                $dayRow = $model->days->firstWhere('day_of_week', $dayKey);
                if ($dayRow instanceof WorkingScheduleDay && $dayRow->is_working_day) {
                    $restDays = $model->days
                        ->filter(fn (WorkingScheduleDay $d) => ! $d->is_working_day)
                        ->pluck('day_of_week')
                        ->all();

                    return $dayRow->toDayConfig($model, $restDays);
                }
            }
        }

        $breaks = [];
        foreach ($model->getAllBreaks() as $b) {
            $breaks[] = [
                'start' => $b['start'],
                'end' => $b['end'],
                'is_paid' => $b['is_paid'] ?? false,
            ];
        }

        return [
            'in' => $model->time_in,
            'out' => $model->time_out,
            'break_start' => $model->break_start,
            'break_end' => $model->break_end,
            'breaks' => $breaks,
            'work_blocks' => $model->getWorkBlocks(),
            'shift_type' => $model->shift_type ?? 'fixed',
            'crosses_midnight' => (bool) ($model->crosses_midnight ?? false),
            'expected_paid_minutes' => $model->expected_paid_minutes,
            'half_day_threshold_minutes' => $model->half_day_threshold_minutes,
            'grace_period_minutes' => $model->grace_period_minutes,
            'early_timein_minutes' => $model->early_timein_minutes ?? 60,
            'late_allowance_minutes' => $model->late_allowance_minutes,
            'early_timeout_minutes' => $model->early_timeout_minutes,
            'overtime_buffer_minutes' => $model->overtime_buffer_minutes ?? 15,
            'rest_days' => $model->rest_days ?? [],
            'flexible_required_minutes' => $model->flexible_required_minutes,
            'flexible_earliest_in' => $model->flexible_earliest_in,
            'flexible_latest_out' => $model->flexible_latest_out,
            'core_hours_start' => $model->core_hours_start,
            'core_hours_end' => $model->core_hours_end,
        ];
    }

    private function firstFlexibleWorkingDayKey(WorkingSchedule $model): ?string
    {
        foreach (WorkingScheduleDay::DAY_KEYS as $key) {
            $dayRow = $model->days->firstWhere('day_of_week', $key);
            if ($dayRow instanceof WorkingScheduleDay && $dayRow->is_working_day) {
                return $key;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────
    // Fixed / Overnight shift computation
    // ─────────────────────────────────────────

    private function computeFixed(array $result, array $daySchedule, string $dateKey, Carbon $timeIn, ?Carbon $timeOut, string $tz): array
    {
        $scheduledStart = $result['scheduled_start'];
        $scheduledEnd = $result['scheduled_end'];
        $scheduledPaidMinutes = $result['scheduled_paid_minutes'];
        $halfDayThreshold = $result['half_day_threshold_minutes'];
        $graceMinutes = (int) ($daySchedule['grace_period_minutes'] ?? $daySchedule['grace_minutes'] ?? $daySchedule['grace'] ?? 5);
        $earlyTimeoutMinutes = $daySchedule['early_timeout_minutes'] ?? null;
        $overtimeBuffer = (int) ($daySchedule['overtime_buffer_minutes'] ?? 15);

        $lateMinutes = 0;
        if ($scheduledStart) {
            $diffSeconds = $timeIn->diffInSeconds($scheduledStart, false);
            $lateRaw = (int) floor(-$diffSeconds / 60);
            if ($lateRaw > $graceMinutes) {
                $lateMinutes = $lateRaw;
            }
        }

        if (! $timeOut) {
            $result['late_minutes'] = $lateMinutes;
            $result['status'] = $lateMinutes > 0 ? 'incomplete_late' : 'incomplete';

            return $result;
        }

        $rawWorked = (int) $timeIn->diffInMinutes($timeOut);
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $rawWorked = (int) $timeIn->diffInMinutes($timeOut->copy()->addDay());
        }

        $breakDeducted = $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);
        $actualWorkedMinutes = max(0, $rawWorked - $breakDeducted);

        $clippedIn = $timeIn->copy();
        if ($scheduledStart && $clippedIn->lessThan($scheduledStart)) {
            $clippedIn = $scheduledStart->copy();
        }
        $clippedWorked = max(0, (int) $clippedIn->diffInMinutes($timeOut) - $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $clippedIn, $timeOut, $tz));

        $undertimeMinutes = 0;
        if ($scheduledEnd && $timeOut->lessThan($scheduledEnd)) {
            if ($earlyTimeoutMinutes !== null && $earlyTimeoutMinutes > 0) {
                $effectiveEnd = $scheduledEnd->copy()->subMinutes((int) $earlyTimeoutMinutes);
                if ($timeOut->lessThan($effectiveEnd)) {
                    $undertimeMinutes = max(0, $scheduledPaidMinutes - $clippedWorked);
                }
            } else {
                $undertimeMinutes = max(0, $scheduledPaidMinutes - $clippedWorked);
            }
        }

        $overtimeMinutes = 0;
        if ($scheduledEnd && $timeOut->greaterThan($scheduledEnd->copy()->addMinutes($overtimeBuffer))) {
            $otStart = $scheduledEnd->copy()->addMinutes($overtimeBuffer);
            $overtimeMinutes = (int) $otStart->diffInMinutes($timeOut);
        }

        $payableMinutes = min($actualWorkedMinutes, $scheduledPaidMinutes);

        $status = 'present';
        if ($actualWorkedMinutes <= 0) {
            $status = 'absent';
        } elseif ($payableMinutes < $halfDayThreshold) {
            $status = 'half_day';
        } elseif ($lateMinutes > 0 && $undertimeMinutes > 0) {
            $status = 'late_undertime';
        } elseif ($lateMinutes > 0) {
            $status = 'late';
        } elseif ($undertimeMinutes > 0) {
            $status = 'undertime';
        }

        $result['actual_worked_minutes'] = $actualWorkedMinutes;
        $result['break_deducted_minutes'] = $breakDeducted;
        $result['payable_minutes'] = $payableMinutes;
        $result['late_minutes'] = $lateMinutes;
        $result['undertime_minutes'] = $undertimeMinutes;
        $result['overtime_minutes'] = $overtimeMinutes;
        $result['status'] = $status;

        return $result;
    }

    // ─────────────────────────────────────────
    // Flexible shift computation
    // ─────────────────────────────────────────

    private function computeFlexible(array $result, array $daySchedule, string $dateKey, Carbon $timeIn, ?Carbon $timeOut, string $tz): array
    {
        $requiredMinutes = (int) ($daySchedule['flexible_required_minutes'] ?? $daySchedule['expected_paid_minutes'] ?? $result['scheduled_paid_minutes']);
        $halfDayThreshold = $result['half_day_threshold_minutes'];

        if (! $timeOut) {
            $result['status'] = 'incomplete';

            return $result;
        }

        $rawWorked = (int) $timeIn->diffInMinutes($timeOut);
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $rawWorked = (int) $timeIn->diffInMinutes($timeOut->copy()->addDay());
        }

        $breakDeducted = $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);
        $actualWorkedMinutes = max(0, $rawWorked - $breakDeducted);

        $deficit = max(0, $requiredMinutes - $actualWorkedMinutes);
        $surplus = max(0, $actualWorkedMinutes - $requiredMinutes);

        $payableMinutes = min($actualWorkedMinutes, $requiredMinutes);

        $status = 'present';
        if ($actualWorkedMinutes <= 0) {
            $status = 'absent';
        } elseif ($payableMinutes < $halfDayThreshold) {
            $status = 'half_day';
        } elseif ($deficit > 0) {
            $status = 'undertime';
        }

        $result['actual_worked_minutes'] = $actualWorkedMinutes;
        $result['break_deducted_minutes'] = $breakDeducted;
        $result['payable_minutes'] = $payableMinutes;
        $result['undertime_minutes'] = $deficit;
        $result['overtime_minutes'] = $surplus;
        $result['status'] = $status;
        $result['scheduled_paid_minutes'] = $requiredMinutes;

        return $result;
    }

    // ─────────────────────────────────────────
    // Split shift computation
    // ─────────────────────────────────────────

    private function computeSplit(array $result, array $daySchedule, string $dateKey, Carbon $timeIn, ?Carbon $timeOut, string $tz): array
    {
        $scheduledPaidMinutes = $result['scheduled_paid_minutes'];
        $halfDayThreshold = $result['half_day_threshold_minutes'];

        if (! $timeOut) {
            $result['status'] = 'incomplete';

            return $result;
        }

        $rawWorked = (int) $timeIn->diffInMinutes($timeOut);
        if ($timeOut->lessThanOrEqualTo($timeIn)) {
            $rawWorked = (int) $timeIn->diffInMinutes($timeOut->copy()->addDay());
        }

        $breakDeducted = $this->totalUnpaidBreakOverlapMinutes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);
        $gapDeducted = $this->computeSplitGapMinutes($dateKey, $daySchedule, $timeIn, $timeOut, $tz);

        $actualWorkedMinutes = max(0, $rawWorked - $breakDeducted - $gapDeducted);
        $payableMinutes = min($actualWorkedMinutes, $scheduledPaidMinutes);
        $undertime = max(0, $scheduledPaidMinutes - $actualWorkedMinutes);

        $status = 'present';
        if ($actualWorkedMinutes <= 0) {
            $status = 'absent';
        } elseif ($payableMinutes < $halfDayThreshold) {
            $status = 'half_day';
        } elseif ($undertime > 0) {
            $status = 'undertime';
        }

        $result['actual_worked_minutes'] = $actualWorkedMinutes;
        $result['break_deducted_minutes'] = $breakDeducted + $gapDeducted;
        $result['payable_minutes'] = $payableMinutes;
        $result['undertime_minutes'] = $undertime;
        $result['status'] = $status;

        return $result;
    }

    private function computeSplitGapMinutes(string $dateKey, array $daySchedule, Carbon $timeIn, Carbon $timeOut, string $tz): int
    {
        $blocks = $daySchedule['work_blocks'] ?? [];
        if (! is_array($blocks) || count($blocks) < 2) {
            return 0;
        }

        $gapMinutes = 0;
        $sortedBlocks = $blocks;
        usort($sortedBlocks, fn ($a, $b) => strcmp($a['start'] ?? '', $b['start'] ?? ''));

        for ($i = 0; $i < count($sortedBlocks) - 1; $i++) {
            $gapStart = Carbon::parse($dateKey.' '.substr($sortedBlocks[$i]['end'] ?? '00:00', 0, 5), $tz);
            $gapEnd = Carbon::parse($dateKey.' '.substr($sortedBlocks[$i + 1]['start'] ?? '00:00', 0, 5), $tz);

            if ($gapEnd->greaterThan($gapStart)) {
                $overlapStart = $timeIn->greaterThan($gapStart) ? $timeIn->copy() : $gapStart->copy();
                $overlapEnd = $timeOut->lessThan($gapEnd) ? $timeOut->copy() : $gapEnd->copy();
                if ($overlapEnd->greaterThan($overlapStart)) {
                    $gapMinutes += (int) $overlapStart->diffInMinutes($overlapEnd);
                }
            }
        }

        return $gapMinutes;
    }

    private function summarizeSplit(string $dateKey, array $daySchedule, string $tz): array
    {
        $blocks = $daySchedule['work_blocks'] ?? [];
        if (! is_array($blocks) || empty($blocks)) {
            return ['start' => null, 'end' => null, 'span_minutes' => 0, 'break_minutes' => 0, 'required_minutes' => 0];
        }

        $totalBlockMinutes = 0;
        $firstStart = null;
        $lastEnd = null;

        foreach ($blocks as $block) {
            $s = $this->normalizeTime($block['start'] ?? null);
            $e = $this->normalizeTime($block['end'] ?? null);
            if ($s === null || $e === null) {
                continue;
            }
            $start = Carbon::parse($dateKey.' '.$s, $tz);
            $end = Carbon::parse($dateKey.' '.$e, $tz);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $totalBlockMinutes += (int) $start->diffInMinutes($end);
            if ($firstStart === null || $start->lessThan($firstStart)) {
                $firstStart = $start;
            }
            if ($lastEnd === null || $end->greaterThan($lastEnd)) {
                $lastEnd = $end;
            }
        }

        $explicitPaid = $daySchedule['expected_paid_minutes'] ?? null;
        if ($explicitPaid !== null && (int) $explicitPaid > 0) {
            $required = (int) $explicitPaid;
        } else {
            $required = $totalBlockMinutes;
        }

        $spanMinutes = ($firstStart && $lastEnd) ? (int) $firstStart->diffInMinutes($lastEnd) : 0;

        return [
            'start' => $firstStart,
            'end' => $lastEnd,
            'span_minutes' => $spanMinutes,
            'break_minutes' => max(0, $spanMinutes - $required),
            'required_minutes' => $required,
        ];
    }

    private function normalizeTime(mixed $value): ?string
    {
        $time = trim((string) $value);
        if ($time === '' || ! preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
            return null;
        }

        return substr($time, 0, 5);
    }

    /**
     * Human-readable shift label for calendar, attendance, reports, and efficiency views.
     */
    public function scheduleLabelForDaySchedule(
        ?array $daySchedule,
        bool $isRestDay = false,
        bool $isRestDayWorked = false,
    ): ?string {
        if ($isRestDayWorked) {
            return 'Rest Day Worked';
        }

        if ($isRestDay || ! is_array($daySchedule)) {
            return AttendanceStatusResolver::REST_DAY_LABEL;
        }

        $in = $this->normalizeTime($daySchedule['in'] ?? null);
        $out = $this->normalizeTime($daySchedule['out'] ?? null);
        $optionName = trim((string) ($daySchedule['matched_schedule_option_name'] ?? ''));
        $options = $daySchedule['flexible_shift_options'] ?? null;
        $matchSource = $daySchedule['match_source'] ?? null;
        $hasMultipleOptions = is_array($options) && count($options) > 1;

        if ($hasMultipleOptions && ! in_array($matchSource, ['automatic', 'schedule_adjustment'], true)) {
            $parts = [];
            foreach ($options as $option) {
                if (! is_array($option)) {
                    continue;
                }
                $optionIn = $this->normalizeTime($option['in'] ?? null);
                $optionOut = $this->normalizeTime($option['out'] ?? null);
                if ($optionIn === null || $optionOut === null) {
                    continue;
                }
                $name = trim((string) ($option['matched_schedule_option_name'] ?? $option['option_name'] ?? ''));
                $range = "{$optionIn} – {$optionOut}";
                $parts[] = ($name !== '' && strcasecmp($name, 'Default') !== 0) ? "{$name}: {$range}" : $range;
            }

            if ($parts !== []) {
                return implode(' / ', $parts);
            }
        }

        if ($in !== null && $out !== null) {
            $range = "{$in} – {$out}";
            if ($optionName !== '' && strcasecmp($optionName, 'Default') !== 0) {
                return "{$optionName}: {$range}";
            }

            return $range;
        }

        if (! is_array($options) || $options === []) {
            return $in !== null ? $in : ($out !== null ? $out : null);
        }

        $parts = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $optionIn = $this->normalizeTime($option['in'] ?? null);
            $optionOut = $this->normalizeTime($option['out'] ?? null);
            if ($optionIn === null || $optionOut === null) {
                continue;
            }
            $name = trim((string) ($option['matched_schedule_option_name'] ?? $option['option_name'] ?? ''));
            $range = "{$optionIn} – {$optionOut}";
            $parts[] = ($name !== '' && strcasecmp($name, 'Default') !== 0) ? "{$name}: {$range}" : $range;
        }

        return $parts !== [] ? implode(' / ', $parts) : null;
    }
}
