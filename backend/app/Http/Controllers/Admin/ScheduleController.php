<?php

namespace App\Http\Controllers\Admin;

use App\Events\ScheduleUpdated;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\ScheduleComputationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private readonly ScheduleComputationService $scheduleComputation,
    ) {}

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

    public function index(): JsonResponse
    {
        $schedules = WorkingSchedule::orderBy('name')->get();

        return response()->json([
            'schedules' => $schedules->map(fn (WorkingSchedule $s) => $this->scheduleResponse($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $toMerge = [];
        foreach (['time_in', 'time_out', 'break_start', 'break_end', 'flexible_earliest_in', 'flexible_latest_out', 'core_hours_start', 'core_hours_end'] as $key) {
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

        $validated = $request->validate($this->validationRules());

        $schedule = WorkingSchedule::create($this->buildScheduleData($validated));

        return response()->json([
            'message' => 'Schedule created.',
            'schedule' => $this->scheduleResponse($schedule),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $toMerge = [];
        foreach (['time_in', 'time_out', 'break_start', 'break_end', 'flexible_earliest_in', 'flexible_latest_out', 'core_hours_start', 'core_hours_end'] as $key) {
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

        $validated = $request->validate($this->validationRules(true));

        $schedule->fill($this->buildScheduleData($validated, true));
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

    public function destroy(int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $affectedIds = User::query()
            ->where('working_schedule_id', $schedule->id)
            ->visibleEmployees()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

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

    public function assign(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $validated = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'effective_date' => ['nullable', 'date'],
            'mode' => ['nullable', 'string', 'in:assign_only,replace_roster'],
        ]);

        $desiredIds = array_values(array_unique(array_map('intval', $validated['employee_ids'])));
        $scheduleId = (int) $schedule->id;
        $mode = $validated['mode'] ?? 'assign_only';
        $effectiveDate = isset($validated['effective_date']) && $validated['effective_date']
            ? Carbon::parse($validated['effective_date'])->toDateString()
            : null;
        $isFutureAssignment = $effectiveDate !== null
            && Carbon::parse($effectiveDate)->gt(Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->startOfDay());

        $selectedEmployees = User::query()
            ->visibleEmployees()
            ->whereIn('id', $desiredIds)
            ->get();

        $visibleSelectedIds = $selectedEmployees
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = count(array_diff($desiredIds, $visibleSelectedIds));
        $unassignedCount = 0;
        $assignedIds = [];
        $unassignedIds = [];
        $affectedIds = [];
        $assignmentAudit = [];

        foreach ($selectedEmployees as $employee) {
            $oldWorkingScheduleId = $employee->working_schedule_id !== null ? (int) $employee->working_schedule_id : null;
            $oldPendingScheduleId = $employee->pending_working_schedule_id !== null ? (int) $employee->pending_working_schedule_id : null;
            $oldScheduleSnapshot = [
                'working_schedule_id' => $oldWorkingScheduleId,
                'pending_working_schedule_id' => $oldPendingScheduleId,
                'pending_schedule_effective_from' => $employee->pending_schedule_effective_from,
                'has_custom_schedule' => $this->hasWorkingDays($employee->schedule),
            ];
            $hadScheduleAssignment = $oldWorkingScheduleId !== null
                || $oldPendingScheduleId !== null
                || $oldScheduleSnapshot['has_custom_schedule'];

            if ($isFutureAssignment) {
                $employee->pending_working_schedule_id = $scheduleId;
                $employee->pending_schedule_effective_from = $effectiveDate;
            } else {
                $employee->schedule = null;
                $employee->working_schedule_id = $scheduleId;
                $employee->pending_working_schedule_id = null;
                $employee->pending_schedule_effective_from = null;
            }

            if (! $employee->isDirty(['schedule', 'working_schedule_id', 'pending_working_schedule_id', 'pending_schedule_effective_from'])) {
                continue;
            }

            $employee->save();

            if ($hadScheduleAssignment) {
                $updatedCount++;
            } else {
                $createdCount++;
            }

            $employeeId = (int) $employee->id;
            $assignedIds[] = $employeeId;
            $affectedIds[] = $employeeId;
            $assignmentAudit[] = [
                'employee_id' => $employeeId,
                'old' => $oldScheduleSnapshot,
                'new' => [
                    'working_schedule_id' => $isFutureAssignment ? $oldWorkingScheduleId : $scheduleId,
                    'pending_working_schedule_id' => $isFutureAssignment ? $scheduleId : null,
                    'pending_schedule_effective_from' => $isFutureAssignment ? $effectiveDate : null,
                ],
            ];
            $this->forgetEmployeeScheduleCaches($employeeId);
        }

        if ($mode === 'replace_roster') {
            $employeesToUnassign = User::query()
                ->visibleEmployees()
                ->where('working_schedule_id', $scheduleId)
                ->whereNotIn('id', $desiredIds)
                ->get();

            foreach ($employeesToUnassign as $employee) {
                $employee->schedule = null;
                $employee->working_schedule_id = null;
                $employee->pending_working_schedule_id = null;
                $employee->pending_schedule_effective_from = null;

                if (! $employee->isDirty(['schedule', 'working_schedule_id', 'pending_working_schedule_id', 'pending_schedule_effective_from'])) {
                    continue;
                }

                $employee->save();

                $employeeId = (int) $employee->id;
                $unassignedCount++;
                $unassignedIds[] = $employeeId;
                $affectedIds[] = $employeeId;
                $this->forgetEmployeeScheduleCaches($employeeId);
            }
        }

        Log::info('schedule_bulk_assignment', [
            'selected_employee_count' => count($desiredIds),
            'schedule_template_id' => $scheduleId,
            'effective_date' => $effectiveDate,
            'mode' => $mode,
            'updated_count' => $updatedCount,
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
            'unassigned_count' => $unassignedCount,
            'assignments' => $assignmentAudit,
        ]);

        $message = [];
        if ($createdCount > 0) {
            $message[] = "{$createdCount} assigned.";
        }
        if ($updatedCount > 0) {
            $message[] = "{$updatedCount} reassigned.";
        }
        if ($unassignedCount > 0) {
            $message[] = "{$unassignedCount} unassigned.";
        }

        $affectedIds = array_values(array_unique(array_map('intval', $affectedIds)));
        if ($affectedIds !== []) {
            ScheduleUpdated::dispatch($schedule->fresh(), $affectedIds, 'assigned');
        }

        return response()->json([
            'message' => implode(' ', $message) ?: 'No changes.',
            'assigned_count' => $createdCount + $updatedCount,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'unassigned_count' => $unassignedCount,
            'assigned_ids' => $assignedIds,
            'unassigned_ids' => $unassignedIds,
        ]);
    }

    /**
     * Preview computation for a specific schedule + attendance input.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $schedule = WorkingSchedule::findOrFail($id);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'time_out' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $daySchedule = $this->scheduleComputation->buildDayScheduleFromModel($schedule);
        $timeIn = ! empty($validated['time_in']) ? Carbon::parse($validated['time_in'], $tz) : null;
        $timeOut = ! empty($validated['time_out']) ? Carbon::parse($validated['time_out'], $tz) : null;

        $result = $this->scheduleComputation->compute(
            $validated['date'],
            $daySchedule,
            $timeIn,
            $timeOut,
            $tz,
        );

        $result['scheduled_start'] = $result['scheduled_start']?->toIso8601String();
        $result['scheduled_end'] = $result['scheduled_end']?->toIso8601String();

        return response()->json($result);
    }

    private function forgetEmployeeScheduleCaches(int $employeeId): void
    {
        Cache::forget("employee:schedule:{$employeeId}");
        Cache::forget("employee:calendar:{$employeeId}");
        Cache::forget("attendance:schedule:{$employeeId}");
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

    private function validationRules(bool $isUpdate = false): array
    {
        $sometimes = $isUpdate ? 'sometimes|' : '';

        return [
            'name' => [
                ...($isUpdate ? ['sometimes'] : []),
                'required',
                'string',
                'max:255',
                "regex:/^[A-Za-z0-9\s\-']+$/",
            ],
            'schedule_code' => ['nullable', 'string', 'max:32'],
            'shift_type' => ['nullable', 'string', 'in:fixed,flexible,split,overnight,rotating,compressed'],
            'time_in' => [...($isUpdate ? ['sometimes'] : []), 'required', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => [...($isUpdate ? ['sometimes'] : []), 'required', 'date_format:H:i'],
            'crosses_midnight' => ['nullable', 'boolean'],
            'expected_paid_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'half_day_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'late_allowance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'rest_days' => ['nullable', 'array'],
            'rest_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.start' => ['required_with:breaks', 'date_format:H:i'],
            'breaks.*.end' => ['required_with:breaks', 'date_format:H:i'],
            'breaks.*.is_paid' => ['nullable', 'boolean'],
            'work_blocks' => ['nullable', 'array'],
            'work_blocks.*.start' => ['required_with:work_blocks', 'date_format:H:i'],
            'work_blocks.*.end' => ['required_with:work_blocks', 'date_format:H:i'],
            'flexible_required_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'flexible_earliest_in' => ['nullable', 'date_format:H:i'],
            'flexible_latest_out' => ['nullable', 'date_format:H:i'],
            'core_hours_start' => ['nullable', 'date_format:H:i'],
            'core_hours_end' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function buildScheduleData(array $validated, bool $isUpdate = false): array
    {
        $data = [];

        $directFields = [
            'name', 'schedule_code', 'time_in', 'time_out',
            'break_start', 'break_end', 'breaks', 'work_blocks',
            'rest_days', 'description',
            'flexible_earliest_in', 'flexible_latest_out',
            'core_hours_start', 'core_hours_end',
        ];

        foreach ($directFields as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        $data['shift_type'] = $validated['shift_type'] ?? 'fixed';

        if (array_key_exists('crosses_midnight', $validated)) {
            $data['crosses_midnight'] = (bool) $validated['crosses_midnight'];
        } elseif (! $isUpdate) {
            $timeIn = $validated['time_in'] ?? null;
            $timeOut = $validated['time_out'] ?? null;
            $data['crosses_midnight'] = ($timeIn && $timeOut && $timeOut <= $timeIn);
        }

        $intFields = [
            'expected_paid_minutes' => null,
            'half_day_threshold_minutes' => null,
            'grace_period_minutes' => 0,
            'early_timein_minutes' => 60,
            'late_allowance_minutes' => null,
            'early_timeout_minutes' => null,
            'overtime_buffer_minutes' => 15,
            'flexible_required_minutes' => null,
        ];

        foreach ($intFields as $field => $default) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field] !== null && $validated[$field] !== ''
                    ? (int) $validated[$field]
                    : $default;
            } elseif (! $isUpdate) {
                $data[$field] = $default;
            }
        }

        if (! $isUpdate && ! array_key_exists('rest_days', $data)) {
            $data['rest_days'] = [];
        }

        return $data;
    }

    private function scheduleResponse(WorkingSchedule $schedule): array
    {
        $daySchedule = $this->scheduleComputation->buildDayScheduleFromModel($schedule);
        $summary = $this->scheduleComputation->summarize(now()->toDateString(), $daySchedule);

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'schedule_code' => $schedule->schedule_code,
            'shift_type' => $schedule->shift_type ?? 'fixed',
            'time_in' => $schedule->time_in,
            'break_start' => $schedule->break_start,
            'break_end' => $schedule->break_end,
            'time_out' => $schedule->time_out,
            'crosses_midnight' => (bool) ($schedule->crosses_midnight ?? false),
            'expected_paid_minutes' => $schedule->expected_paid_minutes,
            'computed_paid_minutes' => $summary['required_minutes'],
            'half_day_threshold_minutes' => $schedule->effective_half_day_threshold,
            'breaks' => $schedule->breaks ?? [],
            'work_blocks' => $schedule->work_blocks ?? [],
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
            'late_allowance_minutes' => $schedule->late_allowance_minutes,
            'early_timeout_minutes' => $schedule->early_timeout_minutes,
            'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            'rest_days' => $schedule->rest_days ?? [],
            'flexible_required_minutes' => $schedule->flexible_required_minutes,
            'flexible_earliest_in' => $schedule->flexible_earliest_in,
            'flexible_latest_out' => $schedule->flexible_latest_out,
            'core_hours_start' => $schedule->core_hours_start,
            'core_hours_end' => $schedule->core_hours_end,
            'description' => $schedule->description,
            'is_active' => (bool) ($schedule->is_active ?? true),
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }
}
