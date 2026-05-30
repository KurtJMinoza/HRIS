<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ProcessesBulkApproval;
use App\Http\Controllers\Controller;
use App\Models\LeaveApprovalAudit;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\BulkApproval\LeaveBulkApprovalQuery;
use App\Services\DataScopeService;
use App\Services\HrApprovalChainResolver;
use App\Services\HrRoleResolver;
use App\Services\LeaveApprovalService;
use App\Services\LeaveCreditService;
use App\Services\OrgApprovalWorkflowService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\HrApprovalStages;
use App\Support\LeaveModuleCache;
use App\Support\LeaveFilingRules;
use App\Support\LeaveScheduleSupport;
use App\Support\RequestPerformanceLogger;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LeaveController extends Controller
{
    use ProcessesBulkApproval;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly LeaveApprovalService $leaveApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly HrApprovalChainResolver $hrApprovalChainResolver,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly LeaveBulkApprovalQuery $bulkApprovalQuery,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
    ) {}

    /**
     * List leave requests. Optional filter: status = pending | approved | rejected.
     */
    public function index(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.leave.index');
        $status = $request->query('status');
        $actor = $request->user();
        $perPage = $this->requestPerPage($request);
        $page = max(1, (int) $request->query('page', 1));

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cacheKey = $this->leaveListCacheKey($actor, $request, $perPage, $page);
        $cacheHit = false;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cacheHit = true;
                RequestPerformanceLogger::finish($perf, $request, count($cached['leave_requests'] ?? []), [
                    'scope' => 'admin',
                    'per_page' => $perPage,
                    'total' => $cached['pagination']['total'] ?? null,
                    'cache' => 'hit',
                    'cache_hit' => true,
                ]);

                return response()->json($cached);
            }
        } catch (\Throwable $e) {
            Log::warning('leave.list.cache_read_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        $query = LeaveRequest::query()
            ->select([
                'id',
                'user_id',
                'company_id',
                'department_id',
                'type',
                'start_date',
                'end_date',
                'undertime_time',
                'undertime_minutes',
                'half_type',
                'status',
                'notes',
                'rejection_note',
                'approval_stage',
                'pending_approval',
                'filed_by',
                'first_approver_id',
                'second_approver_id',
                'rejected_at',
                'created_at',
            ])
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,company_id,department_id',
            ]);

        $this->applyFilingApprovalVisibility($actor, $query, $request);

        $requestId = $request->query('request_id');
        if ($requestId !== null && $requestId !== '' && ctype_digit((string) $requestId)) {
            $query->where('id', (int) $requestId);
        } elseif (in_array($status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED, 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $this->applyLeaveListFilters($query, $request);

        $paginator = $query->orderByDesc('created_at')->paginate($perPage)->withQueryString();
        $pageLeaves = $paginator->getCollection();
        $currentApprovals = $this->currentLeaveApprovalRecords($pageLeaves->pluck('id')->map(fn ($id) => (int) $id)->all());

        $leaves = $pageLeaves->map(function (LeaveRequest $l) use ($actor, $currentApprovals) {
            $currentApproval = $currentApprovals[(int) $l->id] ?? null;
            $auth = $currentApproval && $l->user
                ? $this->approvalWorkflowService->authorizePendingRecord(
                    $actor,
                    $currentApproval,
                    $l->user,
                    OrgApprovalWorkflowService::MODULE_LEAVE,
                )
                : ['allowed' => false, 'deny_reason' => $l->status === LeaveRequest::STATUS_PENDING ? 'no_pending_approval_step' : 'request_is_not_pending'];

            return [
                'id' => $l->id,
                'request_id' => $l->id,
                'request_no' => 'LV-'.$l->id,
                'employee_id' => $l->user_id,
                'employee_name' => $l->user?->display_name,
                'employee_profile_image' => null,
                'request_type' => 'leave',
                'type' => $l->type,
                'leave_type' => $l->type,
                'start_date' => $l->start_date->toDateString(),
                'end_date' => $l->end_date->toDateString(),
                'leave_dates' => $l->start_date->toDateString() === $l->end_date->toDateString()
                    ? $l->start_date->toDateString()
                    : $l->start_date->toDateString().' - '.$l->end_date->toDateString(),
                'undertime_time' => $l->undertime_time ? substr((string) $l->undertime_time, 0, 5) : null,
                'undertime_minutes' => $l->undertime_minutes,
                'undertime_hours' => $l->undertime_minutes !== null ? round(((int) $l->undertime_minutes) / 60, 2) : null,
                'half_type' => $l->half_type,
                'duration' => $this->leaveDurationSummary($l),
                'status' => $l->status,
                'notes' => $l->notes,
                'rejection_note' => $l->rejection_note,
                'current_approver' => $currentApproval?->approver?->display_name ?? $currentApproval?->approver_name,
                'created_at' => $l->created_at->toIso8601String(),
                'display_status' => $this->leaveListDisplayStatus($l, $currentApproval),
                'approval_stage' => $l->approval_stage,
                'actor_can_approve' => (bool) ($auth['allowed'] ?? false),
                'actor_can_reject' => (bool) ($auth['allowed'] ?? false),
                'can_approve' => (bool) ($auth['allowed'] ?? false),
                'can_reject' => (bool) ($auth['allowed'] ?? false),
                'actor_can_delete' => $this->canDeleteLeaveRequest($actor, $l),
                'hr_wait_message' => $this->leaveListWaitMessage($l, $currentApproval, (bool) ($auth['allowed'] ?? false)),
            ];
        });

        RequestPerformanceLogger::finish($perf, $request, $leaves->count(), [
            'scope' => 'admin',
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'cache' => $cacheHit ? 'hit' : 'miss',
            'cache_hit' => $cacheHit,
        ]);

        $payload = [
            'leave_requests' => $leaves->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        try {
            Cache::put($cacheKey, $payload, now()->addSeconds(45));
        } catch (\Throwable $e) {
            Log::warning('leave.list.cache_write_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json($payload);
    }

    public function counts(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.leave.counts');
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $filters = $this->normalizedLeaveListFilters($request);
        unset($filters['status'], $filters['page'], $filters['per_page']);
        $hash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $cacheKey = 'leave:counts:'.LeaveModuleCache::version().':'.$actor->id.':'.$hash;
        $cacheHit = false;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cacheHit = true;
                RequestPerformanceLogger::finish($perf, $request, 1, [
                    'scope' => 'admin',
                    'cache' => 'hit',
                    'cache_hit' => true,
                ]);

                return response()->json($cached);
            }
        } catch (\Throwable $e) {
            Log::warning('leave.counts.cache_read_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        $base = LeaveRequest::query()->select('id', 'user_id', 'status', 'company_id', 'department_id', 'start_date', 'end_date');
        $this->applyFilingApprovalVisibility($actor, $base, $request);
        $this->applyLeaveListFilters($base, $request, includeStatus: false);

        $statusCounts = (clone $base)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $payload = [
            'pending' => (int) ($statusCounts[LeaveRequest::STATUS_PENDING] ?? 0),
            'approved' => (int) ($statusCounts[LeaveRequest::STATUS_APPROVED] ?? 0),
            'rejected' => (int) ($statusCounts[LeaveRequest::STATUS_REJECTED] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'my_filings' => (int) (clone $base)->where('user_id', (int) $actor->id)->count(),
            'all_filings' => (int) (clone $base)->count(),
        ];

        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'cache' => $cacheHit ? 'hit' : 'miss',
            'cache_hit' => $cacheHit,
        ]);

        try {
            Cache::put($cacheKey, $payload, now()->addSeconds(45));
        } catch (\Throwable $e) {
            Log::warning('leave.counts.cache_write_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json($payload);
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
        $employee = User::query()->approvableEmployees()->active()->findOrFail($validated['user_id']);
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

        $stage = $this->hrApprovalChainResolver->initialApprovalStage(
            $employee,
            employeeSubmitted: false,
            requestType: OrgApprovalWorkflowService::MODULE_LEAVE,
        );

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
            'filed_by' => $actor->id,
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

        $this->approvalWorkflowService->ensureRecordsForRequest(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $employee,
            $actor,
        );
        $leave->refresh();
        LeaveModuleCache::flush();

        return response()->json([
            'message' => 'Leave request created.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'filedBy', 'firstApprover', 'secondApprover', 'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ]), $request->user()),
        ], 201);
    }

    /**
     * Lightweight review payload for dashboard deep-links and modal detail views.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.leave.review');
        $actor = $request->user();
        $started = microtime(true);

        Log::info('admin.leave.review_start', [
            'leave_request_id' => $id,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'hr_role' => $actor ? $this->hrRoleResolver->resolve($actor)->value : null,
        ]);

        $leave = LeaveRequest::query()
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,profile_image,employee_code,department_id,section_unit_id,department,role',
                'reviewedByUser:id,name,first_name,middle_name,last_name,suffix',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,role',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix',
                'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
                'approvalAudits' => fn ($q) => $q
                    ->orderBy('created_at')
                    ->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ])
            ->find($id);

        if ($leave === null) {
            Log::warning('admin.leave.review_not_found', [
                'leave_request_id' => $id,
                'actor_id' => $actor?->id,
            ]);

            return response()->json(['message' => 'Leave request not found.'], 404);
        }

        try {
            $this->ensureLeaveRequestReviewAccessible($actor, $leave);
        } catch (HttpResponseException $e) {
            Log::warning('admin.leave.review_forbidden', [
                'leave_request_id' => $id,
                'actor_id' => $actor?->id,
                'employee_id' => $leave->user_id,
            ]);

            throw $e;
        }

        try {
            $cached = ReviewRequestCache::remember('leave', $id, fn () => $this->leaveReviewResponse($leave, $actor));
            $payload = $cached['payload'];
        } catch (\Throwable $e) {
            Log::error('admin.leave.review_failed', [
                'leave_request_id' => $id,
                'actor_id' => $actor?->id,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }

        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'mode' => 'review',
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'cache_hit' => $cached['cache_hit'] ?? false,
            'query_count' => $cached['query_count'] ?? null,
            'cache_error' => $cached['cache_error'] ?? null,
        ]);

        Log::info('admin.leave.review_ok', [
            'leave_request_id' => $id,
            'actor_id' => $actor?->id,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'cache_hit' => $cached['cache_hit'] ?? false,
            'query_count' => $cached['query_count'] ?? null,
        ]);

        return response()->json(['leave_request' => $payload]);
    }

    /**
     * Lean permission-aware review payload for modal shells and dashboard deep-links.
     */
    public function reviewLite(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.leave.review_lite');
        $actor = $request->user();
        $started = microtime(true);

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cached = ReviewRequestCache::rememberLeaveReviewLite($id, (int) $actor->id, function () use ($actor, $id): array {
            $leave = LeaveRequest::query()
                ->select([
                    'id',
                    'user_id',
                    'type',
                    'start_date',
                    'end_date',
                    'undertime_time',
                    'undertime_minutes',
                    'half_type',
                    'status',
                    'notes',
                    'rejection_note',
                    'approval_stage',
                    'pending_approval',
                    'filed_at',
                    'filed_by',
                    'first_approver_id',
                    'second_approver_id',
                    'rejected_at',
                    'created_at',
                ])
                ->with([
                    'user:id,name,first_name,middle_name,last_name,suffix',
                    'filedBy:id,name,first_name,middle_name,last_name,suffix',
                ])
                ->findOrFail($id);

            $this->ensureLeaveRequestReviewAccessible($actor, $leave);

            $approvalRecords = OrgApprovalRecord::query()
                ->select([
                    'id',
                    'request_id',
                    'module_type',
                    'approval_level',
                    'approval_label',
                    'approver_role',
                    'approver_id',
                    'approver_name',
                    'eligible_approver_ids',
                    'approval_status',
                    'remarks',
                    'approved_at',
                    'sequence_order',
                ])
                ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
                ->where('request_id', (int) $leave->id)
                ->with('approver:id,name,first_name,middle_name,last_name,suffix')
                ->orderBy('sequence_order')
                ->orderBy('id')
                ->get();

            $pending = $approvalRecords->firstWhere('approval_status', OrgApprovalRecord::STATUS_PENDING);
            $auth = $this->leaveReviewLiteActionAuthorization($actor, $leave, $pending);

            return $this->leaveReviewLiteResponse($leave, $approvalRecords, $pending, $auth);
        });

        $payload = $cached['payload'];
        $durationMs = round((microtime(true) - $started) * 1000, 2);

        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'mode' => 'review_lite',
            'duration_ms' => $durationMs,
            'cache_hit' => $cached['cache_hit'] ?? false,
            'query_count' => $cached['query_count'] ?? null,
            'cache_error' => $cached['cache_error'] ?? null,
        ]);

        Log::info('admin.leave.review_lite_ok', [
            'request_id' => $id,
            'endpoint_response_time_ms' => $durationMs,
            'query_count' => $cached['query_count'] ?? null,
            'cache' => ($cached['cache_hit'] ?? false) ? 'hit' : 'miss',
            'cache_hit' => $cached['cache_hit'] ?? false,
            'current_user_employee_id' => (int) $actor->id,
            'current_approver_id' => $payload['current_approver_id'] ?? null,
            'can_approve' => $payload['can_approve'] ?? false,
            'can_reject' => $payload['can_reject'] ?? false,
            'deny_reason' => $payload['deny_reason'] ?? null,
        ]);

        return response()->json(['leave_request' => $payload]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.leave.show');
        $actor = $request->user();
        $leave = LeaveRequest::query()
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,profile_image,position,schedule,working_schedule_id,role,department_id,department,branch_id,company_id,division_id,section_unit_id',
                'reviewedByUser:id,name,first_name,middle_name,last_name,suffix',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image,position,role,department_id,branch_id,company_id,division_id,section_unit_id',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ])
            ->findOrFail($id);

        if (! $this->canAccessLeaveThroughFilingScope($actor, $leave)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $payload = $this->leaveResponse($leave, $actor);
        RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin']);

        return response()->json(['leave_request' => $payload]);
    }

    /**
     * Approve a leave request (line manager first, then HR final).
     */
    public function bulkApprovePreview(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'filters' => ['required', 'array'],
        ]);

        $filters = $this->normalizeBulkApproveFilters($validated['filters']);
        $count = $this->bulkApprovalQuery->approvableCount($actor, $filters);

        return response()->json([
            'approvable_count' => $count,
        ]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $parsed = $this->parseBulkApproveRequest($request);

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $skipped = 0;
        $failedItems = [];

        if ($parsed['mode'] === 'all_matching') {
            $filters = $this->normalizeBulkApproveFilters($parsed['filters']);
            $ids = $this->bulkApprovalQuery->approvableIds($actor, $filters);
        } else {
            $this->assertBulkApproveIdsPresent($parsed['ids']);
            if (count($parsed['ids']) > 500) {
                throw ValidationException::withMessages([
                    'ids' => ['Too many requests selected. Use “select all matching” or approve in smaller batches.'],
                ]);
            }
            $resolved = $this->resolveBulkApproveIds($parsed['ids'], function (int $id) use ($actor): bool {
                $leave = LeaveRequest::query()->with(['user', 'filedBy'])->find($id);

                return $leave !== null && $this->leaveApprovalService->canApprove($actor, $leave);
            });
            $ids = $resolved['ids'];
            $skipped = $resolved['skipped'];
            $failedItems = $resolved['failed_items'];
        }

        if (count($ids) === 0) {
            return $this->bulkApproveJsonResponse(0, $skipped, 0, $failedItems, 'leave request');
        }

        $remarks = $parsed['remarks'];
        $approved = 0;
        $failed = 0;
        $leaveBulkExtra = $this->leaveBulkApproveExtraInput(
            $remarks,
            $actor,
            fn (User $user) => $this->hrRoleResolver->isAdminHrAccount($user),
        );

        foreach ($ids as $id) {
            try {
                $single = $this->duplicateBulkApproveRequest($request, $remarks, $leaveBulkExtra);
                $single->setUserResolver(fn () => $actor);
                $response = $this->approve($single, $id);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $approved++;
                    continue;
                }

                $body = $response->getData(true);
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => (string) ($body['message'] ?? 'Leave request was skipped.'),
                ];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => 'Leave request was not found.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : ($e->getMessage() ?: 'Bulk approval failed for this leave request.'),
                ];
            }
        }

        return $this->bulkApproveJsonResponse($approved, $skipped, $failed, $failedItems, 'leave request');
    }

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
        if (! $this->canAccessLeaveThroughFilingScope($actor, $leave)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
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

        $notes = $validated['notes'] ?? null;
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();
        $currentApproval = $this->approvalWorkflowService->currentPendingRecord(
            $leave,
            OrgApprovalWorkflowService::MODULE_LEAVE,
            $leave->user,
            $leave->filedBy,
        );
        $isHrFinalStep = $currentApproval?->approver_role === \App\Enums\HrRole::AdminHr->value;

        if (! $isHrFinalStep) {
            try {
                $this->payrollPeriodMutationGuard->assertMutableForUserWindow(
                    (int) $leave->user_id,
                    Carbon::parse($leave->start_date)->startOfDay(),
                    Carbon::parse($leave->end_date)->startOfDay()
                );
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $nextPending = DB::transaction(function () use ($leave, $actor, $notes, $roleLabel, $applyBypass, $request) {
                $leave->refresh();
                $nextPending = $this->approvalWorkflowService->approveCurrent(
                    $leave,
                    OrgApprovalWorkflowService::MODULE_LEAVE,
                    $leave->user,
                    $actor,
                    $notes,
                    $leave->filedBy,
                );

                if ($applyBypass) {
                    $leave->rest_day_bypass = true;
                    $leave->rest_day_bypass_reason = trim((string) $request->input('rest_day_bypass_reason'));
                    $leave->rest_day_bypass_by = $actor->id;
                    $leave->rest_day_bypass_at = now();
                }
                if ($leave->first_approver_id === null) {
                    $leave->first_approver_id = $actor->id;
                }
                if ($nextPending?->approver_role === \App\Enums\HrRole::AdminHr->value) {
                    $leave->first_approved_at = now();
                    $leave->approval_stage = HrApprovalStages::PENDING_SECOND;
                } else {
                    $leave->approval_stage = HrApprovalStages::PENDING_FIRST;
                }
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

                return $nextPending;
            });

            $nextLabel = $nextPending?->approver_role
                ? (\App\Enums\HrRole::tryFrom((string) $nextPending->approver_role)?->badgeLabel() ?? 'next approver')
                : 'next approver';

            ReviewRequestCache::forget('leave', (int) $leave->id);
            LeaveModuleCache::flush();

            if ($this->wantsLiteLeaveMutationResponse($request)) {
                return response()->json([
                    'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                    'request_id' => (int) $leave->id,
                    'status' => $leave->status,
                    'approval_stage' => $leave->approval_stage,
                ]);
            }

            return response()->json([
                'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                'leave_request' => $this->leaveResponse($leave->fresh([
                    'user', 'filedBy', 'firstApprover', 'secondApprover',
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
                ]), $actor),
            ]);
        }

        if (! $isHrFinalStep) {
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
                $this->approvalWorkflowService->approveCurrent(
                    $leave,
                    OrgApprovalWorkflowService::MODULE_LEAVE,
                    $leave->user,
                    $actor,
                    $validated['notes'] ?? null,
                    $leave->filedBy,
                );

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

        ReviewRequestCache::forget('leave', (int) $leave->id);
        LeaveModuleCache::flush();

        if ($this->wantsLiteLeaveMutationResponse($request)) {
            return response()->json([
                'message' => 'Leave request approved.',
                'request_id' => (int) $leave->id,
                'status' => LeaveRequest::STATUS_APPROVED,
                'approval_stage' => HrApprovalStages::APPROVED,
            ]);
        }

        return response()->json([
            'message' => 'Leave request approved.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
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
        if (! $this->canAccessLeaveThroughFilingScope($actor, $leave)) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Leave request is not pending.'], 422);
        }

        if (! $this->leaveApprovalService->canReject($actor, $leave)) {
            return response()->json(['message' => 'You are not authorized to reject at this stage.'], 403);
        }

        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();

        DB::transaction(function () use ($leave, $actor, $validated, $roleLabel) {
            $this->approvalWorkflowService->rejectCurrent(
                $leave,
                OrgApprovalWorkflowService::MODULE_LEAVE,
                $leave->user,
                $actor,
                $validated['reason'],
                $leave->filedBy,
            );

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

        ReviewRequestCache::forget('leave', (int) $leave->id);
        LeaveModuleCache::flush();

        if ($this->wantsLiteLeaveMutationResponse($request)) {
            return response()->json([
                'message' => 'Leave request rejected.',
                'request_id' => (int) $leave->id,
                'status' => LeaveRequest::STATUS_REJECTED,
                'approval_stage' => HrApprovalStages::REJECTED,
            ]);
        }

        return response()->json([
            'message' => 'Leave request rejected.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover', 'rejectedBy',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
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
        ReviewRequestCache::forget('leave', (int) $leave->id);
        LeaveModuleCache::flush();

        return response()->json([
            'message' => 'Notes updated.',
            'leave_request' => $this->leaveResponse($leave->fresh([
                'user:id,name,first_name,middle_name,last_name,suffix', 'reviewedByUser:id,name,first_name,middle_name,last_name,suffix',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ]), $request->user()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $leave = LeaveRequest::query()->with('user')->findOrFail($id);
        if ($leave->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $leave->user);
        }

        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending leave requests can be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->canDeleteLeaveRequest($actor, $leave)) {
            return response()->json([
                'message' => 'You can only delete leave requests you created or requests filed for you.',
            ], Response::HTTP_FORBIDDEN);
        }

        foreach ($leave->resolveDocumentPaths() as $path) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        $leave->delete();
        ReviewRequestCache::forget('leave', (int) $leave->id);
        LeaveModuleCache::flush();

        return response()->json([
            'message' => 'Leave request deleted.',
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
            if ($remarks !== null) {
                $steps[$i]['remarks'] = $remarks;
            }
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
            'actor_can_delete' => $this->canDeleteLeaveRequest($actor, $leave),
            'hr_wait_message' => $hrWait,
        ];
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

    private function requestPerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 25);

        return in_array($perPage, [10, 25, 50], true) ? $perPage : 25;
    }

    private function wantsLiteLeaveMutationResponse(Request $request): bool
    {
        return str_starts_with($request->path(), 'api/leave-requests/');
    }

    private function leaveListCacheKey(User $actor, Request $request, int $perPage, int $page): string
    {
        $filters = $this->normalizedLeaveListFilters($request);
        $filters['per_page'] = $perPage;
        $filters['page'] = $page;
        $hash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $company = (string) ($filters['company_id'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');

        return 'leave:list:'.((int) $actor->id).':'.$company.':'.$status.':'.$page.':'.$hash.':v'.LeaveModuleCache::version();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedLeaveListFilters(Request $request): array
    {
        return array_filter([
            'status' => $request->query('status'),
            'employee_id' => $request->query('employee_id') ?? $request->query('user_id'),
            'company_id' => $request->query('company_id'),
            'department_id' => $request->query('department_id'),
            'date_from' => $request->query('date_from') ?? $request->query('from_date'),
            'date_to' => $request->query('date_to') ?? $request->query('to_date'),
            'search' => is_string($request->query('search')) ? trim((string) $request->query('search')) : null,
            'request_id' => $request->query('request_id'),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<LeaveRequest>  $query
     */
    private function applyLeaveListFilters($query, Request $request, bool $includeStatus = false): void
    {
        $filters = $this->normalizedLeaveListFilters($request);

        if ($includeStatus && isset($filters['status']) && in_array($filters['status'], [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_REJECTED, 'cancelled'], true)) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['employee_id']) && ctype_digit((string) $filters['employee_id'])) {
            $query->where('user_id', (int) $filters['employee_id']);
        }

        if (isset($filters['company_id']) && ctype_digit((string) $filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (isset($filters['department_id']) && ctype_digit((string) $filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        if (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->where('end_date', '>=', $from);
        }
        if (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->where('start_date', '<=', $to);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($scope) use ($like, $search): void {
                if (ctype_digit($search)) {
                    $scope->orWhere('id', (int) $search);
                }
                $scope->orWhereHas('user', function ($userQuery) use ($like): void {
                    $userQuery->where('name', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            });
        }
    }

    /**
     * @param  list<int>  $requestIds
     * @return array<int, OrgApprovalRecord>
     */
    private function currentLeaveApprovalRecords(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return OrgApprovalRecord::query()
            ->select([
                'id',
                'request_id',
                'module_type',
                'approval_label',
                'approver_role',
                'approver_id',
                'approver_name',
                'eligible_approver_ids',
                'approval_status',
                'sequence_order',
            ])
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
            ->where('approval_status', OrgApprovalRecord::STATUS_PENDING)
            ->whereIn('request_id', $requestIds)
            ->with('approver:id,name,first_name,middle_name,last_name,suffix')
            ->orderBy('sequence_order')
            ->orderBy('id')
            ->get()
            ->unique('request_id')
            ->mapWithKeys(fn (OrgApprovalRecord $record): array => [(int) $record->request_id => $record])
            ->all();
    }

    private function leaveListDisplayStatus(LeaveRequest $leave, ?OrgApprovalRecord $currentApproval): string
    {
        if ($leave->status === LeaveRequest::STATUS_APPROVED) {
            return 'HR Approved';
        }
        if ($leave->status === LeaveRequest::STATUS_REJECTED || $leave->rejected_at !== null) {
            return 'Rejected';
        }
        if ($currentApproval) {
            return 'Pending '.rtrim(str_ireplace(' approval', '', $this->approvalRecordStageLabel($currentApproval))).' Approval';
        }

        return 'Pending';
    }

    private function leaveListWaitMessage(LeaveRequest $leave, ?OrgApprovalRecord $currentApproval, bool $canAct): ?string
    {
        if ($leave->status !== LeaveRequest::STATUS_PENDING || $canAct || ! $currentApproval) {
            return null;
        }

        return 'Waiting for '.$this->approvalRecordStageLabel($currentApproval).'.';
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

    private function ensureLeaveRequestReviewAccessible(?User $actor, LeaveRequest $leave): void
    {
        if ($actor === null) {
            throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
        }

        if ($actor->isAdmin() || $this->hrRoleResolver->isAdminHrAccount($actor)) {
            return;
        }

        if ($this->canAccessLeaveThroughFilingScope($actor, $leave)) {
            return;
        }

        throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<LeaveRequest>  $query
     */
    private function applyFilingApprovalVisibility(User $actor, $query, Request $request): void
    {
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        if ($scopedEmployeeIds === null) {
            return;
        }

        $assignedRequestIds = $this->assignedLeaveRequestIdsForActor($actor);
        $actorId = (int) $actor->id;
        $query->where(function ($scope) use ($scopedEmployeeIds, $assignedRequestIds, $actorId): void {
            $scope->whereIn('user_id', $scopedEmployeeIds)
                ->orWhere('first_approver_id', $actorId)
                ->orWhere('second_approver_id', $actorId);

            if ($assignedRequestIds !== []) {
                $scope->orWhereIn('id', $assignedRequestIds);
            }
        });

    }

    /**
     * @return list<int>
     */
    private function assignedLeaveRequestIdsForActor(User $actor): array
    {
        $actorId = (int) $actor->id;
        $ids = LeaveRequest::query()
            ->where(function ($query) use ($actorId): void {
                $query->where('first_approver_id', $actorId)
                    ->orWhere('second_approver_id', $actorId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recordIds = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
            ->where('approver_id', $actorId)
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$recordIds]));
    }

    private function canAccessLeaveThroughFilingScope(User $actor, LeaveRequest $leave): bool
    {
        $actorId = (int) $actor->id;
        if ((int) $leave->user_id === $actorId
            || (int) $leave->filed_by === $actorId
            || (int) $leave->first_approver_id === $actorId
            || (int) $leave->second_approver_id === $actorId
        ) {
            return true;
        }

        if (OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_LEAVE)
            ->where('request_id', (int) $leave->id)
            ->where('approver_id', $actorId)
            ->exists()) {
            return true;
        }

        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);

        return is_array($scopedEmployeeIds) && in_array((int) $leave->user_id, $scopedEmployeeIds, true);
    }

    /**
     * @return array{can_approve: bool, can_reject: bool, deny_reason: ?string}
     */
    private function leaveReviewLiteActionAuthorization(User $actor, LeaveRequest $leave, ?OrgApprovalRecord $pending): array
    {
        if (! $leave->pending_approval || $leave->status !== LeaveRequest::STATUS_PENDING || $leave->rejected_at !== null) {
            return [
                'can_approve' => false,
                'can_reject' => false,
                'deny_reason' => 'request_is_not_pending',
            ];
        }

        if (! $leave->user) {
            return [
                'can_approve' => false,
                'can_reject' => false,
                'deny_reason' => 'request_employee_not_loaded',
            ];
        }

        if (! $pending) {
            return [
                'can_approve' => false,
                'can_reject' => false,
                'deny_reason' => 'no_pending_approval_step',
            ];
        }

        $auth = $this->approvalWorkflowService->authorizePendingRecord(
            $actor,
            $pending,
            $leave->user,
            OrgApprovalWorkflowService::MODULE_LEAVE,
        );

        return [
            'can_approve' => (bool) $auth['allowed'],
            'can_reject' => (bool) $auth['allowed'],
            'deny_reason' => $auth['deny_reason'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrgApprovalRecord>  $approvalRecords
     * @param  array{can_approve: bool, can_reject: bool, deny_reason: ?string}  $auth
     * @return array<string, mixed>
     */
    private function leaveReviewLiteResponse(LeaveRequest $leave, $approvalRecords, ?OrgApprovalRecord $pending, array $auth): array
    {
        $employeeName = $leave->user?->display_name;
        $currentApproverName = $pending
            ? ($pending->approver?->display_name ?? $pending->approver_name ?? $this->approvalRecordRoleLabel($pending))
            : null;

        return [
            'request_id' => (int) $leave->id,
            'employee_name' => $employeeName,
            'employee_initials' => $this->initialsForName($employeeName),
            'leave_type' => $leave->type,
            'start_date' => $leave->start_date?->toDateString(),
            'end_date' => $leave->end_date?->toDateString(),
            'duration' => $this->leaveDurationSummary($leave),
            'date_filed' => ($leave->filed_at ?? $leave->created_at)?->toIso8601String(),
            'status' => $leave->status,
            'current_stage' => $pending ? $this->approvalRecordStageLabel($pending) : $leave->approval_stage,
            'current_approver_id' => $pending?->approver_id,
            'current_approver_name' => $currentApproverName,
            'requester_id' => $leave->user_id,
            'remarks' => $leave->notes,
            'approval_chain' => $approvalRecords->map(fn (OrgApprovalRecord $record): array => [
                'id' => (int) $record->id,
                'role_label' => $this->approvalRecordRoleLabel($record),
                'stage' => $this->approvalRecordStageLabel($record),
                'status' => $record->approval_status,
                'approver_id' => $record->approver_id,
                'approver_name' => $record->approver?->display_name ?? $record->approver_name,
                'remarks' => $record->remarks,
                'approved_at' => $record->approved_at?->toIso8601String(),
                'sequence_order' => (int) $record->sequence_order,
            ])->values()->all(),
            'can_approve' => $auth['can_approve'],
            'can_reject' => $auth['can_reject'],
            'deny_reason' => $auth['deny_reason'],
        ];
    }

    private function approvalRecordStageLabel(OrgApprovalRecord $record): string
    {
        $label = trim((string) ($record->approval_label ?? ''));
        if ($label !== '') {
            return $label;
        }

        if ($record->approver_role === \App\Enums\HrRole::AdminHr->value) {
            return 'Admin HR final approval';
        }

        return $this->approvalRecordRoleLabel($record).' approval';
    }

    private function approvalRecordRoleLabel(OrgApprovalRecord $record): string
    {
        $role = \App\Enums\HrRole::tryFrom((string) $record->approver_role);

        return $role?->badgeLabel() ?? (string) ($record->approver_role ?: 'Approver');
    }

    private function initialsForName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            return '?';
        }
        if (count($parts) === 1) {
            return strtoupper(substr($parts[0], 0, 2));
        }

        return strtoupper(substr($parts[0], 0, 1).substr($parts[count($parts) - 1], 0, 1));
    }

    private function leaveDurationSummary(LeaveRequest $leave): string
    {
        if (! $leave->start_date || ! $leave->end_date) {
            return '—';
        }

        if ($leave->type === 'half_day') {
            $label = $leave->half_type ? ' ('.strtoupper((string) $leave->half_type).')' : '';

            return '0.5 day'.$label;
        }

        if ($leave->type === 'undertime') {
            if ($leave->undertime_minutes !== null) {
                $minutes = (int) $leave->undertime_minutes;

                return $minutes.' min ('.round($minutes / 60, 2).' hours)';
            }

            return '—';
        }

        $days = $leave->start_date->diffInDays($leave->end_date) + 1;

        return $days.' day'.($days === 1 ? '' : 's');
    }

    /**
     * Modal/dashboard review payload — avoids reloading relations already eager-loaded in {@see review()}.
     *
     * @return array<string, mixed>
     */
    private function leaveReviewResponse(LeaveRequest $l, ?User $actor = null): array
    {
        return $this->leaveResponse($l, $actor);
    }

    private function leaveResponse(LeaveRequest $l, ?User $actor = null): array
    {
        $l->loadMissing([
            'user', 'reviewedByUser', 'filedBy', 'firstApprover', 'secondApprover', 'rejectedBy',
            'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
        ]);

        $base = array_merge([
            'id' => $l->id,
            'employee_id' => $l->user_id,
            'employee_name' => $l->user?->display_name,
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
            'reviewed_by_name' => $l->reviewedByUser?->display_name,
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
                    'actor_name' => $a->actor?->display_name,
                ];
            })->values()->all(),
        ], $this->documentPayload($l));

        return array_merge($base, $this->leaveRequesterMeta($l), $this->leaveActorFlags($l, $actor));
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
        ReviewRequestCache::forget('leave', (int) $leave->id);
        LeaveModuleCache::flush();

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
