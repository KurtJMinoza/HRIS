<?php

namespace App\Http\Controllers\Admin;

use App\Events\ScheduleUpdated;
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

        $affectedIds = User::query()
            ->where('working_schedule_id', $schedule->id)
            ->visibleEmployees()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($affectedIds !== []) {
            ScheduleUpdated::dispatch($schedule->fresh(), $affectedIds, 'updated');
        }

        return response()->json([
            'message' => 'Schedule updated.',
            'schedule' => $this->scheduleResponse($schedule->fresh()),
        ]);
    }

    /**
     * Delete a working schedule. Unassigns all employees who had this schedule.
     */
    public function destroy(int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $affectedIds = User::query()
            ->where('working_schedule_id', $schedule->id)
            ->visibleEmployees()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Unassign all employees before deleting so they show as "Available".
        User::where('working_schedule_id', $schedule->id)
            ->visibleEmployees()
            ->update([
                'schedule' => null,
                'working_schedule_id' => null,
            ]);

        if ($affectedIds !== []) {
            ScheduleUpdated::dispatch(null, $affectedIds, 'destroyed');
        }

        $schedule->delete();

        return response()->json([
            'message' => 'Schedule deleted. All assigned employees have been unassigned.',
        ]);
    }

    /**
     * Assign schedule to one or more employees using live template linkage.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // `employee_ids` = desired roster for THIS shift after the update (full list, not per-row toggle).
        $desiredIds = array_values(array_unique(array_map('intval', $validated['employee_ids'])));
        $scheduleId = (int) $schedule->id;

        $currentlyOnShift = User::query()
            ->visibleEmployees()
            ->where('working_schedule_id', $scheduleId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toUnassign = array_values(array_diff($currentlyOnShift, $desiredIds));
        $toAssignCandidates = array_values(array_diff($desiredIds, $currentlyOnShift));

        $toAssign = [];
        $otherSchedule = [];

        $newAssignees = User::query()
            ->whereIn('id', $toAssignCandidates)
            ->visibleEmployees()
            ->with('workingSchedule')
            ->get();

        foreach ($newAssignees as $emp) {
            $onThisSchedule = (int) $emp->working_schedule_id === $scheduleId;
            $hasOtherSchedule = $emp->working_schedule_id !== null && $emp->workingSchedule !== null
                ? ! $onThisSchedule
                : false;

            if ($hasOtherSchedule) {
                $currentName = $emp->workingSchedule?->name ?? 'Custom schedule';
                $currentTime = $emp->workingSchedule
                    ? "{$emp->workingSchedule->time_in}–{$emp->workingSchedule->time_out}"
                    : self::formatCustomScheduleTime($emp->schedule);
                $otherSchedule[] = [
                    'employee_id' => $emp->id,
                    'employee_name' => $emp->display_name,
                    'current_schedule' => $currentName,
                    'current_time' => $currentTime,
                ];
            } else {
                $toAssign[] = $emp->id;
            }
        }

        if (! empty($otherSchedule)) {
            return response()->json([
                'message' => 'Employee already assigned to another shift. Please unassign first before reassigning.',
                'conflicts' => $otherSchedule,
            ], 422);
        }

        $assignedCount = 0;
        $unassignedCount = 0;

        if (! empty($toAssign)) {
            $assignedCount = User::whereIn('id', $toAssign)
                ->visibleEmployees()
                ->update([
                    'schedule' => null,
                    'working_schedule_id' => $schedule->id,
                    'pending_working_schedule_id' => null,
                    'pending_schedule_effective_from' => null,
                ]);
        }

        if (! empty($toUnassign)) {
            $unassignedCount = User::whereIn('id', $toUnassign)
                ->visibleEmployees()
                ->update([
                    'schedule' => null,
                    'working_schedule_id' => null,
                    'pending_working_schedule_id' => null,
                    'pending_schedule_effective_from' => null,
                ]);
        }

        $message = [];
        if ($assignedCount > 0) {
            $message[] = "{$assignedCount} assigned.";
        }
        if ($unassignedCount > 0) {
            $message[] = "{$unassignedCount} unassigned.";
        }

        $rosterChanged = array_values(array_unique(array_merge(
            array_map('intval', $toAssign),
            array_map('intval', $toUnassign)
        )));
        if ($rosterChanged !== []) {
            ScheduleUpdated::dispatch($schedule->fresh(), $rosterChanged, 'assigned');
        }

        return response()->json([
            'message' => implode(' ', $message) ?: 'No changes.',
            'assigned_count' => $assignedCount,
            'unassigned_count' => $unassignedCount,
            'assigned_ids' => $toAssign,
            'unassigned_ids' => $toUnassign,
        ]);
    }

    private function hasWorkingDays(?array $schedule): bool
    {
        if (! is_array($schedule) || empty($schedule)) {
            return false;
        }
        foreach ($schedule as $dayConfig) {
            if (is_array($dayConfig) && trim((string) ($dayConfig['in'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function formatCustomScheduleTime(?array $schedule): string
    {
        if (! is_array($schedule) || empty($schedule)) {
            return '—';
        }
        foreach (self::DAY_KEYS as $day) {
            $dayConfig = $schedule[$day] ?? null;
            if (is_array($dayConfig) && ! empty($dayConfig['in']) && ! empty($dayConfig['out'])) {
                return "{$dayConfig['in']}–{$dayConfig['out']}";
            }
        }

        return '—';
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
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }
}
