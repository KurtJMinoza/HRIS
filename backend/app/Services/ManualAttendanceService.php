<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\ManualAttendanceRevision;
use App\Models\Overtime;
use App\Models\User;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ManualAttendanceService
{
    public const SOURCE_TYPE = AttendanceCorrection::SOURCE_ADMIN_MANUAL;

    public const REASON_CODES = [
        'biometric_device_failure' => 'Biometric device failure',
        'kiosk_unavailable' => 'Kiosk unavailable',
        'network_outage' => 'Network outage',
        'missed_clock_in' => 'Missed clock-in',
        'missed_clock_out' => 'Missed clock-out',
        'approved_off_site_duty' => 'Approved off-site duty',
        'attendance_migration' => 'Attendance migration',
        'administrative_correction' => 'Administrative correction',
        'emergency' => 'Emergency',
        'other' => 'Other',
    ];

    public const CHANGE_CREATED = 'created';

    public const CHANGE_MISSING_LOG_COMPLETED = 'missing_log_completed';

    public const CHANGE_REPLACED = 'replaced';

    public const CHANGE_SEGMENT_ADDED = 'segment_added';

    public const CHANGE_EDITED = 'edited';

    public const CHANGE_REVERSED = 'reversed';

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly PayrollFreezeService $payrollFreezeService,
        private readonly HolidayService $holidayService,
        private readonly AttendanceSessionService $attendanceSessionService,
        private readonly AttendanceDailySummaryService $attendanceDailySummaryService,
        private readonly ScheduleComputationService $scheduleComputationService,
        private readonly PresenceFilingAttendanceLogSyncService $attendanceLogSyncService,
        private readonly OvertimeService $overtimeService,
        private readonly RbacService $rbacService,
        private readonly PayrollDailyRecordSyncService $payrollDailyRecordSyncService,
    ) {}

    public function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveContext(User $employee, string $dateKey, ?User $actor = null): array
    {
        $tz = $this->attendanceTimezone();
        $dateCarbon = Carbon::parse($dateKey, $tz)->startOfDay();
        $dateKey = $dateCarbon->toDateString();

        if ($actor) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        }

        $employee->loadMissing(['workingSchedule', 'company', 'branch', 'departmentRelation']);
        $schedule = EmployeeScheduleResolver::resolveForDate($employee, $dateKey);
        $dayKey = self::DAY_KEYS[(int) $dateCarbon->format('w')];
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;
        $hasSchedule = is_array($daySchedule) && ! empty($daySchedule['in']);
        $isRestDay = is_array($schedule) && ! $hasSchedule;

        $holiday = $this->holidayService->getEffectiveHolidayForEmployee($employee, $dateKey);
        $leave = $this->resolveApprovedLeaveForDate($employee, $dateKey);
        $existing = $this->resolveExistingAttendance($employee, $dateKey, $tz);
        $existingManual = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->adminManual()
            ->whereNull('reversed_at')
            ->first();

        $approvedOt = Overtime::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->where('status', Overtime::STATUS_APPROVED)
            ->first();

        $payrollFrozen = $this->payrollFreezeService->isPayrollLocked((int) $employee->id, $dateCarbon);

        $shiftType = is_array($daySchedule) ? ($daySchedule['shift_type'] ?? 'fixed') : null;
        $flexOptions = is_array($daySchedule) ? ($daySchedule['flexible_shift_options'] ?? []) : [];

        return [
            'employee_id' => (int) $employee->id,
            'date' => $dateKey,
            'schedule' => $this->formatScheduleContext($daySchedule, $dateKey, $isRestDay, $shiftType, $flexOptions),
            'holiday' => $holiday ? [
                'name' => $holiday['name'] ?? null,
                'type' => $holiday['type'] ?? null,
            ] : null,
            'leave' => $leave ? $this->formatLeaveContext($leave) : null,
            'existing_attendance' => $existing,
            'existing_manual' => $existingManual ? $this->formatRecord($existingManual) : null,
            'approved_overtime' => $approvedOt ? [
                'id' => (int) $approvedOt->id,
                'expected_end_time' => $approvedOt->expected_end_time?->format('H:i'),
                'computed_hours' => (float) ($approvedOt->computed_hours ?? 0),
            ] : null,
            'payroll_status' => $payrollFrozen ? 'Locked' : 'Open',
            'payroll_locked' => $payrollFrozen,
            'employment' => [
                'hire_date' => $employee->hire_date?->toDateString(),
                'is_active' => (bool) $employee->is_active,
            ],
            'conflicts' => $this->detectConflicts($employee, $dateKey, $leave, $existing, $hasSchedule, $payrollFrozen),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(array $payload, User $admin): array
    {
        [$employee, $dateKey, $segments, $shiftMatchMode, $scheduleOptionId] = $this->parsePayload($payload, $admin, requireSegments: false);
        $context = $this->resolveContext($employee, $dateKey, $admin);

        if ($segments === []) {
            return [
                'context' => $context,
                'preview' => null,
            ];
        }

        $this->validateSegments($segments, $dateKey);
        [$timeIn, $timeOut] = $this->segmentBounds($segments);

        $preview = $this->computePreview($employee, $dateKey, $timeIn, $timeOut, $shiftMatchMode, $scheduleOptionId);

        return [
            'context' => $context,
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(array $payload, User $admin, bool $deferSideEffects = false): AttendanceCorrection
    {
        if (! $this->rbacService->can($admin, 'attendance.manual.create')) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to create manual attendance.']]);
        }

        [$employee, $dateKey, $segments, $shiftMatchMode, $scheduleOptionId] = $this->parsePayload($payload, $admin);
        $this->assertPayrollMutable($employee, $dateKey);
        $this->validateReason($payload);
        $this->validateSegments($segments, $dateKey);

        $action = (string) ($payload['conflict_action'] ?? 'create');
        $overrideLeave = (bool) ($payload['override_leave'] ?? false);
        $partialDay = (bool) ($payload['partial_day'] ?? false);

        $context = $this->resolveContext($employee, $dateKey, $admin);
        $this->assertConflictPolicy($context, $action, $overrideLeave, $partialDay, $admin);

        [$timeIn, $timeOut] = $this->segmentBounds($segments);
        $existing = AttendanceCorrection::query()
            ->where('user_id', $employee->id)
            ->whereDate('date', $dateKey)
            ->adminManual()
            ->whereNull('reversed_at')
            ->first();

        $changeType = self::CHANGE_CREATED;
        $previousSnapshot = null;

        if ($existing) {
            $previousSnapshot = $this->snapshot($existing);
            if ($action === 'complete_missing') {
                $timeIn = $timeIn ?? ($existing->time_in?->copy()->timezone($this->attendanceTimezone()));
                $timeOut = $timeOut ?? ($existing->time_out?->copy()->timezone($this->attendanceTimezone()));
                $segments = $this->buildSegmentsFromBounds($timeIn, $timeOut);
                $changeType = self::CHANGE_MISSING_LOG_COMPLETED;
            } elseif ($action === 'add_segment') {
                $merged = array_merge($existing->work_segments ?? $this->segmentsFromRecord($existing), $segments);
                $this->validateSegments($merged, $dateKey);
                $segments = $merged;
                [$timeIn, $timeOut] = $this->segmentBounds($segments);
                $changeType = self::CHANGE_SEGMENT_ADDED;
            } elseif ($action === 'replace') {
                $changeType = self::CHANGE_REPLACED;
            } else {
                throw ValidationException::withMessages([
                    'conflict_action' => ['Existing manual attendance found. Choose complete_missing, replace, or add_segment.'],
                ]);
            }
        }

        return $this->persistManualRecord(
            $employee,
            $dateKey,
            $timeIn,
            $timeOut,
            $segments,
            $payload,
            $admin,
            $existing,
            $previousSnapshot,
            $changeType,
            $shiftMatchMode,
            $scheduleOptionId,
            $deferSideEffects,
        );
    }

    /**
     * One employee, many dates — single request path for the Add Manual Attendance calendar multi-select.
     *
     * @param  array<string, mixed>  $payload
     * @return array{saved:int, failed:list<array{date:string,message:string}>, created_ids:list<int>, employee_id:int}
     */
    public function storeManyDates(array $payload, User $admin): array
    {
        if (! $this->rbacService->can($admin, 'attendance.manual.create')) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to create manual attendance.']]);
        }

        $rawDates = $payload['dates'] ?? [];
        if (! is_array($rawDates) || $rawDates === []) {
            throw ValidationException::withMessages(['dates' => ['Select at least one date.']]);
        }

        $tz = $this->attendanceTimezone();
        $dates = [];
        foreach ($rawDates as $raw) {
            $key = Carbon::parse((string) $raw, $tz)->toDateString();
            $dates[$key] = $key;
        }
        $dates = array_values($dates);
        sort($dates);

        if (count($dates) > 62) {
            throw ValidationException::withMessages(['dates' => ['You can save at most 62 days at once.']]);
        }

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $employee = $this->loadEmployee($employeeId);
        $this->dataScopeService->ensureEmployeeAccessible($admin, $employee);

        $createdIds = [];
        $failed = [];
        $touched = [];

        // Multi-day fills: treat bare "create" as replace so days with existing attendance still save.
        if (count($dates) > 1 && (($payload['conflict_action'] ?? 'create') === 'create')) {
            $payload['conflict_action'] = 'replace';
        }

        foreach ($dates as $dateKey) {
            try {
                $dayPayload = array_merge($payload, [
                    'employee_id' => $employeeId,
                    'date' => $dateKey,
                ]);
                unset($dayPayload['dates']);
                $record = $this->store($dayPayload, $admin, deferSideEffects: true);
                $createdIds[] = (int) $record->id;
                $touched[] = ['date' => $dateKey, 'record' => $record];
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->filter()->values()->all();
                $failed[] = [
                    'date' => $dateKey,
                    'message' => $messages[0] ?? $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'date' => $dateKey,
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($touched !== []) {
            $this->afterMutationBatch($employee, $touched, $admin);
        }

        if ($createdIds === [] && $failed !== []) {
            throw ValidationException::withMessages([
                'dates' => [$failed[0]['message'] ?? 'Save failed for all selected dates.'],
            ]);
        }

        return [
            'employee_id' => $employeeId,
            'saved' => count($createdIds),
            'failed' => $failed,
            'created_ids' => $createdIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(int $id, array $payload, User $admin): AttendanceCorrection
    {
        if (! $this->rbacService->can($admin, 'attendance.manual.edit')) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to edit manual attendance.']]);
        }

        $record = $this->findManualRecord($id);
        $employee = $this->loadEmployee((int) $record->user_id);
        $this->dataScopeService->ensureEmployeeAccessible($admin, $employee);
        $dateKey = $record->date?->toDateString() ?? '';
        $this->assertPayrollMutable($employee, $dateKey);

        if ($record->isReversed()) {
            throw ValidationException::withMessages(['id' => ['This manual attendance record has been reversed.']]);
        }

        $this->validateReason($payload, requireEditReason: true);
        [$employee, $dateKey, $segments, $shiftMatchMode, $scheduleOptionId] = $this->parsePayload(
            array_merge($payload, [
                'employee_id' => $employee->id,
                'date' => $dateKey,
            ]),
            $admin,
        );
        $this->validateSegments($segments, $dateKey);
        [$timeIn, $timeOut] = $this->segmentBounds($segments);
        $previousSnapshot = $this->snapshot($record);

        return $this->persistManualRecord(
            $employee,
            $dateKey,
            $timeIn,
            $timeOut,
            $segments,
            $payload,
            $admin,
            $record,
            $previousSnapshot,
            self::CHANGE_EDITED,
            $shiftMatchMode,
            $scheduleOptionId,
        );
    }

    public function reverse(int $id, string $reason, User $admin): AttendanceCorrection
    {
        if (! $this->rbacService->can($admin, 'attendance.manual.reverse')) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to reverse manual attendance.']]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reversal_reason' => ['Reversal reason is required.']]);
        }

        $record = $this->findManualRecord($id);
        $employee = $this->loadEmployee((int) $record->user_id);
        $this->dataScopeService->ensureEmployeeAccessible($admin, $employee);
        $dateKey = $record->date?->toDateString() ?? '';
        $this->assertPayrollMutable($employee, $dateKey);

        if ($record->isReversed()) {
            throw ValidationException::withMessages(['id' => ['This record is already reversed.']]);
        }

        $previousSnapshot = $this->snapshot($record);
        $restored = $this->findRestoredState($record);

        return DB::transaction(function () use ($record, $employee, $dateKey, $reason, $admin, $previousSnapshot, $restored) {
            $record->reversed_at = now();
            $record->reversed_by_admin_id = $admin->id;
            $record->reversal_reason = $reason;
            $record->approved = false;
            $record->save();

            $this->writeRevision($record, $previousSnapshot, $this->snapshot($record), self::CHANGE_REVERSED, $reason, $admin);

            if ($restored) {
                AttendanceCorrection::updateOrCreate(
                    ['user_id' => $employee->id, 'date' => $dateKey],
                    array_merge($restored, [
                        'source_type' => null,
                        'is_manual' => false,
                    ])
                );
            } else {
                AttendanceLog::query()
                    ->where('user_id', $employee->id)
                    ->whereBetween('verified_at', [
                        Carbon::parse($dateKey, $this->attendanceTimezone())->startOfDay()->utc(),
                        Carbon::parse($dateKey, $this->attendanceTimezone())->endOfDay()->utc(),
                    ])
                    ->where('authentication_method', AttendanceLog::AUTH_METHOD_ADMIN_MANUAL)
                    ->delete();
            }

            $this->afterMutation($employee, $dateKey, $admin, $record);

            return $record->fresh(['user', 'createdByAdmin']);
        });
    }

    /**
     * @return array{data: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function paginateList(User $admin, array $filters): array
    {
        if (! $this->rbacService->canAny($admin, ['attendance.manual.view', 'attendance.manual.create', 'attendance.manual.edit'])) {
            throw ValidationException::withMessages(['permission' => ['You are not allowed to view manual attendance.']]);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $query = AttendanceCorrection::query()
            ->adminManual()
            ->with(['user.company', 'user.branch', 'user.departmentRelation', 'createdByAdmin'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $scopedIds = $this->dataScopeService->getScopedEmployeeIdsForUser($admin, 'attendance');
        if ($scopedIds !== null) {
            $query->whereIn('user_id', $scopedIds);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('user_id', (int) $filters['employee_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('date', '<=', $filters['to_date']);
        }
        if (($filters['status'] ?? '') === 'reversed') {
            $query->whereNotNull('reversed_at');
        } elseif (($filters['status'] ?? '') === 'active') {
            $query->whereNull('reversed_at');
        }

        $paginator = $query->paginate($perPage);
        $items = collect($paginator->items())->map(fn (AttendanceCorrection $r) => $this->formatListRow($r))->values()->all();

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $id, User $admin): array
    {
        $record = $this->findManualRecord($id);
        $employee = $this->loadEmployee((int) $record->user_id);
        $this->dataScopeService->ensureEmployeeAccessible($admin, $employee);

        return $record->manualRevisions()
            ->with('changedByUser')
            ->get()
            ->map(fn (ManualAttendanceRevision $rev) => [
                'id' => (int) $rev->id,
                'change_type' => $rev->change_type,
                'reason' => $rev->reason,
                'previous_values' => $rev->previous_values,
                'new_values' => $rev->new_values,
                'changed_by' => $rev->changedByUser?->name,
                'changed_at' => $rev->changed_at?->toIso8601String(),
            ])
            ->all();
    }

    public function findManualRecord(int $id): AttendanceCorrection
    {
        return AttendanceCorrection::query()->adminManual()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: User, 1: string, 2: list<array{time_in: Carbon, time_out: Carbon}>, 3: string, 4: ?int}
     */
    private function parsePayload(array $payload, User $admin, bool $requireSegments = true): array
    {
        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $dateKey = (string) ($payload['date'] ?? '');
        if ($employeeId <= 0 || $dateKey === '') {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee is required.'],
                'date' => ['Attendance date is required.'],
            ]);
        }

        $employee = $this->loadEmployee($employeeId);
        $this->dataScopeService->ensureEmployeeAccessible($admin, $employee);

        $tz = $this->attendanceTimezone();
        $dateKey = Carbon::parse($dateKey, $tz)->toDateString();
        $shiftMatchMode = (string) ($payload['shift_match_mode'] ?? 'auto');
        $scheduleOptionId = isset($payload['schedule_option_id']) ? (int) $payload['schedule_option_id'] : null;

        $segments = $this->parseSegments($payload['segments'] ?? [], $dateKey, $tz);
        if ($requireSegments && $segments === []) {
            $timeInStr = trim((string) ($payload['time_in'] ?? ''));
            $timeOutStr = trim((string) ($payload['time_out'] ?? ''));
            if ($timeInStr !== '' || $timeOutStr !== '') {
                $segments = $this->parseSegments([[
                    'time_in' => $timeInStr,
                    'time_out' => $timeOutStr,
                ]], $dateKey, $tz);
            }
        }

        if ($requireSegments && $segments === []) {
            throw ValidationException::withMessages(['segments' => ['At least one work segment is required.']]);
        }

        return [$employee, $dateKey, $segments, $shiftMatchMode, $scheduleOptionId];
    }

    /**
     * @param  list<array<string, mixed>>  $rawSegments
     * @return list<array{time_in: Carbon, time_out: Carbon}>
     */
    private function parseSegments(array $rawSegments, string $dateKey, string $tz): array
    {
        $segments = [];
        foreach ($rawSegments as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $inStr = trim((string) ($raw['time_in'] ?? ''));
            $outStr = trim((string) ($raw['time_out'] ?? ''));
            if ($inStr === '' && $outStr === '') {
                continue;
            }
            if ($inStr === '' || $outStr === '') {
                throw ValidationException::withMessages(['segments' => ['Each segment requires both time in and time out.']]);
            }
            $in = Carbon::parse($dateKey.' '.substr($inStr, 0, 5), $tz);
            $out = Carbon::parse($dateKey.' '.substr($outStr, 0, 5), $tz);
            if ($out->lessThanOrEqualTo($in)) {
                $out = $out->copy()->addDay();
            }
            $segments[] = ['time_in' => $in, 'time_out' => $out];
        }

        usort($segments, fn ($a, $b) => $a['time_in']->timestamp <=> $b['time_in']->timestamp);

        return $segments;
    }

    /**
     * @param  list<array{time_in: Carbon, time_out: Carbon}>  $segments
     */
    private function validateSegments(array $segments, string $dateKey): void
    {
        if ($segments === []) {
            return;
        }

        $tz = $this->attendanceTimezone();
        $dayStart = Carbon::parse($dateKey, $tz)->startOfDay();
        $dayEnd = Carbon::parse($dateKey, $tz)->endOfDay()->addDay();

        for ($i = 0; $i < count($segments); $i++) {
            $seg = $segments[$i];
            if ($seg['time_out']->lessThanOrEqualTo($seg['time_in'])) {
                throw ValidationException::withMessages(['segments' => ['Time out must be after time in for each segment.']]);
            }
            if ($seg['time_in']->lt($dayStart) || $seg['time_in']->gt($dayEnd)) {
                throw ValidationException::withMessages(['segments' => ['Logs must belong to the selected attendance work date.']]);
            }
            if ($i > 0 && $seg['time_in']->lt($segments[$i - 1]['time_out'])) {
                throw ValidationException::withMessages(['segments' => ['Work segments must not overlap.']]);
            }
        }
    }

    /**
     * @param  list<array{time_in: Carbon, time_out: Carbon}>  $segments
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function segmentBounds(array $segments): array
    {
        if ($segments === []) {
            return [null, null];
        }

        return [
            $segments[0]['time_in']->copy(),
            $segments[array_key_last($segments)]['time_out']->copy(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateReason(array $payload, bool $requireEditReason = false): void
    {
        $code = trim((string) ($payload['reason_code'] ?? ''));
        if ($code === '' || ! array_key_exists($code, self::REASON_CODES)) {
            throw ValidationException::withMessages(['reason_code' => ['A valid reason is required.']]);
        }

        $remarks = trim((string) ($payload['manual_remarks'] ?? $payload['remarks'] ?? ''));
        if ($code === 'other' && $remarks === '') {
            throw ValidationException::withMessages(['manual_remarks' => ['Remarks are required when reason is Other.']]);
        }

        if ($requireEditReason && trim((string) ($payload['edit_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['edit_reason' => ['Edit reason is required.']]);
        }
    }

    private function assertPayrollMutable(User $employee, string $dateKey): void
    {
        $dateCarbon = Carbon::parse($dateKey, $this->attendanceTimezone())->startOfDay();
        try {
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $employee->id, $dateCarbon, $dateCarbon);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['date' => [$e->getMessage()]]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function assertConflictPolicy(array $context, string $action, bool $overrideLeave, bool $partialDay, User $admin): void
    {
        $leave = $context['leave'] ?? null;
        if (is_array($leave) && ($leave['is_full_day'] ?? false) && ! $overrideLeave && ! $partialDay) {
            if (! $this->rbacService->can($admin, 'attendance.manual.override_conflict')) {
                throw ValidationException::withMessages([
                    'leave' => ['Employee has approved full-day leave on this date. Override permission required.'],
                ]);
            }
        }

        $existingManual = $context['existing_manual'] ?? null;
        if ($existingManual && $action === 'create') {
            throw ValidationException::withMessages([
                'conflict_action' => ['Existing manual attendance found. Choose an action.'],
            ]);
        }
    }

    /**
     * @param  list<array{time_in: Carbon, time_out: Carbon}>  $segments
     * @param  array<string, mixed>  $payload
     */
    private function persistManualRecord(
        User $employee,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        array $segments,
        array $payload,
        User $admin,
        ?AttendanceCorrection $existing,
        ?array $previousSnapshot,
        string $changeType,
        string $shiftMatchMode,
        ?int $scheduleOptionId,
        bool $deferSideEffects = false,
    ): AttendanceCorrection {
        $reasonLabel = self::REASON_CODES[$payload['reason_code']] ?? $payload['reason_code'];
        $manualRemarks = trim((string) ($payload['manual_remarks'] ?? ''));
        $editReason = trim((string) ($payload['edit_reason'] ?? ''));
        $remarks = $manualRemarks !== '' ? $manualRemarks : $reasonLabel;
        if ($editReason !== '') {
            $remarks = $editReason.($remarks !== '' ? "\n\n".$remarks : '');
        }

        $now = now();
        $workSegmentsJson = array_map(fn ($s) => [
            'time_in' => $s['time_in']->toIso8601String(),
            'time_out' => $s['time_out']->toIso8601String(),
        ], $segments);

        return DB::transaction(function () use (
            $employee,
            $dateKey,
            $timeIn,
            $timeOut,
            $workSegmentsJson,
            $payload,
            $admin,
            $existing,
            $previousSnapshot,
            $changeType,
            $shiftMatchMode,
            $scheduleOptionId,
            $remarks,
            $reasonLabel,
            $now,
            $deferSideEffects,
        ) {
            $record = AttendanceCorrection::updateOrCreate(
                ['user_id' => $employee->id, 'date' => $dateKey],
                [
                    'time_in' => $timeIn?->copy()->setTimezone('UTC'),
                    'time_out' => $timeOut?->copy()->setTimezone('UTC'),
                    'work_segments' => $workSegmentsJson,
                    'matched_schedule_option_id' => $shiftMatchMode === 'explicit' ? $scheduleOptionId : null,
                    'shift_match_mode' => $shiftMatchMode,
                    'remarks' => "[Admin Manual: {$reasonLabel}] ".$remarks,
                    'manual_remarks' => trim((string) ($payload['manual_remarks'] ?? '')),
                    'manual_reason_code' => $payload['reason_code'],
                    'source_type' => self::SOURCE_TYPE,
                    'is_manual' => true,
                    'issue_kind' => 'both',
                    'pending_approval' => false,
                    'status' => 'approved',
                    'approved' => true,
                    'approved_by' => $admin->id,
                    'approved_by_admin_id' => $admin->id,
                    'approved_at' => $now,
                    'created_by_admin_id' => $existing?->created_by_admin_id ?? $admin->id,
                    'filed_by' => $admin->id,
                    'filed_at' => $existing?->filed_at ?? $now,
                    'reversed_at' => null,
                    'reversed_by_admin_id' => null,
                    'reversal_reason' => null,
                    'rejected_at' => null,
                    'approval_stage' => null,
                    'first_approver_id' => null,
                    'second_approver_id' => null,
                ]
            );

            $this->writeRevision(
                $record,
                $previousSnapshot,
                $this->snapshot($record),
                $changeType,
                $remarks,
                $admin,
            );

            if ($timeIn && $timeOut) {
                $this->attendanceLogSyncService->syncApprovedCorrectionToLogs(
                    $employee,
                    $dateKey,
                    $record->time_in,
                    $record->time_out,
                    $admin,
                    (int) $record->id,
                    'Admin Manual Attendance',
                    'both',
                    null,
                    true,
                    AttendanceLog::AUTH_METHOD_ADMIN_MANUAL,
                );
                $record->attendance_logs_synced_at = now();
                $record->attendance_logs_synced_by = $admin->id;
                $record->save();
            }

            if (! $deferSideEffects) {
                $this->afterMutation($employee, $dateKey, $admin, $record);
            }

            return $record->fresh(['user', 'createdByAdmin']);
        });
    }

    private function afterMutation(User $employee, string $dateKey, User $admin, AttendanceCorrection $record): void
    {
        $this->attendanceSessionService->flushRuntimeCache();
        $this->overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $record->time_out, $admin);
        // ponytail: sync only this employee. ProcessDailyPayrollJob::dispatchSync rebuilds ALL employees for the day (10–20s×N).
        $this->payrollDailyRecordSyncService->syncDayForUser($employee, $dateKey);
        \App\Services\AdminAttendanceCacheService::invalidateAffected(
            (int) $employee->id,
            $dateKey,
            (int) ($employee->company_id ?? 0) ?: null,
            (int) ($employee->branch_id ?? 0) ?: null,
        );
        AttendanceCorrectionModuleCache::flushAfterMutation(
            $admin,
            (int) ($employee->company_id ?? 0) ?: null,
            (int) $record->id,
        );
    }

    /**
     * @param  list<array{date:string,record:AttendanceCorrection}>  $touched
     */
    private function afterMutationBatch(User $employee, array $touched, User $admin): void
    {
        $this->attendanceSessionService->flushRuntimeCache();
        $lastRecordId = 0;
        foreach ($touched as $item) {
            $dateKey = $item['date'];
            $record = $item['record'];
            $this->overtimeService->syncActualClockOutToFiledOvertime($employee, $dateKey, $record->time_out, $admin);
            $this->payrollDailyRecordSyncService->syncDayForUser($employee, $dateKey);
            \App\Services\AdminAttendanceCacheService::invalidateAffected(
                (int) $employee->id,
                $dateKey,
                (int) ($employee->company_id ?? 0) ?: null,
                (int) ($employee->branch_id ?? 0) ?: null,
            );
            $lastRecordId = (int) $record->id;
        }
        AttendanceCorrectionModuleCache::flushAfterMutation(
            $admin,
            (int) ($employee->company_id ?? 0) ?: null,
            $lastRecordId ?: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computePreview(
        User $employee,
        string $dateKey,
        ?Carbon $timeIn,
        ?Carbon $timeOut,
        string $shiftMatchMode,
        ?int $scheduleOptionId,
    ): array {
        $tz = $this->attendanceTimezone();
        $today = Carbon::now($tz)->toDateString();
        $schedule = EmployeeScheduleResolver::resolveForDate($employee, $dateKey);
        $dayKey = self::DAY_KEYS[(int) Carbon::parse($dateKey)->format('w')];
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;

        if (is_array($daySchedule) && $shiftMatchMode === 'explicit' && $scheduleOptionId) {
            $daySchedule['explicit_schedule_option_id'] = $scheduleOptionId;
        }

        $previewCorrection = new AttendanceCorrection([
            'user_id' => $employee->id,
            'date' => $dateKey,
            'time_in' => $timeIn?->copy()->setTimezone('UTC'),
            'time_out' => $timeOut?->copy()->setTimezone('UTC'),
            'approved' => true,
            'pending_approval' => false,
        ]);

        $holiday = $this->holidayService->getEffectiveHolidayForEmployee($employee, $dateKey);
        $leave = $this->resolveApprovedLeaveForDate($employee, $dateKey);

        $summary = $this->attendanceDailySummaryService->computeForDate(
            $employee,
            $dateKey,
            $today,
            Carbon::now($tz),
            $schedule,
            null,
            $previewCorrection,
            $leave,
            $holiday,
        );

        $resolvedShift = null;
        if ($timeIn && $timeOut && is_array($daySchedule)) {
            $resolved = $this->scheduleComputationService->resolveFlexibleShiftForAttendance(
                $dateKey,
                $daySchedule,
                $timeIn,
                $timeOut,
                $tz,
            );
            $resolvedSchedule = $resolved['schedule'] ?? $daySchedule;
            $resolvedShift = [
                'in' => $resolvedSchedule['in'] ?? null,
                'out' => $resolvedSchedule['out'] ?? null,
                'matched_option_id' => $resolved['metadata']['matched_schedule_option_id'] ?? null,
                'matched_option_name' => $resolved['metadata']['matched_schedule_option_name'] ?? null,
            ];
        }

        return [
            'resolved_shift' => $resolvedShift,
            'time_in' => $timeIn?->format('g:i A'),
            'time_out' => $timeOut?->format('g:i A'),
            'status' => $summary['status'] ?? null,
            'status_label' => $summary['presence_label'] ?? $summary['status'] ?? null,
            'late_minutes' => $summary['late_minutes'] ?? null,
            'late_label' => $summary['late_label'] ?? null,
            'undertime_minutes' => $summary['undertime_minutes'] ?? null,
            'total_hours' => $summary['total_rendered_hours'] ?? $summary['worked_hours'] ?? null,
            'payroll_impact_hours' => $summary['payroll_impact_hours'] ?? null,
            'potential_ot_hours' => $summary['unapproved_overtime_hours'] ?? null,
            'holiday_context' => $summary['holiday_name'] ?? null,
            'rest_day_context' => ! empty($summary['is_rest_day']) ? 'Rest Day Worked' : null,
        ];
    }

    private function loadEmployee(int $employeeId): User
    {
        return User::query()
            ->whereKey($employeeId)
            ->attendanceEmployees()
            ->active()
            ->with('workingSchedule')
            ->firstOrFail();
    }

    private function resolveApprovedLeaveForDate(User $employee, string $dateKey): ?LeaveRequest
    {
        return LeaveRequest::query()
            ->where('user_id', $employee->id)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExistingAttendance(User $employee, string $dateKey, string $tz): array
    {
        [$timeIn, $timeOut] = $this->attendanceSessionService->getTimesForDate($employee, $dateKey, $tz);
        $hasIn = $timeIn !== null;
        $hasOut = $timeOut !== null;

        $status = 'None';
        if ($hasIn && $hasOut) {
            $status = 'Present';
        } elseif ($hasIn xor $hasOut) {
            $status = $hasIn ? 'Missing Time Out' : 'Missing Time In';
        }

        return [
            'time_in' => $timeIn?->format('g:i A'),
            'time_out' => $timeOut?->format('g:i A'),
            'status' => $status === 'None' ? null : $status,
            'has_complete_pair' => $hasIn && $hasOut,
            'has_partial' => ($hasIn xor $hasOut),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $daySchedule
     * @param  list<array<string, mixed>>  $flexOptions
     * @return array<string, mixed>
     */
    private function formatScheduleContext(?array $daySchedule, string $dateKey, bool $isRestDay, ?string $shiftType, array $flexOptions): array
    {
        if ($isRestDay || ! is_array($daySchedule) || empty($daySchedule['in'])) {
            return [
                'label' => 'Rest Day',
                'day_type' => 'Rest Day',
                'shift' => null,
                'break' => null,
                'schedule_type' => $shiftType ?? 'fixed',
                'flexible_options' => [],
            ];
        }

        $break = null;
        if (! empty($daySchedule['break_start']) && ! empty($daySchedule['break_end'])) {
            $break = $this->formatTime($daySchedule['break_start']).'–'.$this->formatTime($daySchedule['break_end']);
        }

        $options = [];
        if ($shiftType === 'flexible' && $flexOptions !== []) {
            foreach ($flexOptions as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $options[] = [
                    'id' => $opt['id'] ?? $opt['matched_schedule_option_id'] ?? null,
                    'label' => $this->formatTime($opt['in'] ?? '').'–'.$this->formatTime($opt['out'] ?? ''),
                ];
            }
        }

        return [
            'label' => ($shiftType === 'flexible' ? 'Flexible' : 'Regular Day Shift'),
            'day_type' => 'Regular Workday',
            'shift' => $this->formatTime($daySchedule['in']).'–'.$this->formatTime($daySchedule['out']),
            'break' => $break ?? 'None',
            'schedule_type' => $shiftType ?? 'fixed',
            'flexible_options' => $options,
            'resolved_day' => Carbon::parse($dateKey)->format('l'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLeaveContext(LeaveRequest $leave): array
    {
        $isFullDay = ! in_array($leave->type, ['half_day', 'undertime'], true);

        return [
            'type' => $leave->type,
            'label' => ucfirst(str_replace('_', ' ', (string) $leave->type)),
            'is_full_day' => $isFullDay,
            'half_type' => $leave->half_type,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function detectConflicts(User $employee, string $dateKey, ?LeaveRequest $leave, array $existing, bool $hasSchedule, bool $payrollFrozen): array
    {
        $conflicts = [];
        if ($leave && ! in_array($leave->type, ['half_day', 'undertime'], true)) {
            $conflicts[] = ['type' => 'approved_leave', 'message' => 'Approved full-day leave on this date.'];
        }
        if (! empty($existing['has_complete_pair'])) {
            $conflicts[] = ['type' => 'existing_attendance', 'message' => 'Existing attendance record found.'];
        }
        if (! $hasSchedule) {
            $conflicts[] = ['type' => 'rest_day', 'message' => 'Selected date is a rest day.'];
        }
        if ($payrollFrozen) {
            $conflicts[] = ['type' => 'payroll_locked', 'message' => PayrollFreezeService::LOCK_MESSAGE];
        }
        if (! $employee->is_active) {
            $conflicts[] = ['type' => 'inactive', 'message' => 'Employee is inactive.'];
        }

        return $conflicts;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AttendanceCorrection $record): array
    {
        $tz = $this->attendanceTimezone();

        return [
            'time_in' => $record->time_in?->copy()->timezone($tz)->toIso8601String(),
            'time_out' => $record->time_out?->copy()->timezone($tz)->toIso8601String(),
            'work_segments' => $record->work_segments,
            'manual_reason_code' => $record->manual_reason_code,
            'manual_remarks' => $record->manual_remarks,
            'reversed_at' => $record->reversed_at?->toIso8601String(),
        ];
    }

    private function writeRevision(
        AttendanceCorrection $record,
        ?array $previous,
        array $current,
        string $changeType,
        string $reason,
        User $admin,
    ): void {
        ManualAttendanceRevision::create([
            'attendance_record_id' => $record->id,
            'previous_values' => $previous,
            'new_values' => $current,
            'change_type' => $changeType,
            'reason' => $reason,
            'changed_by' => $admin->id,
            'changed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRestoredState(AttendanceCorrection $record): ?array
    {
        $createdRevision = $record->manualRevisions()
            ->where('change_type', self::CHANGE_CREATED)
            ->orderBy('changed_at')
            ->first();

        if (! $createdRevision || ! is_array($createdRevision->previous_values)) {
            return null;
        }

        $prev = $createdRevision->previous_values;
        if (empty($prev['time_in']) && empty($prev['time_out'])) {
            return null;
        }

        return [
            'time_in' => isset($prev['time_in']) ? Carbon::parse($prev['time_in'])->utc() : null,
            'time_out' => isset($prev['time_out']) ? Carbon::parse($prev['time_out'])->utc() : null,
            'approved' => true,
            'pending_approval' => false,
        ];
    }

    /**
     * @return list<array{time_in: string, time_out: string}>
     */
    private function segmentsFromRecord(AttendanceCorrection $record): array
    {
        if (is_array($record->work_segments) && $record->work_segments !== []) {
            return array_map(fn ($s) => [
                'time_in' => Carbon::parse($s['time_in'])->format('H:i'),
                'time_out' => Carbon::parse($s['time_out'])->format('H:i'),
            ], $record->work_segments);
        }

        $tz = $this->attendanceTimezone();
        if ($record->time_in && $record->time_out) {
            return [[
                'time_in' => $record->time_in->copy()->timezone($tz)->format('H:i'),
                'time_out' => $record->time_out->copy()->timezone($tz)->format('H:i'),
            ]];
        }

        return [];
    }

    /**
     * @return list<array{time_in: Carbon, time_out: Carbon}>
     */
    private function buildSegmentsFromBounds(?Carbon $timeIn, ?Carbon $timeOut): array
    {
        if (! $timeIn || ! $timeOut) {
            return [];
        }

        return [['time_in' => $timeIn->copy(), 'time_out' => $timeOut->copy()]];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(AttendanceCorrection $record): array
    {
        $tz = $this->attendanceTimezone();
        $preview = null;
        if ($record->time_in && $record->time_out) {
            $preview = $this->computePreview(
                $record->user ?? User::find($record->user_id),
                $record->date?->toDateString() ?? '',
                $record->time_in->copy()->timezone($tz),
                $record->time_out->copy()->timezone($tz),
                (string) ($record->shift_match_mode ?? 'auto'),
                $record->matched_schedule_option_id ? (int) $record->matched_schedule_option_id : null,
            );
        }

        return array_merge($this->formatListRow($record), [
            'work_segments' => $record->work_segments,
            'preview' => $preview,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatListRow(AttendanceCorrection $record): array
    {
        $tz = $this->attendanceTimezone();
        $employee = $record->user;
        $dateKey = $record->date?->toDateString() ?? '';
        $schedule = $employee ? EmployeeScheduleResolver::resolveForDate($employee, $dateKey) : null;
        $dayKey = $dateKey !== '' ? self::DAY_KEYS[(int) Carbon::parse($dateKey)->format('w')] : 'mon';
        $daySchedule = is_array($schedule) ? ($schedule[$dayKey] ?? null) : null;

        $preview = ($record->time_in && $record->time_out && $employee)
            ? $this->computePreview(
                $employee,
                $dateKey,
                $record->time_in->copy()->timezone($tz),
                $record->time_out->copy()->timezone($tz),
                (string) ($record->shift_match_mode ?? 'auto'),
                $record->matched_schedule_option_id ? (int) $record->matched_schedule_option_id : null,
            )
            : null;

        $scheduleLabel = is_array($daySchedule) && ! empty($daySchedule['in'])
            ? $this->formatTime($daySchedule['in']).'–'.$this->formatTime($daySchedule['out'])
            : 'Rest Day';

        return [
            'id' => (int) $record->id,
            'employee_id' => (int) $record->user_id,
            'employee_name' => $employee?->name,
            'employee_number' => $employee?->employee_id,
            'profile_image' => $employee?->profile_image,
            'company_name' => $employee?->company?->name,
            'branch_name' => $employee?->branch?->name,
            'department_name' => $employee?->departmentRelation?->name ?? $employee?->department,
            'date' => $dateKey,
            'resolved_schedule' => $scheduleLabel,
            'time_in' => $record->time_in?->copy()->timezone($tz)->format('g:i A'),
            'time_out' => $record->time_out?->copy()->timezone($tz)->format('g:i A'),
            'computed_status' => $preview['status_label'] ?? ($record->isReversed() ? 'Reversed' : '—'),
            'total_hours' => $preview['total_hours'] ?? null,
            'payroll_impact_hours' => $preview['payroll_impact_hours'] ?? null,
            'reason_code' => $record->manual_reason_code,
            'reason_label' => self::REASON_CODES[$record->manual_reason_code ?? ''] ?? $record->manual_reason_code,
            'manual_remarks' => $record->manual_remarks,
            'entered_by' => $record->createdByAdmin?->name,
            'entered_at' => $record->created_at?->toIso8601String(),
            'approved_at' => $record->approved_at?->toIso8601String(),
            'is_reversed' => $record->isReversed(),
            'reversed_at' => $record->reversed_at?->toIso8601String(),
            'source_type' => self::SOURCE_TYPE,
            'source_badge' => 'Admin Manual',
        ];
    }

    private function formatTime(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $v = substr(trim($value), 0, 5);

        return Carbon::parse('2000-01-01 '.$v)->format('g:i A');
    }
}
