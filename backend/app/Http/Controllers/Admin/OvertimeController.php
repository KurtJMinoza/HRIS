<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ProcessesBulkApproval;
use App\Http\Controllers\Controller;
use App\Models\Overtime;
use App\Models\OvertimeApprovalAudit;
use App\Models\User;
use App\Services\BulkApproval\OvertimeBulkApprovalQuery;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OvertimeApprovalService;
use App\Services\PayrollPeriodMutationGuard;
use App\Support\HrApprovalStages;
use App\Support\PhPayrollReference;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * Single row: measured OT only exists once attendance time-out is stored on the OT row
     * (after clock-out sync). Until then, computed_* holds the employee's requested/planned OT.
     *
     * @return array{computed_hours: float|null, computed_minutes: int|null, requested_ot_hours: float|null, requested_ot_minutes: int|null}
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
            ];
        }

        return [
            'computed_hours' => null,
            'computed_minutes' => null,
            'requested_ot_hours' => round($storedHours, 2),
            'requested_ot_minutes' => $storedMinutes,
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
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'ot_type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])->startOfDay()
            : null;
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : null;

        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()
            ->with([
                'user:id,name,first_name,middle_name,last_name,suffix,department,department_id,branch_id,company_id,profile_image,position',
                'filedBy:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'firstApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'secondApprover:id,name,first_name,middle_name,last_name,suffix,profile_image',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
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
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['ot_type'])) {
            $query->where('ot_type', $validated['ot_type']);
        }

        $scope = User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
        $this->dataScopeService->restrictEmployeeQuery($request->user(), $scope);
        $query->whereIn('user_id', $scope->select('users.id'));

        $perPage = (int) ($validated['per_page'] ?? 50);
        $perPage = $perPage > 0 ? $perPage : 50;

        $paginator = $query->paginate($perPage)->withQueryString();

        $actor = $request->user();
        $items = $paginator->getCollection()->map(function (Overtime $o) use ($actor) {
            $user = $o->user;
            $path = $o->attachment_path;
            $hasAttachment = is_string($path) && trim($path) !== '';
            $disp = $this->overtimeDisplayFields($o);

            return array_merge([
                'id' => $o->id,
                'employee_id' => $o->user_id,
                'employee_name' => $user?->display_name,
                'employee_profile_image' => $user?->profile_image_url,
                'department' => $user?->department,
                'date' => $o->date?->toDateString(),
                'schedule_end' => $o->schedule_end?->format('H:i'),
                'time_out' => $o->time_out?->format('H:i'),
                'expected_end_time' => $o->expected_end_time?->format('H:i'),
                'computed_hours' => $disp['computed_hours'],
                'computed_minutes' => $disp['computed_minutes'],
                'requested_ot_hours' => $disp['requested_ot_hours'],
                'requested_ot_minutes' => $disp['requested_ot_minutes'],
                'ot_type' => $o->ot_type,
                'status' => $o->status,
                'pending_approval' => (bool) $o->pending_approval,
                'reason' => $o->reason,
                'remarks' => $o->remarks,
                'rejection_note' => $o->rejection_note,
                'has_attachment' => $hasAttachment,
                'attachment_url' => $this->publicMediaUrl($hasAttachment ? $path : null),
                'attachment_filename' => $hasAttachment ? basename(str_replace('\\', '/', $path)) : null,
                'approved_at' => $o->approved_at?->toIso8601String(),
                'locked_at' => $o->locked_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
                'filed_at' => $o->filed_at?->toIso8601String(),
                'display_status' => $this->overtimeApprovalService->deriveDisplayStatusLabel($o),
                'approval_stage' => $o->approval_stage,
                'approval_progress' => $this->mergeOvertimeRemarksIntoProgress(
                    $o,
                    $this->overtimeApprovalService->buildApprovalProgress($o)
                ),
                'approval_history' => $o->approvalAudits->map(function (OvertimeApprovalAudit $a) {
                    return [
                        'action' => $a->action,
                        'approver_role' => $a->approver_role,
                        'details' => $a->details,
                        'at' => $a->created_at?->toIso8601String(),
                        'actor_name' => $a->actor?->display_name,
                    ];
                })->values()->all(),
            ], $this->overtimeRequesterMeta($user), PhPayrollReference::ruleMetaForOvertime($o->ph_ot_rule), $this->overtimeActorFlags($o, $actor));
        })->values();

        // Summary aggregates across the filtered set (ignores pagination).
        $allForSummary = (clone $query)->get();
        $today = today()->toDateString();
        $startOfMonth = today()->startOfMonth()->toDateString();

        $totalOtTodayHours = $allForSummary
            ->where('date', $today)
            ->sum('computed_hours');

        $pendingCount = $allForSummary
            ->where('status', Overtime::STATUS_PENDING)
            ->count();

        $approvedThisMonthHours = $allForSummary
            ->filter(function (Overtime $o) use ($startOfMonth) {
                return $o->status === Overtime::STATUS_APPROVED
                    && $o->date
                    && $o->date->toDateString() >= $startOfMonth;
            })
            ->sum('computed_hours');

        $topEmployees = $allForSummary
            ->groupBy('user_id')
            ->map(function ($rows, $userId) {
                /** @var \Illuminate\Support\Collection<int, Overtime> $rows */
                $first = $rows->first();
                $totalHours = $rows->sum('computed_hours');
                $user = $first->user;

                return [
                    'employee_id' => (int) $userId,
                    'employee_name' => $user?->display_name,
                    'department' => $user?->department,
                    'total_hours' => round((float) $totalHours, 2),
                ];
            })
            ->sortByDesc('total_hours')
            ->values()
            ->take(5)
            ->all();

        $approvedTotal = $allForSummary
            ->where('status', Overtime::STATUS_APPROVED)
            ->sum('computed_hours');
        $pendingTotal = $allForSummary
            ->where('status', Overtime::STATUS_PENDING)
            ->sum('computed_hours');

        $monthlySummary = $allForSummary
            ->groupBy(function (Overtime $o) {
                return $o->date ? $o->date->format('Y-m') : null;
            })
            ->filter(fn ($_, $monthKey) => $monthKey !== null)
            ->map(function ($rows, $monthKey) {
                /** @var \Illuminate\Support\Collection<int, Overtime> $rows */
                $month = Carbon::createFromFormat('Y-m', (string) $monthKey);
                $approvedHours = $rows
                    ->where('status', Overtime::STATUS_APPROVED)
                    ->sum('computed_hours');
                $pendingHours = $rows
                    ->where('status', Overtime::STATUS_PENDING)
                    ->sum('computed_hours');

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->format('M Y'),
                    'approved_hours' => round((float) $approvedHours, 2),
                    'pending_hours' => round((float) $pendingHours, 2),
                    'record_count' => $rows->count(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'overtimes' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total_ot_today_hours' => round((float) $totalOtTodayHours, 2),
                'pending_requests' => $pendingCount,
                'approved_this_month_hours' => round((float) $approvedThisMonthHours, 2),
                'approved_total_hours' => round((float) $approvedTotal, 2),
                'pending_total_hours' => round((float) $pendingTotal, 2),
                'top_employees' => $topEmployees,
                'monthly_summary' => $monthlySummary,
            ],
        ]);
    }

    /**
     * Detailed overtime view including adjustment history.
     */
    public function show(Request $request, int $id): JsonResponse
    {
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

        $user = $overtime->user;
        if ($user) {
            $this->dataScopeService->ensureEmployeeAccessible($request->user(), $user);
        }

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

        return response()->json([
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
        ]);
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

        if ($parsed['mode'] === 'all_matching') {
            $filters = $this->normalizeBulkApproveFilters($parsed['filters']);
            $ids = $this->bulkApprovalQuery->approvableIds($actor, $filters);
        } else {
            $ids = $parsed['ids'];
            $this->assertBulkApproveIdsPresent($ids);
            if (count($ids) > 500) {
                throw ValidationException::withMessages([
                    'ids' => ['Too many requests selected. Use “select all matching” or approve in smaller batches.'],
                ]);
            }
        }

        if (count($ids) === 0) {
            return $this->bulkApproveJsonResponse(0, 0, 0, [], 'overtime request');
        }

        $remarks = $parsed['remarks'];
        $approved = 0;
        $skipped = 0;
        $failed = 0;
        $failedItems = [];

        foreach ($ids as $id) {
            try {
                $single = $request->duplicate(null, [
                    'status' => Overtime::STATUS_APPROVED,
                    'remarks' => $remarks,
                ]);
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
        if ($overtime->user) {
            $this->dataScopeService->ensureEmployeeAccessible($actor, $overtime->user);
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
                $overtime->first_approved_at = now();
                $overtime->approval_stage = $nextPending?->approver_role === \App\Enums\HrRole::AdminHr->value
                    ? HrApprovalStages::PENDING_SECOND
                    : HrApprovalStages::PENDING_FIRST;
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

        return response()->json([
            'message' => 'Overtime approved.',
            'overtime' => $this->mapOvertime($overtime->fresh([
                'user', 'filedBy', 'firstApprover', 'secondApprover',
                'approvalAudits' => fn ($q) => $q->orderBy('created_at')->with('actor:id,name,first_name,middle_name,last_name,suffix'),
            ]), $actor),
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
