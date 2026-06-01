<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ProcessesBulkApproval;
use App\Http\Controllers\Controller;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\BulkApproval\OvertimeBulkApprovalQuery;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeApprovalService;
use App\Services\OvertimeService;
use App\Services\PayrollPeriodMutationGuard;
use App\Services\ReportsCacheService;
use App\Support\HrApprovalStages;
use App\Support\OvertimeModuleCache;
use App\Support\PhPayrollReference;
use App\Support\RequestPerformanceLogger;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OvertimeController extends Controller
{
    use ProcessesBulkApproval;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly OvertimeApprovalService $overtimeApprovalService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PayrollPeriodMutationGuard $payrollPeriodMutationGuard,
        private readonly OvertimeBulkApprovalQuery $bulkApprovalQuery,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
        private readonly OvertimeService $overtimeService,
    ) {}

    /**
     * Single row: measured OT only exists once attendance time-out is stored on the OT row
     * (after clock-out sync). Until then, computed_* holds the employee's requested/planned OT.
     *
     * @return array{computed_hours: float|null, computed_minutes: int|null, requested_ot_hours: float|null, requested_ot_minutes: int|null, approved_ot_hours: float|null, actual_rendered_ot_hours: float, payable_ot_hours: float, unapproved_ot_hours: float, overtime_reduction_reason: ?string}
     */
    private function overtimeDisplayFields(Overtime $o): array
    {
        $storedHours = (float) $o->computed_hours;
        $storedMinutes = (int) $o->computed_minutes;
        $hasMeasured = $o->time_out !== null;

        if ($hasMeasured) {
            return [
                'computed_hours' => round($storedHours, 2),
                'computed_minutes' => $storedMinutes,
                'requested_ot_hours' => null,
                'requested_ot_minutes' => null,
                'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : round($storedHours, 2),
                'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
                'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
                'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
                'overtime_reduction_reason' => $o->overtime_reduction_reason,
            ];
        }

        return [
            'computed_hours' => null,
            'computed_minutes' => null,
            'requested_ot_hours' => round($storedHours, 2),
            'requested_ot_minutes' => $storedMinutes,
            'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
            'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
            'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
            'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
            'overtime_reduction_reason' => $o->overtime_reduction_reason,
        ];
    }

    /**
     * List overtime records with filters and summary aggregates.
     *
     * Filters:
     * - from_date, to_date (YYYY-MM-DD)
     * - department (string)
     * - employee_id (int)
     * - status (pending|approved|rejected)
     * - ot_type (string)
     */
    public function index(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.overtime.index');
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'ot_type' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 25;
        $page = max(1, (int) $request->query('page', 1));
        $cacheKey = $this->overtimeListCacheKey($actor, $request, $perPage, $page);
        $cacheHit = false;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cacheHit = true;
                RequestPerformanceLogger::finish($perf, $request, count($cached['overtimes'] ?? []), [
                    'scope' => 'admin',
                    'per_page' => $perPage,
                    'total' => $cached['pagination']['total'] ?? null,
                    'cache' => 'hit',
                    'cache_hit' => true,
                ]);

                return response()->json($cached);
            }
        } catch (\Throwable $e) {
            Log::warning('overtime.list.cache_read_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        $fromRaw = $validated['date_from'] ?? $validated['from_date'] ?? null;
        $toRaw = $validated['date_to'] ?? $validated['to_date'] ?? null;
        $from = $fromRaw
            ? Carbon::parse($fromRaw)->startOfDay()
            : null;
        $to = $toRaw
            ? Carbon::parse($toRaw)->endOfDay()
            : null;

        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()
            ->select([
                'id',
                'user_id',
                'company_id',
                'department_id',
                'date',
                'schedule_end',
                'time_out',
                'expected_end_time',
                'approved_ot_start',
                'approved_ot_end',
                'approved_ot_hours',
                'actual_rendered_ot_hours',
                'payable_ot_hours',
                'unapproved_ot_hours',
                'overtime_reduction_reason',
                'computed_minutes',
                'computed_hours',
                'ph_ot_rule',
                'ot_type',
                'reason',
                'status',
                'approval_stage',
                'pending_approval',
                'filed_by',
                'first_approver_id',
                'second_approver_id',
                'rejected_at',
                'rejection_note',
                'remarks',
                'created_at',
                'filed_at',
            ])
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,company_id,department_id',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }
        if (! empty($validated['department'])) {
            $dept = $validated['department'];
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept);
            });
        }
        if (! empty($validated['employee_id'])) {
            $query->where('user_id', $validated['employee_id']);
        }
        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['ot_type'])) {
            $query->where('ot_type', $validated['ot_type']);
        }
        if (! empty($validated['search'])) {
            $search = trim((string) $validated['search']);
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(function ($scope) use ($search, $like): void {
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

        $this->applyFilingApprovalVisibility($actor, $query, $request);

        $paginator = $query->paginate($perPage)->withQueryString();
        $pageRows = $paginator->getCollection();
        $currentApprovals = $this->currentOvertimeApprovalRecords($pageRows->pluck('id')->map(fn ($id) => (int) $id)->all());

        $items = $pageRows->map(function (Overtime $o) use ($actor, $currentApprovals) {
            $user = $o->user;
            $disp = $this->overtimeDisplayFields($o);
            $currentApproval = $currentApprovals[(int) $o->id] ?? null;
            $auth = $currentApproval && $o->user
                ? $this->approvalWorkflowService->authorizePendingRecord(
                    $actor,
                    $currentApproval,
                    $o->user,
                    OrgApprovalWorkflowService::MODULE_OVERTIME,
                )
                : ['allowed' => false, 'deny_reason' => $o->status === Overtime::STATUS_PENDING ? 'no_pending_approval_step' : 'request_is_not_pending'];

            return array_merge([
                'id' => $o->id,
                'request_id' => $o->id,
                'employee_id' => $o->user_id,
                'employee_name' => $user?->display_name,
                'date' => $o->date?->toDateString(),
                'start_time' => $o->schedule_end?->format('H:i'),
                'end_time' => ($o->expected_end_time ?? $o->time_out)?->format('H:i'),
                'schedule_end' => $o->schedule_end?->format('H:i'),
                'time_out' => $o->time_out?->format('H:i'),
                'expected_end_time' => $o->expected_end_time?->format('H:i'),
                'approved_hours' => $disp['computed_hours'] ?? $disp['requested_ot_hours'],
                'approved_ot_start' => $o->approved_ot_start?->format('H:i'),
                'approved_ot_end' => $o->approved_ot_end?->format('H:i'),
                'approved_ot_hours' => $disp['approved_ot_hours'],
                'actual_rendered_ot_hours' => $disp['actual_rendered_ot_hours'],
                'payable_ot_hours' => $disp['payable_ot_hours'],
                'unapproved_ot_hours' => $disp['unapproved_ot_hours'],
                'overtime_reduction_reason' => $disp['overtime_reduction_reason'],
                'computed_hours' => $disp['computed_hours'],
                'computed_minutes' => $disp['computed_minutes'],
                'requested_ot_hours' => $disp['requested_ot_hours'],
                'requested_ot_minutes' => $disp['requested_ot_minutes'],
                'ot_type' => $o->ot_type,
                'category' => $o->ot_type,
                'status' => $o->status,
                'pending_approval' => (bool) $o->pending_approval,
                'reason' => $o->reason,
                'remarks' => $o->remarks,
                'rejection_note' => $o->rejection_note,
                'created_at' => $o->created_at?->toIso8601String(),
                'filed_at' => $o->filed_at?->toIso8601String(),
                'display_status' => $this->overtimeListDisplayStatus($o, $currentApproval),
                'approval_stage' => $o->approval_stage,
                'current_stage' => $currentApproval ? $this->approvalRecordStageLabel($currentApproval) : $o->approval_stage,
                'current_approver' => $currentApproval?->approver?->display_name ?? $currentApproval?->approver_name,
                'actor_can_approve' => (bool) ($auth['allowed'] ?? false),
                'actor_can_reject' => (bool) ($auth['allowed'] ?? false),
                'can_approve' => (bool) ($auth['allowed'] ?? false),
                'can_reject' => (bool) ($auth['allowed'] ?? false),
                'actor_can_delete' => $this->canDeleteOvertimeRequest($actor, $o),
                'hr_wait_message' => $this->overtimeListWaitMessage($o, $currentApproval, (bool) ($auth['allowed'] ?? false)),
            ], $this->overtimeRequesterMeta($user), PhPayrollReference::ruleMetaForOvertime($o->ph_ot_rule));
        })->values();

        $today = today()->toDateString();
        $startOfMonth = today()->startOfMonth()->toDateString();

        $summaryBase = clone $query;
        $pendingCount = (clone $summaryBase)->where('status', Overtime::STATUS_PENDING)->count();
        $approvedThisMonthHours = (clone $summaryBase)->where('status', Overtime::STATUS_APPROVED)->whereDate('date', '>=', $startOfMonth)->sum('computed_hours');

        RequestPerformanceLogger::finish($perf, $request, $items->count(), [
            'scope' => 'admin',
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'cache' => $cacheHit ? 'hit' : 'miss',
            'cache_hit' => $cacheHit,
        ]);

        $payload = [
            'overtimes' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total_ot_today_hours' => 0,
                'pending_requests' => $pendingCount,
                'approved_this_month_hours' => round((float) $approvedThisMonthHours, 2),
                'approved_total_hours' => 0,
                'pending_total_hours' => 0,
                'top_employees' => [],
                'monthly_summary' => [],
            ],
        ];

        try {
            Cache::put($cacheKey, $payload, now()->addSeconds(45));
        } catch (\Throwable $e) {
            Log::warning('overtime.list.cache_write_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json($payload);
    }

    public function counts(Request $request): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.overtime.counts');
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $company = (string) ($request->query('company_id') ?? 'all');
        $cacheKey = 'overtime:counts:'.OvertimeModuleCache::version().':'.$actor->id.':'.$company.':'.md5(json_encode($request->query(), JSON_THROW_ON_ERROR));
        $cacheHit = false;

        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cacheHit = true;
                RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin', 'cache' => 'hit', 'cache_hit' => true]);

                return response()->json($cached);
            }
        } catch (\Throwable $e) {
            Log::warning('overtime.counts.cache_read_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        $base = Overtime::query()->select('id', 'user_id', 'status', 'company_id', 'department_id', 'date');
        $this->applyFilingApprovalVisibility($actor, $base, $request);
        if ($request->query('company_id') !== null && ctype_digit((string) $request->query('company_id'))) {
            $base->where('company_id', (int) $request->query('company_id'));
        }

        $today = today()->toDateString();
        $payload = [
            'pending' => (int) (clone $base)->where('status', Overtime::STATUS_PENDING)->count(),
            'approved_today' => (int) (clone $base)->where('status', Overtime::STATUS_APPROVED)->whereDate('date', $today)->count(),
            'rejected_today' => (int) (clone $base)->where('status', Overtime::STATUS_REJECTED)->whereDate('date', $today)->count(),
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
            Log::warning('overtime.counts.cache_write_failed', [
                'user_id' => (int) $actor->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json($payload);
    }

    /**
     * Detailed overtime view including adjustment history.
     */
    public function review(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.overtime.review');
        $started = microtime(true);
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Log::info('admin.overtime.review_start', [
            'overtime_request_id' => $id,
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'hr_role' => $this->hrRoleResolver->resolve($actor)->value,
        ]);

        $overtime = Overtime::query()
            ->select([
                'id', 'user_id', 'assignment_id', 'assignment_type', 'company_id', 'branch_id',
                'division_id', 'department_id', 'section_unit_id', 'date', 'schedule_end',
                'time_out', 'expected_end_time', 'approved_ot_start', 'approved_ot_end',
                'approved_ot_hours', 'actual_rendered_ot_hours', 'payable_ot_hours',
                'unapproved_ot_hours', 'overtime_reduction_reason', 'computed_minutes', 'computed_hours',
                'ph_ot_rule', 'ot_type', 'reason', 'attachment_path', 'status', 'approved_by',
                'approved_at', 'remarks', 'locked_at', 'approval_stage', 'pending_approval',
                'filed_at', 'filed_by', 'first_approver_id', 'first_approved_at',
                'second_approver_id', 'second_approved_at', 'rejected_at', 'rejected_by',
                'rejection_note', 'created_at', 'updated_at',
            ])
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,role,department,department_id,branch_id,company_id,profile_image,position',
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ])
            ->find($id);

        if ($overtime === null) {
            Log::warning('admin.overtime.review_not_found', [
                'overtime_request_id' => $id,
                'actor_id' => $actor->id,
            ]);

            return response()->json(['message' => 'Overtime request not found.'], 404);
        }

        if (! $this->canAccessOvertimeThroughFilingScope($actor, $overtime)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $cached = ReviewRequestCache::remember('overtime', $id, fn () => $this->mapOvertime($overtime, $actor));

        RequestPerformanceLogger::finish($perf, $request, 1, [
            'scope' => 'admin',
            'mode' => 'review',
            'cache_hit' => $cached['cache_hit'],
            'query_count' => $cached['query_count'],
            'cache_error' => $cached['cache_error'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        Log::info('admin.overtime.review_ok', [
            'overtime_request_id' => $id,
            'actor_id' => $actor->id,
            'cache_hit' => $cached['cache_hit'],
            'query_count' => $cached['query_count'],
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return response()->json(['overtime' => $cached['payload']]);
    }

    public function reviewLite(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.overtime.review_lite');
        $actor = $request->user();
        $started = microtime(true);
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cached = ReviewRequestCache::rememberOvertimeReviewLite($id, (int) $actor->id, function () use ($actor, $id): array {
            $overtime = Overtime::query()
                ->select([
                    'id',
                    'user_id',
                    'company_id',
                    'department_id',
                    'date',
                    'schedule_end',
                    'time_out',
                    'expected_end_time',
                    'computed_minutes',
                    'computed_hours',
                    'ph_ot_rule',
                    'ot_type',
                    'reason',
                    'status',
                    'approval_stage',
                    'pending_approval',
                    'filed_at',
                    'filed_by',
                    'first_approver_id',
                    'second_approver_id',
                    'rejected_at',
                    'rejection_note',
                    'remarks',
                    'created_at',
                ])
                ->with([
                    'user:id,name,first_name,middle_name,last_name,suffix,company_id,department_id',
                    'filedBy:id,name,first_name,middle_name,last_name,suffix',
                ])
                ->findOrFail($id);

            if (! $this->canAccessOvertimeThroughFilingScope($actor, $overtime)) {
                abort(403, 'Forbidden.');
            }

            $records = OrgApprovalRecord::query()
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
                    'remarks',
                    'approved_at',
                    'sequence_order',
                ])
                ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
                ->where('request_id', (int) $overtime->id)
                ->with('approver:id,name,first_name,middle_name,last_name,suffix')
                ->orderBy('sequence_order')
                ->orderBy('id')
                ->get();

            $pending = $records->firstWhere('approval_status', OrgApprovalRecord::STATUS_PENDING);
            $auth = $this->overtimeReviewLiteActionAuthorization($actor, $overtime, $pending);

            return $this->overtimeReviewLiteResponse($overtime, $records, $pending, $auth);
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

        Log::info('admin.overtime.review_lite_ok', [
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

        return response()->json(['overtime' => $payload]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $perf = RequestPerformanceLogger::start('admin.overtime.show');
        $overtime = Overtime::query()
            ->with([
                // `role` required: ensureEmployeeAccessible() rejects subjects whose role is not employee.
                'user:id,name,first_name,middle_name,last_name,suffix,role,department,department_id,branch_id,company_id,profile_image,position',
                'adjustments.admin:id,name,first_name,middle_name,last_name,suffix',
                'approvedBy:id,name,first_name,middle_name,last_name,suffix',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ])
            ->findOrFail($id);

        $actor = $request->user();
        if ($actor instanceof User && ! $this->canAccessOvertimeThroughFilingScope($actor, $overtime)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user = $overtime->user;

        $adjustments = $overtime->adjustments
            ->sortByDesc('created_at')
            ->map(function ($adj) {
                return [
                    'id' => $adj->id,
                    'original_minutes' => $adj->original_minutes,
                    'original_hours' => (float) $adj->original_hours,
                    'updated_minutes' => $adj->updated_minutes,
                    'updated_hours' => (float) $adj->updated_hours,
                    'reason' => $adj->reason,
                    'notes' => $adj->notes,
                    'admin_id' => $adj->admin_id,
                    'admin_name' => $adj->admin?->display_name,
                    'created_at' => $adj->created_at?->toIso8601String(),
                ];
            })
            ->values();

        $attPath = $overtime->attachment_path;
        $disp = $this->overtimeDisplayFields($overtime);

        $payload = [
            'overtime' => array_merge([
                'id' => $overtime->id,
                'employee_id' => $overtime->user_id,
                'employee_name' => $user?->display_name,
                'employee_profile_image' => $user?->profile_image_url,
                'department' => $user?->department,
                'date' => $overtime->date?->toDateString(),
                'schedule_end' => $overtime->schedule_end?->format('H:i'),
                'time_out' => $overtime->time_out?->format('H:i'),
                'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
                'computed_hours' => $disp['computed_hours'],
                'computed_minutes' => $disp['computed_minutes'],
                'requested_ot_hours' => $disp['requested_ot_hours'],
                'requested_ot_minutes' => $disp['requested_ot_minutes'],
                'ot_type' => $overtime->ot_type,
                'status' => $overtime->status,
                'pending_approval' => (bool) $overtime->pending_approval,
                'reason' => $overtime->reason,
                'has_attachment' => is_string($attPath) && trim($attPath) !== '',
                'attachment_url' => $this->publicMediaUrl(is_string($attPath) ? $attPath : null),
                'attachment_filename' => (is_string($attPath) && trim($attPath) !== '')
                    ? basename(str_replace('\\', '/', $attPath))
                    : null,
                'remarks' => $overtime->remarks,
                'rejection_note' => $overtime->rejection_note,
                'approved_by_id' => $overtime->approved_by,
                'approved_by_name' => $overtime->approvedBy?->display_name,
                'approved_at' => $overtime->approved_at?->toIso8601String(),
                'locked_at' => $overtime->locked_at?->toIso8601String(),
                'created_at' => $overtime->created_at?->toIso8601String(),
                'updated_at' => $overtime->updated_at?->toIso8601String(),
                'filed_at' => $overtime->filed_at?->toIso8601String(),
                'adjustments' => $adjustments,
                'display_status' => $this->overtimeApprovalService->deriveDisplayStatusLabel($overtime),
                'approval_stage' => $overtime->approval_stage,
                'approval_progress' => $this->mergeOvertimeRemarksIntoProgress(
                    $overtime,
                    $this->overtimeApprovalService->buildApprovalProgress($overtime)
                ),
                'approval_history' => $overtime->relationLoaded('approvalAudits')
                    ? $overtime->approvalAudits->map(function (OvertimeApprovalAudit $a) {
                        return [
                            'action' => $a->action,
                            'approver_role' => $a->approver_role,
                            'details' => $a->details,
                            'at' => $a->created_at?->toIso8601String(),
                            'actor_name' => $a->actor?->display_name,
                        ];
                    })->values()->all()
                    : [],
            ], $this->overtimeRequesterMeta($user), PhPayrollReference::ruleMetaForOvertime($overtime->ph_ot_rule), $this->overtimeActorFlags($overtime, $request->user())),
        ];
        RequestPerformanceLogger::finish($perf, $request, 1, ['scope' => 'admin']);

        return response()->json($payload);
    }

    /**
     * Approve or reject an overtime entry (multi-level: line manager → HR final).
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
        $ids = $this->bulkApprovalQuery->approvableIds($actor, $filters);

        return response()->json([
            'approvable_count' => count($ids),
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
                $overtime = Overtime::query()->with(['user', 'filedBy'])->find($id);

                return $overtime !== null && $this->overtimeApprovalService->canApprove($actor, $overtime);
            });
            $ids = $resolved['ids'];
            $skipped = $resolved['skipped'];
            $failedItems = $resolved['failed_items'];
        }

        if (count($ids) === 0) {
            return $this->bulkApproveJsonResponse(0, $skipped, 0, $failedItems, 'overtime request');
        }

        $remarks = $parsed['remarks'];
        $approved = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $single = $this->duplicateBulkApproveRequest($request, null, array_filter([
                    'status' => Overtime::STATUS_APPROVED,
                    'remarks' => $remarks,
                ], static fn ($value) => $value !== null));
                $single->setUserResolver(fn () => $actor);
                $response = $this->updateStatus($single, $id);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $approved++;
                    continue;
                }

                $body = $response->getData(true);
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => (string) ($body['message'] ?? 'Overtime request was skipped.'),
                ];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $skipped++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => 'Overtime request was not found.',
                ];
            } catch (\Throwable $e) {
                $failed++;
                $failedItems[] = [
                    'request_id' => $id,
                    'reason' => $e instanceof ValidationException
                        ? (string) collect($e->errors())->flatten()->first()
                        : ($e->getMessage() ?: 'Bulk approval failed for this overtime request.'),
                ];
            }
        }

        return $this->bulkApproveJsonResponse($approved, $skipped, $failed, $failedItems, 'overtime request');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $request->merge(['status' => Overtime::STATUS_APPROVED]);

        return $this->updateStatus($request, $id);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ]);
        $request->merge([
            'status' => Overtime::STATUS_REJECTED,
            'remarks' => $validated['remarks'],
        ]);

        return $this->updateStatus($request, $id);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $overtime = Overtime::query()->with(['user', 'filedBy', 'firstApprover', 'secondApprover'])->findOrFail($id);
        if (! $this->canAccessOvertimeThroughFilingScope($actor, $overtime)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending overtime records can be updated.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $remarks = $validated['remarks'] ?? null;
        $roleLabel = $this->hrRoleResolver->resolve($actor)->badgeLabel();

        if ($validated['status'] === Overtime::STATUS_REJECTED) {
            if (! $this->overtimeApprovalService->canReject($actor, $overtime)) {
                return response()->json(['message' => 'You are not authorized to reject at this stage.'], 403);
            }

            DB::transaction(function () use ($overtime, $actor, $remarks, $roleLabel) {
                $this->approvalWorkflowService->rejectCurrent(
                    $overtime,
                    OrgApprovalWorkflowService::MODULE_OVERTIME,
                    $overtime->user,
                    $actor,
                    $remarks ?? 'Rejected.',
                    $overtime->filedBy,
                );

                $overtime->status = Overtime::STATUS_REJECTED;
                $overtime->pending_approval = false;
                $overtime->approval_stage = HrApprovalStages::REJECTED;
                $overtime->rejected_at = now();
                $overtime->rejected_by = $actor->id;
                $overtime->rejection_note = $remarks;
                if ($remarks !== null && $remarks !== '') {
                    $overtime->remarks = $remarks;
                }
                $overtime->locked_at = now();
                $overtime->updated_by = $actor->id;
                $overtime->save();

                OvertimeApprovalAudit::create([
                    'overtime_id' => $overtime->id,
                    'actor_id' => $actor->id,
                    'employee_id' => $overtime->user_id,
                    'action' => 'reject',
                    'details' => $remarks,
                    'approver_role' => $roleLabel,
                ]);
            });

            ReviewRequestCache::forget('overtime', (int) $overtime->id);
            OvertimeModuleCache::flush();

            if ($this->wantsLiteOvertimeMutationResponse($request)) {
                return response()->json([
                    'message' => 'Overtime request rejected.',
                    'request_id' => (int) $overtime->id,
                    'status' => Overtime::STATUS_REJECTED,
                    'approval_stage' => HrApprovalStages::REJECTED,
                ]);
            }

            return response()->json([
                'message' => 'Overtime request rejected.',
                'overtime' => $this->mapOvertime($overtime->fresh([
                    'user', 'filedBy', 'firstApprover', 'secondApprover', 'rejectedBy',
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
                ]), $actor),
            ]);
        }

        if (! $this->overtimeApprovalService->canApprove($actor, $overtime)) {
            return response()->json(['message' => 'You are not authorized to approve at this stage.'], 403);
        }

        $currentApproval = $this->approvalWorkflowService->currentPendingRecord(
            $overtime,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            $overtime->user,
            $overtime->filedBy,
        );
        $isHrFinalStep = $currentApproval?->approver_role === \App\Enums\HrRole::AdminHr->value;

        if (! $isHrFinalStep) {
            $nextPending = DB::transaction(function () use ($overtime, $actor, $remarks, $roleLabel) {
                $nextPending = $this->approvalWorkflowService->approveCurrent(
                    $overtime,
                    OrgApprovalWorkflowService::MODULE_OVERTIME,
                    $overtime->user,
                    $actor,
                    $remarks,
                    $overtime->filedBy,
                );

                if ($overtime->first_approver_id === null) {
                    $overtime->first_approver_id = $actor->id;
                }
                if ($nextPending?->approver_role === \App\Enums\HrRole::AdminHr->value) {
                    $overtime->first_approved_at = now();
                    $overtime->approval_stage = HrApprovalStages::PENDING_SECOND;
                } else {
                    $overtime->approval_stage = HrApprovalStages::PENDING_FIRST;
                }
                if ($remarks !== null && $remarks !== '') {
                    $overtime->remarks = $remarks;
                }
                $overtime->updated_by = $actor->id;
                $overtime->save();

                OvertimeApprovalAudit::create([
                    'overtime_id' => $overtime->id,
                    'actor_id' => $actor->id,
                    'employee_id' => $overtime->user_id,
                    'action' => 'approve_first',
                    'details' => $remarks,
                    'approver_role' => $roleLabel,
                ]);

                return $nextPending;
            });

            $nextLabel = $nextPending?->approver_role
                ? (\App\Enums\HrRole::tryFrom((string) $nextPending->approver_role)?->badgeLabel() ?? 'next approver')
                : 'next approver';

            ReviewRequestCache::forget('overtime', (int) $overtime->id);
            OvertimeModuleCache::flush();

            if ($this->wantsLiteOvertimeMutationResponse($request)) {
                return response()->json([
                    'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                    'request_id' => (int) $overtime->id,
                    'status' => $overtime->status,
                    'approval_stage' => $overtime->approval_stage,
                ]);
            }

            return response()->json([
                'message' => 'Approval recorded. Pending '.$nextLabel.' approval.',
                'overtime' => $this->mapOvertime($overtime->fresh([
                    'user', 'filedBy', 'firstApprover', 'secondApprover',
                    'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
                ]), $actor),
            ]);
        }

        if (! $isHrFinalStep) {
            return response()->json(['message' => 'This overtime request cannot be approved.'], 422);
        }

        DB::transaction(function () use ($overtime, $actor, $remarks, $roleLabel) {
            $this->approvalWorkflowService->approveCurrent(
                $overtime,
                OrgApprovalWorkflowService::MODULE_OVERTIME,
                $overtime->user,
                $actor,
                $remarks,
                $overtime->filedBy,
            );

            $overtime->status = Overtime::STATUS_APPROVED;
            $overtime->pending_approval = false;
            $overtime->approval_stage = HrApprovalStages::APPROVED;
            $overtime->second_approver_id = $actor->id;
            $overtime->second_approved_at = now();
            $overtime->approved_by = $actor->id;
            $overtime->approved_at = now();
            $overtime->approved_ot_start = $overtime->schedule_end?->format('H:i:s');
            $overtime->approved_ot_end = $overtime->expected_end_time?->format('H:i:s');
            $overtime->approved_ot_hours = round((float) ($overtime->computed_hours ?? 0), 2);
            if ($remarks !== null && $remarks !== '') {
                $overtime->remarks = $remarks;
            }
            $overtime->locked_at = now();
            $overtime->updated_by = $actor->id;
            $overtime->save();

            OvertimeApprovalAudit::create([
                'overtime_id' => $overtime->id,
                'actor_id' => $actor->id,
                'employee_id' => $overtime->user_id,
                'action' => 'approve_final',
                'details' => $remarks,
                'approver_role' => $roleLabel,
            ]);
        });

        $freshForRenderedSync = $overtime->fresh('user');
        if ($freshForRenderedSync instanceof Overtime && $freshForRenderedSync->user instanceof User) {
            $dateKey = $freshForRenderedSync->date?->toDateString();
            if ($dateKey !== null) {
                $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
                $computedClockOut = $this->overtimeService->computeOvertimeForDate($freshForRenderedSync->user, Carbon::parse($dateKey, $tz));
                $actualClockOut = $computedClockOut['time_out'] ?? null;
                if (! $actualClockOut instanceof Carbon && $freshForRenderedSync->time_out !== null) {
                    $actualClockOut = Carbon::parse($dateKey.' '.$freshForRenderedSync->time_out->format('H:i:s'), $tz);
                }
                $this->overtimeService->syncActualClockOutToFiledOvertime($freshForRenderedSync->user, $dateKey, $actualClockOut, $actor);
            }
        }

        ReviewRequestCache::forget('overtime', (int) $overtime->id);
        OvertimeModuleCache::flush();
        ReportsCacheService::invalidateAttendanceCache((int) $overtime->user_id, $overtime->date?->toDateString());
        $this->clearAffectedDraftPayrollSnapshots($overtime);

        if ($this->wantsLiteOvertimeMutationResponse($request)) {
            return response()->json([
                'message' => 'Overtime approved.',
                'request_id' => (int) $overtime->id,
                'status' => Overtime::STATUS_APPROVED,
                'approval_stage' => HrApprovalStages::APPROVED,
            ]);
        }

        return response()->json([
            'message' => 'Overtime approved.',
            'overtime' => $this->mapOvertime($overtime->fresh([
                'user', 'filedBy', 'firstApprover', 'secondApprover',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ]), $actor),
        ]);
    }

    private function clearAffectedDraftPayrollSnapshots(Overtime $overtime): void
    {
        $date = $overtime->date?->toDateString();
        if ($date === null || (int) $overtime->user_id <= 0) {
            return;
        }

        $drafts = Payslip::query()
            ->where('user_id', (int) $overtime->user_id)
            ->where('status', Payslip::STATUS_DRAFT)
            ->whereDate('pay_period_start', '<=', $date)
            ->whereDate('pay_period_end', '>=', $date)
            ->get(['id', 'company_id', 'pay_period_start', 'pay_period_end']);

        if ($drafts->isEmpty()) {
            return;
        }

        $draftIds = $drafts->pluck('id')->map(fn ($id) => (int) $id)->all();
        Payslip::query()->whereIn('id', $draftIds)->delete();

        foreach ($drafts as $draft) {
            PayrollBatchRun::query()
                ->where('status', PayrollBatchRun::STATUS_DRAFT)
                ->whereDate('pay_period_start', $draft->pay_period_start?->toDateString() ?? $date)
                ->whereDate('pay_period_end', $draft->pay_period_end?->toDateString() ?? $date)
                ->when($draft->company_id !== null, fn ($q) => $q->where('company_id', (int) $draft->company_id))
                ->update(['error_message' => 'Draft needs recompute: overtime request '.$overtime->id.' was approved.']);
        }

        Log::info('payroll_draft_cache_cleared_for_overtime', [
            'overtime_id' => (int) $overtime->id,
            'employee_id' => (int) $overtime->user_id,
            'date' => $date,
            'deleted_draft_payslip_ids' => $draftIds,
        ]);
    }

    /**
     * Manually adjust overtime hours for a pending record.
     * Logs the change in overtime_adjustments.
     */
    public function updateHours(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
        ]);

        $overtime = Overtime::findOrFail($id);
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending overtime records can be adjusted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $d = Carbon::parse($overtime->date->toDateString())->startOfDay();
            $this->payrollPeriodMutationGuard->assertMutableForUserWindow((int) $overtime->user_id, $d, $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $admin = $request->user();
        if (! $admin instanceof User || ! $admin->isAdmin()) {
            return response()->json([
                'message' => 'Only admins can adjust overtime.',
            ], Response::HTTP_FORBIDDEN);
        }

        $newHours = (float) $validated['hours'];
        $newMinutes = (int) round($newHours * 60);
        $newHours = round($newMinutes / 60, 2);

        $originalMinutes = (int) $overtime->computed_minutes;
        $originalHours = (float) $overtime->computed_hours;

        $overtime->adjustments()->create([
            'admin_id' => $admin->id,
            'original_minutes' => $originalMinutes,
            'original_hours' => $originalHours,
            'updated_minutes' => $newMinutes,
            'updated_hours' => $newHours,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
        ]);

        $overtime->computed_minutes = $newMinutes;
        $overtime->computed_hours = $newHours;
        $overtime->updated_by = $admin->id;
        $overtime->save();
        ReviewRequestCache::forget('overtime', (int) $overtime->id);
        OvertimeModuleCache::flush();

        return response()->json([
            'message' => 'Overtime hours updated.',
            'overtime' => $this->mapOvertime($overtime->fresh('user')),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $overtime = Overtime::query()->with('user')->findOrFail($id);
        if ($overtime->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $overtime->user);
        }

        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending overtime requests can be deleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->canDeleteOvertimeRequest($actor, $overtime)) {
            return response()->json([
                'message' => 'You can only delete overtime requests you created or requests filed for you.',
            ], Response::HTTP_FORBIDDEN);
        }

        $path = $overtime->attachment_path;
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $overtime->delete();
        ReviewRequestCache::forget('overtime', (int) $overtime->id);
        OvertimeModuleCache::flush();

        return response()->json([
            'message' => 'Overtime request deleted.',
        ]);
    }

    private function canDeleteOvertimeRequest(User $actor, Overtime $overtime): bool
    {
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return false;
        }

        $actorId = (int) $actor->id;

        return $actorId === (int) $overtime->filed_by
            || $actorId === (int) $overtime->user_id;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Overtime>  $query
     */
    private function applyFilingApprovalVisibility(User $actor, $query, Request $request): void
    {
        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);
        if ($scopedEmployeeIds === null) {
            Log::info('filing_visibility: overtime admin list unrestricted for Admin HR', [
                'current_user_id' => (int) $actor->id,
                'current_employee_id' => (int) $actor->id,
                'role' => $actor->role,
            ]);

            return;
        }

        $assignedRequestIds = $this->assignedOvertimeRequestIdsForActor($actor);
        $actorId = (int) $actor->id;
        $query->where(function ($scope) use ($scopedEmployeeIds, $assignedRequestIds, $actorId): void {
            $scope->whereIn('user_id', $scopedEmployeeIds)
                ->orWhere('first_approver_id', $actorId)
                ->orWhere('second_approver_id', $actorId);

            if ($assignedRequestIds !== []) {
                $scope->orWhereIn('id', $assignedRequestIds);
            }
        });

        Log::info('filing_visibility: overtime admin list scoped for approvals', [
            'current_user_id' => $actorId,
            'current_employee_id' => $actorId,
            'role' => $this->hrRoleResolver->resolve($actor)->value,
            'can_view_my_filings' => true,
            'can_view_assigned_approvals' => true,
            'can_view_team_filings' => true,
            'approval_scoped_employee_ids' => $scopedEmployeeIds,
            'assigned_approval_request_ids' => $assignedRequestIds,
            'returned_all_filings_count' => null,
            'request_status_filter' => $request->query('status'),
        ]);
    }

    /**
     * @return list<int>
     */
    private function assignedOvertimeRequestIdsForActor(User $actor): array
    {
        $actorId = (int) $actor->id;
        $ids = Overtime::query()
            ->where(function ($query) use ($actorId): void {
                $query->where('first_approver_id', $actorId)
                    ->orWhere('second_approver_id', $actorId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $recordIds = OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
            ->where('approver_id', $actorId)
            ->pluck('request_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ids, ...$recordIds]));
    }

    private function canAccessOvertimeThroughFilingScope(User $actor, Overtime $overtime): bool
    {
        if ($this->hrRoleResolver->isAdminHrAccount($actor)) {
            return true;
        }

        $actorId = (int) $actor->id;
        if ((int) $overtime->user_id === $actorId
            || (int) $overtime->filed_by === $actorId
            || (int) $overtime->first_approver_id === $actorId
            || (int) $overtime->second_approver_id === $actorId
        ) {
            return true;
        }

        if (OrgApprovalRecord::query()
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
            ->where('request_id', (int) $overtime->id)
            ->where('approver_id', $actorId)
            ->exists()) {
            return true;
        }

        $scopedEmployeeIds = $this->dataScopeService->getApprovalScopedEmployeeIdsForUser($actor);

        return is_array($scopedEmployeeIds) && in_array((int) $overtime->user_id, $scopedEmployeeIds, true);
    }

    private function wantsLiteOvertimeMutationResponse(Request $request): bool
    {
        return str_starts_with($request->path(), 'api/overtime-requests/');
    }

    private function overtimeListCacheKey(User $actor, Request $request, int $perPage, int $page): string
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'employee_id' => $request->query('employee_id'),
            'company_id' => $request->query('company_id'),
            'department_id' => $request->query('department_id'),
            'date_from' => $request->query('date_from') ?? $request->query('from_date'),
            'date_to' => $request->query('date_to') ?? $request->query('to_date'),
            'ot_type' => $request->query('ot_type'),
            'search' => is_string($request->query('search')) ? trim((string) $request->query('search')) : null,
            'page' => $page,
            'per_page' => $perPage,
        ], static fn ($value): bool => $value !== null && $value !== '');
        $hash = md5(json_encode($filters, JSON_THROW_ON_ERROR));
        $company = (string) ($filters['company_id'] ?? 'all');
        $status = (string) ($filters['status'] ?? 'all');

        return 'overtime:list:'.((int) $actor->id).':'.$company.':'.$status.':'.$page.':'.$hash.':v'.OvertimeModuleCache::version();
    }

    /**
     * @param  list<int>  $requestIds
     * @return array<int, OrgApprovalRecord>
     */
    private function currentOvertimeApprovalRecords(array $requestIds): array
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
            ->where('module_type', OrgApprovalWorkflowService::MODULE_OVERTIME)
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

    private function overtimeListDisplayStatus(Overtime $overtime, ?OrgApprovalRecord $currentApproval): string
    {
        if ($overtime->status === Overtime::STATUS_APPROVED) {
            return 'HR Approved';
        }
        if ($overtime->status === Overtime::STATUS_REJECTED || $overtime->rejected_at !== null) {
            return 'Rejected';
        }
        if ($currentApproval) {
            return 'Pending '.rtrim(str_ireplace(' approval', '', $this->approvalRecordStageLabel($currentApproval))).' Approval';
        }

        return 'Pending';
    }

    private function overtimeListWaitMessage(Overtime $overtime, ?OrgApprovalRecord $currentApproval, bool $canAct): ?string
    {
        if ($overtime->status !== Overtime::STATUS_PENDING || $canAct || ! $currentApproval) {
            return null;
        }

        return 'Waiting for '.$this->approvalRecordStageLabel($currentApproval).'.';
    }

    /**
     * @return array{can_approve: bool, can_reject: bool, deny_reason: ?string}
     */
    private function overtimeReviewLiteActionAuthorization(User $actor, Overtime $overtime, ?OrgApprovalRecord $pending): array
    {
        if (! $overtime->pending_approval || $overtime->status !== Overtime::STATUS_PENDING || $overtime->rejected_at !== null) {
            return ['can_approve' => false, 'can_reject' => false, 'deny_reason' => 'request_is_not_pending'];
        }
        if (! $overtime->user) {
            return ['can_approve' => false, 'can_reject' => false, 'deny_reason' => 'request_employee_not_loaded'];
        }
        if (! $pending) {
            return ['can_approve' => false, 'can_reject' => false, 'deny_reason' => 'no_pending_approval_step'];
        }

        $auth = $this->approvalWorkflowService->authorizePendingRecord(
            $actor,
            $pending,
            $overtime->user,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
        );

        return [
            'can_approve' => (bool) $auth['allowed'],
            'can_reject' => (bool) $auth['allowed'],
            'deny_reason' => $auth['deny_reason'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrgApprovalRecord>  $records
     * @param  array{can_approve: bool, can_reject: bool, deny_reason: ?string}  $auth
     * @return array<string, mixed>
     */
    private function overtimeReviewLiteResponse(Overtime $overtime, $records, ?OrgApprovalRecord $pending, array $auth): array
    {
        $disp = $this->overtimeDisplayFields($overtime);
        $hours = $disp['computed_hours'] ?? $disp['requested_ot_hours'];

        return array_merge([
            'id' => (int) $overtime->id,
            'request_id' => (int) $overtime->id,
            'employee_id' => (int) $overtime->user_id,
            'employee' => [
                'id' => (int) $overtime->user_id,
                'name' => $overtime->user?->display_name,
                'initials' => $this->initialsForName($overtime->user?->display_name),
            ],
            'employee_name' => $overtime->user?->display_name,
            'date' => $overtime->date?->toDateString(),
            'start_time' => $overtime->schedule_end?->format('H:i'),
            'end_time' => ($overtime->expected_end_time ?? $overtime->time_out)?->format('H:i'),
            'schedule_end' => $overtime->schedule_end?->format('H:i'),
            'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
            'time_out' => $overtime->time_out?->format('H:i'),
            'approved_ot_start' => $overtime->approved_ot_start?->format('H:i'),
            'approved_ot_end' => $overtime->approved_ot_end?->format('H:i'),
            'approved_ot_hours' => $disp['approved_ot_hours'],
            'actual_rendered_ot_hours' => $disp['actual_rendered_ot_hours'],
            'payable_ot_hours' => $disp['payable_ot_hours'],
            'unapproved_ot_hours' => $disp['unapproved_ot_hours'],
            'overtime_reduction_reason' => $disp['overtime_reduction_reason'],
            'hours' => $hours,
            'computed_hours' => $disp['computed_hours'],
            'computed_minutes' => $disp['computed_minutes'],
            'requested_ot_hours' => $disp['requested_ot_hours'],
            'requested_ot_minutes' => $disp['requested_ot_minutes'],
            'category' => $overtime->ot_type,
            'ot_type' => $overtime->ot_type,
            'pay_rule' => $overtime->ph_ot_rule,
            'ph_ot_rule' => $overtime->ph_ot_rule,
            'reason' => $overtime->reason,
            'remarks' => $overtime->remarks,
            'rejection_note' => $overtime->rejection_note,
            'status' => $overtime->status,
            'display_status' => $this->overtimeListDisplayStatus($overtime, $pending),
            'current_approver_id' => $pending?->approver_id,
            'current_approver' => $pending?->approver?->display_name ?? $pending?->approver_name,
            'current_stage' => $pending ? $this->approvalRecordStageLabel($pending) : $overtime->approval_stage,
            'approval_stage' => $overtime->approval_stage,
            'approval_chain' => $this->approvalRecordSummary($records),
            'approval_progress' => $this->approvalRecordSummary($records),
            'approval_history' => $records
                ->whereIn('approval_status', [OrgApprovalRecord::STATUS_APPROVED, OrgApprovalRecord::STATUS_REJECTED])
                ->map(fn (OrgApprovalRecord $record): array => [
                    'action' => $record->approval_status === OrgApprovalRecord::STATUS_REJECTED ? 'reject' : 'approve',
                    'approver_role' => $this->approvalRecordRoleLabel($record),
                    'details' => $record->remarks,
                    'at' => $record->approved_at?->toIso8601String(),
                    'actor_name' => $record->approver?->display_name ?? $record->approver_name,
                ])->values()->all(),
            'can_approve' => $auth['can_approve'],
            'can_reject' => $auth['can_reject'],
            'actor_can_approve' => $auth['can_approve'],
            'actor_can_reject' => $auth['can_reject'],
            'deny_reason' => $auth['deny_reason'],
            'created_at' => $overtime->created_at?->toIso8601String(),
            'filed_at' => ($overtime->filed_at ?? $overtime->created_at)?->toIso8601String(),
        ], $this->overtimeRequesterMeta($overtime->user), PhPayrollReference::ruleMetaForOvertime($overtime->ph_ot_rule));
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

    private function approvalRecordSummary($records): array
    {
        $currentMarked = false;

        return $records->map(function (OrgApprovalRecord $record) use (&$currentMarked): array {
            $status = match ($record->approval_status) {
                OrgApprovalRecord::STATUS_APPROVED => 'completed',
                OrgApprovalRecord::STATUS_REJECTED => 'rejected',
                default => $currentMarked ? 'pending' : 'current',
            };
            if ($status === 'current') {
                $currentMarked = true;
            }

            return [
                'id' => (int) $record->id,
                'key' => 'approval-'.$record->id,
                'label' => $this->approvalRecordStageLabel($record),
                'role_label' => $this->approvalRecordRoleLabel($record),
                'approver_role_label' => $this->approvalRecordRoleLabel($record),
                'status' => $status,
                'approver_id' => $record->approver_id,
                'approver_name' => $record->approver?->display_name ?? $record->approver_name,
                'remarks' => $record->remarks,
                'approved_at' => $record->approved_at?->toIso8601String(),
                'acted_at' => $record->approved_at?->toIso8601String(),
                'sequence_order' => (int) $record->sequence_order,
            ];
        })->values()->all();
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

    /**
     * Export overtime records as CSV using the same filters as index().
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'ot_type' => ['nullable', 'string', 'max:50'],
            'format' => ['nullable', 'string', 'in:csv'],
        ]);

        // Reuse the same query logic as index() but without pagination.
        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])->startOfDay()
            : null;
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : null;

        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()->with(['user:id,name,first_name,middle_name,last_name,suffix,department,department_id,branch_id,company_id']);

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }
        if (! empty($validated['department'])) {
            $dept = $validated['department'];
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept);
            });
        }
        if (! empty($validated['employee_id'])) {
            $query->where('user_id', $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['ot_type'])) {
            $query->where('ot_type', $validated['ot_type']);
        }

        $rows = $query->orderBy('date')->orderBy('user_id')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="overtime-export-'.now()->format('Ymd_His').'.csv"',
        ];

        $lines = [];
        $lines[] = implode(',', [
            'Employee ID',
            'Employee Name',
            'Department',
            'Date',
            'Schedule End',
            'Time Out',
            'Overtime Hours',
            'Overtime Minutes',
            'OT Type',
            'Status',
            'Remarks',
        ]);

        foreach ($rows as $o) {
            $user = $o->user;
            $disp = $this->overtimeDisplayFields($o);
            $line = [
                $user?->id,
                $user?->display_name,
                $user?->department,
                $o->date?->toDateString(),
                $o->schedule_end?->format('H:i'),
                $o->time_out?->format('H:i'),
                $disp['computed_hours'] ?? $disp['requested_ot_hours'] ?? '',
                $disp['computed_minutes'] ?? $disp['requested_ot_minutes'] ?? '',
                $o->ot_type,
                $o->status,
                $o->remarks,
            ];

            $lines[] = $this->toCsvLine($line);
        }

        $content = implode("\n", $lines)."\n";

        return response($content, 200, $headers);
    }

    private function toCsvLine(array $fields): string
    {
        return implode(',', array_map(function ($value) {
            $str = (string) $value;
            $str = str_replace('"', '""', $str);

            return '"'.$str.'"';
        }, $fields));
    }

    /**
     * @return array<string, mixed>
     */
    private function overtimeRequesterMeta(?User $user): array
    {
        if (! $user) {
            return [
                'requested_by_id' => null,
                'requested_by_name' => null,
                'requested_by_position' => null,
                'requested_by_profile_image_url' => null,
                'requested_by_hr_role' => null,
                'requested_by_role_label' => null,
            ];
        }

        $hr = $this->hrRoleResolver->resolveForApprovalSubject($user);

        return [
            'requested_by_id' => $user->id,
            'requested_by_name' => $user->display_name,
            'requested_by_formatted_name' => $user->formatted_name,
            'requested_by_position' => $user->position,
            'requested_by_profile_image_url' => $user->profile_image_url,
            'requested_by_hr_role' => $hr->value,
            'requested_by_role_label' => $hr->badgeLabel(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function mergeOvertimeRemarksIntoProgress(Overtime $overtime, array $steps): array
    {
        if (! $overtime->relationLoaded('approvalAudits')) {
            return $steps;
        }

        $audits = $overtime->approvalAudits;
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
                $a = $audits->firstWhere('action', 'approve_final');
                if (! $a) {
                    $a = $audits->firstWhere('action', 'reject');
                }
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
    private function overtimeActorFlags(Overtime $overtime, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $chain = $overtime->user ? $this->overtimeApprovalService->getApprovalChain($overtime->user) : null;
        $stage = $overtime->approval_stage ?? HrApprovalStages::PENDING_FIRST;
        $hrWait = null;
        $actorHr = $this->hrRoleResolver->resolve($actor) === \App\Enums\HrRole::AdminHr;
        if ($actorHr && $chain !== null && count($chain) >= 2 && $stage === HrApprovalStages::PENDING_FIRST
            && $overtime->pending_approval && $overtime->status === Overtime::STATUS_PENDING && ! $overtime->rejected_at) {
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
            'actor_can_approve' => $this->overtimeApprovalService->canApprove($actor, $overtime),
            'actor_can_reject' => $this->overtimeApprovalService->canReject($actor, $overtime),
            'actor_can_delete' => $this->canDeleteOvertimeRequest($actor, $overtime),
            'hr_wait_message' => $hrWait,
        ];
    }

    private function mapOvertime(Overtime $overtime, ?User $actor = null): array
    {
        $overtime->loadMissing([
            'user:id,name,first_name,middle_name,last_name,suffix,department,department_id,branch_id,company_id,profile_image,position',
            'approvedBy:id,name,first_name,middle_name,last_name,suffix',
            'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
            'rejectedBy:id,name,first_name,middle_name,last_name,suffix',
            'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
        ]);
        $user = $overtime->user;
        $path = $overtime->attachment_path;
        $has = is_string($path) && trim($path) !== '';
        $disp = $this->overtimeDisplayFields($overtime);

        $base = [
            'id' => $overtime->id,
            'employee_id' => $overtime->user_id,
            'employee_name' => $user?->display_name,
            'employee_profile_image' => $user?->profile_image_url,
            'department' => $user?->department,
            'date' => $overtime->date?->toDateString(),
            'schedule_end' => $overtime->schedule_end?->format('H:i'),
            'time_out' => $overtime->time_out?->format('H:i'),
            'approved_ot_start' => $overtime->approved_ot_start?->format('H:i'),
            'approved_ot_end' => $overtime->approved_ot_end?->format('H:i'),
            'approved_ot_hours' => $disp['approved_ot_hours'],
            'actual_rendered_ot_hours' => $disp['actual_rendered_ot_hours'],
            'payable_ot_hours' => $disp['payable_ot_hours'],
            'unapproved_ot_hours' => $disp['unapproved_ot_hours'],
            'overtime_reduction_reason' => $disp['overtime_reduction_reason'],
            'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
            'computed_hours' => $disp['computed_hours'],
            'computed_minutes' => $disp['computed_minutes'],
            'requested_ot_hours' => $disp['requested_ot_hours'],
            'requested_ot_minutes' => $disp['requested_ot_minutes'],
            'ot_type' => $overtime->ot_type,
            'status' => $overtime->status,
            'pending_approval' => (bool) $overtime->pending_approval,
            'reason' => $overtime->reason,
            'has_attachment' => $has,
            'attachment_url' => $this->publicMediaUrl($has ? $path : null),
            'attachment_filename' => $has ? basename(str_replace('\\', '/', $path)) : null,
            'remarks' => $overtime->remarks,
            'rejection_note' => $overtime->rejection_note,
            'approved_by_id' => $overtime->approved_by,
            'approved_by_name' => $overtime->approvedBy?->display_name,
            'approved_at' => $overtime->approved_at?->toIso8601String(),
            'locked_at' => $overtime->locked_at?->toIso8601String(),
            'created_at' => $overtime->created_at?->toIso8601String(),
            'updated_at' => $overtime->updated_at?->toIso8601String(),
            'filed_at' => $overtime->filed_at?->toIso8601String(),
            'display_status' => $this->overtimeApprovalService->deriveDisplayStatusLabel($overtime),
            'approval_stage' => $overtime->approval_stage,
            'approval_progress' => $this->mergeOvertimeRemarksIntoProgress(
                $overtime,
                $this->overtimeApprovalService->buildApprovalProgress($overtime)
            ),
            'approval_history' => $overtime->approvalAudits->map(function (OvertimeApprovalAudit $a) {
                return [
                    'action' => $a->action,
                    'approver_role' => $a->approver_role,
                    'details' => $a->details,
                    'at' => $a->created_at?->toIso8601String(),
                    'actor_name' => $a->actor?->display_name,
                ];
            })->values()->all(),
        ];

        return array_merge($base, $this->overtimeRequesterMeta($user), $this->overtimeActorFlags($overtime, $actor), PhPayrollReference::ruleMetaForOvertime($overtime->ph_ot_rule));
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
