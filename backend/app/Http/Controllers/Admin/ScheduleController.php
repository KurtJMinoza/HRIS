<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkingSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Normalize time string to H:i (e.g. "12:00:00" -> "12:00"). Accepts H:i or H:i:s.
     */
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

    /**
     * List all working schedules.
     */
    public function index(): JsonResponse
    {
        $schedules = WorkingSchedule::orderBy('name')->get();

        return response()->json([
            'schedules' => $schedules->map(fn (WorkingSchedule $s) => $this->scheduleResponse($s)),
        ]);
    }

    /**
     * Create a new working schedule.
     */
    public function store(Request $request): JsonResponse
    {
        $toMerge = [];
        foreach (['time_in', 'time_out', 'break_start', 'break_end'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                $normalized = $this->normalizeTimeToHi(is_string($val) ? $val : (string) $val);
                if ($normalized !== null) {
                    $toMerge[$key] = $normalized;
                }
            }
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'time_in' => ['required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'late_allowance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'rest_days' => ['nullable', 'array'],
            'rest_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
        ], [
            'name.regex' => 'Schedule name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        // Allow time_out <= time_in for night shift (e.g. 22:00 - 06:00)
        // No extra validation; both times are required.

        $schedule = WorkingSchedule::create([
            'name' => $validated['name'],
            'time_in' => $validated['time_in'],
            'break_start' => $validated['break_start'] ?? null,
            'break_end' => $validated['break_end'] ?? null,
            'time_out' => $validated['time_out'],
            'grace_period_minutes' => $validated['grace_period_minutes'] ?? 0,
            'early_timein_minutes' => $validated['early_timein_minutes'] ?? 60,
            'late_allowance_minutes' => $validated['late_allowance_minutes'] ?? null,
            'early_timeout_minutes' => $validated['early_timeout_minutes'] ?? null,
            'overtime_buffer_minutes' => $validated['overtime_buffer_minutes'] ?? 15,
            'rest_days' => $validated['rest_days'] ?? [],
        ]);

        return response()->json([
            'message' => 'Schedule created.',
            'schedule' => $this->scheduleResponse($schedule),
        ], 201);
    }

    /**
     * Update an existing working schedule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $toMerge = [];
        foreach (['time_in', 'time_out', 'break_start', 'break_end'] as $key) {
            $val = $request->input($key);
            if ($val !== null && $val !== '') {
                $normalized = $this->normalizeTimeToHi(is_string($val) ? $val : (string) $val);
                if ($normalized !== null) {
                    $toMerge[$key] = $normalized;
                }
            }
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'time_in' => ['sometimes', 'required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => ['sometimes', 'required', 'date_format:H:i'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'late_allowance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'rest_days' => ['nullable', 'array'],
            'rest_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
        ], [
            'name.regex' => 'Schedule name may only contain letters, numbers, spaces, hyphens, and apostrophes.',
        ]);

        // Allow time_out <= time_in for night shift (e.g. 22:00 - 06:00)

        $schedule->fill($validated);
        $schedule->save();

        return response()->json([
            'message' => 'Schedule updated.',
            'schedule' => $this->scheduleResponse($schedule->fresh()),
        ]);
    }

    /**
     * Delete a working schedule.
     */
    public function destroy(int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);
        $schedule->delete();

        return response()->json([
            'message' => 'Schedule deleted.',
        ]);
    }

    /**
     * Assign schedule to one or more employees by generating their per-day schedule JSON.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $restDays = $schedule->rest_days ?? [];
        $baseDayConfig = [];
        foreach (self::DAY_KEYS as $dayKey) {
            if (in_array($dayKey, $restDays, true)) {
                $baseDayConfig[$dayKey] = null;
            } else {
                $baseDayConfig[$dayKey] = [
                    'in' => $schedule->time_in,
                    'out' => $schedule->time_out,
                    'break_start' => $schedule->break_start,
                    'break_end' => $schedule->break_end,
                    'grace_minutes' => (int) ($schedule->grace_period_minutes ?: config('attendance.grace_period_minutes', 5)),
                    'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                    'late_allowance_minutes' => $schedule->late_allowance_minutes,
                    'early_timeout_minutes' => $schedule->early_timeout_minutes,
                    'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
                ];
            }
        }

        $employees = User::whereIn('id', $validated['employee_ids'])
            ->where('role', User::ROLE_EMPLOYEE)
            ->get();

        foreach ($employees as $employee) {
            $employee->update([
                'schedule' => $baseDayConfig,
                'working_schedule_id' => $schedule->id,
            ]);
        }

        return response()->json([
            'message' => 'Schedule assigned to employees.',
            'assigned_count' => $employees->count(),
        ]);
    }

    private function scheduleResponse(WorkingSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'time_in' => $schedule->time_in,
            'break_start' => $schedule->break_start,
            'break_end' => $schedule->break_end,
            'time_out' => $schedule->time_out,
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
            'late_allowance_minutes' => $schedule->late_allowance_minutes,
            'early_timeout_minutes' => $schedule->early_timeout_minutes,
            'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            'rest_days' => $schedule->rest_days ?? [],
            'created_at' => $schedule->created_at?->toIso8601String(),
        ];
    }
}

