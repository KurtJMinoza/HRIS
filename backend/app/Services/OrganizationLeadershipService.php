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
        'branch' => 'branch',
        'division' => 'division',
        'department' => 'department',
        'section_unit' => 'section_unit',
    ];

    public function __construct(
        private readonly LegacyOrganizationMirrorService $mirrorService,
        private readonly OrganizationLeadershipAssignmentScopeService $assignmentScopeService,
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
        return OrganizationPositionType::query()
            ->where('organization_level', $organizationLevel)
            ->orderBy('approval_priority')
            ->orderBy('position_name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function leadershipPayload(string $legacyType, int $legacyId): array
    {
        $unit = $this->resolveUnit($legacyType, $legacyId);
        $level = $this->organizationLevelForLegacyType($legacyType);

        $assignments = OrganizationPositionAssignment::query()
            ->with(['employee', 'positionType', 'activeDepartmentScopes'])
            ->where('organization_unit_id', (int) $unit->id)
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
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

        DB::transaction(function () use ($unit, $level, $assignments, $legacyType, $legacyId): void {
            $seenKeys = [];
            $seenIds = [];
            foreach ($assignments as $index => $row) {
                $positionTypeId = (int) ($row['position_type_id'] ?? 0);
                $employeeId = (int) ($row['employee_id'] ?? 0);
                if ($positionTypeId <= 0 || $employeeId <= 0) {
                    continue;
                }

                $dedupeKey = $positionTypeId.'|'.$employeeId;
                if (isset($seenKeys[$dedupeKey])) {
                    throw ValidationException::withMessages([
                        "assignments.{$index}.employee_id" => ['This employee is already assigned to the same leadership role for this unit.'],
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

        if ($legacyType === 'division' && (bool) ($assignment->positionType?->can_approve ?? true)) {
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
            'branch' => 'Branch Head',
            'division' => 'Division Head',
            'department' => 'Department Head',
            'section_unit' => 'Section Leader',
            default => 'Head',
        };
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
