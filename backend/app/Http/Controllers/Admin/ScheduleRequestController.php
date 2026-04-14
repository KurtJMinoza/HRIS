<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\MyScheduleController;
use App\Models\ScheduleRequest;
use App\Models\ScheduleRequestApprovalAudit;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\ScheduleApprovalService;
use App\Services\ScheduleRequestPayloadService;
use App\Services\UserScheduleAssignmentService;
use App\Support\HrApprovalStages;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleRequestController extends MyScheduleController
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly ScheduleApprovalService $approvalService,
        private readonly HrRoleResolver $roleResolver,
        private readonly UserScheduleAssignmentService $assignmentService,
        \App\Services\HrApprovalChainResolver $approvalChainResolver,
        ScheduleApprovalService $scheduleApprovalService,
        HrRoleResolver $hrRoleResolver,
        ScheduleRequestPayloadService $scheduleRequestPayloadService,
    ) {
        parent::__construct($approvalChainResolver, $scheduleApprovalService, $hrRoleResolver, $scheduleRequestPayloadService);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ScheduleRequest::query()
            ->with($this->requestRelations())
            ->orderByDesc('created_at');

        $scope = User::query()->where('role', User::ROLE_EMPLOYEE);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $scope);
        $query->whereIn('user_id', $scope->select('users.id'));

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $requests = $query->get()
            ->map(fn (ScheduleRequest $row) => $this->mapScheduleRequestRow($row, $request->user()))
            ->values();

        return response()->json([
            'requests' => $requests,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $scheduleRequest = ScheduleRequest::query()
            ->with($this->requestRelations())
            ->findOrFail($id);

        $this->dataScopeService->ensureEmployeeAccessible($request->user(), $scheduleRequest->user);

        return response()->json([
            'request' => $this->mapScheduleRequestRow($scheduleRequest, $request->user()),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $scheduleRequest = ScheduleRequest::query()
            ->with($this->requestRelations())
            ->findOrFail($id);

        $this->dataScopeService->ensureEmployeeAccessible($actor, $scheduleRequest->user);
        if (! $this->approvalService->canApprove($actor, $scheduleRequest)) {
            throw ValidationException::withMessages([
                'request' => ['You are not the current approver for this schedule request.'],
            ]);
        }

        $remarks = isset($validated['remarks']) ? trim((string) $validated['remarks']) : null;
        $stage = $scheduleRequest->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $action = 'approve_first';

        DB::transaction(function () use (&$scheduleRequest, $actor, $stage, &$action) {
            /** @var ScheduleRequest $locked */
            $locked = ScheduleRequest::query()->with($this->requestRelations())->lockForUpdate()->findOrFail($scheduleRequest->id);

            if ($stage === HrApprovalStages::PENDING_FIRST) {
                $locked->first_approver_id = $actor->id;
                $locked->first_approved_at = now();
                $locked->approval_stage = HrApprovalStages::PENDING_SECOND;
                $locked->save();
                $scheduleRequest = $locked;

                return;
            }

            $locked->second_approver_id = $actor->id;
            $locked->second_approved_at = now();
            $locked->approval_stage = HrApprovalStages::APPROVED;
            $locked->status = ScheduleRequest::STATUS_APPROVED;
            $locked->pending_approval = false;

            $this->finalizeCustomScheduleIfNeeded($locked);
            $locked->save();

            // Force fresh relation reload (loadMissing would keep the stale pre-approval null relation).
            $locked->unsetRelation('workingSchedule');
            $locked->load('workingSchedule');
            if (! $locked->workingSchedule) {
                throw ValidationException::withMessages([
                    'request' => ['Approved request has no resolvable working schedule.'],
                ]);
            }

            $employee = $locked->user()->firstOrFail();
            $tz = config('attendance.timezone', 'Asia/Manila');
            $effectiveSource = $locked->effective_from ?? $locked->created_at;
            $effective = Carbon::parse($effectiveSource)->timezone($tz)->startOfDay();
            $today = Carbon::now($tz)->startOfDay();
            if ($effective->lte($today)) {
                $this->assignmentService->assign($employee, $locked->workingSchedule);
                $employee->forceFill([
                    'pending_working_schedule_id' => null,
                    'pending_schedule_effective_from' => null,
                ])->save();
            } else {
                $employee->forceFill([
                    'pending_working_schedule_id' => $locked->working_schedule_id,
                    'pending_schedule_effective_from' => $locked->effective_from,
                ])->save();
            }
            $action = 'approve_final';
            $scheduleRequest = $locked;
        });

        ScheduleRequestApprovalAudit::create([
            'schedule_request_id' => $scheduleRequest->id,
            'actor_id' => $actor->id,
            'employee_id' => $scheduleRequest->user_id,
            'action' => $action,
            'details' => $remarks,
            'approver_role' => $this->roleResolver->resolve($actor)->value,
        ]);

        $scheduleRequest->load($this->requestRelations());

        return response()->json([
            'message' => $action === 'approve_final' ? 'Schedule request approved and assigned.' : 'Schedule request advanced to HR approval.',
            'request' => $this->mapScheduleRequestRow($scheduleRequest, $actor),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        $scheduleRequest = ScheduleRequest::query()
            ->with($this->requestRelations())
            ->findOrFail($id);

        $this->dataScopeService->ensureEmployeeAccessible($actor, $scheduleRequest->user);
        if (! $this->approvalService->canReject($actor, $scheduleRequest)) {
            throw ValidationException::withMessages([
                'request' => ['You are not the current approver for this schedule request.'],
            ]);
        }

        $remarks = trim((string) $validated['remarks']);
        $scheduleRequest->status = ScheduleRequest::STATUS_REJECTED;
        $scheduleRequest->approval_stage = HrApprovalStages::REJECTED;
        $scheduleRequest->pending_approval = false;
        $scheduleRequest->rejected_at = now();
        $scheduleRequest->rejected_by = $actor->id;
        $scheduleRequest->rejection_note = $remarks;
        $scheduleRequest->save();

        ScheduleRequestApprovalAudit::create([
            'schedule_request_id' => $scheduleRequest->id,
            'actor_id' => $actor->id,
            'employee_id' => $scheduleRequest->user_id,
            'action' => 'reject',
            'details' => $remarks,
            'approver_role' => $this->roleResolver->resolve($actor)->value,
        ]);

        $scheduleRequest->load($this->requestRelations());

        return response()->json([
            'message' => 'Schedule request rejected.',
            'request' => $this->mapScheduleRequestRow($scheduleRequest, $actor),
        ]);
    }
}
