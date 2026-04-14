<?php

namespace App\Services;

use App\Models\WorkingSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates employee "custom schedule" requests and mirrors Admin → Schedules create rules.
 */
class ScheduleRequestPayloadService
{
    /**
     * Validate incoming JSON for custom schedule request (store).
     *
     * @return array<string, mixed> Normalized payload for JSON storage + later WorkingSchedule::create
     */
    public function validateCustomPayload(array $input): array
    {
        $v = Validator::make($input, [
            'name' => ['required', 'string', 'max:255', "regex:/^[A-Za-z0-9\s\-']+$/"],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'break_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'late_allowance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'rest_days' => ['nullable', 'array'],
            'rest_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'shift_type' => ['nullable', 'string', 'in:day,night,rotating'],
        ], [
            'name.regex' => 'Schedule name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        if ($v->fails()) {
            throw ValidationException::withMessages($v->errors()->toArray());
        }

        $data = $v->validated();
        $timeIn = $this->normalizeTimeToHi(is_string($data['time_in']) ? $data['time_in'] : (string) $data['time_in']);
        $timeOut = $this->normalizeTimeToHi(is_string($data['time_out']) ? $data['time_out'] : (string) $data['time_out']);
        $breakStart = isset($data['break_start']) ? $this->normalizeTimeToHi((string) $data['break_start']) : null;
        $breakEnd = isset($data['break_end']) ? $this->normalizeTimeToHi((string) $data['break_end']) : null;

        $breakMins = isset($data['break_duration_minutes']) ? (int) $data['break_duration_minutes'] : null;
        if ($breakMins !== null && $breakMins > 0 && ($breakStart === null || $breakStart === '') && ($breakEnd === null || $breakEnd === '')) {
            [$breakStart, $breakEnd] = $this->deriveBreakFromDuration($timeIn, $timeOut, $breakMins);
        }

        $restDays = array_values(array_unique($data['rest_days'] ?? []));

        $all = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $work = array_values(array_diff($all, $restDays));
        if ($work === []) {
            throw ValidationException::withMessages([
                'custom_schedule.rest_days' => ['Select at least one working day (not all rest days).'],
            ]);
        }

        return [
            'name' => trim((string) $data['name']),
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'break_start' => $breakStart,
            'break_end' => $breakEnd,
            'break_duration_minutes' => $breakMins,
            'grace_period_minutes' => (int) ($data['grace_period_minutes'] ?? 0),
            'early_timein_minutes' => isset($data['early_timein_minutes']) ? (int) $data['early_timein_minutes'] : 60,
            'late_allowance_minutes' => isset($data['late_allowance_minutes']) ? (int) $data['late_allowance_minutes'] : null,
            'early_timeout_minutes' => isset($data['early_timeout_minutes']) ? (int) $data['early_timeout_minutes'] : null,
            'overtime_buffer_minutes' => isset($data['overtime_buffer_minutes']) ? (int) $data['overtime_buffer_minutes'] : 15,
            'rest_days' => $restDays,
            'shift_type' => $data['shift_type'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  From validateCustomPayload or DB JSON
     */
    public function createWorkingScheduleFromPayload(array $payload): WorkingSchedule
    {
        $attrs = $this->toWorkingScheduleAttributes($payload);

        return WorkingSchedule::create($attrs);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function summaryFromPayload(array $payload): array
    {
        $restDays = collect($payload['rest_days'] ?? [])
            ->map(fn ($day) => strtoupper((string) $day))
            ->values()
            ->all();

        $all = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $work = array_values(array_diff($all, $payload['rest_days'] ?? []));
        $breakDuration = isset($payload['break_duration_minutes']) ? (int) $payload['break_duration_minutes'] : null;

        return [
            'id' => null,
            'name' => (string) ($payload['name'] ?? 'Custom schedule'),
            'time_in' => $payload['time_in'] ?? '00:00',
            'time_out' => $payload['time_out'] ?? '00:00',
            'break_start' => $payload['break_start'] ?? null,
            'break_end' => $payload['break_end'] ?? null,
            'break_duration_minutes' => $breakDuration,
            'grace_period_minutes' => (int) ($payload['grace_period_minutes'] ?? 0),
            'rest_days' => $payload['rest_days'] ?? [],
            'rest_days_label' => $restDays !== [] ? implode(', ', $restDays) : 'None',
            'work_days_label' => implode(', ', array_map(fn ($day) => strtoupper($day), $work)),
            'work_days_per_week' => count($work),
            'shift_type' => $payload['shift_type'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function toWorkingScheduleAttributes(array $payload): array
    {
        return [
            'name' => $payload['name'],
            'time_in' => $payload['time_in'],
            'break_start' => $payload['break_start'] ?? null,
            'break_end' => $payload['break_end'] ?? null,
            'time_out' => $payload['time_out'],
            'grace_period_minutes' => $payload['grace_period_minutes'] ?? 0,
            'early_timein_minutes' => $payload['early_timein_minutes'] ?? 60,
            'late_allowance_minutes' => $payload['late_allowance_minutes'] ?? null,
            'early_timeout_minutes' => $payload['early_timeout_minutes'] ?? null,
            'overtime_buffer_minutes' => $payload['overtime_buffer_minutes'] ?? 15,
            'rest_days' => $payload['rest_days'] ?? [],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function deriveBreakFromDuration(string $timeIn, string $timeOut, int $breakMins): array
    {
        if ($breakMins <= 0) {
            return [null, null];
        }
        try {
            $start = Carbon::createFromFormat('H:i', $timeIn);
            $end = Carbon::createFromFormat('H:i', $timeOut);
        } catch (\Throwable) {
            return [null, null];
        }
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->copy()->addDay();
        }
        $spanMin = $start->diffInMinutes($end);
        if ($breakMins >= $spanMin) {
            return [null, null];
        }
        $offset = (int) floor(($spanMin - $breakMins) / 2);
        $breakStart = $start->copy()->addMinutes($offset);
        $breakEnd = $breakStart->copy()->addMinutes($breakMins);

        return [$breakStart->format('H:i'), $breakEnd->format('H:i')];
    }

    private function normalizeTimeToHi(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }
        $v = trim($value);
        if (strlen($v) >= 5 && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        return $v;
    }
}
