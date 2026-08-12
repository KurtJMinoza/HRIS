<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OrgApprovalRecord;
use App\Models\Overtime;
use App\Models\ScheduleRequest;
use App\Models\User;
use App\Support\AttendanceCorrectionModuleCache;
use App\Support\HrApprovalStages;
use App\Support\LeaveModuleCache;
use App\Support\OvertimeModuleCache;
use App\Support\ReviewRequestCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrgApprovalWorkflowService
{
    public const MODULE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const MODULE_OVERTIME = 'overtime';

    public const MODULE_LEAVE = 'leave';

    public const MODULE_SCHEDULE = 'schedule';

    public const MODULE_CHANGE_SCHEDULE = 'change_schedule';

    public const MODULE_REPORTS_REQUEST = 'reports_request';

    public function __construct(
        private readonly HrApprovalChainResolver $chainResolver,
        private readonly HrRoleResolver $roleResolver,
        private readonly ApprovalWorkflowSettingService $workflowSettingService,
    ) {}

    public static function normalizeModuleType(?string $moduleType): ?string
    {
        if ($moduleType === self::MODULE_SCHEDULE) {
            return self::MODULE_CHANGE_SCHEDULE;
        }

        return HrApprovalChainResolver::normalizeRequestType($moduleType) ?? $moduleType;
    }

    /**
     * @return EloquentCollection<int, OrgApprovalRecord>
     */
    public function ensureRecordsForRequest(
        Model $request,
        string $moduleType,
        User $employee,
        ?User $requestor = null
    ): EloquentCollection {
        $employee = $this->employeeForApprovalRouting($employee);
        $requestor = $requestor ? $this->employeeForApprovalRouting($requestor) : null;

        $requestId = (int) $request->getKey();
        $resolvedRequestType = self::normalizeModuleType($moduleType);
        $steps = $this->chainResolver->resolveApprovalChain(
            $employee,
            $resolvedRequestType,
            $requestor ?? $employee,
            [
                'request_id' => $requestId,
                'module_type' => $moduleType,
                'assignment_id' => $request->getAttribute('assignment_id'),
                'assignment_type' => $request->getAttribute('assignment_type'),
                'company_id' => $request->getAttribute('company_id'),
                'branch_id' => $request->getAttribute('branch_id'),
                'division_id' => $request->getAttribute('division_id'),
                'department_id' => $request->getAttribute('department_id'),
                'section_unit_id' => $request->getAttribute('section_unit_id'),
            ],
        );
        if ($steps === []) {
            return new EloquentCollection;
        }

        $existing = $this->records($moduleType, $requestId);
        if ($existing->isNotEmpty()) {
            $isPending = $this->requestIsPending($request, $moduleType);

            // Re-filed pending requests can still carry Completed steps from an earlier
            // submission (approved_at before filed_at, or no pending step left). Reset
            // those so the chain matches the current pending status.
            if ($isPending && $this->pendingRequestHasStaleApprovals($request, $existing)) {
                Log::info('approval_chain: resetting stale org approval records for pending request', [
                    'module_type' => $moduleType,
                    'request_id' => $requestId,
                    'existing_count' => $existing->count(),
                    'resolved_count' => count($steps),
                    'filed_at' => $this->requestFiledAt($request)?->toIso8601String(),
                ]);
                OrgApprovalRecord::query()
                    ->where('module_type', $moduleType)
                    ->where('request_id', $requestId)
                    ->delete();
                ReviewRequestCache::forget($moduleType, $requestId);
            } elseif ($isPending && $this->chainNeedsSync($existing, $steps, $moduleType)) {
                // Wrong/stale request org snapshots can re-resolve to HR-only and wipe the
                // current Department Head (etc.) step mid-approve — keep the active chain.
                if ($this->shouldKeepActiveOrgPendingApprover($request, $moduleType, $employee, $existing, $steps)) {
                    Log::warning('approval_chain: refusing sync that would drop active org pending approver', [
                        'module_type' => $moduleType,
                        'request_id' => $requestId,
                        'existing_count' => $existing->count(),
                        'resolved_count' => count($steps),
                        'pending_approver_id' => $existing
                            ->first(fn (OrgApprovalRecord $record): bool => $record->approval_status === OrgApprovalRecord::STATUS_PENDING
                                && $record->approver_role !== HrRole::AdminHr->value)
                            ?->approver_id,
                    ]);

                    return $existing;
                }

                Log::info('approval_chain: syncing org approval records for pending request', [
                    'module_type' => $moduleType,
                    'request_id' => $requestId,
                    'existing_count' => $existing->count(),
                    'resolved_count' => count($steps),
                ]);
                $this->syncRecordsToChain($request, $moduleType, $requestId, $steps, $existing, $employee, $requestor ?? $employee);

                return $this->records($moduleType, $requestId);
            } else {
                // Chain shape already matches — still refresh legacy first/second/stage snapshots
                // (head removal can leave Arbole on first_approver_id while org records are already HR-only).
                if ($isPending) {
                    $this->syncLegacyRequestApprovers($request, $moduleType, $steps);
                }

                return $this->records($moduleType, $requestId);
            }
        }

        DB::transaction(function () use ($steps, $request, $moduleType, $requestId): void {
            $now = now();
            $rows = [];
            foreach ($steps as $step) {
                $legacyStatus = $this->legacyStatusForStep($request, $step);
                $approvedAt = $legacyStatus === OrgApprovalRecord::STATUS_APPROVED
                    ? $this->legacyApprovedAtForStep($request, $step)
                    : null;

                $rows[] = [
                    'request_id' => $requestId,
                    'module_type' => $moduleType,
                    'approval_level' => $step['approval_level'],
                    'approval_label' => $step['approval_label'] ?? null,
                    'approver_role' => $step['approver_role']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'eligible_approver_ids' => isset($step['eligible_approver_ids'])
                        ? json_encode(array_values($step['eligible_approver_ids']))
                        : null,
                    'routing_rule' => $step['routing_rule'] ?? null,
                    'approval_status' => $legacyStatus,
                    'remarks' => null,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                OrgApprovalRecord::query()->insert($rows);
            }

            $this->syncLegacyRequestApprovers($request, $moduleType, $steps);
        });

        Log::info('request_submitted', [
            'module_type' => $moduleType,
            'request_id' => $requestId,
            'employee_id' => (int) $employee->id,
            'requestor_id' => $requestor ? (int) $requestor->id : (int) $employee->id,
        ]);
        $this->logSelfApprovalDetection($request, $moduleType, $steps, $employee, $requestor ?? $employee);

        return $this->records($moduleType, $requestId);
    }

    /**
     * Re-resolve approval chains for pending requests after workflow settings / head change.
     *
     * @param  list<string>  $requestTypes
     */
    public function resyncPendingRequestChains(
        array $requestTypes,
        ?string $legacyType = null,
        ?int $legacyId = null,
    ): int {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (string $type): ?string => self::normalizeModuleType($type),
            $requestTypes,
        ))));

        $synced = 0;
        $scope = ($legacyType !== null && $legacyId !== null && $legacyId > 0)
            ? ['legacy_type' => $legacyType, 'legacy_id' => $legacyId]
            : null;

        if (in_array(self::MODULE_LEAVE, $normalized, true)) {
            $synced += $this->resyncPendingLeaveRequests($scope);
            LeaveModuleCache::flush();
        }

        if (in_array(self::MODULE_OVERTIME, $normalized, true)) {
            $synced += $this->resyncPendingOvertimeRequests($scope);
            OvertimeModuleCache::flush();
        }

        if (in_array(self::MODULE_ATTENDANCE_CORRECTION, $normalized, true)) {
            $synced += $this->resyncPendingAttendanceCorrectionRequests($scope);
            AttendanceCorrectionModuleCache::flush();
        }

        if (in_array(self::MODULE_CHANGE_SCHEDULE, $normalized, true)) {
            $synced += $this->resyncPendingScheduleRequests($scope);
        }

        if ($synced > 0) {
            Log::info('approval_chain: resynced pending request chains after workflow settings change', [
                'request_types' => $normalized,
                'requests_updated' => $synced,
                'legacy_type' => $legacyType,
                'legacy_id' => $legacyId,
            ]);
        }

        return $synced;
    }

    /**
     * Re-resolve pending attendance corrections after an employee's organization
     * assignment changes. The correction snapshot must follow the current active
     * assignment so a new department/company head becomes effective immediately.
     *
     * @param  list<int>  $employeeIds
     */
    public function resyncPendingAttendanceCorrectionChainsForEmployees(array $employeeIds): int
    {
        return $this->resyncPendingFilingChainsForEmployees($employeeIds, [self::MODULE_ATTENDANCE_CORRECTION]);
    }

    /**
     * Re-resolve every pending filing for employees whose organization assignment changed.
     * This keeps leave, overtime, attendance corrections, and schedule requests aligned
     * with the employee's current shared or primary organization.
     *
     * @param  list<int>  $employeeIds
     * @param  list<string>|null  $requestTypes
     */
    public function resyncPendingFilingChainsForEmployees(array $employeeIds, ?array $requestTypes = null): int
    {
        $employeeIds = array_values(array_unique(array_filter(
            array_map('intval', $employeeIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($employeeIds === []) {
            return 0;
        }

        $requestTypes = $requestTypes ?? [
            self::MODULE_LEAVE,
            self::MODULE_OVERTIME,
            self::MODULE_ATTENDANCE_CORRECTION,
            self::MODULE_CHANGE_SCHEDULE,
        ];
        $requestTypes = array_values(array_unique(array_map(
            fn (string $type): string => self::normalizeModuleType($type) ?? $type,
            $requestTypes,
        )));

        $synced = 0;
        if (in_array(self::MODULE_LEAVE, $requestTypes, true)) {
            $synced += $this->resyncPendingEmployeeRequests(
                LeaveRequest::query()
                    ->where('pending_approval', true)
                    ->where('status', LeaveRequest::STATUS_PENDING)
                    ->whereNull('rejected_at'),
                $employeeIds,
                self::MODULE_LEAVE,
                'start_date',
            );
        }

        if (in_array(self::MODULE_OVERTIME, $requestTypes, true)) {
            $synced += $this->resyncPendingEmployeeRequests(
                Overtime::query()
                    ->where('pending_approval', true)
                    ->where('status', Overtime::STATUS_PENDING)
                    ->whereNull('rejected_at'),
                $employeeIds,
                self::MODULE_OVERTIME,
                'date',
            );
        }

        if (in_array(self::MODULE_ATTENDANCE_CORRECTION, $requestTypes, true)) {
            $synced += $this->resyncPendingEmployeeRequests(
                AttendanceCorrection::query()
                    ->where('pending_approval', true)
                    ->where('approved', false)
                    ->whereNull('rejected_at')
                    ->when(
                        Schema::hasColumn('attendance_corrections', 'reversed_at'),
                        fn ($q) => $q->whereNull('reversed_at')
                    ),
                $employeeIds,
                self::MODULE_ATTENDANCE_CORRECTION,
                'date',
            );
        }

        if (in_array(self::MODULE_CHANGE_SCHEDULE, $requestTypes, true)) {
            $synced += $this->resyncPendingEmployeeRequests(
                ScheduleRequest::query()
                    ->where('pending_approval', true)
                    ->where('status', ScheduleRequest::STATUS_PENDING)
                    ->whereNull('rejected_at'),
                $employeeIds,
                self::MODULE_CHANGE_SCHEDULE,
                'effective_from',
                fn (ScheduleRequest $request): string => $this->scheduleApprovalModuleType($request),
            );
        }

        return $synced;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<int>  $employeeIds
     * @param  callable|null  $moduleTypeResolver
     */
    private function resyncPendingEmployeeRequests(
        $query,
        array $employeeIds,
        string $moduleType,
        string $assignmentDateColumn,
        ?callable $moduleTypeResolver = null,
    ): int {
        $synced = 0;
        $query
            ->whereIn('user_id', $employeeIds)
            ->with(['user', 'filedBy'])
            ->orderBy('id')
            ->chunkById(100, function ($requests) use (&$synced, $moduleType, $assignmentDateColumn, $moduleTypeResolver): void {
                foreach ($requests as $request) {
                    $employee = $request->user;
                    if (! $employee instanceof User) {
                        continue;
                    }

                    $resolvedModuleType = $moduleTypeResolver
                        ? (string) $moduleTypeResolver($request)
                        : $moduleType;
                    $requestor = $request->filedBy instanceof User ? $request->filedBy : $employee;
                    if ($this->resyncRequestChainForCurrentAssignment(
                        $request,
                        $resolvedModuleType,
                        $employee,
                        $requestor,
                        $assignmentDateColumn,
                    )) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    private function resyncRequestChainForCurrentAssignment(
        Model $request,
        string $moduleType,
        User $employee,
        User $requestor,
        string $assignmentDateColumn,
    ): bool {
        $organizationAssignments = app(EmployeeOrganizationAssignmentService::class);
        $assignment = $organizationAssignments->resolveRequestAssignment(
            $employee,
            null,
            $request->getAttribute($assignmentDateColumn),
        );
        $context = $organizationAssignments->requestContextPayload($assignment);
        $snapshotChanged = false;
        foreach ($context as $column => $value) {
            if ($request->getAttribute($column) != $value) {
                $snapshotChanged = true;
                break;
            }
        }

        if ($snapshotChanged) {
            $request->forceFill($context + [
                'first_approver_id' => null,
                'first_approved_at' => null,
                'approval_stage' => HrApprovalStages::PENDING_FIRST,
                'second_approved_at' => null,
            ])->save();
            OrgApprovalRecord::query()
                ->where('module_type', $moduleType)
                ->where('request_id', (int) $request->getKey())
                ->delete();
            ReviewRequestCache::forget($moduleType, (int) $request->getKey());
        }

        return $this->resyncRequestChain($request, $moduleType, $employee, $requestor);
    }

    private function scheduleApprovalModuleType(ScheduleRequest $request): string
    {
        if (OrgApprovalRecord::query()
            ->where('module_type', self::MODULE_SCHEDULE)
            ->where('request_id', (int) $request->getKey())
            ->exists()) {
            return self::MODULE_SCHEDULE;
        }

        return self::MODULE_CHANGE_SCHEDULE;
    }

    /**
     * Limit pending-filing resync to employees under a legacy org unit (head change path).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array{legacy_type: string, legacy_id: int}|null  $scope
     */
    private function applyEmployeeOrgScope($query, ?array $scope): void
    {
        if ($scope === null) {
            return;
        }

        $legacyType = (string) $scope['legacy_type'];
        $legacyId = (int) $scope['legacy_id'];
        if ($legacyId <= 0) {
            return;
        }

        $requestTable = $query->getModel()->getTable();
        $snapshotColumn = match ($legacyType) {
            'company' => 'company_id',
            'branch' => 'branch_id',
            'division' => 'division_id',
            'department' => 'department_id',
            'section_unit' => 'section_unit_id',
            default => null,
        };

        $query->where(function ($scopeQuery) use ($legacyType, $legacyId, $requestTable, $snapshotColumn): void {
            $scopeQuery->whereHas('user', function ($userQuery) use ($legacyType, $legacyId): void {
                match ($legacyType) {
                    'company' => $userQuery->where('company_id', $legacyId),
                    'branch' => $userQuery->where('branch_id', $legacyId),
                    'division' => $userQuery->where('division_id', $legacyId),
                    'department' => $userQuery->where('department_id', $legacyId),
                    'section_unit' => $userQuery->where('section_unit_id', $legacyId),
                    'area' => $userQuery->whereHas('branch', fn ($branchQuery) => $branchQuery->where('area_id', $legacyId)),
                    default => $userQuery->whereRaw('1 = 0'),
                };
            });

            if ($snapshotColumn !== null && Schema::hasColumn($requestTable, $snapshotColumn)) {
                $scopeQuery->orWhere($requestTable.'.'.$snapshotColumn, $legacyId);
            }

            if ($legacyType === 'area' && Schema::hasColumn($requestTable, 'branch_id') && Schema::hasColumn('branches', 'area_id')) {
                $branchIds = DB::table('branches')
                    ->where('area_id', $legacyId)
                    ->pluck('id');

                if ($branchIds->isNotEmpty()) {
                    $scopeQuery->orWhereIn($requestTable.'.branch_id', $branchIds);
                }
            }
        });
    }

    private function requestHasApprovalRoutingSnapshot(Model $request): bool
    {
        foreach (['assignment_id', 'company_id', 'branch_id', 'division_id', 'department_id', 'section_unit_id'] as $column) {
            $value = $request->getAttribute($column);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a re-resolved chain would remove a still-pending non-HR approver
     * (e.g. stale overtime company/department snapshot resolves to Admin HR only).
     *
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function wouldDropActiveOrgPendingApprover(EloquentCollection $existing, array $steps): bool
    {
        $pendingOrg = $existing->first(
            fn (OrgApprovalRecord $record): bool => $record->approval_status === OrgApprovalRecord::STATUS_PENDING
                && $record->approver_role !== HrRole::AdminHr->value
                && (int) ($record->approver_id ?? 0) > 0
        );

        if ($pendingOrg === null) {
            return false;
        }

        foreach ($steps as $step) {
            $role = $step['approver_role'] ?? null;
            $roleValue = $role instanceof HrRole ? $role->value : (string) $role;
            if ($roleValue === HrRole::AdminHr->value) {
                continue;
            }
            if ((int) ($step['approver_id'] ?? 0) === (int) $pendingOrg->approver_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Keep a non-HR pending approver only when dropping it would likely hide a
     * still-valid workflow step. Company Head/Admin HR requesters legitimately
     * route straight to Admin HR, so stale legacy first approvers should sync away.
     *
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function shouldKeepActiveOrgPendingApprover(
        Model $request,
        string $moduleType,
        User $employee,
        EloquentCollection $existing,
        array $steps,
    ): bool {
        if (! $this->wouldDropActiveOrgPendingApprover($existing, $steps)) {
            return false;
        }

        if ($this->workflowSettingService->isHrOnlyRequestType(self::normalizeModuleType($moduleType))) {
            return false;
        }

        $subjectRole = $this->roleResolver->resolveForApprovalSubject($employee);
        if (in_array($subjectRole, [HrRole::CompanyHead, HrRole::AdminHr], true)) {
            return false;
        }

        return $this->requestHasApprovalRoutingSnapshot($request);
    }

    /**
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function chainNeedsSync(EloquentCollection $existing, array $steps, string $moduleType): bool
    {
        if ($this->workflowSettingService->isHrOnlyRequestType(self::normalizeModuleType($moduleType))) {
            $sorted = $existing->sortBy('sequence_order')->values();
            if ($sorted->count() !== 1) {
                return true;
            }

            return $sorted->first()?->approver_role !== HrRole::AdminHr->value;
        }

        if ($existing->count() !== count($steps)) {
            return true;
        }

        $sorted = $existing->sortBy('sequence_order')->values();
        foreach ($steps as $index => $step) {
            $record = $sorted->get($index);
            if (! $record) {
                return true;
            }

            if ((int) $record->approver_id !== (int) $step['approver_id']) {
                return true;
            }

            if ($record->approver_role !== $step['approver_role']->value) {
                return true;
            }

            if (($record->approval_label ?? null) !== ($step['approval_label'] ?? null)) {
                return true;
            }
        }

        return $sorted->last()?->approver_role !== HrRole::AdminHr->value;
    }

    private function requestIsPending(Model $request, string $moduleType): bool
    {
        if (isset($request->rejected_at) && $request->rejected_at !== null) {
            return false;
        }

        if (isset($request->status) && in_array((string) $request->status, ['approved', 'rejected', 'cancelled'], true)) {
            return false;
        }

        if (isset($request->approved) && $request->approved === true) {
            return false;
        }

        if (isset($request->pending_approval)) {
            return (bool) $request->pending_approval;
        }

        return true;
    }

    /**
     * True when a pending request's stored chain cannot be acted on (no pending step),
     * or still shows Completed/Rejected from a prior filing before the current filed_at.
     *
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     */
    private function pendingRequestHasStaleApprovals(Model $request, EloquentCollection $existing): bool
    {
        if ($existing->isEmpty()) {
            return false;
        }

        $hasPendingStep = $existing->contains(
            fn (OrgApprovalRecord $record): bool => $record->approval_status === OrgApprovalRecord::STATUS_PENDING
        );
        if (! $hasPendingStep) {
            return true;
        }

        $filedAt = $this->requestFiledAt($request);
        if ($filedAt === null) {
            return false;
        }

        return $existing->contains(function (OrgApprovalRecord $record) use ($filedAt): bool {
            if (! in_array($record->approval_status, [
                OrgApprovalRecord::STATUS_APPROVED,
                OrgApprovalRecord::STATUS_REJECTED,
            ], true)) {
                return false;
            }

            if ($record->approved_at === null) {
                return false;
            }

            return $record->approved_at->lt($filedAt);
        });
    }

    private function requestFiledAt(Model $request): ?Carbon
    {
        $value = $request->getAttribute('filed_at')
            ?? $request->getAttribute('submitted_at')
            ?? null;

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value !== null && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     */
    private function syncRecordsToChain(
        Model $request,
        string $moduleType,
        int $requestId,
        array $steps,
        EloquentCollection $existing,
        User $employee,
        ?User $requestor,
    ): void {
        DB::transaction(function () use ($request, $moduleType, $requestId, $steps, $existing): void {
            OrgApprovalRecord::query()
                ->where('module_type', $moduleType)
                ->where('request_id', $requestId)
                ->delete();

            $filedAt = $this->requestFiledAt($request);
            $planned = [];

            foreach ($steps as $step) {
                $prior = $existing->first(
                    fn (OrgApprovalRecord $record): bool => $record->approver_role === $step['approver_role']->value
                        && (int) $record->approver_id === (int) $step['approver_id'],
                );
                $status = $this->legacyStatusForStep($request, $step);
                $approvedAt = null;
                $remarks = null;

                if ($prior && in_array($prior->approval_status, [
                    OrgApprovalRecord::STATUS_APPROVED,
                    OrgApprovalRecord::STATUS_REJECTED,
                ], true)) {
                    $priorAt = $prior->approved_at;
                    $staleVsFile = $filedAt !== null
                        && $priorAt !== null
                        && $priorAt->lt($filedAt);

                    if (! $staleVsFile) {
                        $status = $prior->approval_status;
                        $approvedAt = $priorAt;
                        $remarks = $prior->remarks;
                    }
                }

                if ($status === OrgApprovalRecord::STATUS_APPROVED && $approvedAt === null) {
                    $approvedAt = $this->legacyApprovedAtForStep($request, $step);
                }

                $planned[] = [
                    'request_id' => $requestId,
                    'module_type' => $moduleType,
                    'approval_level' => $step['approval_level'],
                    'approval_label' => $step['approval_label'] ?? null,
                    'approver_role' => $step['approver_role']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'eligible_approver_ids' => $step['eligible_approver_ids'] ?? null,
                    'routing_rule' => $step['routing_rule'] ?? null,
                    'approval_status' => $status,
                    'remarks' => $remarks,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                ];
            }

            // Never leave a pending request with only Completed/Rejected steps after sync.
            if ($this->requestIsPending($request, $moduleType)
                && ! collect($planned)->contains(
                    fn (array $row): bool => $row['approval_status'] === OrgApprovalRecord::STATUS_PENDING
                )
            ) {
                foreach ($planned as $index => $row) {
                    $planned[$index]['approval_status'] = OrgApprovalRecord::STATUS_PENDING;
                    $planned[$index]['approved_at'] = null;
                    $planned[$index]['remarks'] = null;
                }
            }

            foreach ($planned as $row) {
                OrgApprovalRecord::query()->create($row);
            }

            $this->syncLegacyRequestApprovers($request, $moduleType, $steps);
        });

        $this->logSelfApprovalDetection($request, $moduleType, $steps, $employee, $requestor ?? $employee);
    }

    /**
     * @return EloquentCollection<int, OrgApprovalRecord>
     */
    public function records(string $moduleType, int $requestId): EloquentCollection
    {
        return OrgApprovalRecord::query()
            ->where('module_type', $moduleType)
            ->where('request_id', $requestId)
            ->orderBy('sequence_order')
            ->with('approver')
            ->get();
    }

    public function currentPendingRecord(Model $request, string $moduleType, User $employee, ?User $requestor = null): ?OrgApprovalRecord
    {
        return $this->ensureRecordsForRequest($request, $moduleType, $employee, $requestor)
            ->firstWhere('approval_status', OrgApprovalRecord::STATUS_PENDING);
    }

    /**
     * Clear prior approval steps when a request is re-filed so the UI/workflow
     * do not keep a stale "Completed" HR step from an earlier submission.
     *
     * @return EloquentCollection<int, OrgApprovalRecord>
     */
    public function resetRecordsForResubmit(Model $request, string $moduleType, User $employee, ?User $requestor = null): EloquentCollection
    {
        $requestId = (int) $request->getKey();
        OrgApprovalRecord::query()
            ->where('module_type', $moduleType)
            ->where('request_id', $requestId)
            ->delete();
        ReviewRequestCache::forget($moduleType, $requestId);

        return $this->ensureRecordsForRequest($request, $moduleType, $employee, $requestor ?? $employee);
    }

    public function canAct(
        User $actor,
        Model $request,
        string $moduleType,
        User $employee,
        ?User $requestor = null,
        bool $forbidSubjectSelfApproval = false
    ): bool {
        $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);

        if ($pending === null) {
            return false;
        }

        if ($forbidSubjectSelfApproval && (int) $actor->id === (int) $employee->id) {
            $result = $this->selfApprovalValidationResult($actor, $employee, $pending, $moduleType);
            $this->logApprovalActionValidation('can_act', $request, $moduleType, $employee, $actor, $pending, $requestor, $result);

            return (bool) $result['allowed'];
        }

        if ($pending->approver_role === HrRole::AdminHr->value) {
            return $this->roleResolver->resolve($actor) === HrRole::AdminHr;
        }

        return (int) $pending->approver_id === (int) $actor->id
            || $this->actorIsEligibleApprover($actor, $pending);
    }

    /**
     * Check an already-loaded pending record without resolving/syncing the whole chain.
     *
     * @return array{allowed: bool, deny_reason: ?string, self_approval_setting: ?bool, user_is_assigned_approver: bool, is_self_approval: bool}
     */
    public function authorizePendingRecord(User $actor, OrgApprovalRecord $pending, User $employee, string $moduleType): array
    {
        $isSelfApproval = $this->isAssignedSelfApproval($actor, $employee, $pending);
        $userIsAssignedApprover = (int) $pending->approver_id === (int) $actor->id
            || $this->actorIsEligibleApprover($actor, $pending);

        if ($pending->approval_status !== OrgApprovalRecord::STATUS_PENDING) {
            return [
                'allowed' => false,
                'deny_reason' => 'approval_step_is_not_pending',
                'self_approval_setting' => null,
                'user_is_assigned_approver' => $userIsAssignedApprover,
                'is_self_approval' => $isSelfApproval,
            ];
        }

        if ((int) $actor->id === (int) $employee->id) {
            $result = $this->selfApprovalValidationResult($actor, $employee, $pending, $moduleType);

            return [
                'allowed' => (bool) $result['allowed'],
                'deny_reason' => $result['deny_reason'],
                'self_approval_setting' => (bool) $result['self_approval_setting'],
                'user_is_assigned_approver' => (bool) $result['user_is_assigned_approver'],
                'is_self_approval' => (bool) $result['is_self_approval'],
            ];
        }

        if ($pending->approver_role === HrRole::AdminHr->value) {
            $allowed = $this->roleResolver->resolve($actor) === HrRole::AdminHr;

            return [
                'allowed' => $allowed,
                'deny_reason' => $allowed ? null : 'current_user_role_not_authorized_for_admin_hr_step',
                'self_approval_setting' => null,
                'user_is_assigned_approver' => $userIsAssignedApprover,
                'is_self_approval' => $isSelfApproval,
            ];
        }

        $allowed = $userIsAssignedApprover;

        return [
            'allowed' => $allowed,
            'deny_reason' => $allowed ? null : 'current_user_not_assigned_or_authorized_for_step',
            'self_approval_setting' => null,
            'user_is_assigned_approver' => $userIsAssignedApprover,
            'is_self_approval' => $isSelfApproval,
        ];
    }

    public function approveCurrent(Model $request, string $moduleType, User $employee, User $actor, ?string $remarks = null, ?User $requestor = null): ?OrgApprovalRecord
    {
        return DB::transaction(function () use ($request, $moduleType, $employee, $actor, $remarks, $requestor): ?OrgApprovalRecord {
            $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
            if (! $pending) {
                $this->logApprovalActionValidation('approve', $request, $moduleType, $employee, $actor, null, $requestor, [
                    'allowed' => false,
                    'deny_reason' => 'no_pending_approval_step',
                ]);

                return null;
            }
            if (! $this->canActorActOnRecord($actor, $pending)) {
                $this->logApprovalActionValidation('approve', $request, $moduleType, $employee, $actor, $pending, $requestor, [
                    'allowed' => false,
                    'deny_reason' => 'current_user_not_assigned_or_authorized_for_step',
                ]);

                return null;
            }
            if ((int) $actor->id === (int) $employee->id) {
                $result = $this->selfApprovalValidationResult($actor, $employee, $pending, $moduleType);
                if (! $result['allowed']) {
                    $this->logApprovalActionValidation('approve', $request, $moduleType, $employee, $actor, $pending, $requestor, $result);

                    return null;
                }
                $this->logApprovalActionValidation('approve', $request, $moduleType, $employee, $actor, $pending, $requestor, $result);
            } else {
                $this->logApprovalActionValidation('approve', $request, $moduleType, $employee, $actor, $pending, $requestor, [
                    'allowed' => true,
                    'deny_reason' => null,
                ]);
            }

            if ((int) $actor->id === (int) $employee->id && ! $this->canSelfApproveAssignedRecord($actor, $employee, $pending, $moduleType)) {
                return null;
            }

            $isSelfApproval = $this->isAssignedSelfApproval($actor, $employee, $pending);
            $pending->approval_status = OrgApprovalRecord::STATUS_APPROVED;
            $pending->remarks = $remarks;
            $pending->approved_at = now();
            $pending->approver_id = $actor->id;
            $pending->approver_name = $actor->display_name;
            $pending->save();

            Log::info($isSelfApproval ? 'self_approved' : 'approved_by', [
                'module_type' => $moduleType,
                'request_id' => (int) $request->getKey(),
                'employee_id' => (int) $employee->id,
                'requestor_id' => $requestor ? (int) $requestor->id : null,
                'approved_by' => (int) $actor->id,
                'approved_at' => $pending->approved_at?->toIso8601String(),
                'approval_record_id' => (int) $pending->id,
                'self_approval' => $isSelfApproval,
            ]);

            return $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
        });
    }

    public function rejectCurrent(Model $request, string $moduleType, User $employee, User $actor, string $remarks, ?User $requestor = null): ?OrgApprovalRecord
    {
        return DB::transaction(function () use ($request, $moduleType, $employee, $actor, $remarks, $requestor): ?OrgApprovalRecord {
            $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
            if (! $pending) {
                $this->logApprovalActionValidation('reject', $request, $moduleType, $employee, $actor, null, $requestor, [
                    'allowed' => false,
                    'deny_reason' => 'no_pending_approval_step',
                ]);

                return null;
            }
            if (! $this->canActorActOnRecord($actor, $pending)) {
                $this->logApprovalActionValidation('reject', $request, $moduleType, $employee, $actor, $pending, $requestor, [
                    'allowed' => false,
                    'deny_reason' => 'current_user_not_assigned_or_authorized_for_step',
                ]);

                return null;
            }
            if ((int) $actor->id === (int) $employee->id) {
                $result = $this->selfApprovalValidationResult($actor, $employee, $pending, $moduleType);
                if (! $result['allowed']) {
                    $this->logApprovalActionValidation('reject', $request, $moduleType, $employee, $actor, $pending, $requestor, $result);

                    return null;
                }
                $this->logApprovalActionValidation('reject', $request, $moduleType, $employee, $actor, $pending, $requestor, $result);
            } else {
                $this->logApprovalActionValidation('reject', $request, $moduleType, $employee, $actor, $pending, $requestor, [
                    'allowed' => true,
                    'deny_reason' => null,
                ]);
            }
            if ((int) $actor->id === (int) $employee->id && ! $this->canSelfApproveAssignedRecord($actor, $employee, $pending, $moduleType)) {
                return null;
            }

            $isSelfApproval = $this->isAssignedSelfApproval($actor, $employee, $pending);
            $pending->approval_status = OrgApprovalRecord::STATUS_REJECTED;
            $pending->remarks = $remarks;
            $pending->approved_at = now();
            $pending->approver_id = $actor->id;
            $pending->approver_name = $actor->display_name;
            $pending->save();

            Log::info($isSelfApproval ? 'self_rejected' : 'rejected_by', [
                'module_type' => $moduleType,
                'request_id' => (int) $request->getKey(),
                'employee_id' => (int) $employee->id,
                'requestor_id' => $requestor ? (int) $requestor->id : null,
                'rejected_by' => (int) $actor->id,
                'rejected_at' => $pending->approved_at?->toIso8601String(),
                'approval_record_id' => (int) $pending->id,
                'self_approval' => $isSelfApproval,
            ]);

            return $pending;
        });
    }

    public function isCurrentPendingHr(Model $request, string $moduleType, User $employee, ?User $requestor = null): bool
    {
        $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);

        return $pending !== null && $pending->approver_role === HrRole::AdminHr->value;
    }

    public function currentPendingLabel(Model $request, string $moduleType, User $employee, ?User $requestor = null): ?string
    {
        $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
        if ($pending === null) {
            return null;
        }

        $storedLabel = trim((string) ($pending->approval_label ?? ''));
        if ($storedLabel !== '') {
            return rtrim(str_ireplace(' approval', '', $storedLabel));
        }

        $role = HrRole::tryFrom((string) $pending->approver_role);

        return $role?->badgeLabel();
    }

    /**
     * Prefer the pending (current) approver; otherwise the latest approved/rejected actor.
     *
     * @return array{
     *   current_approver_id: ?int,
     *   current_approver: ?string,
     *   current_approver_name: ?string,
     *   current_approver_profile_image: ?string
     * }
     */
    public function listApproverDisplayFields(?OrgApprovalRecord $pending, ?OrgApprovalRecord $latestActed = null): array
    {
        $record = $pending ?? $latestActed;
        $name = $record?->approver?->display_name ?? $record?->approver_name;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        return [
            'current_approver_id' => $record?->approver_id !== null ? (int) $record->approver_id : null,
            'current_approver' => $name,
            'current_approver_name' => $name,
            'current_approver_profile_image' => $record?->approver?->profile_image_url,
        ];
    }

    /**
     * Resolve list Approver column from approval_progress steps.
     *
     * @param  list<array<string, mixed>>  $progress
     * @return array{
     *   current_approver_id: ?int,
     *   current_approver: ?string,
     *   current_approver_name: ?string,
     *   current_approver_profile_image: ?string
     * }
     */
    public static function listApproverDisplayFieldsFromProgress(array $progress): array
    {
        $current = null;
        $lastActed = null;
        foreach ($progress as $step) {
            if (! is_array($step)) {
                continue;
            }
            $status = (string) ($step['status'] ?? '');
            $name = trim((string) ($step['approver_name'] ?? ''));
            if ($status === 'current') {
                $current = $step;
                break;
            }
            if (($status === 'completed' || $status === 'rejected') && $name !== '') {
                $lastActed = $step;
            }
        }

        $step = $current ?? $lastActed;
        $name = $step ? trim((string) ($step['approver_name'] ?? '')) : '';
        $name = $name !== '' ? $name : null;
        $image = is_array($step) ? ($step['profile_image_url'] ?? null) : null;
        $id = is_array($step) && isset($step['approver_id']) && is_numeric($step['approver_id'])
            ? (int) $step['approver_id']
            : null;

        return [
            'current_approver_id' => $id,
            'current_approver' => $name,
            'current_approver_name' => $name,
            'current_approver_profile_image' => is_string($image) && $image !== '' ? $image : null,
        ];
    }

    /**
     * Latest approved/rejected step per request (highest sequence), for completed Approver column.
     *
     * @param  list<int>  $requestIds
     * @return array<int, OrgApprovalRecord>
     */
    public function latestActedApprovalRecords(string $moduleType, array $requestIds): array
    {
        $moduleType = self::normalizeModuleType($moduleType) ?? $moduleType;
        $requestIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $requestIds),
            static fn (int $id): bool => $id > 0,
        )));
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
                'approval_status',
                'sequence_order',
            ])
            ->where('module_type', $moduleType)
            ->whereIn('approval_status', [
                OrgApprovalRecord::STATUS_APPROVED,
                OrgApprovalRecord::STATUS_REJECTED,
            ])
            ->whereIn('request_id', $requestIds)
            ->whereNotExists(function ($later) use ($moduleType): void {
                $later
                    ->selectRaw('1')
                    ->from('org_approval_records as later_approval')
                    ->whereColumn('later_approval.request_id', 'org_approval_records.request_id')
                    ->where('later_approval.module_type', $moduleType)
                    ->whereIn('later_approval.approval_status', [
                        OrgApprovalRecord::STATUS_APPROVED,
                        OrgApprovalRecord::STATUS_REJECTED,
                    ])
                    ->where(function ($q): void {
                        $q->whereColumn('later_approval.sequence_order', '>', 'org_approval_records.sequence_order')
                            ->orWhere(function ($same): void {
                                $same->whereColumn('later_approval.sequence_order', '=', 'org_approval_records.sequence_order')
                                    ->whereColumn('later_approval.id', '>', 'org_approval_records.id');
                            });
                    });
            })
            ->with('approver:id,name,first_name,middle_name,last_name,suffix,profile_image')
            ->orderByDesc('sequence_order')
            ->orderByDesc('id')
            ->get()
            ->unique('request_id')
            ->mapWithKeys(fn (OrgApprovalRecord $record): array => [(int) $record->request_id => $record])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildApprovalProgress(
        Model $request,
        string $moduleType,
        User $employee,
        ?User $submitter,
        mixed $submittedAt,
        bool $finalApproved,
        bool $rejected,
        ?User $requestor = null
    ): array {
        $records = $this->ensureRecordsForRequest($request, $moduleType, $employee, $requestor ?? $submitter ?? $employee);
        if ($records->isEmpty()) {
            return [];
        }

        $steps = [[
            'key' => 'submitted',
            'label' => 'Request submitted',
            'status' => 'completed',
            'approver_role_label' => null,
            'submitter_name' => ($submitter ?? $employee)->display_name,
            'approver_name' => null,
            'profile_image_url' => ($submitter ?? $employee)->profile_image_url,
            'acted_at' => $this->toIso8601String($submittedAt),
            'remarks' => null,
            'is_self_approval' => false,
        ]];

        $currentMarked = false;
        foreach ($records as $record) {
            $role = HrRole::tryFrom((string) $record->approver_role);
            $isHr = $role === HrRole::AdminHr;
            $status = match ($record->approval_status) {
                OrgApprovalRecord::STATUS_APPROVED => 'completed',
                OrgApprovalRecord::STATUS_REJECTED => 'rejected',
                default => $rejected ? 'skipped' : ($currentMarked || $finalApproved ? 'pending' : 'current'),
            };
            if ($status === 'current') {
                $currentMarked = true;
            }

            $roleLabel = $this->formatApprovalStepLabel($record, $role);
            $steps[] = [
                'key' => $isHr ? 'hr_final' : 'approval_'.$record->sequence_order,
                'label' => $isHr ? 'Admin HR final approval' : $roleLabel,
                'status' => $status,
                'approver_role_label' => $roleLabel,
                'submitter_name' => null,
                'approver_name' => $record->approver?->display_name ?? $record->approver_name,
                'profile_image_url' => $record->approver?->profile_image_url,
                'acted_at' => $this->toIso8601String($record->approved_at),
                'remarks' => $record->remarks,
                'sequence_order' => (int) $record->sequence_order,
                'approver_role' => $record->approver_role,
                'approver_id' => $record->approver_id,
                'is_self_approval' => $this->recordIsSelfApproval($record, $employee, $requestor ?? $submitter ?? $employee),
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function legacyStatusForStep(Model $request, array $step): string
    {
        $approvalStage = (string) ($request->approval_stage ?? '');
        $isHr = $this->stepIsAdminHr($step);

        if (in_array($approvalStage, ['approved'], true)) {
            return OrgApprovalRecord::STATUS_APPROVED;
        }

        if ($isHr && $this->legacyTimestampForColumns($request, ['second_approved_at', 'approved_at', 'reviewed_at'])) {
            return OrgApprovalRecord::STATUS_APPROVED;
        }

        if (! $isHr && $this->legacyTimestampForColumns($request, ['first_approved_at'])) {
            return OrgApprovalRecord::STATUS_APPROVED;
        }

        return OrgApprovalRecord::STATUS_PENDING;
    }

    private function canActorActOnRecord(User $actor, OrgApprovalRecord $record): bool
    {
        if ($record->approver_role === HrRole::AdminHr->value) {
            return $this->roleResolver->resolve($actor) === HrRole::AdminHr;
        }

        return (int) $record->approver_id === (int) $actor->id
            || $this->actorIsEligibleApprover($actor, $record);
    }

    private function actorIsEligibleApprover(User $actor, OrgApprovalRecord $record): bool
    {
        $eligible = $record->eligible_approver_ids;
        if (! is_array($eligible) || $eligible === []) {
            return false;
        }

        return in_array((int) $actor->id, array_map('intval', $eligible), true);
    }

    private function canSelfApproveAssignedRecord(User $actor, User $employee, OrgApprovalRecord $record, string $moduleType): bool
    {
        return (bool) $this->selfApprovalValidationResult($actor, $employee, $record, $moduleType)['allowed'];
    }

    /**
     * @return array{allowed: bool, deny_reason: ?string, self_approval_setting: bool, user_is_assigned_approver: bool, is_self_approval: bool}
     */
    private function selfApprovalValidationResult(User $actor, User $employee, OrgApprovalRecord $record, string $moduleType): array
    {
        $isSelfApproval = $this->isAssignedSelfApproval($actor, $employee, $record);
        $userIsAssignedApprover = (int) $record->approver_id === (int) $actor->id
            || $this->actorIsEligibleApprover($actor, $record);
        $roleAllowed = $this->roleResolver->resolve($actor) === HrRole::AdminHr;
        $settingEnabled = $this->selfApprovalSettingEnabled($actor, $moduleType);

        $denyReason = null;
        if (! $isSelfApproval) {
            $denyReason = 'not_assigned_self_approval_step';
        } elseif (! $userIsAssignedApprover) {
            $denyReason = 'current_user_is_not_assigned_approver';
        } elseif (! $roleAllowed) {
            $denyReason = 'current_user_role_not_authorized_for_self_approval';
        } elseif (! $settingEnabled) {
            $denyReason = 'self_approval_setting_disabled';
        }

        return [
            'allowed' => $denyReason === null,
            'deny_reason' => $denyReason,
            'self_approval_setting' => $settingEnabled,
            'user_is_assigned_approver' => $userIsAssignedApprover,
            'is_self_approval' => $isSelfApproval,
        ];
    }

    private function selfApprovalSettingEnabled(User $actor, string $moduleType): bool
    {
        $setting = $this->workflowSettingService->resolveSetting(self::normalizeModuleType($moduleType));

        if ($actor->isSuperAdmin()) {
            return $this->settingFlag($setting, 'allow_super_admin_self_approval', true);
        }

        return $this->settingFlag($setting, 'allow_admin_self_approval', true)
            && $this->settingFlag($setting, 'allow_hr_self_approval', true);
    }

    /**
     * @param  array<string, mixed>  $setting
     */
    private function settingFlag(array $setting, string $key, bool $default): bool
    {
        return array_key_exists($key, $setting) ? (bool) $setting[$key] : $default;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function logApprovalActionValidation(
        string $action,
        Model $request,
        string $moduleType,
        User $employee,
        User $actor,
        ?OrgApprovalRecord $record,
        ?User $requestor,
        array $result,
    ): void {
        $roleValues = array_map(
            fn (HrRole $role): string => $role->value,
            $this->roleResolver->listEffectiveHrRoles($actor),
        );

        Log::info('approval_action_validation', [
            'action' => $action,
            'request_id' => (int) $request->getKey(),
            'request_type' => $moduleType,
            'requester_employee_id' => $requestor ? (int) $requestor->id : (int) $employee->id,
            'approver_employee_id' => $record ? (int) $record->approver_id : null,
            'current_user_id' => (int) $actor->id,
            'current_user_employee_id' => (int) $actor->id,
            'current_user_roles' => $roleValues,
            'is_self_approval' => (bool) ($result['is_self_approval'] ?? false),
            'self_approval_setting' => (bool) ($result['self_approval_setting'] ?? true),
            'user_is_assigned_approver' => (bool) ($result['user_is_assigned_approver'] ?? false),
            'approve_button_visible' => (bool) ($result['allowed'] ?? false),
            'validation_result' => (bool) ($result['allowed'] ?? false) ? 'allowed' : 'denied',
            'deny_reason' => $result['deny_reason'] ?? null,
            'final_request_status' => (string) ($request->getAttribute('status') ?? ($request->getAttribute('approved') ? 'approved' : 'pending')),
        ]);
    }

    private function isAssignedSelfApproval(User $actor, User $employee, OrgApprovalRecord $record): bool
    {
        return (int) $actor->id === (int) $employee->id
            && (int) $record->approver_id === (int) $actor->id;
    }

    private function recordIsSelfApproval(OrgApprovalRecord $record, User $employee, ?User $requestor): bool
    {
        $requesterId = $requestor ? (int) $requestor->id : (int) $employee->id;

        return (int) $record->approver_id === $requesterId
            && (int) $record->approver_id === (int) $employee->id
            && $record->approver_role === HrRole::AdminHr->value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function logSelfApprovalDetection(Model $request, string $moduleType, array $steps, User $employee, ?User $requestor): void
    {
        $requesterId = $requestor ? (int) $requestor->id : (int) $employee->id;
        foreach ($steps as $step) {
            if ((int) ($step['approver_id'] ?? 0) !== $requesterId || $requesterId !== (int) $employee->id) {
                continue;
            }

            $role = $step['approver_role'] ?? null;
            if (! $role instanceof HrRole || $role !== HrRole::AdminHr) {
                continue;
            }

            Log::info('self_approval_detected', [
                'module_type' => $moduleType,
                'request_id' => (int) $request->getKey(),
                'employee_id' => (int) $employee->id,
                'requestor_id' => $requesterId,
                'approver_id' => $requesterId,
                'sequence_order' => (int) ($step['sequence_order'] ?? 0),
                'approver_role' => $role->value,
            ]);

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function legacyApprovedAtForStep(Model $request, array $step): ?Carbon
    {
        return $this->stepIsAdminHr($step)
            ? $this->legacyTimestampForColumns($request, ['second_approved_at', 'approved_at', 'reviewed_at'])
            : $this->legacyTimestampForColumns($request, ['first_approved_at']);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepIsAdminHr(array $step): bool
    {
        $role = $step['approver_role'] ?? null;

        return $role instanceof HrRole
            ? $role === HrRole::AdminHr
            : (string) $role === HrRole::AdminHr->value;
    }

    /**
     * @param  list<string>  $columns
     */
    private function legacyTimestampForColumns(Model $request, array $columns): ?Carbon
    {
        foreach ($columns as $column) {
            $value = array_key_exists($column, $request->getAttributes())
                ? $request->getAttribute($column)
                : null;

            if ($value === null && $request->exists && Schema::hasColumn($request->getTable(), $column)) {
                $value = $request->newQueryWithoutRelationships()
                    ->whereKey($request->getKey())
                    ->value($column);
            }

            if ($value instanceof Carbon) {
                return $value;
            }
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value);
            }
            if (is_string($value) && $value !== '') {
                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function toIso8601String(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }
        if (is_string($value)) {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function syncLegacyRequestApprovers(Model $request, string $moduleType, array $steps): void
    {
        if (! $this->requestIsPending($request, $moduleType)) {
            return;
        }

        $normalized = self::normalizeModuleType($moduleType) ?? $moduleType;
        $firstLine = collect($steps)->first(fn (array $step): bool => ($step['approver_role'] ?? null) !== HrRole::AdminHr);
        $hrLine = collect($steps)->first(fn (array $step): bool => ($step['approver_role'] ?? null) === HrRole::AdminHr);
        $pendingIsHrOnly = $firstLine === null && $hrLine !== null;
        $firstStepAlreadyApproved = $this->legacyTimestampForColumns($request, ['first_approved_at']) !== null && $hrLine !== null;

        if ($normalized === self::MODULE_LEAVE && $request instanceof LeaveRequest) {
            $updates = [
                'first_approver_id' => $firstLine ? (int) $firstLine['approver_id'] : null,
            ];
            if ($hrLine) {
                $updates['second_approver_id'] = (int) $hrLine['approver_id'];
            }
            $request->forceFill($updates)->save();

            return;
        }

        if ($normalized === self::MODULE_OVERTIME && $request instanceof Overtime) {
            $updates = [
                'first_approver_id' => $firstLine ? (int) $firstLine['approver_id'] : null,
                'approval_stage' => ($pendingIsHrOnly || $firstStepAlreadyApproved)
                    ? HrApprovalStages::PENDING_SECOND
                    : HrApprovalStages::PENDING_FIRST,
            ];
            if ($hrLine) {
                $updates['second_approver_id'] = (int) $hrLine['approver_id'];
            }
            $request->forceFill($updates)->save();

            return;
        }

        if ($normalized === self::MODULE_ATTENDANCE_CORRECTION && $request instanceof AttendanceCorrection) {
            $updates = [
                'first_approver_id' => $firstLine ? (int) $firstLine['approver_id'] : null,
                'approval_stage' => ($pendingIsHrOnly || $firstStepAlreadyApproved)
                    ? HrApprovalStages::PENDING_SECOND
                    : HrApprovalStages::PENDING_FIRST,
            ];
            if ($hrLine) {
                $updates['second_approver_id'] = (int) $hrLine['approver_id'];
            }

            $before = [
                'first_approver_id' => $request->first_approver_id !== null ? (int) $request->first_approver_id : null,
                'second_approver_id' => $request->second_approver_id !== null ? (int) $request->second_approver_id : null,
                'approval_stage' => (string) ($request->approval_stage ?? ''),
            ];
            $request->forceFill($updates)->save();
            $after = [
                'first_approver_id' => $updates['first_approver_id'],
                'second_approver_id' => $updates['second_approver_id'] ?? $before['second_approver_id'],
                'approval_stage' => (string) $updates['approval_stage'],
            ];
            if ($before !== $after) {
                ReviewRequestCache::forget(self::MODULE_ATTENDANCE_CORRECTION, (int) $request->getKey());
                AttendanceCorrectionModuleCache::flush();
            }

            return;
        }

        if ($normalized === self::MODULE_CHANGE_SCHEDULE && $request instanceof ScheduleRequest) {
            $updates = [
                'first_approver_id' => $firstLine ? (int) $firstLine['approver_id'] : null,
                'approval_stage' => $pendingIsHrOnly
                    ? HrApprovalStages::PENDING_SECOND
                    : HrApprovalStages::PENDING_FIRST,
            ];
            if ($hrLine) {
                $updates['second_approver_id'] = (int) $hrLine['approver_id'];
            }

            $request->forceFill($updates)->save();
            ReviewRequestCache::forget($moduleType, (int) $request->getKey());
        }
    }

    private function formatApprovalStepLabel(OrgApprovalRecord $record, ?HrRole $role): string
    {
        $label = trim((string) ($record->approval_label ?? ''));
        if ($label !== '') {
            return $label;
        }

        $base = match ($role) {
            HrRole::DepartmentHead => 'Department Head',
            HrRole::SectionUnitHead => 'Section/Unit Head',
            HrRole::DivisionHead => 'Division Head',
            HrRole::BranchHead => 'Branch Head',
            HrRole::AreaHead => 'Area Head',
            HrRole::CompanyHead => 'Company Head',
            default => $role?->badgeLabel() ?? (string) $record->approver_role,
        };

        $lower = strtolower($base);
        if (str_contains($lower, 'team leader') || str_contains($lower, 'team lead')) {
            return 'Team Lead approval';
        }

        return str_contains($lower, 'approval') ? $base : $base.' approval';
    }

    /**
     * @param  array{legacy_type: string, legacy_id: int}|null  $scope
     */
    private function resyncPendingLeaveRequests(?array $scope = null): int
    {
        $synced = 0;

        $query = \App\Models\LeaveRequest::query()
            ->where('pending_approval', true)
            ->where('status', \App\Models\LeaveRequest::STATUS_PENDING)
            ->whereNull('rejected_at');
        $this->applyEmployeeOrgScope($query, $scope);

        $query
            ->with(['user', 'filedBy'])
            ->orderBy('id')
            ->chunkById(100, function ($leaves) use (&$synced): void {
                foreach ($leaves as $leave) {
                    $employee = $leave->user;
                    if (! $employee instanceof User) {
                        continue;
                    }

                    $requestor = $leave->filedBy instanceof User ? $leave->filedBy : $employee;
                    if ($this->resyncRequestChain($leave, self::MODULE_LEAVE, $employee, $requestor)) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    /**
     * @param  array{legacy_type: string, legacy_id: int}|null  $scope
     */
    private function resyncPendingOvertimeRequests(?array $scope = null): int
    {
        $synced = 0;

        $query = \App\Models\Overtime::query()
            ->where('pending_approval', true)
            ->where('status', \App\Models\Overtime::STATUS_PENDING)
            ->whereNull('rejected_at');
        $this->applyEmployeeOrgScope($query, $scope);

        $query
            ->with(['user', 'filedBy'])
            ->orderBy('id')
            ->chunkById(100, function ($overtimes) use (&$synced): void {
                foreach ($overtimes as $overtime) {
                    $employee = $overtime->user;
                    if (! $employee instanceof User) {
                        continue;
                    }

                    $requestor = $overtime->filedBy instanceof User ? $overtime->filedBy : $employee;
                    if ($this->resyncRequestChain($overtime, self::MODULE_OVERTIME, $employee, $requestor)) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    /**
     * @param  array{legacy_type: string, legacy_id: int}|null  $scope
     */
    private function resyncPendingAttendanceCorrectionRequests(?array $scope = null): int
    {
        $synced = 0;

        $query = \App\Models\AttendanceCorrection::query()
            ->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at')
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('attendance_corrections', 'reversed_at'),
                fn ($q) => $q->whereNull('reversed_at')
            );
        $this->applyEmployeeOrgScope($query, $scope);

        $query
            ->with(['user', 'filedBy'])
            ->orderBy('id')
            ->chunkById(100, function ($corrections) use (&$synced): void {
                foreach ($corrections as $correction) {
                    $employee = $correction->user;
                    if (! $employee instanceof User) {
                        continue;
                    }

                    $requestor = $correction->filedBy instanceof User ? $correction->filedBy : $employee;
                    if ($this->resyncRequestChain($correction, self::MODULE_ATTENDANCE_CORRECTION, $employee, $requestor)) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    /**
     * @param  array{legacy_type: string, legacy_id: int}|null  $scope
     */
    private function resyncPendingScheduleRequests(?array $scope = null): int
    {
        $synced = 0;

        $query = \App\Models\ScheduleRequest::query()
            ->where('pending_approval', true)
            ->where('status', \App\Models\ScheduleRequest::STATUS_PENDING)
            ->whereNull('rejected_at');
        $this->applyEmployeeOrgScope($query, $scope);

        $query
            ->with(['user', 'filedBy'])
            ->orderBy('id')
            ->chunkById(100, function ($requests) use (&$synced): void {
                foreach ($requests as $request) {
                    $employee = $request->user;
                    if (! $employee instanceof User) {
                        continue;
                    }

                    $requestor = $request->filedBy instanceof User ? $request->filedBy : $employee;
                    if ($this->resyncRequestChain($request, self::MODULE_CHANGE_SCHEDULE, $employee, $requestor)) {
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    private function resyncRequestChain(Model $request, string $moduleType, User $employee, User $requestor): bool
    {
        $requestId = (int) $request->getKey();
        ReviewRequestCache::forget($moduleType, $requestId);

        $before = $this->records($moduleType, $requestId)
            ->sortBy('sequence_order')
            ->values()
            ->map(fn (OrgApprovalRecord $record): array => [
                'approver_id' => (int) $record->approver_id,
                'approver_role' => (string) $record->approver_role,
                'approval_label' => $record->approval_label,
            ])
            ->all();

        $this->ensureRecordsForRequest($request, $moduleType, $employee, $requestor);

        $after = $this->records($moduleType, $requestId)
            ->sortBy('sequence_order')
            ->values()
            ->map(fn (OrgApprovalRecord $record): array => [
                'approver_id' => (int) $record->approver_id,
                'approver_role' => (string) $record->approver_role,
                'approval_label' => $record->approval_label,
            ])
            ->all();

        $changed = $before !== $after;

        // Legacy columns / list cache can lag even when org_approval_records already match.
        if ($moduleType === self::MODULE_CHANGE_SCHEDULE
            || $moduleType === self::MODULE_SCHEDULE
            || $moduleType === self::MODULE_ATTENDANCE_CORRECTION
            || $moduleType === self::MODULE_OVERTIME
            || $moduleType === self::MODULE_LEAVE) {
            $request->refresh();
        }

        if ($changed) {
            ReviewRequestCache::forget($moduleType, $requestId);
            if ($moduleType === self::MODULE_LEAVE) {
                LeaveModuleCache::flush();
            } elseif ($moduleType === self::MODULE_OVERTIME) {
                OvertimeModuleCache::flush();
            } elseif ($moduleType === self::MODULE_ATTENDANCE_CORRECTION) {
                AttendanceCorrectionModuleCache::flush();
            }
        }

        return $changed;
    }

    private function employeeForApprovalRouting(User $employee): User
    {
        $employeeId = (int) $employee->id;
        $cacheKey = 'org_approval_routing_user:'.$employeeId;
        $cached = Cache::store('array')->get($cacheKey);
        if ($cached instanceof User) {
            return $cached;
        }

        $relations = ['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'];
        $missing = array_values(array_filter(
            $relations,
            static fn (string $relation): bool => ! $employee->relationLoaded($relation),
        ));
        if ($missing === []) {
            Cache::store('array')->put($cacheKey, $employee, 3600);

            return $employee;
        }

        $loaded = User::query()
            ->with($relations)
            ->findOrFail($employeeId);
        Cache::store('array')->put($cacheKey, $loaded, 3600);

        return $loaded;
    }
}
