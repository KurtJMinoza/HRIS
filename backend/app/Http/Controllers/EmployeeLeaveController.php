<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\LeaveApprovalAudit;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use App\Services\EmployeeOrganizationAssignmentService;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\LeaveApprovalService;
use App\Services\LeaveCreditService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\LeaveFilingRules;
use App\Support\LeaveScheduleSupport;
use App\Support\RequestPerformanceLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EmployeeLeaveController extends Controller
{
    public function __construct(
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly LeaveApprovalService $leaveApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly EmployeeOrganizationAssignmentService $organizationAssignments,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    private function mergeLeaveRemarksIntoProgress(LeaveRequest $leave, array $steps): array
    {
        if (! $leave->relationLoaded('approvalAudits')) {
            return $steps;
        }

        $audits = $leave->approvalAudits;
        foreach ($steps as $i => $step) {
            $key = $step['key'] ?? '';
            $remarks = null;
            if ($key === 'submitted') {
                $a = $audits->firstWhere('action', 'file');
                $remarks = $a?->details;
            } elseif ($key === 'line_approval') {
                $a = $audits->firstWhere('action', 'approve_first');
                $remarks = $a?->details;
            } elseif ($key === 'hr_final') {
                $a = $audits->firstWhere('action', 'approve_final') ?? $audits->firstWhere('action', 'reject');
                $remarks = $a?->details;
            }
            if ($remarks !== null) {
                $steps[$i]['remarks'] = $remarks;
            }
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentFieldsForLeave(LeaveRequest $l): array
    {
        $paths = $l->resolveDocumentPaths();
        $urls = array_map(static fn (string $p) => Storage::url($p), $paths);

        return [
            'has_document' => count($paths) > 0,
            'document_url' => $urls[0] ?? null,
            'document_urls' => $urls,
            'document_count' => count($paths),
        ];
    }

    /**
     * Merge legacy single path into `document_paths` and clear `document_path` so new uploads append consistently.
     */
    private function normalizeLeaveDocuments(LeaveRequest $leave): void
    {
        $paths = $leave->document_paths ?? [];
        if (! is_array($paths)) {
            $paths = [];
        }
        $paths = array_values(array_filter($paths, static fn ($p) => is_string($p) && $p !== ''));
        if (! empty($leave->document_path) && is_string($leave->document_path)) {
            if (! in_array($leave->document_path, $paths, true)) {
                array_unshift($paths, $leave->document_path);
            }
            $leave->document_path = null;
        }
        $leave->document_paths = $paths;
    }

    private function mapEmployeeLeaveRow(LeaveRequest $l): array
    {
        $l->loadMissing([
            'user:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,department,branch_id,company_id,section_unit_id,division_id,supervisor_id,assigned_team_leader_id',
            'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,branch_id,company_id,section_unit_id,division_id',
            'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
        ]);

        return array_merge([
            'id' => $l->id,
            'type' => $l->type,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
            'half_type' => $l->half_type,
            'notes' => $l->notes,
            'rejection_note' => $l->rejection_note,
            'leave_credits_charged' => $l->leave_credits_charged,
            'leave_unpaid_credit_days' => $l->leave_unpaid_credit_days,
            'rest_day_bypass' => (bool) $l->rest_day_bypass,
            'rest_day_bypass_reason' => $l->rest_day_bypass_reason,
            'status' => $l->status,
            'created_at' => $l->created_at?->toIso8601String(),
            'display_status' => $this->leaveApprovalService->deriveDisplayStatusLabel($l),
            'approval_stage' => $l->approval_stage,
            'approval_progress' => $this->mergeLeaveRemarksIntoProgress(
                $l,
                $this->leaveApprovalService->buildApprovalProgress($l)
            ),
            'approval_history' => $l->approvalAudits->map(function (LeaveApprovalAudit $a) {
                return [
                    'action' => $a->action,
                    'approver_role' => $a->approver_role,
                    'details' => $a->details,
                    'at' => $a->created_at?->toIso8601String(),
                    'actor_name' => $a->actor?->display_name,
                ];
            })->values()->all(),
        ], $this->documentFieldsForLeave($l), $this->leaveRequesterMeta($l), [
            'actor_can_delete' => $this->canDeleteLeaveRequest($l->user, $l),
        ]);
    }

    private function canDeleteLeaveRequest(?User $actor, LeaveRequest $leave): bool
    {
        if ($actor === null || $leave->status !== LeaveRequest::STATUS_PENDING) {
            return false;
        }

        $actorId = (int) $actor->id;

        return $actorId === (int) $leave->filed_by
            || $actorId === (int) $leave->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveRequesterMeta(LeaveRequest $leave): array
    {
        $requester = ($leave->relationLoaded('filedBy') && $leave->filedBy) ? $leave->filedBy : $leave->user;
        if (! $requester) {
            return [
                'requested_by_id' => null,
                'requested_by_name' => null,
                'requested_by_profile_image_url' => null,
                'requested_by_position' => null,
                'requested_by_hr_role' => null,
                'requested_by_role_label' => null,
            ];
        }

        $hr = $requester->isAdmin()
            ? \App\Enums\HrRole::AdminHr
            : $this->hrRoleResolver->resolveForApprovalSubject($requester);

        return [
            'requested_by_id' => $requester->id,
            'requested_by_name' => $requester->display_name,
            'requested_by_profile_image_url' => $requester->profile_image_url,
            'requested_by_position' => $requester->position,
            'requested_by_hr_role' => $hr->value,
            'requested_by_role_label' => $hr->badgeLabel(),
        ];
    }

    /**
     * Refreshes user schedule fields from DB to avoid stale schedule JSON.
     */
    private function refreshUserForScheduleCheck(User $user): User
    {
        $fresh = User::query()
            ->select(['id', 'schedule', 'working_schedule_id'])
            ->where('id', $user->id)
            ->first();

        return $fresh ?? $user;
    }

    /**
     * @return array|null Day schedule array or null when rest day / not configured.
     */
    private function getDayScheduleForDate(User $user, Carbon $date): ?array
    {
        $schedule = $user->schedule;
        if (! is_array($schedule) || $schedule === []) {
            return null;
        }
        $dayKey = self::DAY_KEYS[(int) $date->format('w')];
        $day = $schedule[$dayKey] ?? null;
        if (! is_array($day) || $day === []) {
            return null;
        }

        return $day;
    }

    private function ensureNotHoliday(string $dateKey): void
    {
        $holidays = config('attendance.holidays', []);
        if (! is_array($holidays)) {
            $holidays = [];
        }
        $isHoliday = in_array($dateKey, $holidays, true);
        $allowOnHoliday = (bool) config('attendance.allow_undertime_on_holiday', false);
        if ($isHoliday && ! $allowOnHoliday) {
            throw ValidationException::withMessages([
                'start_date' => ['Selected date is a holiday.'],
            ]);
        }
    }

    private function ensureNotRestDay(?array $daySchedule): void
    {
        $allowOnRestDay = (bool) config('attendance.allow_undertime_on_rest_day', false);
        if ($daySchedule === null && ! $allowOnRestDay) {
            throw ValidationException::withMessages([
                'start_date' => ['Selected date is a rest day.'],
            ]);
        }
    }

    /**
     * Validate early-out time against schedule start/end and break window.
     * Returns a Carbon instance representing the early-out datetime (shift-aware).
     */
    private function validateUndertimeTimeOrThrow(string $dateKey, array $daySchedule, string $undertimeTime, string $tz): Carbon
    {
        $in = trim((string) ($daySchedule['in'] ?? ''));
        $out = trim((string) ($daySchedule['out'] ?? ''));
        if ($in === '' || $out === '') {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned yet. Please contact the administrator.'],
            ]);
        }

        $scheduledStart = Carbon::parse($dateKey.' '.$in, $tz);
        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned yet. Please contact the administrator.'],
            ]);
        }

        $earlyOut = Carbon::parse($dateKey.' '.$undertimeTime, $tz);

        // Night shift support: if schedule end is next day and earlyOut time is before schedule start (e.g. 02:00),
        // treat earlyOut as next-day time.
        if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $earlyOut->lessThan($scheduledStart)) {
            $earlyOut = $earlyOut->addDay();
        }

        if ($earlyOut->lessThanOrEqualTo($scheduledStart)) {
            throw ValidationException::withMessages([
                'undertime_time' => ['Early-out time must be after your schedule start.'],
            ]);
        }

        if ($earlyOut->greaterThanOrEqualTo($scheduledEnd)) {
            throw ValidationException::withMessages([
                'undertime_time' => ['Early-out time must be earlier than your schedule end.'],
            ]);
        }

        $breakStartStr = trim((string) ($daySchedule['break_start'] ?? ''));
        $breakEndStr = trim((string) ($daySchedule['break_end'] ?? ''));
        if ($breakStartStr !== '' && $breakEndStr !== '') {
            $breakStart = Carbon::parse($dateKey.' '.substr($breakStartStr, 0, 5), $tz);
            $breakEnd = Carbon::parse($dateKey.' '.substr($breakEndStr, 0, 5), $tz);
            if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $breakStart->lessThan($scheduledStart)) {
                $breakStart->addDay();
            }
            if ($scheduledEnd->toDateString() !== $scheduledStart->toDateString() && $breakEnd->lessThan($scheduledStart)) {
                $breakEnd->addDay();
            }
            if ($breakEnd->lessThanOrEqualTo($breakStart)) {
                $breakEnd->addDay();
            }

            if ($earlyOut->greaterThanOrEqualTo($breakStart) && $earlyOut->lessThanOrEqualTo($breakEnd)) {
                throw ValidationException::withMessages([
                    'undertime_time' => ['Early-out time cannot be during the break period.'],
                ]);
            }
        }

        return $earlyOut;
    }

    /**
     * List leave requests for the authenticated employee with simple summary.
     *
     * Optional query params:
     * - from_date, to_date (date range)
     * - status: pending|approved|rejected
     */
    public function my(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('employee.leave.my');
        $user = $request->user();
        $tz = $this->attendanceTimezone();
        $userForSchedule = $this->refreshUserForScheduleCheck($user);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'dashboard_lite' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);
        $dashboardLite = $request->boolean('dashboard_lite');
        $perPage = (int) ($validated['per_page'] ?? ($dashboardLite ? 10 : 10));

        $query = LeaveRequest::query()
            ->where('user_id', $user->id);

        if (! $dashboardLite) {
            $query->with([
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,branch_id,company_id',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
            ]);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['from_date']) || ! empty($validated['to_date'])) {
            $from = isset($validated['from_date'])
                ? Carbon::parse($validated['from_date'])->startOfDay()
                : Carbon::parse($validated['to_date'])->startOfDay();
            $to = isset($validated['to_date'])
                ? Carbon::parse($validated['to_date'])->endOfDay()
                : Carbon::parse($validated['from_date'])->endOfDay();
            if ($to->lessThan($from)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            $query->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString());
        }

        $summaryBase = clone $query;
        $total = (clone $summaryBase)->count();
        $pending = (clone $summaryBase)->where('status', LeaveRequest::STATUS_PENDING)->count();
        $approved = (clone $summaryBase)->where('status', LeaveRequest::STATUS_APPROVED)->count();
        $rejected = (clone $summaryBase)->where('status', LeaveRequest::STATUS_REJECTED)->count();
        $today = now()->toDateString();
        $upcoming = (clone $summaryBase)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('end_date', '>=', $today)
            ->count();

        $paginator = $query
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $payloadLeaves = $paginator->getCollection()->map(function (LeaveRequest $l) use ($tz, $userForSchedule, $dashboardLite) {
            if ($dashboardLite) {
                return [
                    'id' => $l->id,
                    'type' => $l->type,
                    'start_date' => $l->start_date?->toDateString(),
                    'end_date' => $l->end_date?->toDateString(),
                    'status' => $l->status,
                ];
            }

            $undertimeMinutes = null;
            if ($l->type === 'undertime' && $l->undertime_time) {
                $dateKey = $l->start_date->toDateString();
                $date = Carbon::parse($dateKey, $tz);
                $daySchedule = $this->getDayScheduleForDate($userForSchedule, $date);
                if ($daySchedule) {
                    $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
                    if ($scheduledEnd) {
                        $earlyOut = Carbon::parse($dateKey.' '.substr((string) $l->undertime_time, 0, 5), $tz);
                        $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $earlyOut, null);
                    }
                }
            }

            $base = $this->mapEmployeeLeaveRow($l);
            $base['undertime_minutes'] = $undertimeMinutes;
            $base['undertime_hours'] = $undertimeMinutes !== null ? round($undertimeMinutes / 60, 2) : null;

            return $base;
        });

        $leaveCreditsPayload = null;
        if (! $dashboardLite) {
            $user->refresh();
            $leaveCreditsPayload = $this->leaveCreditService->buildLeaveCreditsApiPayload($user);
        }

        RequestPerformanceLogger::finish($perf, $request, $payloadLeaves->count(), [
            'scope' => 'employee',
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);

        return response()->json([
            'summary' => [
                'total' => $total,
                'pending' => $pending,
                'approved' => $approved,
                'rejected' => $rejected,
                'upcoming' => $upcoming,
            ],
            'leave_credits' => $leaveCreditsPayload,
            'leave_requests' => $payloadLeaves->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Return half-day availability flags (morning/afternoon clock-ins) for a given date.
     *
     * This is used by the frontend to enable/disable AM/PM half-day options in real time.
     */
    public function halfdayAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = $this->attendanceTimezone();

        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = Carbon::parse($validated['date'], $tz)->startOfDay();
        $dayStart = $date->copy();
        $noon = $dayStart->copy()->setTime(12, 0, 0);
        $dayEnd = $dayStart->copy()->endOfDay();

        $hasMorningIn = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('created_at', [$dayStart->copy()->utc(), $noon->copy()->utc()->subSecond()])
            ->exists();

        $hasAfternoonIn = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('created_at', [$noon->copy()->utc(), $dayEnd->copy()->utc()])
            ->exists();

        return response()->json([
            'date' => $date->toDateString(),
            'has_morning_in' => $hasMorningIn,
            'has_afternoon_in' => $hasAfternoonIn,
        ]);
    }

    /**
     * Live preview for undertime: given a date and approved early-out time,
     * return the scheduled shift end and computed undertime minutes/hours.
     */
    public function undertimePreview(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = $this->attendanceTimezone();

        $validated = $request->validate([
            'date' => ['required', 'date'],
            'undertime_time' => ['required', 'date_format:H:i'],
        ]);

        $date = Carbon::parse($validated['date'], $tz)->startOfDay();
        $dateKey = $date->toDateString();

        LeaveFilingRules::assertLeaveStartsAfterToday($dateKey);
        LeaveFilingRules::assertRangeHasNoCompletedAttendance((int) $user->id, $dateKey, $dateKey);

        $user = $this->refreshUserForScheduleCheck($user);
        if ($user->working_schedule_id === null) {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned yet. Please contact the administrator.'],
            ]);
        }

        $this->ensureNotHoliday($dateKey);
        $daySchedule = $this->getDayScheduleForDate($user, Carbon::parse($dateKey, $tz));
        $this->ensureNotRestDay($daySchedule);
        if (! $daySchedule) {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned for the selected date.'],
            ]);
        }

        $undertimeTime = trim((string) ($validated['undertime_time'] ?? ''));
        if ($undertimeTime === '') {
            throw ValidationException::withMessages([
                'undertime_time' => ['Approved early-out time is required for undertime leave.'],
            ]);
        }

        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
        if (! $scheduledEnd) {
            throw ValidationException::withMessages([
                'schedule' => ['No schedule assigned yet. Please contact the administrator.'],
            ]);
        }

        // Reuse strict time validation, including night shift / break window rules.
        $earlyOut = $this->validateUndertimeTimeOrThrow($dateKey, $daySchedule, $undertimeTime, $tz);

        $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $earlyOut, null);

        return response()->json([
            'date' => $dateKey,
            'shift_end_time' => $scheduledEnd->copy()->timezone($tz)->format('H:i'),
            'early_out_time' => substr($undertimeTime, 0, 5),
            'undertime_minutes' => $undertimeMinutes,
            'undertime_hours' => $undertimeMinutes !== null ? round($undertimeMinutes / 60, 2) : null,
        ]);
    }

    /**
     * Server-side paid vs unpaid preview (schedule-based billable days; matches approval deduction).
     */
    public function paidLeavePreview(Request $request): JsonResponse
    {
        $user = $this->refreshUserForScheduleCheck($request->user());

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50', 'in:vacation,sick,emergency,other,undertime,half_day'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'except_leave_request_id' => ['nullable', 'integer'],
        ]);

        if (! empty($validated['except_leave_request_id'])) {
            $owns = LeaveRequest::query()
                ->whereKey((int) $validated['except_leave_request_id'])
                ->where('user_id', $user->id)
                ->exists();
            if (! $owns) {
                throw ValidationException::withMessages([
                    'except_leave_request_id' => ['Invalid leave request for this user.'],
                ]);
            }
        }

        $end = $validated['end_date'] ?? $validated['start_date'];
        $payload = $this->leaveCreditService->previewPaidLeaveForRequest(
            $user,
            (string) $validated['type'],
            $validated['start_date'],
            $end,
            isset($validated['except_leave_request_id']) ? (int) $validated['except_leave_request_id'] : null
        );

        return response()->json($payload);
    }

    /**
     * Check whether a date range includes scheduled rest days (for leave form validation).
     */
    public function validateLeaveDateRange(Request $request): JsonResponse
    {
        $user = $this->refreshUserForScheduleCheck($request->user());
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $summary = LeaveScheduleSupport::summarizeRangeForUser(
            $user,
            $validated['start_date'],
            $validated['end_date']
        );

        $message = null;
        if (! $summary['valid']) {
            $first = $summary['rest_day_hits'][0] ?? null;
            $message = $first
                ? LeaveScheduleSupport::formatRestDayViolationMessage(
                    Carbon::parse($first['date'], LeaveScheduleSupport::attendanceTimezone())->startOfDay()
                )
                : 'Selected dates include scheduled rest days. Choose working days only.';
            Log::info('leave.validate_range_blocked', [
                'user_id' => $user->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'using_default_schedule' => $summary['using_default_schedule'] ?? false,
            ]);
        }

        return response()->json(array_merge($summary, [
            'message' => $message,
        ]));
    }

    /**
     * Apply for leave as the authenticated employee.
     */
    public function organizationAssignments(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['user' => ['Unauthenticated.']]);
        }

        return response()->json([
            ...$this->organizationAssignments->requestContextOptionsForEmployee(
                $user,
                $request->query('date') ?: $request->query('start_date')
            ),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessSelfServiceEmployeeProfile()) {
            throw ValidationException::withMessages([
                'user' => ['System access accounts cannot file employee leave requests.'],
            ]);
        }
        if ($user->isAccountDeactivated()) {
            throw ValidationException::withMessages([
                'user' => [User::DEACTIVATED_LOGIN_MESSAGE],
            ]);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50', 'in:vacation,sick,emergency,other,undertime,half_day'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'undertime_time' => ['nullable', 'date_format:H:i'],
            // Reason is required only for undertime; for other types it is optional.
            'reason' => ['nullable', 'string', 'max:2000'],
            // For half-day leave we require which half of the day is worked.
            'half_type' => ['nullable', 'string', 'in:am,pm'],
            'assignment_id' => ['nullable', 'integer', 'exists:employee_organization_assignments,id'],
        ]);

        $type = $validated['type'];

        if ($type === 'undertime') {
            // Undertime is time-based and must always be a single calendar date.
            $validated['end_date'] = $validated['start_date'];

            $reason = trim((string) ($validated['reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => ['Reason is required for undertime leave.'],
                ]);
            }
            $undertimeTime = trim((string) ($validated['undertime_time'] ?? ''));
            if ($undertimeTime === '') {
                throw ValidationException::withMessages([
                    'undertime_time' => ['Approved early-out time is required for undertime leave.'],
                ]);
            }

            // Previous schedule / attendance-based restrictions for undertime have
            // been removed. We only ensure that a time value is provided; detailed
            // schedule checks can be handled separately if needed.
        }

        if ($type === 'half_day') {
            // Half-day leave is always a single calendar date.
            $validated['end_date'] = $validated['start_date'];

            $halfType = $validated['half_type'] ?? null;
            if ($halfType === null || $halfType === '') {
                throw ValidationException::withMessages([
                    'half_type' => ['Half day type (AM or PM) is required.'],
                ]);
            }

            // Previous attendance-based restrictions for half-day leave (e.g. only
            // allowing AM/PM based on existing time-ins) have been removed so that
            // employees can file half-day leave more freely.
        }

        // Earliest start is tomorrow; no leave on dates that already have completed DTR.
        LeaveFilingRules::assertLeaveStartsAfterToday($validated['start_date']);
        LeaveFilingRules::assertRangeHasNoCompletedAttendance(
            (int) $user->id,
            $validated['start_date'],
            $validated['end_date']
        );
        LeaveFilingRules::assertNoOverlappingPendingOrApprovedLeave(
            (int) $user->id,
            $validated['start_date'],
            $validated['end_date']
        );

        try {
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                (int) $user->id,
                Carbon::parse($validated['start_date'])->startOfDay(),
                Carbon::parse($validated['end_date'])->startOfDay()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $userForSchedule = $this->refreshUserForScheduleCheck($user);
        LeaveScheduleSupport::assertRangeHasNoRestDays(
            $userForSchedule,
            $validated['start_date'],
            $validated['end_date']
        );

        $this->leaveCreditService->assertSufficientForNewRequest(
            $userForSchedule,
            $type,
            $validated['start_date'],
            $validated['end_date'],
            false
        );

        $selectedAssignment = $this->organizationAssignments->resolveRequestAssignment(
            $user,
            isset($validated['assignment_id']) ? (int) $validated['assignment_id'] : null,
            $validated['start_date'],
        );
        $assignmentContext = $this->organizationAssignments->requestContextPayload($selectedAssignment);

        Log::info('leave_request: selected organization context', [
            'request_employee_id' => (int) $user->id,
            'selected_assignment_id' => $assignmentContext['assignment_id'],
            'selected_assignment_type' => $assignmentContext['assignment_type'],
            'selected_section_unit_id' => $assignmentContext['section_unit_id'],
        ]);

        $routing = $this->hrApprovalChainResolver->resolveRoutingDecision(
            $user,
            true,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $assignmentContext,
        );
        $chain = $routing['chain'];
        if ($chain === null) {
            throw ValidationException::withMessages([
                'user' => ['Your role cannot file leave requests.'],
            ]);
        }

        $stage = $this->hrApprovalChainResolver->initialApprovalStage(
            $user,
            true,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $assignmentContext,
        );
        $hrApproverId = $routing['hr_approver']?->id;
        if (! $hrApproverId) {
            throw ValidationException::withMessages([
                'approval' => ['No active Admin (HR) approver is configured.'],
            ]);
        }
        $reasonTrim = trim((string) ($validated['reason'] ?? ''));
        $notes = $reasonTrim !== '' ? $reasonTrim : null;
        $fileDetails = $type === 'undertime' ? $reasonTrim : ($notes ?? 'Leave request submitted.');
        $leave = DB::transaction(function () use ($user, $type, $validated, $stage, $fileDetails, $notes, $hrApproverId, $assignmentContext) {
            $leave = LeaveRequest::create([
                'user_id' => $user->id,
                ...$assignmentContext,
                'type' => $type,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'undertime_time' => $type === 'undertime' ? ($validated['undertime_time'] ?? null) : null,
                'half_type' => $type === 'half_day' ? ($validated['half_type'] ?? null) : null,
                'notes' => $notes,
                'status' => LeaveRequest::STATUS_PENDING,
                'approval_stage' => $stage,
                'pending_approval' => true,
                'first_approver_id' => null,
                'second_approver_id' => $hrApproverId,
                'filed_at' => now(),
                'filed_by' => $user->id,
            ]);

            LeaveApprovalAudit::create([
                'leave_request_id' => $leave->id,
                'actor_id' => $user->id,
                'employee_id' => $user->id,
                'action' => 'file',
                'details' => $fileDetails,
                'approver_role' => $this->hrRoleResolver->resolve($user)->badgeLabel(),
            ]);

            return $leave;
        });

        $this->approvalWorkflowService->ensureRecordsForRequest(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $user,
            $user,
        );

        $leave->refresh();
        $leave->load([
            'user:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,department,branch_id,company_id',
            'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,branch_id,company_id',
            'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'approvalAudits' => fn ($q) => $q->with('actor:id,name,first_name,middle_name,last_name,suffix')->orderBy('created_at'),
        ]);

        return response()->json([
            'message' => 'Leave request submitted.',
            'leave_request' => $this->mapEmployeeLeaveRow($leave),
        ], 201);
    }

    /**
     * Upload or replace supporting document for a leave request.
     */
    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leave = LeaveRequest::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $this->normalizeLeaveDocuments($leave);
        $paths = $leave->document_paths ?? [];
        if (count($paths) >= 5) {
            throw ValidationException::withMessages([
                'document' => ['You can attach up to 5 files.'],
            ]);
        }

        $path = $validated['document']->store('leave-documents', 'public');
        $paths[] = $path;
        $leave->document_paths = $paths;
        $leave->save();

        $leave->refresh();

        return response()->json([
            'message' => 'Document uploaded.',
            ...$this->documentFieldsForLeave($leave),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leave = LeaveRequest::query()
            ->where('id', $id)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('filed_by', $user->id);
            })
            ->firstOrFail();

        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'id' => ['Only pending leave requests can be deleted.'],
            ]);
        }

        if (! $this->canDeleteLeaveRequest($user, $leave)) {
            throw ValidationException::withMessages([
                'id' => ['You can only delete leave requests you created or requests filed for you.'],
            ]);
        }

        foreach ($leave->resolveDocumentPaths() as $path) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        $leave->delete();

        return response()->json([
            'message' => 'Leave request deleted.',
        ]);
    }
}
