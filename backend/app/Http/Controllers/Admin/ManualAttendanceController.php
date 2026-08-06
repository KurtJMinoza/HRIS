<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManualAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ManualAttendanceController extends Controller
{
    public function __construct(
        private readonly ManualAttendanceService $manualAttendanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:active,reversed,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->manualAttendanceService->paginateList($request->user(), $validated);

        return response()->json([
            'data' => $result['data'],
            'meta' => $result['meta'],
            'reason_codes' => ManualAttendanceService::REASON_CODES,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'segments' => ['nullable', 'array'],
            'segments.*.time_in' => ['nullable', 'date_format:H:i'],
            'segments.*.time_out' => ['nullable', 'date_format:H:i'],
            'shift_match_mode' => ['nullable', 'string', 'in:auto,explicit'],
            'schedule_option_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->manualAttendanceService->preview($validated, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required_without:dates', 'nullable', 'date'],
            'dates' => ['required_without:date', 'nullable', 'array', 'min:1', 'max:62'],
            'dates.*' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'segments' => ['nullable', 'array'],
            'segments.*.time_in' => ['nullable', 'date_format:H:i'],
            'segments.*.time_out' => ['nullable', 'date_format:H:i'],
            'reason_code' => ['required', 'string'],
            'manual_remarks' => ['nullable', 'string', 'max:65535'],
            'shift_match_mode' => ['nullable', 'string', 'in:auto,explicit'],
            'schedule_option_id' => ['nullable', 'integer'],
            'conflict_action' => ['nullable', 'string', 'in:create,complete_missing,replace,add_segment'],
            'override_leave' => ['nullable', 'boolean'],
            'partial_day' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['dates']) && is_array($validated['dates'])) {
            $result = $this->manualAttendanceService->storeManyDates($validated, $request->user());

            return response()->json([
                'message' => $result['saved'] === 1
                    ? 'Manual attendance saved and approved.'
                    : "Saved {$result['saved']} day(s) of manual attendance.",
                'saved' => $result['saved'],
                'failed' => $result['failed'],
                'created_ids' => $result['created_ids'],
            ], 201);
        }

        $record = $this->manualAttendanceService->store($validated, $request->user());

        return response()->json([
            'message' => 'Manual attendance saved and approved.',
            'record' => $this->manualAttendanceService->findManualRecord((int) $record->id),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $record = $this->manualAttendanceService->findManualRecord($id);
        $employee = $record->user;
        if ($employee) {
            app(\App\Services\DataScopeService::class)->ensureEmployeeAccessible($request->user(), $employee);
        }

        $tz = $this->manualAttendanceService->attendanceTimezone();
        $context = $employee
            ? $this->manualAttendanceService->resolveContext($employee, $record->date?->toDateString() ?? '', $request->user())
            : null;

        return response()->json([
            'record' => [
                'id' => (int) $record->id,
                'employee_id' => (int) $record->user_id,
                'date' => $record->date?->toDateString(),
                'time_in' => $record->time_in?->copy()->timezone($tz)->toIso8601String(),
                'time_out' => $record->time_out?->copy()->timezone($tz)->toIso8601String(),
                'work_segments' => $record->work_segments,
                'reason_code' => $record->manual_reason_code,
                'manual_remarks' => $record->manual_remarks,
                'shift_match_mode' => $record->shift_match_mode,
                'schedule_option_id' => $record->matched_schedule_option_id,
                'approved' => (bool) $record->approved,
                'approved_at' => $record->approved_at?->toIso8601String(),
                'is_reversed' => $record->isReversed(),
                'source_badge' => 'Admin Manual',
            ],
            'context' => $context,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'segments' => ['nullable', 'array'],
            'segments.*.time_in' => ['nullable', 'date_format:H:i'],
            'segments.*.time_out' => ['nullable', 'date_format:H:i'],
            'reason_code' => ['required', 'string'],
            'manual_remarks' => ['nullable', 'string', 'max:65535'],
            'edit_reason' => ['required', 'string', 'max:65535'],
            'shift_match_mode' => ['nullable', 'string', 'in:auto,explicit'],
            'schedule_option_id' => ['nullable', 'integer'],
        ]);

        $record = $this->manualAttendanceService->update($id, $validated, $request->user());

        return response()->json([
            'message' => 'Manual attendance updated.',
            'record' => $record,
        ]);
    }

    public function addSegments(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'segments' => ['required', 'array', 'min:1'],
            'segments.*.time_in' => ['required', 'date_format:H:i'],
            'segments.*.time_out' => ['required', 'date_format:H:i'],
            'reason_code' => ['required', 'string'],
            'manual_remarks' => ['nullable', 'string', 'max:65535'],
            'edit_reason' => ['required', 'string', 'max:65535'],
        ]);

        $record = $this->manualAttendanceService->findManualRecord($id);
        $payload = array_merge($validated, [
            'employee_id' => (int) $record->user_id,
            'date' => $record->date?->toDateString(),
            'conflict_action' => 'add_segment',
        ]);

        $updated = $this->manualAttendanceService->store($payload, $request->user());

        return response()->json([
            'message' => 'Work segment added.',
            'record' => $updated,
        ]);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reversal_reason' => ['required', 'string', 'max:65535'],
        ]);

        $record = $this->manualAttendanceService->reverse($id, $validated['reversal_reason'], $request->user());

        return response()->json([
            'message' => 'Manual attendance reversed.',
            'record' => $record,
        ]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'history' => $this->manualAttendanceService->history($id, $request->user()),
        ]);
    }

    public function bulk(Request $request): JsonResponse
    {
        if (! app(\App\Services\RbacService::class)->can($request->user(), 'attendance.manual.bulk_create')) {
            throw ValidationException::withMessages(['permission' => ['Bulk manual attendance permission required.']]);
        }

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'time_in' => ['required', 'date_format:H:i'],
            'time_out' => ['required', 'date_format:H:i'],
            'reason_code' => ['required', 'string'],
            'manual_remarks' => ['nullable', 'string', 'max:65535'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer'],
        ]);

        $ready = [];
        $conflicts = [];

        foreach ($validated['employee_ids'] as $employeeId) {
            try {
                $payload = array_merge($validated, [
                    'employee_id' => (int) $employeeId,
                    'segments' => [[
                        'time_in' => $validated['time_in'],
                        'time_out' => $validated['time_out'],
                    ]],
                    'conflict_action' => 'create',
                ]);
                $context = $this->manualAttendanceService->resolveContext(
                    \App\Models\User::query()->whereKey((int) $employeeId)->attendanceEmployees()->firstOrFail(),
                    $validated['date'],
                    $request->user(),
                );
                if (($context['conflicts'] ?? []) !== []) {
                    $conflicts[] = ['employee_id' => (int) $employeeId, 'conflicts' => $context['conflicts']];

                    continue;
                }
                $ready[] = (int) $employeeId;
            } catch (\Throwable $e) {
                $conflicts[] = ['employee_id' => (int) $employeeId, 'message' => $e->getMessage()];
            }
        }

        $created = [];
        if ($request->boolean('apply') && $ready !== []) {
            foreach ($ready as $employeeId) {
                $payload = array_merge($validated, [
                    'employee_id' => $employeeId,
                    'segments' => [[
                        'time_in' => $validated['time_in'],
                        'time_out' => $validated['time_out'],
                    ]],
                    'conflict_action' => 'create',
                ]);
                $created[] = $this->manualAttendanceService->store($payload, $request->user())->id;
            }
        }

        return response()->json([
            'selected' => count($validated['employee_ids']),
            'ready' => count($ready),
            'conflicts' => $conflicts,
            'created_ids' => $created,
        ]);
    }
}
