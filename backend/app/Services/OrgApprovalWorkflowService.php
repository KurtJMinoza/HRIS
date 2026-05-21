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

    public function __construct(
        private readonly HrApprovalChainResolver $chainResolver,
        private readonly HrRoleResolver $roleResolver,
    ) {}

    /**
     * @return EloquentCollection<int, OrgApprovalRecord>
     */
    public function ensureRecordsForRequest(
        Model $request,
        string $moduleType,
        User $employee,
        ?User $requestor = null
    ): EloquentCollection {
        $requestId = (int) $request->getKey();
        $steps = $this->chainResolver->resolveApprovalChain($employee, $moduleType, $requestor ?? $employee);
        if ($steps === []) {
            return new EloquentCollection;
        }

        $existing = $this->records($moduleType, $requestId);
        if ($existing->isNotEmpty()) {
            if ($this->chainNeedsSync($existing, $steps) && $this->requestIsPending($request, $moduleType)) {
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
                    'approver_role' => $step['approver_role']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'approval_status' => $legacyStatus,
                    'remarks' => null,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                ]);
            }
        });

        return $this->records($moduleType, $requestId);
    }

    /**
     * @param  EloquentCollection<int, OrgApprovalRecord>  $existing
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function chainNeedsSync(EloquentCollection $existing, array $steps): bool
    {
        if ($existing->count() !== count($steps)) {
            return true;
        }

        $sorted = $existing->sortBy('sequence_order')->values();
        foreach ($steps as $index => $step) {
            $record = $sorted->get($index);
            if (! $record || $record->approver_role !== $step['approver_role']->value) {
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
                    'approver_role' => $step['approver_role']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'approval_status' => $status,
                    'remarks' => $prior?->remarks,
                    'approved_at' => $approvedAt,
                    'sequence_order' => $step['sequence_order'],
                ]);
            }
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

        return (int) $pending->approver_id === (int) $actor->id;
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
        $role = $pending ? HrRole::tryFrom((string) $pending->approver_role) : null;

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

            $roleLabel = $role?->badgeLabel() ?? (string) $record->approver_role;
            $steps[] = [
                'key' => $isHr ? 'hr_final' : 'approval_'.$record->sequence_order,
                'label' => $isHr ? 'Admin HR final approval' : $roleLabel.' approval',
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

        return (int) $record->approver_id === (int) $actor->id;
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
}
