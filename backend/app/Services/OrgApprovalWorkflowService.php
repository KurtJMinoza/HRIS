<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            if ($this->chainNeedsSync($existing, $steps, $moduleType) && $this->requestIsPending($request, $moduleType)) {
                Log::info('approval_chain: syncing org approval records for pending request', [
                    'module_type' => $moduleType,
                    'request_id' => $requestId,
                    'existing_count' => $existing->count(),
                    'resolved_count' => count($steps),
                ]);
                $this->syncRecordsToChain($request, $moduleType, $requestId, $steps, $existing);
            }

            return $this->records($moduleType, $requestId);
        }

        DB::transaction(function () use ($steps, $request, $moduleType, $requestId): void {
            foreach ($steps as $step) {
                $legacyStatus = $this->legacyStatusForStep($request, $step);
                $approvedAt = $legacyStatus === OrgApprovalRecord::STATUS_APPROVED
                    ? $this->legacyApprovedAtForStep($request, $step)
                    : null;

                OrgApprovalRecord::query()->create([
                    'request_id' => $requestId,
                    'module_type' => $moduleType,
                    'approval_level' => $step['approval_level'],
                    'approval_label' => $step['approval_label'] ?? null,
                    'approver_role' => $step['approver_role']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'eligible_approver_ids' => $step['eligible_approver_ids'] ?? null,
                    'routing_rule' => $step['routing_rule'] ?? null,
                    'approval_status' => $legacyStatus,
                    'remarks' => null,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                ]);
            }

            $this->syncLegacyRequestApprovers($request, $moduleType, $steps);
        });

        return $this->records($moduleType, $requestId);
    }

    /**
     * Re-resolve approval chains for pending requests after workflow settings change.
     *
     * @param  list<string>  $requestTypes
     */
    public function resyncPendingRequestChains(array $requestTypes): int
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            fn (string $type): ?string => self::normalizeModuleType($type),
            $requestTypes,
        ))));

        $synced = 0;

        if (in_array(self::MODULE_LEAVE, $normalized, true)) {
            $synced += $this->resyncPendingLeaveRequests();
        }

        if (in_array(self::MODULE_OVERTIME, $normalized, true)) {
            $synced += $this->resyncPendingOvertimeRequests();
        }

        if ($synced > 0) {
            Log::info('approval_chain: resynced pending request chains after workflow settings change', [
                'request_types' => $normalized,
                'requests_updated' => $synced,
            ]);
        }

        return $synced;
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
     * @param  array<int, array<string, mixed>>  $steps
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     */
    private function syncRecordsToChain(
        Model $request,
        string $moduleType,
        int $requestId,
        array $steps,
        EloquentCollection $existing,
    ): void {
        DB::transaction(function () use ($request, $moduleType, $requestId, $steps, $existing): void {
            OrgApprovalRecord::query()
                ->where('module_type', $moduleType)
                ->where('request_id', $requestId)
                ->delete();

            foreach ($steps as $step) {
                $prior = $existing->firstWhere('approver_role', $step['approver_role']->value);
                $status = $prior?->approval_status ?? $this->legacyStatusForStep($request, $step);
                $approvedAt = $status === OrgApprovalRecord::STATUS_APPROVED
                    ? ($prior?->approved_at ?? $this->legacyApprovedAtForStep($request, $step))
                    : null;

                OrgApprovalRecord::query()->create([
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
                    'remarks' => $prior?->remarks,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                ]);
            }

            $this->syncLegacyRequestApprovers($request, $moduleType, $steps);
        });
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

    public function canAct(
        User $actor,
        Model $request,
        string $moduleType,
        User $employee,
        ?User $requestor = null,
        bool $forbidSubjectSelfApproval = false
    ): bool {
        if ($forbidSubjectSelfApproval && (int) $actor->id === (int) $employee->id) {
            return false;
        }

        $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);

        if ($pending === null) {
            return false;
        }

        if ($pending->approver_role === HrRole::AdminHr->value) {
            return $this->roleResolver->resolve($actor) === HrRole::AdminHr;
        }

        return (int) $pending->approver_id === (int) $actor->id
            || $this->actorIsEligibleApprover($actor, $pending);
    }

    public function approveCurrent(Model $request, string $moduleType, User $employee, User $actor, ?string $remarks = null, ?User $requestor = null): ?OrgApprovalRecord
    {
        return DB::transaction(function () use ($request, $moduleType, $employee, $actor, $remarks, $requestor): ?OrgApprovalRecord {
            $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
            if (! $pending || ! $this->canActorActOnRecord($actor, $pending)) {
                return null;
            }

            $pending->approval_status = OrgApprovalRecord::STATUS_APPROVED;
            $pending->remarks = $remarks;
            $pending->approved_at = now();
            $pending->approver_id = $actor->id;
            $pending->approver_name = $actor->display_name;
            $pending->save();

            return $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
        });
    }

    public function rejectCurrent(Model $request, string $moduleType, User $employee, User $actor, string $remarks, ?User $requestor = null): ?OrgApprovalRecord
    {
        return DB::transaction(function () use ($request, $moduleType, $employee, $actor, $remarks, $requestor): ?OrgApprovalRecord {
            $pending = $this->currentPendingRecord($request, $moduleType, $employee, $requestor);
            if (! $pending || ! $this->canActorActOnRecord($actor, $pending)) {
                return null;
            }

            $pending->approval_status = OrgApprovalRecord::STATUS_REJECTED;
            $pending->remarks = $remarks;
            $pending->approved_at = now();
            $pending->approver_id = $actor->id;
            $pending->approver_name = $actor->display_name;
            $pending->save();

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
        $isHr = $step['approver_role'] === HrRole::AdminHr;

        if (in_array($approvalStage, ['approved'], true)) {
            return OrgApprovalRecord::STATUS_APPROVED;
        }

        if ($isHr && $request->second_approved_at) {
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

    /**
     * @param  array<string, mixed>  $step
     */
    private function legacyApprovedAtForStep(Model $request, array $step): ?Carbon
    {
        $value = $step['approver_role'] === HrRole::AdminHr
            ? ($request->second_approved_at ?? $request->approved_at ?? $request->reviewed_at ?? null)
            : ($request->first_approved_at ?? null);

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
        if ($moduleType !== self::MODULE_LEAVE || ! $request instanceof \App\Models\LeaveRequest) {
            return;
        }

        if (! $this->requestIsPending($request, $moduleType)) {
            return;
        }

        $firstLine = collect($steps)->first(fn (array $step): bool => ($step['approver_role'] ?? null) !== HrRole::AdminHr);
        $hrLine = collect($steps)->first(fn (array $step): bool => ($step['approver_role'] ?? null) === HrRole::AdminHr);

        $updates = [];
        if ($firstLine) {
            $updates['first_approver_id'] = (int) $firstLine['approver_id'];
        } else {
            $updates['first_approver_id'] = null;
        }

        if ($hrLine) {
            $updates['second_approver_id'] = (int) $hrLine['approver_id'];
        }

        if ($updates !== []) {
            $request->forceFill($updates)->save();
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
            HrRole::CompanyHead => 'Company Head',
            default => $role?->badgeLabel() ?? (string) $record->approver_role,
        };

        $lower = strtolower($base);
        if (str_contains($lower, 'team leader') || str_contains($lower, 'team lead')) {
            return 'Team Lead approval';
        }

        return str_contains($lower, 'approval') ? $base : $base.' approval';
    }

    private function resyncPendingLeaveRequests(): int
    {
        $synced = 0;

        \App\Models\LeaveRequest::query()
            ->where('pending_approval', true)
            ->where('status', \App\Models\LeaveRequest::STATUS_PENDING)
            ->whereNull('rejected_at')
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

    private function resyncPendingOvertimeRequests(): int
    {
        $synced = 0;

        \App\Models\Overtime::query()
            ->where('pending_approval', true)
            ->where('status', \App\Models\Overtime::STATUS_PENDING)
            ->whereNull('rejected_at')
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

    private function resyncRequestChain(Model $request, string $moduleType, User $employee, User $requestor): bool
    {
        $requestId = (int) $request->getKey();
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

        return $before !== $after;
    }

    private function employeeForApprovalRouting(User $employee): User
    {
        return User::query()
            ->with(['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'])
            ->findOrFail((int) $employee->id);
    }
}
