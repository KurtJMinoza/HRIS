<?php

namespace App\Services;

use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;

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
            if (! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
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
     * Build per-day schedule array from a WorkingSchedule model.
     * Used by controllers that need to pass a day config to compute().
     */
    public function buildDayScheduleFromModel(WorkingSchedule $model, ?string $dateKey = null): array
    {
        $model->loadMissing('days');

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
}
