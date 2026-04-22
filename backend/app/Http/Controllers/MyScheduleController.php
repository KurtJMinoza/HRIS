<?php

namespace App\Http\Controllers;

use App\Models\ScheduleRequest;
use App\Models\ScheduleRequestApprovalAudit;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\ScheduleApprovalService;
use App\Services\ScheduleRequestPayloadService;
use App\Support\EmployeeScheduleResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MyScheduleController extends Controller
{
    public function __construct(
        private readonly HrApprovalChainResolver $approvalChainResolver,
        private readonly ScheduleApprovalService $scheduleApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly ScheduleRequestPayloadService $scheduleRequestPayloadService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveSelfUser($request);
        $user->loadMissing('pendingWorkingSchedule');

        $requests = ScheduleRequest::query()
            ->where('user_id', $user->id)
            ->with($this->requestRelations())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ScheduleRequest $row) => $this->mapScheduleRequestRow($row, $user))
            ->values();

        return response()->json([
            'current_schedule' => $this->currentScheduleSummary($user->fresh('workingSchedule')),
            'pending_schedule_change' => $this->pendingScheduleChangeSummary($user),
            'requests' => $requests,
        ]);
    }

    public function requestContext(Request $request): JsonResponse
    {
        $user = $this->resolveSelfUser($request);
        $user->loadMissing('pendingWorkingSchedule');

        return response()->json([
            'current_schedule' => $this->currentScheduleSummary($user->fresh('workingSchedule')),
            'pending_schedule_change' => $this->pendingScheduleChangeSummary($user),
            'available_schedules' => WorkingSchedule::query()
                ->orderBy('name')
                ->get()
                ->map(fn (WorkingSchedule $schedule) => $this->workingScheduleSummary($schedule))
                ->values(),
            'approval_chain_preview' => $this->approvalChainPreview($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveSelfUser($request);
        $tz = config('attendance.timezone', 'Asia/Manila');
        $minEffective = Carbon::now($tz)->toDateString();
        $base = $request->validate([
            'request_kind' => ['required', 'in:template,custom'],
            'effective_from' => ['required', 'date', 'after_or_equal:'.$minEffective],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $workingScheduleId = null;
        $customPayload = null;

        if ($base['request_kind'] === ScheduleRequest::KIND_TEMPLATE) {
            $more = $request->validate([
                'working_schedule_id' => ['required', 'integer', 'exists:working_schedules,id'],
            ]);
            $workingScheduleId = (int) $more['working_schedule_id'];
            WorkingSchedule::query()->findOrFail($workingScheduleId);
        } else {
            $request->validate([
                'custom_schedule' => ['required', 'array'],
            ]);
            $customPayload = $this->scheduleRequestPayloadService->validateCustomPayload(
                $request->input('custom_schedule', [])
            );
        }

        $routing = $this->approvalChainResolver->resolveRoutingDecision($user);
        if (($routing['chain'] ?? null) === null) {
            throw ValidationException::withMessages([
                'approval' => ['Your account cannot file schedule requests right now.'],
            ]);
        }
        $initialStage = $this->approvalChainResolver->initialApprovalStage($user);
        $firstApproverId = $initialStage === \App\Support\HrApprovalStages::PENDING_FIRST
            ? ($routing['first_level_approver']?->id)
            : null;
        $hrApproverId = $routing['hr_approver']?->id;
        if (! $hrApproverId) {
            throw ValidationException::withMessages([
                'approval' => ['No active Admin (HR) approver is configured.'],
            ]);
        }

        $scheduleRequest = new ScheduleRequest([
            'user_id' => $user->id,
            'request_kind' => $base['request_kind'],
            'working_schedule_id' => $workingScheduleId,
            'custom_schedule_payload' => $customPayload,
            'effective_from' => $base['effective_from'],
            'remarks' => isset($base['remarks']) ? trim((string) $base['remarks']) : null,
            'status' => ScheduleRequest::STATUS_PENDING,
            'approval_stage' => $initialStage,
            'pending_approval' => true,
            'first_approver_id' => $firstApproverId,
            'second_approver_id' => $hrApproverId,
            'filed_at' => now(),
            'filed_by' => $user->id,
        ]);
        $scheduleRequest->save();

        $auditDetails = $scheduleRequest->remarks;

        ScheduleRequestApprovalAudit::create([
            'schedule_request_id' => $scheduleRequest->id,
            'actor_id' => $user->id,
            'employee_id' => $user->id,
            'action' => 'file',
            'details' => $auditDetails,
            'approver_role' => $this->hrRoleResolver->resolveForApprovalSubject($user)->value,
        ]);

        $scheduleRequest->load($this->requestRelations());

        return response()->json([
            'message' => 'Schedule request submitted.',
            'request' => $this->mapScheduleRequestRow($scheduleRequest, $user),
        ], 201);
    }

    /**
     * On final approval, materialize a custom request into a real {@see WorkingSchedule} row.
     */
    protected function finalizeCustomScheduleIfNeeded(ScheduleRequest $scheduleRequest): void
    {
        if (($scheduleRequest->request_kind ?? ScheduleRequest::KIND_TEMPLATE) !== ScheduleRequest::KIND_CUSTOM) {
            return;
        }
        if ($scheduleRequest->working_schedule_id !== null) {
            return;
        }
        $payload = $scheduleRequest->custom_schedule_payload;
        if (! is_array($payload) || $payload === []) {
            throw ValidationException::withMessages([
                'request' => ['This custom schedule request is missing saved details.'],
            ]);
        }
        $schedule = $this->scheduleRequestPayloadService->createWorkingScheduleFromPayload($payload);
        $scheduleRequest->working_schedule_id = $schedule->id;
    }

    private function resolveSelfUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessSelfServiceEmployeeProfile()) {
            throw ValidationException::withMessages([
                'user' => ['Unauthorized.'],
            ]);
        }

        return $user;
    }

    /**
     * @return array<int, string|\Closure>
     */
    protected function requestRelations(): array
    {
        return [
            'user:id,name,position,profile_image,role,department_id,branch_id,company_id',
            'workingSchedule:id,name,time_in,time_out,break_start,break_end,rest_days,grace_period_minutes',
            'filedBy:id,name,profile_image',
            'firstApprover:id,name,profile_image',
            'secondApprover:id,name,profile_image',
            'rejectedBy:id,name',
            'approvalAudits' => fn ($query) => $query->with('actor:id,name')->orderBy('created_at'),
        ];
    }

    /**
     * Approved schedule change that will apply on {@see User::$pending_schedule_effective_from}.
     *
     * @return array<string, mixed>|null
     */
    protected function pendingScheduleChangeSummary(User $user): ?array
    {
        $user->loadMissing('pendingWorkingSchedule');
        if (! $user->pending_working_schedule_id || ! $user->pending_schedule_effective_from) {
            return null;
        }

        $pending = $user->pendingWorkingSchedule;
        if (! $pending) {
            return null;
        }

        return [
            'effective_from' => $user->pending_schedule_effective_from->toDateString(),
            'schedule' => $this->workingScheduleSummary($pending),
        ];
    }

    protected function currentScheduleSummary(User $user): ?array
    {
        $user->loadMissing('workingSchedule');
        if ($user->workingSchedule) {
            return $this->workingScheduleSummary($user->workingSchedule);
        }

        $resolved = EmployeeScheduleResolver::resolve($user);
        if (! is_array($resolved) || $resolved === []) {
            return null;
        }

        return $this->scheduleSummaryFromResolvedDays($resolved);
    }

    protected function workingScheduleSummary(WorkingSchedule $schedule): array
    {
        $restDays = collect($schedule->rest_days ?? [])
            ->map(fn ($day) => strtoupper((string) $day))
            ->values()
            ->all();

        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'status' => 'Active',
            'time_in' => $schedule->time_in,
            'time_out' => $schedule->time_out,
            'break_start' => $schedule->break_start,
            'break_end' => $schedule->break_end,
            'grace_period_minutes' => $schedule->grace_period_minutes,
            'rest_days' => $schedule->rest_days ?? [],
            'rest_days_label' => $restDays !== [] ? implode(', ', $restDays) : 'None',
            'work_days_label' => $this->workDaysLabel($schedule->rest_days ?? []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function approvalChainPreview(User $user): array
    {
        $preview = [[
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'submitter_name' => $user->name,
            'profile_image_url' => $user->profile_image_url,
            'acted_at' => null,
            'remarks' => null,
            'approver_name' => null,
            'approver_role_label' => null,
        ]];

        $firstApprover = $this->approvalChainResolver->resolveFirstLevelApprover($user);
        if ($firstApprover) {
            $preview[] = [
                'key' => 'line_approval',
                'label' => $this->lineApproverLabel($user).' approval',
                'status' => 'pending',
                'approver_name' => $firstApprover->name,
                'profile_image_url' => $firstApprover->profile_image_url,
                'acted_at' => null,
                'remarks' => null,
                'submitter_name' => null,
                'approver_role_label' => $this->lineApproverLabel($user),
            ];
        }

        $hrApprover = $this->approvalChainResolver->resolveHrApprover();
        $preview[] = [
            'key' => 'hr_final',
            'label' => 'Admin (HR) final approval',
            'status' => 'pending',
            'approver_name' => $hrApprover?->name,
            'profile_image_url' => $hrApprover?->profile_image_url,
            'acted_at' => null,
            'remarks' => null,
            'submitter_name' => null,
            'approver_role_label' => 'Admin (HR)',
        ];

        return $preview;
    }

    private function lineApproverLabel(User $user): string
    {
        return match ($this->hrRoleResolver->resolveForApprovalSubject($user)->value) {
            'department_head' => 'Branch Head',
            'branch_head' => 'Company Head',
            'company_head', 'admin_hr' => 'Admin (HR)',
            default => 'Department Head',
        };
    }

    private function workDaysLabel(array $restDays): string
    {
        $all = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $work = array_values(array_diff($all, $restDays));

        return implode(', ', array_map(fn ($day) => strtoupper($day), $work));
    }

    /**
     * Build a My Schedule card payload from legacy/custom per-day schedule JSON.
     *
     * This keeps the page useful for older assignments while newer assignments
     * continue to come from the live `working_schedule_id` relationship.
     *
     * @param  array<string, array<string, mixed>|null>  $resolved
     * @return array<string, mixed>|null
     */
    private function scheduleSummaryFromResolvedDays(array $resolved): ?array
    {
        $orderedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $restDays = [];
        $workDays = [];
        $timeIn = null;
        $timeOut = null;
        $breakStart = null;
        $breakEnd = null;
        $graceMinutes = 0;

        foreach ($orderedDays as $day) {
            $dayConfig = $resolved[$day] ?? null;
            if (! is_array($dayConfig) || trim((string) ($dayConfig['in'] ?? '')) === '') {
                $restDays[] = $day;

                continue;
            }

            $workDays[] = $day;
            $timeIn ??= (string) ($dayConfig['in'] ?? '');
            $timeOut ??= (string) ($dayConfig['out'] ?? '');
            $breakStart ??= ($dayConfig['break_start'] ?? null);
            $breakEnd ??= ($dayConfig['break_end'] ?? null);
            $graceMinutes = (int) ($dayConfig['grace_period_minutes'] ?? $dayConfig['grace_minutes'] ?? $graceMinutes);
        }

        if ($workDays === [] || $timeIn === null || $timeOut === null) {
            return null;
        }

        $restLabels = array_map(fn ($day) => strtoupper($day), $restDays);
        $workLabels = array_map(fn ($day) => strtoupper($day), $workDays);

        return [
            'id' => null,
            'name' => 'Assigned Schedule',
            'status' => 'Active',
            'time_in' => $timeIn,
            'time_out' => $timeOut,
            'break_start' => $breakStart,
            'break_end' => $breakEnd,
            'grace_period_minutes' => $graceMinutes,
            'rest_days' => $restDays,
            'rest_days_label' => $restLabels !== [] ? implode(', ', $restLabels) : 'None',
            'work_days_label' => implode(', ', $workLabels),
        ];
    }

    /**
     * Live template row or pending custom snapshot (same shape as {@see workingScheduleSummary()}).
     *
     * @return array<string, mixed>|null
     */
    protected function resolvedRequestedSchedule(ScheduleRequest $request): ?array
    {
        if ($request->workingSchedule) {
            return $this->workingScheduleSummary($request->workingSchedule);
        }
        $payload = $request->custom_schedule_payload;
        if (is_array($payload) && $payload !== []) {
            return $this->scheduleRequestPayloadService->summaryFromPayload($payload);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapScheduleRequestRow(ScheduleRequest $request, User $actor): array
    {
        $request->loadMissing($this->requestRelations());

        return [
            'id' => $request->id,
            'user_id' => $request->user_id,
            'request_kind' => $request->request_kind ?? ScheduleRequest::KIND_TEMPLATE,
            'custom_schedule_payload' => $request->custom_schedule_payload,
            'effective_from' => $request->effective_from?->toDateString(),
            'employee_name' => $request->user?->name,
            'requested_by_name' => $request->user?->name,
            'requested_by_position' => $request->user?->position,
            'requested_by_profile_image_url' => $request->user?->profile_image_url,
            'requested_by_hr_role' => $this->hrRoleResolver->resolveForApprovalSubject($request->user)->value,
            'requested_by_role_label' => $this->hrRoleResolver->resolveForApprovalSubject($request->user)->badgeLabel(),
            'working_schedule' => $this->resolvedRequestedSchedule($request),
            'remarks' => $request->remarks,
            'status' => $request->status,
            'pending_approval' => (bool) $request->pending_approval,
            'approval_stage' => $request->approval_stage,
            'display_status' => $this->scheduleApprovalService->deriveDisplayStatusLabel($request),
            'filed_at' => $request->filed_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'rejection_note' => $request->rejection_note,
            'approval_progress' => $this->mergeAuditRemarksIntoProgress(
                $request,
                $this->scheduleApprovalService->buildApprovalProgress($request)
            ),
            'approval_history' => $request->approvalAudits->map(function (ScheduleRequestApprovalAudit $audit) {
                return [
                    'action' => $audit->action,
                    'approver_role' => $audit->approver_role,
                    'details' => $audit->details,
                    'at' => $audit->created_at?->toIso8601String(),
                    'actor_name' => $audit->actor?->name,
                ];
            })->values()->all(),
            'actor_can_approve' => $this->scheduleApprovalService->canApprove($actor, $request),
            'actor_can_reject' => $this->scheduleApprovalService->canReject($actor, $request),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    protected function mergeAuditRemarksIntoProgress(ScheduleRequest $request, array $steps): array
    {
        if (! $request->relationLoaded('approvalAudits')) {
            return $steps;
        }

        $audits = $request->approvalAudits;
        foreach ($steps as $i => $step) {
            $key = $step['key'] ?? '';
            $remarks = null;
            if ($key === 'submitted') {
                $remarks = $audits->firstWhere('action', 'file')?->details;
            } elseif ($key === 'line_approval') {
                $remarks = $audits->firstWhere('action', 'approve_first')?->details;
            } elseif ($key === 'hr_final') {
                $remarks = $audits->firstWhere('action', 'approve_final')?->details
                    ?? $audits->firstWhere('action', 'reject')?->details;
            }
            $steps[$i]['remarks'] = $remarks;
        }

        return $steps;
    }
}
