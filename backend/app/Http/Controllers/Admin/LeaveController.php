<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveApprovalAudit;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\LeaveApprovalService;
use App\Services\LeaveCreditService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\HrApprovalStages;
use App\Support\LeaveFilingRules;
use App\Support\LeaveScheduleSupport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly LeaveApprovalService $leaveApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
    ) {}

    /**
     * List leave requests. Optional filter: status = pending | approved | rejected.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $actor = $request->user();
        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        // Include org + role columns so approval checks (department head, etc.) match the approve() endpoint.
        $query = LeaveRequest::with([
            'user:id,name,profile_image,schedule,working_schedule_id,role,department_id,branch_id,company_id',
            'reviewedByUser:id,name',
            'filedBy:id,name,profile_image',
            'firstApprover:id,name,profile_image',
            'secondApprover:id,name,profile_image',
            'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
        ]);

        $scope = User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $scope);
        $query->whereIn('user_id', $scope->select('users.id'));

        if (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED], true)) {
            $query->where('status', $status);
        }

        $leaves = $query->orderByDesc('created_at')->get()->map(function (LeaveRequest $l) use ($tz, $actor) {
            $undertimeMinutes = null;
            $shiftEndTime = null;
            $actualClockOutTime = null;
            if ($l->type === 'undertime' && $l->undertime_time && $l->user && $l->user->working_schedule_id !== null) {
                $dateKey = $l->start_date->toDateString();
                $date = \Carbon\Carbon::parse($dateKey, $tz);

                $schedule = $l->user->schedule;
                if (is_array($schedule) && $schedule !== []) {
                    $dayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
                    $dayKey = $dayKeys[(int) $date->format('w')];
                    $daySchedule = $schedule[$dayKey] ?? null;

                    if (is_array($daySchedule) && $daySchedule !== []) {
                        $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $tz);
                        if ($scheduledEnd) {
                            $shiftEndTime = $scheduledEnd->copy()->timezone($tz)->format('H:i');

                            $earlyOut = \Carbon\Carbon::parse($dateKey.' '.substr((string) $l->undertime_time, 0, 5), $tz);
                            $undertimeMinutes = AttendanceStatusService::getUndertimeMinutes($scheduledEnd, $earlyOut, null);
                        }
                    }
                }

                // Actual clock-out time (last clock-out log for the date, if any).
                $dayStart = \Carbon\Carbon::parse($dateKey, $tz)->startOfDay();
                $dayEnd = $dayStart->copy()->endOfDay();
                $lastClockOut = AttendanceLog::query()
                    ->where('user_id', $l->user_id)
                    ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
                    ->whereBetween('created_at', [$dayStart->copy()->utc(), $dayEnd->copy()->utc()])
                    ->orderByDesc('created_at')
                    ->first();

                if ($lastClockOut) {
                    $actualClockOutTime = $lastClockOut->created_at->copy()->timezone($tz)->format('H:i');
                }
            }

            return array_merge([
                'id' => $l->id,
                'employee_id' => $l->user_id,
                'employee_name' => $l->user?->name,
                'employee_profile_image' => $l->user?->profile_image_url,
                'type' => $l->type,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
                'undertime_minutes' => $undertimeMinutes,
                'undertime_hours' => $undertimeMinutes !== null ? round($undertimeMinutes / 60, 2) : null,
                'shift_end_time' => $shiftEndTime,
                'actual_clock_out_time' => $actualClockOutTime,
                'undertime_filing_status' => $l->type === 'undertime' ? 'filed' : null,
                'half_type' => $l->half_type,
                'leave_credits_charged' => $l->leave_credits_charged,
                'leave_unpaid_credit_days' => $l->leave_unpaid_credit_days,
                'status' => $l->status,
                'notes' => $l->notes,
                'filed_on_time' => null,
                'filed_after_leave_date' => false,
                'reviewed_at' => $l->reviewed_at?->toIso8601String(),
                'reviewed_by_name' => $l->reviewedByUser?->name,
                'created_at' => $l->created_at->toIso8601String(),
                'display_status' => $this->leaveApprovalService->deriveDisplayStatusLabel($l),
                'approval_stage' => $l->approval_stage,
                'approval_progress' => $this->mergeLeaveRemarksIntoProgress(
                    $l,
                    $this->leaveApprovalService->buildApprovalProgress($l)
                ),
            ], $this->documentPayload($l), $this->leaveActorFlags($l, $actor));
        });

        return response()->json(['leave_requests' => $leaves]);
    }

    /**
     * Create a leave request (admin creating on behalf of employee, or for testing).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'string', 'max:50', 'in:vacation,sick,emergency,other,undertime,half_day'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'half_type' => ['nullable', 'string', 'in:am,pm'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'bypass_rest_days' => ['sometimes', 'boolean'],
            'rest_day_bypass_reason' => ['required_if:bypass_rest_days,true', 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $actor = $request->user();

        // Only pure HR (admin account, not an assigned org head) may file for another user.
        if (! $this->hrRoleResolver->canFileLeaveForOthers($actor) && (int) $validated['user_id'] !== (int) $actor->id) {
            return response()->json([
                'message' => 'You may only file leave requests for yourself.',
            ], 403);
        }

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $employee = User::findOrFail($validated['user_id']);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        try {
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                (int) $employee->id,
                Carbon::parse($validated['start_date'])->startOfDay(),
                Carbon::parse($validated['end_date'])->startOfDay()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $type = $validated['type'];

        if ($validated['type'] === 'half_day') {
            // Half-day leave from admin side is always a single calendar date.
            $validated['end_date'] = $validated['start_date'];
            if (empty($validated['half_type'])) {
                return response()->json([
                    'message' => 'Half day type (AM or PM) is required.',
                ], 422);
            }
        }

        try {
            LeaveFilingRules::assertLeaveStartsAfterToday($validated['start_date']);
            LeaveFilingRules::assertRangeHasNoCompletedAttendance(
                (int) $validated['user_id'],
                $validated['start_date'],
                $validated['end_date']
            );
            LeaveFilingRules::assertNoOverlappingPendingOrApprovedLeave(
                (int) $validated['user_id'],
                $validated['start_date'],
                $validated['end_date']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Invalid leave dates.',
                'errors' => $e->errors(),
            ], 422);
        }

        $bypassRest = $request->boolean('bypass_rest_days');
        if ($bypassRest && ! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return response()->json([
                'message' => 'Only an HR administrator may override rest-day rules when filing leave.',
            ], 403);
        }

        try {
            if (! $bypassRest) {
                LeaveScheduleSupport::assertRangeHasNoRestDays(
                    $employee,
                    $validated['start_date'],
                    $validated['end_date']
                );
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Invalid schedule for leave dates.',
                'errors' => $e->errors(),
            ], 422);
        }

        $bypassCredits = $request->boolean('bypass_leave_credit_check') && $actor->isSuperAdmin();
        try {
            $this->leaveCreditService->assertSufficientForNewRequest(
                $employee,
                $validated['type'],
                $validated['start_date'],
                $validated['end_date'],
                $bypassCredits
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Insufficient leave credits.',
                'errors' => $e->errors(),
            ], 422);
        }

        $stage = $this->hrApprovalChainResolver->initialApprovalStage($employee, employeeSubmitted: false);

        $restBypassReason = $bypassRest ? trim((string) $request->input('rest_day_bypass_reason')) : null;

        $leave = LeaveRequest::create([
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'half_type' => $validated['type'] === 'half_day' ? ($validated['half_type'] ?? null) : null,
            'notes' => $validated['notes'] ?? null,
            'status' => LeaveRequest::STATUS_PENDING,
            'approval_stage' => $stage,
            'pending_approval' => true,
            'filed_at' => now(),
            'filed_by' => $employee->id,
            'rest_day_bypass' => $bypassRest,
            'rest_day_bypass_reason' => $bypassRest ? $restBypassReason : null,
            'rest_day_bypass_by' => $bypassRest ? $actor->id : null,
            'rest_day_bypass_at' => $bypassRest ? now() : null,
        ]);

        $auditDetails = $this->hrRoleResolver->canFileLeaveForOthers($actor) && (int) $employee->id !== (int) $actor->id
            ? 'Created by HR on behalf of employee.'
            : 'Leave request filed via HR panel.';
        if ($bypassRest && $restBypassReason !== '') {
            $auditDetails .= ' Rest-day filing override: '.$restBypassReason;
            Log::info('leave.hr_rest_day_override_filing', [
                'leave_request_id' => $leave->id,
                'actor_id' => $actor->id,
                'employee_id' => $employee->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);
        }

        LeaveApprovalAudit::create([
            'leave_request_id' => $leave->id,
            'actor_id' => $actor->id,
            'employee_id' => $employee->id,
            'action' => 'file',
            'details' => $auditDetails,
            'approver_role' => $this->hrRoleResolver->resolve($actor)->badgeLabel(),
        ]);

        return response()->json([
            'message' => 'Leave request created.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'filedBy', 'firstApprover', 'secondApprover', 'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
            ]), $request->user()),
        ], 201);
    }

    /**
     * Approve a leave request (line manager first, then HR final).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'force_insufficient_credits' => ['sometimes', 'boolean'],
            'bypass_rest_days' => ['sometimes', 'boolean'],
            'rest_day_bypass_reason' => ['required_if:bypass_rest_days,true', 'nullable', 'string', 'min:10', 'max:2000'],
        ]);

        $actor = $request->user();
        if ($request->boolean('bypass_rest_days') && ! $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return response()->json([
                'message' => 'Only an HR administrator may use rest-day approval overrides.',
            ], 403);
        }

        $leave = LeaveRequest::query()->with(['user', 'filedBy', 'firstApprover', 'secondApprover'])->findOrFail($id);
        if ($leave->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $leave->user);
        }
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Leave request is not pending.'], 422);
        }

        if (! $this->leaveApprovalService->canApprove($actor, $leave)) {
            return response()->json(['message' => 'You are not authorized to approve at this stage.'], 403);
        }

        $gate = $this->restDayApproveGate($request, $leave, $actor);
        if ($gate['blocked']) {
            return $gate['response'];
        }
        $applyBypass = $gate['apply_bypass'];
        if ($applyBypass) {
            Log::info('leave.hr_rest_day_override_approve', [
                'leave_request_id' => $leave->id,
                'actor_id' => $actor->id,
                'employee_id' => $leave->user_id,
            ]);
        }

        $stage = $leave->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $notes = $validated['notes'] ?? null;
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();

        if ($stage === HrApprovalStages::PENDING_FIRST) {
            try {
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                    (int) $leave->user_id,
                    Carbon::parse($leave->start_date)->startOfDay(),
                    Carbon::parse($leave->end_date)->startOfDay()
                );
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            DB::transaction(function () use ($leave, $actor, $notes, $roleLabel, $applyBypass, $request) {
                $leave->refresh();
                if ($applyBypass) {
                    $leave->rest_day_bypass = true;
                    $leave->rest_day_bypass_reason = trim((string) $request->input('rest_day_bypass_reason'));
                    $leave->rest_day_bypass_by = $actor->id;
                    $leave->rest_day_bypass_at = now();
                }
                $leave->first_approver_id = $actor->id;
                $leave->first_approved_at = now();
                $leave->approval_stage = HrApprovalStages::PENDING_SECOND;
                $leave->save();

                $details = $notes;
                if ($applyBypass) {
                    $details = trim(($details ? $details.' — ' : '').'Rest-day approval override: '.trim((string) $request->input('rest_day_bypass_reason')));
                }

                LeaveApprovalAudit::create([
                    'leave_request_id' => $leave->id,
                    'actor_id' => $actor->id,
                    'employee_id' => $leave->user_id,
                    'action' => 'approve_first',
                    'details' => $details,
                    'approver_role' => $roleLabel,
                ]);
            });

            return response()->json([
                'message' => 'First approval recorded. Pending Admin (HR) final approval.',
                'leave_request' => $this->leaveResponse($leave->fresh([
                    'user', 'filedBy', 'firstApprover', 'secondApprover',
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
                ]), $actor),
            ]);
        }

        if ($stage !== HrApprovalStages::PENDING_SECOND) {
            return response()->json(['message' => 'This leave request cannot be approved.'], 422);
        }

        $forceCredits = (bool) ($validated['force_insufficient_credits'] ?? false) && $actor->isSuperAdmin();

        try {
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                (int) $leave->user_id,
                Carbon::parse($leave->start_date)->startOfDay(),
                Carbon::parse($leave->end_date)->startOfDay()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            DB::transaction(function () use ($leave, $actor, $validated, $roleLabel, $forceCredits, $applyBypass, $request) {
                $leave->refresh();
                if ($applyBypass) {
                    $leave->rest_day_bypass = true;
                    $leave->rest_day_bypass_reason = trim((string) $request->input('rest_day_bypass_reason'));
                    $leave->rest_day_bypass_by = $actor->id;
                    $leave->rest_day_bypass_at = now();
                }
                $leave->status = LeaveRequest::STATUS_APPROVED;
                $leave->pending_approval = false;
                $leave->approval_stage = HrApprovalStages::APPROVED;
                $leave->second_approver_id = $actor->id;
                $leave->second_approved_at = now();
                $leave->reviewed_at = now();
                $leave->reviewed_by = $actor->id;
                $leave->notes = $validated['notes'] ?? $leave->notes;
                $leave->save();

                $finalDetails = $validated['notes'] ?? null;
                if ($applyBypass) {
                    $finalDetails = trim(($finalDetails ? $finalDetails.' — ' : '').'Rest-day approval override: '.trim((string) $request->input('rest_day_bypass_reason')));
                }

                LeaveApprovalAudit::create([
                    'leave_request_id' => $leave->id,
                    'actor_id' => $actor->id,
                    'employee_id' => $leave->user_id,
                    'action' => 'approve_final',
                    'details' => $finalDetails,
                    'approver_role' => $roleLabel,
                ]);

                $this->leaveCreditService->deductForFinalApproval($leave->fresh(), $actor, $forceCredits);
            });
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Leave credits validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Leave request approved.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
            ]), $actor),
        ]);
    }

    /**
     * Reject a leave request.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        $leave = LeaveRequest::query()->with(['user', 'filedBy', 'firstApprover', 'secondApprover'])->findOrFail($id);
        if ($leave->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $leave->user);
        }
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Leave request is not pending.'], 422);
        }

        if (! $this->leaveApprovalService->canReject($actor, $leave)) {
            return response()->json(['message' => 'You are not authorized to reject at this stage.'], 403);
        }

        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();

        DB::transaction(function () use ($leave, $actor, $validated, $roleLabel) {
            $leave->status = LeaveRequest::STATUS_REJECTED;
            $leave->pending_approval = false;
            $leave->approval_stage = HrApprovalStages::REJECTED;
            $leave->rejected_at = now();
            $leave->rejected_by = $actor->id;
            $leave->rejection_note = $validated['reason'];
            $leave->reviewed_at = now();
            $leave->reviewed_by = $actor->id;
            $leave->save();

            LeaveApprovalAudit::create([
                'leave_request_id' => $leave->id,
                'actor_id' => $actor->id,
                'employee_id' => $leave->user_id,
                'action' => 'reject',
                'details' => $validated['reason'],
                'approver_role' => $roleLabel,
            ]);
        });

        return response()->json([
            'message' => 'Leave request rejected.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover', 'rejectedBy',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
            ]), $actor),
        ]);
    }

    /**
     * Add or update notes on a leave request.
     */
    public function updateNotes(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $leave = LeaveRequest::findOrFail($id);
        $leave->loadMissing('user');
        if ($leave->user) {
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $leave->user);
        }
        $leave->update(['notes' => $validated['notes'] ?? null]);

        return response()->json([
            'message' => 'Notes updated.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user:id,name', 'reviewedByUser:id,name',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
            ]), $request->user()),
        ]);
    }

    /**
     * HR admin may approve leave that spans scheduled rest days only with bypass + audited reason.
     *
     * @return array{blocked: bool, response: JsonResponse|null, apply_bypass: bool}
     */
    private function restDayApproveGate(Request $request, LeaveRequest $leave, User $actor): array
    {
        if ($leave->rest_day_bypass) {
            return ['blocked' => false, 'response' => null, 'apply_bypass' => false];
        }

        $employee = $leave->relationLoaded('user') ? $leave->user : User::query()->find($leave->user_id);
        if (! $employee) {
            return ['blocked' => false, 'response' => null, 'apply_bypass' => false];
        }
        $employee->loadMissing('workingSchedule');

        $bad = LeaveScheduleSupport::firstRestDayInRange(
            $employee,
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString()
        );

        if ($bad === null) {
            return ['blocked' => false, 'response' => null, 'apply_bypass' => false];
        }

        $canBypass = $request->boolean('bypass_rest_days')
            && $this->hrRoleResolver->isAdminHrAccount($actor)
            && strlen(trim((string) $request->input('rest_day_bypass_reason'))) >= 10;

        if (! $canBypass) {
            $msg = LeaveScheduleSupport::formatRestDayViolationMessage($bad);

            return [
                'blocked' => true,
                'response' => response()->json([
                    'message' => $msg.' HR administrators may approve with bypass_rest_days and rest_day_bypass_reason (min. 10 characters).',
                    'errors' => [
                        'start_date' => [
                            $msg.' Only an HR administrator may approve with a documented override.',
                        ],
                    ],
                ], 422),
                'apply_bypass' => false,
            ];
        }

        return ['blocked' => false, 'response' => null, 'apply_bypass' => true];
    }

    /**
     * Admin: preview rest-day conflicts for an employee’s date range (leave form).
     */
    public function validateLeaveDateRange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $actor = $request->user();
        $employee = User::query()->findOrFail($validated['user_id']);
        $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        $employee->loadMissing('workingSchedule');

        $summary = LeaveScheduleSupport::summarizeRangeForUser(
            $employee,
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
                : 'Range includes scheduled rest days.';
            Log::info('leave.validate_range_blocked', [
                'user_id' => $employee->id,
                'actor_id' => $actor->id,
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
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
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
            $steps[$i]['remarks'] = $remarks;
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function leaveActorFlags(LeaveRequest $leave, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $chain = $leave->user ? $this->leaveApprovalService->getApprovalChain($leave->user) : null;
        $stage = $leave->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $hrWait = null;
        $actorHr = $this->hrRoleResolver->resolve($actor) === \App\Enums\HrRole::AdminHr;
        $canActNow = $this->leaveApprovalService->canApprove($actor, $leave);
        if ($actorHr && ! $canActNow && $chain !== null && count($chain) >= 2 && $stage === HrApprovalStages::PENDING_FIRST
            && $leave->pending_approval && $leave->status === LeaveRequest::STATUS_PENDING && ! $leave->rejected_at) {
            $hrWait = sprintf(
                'Waiting for %s approval before HR can approve or reject.',
                match ($chain[0]) {
                    \App\Enums\HrRole::DepartmentHead => 'Department Head',
                    \App\Enums\HrRole::BranchHead => 'Branch Head',
                    \App\Enums\HrRole::CompanyHead => 'Company Head',
                    default => $chain[0]->badgeLabel(),
                }
            );
        }

        return [
            'actor_can_approve' => $this->leaveApprovalService->canApprove($actor, $leave),
            'actor_can_reject' => $this->leaveApprovalService->canReject($actor, $leave),
            'hr_wait_message' => $hrWait,
        ];
    }

    private function leaveResponse(LeaveRequest $l, ?User $actor = null): array
    {
        $l->loadMissing([
            'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover', 'rejectedBy',
            'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name'),
        ]);

        $base = array_merge([
            'id' => $l->id,
            'employee_id' => $l->user_id,
            'employee_name' => $l->user?->name,
            'type' => $l->type,
            'start_date' => $l->start_date->toDateString(),
            'end_date' => $l->end_date->toDateString(),
            'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
            'half_type' => $l->half_type,
            'leave_credits_charged' => $l->leave_credits_charged,
            'leave_unpaid_credit_days' => $l->leave_unpaid_credit_days,
            'status' => $l->status,
            'notes' => $l->notes,
            'rejection_note' => $l->rejection_note,
            'rest_day_bypass' => (bool) $l->rest_day_bypass,
            'rest_day_bypass_reason' => $l->rest_day_bypass_reason,
            'rest_day_bypass_at' => $l->rest_day_bypass_at?->toIso8601String(),
            'reviewed_at' => $l->reviewed_at?->toIso8601String(),
            'reviewed_by_name' => $l->reviewedByUser?->name,
            'created_at' => $l->created_at->toIso8601String(),
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
                    'actor_name' => $a->actor?->name,
                ];
            })->values()->all(),
        ], $this->documentPayload($l));

        return array_merge($base, $this->leaveActorFlags($l, $actor));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(LeaveRequest $l): array
    {
        $paths = $l->resolveDocumentPaths();
        $urls = array_map(fn (string $p) => $this->publicMediaUrl($p), $paths);

        return [
            'has_document' => count($paths) > 0,
            'document_url' => $urls[0] ?? null,
            'document_urls' => $urls,
            'document_count' => count($paths),
        ];
    }

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

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ]);

        $actor = $request->user();
        $leave = LeaveRequest::query()->with('user')->findOrFail($id);
        if ($leave->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $leave->user);
        }

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
            ...$this->documentPayload($leave),
        ]);
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        $segments = explode('/', $normalized);
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }
}
