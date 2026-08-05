<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FlexibleShiftMatcher
{
    /**
     * @param  list<array<string, mixed>>  $attendanceSegments
     * @param  list<array<string, mixed>>  $availableShiftOptions
     * @return array{option: array<string, mixed>|null, source: string, score: int|null, audit: array<string, mixed>}
     */
    public function matchForAttendance(
        ?int $employeeId,
        string $attendanceDate,
        array $attendanceSegments,
        array $availableShiftOptions,
        ?string $tz = null,
        ?int $explicitOptionId = null,
    ): array {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $options = array_values(array_filter($availableShiftOptions, fn ($option) => is_array($option)));

        if ($options === []) {
            return ['option' => null, 'source' => 'none', 'score' => null, 'audit' => []];
        }

        if ($explicitOptionId !== null) {
            foreach ($options as $option) {
                if ((int) ($option['matched_schedule_option_id'] ?? $option['id'] ?? 0) === $explicitOptionId) {
                    return $this->result($employeeId, $attendanceDate, $attendanceSegments, $options, $option, 'schedule_adjustment', 0, $tz);
                }
            }
        }

        $actualIn = $this->firstSegmentTime($attendanceSegments, 'time_in', $tz);
        $actualOut = $this->lastSegmentTime($attendanceSegments, 'time_out', $tz);

        if (! $actualIn) {
            $default = $this->defaultOption($options);

            return $this->result($employeeId, $attendanceDate, $attendanceSegments, $options, $default, 'default', null, $tz);
        }

        $scored = [];
        $boundaries = $this->clockInBoundaries($attendanceDate, $options, $tz);
        foreach ($options as $index => $option) {
            $score = $this->scoreOption($attendanceDate, $option, $actualIn, $actualOut, $tz, $boundaries[$index] ?? null);
            $scored[] = [
                'option' => $option,
                'score' => $score,
                'sequence' => (int) ($option['sequence'] ?? $index + 1),
                'id' => (int) ($option['matched_schedule_option_id'] ?? $option['id'] ?? 0),
            ];
        }

        usort($scored, function (array $a, array $b): int {
            return [$a['score'], $a['sequence'], $a['id']] <=> [$b['score'], $b['sequence'], $b['id']];
        });

        $winner = $scored[0];

        return $this->result(
            $employeeId,
            $attendanceDate,
            $attendanceSegments,
            $options,
            $winner['option'],
            'automatic',
            (int) $winner['score'],
            $tz,
        );
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array{start: Carbon, end: Carbon}|null  $clockInBoundary
     */
    private function scoreOption(
        string $dateKey,
        array $option,
        Carbon $actualIn,
        ?Carbon $actualOut,
        string $tz,
        ?array $clockInBoundary
    ): int {
        $start = $this->scheduledStart($dateKey, $option, $tz);
        $end = $this->scheduledEnd($dateKey, $option, $tz);
        if (! $start || ! $end) {
            return PHP_INT_MAX;
        }

        $out = $actualOut?->copy();
        if ($out && $out->lessThanOrEqualTo($actualIn)) {
            $out->addDay();
        }

        $timeInDiff = abs((int) $actualIn->diffInMinutes($start, false));
        $timeOutDiff = $out ? abs((int) $out->diffInMinutes($end, false)) : 240;
        $lateMinutes = max(0, (int) $start->diffInMinutes($actualIn, false));
        $undertimeMinutes = ($out && $out->lessThan($end)) ? (int) $out->diffInMinutes($end) : 0;

        $overlapPenalty = 0;
        if ($out && $out->greaterThan($actualIn)) {
            $overlapStart = $actualIn->greaterThan($start) ? $actualIn : $start;
            $overlapEnd = $out->lessThan($end) ? $out : $end;
            $overlap = $overlapEnd->greaterThan($overlapStart)
                ? (int) $overlapStart->diffInMinutes($overlapEnd)
                : 0;
            $scheduledSpan = max(1, (int) $start->diffInMinutes($end));
            $overlapPenalty = max(0, $scheduledSpan - $overlap);
        } else {
            $overlapPenalty = 240;
        }

        $windowPenalty = 0;
        if ($clockInBoundary) {
            if ($actualIn->lessThan($clockInBoundary['start'])) {
                $windowPenalty = min(720, (int) $actualIn->diffInMinutes($clockInBoundary['start']));
            } elseif ($actualIn->greaterThanOrEqualTo($clockInBoundary['end'])) {
                $windowPenalty = min(720, (int) $clockInBoundary['end']->diffInMinutes($actualIn));
            }
        }

        $actualCrossesMidnight = $end->toDateString() !== $start->toDateString();
        $crossMidnightPenalty = ((bool) ($option['crosses_midnight'] ?? false) === $actualCrossesMidnight)
            ? 0
            : 30;

        return $timeInDiff
            + $timeOutDiff
            + $lateMinutes
            + $undertimeMinutes
            + $overlapPenalty
            + $windowPenalty
            + $crossMidnightPenalty;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function clockInBoundaries(string $dateKey, array $options, string $tz): array
    {
        $starts = [];
        foreach ($options as $index => $option) {
            $start = $this->scheduledStart($dateKey, $option, $tz);
            if ($start) {
                $starts[] = ['index' => $index, 'start' => $start];
            }
        }

        usort($starts, fn (array $a, array $b): int => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $boundaries = [];
        foreach ($starts as $position => $item) {
            $start = $item['start'];
            $previous = $starts[$position - 1]['start'] ?? null;
            $next = $starts[$position + 1]['start'] ?? null;

            $windowStart = $previous
                ? $previous->copy()->addMinutes((int) floor($previous->diffInMinutes($start) / 2))
                : $start->copy()->subMinutes(180);
            $windowEnd = $next
                ? $start->copy()->addMinutes((int) floor($start->diffInMinutes($next) / 2))
                : $start->copy()->addMinutes(180);

            $option = $options[$item['index']];
            if (($option['matching_start_tolerance_minutes'] ?? null) !== null) {
                $windowStart = $start->copy()->subMinutes((int) $option['matching_start_tolerance_minutes']);
            }
            if (($option['matching_end_tolerance_minutes'] ?? null) !== null) {
                $windowEnd = $start->copy()->addMinutes((int) $option['matching_end_tolerance_minutes']);
            }

            $boundaries[$item['index']] = ['start' => $windowStart, 'end' => $windowEnd];
        }

        return $boundaries;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>
     */
    private function defaultOption(array $options): array
    {
        foreach ($options as $option) {
            if ((bool) ($option['is_default'] ?? false)) {
                return $option;
            }
        }

        return $options[0];
    }

    private function scheduledStart(string $dateKey, array $option, string $tz): ?Carbon
    {
        $in = $this->normalizeTime($option['in'] ?? $option['time_in'] ?? null);
        return $in ? Carbon::parse($dateKey.' '.$in, $tz) : null;
    }

    private function scheduledEnd(string $dateKey, array $option, string $tz): ?Carbon
    {
        $out = $this->normalizeTime($option['out'] ?? $option['time_out'] ?? null);
        if (! $out) {
            return null;
        }

        $in = $this->normalizeTime($option['in'] ?? $option['time_in'] ?? null);
        $end = Carbon::parse($dateKey.' '.$out, $tz);
        if ((bool) ($option['crosses_midnight'] ?? false) || ($in !== null && $out <= $in)) {
            $end->addDay();
        }

        return $end;
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
     * @param  list<array<string, mixed>>  $segments
     */
    private function firstSegmentTime(array $segments, string $key, string $tz): ?Carbon
    {
        foreach ($segments as $segment) {
            if (is_array($segment) && ! empty($segment[$key])) {
                return $segment[$key] instanceof Carbon
                    ? $segment[$key]->copy()->timezone($tz)
                    : Carbon::parse($segment[$key], $tz);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function lastSegmentTime(array $segments, string $key, string $tz): ?Carbon
    {
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $segment = $segments[$i];
            if (is_array($segment) && ! empty($segment[$key])) {
                return $segment[$key] instanceof Carbon
                    ? $segment[$key]->copy()->timezone($tz)
                    : Carbon::parse($segment[$key], $tz);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @param  list<array<string, mixed>>  $options
     * @return array{option: array<string, mixed>, source: string, score: int|null, audit: array<string, mixed>}
     */
    private function result(
        ?int $employeeId,
        string $attendanceDate,
        array $segments,
        array $options,
        array $selected,
        string $source,
        ?int $score,
        string $tz
    ): array {
        $audit = [
            'employee_id' => $employeeId,
            'attendance_date' => $attendanceDate,
            'available_option_ids' => array_values(array_map(
                fn (array $option) => $option['matched_schedule_option_id'] ?? $option['id'] ?? null,
                $options,
            )),
            'actual_time_in' => $this->firstSegmentTime($segments, 'time_in', $tz)?->toDateTimeString(),
            'actual_time_out' => $this->lastSegmentTime($segments, 'time_out', $tz)?->toDateTimeString(),
            'selected_option_id' => $selected['matched_schedule_option_id'] ?? $selected['id'] ?? null,
            'match_source' => $source,
            'match_score' => $score,
        ];

        Log::debug('flexible_shift_matcher.selected', $audit);

        return [
            'option' => $selected,
            'source' => $source,
            'score' => $score,
            'audit' => $audit,
        ];
    }
}
