<?php

namespace App\Services;

use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationPositionType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationLeadershipService
{
    public const LEGACY_LEVEL_MAP = [
        'company' => 'company',
        'area' => 'area',
        'branch' => 'branch',
        'division' => 'division',
        'department' => 'department',
        'section_unit' => 'section_unit',
    ];

    public function __construct(
        private readonly LegacyOrganizationMirrorService $mirrorService,
        private readonly OrganizationLeadershipAssignmentScopeService $assignmentScopeService,
        private readonly ApprovalChainCacheService $approvalChainCacheService,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedLegacyTypes(): array
    {
        return array_keys(self::LEGACY_LEVEL_MAP);
    }

    public function organizationLevelForLegacyType(string $legacyType): string
    {
        $level = self::LEGACY_LEVEL_MAP[$legacyType] ?? null;
        if ($level === null) {
            throw ValidationException::withMessages(['legacy_type' => ['Unsupported organization level.']]);
        }

        return $level;
    }

    public function resolveUnit(string $legacyType, int $legacyId): OrganizationUnit
    {
        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        if ($unit) {
            return $unit;
        }

        $this->mirrorService->syncLegacyRecord($legacyType, $legacyId);
        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages(['legacy_id' => ['Organization unit could not be resolved for this record.']]);
        }

        return $unit;
    }

    /**
     * @return Collection<int, OrganizationPositionType>
     */
    public function positionTypesForLevel(string $organizationLevel): Collection
    {
        $types = OrganizationPositionType::query()
            ->where('organization_level', $organizationLevel)
            ->where('is_active', true)
            ->orderBy('approval_priority')
            ->orderBy('position_name')
            ->get();

        if ($organizationLevel === 'area' && $types->contains(fn (OrganizationPositionType $type): bool => $type->position_name === 'Area Head / Area Manager')) {
            return $types
                ->reject(fn (OrganizationPositionType $type): bool => in_array($type->position_name, ['Area Head', 'Head'], true))
                ->values();
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    public function leadershipPayload(string $legacyType, int $legacyId): array
    {
        $unit = $this->resolveUnit($legacyType, $legacyId);
        $level = $this->organizationLevelForLegacyType($legacyType);
        if ($legacyType === 'area') {
            $this->normalizeAreaHeadAssignments($unit);
        }

        $assignmentRows = OrganizationPositionAssignment::query()
            ->with(['employee', 'positionType', 'activeDepartmentScopes'])
            ->where('organization_unit_id', (int) $unit->id)
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        if (in_array($legacyType, ['company', 'area', 'branch'], true)) {
            $assignmentRows = $assignmentRows->unique(fn (OrganizationPositionAssignment $assignment): int => (int) $assignment->employee_id)->values();
        }

        $assignments = $assignmentRows
            ->map(fn (OrganizationPositionAssignment $assignment): array => $this->assignmentPayload($assignment, $legacyType))
            ->values()
            ->all();

        $payload = [
            'organization_unit_id' => (int) $unit->id,
            'organization_level' => $level,
            'legacy_source_type' => $legacyType,
            'legacy_source_id' => $legacyId,
            'position_types' => $this->positionTypesForLevel($level)
                ->map(fn (OrganizationPositionType $type): array => [
                    'id' => (int) $type->id,
                    'organization_level' => $type->organization_level,
                    'position_name' => $type->position_name,
                    'approval_priority' => (int) $type->approval_priority,
                    'can_approve' => (bool) $type->can_approve,
                    'is_active' => (bool) $type->is_active,
                ])
                ->values()
                ->all(),
            'assignments' => $assignments,
        ];

        if (in_array($legacyType, ['company', 'area', 'branch', 'division'], true)) {
            $payload['approval_scope_options'] = $this->assignmentScopeService->approvalScopeOptions($legacyType, $legacyId);
        }

        if ($legacyType === 'division') {
            $payload['departments'] = $this->assignmentScopeService->departmentsForDivision($legacyId);
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<string, mixed>
     */
    public function syncLeadership(string $legacyType, int $legacyId, array $assignments): array
    {
        $unit = $this->resolveUnit($legacyType, $legacyId);
        $level = $this->organizationLevelForLegacyType($legacyType);
        if ($legacyType === 'area') {
            $this->normalizeAreaHeadAssignments($unit);
        }

        DB::transaction(function () use ($unit, $level, $assignments, $legacyType, $legacyId): void {
            $seenKeys = [];
            $seenIds = [];
            foreach ($assignments as $index => $row) {
                $positionTypeId = (int) ($row['position_type_id'] ?? 0);
                $employeeId = (int) ($row['employee_id'] ?? 0);
                if ($positionTypeId <= 0 || $employeeId <= 0) {
                    continue;
                }

                $dedupeKey = in_array($legacyType, ['company', 'area', 'branch'], true)
                    ? (string) $employeeId
                    : $positionTypeId.'|'.$employeeId;
                if (isset($seenKeys[$dedupeKey])) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.employee_id" => ['This employee is already assigned as a head for this unit.'],
                    ]);
                }
                $seenKeys[$dedupeKey] = true;

                $this->assertActiveEmployee($employeeId);
                $positionType = OrganizationPositionType::query()
                    ->whereKey($positionTypeId)
                    ->where('organization_level', $level)
                    ->first();

                if (! $positionType) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.position_type_id" => ['Invalid position type for this organization level.'],
                    ]);
                }

                $assignment = OrganizationPositionAssignment::query()->updateOrCreate(
                    [
                        'organization_unit_id' => (int) $unit->id,
                        'position_type_id' => $positionTypeId,
                        'employee_id' => $employeeId,
                    ],
                    [
                        'organization_level' => $level,
                        'is_primary' => (bool) ($row['is_primary'] ?? false),
                        'approval_priority' => (int) ($row['approval_priority'] ?? $positionType->approval_priority),
                        'effective_from' => $row['effective_from'] ?? null,
                        'effective_to' => $row['effective_to'] ?? null,
                        'is_active' => (bool) ($row['is_active'] ?? true),
                        'remarks' => $this->nullableTrim($row['remarks'] ?? null),
                    ],
                );

                $seenIds[] = (int) $assignment->id;

                $this->assignmentScopeService->syncAssignmentScopes($assignment, $row, $legacyType, $legacyId);
            }

            OrganizationPositionAssignment::query()
                ->where('organization_unit_id', (int) $unit->id)
                ->when($seenIds !== [], fn ($query) => $query->whereNotIn('id', $seenIds))
                ->update([
                    'is_active' => false,
                    'effective_to' => now()->toDateString(),
                ]);

            $this->syncUnitLeadersFromAssignments($unit);
            $this->mirrorService->syncLegacyPrimaryHead($legacyType, $legacyId, $unit);
        });

        $this->approvalChainCacheService->forgetForLegacyUnit($legacyType, $legacyId);
        $this->approvalChainCacheService->forgetWorkflowSettings();
        if (in_array($legacyType, ['company', 'area', 'branch', 'division', 'department', 'section_unit'], true)) {
            $this->approvalWorkflowService->resyncPendingRequestChains(['leave', 'overtime']);
        }

        return $this->leadershipPayload($legacyType, $legacyId);
    }

    public function syncUnitLeadersFromAssignments(OrganizationUnit $unit): void
    {
        $assignments = OrganizationPositionAssignment::query()
            ->with('positionType')
            ->where('organization_unit_id', (int) $unit->id)
            ->active()
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $seen = [];
        foreach ($assignments as $assignment) {
            $role = $assignment->positionType?->position_name ?: 'Leader';
            $key = ((int) $assignment->employee_id).'|'.$role;
            $seen[] = $key;

            OrganizationUnitLeader::query()->updateOrCreate(
                [
                    'organization_unit_id' => (int) $unit->id,
                    'employee_id' => (int) $assignment->employee_id,
                    'leader_role' => $role,
                ],
                [
                    'is_primary' => (bool) $assignment->is_primary,
                    'approval_priority' => (int) $assignment->approval_priority,
                    'is_active' => true,
                ],
            );
        }

        OrganizationUnitLeader::query()
            ->where('organization_unit_id', (int) $unit->id)
            ->get()
            ->each(function (OrganizationUnitLeader $leader) use ($seen): void {
                $key = ((int) $leader->employee_id).'|'.((string) $leader->leader_role);
                if (! in_array($key, $seen, true)) {
                    $leader->forceFill(['is_active' => false])->save();
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function assignmentPayload(OrganizationPositionAssignment $assignment, ?string $legacyType = null): array
    {
        $payload = [
            'id' => (int) $assignment->id,
            'organization_level' => $assignment->organization_level,
            'organization_unit_id' => (int) $assignment->organization_unit_id,
            'position_type_id' => (int) $assignment->position_type_id,
            'position_name' => $assignment->positionType?->position_name,
            'can_approve' => (bool) ($assignment->positionType?->can_approve ?? true),
            'employee_id' => (int) $assignment->employee_id,
            'employee_name' => $assignment->employee?->display_name,
            'is_primary' => (bool) $assignment->is_primary,
            'approval_priority' => (int) $assignment->approval_priority,
            'effective_from' => $assignment->effective_from?->toDateString(),
            'effective_to' => $assignment->effective_to?->toDateString(),
            'is_active' => (bool) $assignment->is_active,
            'remarks' => $assignment->remarks,
        ];

        if (in_array($legacyType, ['company', 'area', 'branch', 'division'], true)
            && (bool) ($assignment->positionType?->can_approve ?? true)) {
            $payload = array_merge($payload, $this->assignmentScopeService->scopePayloadForAssignment($assignment));
        }

        return $payload;
    }

    public function upsertLegacyHeadAssignment(
        string $legacyType,
        int $legacyId,
        ?int $employeeId,
        ?int $previousEmployeeId = null,
    ): void {
        $unit = $this->resolveUnit($legacyType, $legacyId);
        $level = $this->organizationLevelForLegacyType($legacyType);
        $positionName = $this->defaultHeadPositionName($legacyType);

        $positionType = OrganizationPositionType::query()->firstOrCreate(
            [
                'organization_level' => $level,
                'position_name' => $positionName,
            ],
            [
                'approval_priority' => 1,
                'can_approve' => true,
                'is_final_approver' => false,
                'is_active' => true,
            ],
        );

        if ($previousEmployeeId !== null && $previousEmployeeId !== $employeeId) {
            OrganizationPositionAssignment::query()
                ->where('organization_unit_id', (int) $unit->id)
                ->where('position_type_id', (int) $positionType->id)
                ->where('employee_id', $previousEmployeeId)
                ->update([
                    'is_active' => false,
                    'effective_to' => now()->toDateString(),
                ]);
        }

        if ($employeeId !== null) {
            $this->assertActiveEmployee($employeeId);
            $assignment = OrganizationPositionAssignment::query()->updateOrCreate(
                [
                    'organization_unit_id' => (int) $unit->id,
                    'position_type_id' => (int) $positionType->id,
                    'employee_id' => $employeeId,
                ],
                [
                    'organization_level' => $level,
                    'is_primary' => true,
                    'approval_priority' => 1,
                    'effective_from' => null,
                    'effective_to' => null,
                    'is_active' => true,
                ],
            );
        }

        $this->syncUnitLeadersFromAssignments($unit);
    }

    private function defaultHeadPositionName(string $legacyType): string
    {
        return match ($legacyType) {
            'company' => 'Company Head',
            'area' => 'Area Head / Area Manager',
            'branch' => 'Branch Head',
            'division' => 'Division Head',
            'department' => 'Department Head',
            'section_unit' => 'Section Leader',
            default => 'Head',
        };
    }

    private function normalizeAreaHeadAssignments(OrganizationUnit $unit): void
    {
        $canonical = OrganizationPositionType::query()
            ->where('organization_level', 'area')
            ->where('position_name', 'Area Head / Area Manager')
            ->first();

        if (! $canonical) {
            return;
        }

        $legacyTypeIds = OrganizationPositionType::query()
            ->where('organization_level', 'area')
            ->whereIn('position_name', ['Area Head', 'Head'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($legacyTypeIds === []) {
            return;
        }

        OrganizationPositionAssignment::query()
            ->where('organization_unit_id', (int) $unit->id)
            ->whereIn('position_type_id', $legacyTypeIds)
            ->orderBy('id')
            ->get()
            ->each(function (OrganizationPositionAssignment $assignment) use ($canonical, $unit): void {
                $alreadyCanonical = OrganizationPositionAssignment::query()
                    ->where('organization_unit_id', (int) $unit->id)
                    ->where('position_type_id', (int) $canonical->id)
                    ->where('employee_id', (int) $assignment->employee_id)
                    ->where('id', '!=', (int) $assignment->id)
                    ->exists();

                if ($alreadyCanonical) {
                    $assignment->forceFill([
                        'is_active' => false,
                        'effective_to' => $assignment->effective_to ?? now()->toDateString(),
                    ])->save();

                    return;
                }

                $assignment->forceFill([
                    'position_type_id' => (int) $canonical->id,
                    'organization_level' => 'area',
                ])->save();
            });
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function assertActiveEmployee(int $employeeId): void
    {
        if (! User::query()->activeRoster()->whereKey($employeeId)->exists()) {
            throw ValidationException::withMessages(['employee_id' => ['Selected employee must be active.']]);
        }
    }
}
