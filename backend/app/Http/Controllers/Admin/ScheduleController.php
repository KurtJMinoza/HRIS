<?php

namespace App\Http\Controllers\Admin;

use App\Events\ScheduleUpdated;
use App\Http\Controllers\Controller;
use App\Models\EmployeeScheduleAssignment;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Models\WorkingScheduleDay;
use App\Services\EmployeeScheduleAdjustmentService;
use App\Services\ScheduleComputationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ScheduleController extends Controller
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const DAY_LABELS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    public function __construct(
        private readonly ScheduleComputationService $scheduleComputation,
        private readonly EmployeeScheduleAdjustmentService $scheduleAdjustments,
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

    private function formatActivityShift(?string $start, ?string $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }

        return substr($start, 0, 5).' - '.substr($end, 0, 5);
    }

    public function index(): JsonResponse
    {
        $query = WorkingSchedule::query()->orderBy('name');
        if ($this->hasScheduleDaysTable()) {
            $query->with('days');
        }
        $schedules = $query->get();

        return response()->json([
            'schedules' => $schedules->map(fn (WorkingSchedule $s) => $this->scheduleResponse($s)),
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 40), 1), 100);

        $assignmentActivities = EmployeeScheduleAssignment::query()
            ->with([
                'creator:id,name,first_name,last_name',
                'employee:id,name,first_name,last_name,employee_code,username',
                'template:id,name,time_in,time_out',
                'snapshot:id,employee_schedule_assignment_id,schedule_name,start_time,end_time',
            ])
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->groupBy(function (EmployeeScheduleAssignment $assignment): string {
                return implode('|', [
                    $assignment->created_at?->format('Y-m-d H:i:s') ?? '',
                    $assignment->created_by ?? '',
                    $assignment->schedule_template_id ?? '',
                    $assignment->snapshot?->schedule_name ?? '',
                    $assignment->effective_start_date?->toDateString() ?? '',
                    $assignment->effective_end_date?->toDateString() ?? '',
                    $assignment->assignment_status ?? '',
                    $assignment->source_scope_type ?? '',
                    $assignment->source_scope_id ?? '',
                    trim((string) $assignment->adjustment_reason),
                ]);
            })
            ->map(function ($group) {
                /** @var \Illuminate\Support\Collection<int, EmployeeScheduleAssignment> $group */
                $first = $group->first();
                $scheduleName = $first->template?->name
                    ?? $first->snapshot?->schedule_name
                    ?? 'Custom schedule';
                $isDraft = $first->assignment_status === EmployeeScheduleAssignment::STATUS_DRAFT;
                $title = $first->is_adjustment
                    ? ($isDraft ? 'Schedule adjustment drafted' : 'Schedule adjustment applied')
                    : 'Schedule assignment saved';
                $employeeCount = $group->pluck('employee_id')->unique()->count();

                return [
                    'id' => 'assignment-'.$first->id,
                    'type' => $first->is_adjustment ? 'adjustment' : 'assignment',
                    'title' => $title,
                    'description' => sprintf(
                        '%s for %s %s',
                        $scheduleName,
                        $employeeCount,
                        $employeeCount === 1 ? 'employee' : 'employees',
                    ),
                    'schedule_name' => $scheduleName,
                    'schedule_time' => $this->formatActivityShift(
                        $first->template?->time_in ?? $first->snapshot?->start_time,
                        $first->template?->time_out ?? $first->snapshot?->end_time,
                    ),
                    'employee_count' => $employeeCount,
                    'employees' => $group->take(5)->map(fn (EmployeeScheduleAssignment $assignment) => [
                        'id' => (int) $assignment->employee_id,
                        'name' => $assignment->employee?->display_name
                            ?? $assignment->employee?->name
                            ?? ('Employee #'.$assignment->employee_id),
                        'employee_number' => $assignment->employee?->employee_code
                            ?? $assignment->employee?->username,
                    ])->values(),
                    'effective_start_date' => $first->effective_start_date?->toDateString(),
                    'effective_end_date' => $first->effective_end_date?->toDateString(),
                    'status' => $first->assignment_status,
                    'scope_type' => $first->source_scope_type,
                    'reason' => $first->adjustment_reason,
                    'actor' => $first->creator?->display_name ?? $first->creator?->name,
                    'created_at' => $first->created_at?->toIso8601String(),
                ];
            })
            ->values();

        $templateActivities = WorkingSchedule::query()
            ->latest('updated_at')
            ->limit(120)
            ->get()
            ->flatMap(function (WorkingSchedule $schedule) {
                $items = [[
                    'id' => 'template-created-'.$schedule->id,
                    'type' => 'template_created',
                    'title' => 'Schedule template created',
                    'description' => $schedule->name,
                    'schedule_name' => $schedule->name,
                    'schedule_time' => $this->formatActivityShift($schedule->time_in, $schedule->time_out),
                    'employee_count' => null,
                    'employees' => [],
                    'effective_start_date' => null,
                    'effective_end_date' => null,
                    'status' => $schedule->is_active ? 'active' : 'inactive',
                    'scope_type' => 'template',
                    'reason' => null,
                    'actor' => null,
                    'created_at' => $schedule->created_at?->toIso8601String(),
                ]];

                if ($schedule->updated_at && $schedule->created_at && $schedule->updated_at->gt($schedule->created_at->copy()->addSecond())) {
                    $items[] = [
                        'id' => 'template-updated-'.$schedule->id,
                        'type' => 'template_updated',
                        'title' => 'Schedule template updated',
                        'description' => $schedule->name,
                        'schedule_name' => $schedule->name,
                        'schedule_time' => $this->formatActivityShift($schedule->time_in, $schedule->time_out),
                        'employee_count' => null,
                        'employees' => [],
                        'effective_start_date' => null,
                        'effective_end_date' => null,
                        'status' => $schedule->is_active ? 'active' : 'inactive',
                        'scope_type' => 'template',
                        'reason' => null,
                        'actor' => null,
                        'created_at' => $schedule->updated_at?->toIso8601String(),
                    ];
                }

                return $items;
            });

        $activities = $assignmentActivities
            ->concat($templateActivities)
            ->filter(fn (array $activity) => ! empty($activity['created_at']))
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();

        return response()->json([
            'activities' => $activities,
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
        $days = $request->input('days');
        if (is_array($days)) {
            foreach ($days as $i => $day) {
                if (! is_array($day)) {
                    continue;
                }
                foreach (['time_in', 'time_out', 'break_start', 'break_end'] as $timeKey) {
                    if (! empty($day[$timeKey])) {
                        $days[$i][$timeKey] = $this->normalizeTimeToHi((string) $day[$timeKey]);
                    }
                }
            }
            $toMerge['days'] = $days;
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate($this->validationRules());
        $this->validateFlexibleDays($validated);

        $schedule = WorkingSchedule::create($this->buildScheduleData($validated));
        if (($validated['shift_type'] ?? 'fixed') === WorkingSchedule::SHIFT_FLEXIBLE) {
            $this->syncFlexibleDays($schedule, $validated['days'] ?? []);
            if ($this->hasScheduleDaysTable()) {
                $schedule->load('days');
            }
        }

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
        $days = $request->input('days');
        if (is_array($days)) {
            foreach ($days as $i => $day) {
                if (! is_array($day)) {
                    continue;
                }
                foreach (['time_in', 'time_out', 'break_start', 'break_end'] as $timeKey) {
                    if (! empty($day[$timeKey])) {
                        $days[$i][$timeKey] = $this->normalizeTimeToHi((string) $day[$timeKey]);
                    }
                }
            }
            $toMerge['days'] = $days;
        }
        if ($toMerge !== []) {
            $request->merge($toMerge);
        }

        $validated = $request->validate($this->validationRules(true));
        $this->validateFlexibleDays($validated, true);

        $schedule->fill($this->buildScheduleData($validated, true));
        $schedule->save();

        if (($validated['shift_type'] ?? $schedule->shift_type) === WorkingSchedule::SHIFT_FLEXIBLE
            && array_key_exists('days', $validated)) {
            $this->syncFlexibleDays($schedule, $validated['days'] ?? []);
            if ($this->hasScheduleDaysTable()) {
                $schedule->load('days');
            }
        }

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
            'adjustment_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $desiredIds = array_values(array_unique(array_map('intval', $validated['employee_ids'])));
        $effectiveDate = isset($validated['effective_date']) && $validated['effective_date']
            ? Carbon::parse($validated['effective_date'])->toDateString()
            : Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->toDateString();

        $result = $this->scheduleAdjustments->apply([
            'employee_ids' => $desiredIds,
            'schedule_template_id' => (int) $schedule->id,
            'effective_start_date' => $effectiveDate,
            'effective_end_date' => null,
            'source_scope_type' => 'employee',
            'is_adjustment' => true,
            'adjustment_reason' => $validated['adjustment_reason'] ?? 'Schedule assignment',
            'created_by' => $request->user()?->id,
            'replace_overlaps' => true,
        ]);

        $assignedIds = $result['assigned_ids'];
        $failed = $result['failed'];
        $skippedCount = count($failed);

        Log::info('schedule_bulk_assignment', [
            'selected_employee_count' => count($desiredIds),
            'schedule_template_id' => (int) $schedule->id,
            'effective_date' => $effectiveDate,
            'mode' => $validated['mode'] ?? 'assign_only',
            'updated_count' => count($assignedIds),
            'created_count' => 0,
            'skipped_count' => $skippedCount,
            'unassigned_count' => 0,
            'failures' => $failed,
        ]);

        return response()->json([
            'message' => count($assignedIds) > 0
                ? count($assignedIds).' effective-dated schedule assignment(s) saved.'
                : 'No schedule assignments were saved.',
            'assigned_count' => count($assignedIds),
            'created_count' => count($assignedIds),
            'updated_count' => 0,
            'skipped_count' => $skippedCount,
            'unassigned_count' => 0,
            'assigned_ids' => $assignedIds,
            'unassigned_ids' => [],
            'failed' => $failed,
        ]);
    }

    /**
     * Preview computation for a specific schedule + attendance input.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $query = WorkingSchedule::query();
        if ($this->hasScheduleDaysTable()) {
            $query->with('days');
        }
        $schedule = $query->findOrFail($id);

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'time_out' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $daySchedule = $this->scheduleComputation->buildDayScheduleFromModel($schedule, $validated['date']);
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

    public function adjustmentPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'string', 'in:employee,employees,company,companies,area,areas,branch,branches,division,divisions,department,departments,section,sections,section_unit,section_units'],
            'scope_ids' => ['nullable', 'array'],
            'scope_ids.*' => ['integer'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'exclude_employee_ids' => ['nullable', 'array'],
            'exclude_employee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $employeeIds = $this->scheduleAdjustments->resolveEmployeeIds($validated);
        $excluded = array_values(array_unique(array_map('intval', $validated['exclude_employee_ids'] ?? [])));
        if ($excluded !== []) {
            $employeeIds = array_values(array_diff($employeeIds, $excluded));
        }

        $employees = User::query()
            ->visibleEmployees()
            ->active()
            ->whereIn('id', $employeeIds)
            ->with(['company:id,name', 'branch:id,name,company_id', 'departmentRelation:id,name'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(500)
            ->get();

        return response()->json([
            'affected_count' => count($employeeIds),
            'employees' => $employees->map(fn (User $employee) => [
                'id' => (int) $employee->id,
                'name' => $employee->display_name,
                'employee_number' => $employee->employee_code ?? $employee->username,
                'company' => $employee->company?->name,
                'branch' => $employee->branch?->name,
                'department' => $employee->departmentRelation?->name ?? $employee->department,
                'profile_image' => $employee->profile_image,
                'working_schedule_id' => $employee->working_schedule_id,
                'current_schedule' => $employee->workingSchedule?->name,
            ])->values(),
        ]);
    }

    public function applyAdjustment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'string', 'in:employee,employees,company,companies,area,areas,branch,branches,division,divisions,department,departments,section,sections,section_unit,section_units'],
            'scope_ids' => ['nullable', 'array'],
            'scope_ids.*' => ['integer'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:users,id'],
            'exclude_employee_ids' => ['nullable', 'array'],
            'exclude_employee_ids.*' => ['integer', 'exists:users,id'],
            'schedule_source' => ['required', 'string', 'in:template,custom'],
            'schedule_template_id' => ['nullable', 'integer', 'exists:working_schedules,id'],
            'custom_schedule' => ['nullable', 'array'],
            'custom_schedule.name' => ['nullable', 'string', 'max:255'],
            'custom_schedule.schedule_code' => ['nullable', 'string', 'max:32'],
            'custom_schedule.shift_type' => ['nullable', 'string', 'in:fixed,flexible,split,overnight,rotating,compressed'],
            'custom_schedule.time_in' => ['nullable', 'date_format:H:i'],
            'custom_schedule.time_out' => ['nullable', 'date_format:H:i'],
            'custom_schedule.break_start' => ['nullable', 'date_format:H:i'],
            'custom_schedule.break_end' => ['nullable', 'date_format:H:i'],
            'custom_schedule.crosses_midnight' => ['nullable', 'boolean'],
            'custom_schedule.expected_paid_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'custom_schedule.half_day_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'custom_schedule.grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'custom_schedule.early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'custom_schedule.late_allowance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'custom_schedule.early_timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'custom_schedule.overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'custom_schedule.flexible_required_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'custom_schedule.flexible_earliest_in' => ['nullable', 'date_format:H:i'],
            'custom_schedule.flexible_latest_out' => ['nullable', 'date_format:H:i'],
            'custom_schedule.core_hours_start' => ['nullable', 'date_format:H:i'],
            'custom_schedule.core_hours_end' => ['nullable', 'date_format:H:i'],
            'custom_schedule.rest_days' => ['nullable', 'array'],
            'custom_schedule.rest_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'custom_schedule.breaks' => ['nullable', 'array'],
            'custom_schedule.work_blocks' => ['nullable', 'array'],
            'custom_schedule.description' => ['nullable', 'string', 'max:1000'],
            'effective_start_date' => ['required', 'date'],
            'effective_end_date' => ['nullable', 'date'],
            'adjustment_reason' => ['required', 'string', 'max:1000'],
            'replace_overlaps' => ['nullable', 'boolean'],
            'save_as_draft' => ['nullable', 'boolean'],
        ]);

        $employeeIds = $this->scheduleAdjustments->resolveEmployeeIds($validated);
        $excluded = array_values(array_unique(array_map('intval', $validated['exclude_employee_ids'] ?? [])));
        if ($excluded !== []) {
            $employeeIds = array_values(array_diff($employeeIds, $excluded));
        }

        $result = $this->scheduleAdjustments->apply([
            'employee_ids' => $employeeIds,
            'schedule_template_id' => ($validated['schedule_source'] === 'template') ? ($validated['schedule_template_id'] ?? null) : null,
            'custom_schedule' => ($validated['schedule_source'] === 'custom') ? ($validated['custom_schedule'] ?? null) : null,
            'effective_start_date' => $validated['effective_start_date'],
            'effective_end_date' => $validated['effective_end_date'] ?? null,
            'source_scope_type' => $validated['scope_type'],
            'source_scope_id' => count($validated['scope_ids'] ?? []) === 1 ? (int) $validated['scope_ids'][0] : null,
            'is_adjustment' => true,
            'adjustment_reason' => $validated['adjustment_reason'],
            'created_by' => $request->user()?->id,
            'replace_overlaps' => (bool) ($validated['replace_overlaps'] ?? true),
            'status' => ! empty($validated['save_as_draft'])
                ? \App\Models\EmployeeScheduleAssignment::STATUS_DRAFT
                : \App\Models\EmployeeScheduleAssignment::STATUS_ACTIVE,
        ]);

        if (! empty($validated['save_as_draft'])) {
            $message = 'Schedule adjustment saved as draft.';
        } elseif ((int) ($result['assigned_count'] ?? 0) === 0 && ! empty($result['failed'][0]['reason'])) {
            $message = 'No schedule adjustments applied. '.$result['failed'][0]['reason'];
        } else {
            $message = "{$result['assigned_count']} schedule adjustment(s) applied. Historical schedules remain unchanged.";
        }

        return response()->json([
            'message' => $message,
            ...$result,
        ], ! empty($validated['save_as_draft']) || (int) ($result['assigned_count'] ?? 0) === 0 ? 200 : 201);
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
            'time_in' => [...($isUpdate ? ['sometimes'] : []), 'nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => [...($isUpdate ? ['sometimes'] : []), 'nullable', 'date_format:H:i'],
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
            'days' => ['nullable', 'array'],
            'days.*.day_of_week' => ['required_with:days', 'string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'days.*.is_working_day' => ['nullable', 'boolean'],
            'days.*.time_in' => ['nullable', 'date_format:H:i'],
            'days.*.time_out' => ['nullable', 'date_format:H:i'],
            'days.*.break_start' => ['nullable', 'date_format:H:i'],
            'days.*.break_end' => ['nullable', 'date_format:H:i'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'days.*.expected_paid_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.grace_period_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'days.*.early_timein_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'days.*.overtime_buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'days.*.half_day_threshold_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'days.*.crosses_midnight' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateFlexibleDays(array $validated, bool $isUpdate = false): void
    {
        $shiftType = $validated['shift_type'] ?? 'fixed';
        if ($shiftType !== WorkingSchedule::SHIFT_FLEXIBLE) {
            if (! $isUpdate && empty($validated['time_in'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'time_in' => ['Time in is required.'],
                ]);
            }
            if (! $isUpdate && empty($validated['time_out'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'time_out' => ['Time out is required.'],
                ]);
            }

            return;
        }

        $days = $validated['days'] ?? null;
        if (! is_array($days) || count($days) < 7) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'days' => ['Flexible schedules require one configuration row for each weekday.'],
            ]);
        }

        $errors = [];
        foreach ($days as $index => $day) {
            if (! is_array($day)) {
                continue;
            }
            $dayKey = (string) ($day['day_of_week'] ?? '');
            $label = self::DAY_LABELS[$dayKey] ?? ucfirst($dayKey);
            $isWorking = (bool) ($day['is_working_day'] ?? false);

            if (! $isWorking) {
                continue;
            }

            $timeIn = WorkingScheduleDay::normalizeTime($day['time_in'] ?? null);
            $timeOut = WorkingScheduleDay::normalizeTime($day['time_out'] ?? null);
            $breakStart = WorkingScheduleDay::normalizeTime($day['break_start'] ?? null);
            $breakEnd = WorkingScheduleDay::normalizeTime($day['break_end'] ?? null);

            if (! $timeIn) {
                $errors["days.{$index}.time_in"] = ["{$label} Time In is required."];
            }
            if (! $timeOut) {
                $errors["days.{$index}.time_out"] = ["{$label} Time Out is required."];
            }
            if ($timeIn && $timeOut) {
                $crossesMidnight = (bool) ($day['crosses_midnight'] ?? false) || $timeOut <= $timeIn;
                if (! $crossesMidnight && $timeOut <= $timeIn) {
                    $errors["days.{$index}.time_out"] = ["{$label} Time Out must be later than Time In."];
                }
            }

            if (($breakStart && ! $breakEnd) || (! $breakStart && $breakEnd)) {
                $errors["days.{$index}.break_end"] = ["{$label} break must include both start and end."];
            }

            if ($timeIn && $timeOut && $breakStart && $breakEnd) {
                if (! $this->breakWithinShift($timeIn, $timeOut, $breakStart, $breakEnd, (bool) ($day['crosses_midnight'] ?? false))) {
                    $errors["days.{$index}.break_start"] = ["{$label} break period is outside the scheduled shift."];
                }
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    private function breakWithinShift(
        string $timeIn,
        string $timeOut,
        string $breakStart,
        string $breakEnd,
        bool $crossesMidnight
    ): bool {
        $inMin = WorkingSchedule::timeToMinutes($timeIn);
        $outMin = WorkingSchedule::timeToMinutes($timeOut);
        $bsMin = WorkingSchedule::timeToMinutes($breakStart);
        $beMin = WorkingSchedule::timeToMinutes($breakEnd);

        $spanEnd = $crossesMidnight || $outMin <= $inMin ? $outMin + 1440 : $outMin;
        $breakEndAdj = $beMin <= $bsMin ? $beMin + 1440 : $beMin;

        return $bsMin >= $inMin && $breakEndAdj <= $spanEnd;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function syncFlexibleDays(WorkingSchedule $schedule, array $days): void
    {
        $restDays = [];
        $firstWorking = null;
        $hasScheduleDays = $this->hasScheduleDaysTable();

        if ($hasScheduleDays) {
            $schedule->days()->delete();
        }

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $dayKey = (string) ($day['day_of_week'] ?? '');
            if (! in_array($dayKey, self::DAY_KEYS, true)) {
                continue;
            }

            $isWorking = (bool) ($day['is_working_day'] ?? false);
            if (! $isWorking) {
                $restDays[] = $dayKey;

                continue;
            }

            $timeIn = WorkingScheduleDay::normalizeTime($day['time_in'] ?? null);
            $timeOut = WorkingScheduleDay::normalizeTime($day['time_out'] ?? null);
            $breakStart = WorkingScheduleDay::normalizeTime($day['break_start'] ?? null);
            $breakEnd = WorkingScheduleDay::normalizeTime($day['break_end'] ?? null);
            $crossesMidnight = (bool) ($day['crosses_midnight'] ?? false) || ($timeIn && $timeOut && $timeOut <= $timeIn);

            $rowPayload = [
                'day_of_week' => $dayKey,
                'is_working_day' => true,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'break_start' => $breakStart,
                'break_end' => $breakEnd,
                'break_minutes' => isset($day['break_minutes']) ? (int) $day['break_minutes'] : null,
                'expected_paid_minutes' => isset($day['expected_paid_minutes'])
                    ? (int) $day['expected_paid_minutes']
                    : null,
                'grace_period_minutes' => isset($day['grace_period_minutes'])
                    ? (int) $day['grace_period_minutes']
                    : 5,
                'early_timein_minutes' => isset($day['early_timein_minutes'])
                    ? (int) $day['early_timein_minutes']
                    : 60,
                'overtime_buffer_minutes' => isset($day['overtime_buffer_minutes'])
                    ? (int) $day['overtime_buffer_minutes']
                    : 15,
                'half_day_threshold_minutes' => isset($day['half_day_threshold_minutes'])
                    ? (int) $day['half_day_threshold_minutes']
                    : null,
                'crosses_midnight' => $crossesMidnight,
            ];
            $row = $hasScheduleDays
                ? WorkingScheduleDay::create(array_merge(['working_schedule_id' => $schedule->id], $rowPayload))
                : (object) $rowPayload;

            if ($firstWorking === null) {
                $firstWorking = $row;
            }
        }

        $updates = ['rest_days' => $restDays];
        if ($firstWorking !== null) {
            $updates['time_in'] = $firstWorking->time_in;
            $updates['time_out'] = $firstWorking->time_out;
            $updates['break_start'] = $firstWorking->break_start;
            $updates['break_end'] = $firstWorking->break_end;
            $updates['crosses_midnight'] = $firstWorking->crosses_midnight;
            $updates['grace_period_minutes'] = $firstWorking->grace_period_minutes ?? 5;
            $updates['early_timein_minutes'] = $firstWorking->early_timein_minutes ?? 60;
            $updates['overtime_buffer_minutes'] = $firstWorking->overtime_buffer_minutes ?? 15;
            $updates['expected_paid_minutes'] = $firstWorking->expected_paid_minutes;
            $updates['half_day_threshold_minutes'] = $firstWorking->half_day_threshold_minutes;
        }

        $schedule->forceFill($updates)->save();
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
        $hasScheduleDays = $this->hasScheduleDaysTable();
        if ($hasScheduleDays) {
            $schedule->loadMissing('days');
        }
        $daySchedule = $this->scheduleComputation->buildDayScheduleFromModel($schedule);
        $summary = $this->scheduleComputation->summarize(now()->toDateString(), $daySchedule);
        $days = $hasScheduleDays
            ? $schedule->days->map(fn (WorkingScheduleDay $day) => [
                'day_of_week' => $day->day_of_week,
                'is_working_day' => (bool) $day->is_working_day,
                'time_in' => $day->time_in ? substr((string) $day->time_in, 0, 5) : null,
                'time_out' => $day->time_out ? substr((string) $day->time_out, 0, 5) : null,
                'break_start' => $day->break_start ? substr((string) $day->break_start, 0, 5) : null,
                'break_end' => $day->break_end ? substr((string) $day->break_end, 0, 5) : null,
                'break_minutes' => $day->break_minutes,
                'expected_paid_minutes' => $day->expected_paid_minutes,
                'grace_period_minutes' => $day->grace_period_minutes,
                'early_timein_minutes' => $day->early_timein_minutes,
                'overtime_buffer_minutes' => $day->overtime_buffer_minutes,
                'half_day_threshold_minutes' => $day->half_day_threshold_minutes,
                'crosses_midnight' => (bool) $day->crosses_midnight,
            ])->values()->all()
            : $this->legacyDaysResponse($schedule);

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
            'days' => $days,
            'is_active' => (bool) ($schedule->is_active ?? true),
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }

    private function hasScheduleDaysTable(): bool
    {
        static $exists = null;
        if ($exists === null) {
            $exists = Schema::hasTable('working_schedule_days');
        }

        return $exists;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyDaysResponse(WorkingSchedule $schedule): array
    {
        $restDays = collect($schedule->rest_days ?? [])
            ->map(fn ($day) => strtolower((string) $day))
            ->all();

        return collect(self::DAY_KEYS)->map(fn (string $dayKey) => [
            'day_of_week' => $dayKey,
            'is_working_day' => ! in_array($dayKey, $restDays, true),
            'time_in' => $schedule->time_in ? substr((string) $schedule->time_in, 0, 5) : null,
            'time_out' => $schedule->time_out ? substr((string) $schedule->time_out, 0, 5) : null,
            'break_start' => $schedule->break_start ? substr((string) $schedule->break_start, 0, 5) : null,
            'break_end' => $schedule->break_end ? substr((string) $schedule->break_end, 0, 5) : null,
            'break_minutes' => null,
            'expected_paid_minutes' => $schedule->expected_paid_minutes,
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'early_timein_minutes' => $schedule->early_timein_minutes,
            'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes,
            'half_day_threshold_minutes' => $schedule->half_day_threshold_minutes,
            'crosses_midnight' => (bool) $schedule->crosses_midnight,
        ])->values()->all();
    }
}
