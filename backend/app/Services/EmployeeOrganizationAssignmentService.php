<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\OrganizationUnit;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EmployeeOrganizationAssignmentService
{
    public const MODE_SHARED = 'shared';

    public const MODE_TRANSFER_PRIMARY = 'transfer_primary';

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_SHARED = 'shared';

    public const TYPE_TEMPORARY = 'temporary';

    public const TYPE_ACTING = 'acting';

    public function __construct(
        private readonly LegacyOrganizationMirrorService $legacyMirror,
    ) {}

    /**
     * @param  list<int>  $employeeIds
     * @return array{
     *   assignments: list<EmployeeOrganizationAssignment>,
     *   added_count: int,
     *   skipped_existing_count: int,
     *   skipped_existing_names: list<string>,
     *   final_assigned_count: int,
     * }
     */
    public function assignToLegacyUnit(
        string $legacyType,
        int $legacyId,
        array $employeeIds,
        string $assignmentMode = self::MODE_TRANSFER_PRIMARY,
        ?string $remarks = null,
    ): array {
        if (! $this->tablesReady()) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Organization assignment tables are not available. Run migrations first.'],
            ]);
        }

        if (! in_array($assignmentMode, [self::MODE_SHARED, self::MODE_TRANSFER_PRIMARY], true)) {
            throw ValidationException::withMessages([
                'assignment_mode' => ['Assignment mode must be shared or transfer_primary.'],
            ]);
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Select at least one employee.'],
            ]);
        }

        $context = $this->resolveLegacyContext($legacyType, $legacyId);
        $users = User::query()
            ->whereIn('id', $employeeIds)
            ->visibleEmployees()
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff($employeeIds, $users->keys()->map(fn ($id) => (int) $id)->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'employee_ids' => ['One or more employees could not be found or are not roster-eligible.'],
            ]);
        }

        $inactive = $users->filter(fn (User $user) => ! $user->is_active)->map(fn (User $user) => $user->display_name)->values()->all();
        if ($inactive !== []) {
            throw ValidationException::withMessages([
                'employee_ids' => [
                    'Inactive employees cannot be assigned: '.implode(', ', $inactive).'.',
                ],
            ]);
        }

        $targetAssignmentType = $assignmentMode === self::MODE_SHARED
            ? self::TYPE_SHARED
            : self::TYPE_PRIMARY;

        $existingEmployeeIds = EmployeeOrganizationAssignment::query()
            ->active()
            ->where('organization_unit_id', (int) $context['organization_unit_id'])
            ->where('assignment_type', $targetAssignmentType)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $newEmployeeIds = array_values(array_diff($employeeIds, $existingEmployeeIds));
        $skippedExistingIds = array_values(array_intersect($employeeIds, $existingEmployeeIds));
        $skippedExistingNames = array_values(array_filter(array_map(
            fn (int $employeeId): string => (string) ($users->get($employeeId)?->display_name ?? $employeeId),
            $skippedExistingIds,
        )));

        $created = [];
        DB::transaction(function () use ($newEmployeeIds, $users, $context, $assignmentMode, $remarks, &$created, $legacyType): void {
            foreach ($newEmployeeIds as $employeeId) {
                /** @var User $user */
                $user = $users->get($employeeId);

                if ($assignmentMode === self::MODE_TRANSFER_PRIMARY) {
                    $this->deactivatePrimaryAssignments((int) $user->id, (int) $context['organization_unit_id']);
                    $this->applyPrimaryUserForeignKeys($user, $context, $legacyType);
                    $user->refresh();
                    $this->legacyMirror->syncUserAssignment($user);
                    $assignment = EmployeeOrganizationAssignment::query()
                        ->where('employee_id', (int) $user->id)
                        ->where('organization_unit_id', (int) $context['organization_unit_id'])
                        ->first();
                    if ($assignment) {
                        $assignment->fill([
                            'assignment_type' => self::TYPE_PRIMARY,
                            'company_id' => $context['company_id'],
                            'branch_id' => $context['branch_id'],
                            'division_id' => $context['division_id'],
                            'department_id' => $context['department_id'],
                            'section_unit_id' => $context['section_unit_id'],
                            'is_primary' => true,
                            'remarks' => $remarks,
                            'is_active' => true,
                        ])->save();
                        $created[] = $assignment->fresh();
                    }
                } else {
                    $assignment = EmployeeOrganizationAssignment::query()->create([
                        'employee_id' => (int) $user->id,
                        'organization_unit_id' => (int) $context['organization_unit_id'],
                        'assignment_type' => self::TYPE_SHARED,
                        'company_id' => $context['company_id'],
                        'branch_id' => $context['branch_id'],
                        'division_id' => $context['division_id'],
                        'department_id' => $context['department_id'],
                        'section_unit_id' => $context['section_unit_id'],
                        'is_primary' => false,
                        'immediate_leader_id' => $user->supervisor_id ? (int) $user->supervisor_id : null,
                        'effective_from' => now()->toDateString(),
                        'effective_to' => null,
                        'is_active' => true,
                        'remarks' => $remarks,
                    ]);
                    $created[] = $assignment;
                }
            }
        });

        $finalAssignedCount = EmployeeOrganizationAssignment::query()
            ->active()
            ->where('organization_unit_id', (int) $context['organization_unit_id'])
            ->pluck('employee_id')
            ->unique()
            ->count();

        return [
            'assignments' => $created,
            'added_count' => count($created),
            'skipped_existing_count' => count($skippedExistingIds),
            'skipped_existing_names' => $skippedExistingNames,
            'final_assigned_count' => $finalAssignedCount,
        ];
    }

    /**
     * @param  array{
     *   added_count: int,
     *   skipped_existing_count: int,
     *   skipped_existing_names: list<string>,
     * }  $result
     */
    public function assignResultMessage(array $result): string
    {
        $added = (int) ($result['added_count'] ?? 0);
        $skipped = (int) ($result['skipped_existing_count'] ?? 0);
        $skippedNames = $result['skipped_existing_names'] ?? [];

        if ($added > 0 && $skipped > 0) {
            return sprintf(
                '%d employee(s) added. %d already assigned (%s).',
                $added,
                $skipped,
                implode(', ', $skippedNames),
            );
        }

        if ($added > 0) {
            return $added === 1
                ? '1 employee assigned successfully.'
                : sprintf('%d employees assigned successfully.', $added);
        }

        if ($skipped > 0) {
            return 'Selected employee(s) are already assigned to this organization unit.';
        }

        return 'Employees assigned successfully.';
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public function unassignFromLegacyUnit(string $legacyType, int $legacyId, array $employeeIds): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return;
        }

        $context = $this->resolveLegacyContext($legacyType, $legacyId);

        DB::transaction(function () use ($employeeIds, $context, $legacyType, $legacyId): void {
            EmployeeOrganizationAssignment::query()
                ->active()
                ->whereIn('employee_id', $employeeIds)
                ->where('organization_unit_id', (int) $context['organization_unit_id'])
                ->update([
                    'is_active' => false,
                    'effective_to' => now()->toDateString(),
                ]);

            foreach ($employeeIds as $employeeId) {
                $user = User::query()->find($employeeId);
                if (! $user) {
                    continue;
                }

                $this->clearLegacyForeignKeysIfMatched($user, $legacyType, $legacyId, $context);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignmentsForEmployee(User $employee): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return EmployeeOrganizationAssignment::query()
            ->with(['organizationUnit.type'])
            ->where('employee_id', (int) $employee->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeOrganizationAssignment $row) => $this->assignmentPayload($row))
            ->values()
            ->all();
    }

    /**
     * Contexts available when filing Leave/Overtime. This deliberately excludes
     * partial mirror rows (for example Company > Branch only) when a complete
     * section assignment exists, so request routing uses the actionable unit.
     *
     * @return array{assignments: list<array<string, mixed>>, default_assignment: ?array<string, mixed>}
     */
    public function requestContextOptionsForEmployee(User $employee, mixed $requestDate = null): array
    {
        if (! $this->tablesReady()) {
            return ['assignments' => [], 'default_assignment' => null];
        }

        $date = $requestDate ? \Carbon\Carbon::parse($requestDate)->toDateString() : now()->toDateString();

        $rows = EmployeeOrganizationAssignment::query()
            ->with(['organizationUnit.type'])
            ->where('employee_id', (int) $employee->id)
            ->where('is_active', true)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();

        if ($rows->isEmpty()) {
            return ['assignments' => [], 'default_assignment' => null];
        }

        $validSectionRows = $rows
            ->filter(fn (EmployeeOrganizationAssignment $row): bool => $this->isCompleteSectionContext($row))
            ->values();

        $validDepartmentRows = $rows
            ->filter(fn (EmployeeOrganizationAssignment $row): bool => $this->isCompleteDepartmentContext($row))
            ->values();

        $actionableRows = $validSectionRows
            ->merge($validDepartmentRows)
            ->unique(fn (EmployeeOrganizationAssignment $row): int => (int) $row->id)
            ->values();

        $supplementalRows = $actionableRows
            ->filter(fn (EmployeeOrganizationAssignment $row): bool => in_array((string) $row->assignment_type, [
                self::TYPE_SHARED,
                self::TYPE_TEMPORARY,
                self::TYPE_ACTING,
            ], true))
            ->values();

        $primaryRows = $actionableRows
            ->filter(fn (EmployeeOrganizationAssignment $row): bool => (bool) $row->is_primary || $row->assignment_type === self::TYPE_PRIMARY)
            ->values();

        $candidateRows = $supplementalRows->isNotEmpty()
            ? $supplementalRows
            : $primaryRows;

        if ($candidateRows->isEmpty()) {
            return ['assignments' => [], 'default_assignment' => null];
        }

        $deduped = [];
        foreach ($candidateRows as $row) {
            $key = implode('|', [
                (int) ($row->company_id ?? 0),
                (int) ($row->branch_id ?? 0),
                (int) ($row->division_id ?? 0),
                (int) ($row->department_id ?? 0),
                (int) ($row->section_unit_id ?? 0),
                (string) ($row->assignment_type ?: ($row->is_primary ? self::TYPE_PRIMARY : self::TYPE_SHARED)),
            ]);
            if (! isset($deduped[$key])) {
                $deduped[$key] = $row;
            }
        }

        $selected = collect(array_values($deduped));
        $default = $this->selectDefaultRequestAssignment($selected);
        $payloads = $selected
            ->map(fn (EmployeeOrganizationAssignment $row): array => $this->assignmentPayload($row))
            ->values()
            ->all();

        \Illuminate\Support\Facades\Log::info('request_context: resolved employee organization context options', [
            'employee_id' => (int) $employee->id,
            'request_date' => $date,
            'valid_section_assignment_ids' => $validSectionRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'valid_department_assignment_ids' => $validDepartmentRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'supplemental_assignment_ids' => $supplementalRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'primary_assignment_ids' => $primaryRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'selected_assignment_ids' => $selected->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'default_assignment_id' => $default?->id ? (int) $default->id : null,
            'request_selected_default_context' => $default ? $this->requestContextPayload($default) : null,
        ]);

        return [
            'assignments' => $payloads,
            'default_assignment' => $default ? $this->assignmentPayload($default) : ($payloads[0] ?? null),
        ];
    }

    public function resolveRequestAssignment(User $employee, ?int $assignmentId = null, mixed $requestDate = null): ?EmployeeOrganizationAssignment
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $date = $requestDate ? \Carbon\Carbon::parse($requestDate)->toDateString() : now()->toDateString();

        $query = EmployeeOrganizationAssignment::query()
            ->with(['organizationUnit.type', 'organizationUnit.parent'])
            ->where('employee_id', (int) $employee->id)
            ->where('is_active', true)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });

        if ($assignmentId !== null && $assignmentId > 0) {
            $assignment = (clone $query)->whereKey($assignmentId)->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'assignment_id' => ['Selected organization assignment is inactive, expired, or does not belong to this employee.'],
                ]);
            }

            return $assignment;
        }

        $options = $this->requestContextOptionsForEmployee($employee, $date);
        $defaultId = isset($options['default_assignment']['id']) ? (int) $options['default_assignment']['id'] : 0;
        if ($defaultId > 0) {
            return (clone $query)->whereKey($defaultId)->first();
        }

        return (clone $query)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first()
            ?: (clone $query)->orderByDesc('is_primary')->orderByDesc('id')->first();
    }

    private function isCompleteSectionContext(EmployeeOrganizationAssignment $row): bool
    {
        if (! in_array((string) $row->assignment_type, [
            self::TYPE_PRIMARY,
            self::TYPE_SHARED,
            self::TYPE_TEMPORARY,
            self::TYPE_ACTING,
        ], true)) {
            return false;
        }

        if ($row->company_id === null
            || $row->branch_id === null
            || $row->department_id === null
            || $row->section_unit_id === null) {
            return false;
        }

        return SectionUnit::query()
            ->whereKey((int) $row->section_unit_id)
            ->where('company_id', (int) $row->company_id)
            ->where('branch_id', (int) $row->branch_id)
            ->where('department_id', (int) $row->department_id)
            ->where(function ($query) use ($row): void {
                if ($row->division_id === null) {
                    $query->whereNull('division_id');
                } else {
                    $query->where('division_id', (int) $row->division_id);
                }
            })
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->exists();
    }

    private function isCompleteDepartmentContext(EmployeeOrganizationAssignment $row): bool
    {
        if (! in_array((string) $row->assignment_type, [
            self::TYPE_PRIMARY,
            self::TYPE_SHARED,
            self::TYPE_TEMPORARY,
            self::TYPE_ACTING,
        ], true)) {
            return false;
        }

        if ($row->section_unit_id !== null || $row->company_id === null || $row->department_id === null) {
            return false;
        }

        return Department::query()
            ->whereKey((int) $row->department_id)
            ->where(function ($query) use ($row): void {
                $query->where('company_id', (int) $row->company_id)
                    ->orWhereHas('branch', fn ($branch) => $branch->where('company_id', (int) $row->company_id));
            })
            ->where(function ($query) use ($row): void {
                if ($row->branch_id === null) {
                    $query->whereNull('branch_id');
                } else {
                    $query->where('branch_id', (int) $row->branch_id);
                }
            })
            ->where(function ($query) use ($row): void {
                if ($row->division_id === null) {
                    $query->whereNull('division_id');
                } else {
                    $query->where('division_id', (int) $row->division_id);
                }
            })
            ->exists();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EmployeeOrganizationAssignment>  $rows
     */
    private function selectDefaultRequestAssignment($rows): ?EmployeeOrganizationAssignment
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $shared = $rows->first(fn (EmployeeOrganizationAssignment $row): bool => in_array((string) $row->assignment_type, [
            self::TYPE_SHARED,
            self::TYPE_TEMPORARY,
            self::TYPE_ACTING,
        ], true));
        if ($shared) {
            return $shared;
        }

        return $rows->first(fn (EmployeeOrganizationAssignment $row): bool => (bool) $row->is_primary)
            ?: $rows->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function requestContextPayload(?EmployeeOrganizationAssignment $assignment): array
    {
        if (! $assignment) {
            return [
                'assignment_id' => null,
                'assignment_type' => null,
                'company_id' => null,
                'branch_id' => null,
                'division_id' => null,
                'department_id' => null,
                'section_unit_id' => null,
            ];
        }

        return [
            'assignment_id' => (int) $assignment->id,
            'assignment_type' => $assignment->assignment_type,
            'company_id' => $assignment->company_id ? (int) $assignment->company_id : null,
            'branch_id' => $assignment->branch_id ? (int) $assignment->branch_id : null,
            'division_id' => $assignment->division_id ? (int) $assignment->division_id : null,
            'department_id' => $assignment->department_id ? (int) $assignment->department_id : null,
            'section_unit_id' => $assignment->section_unit_id ? (int) $assignment->section_unit_id : null,
        ];
    }

    /**
     * @return array{
     *   organization_unit_id: int,
     *   company_id: ?int,
     *   branch_id: ?int,
     *   division_id: ?int,
     *   department_id: ?int,
     *   section_unit_id: ?int,
     *   company_name: ?string,
     *   branch_name: ?string,
     *   division_name: ?string,
     *   department_name: ?string,
     *   section_unit_name: ?string,
     *   unit_name: ?string,
     *   unit_type: ?string,
     * }
     */
    public function resolveLegacyContext(string $legacyType, int $legacyId): array
    {
        $this->legacyMirror->syncLegacyRecord($legacyType, $legacyId);

        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                'organization_unit' => ['Could not resolve organization unit for this record.'],
            ]);
        }

        $context = [
            'organization_unit_id' => (int) $unit->id,
            'company_id' => null,
            'branch_id' => null,
            'division_id' => null,
            'department_id' => null,
            'section_unit_id' => null,
            'company_name' => null,
            'branch_name' => null,
            'division_name' => null,
            'department_name' => null,
            'section_unit_name' => null,
            'unit_name' => $unit->name,
            'unit_type' => $legacyType,
        ];

        match ($legacyType) {
            'company' => $this->fillCompanyContext($context, Company::query()->findOrFail($legacyId)),
            'branch' => $this->fillBranchContext($context, Branch::query()->with('company:id,name')->findOrFail($legacyId)),
            'division' => $this->fillDivisionContext($context, Division::query()->with(['company:id,name', 'branch:id,name,company_id'])->findOrFail($legacyId)),
            'department' => $this->fillDepartmentContext($context, Department::query()->with(['company:id,name', 'branch.company:id,name', 'division:id,name'])->findOrFail($legacyId)),
            'section_unit' => $this->fillSectionContext($context, SectionUnit::query()->with(['company:id,name', 'branch:id,name', 'division:id,name', 'department:id,name'])->findOrFail($legacyId)),
            default => throw ValidationException::withMessages([
                'organization_unit' => ['Unsupported organization unit type.'],
            ]),
        };

        return $context;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, list<array<string, mixed>>>
     */
    public function buildActiveAssignmentMapForUsers($users): array
    {
        if (! $this->tablesReady() || $users->isEmpty()) {
            return [];
        }

        $employeeIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = EmployeeOrganizationAssignment::query()
            ->active()
            ->whereIn('employee_id', $employeeIds)
            ->get([
                'id',
                'employee_id',
                'organization_unit_id',
                'assignment_type',
                'is_primary',
                'company_id',
                'branch_id',
                'division_id',
                'department_id',
                'section_unit_id',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row->employee_id;
            $map[$employeeId] ??= [];
            $map[$employeeId][] = [
                'id' => (int) $row->id,
                'organization_unit_id' => (int) $row->organization_unit_id,
                'assignment_type' => $row->assignment_type,
                'is_primary' => (bool) $row->is_primary,
                'company_id' => $row->company_id ? (int) $row->company_id : null,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'division_id' => $row->division_id ? (int) $row->division_id : null,
                'department_id' => $row->department_id ? (int) $row->department_id : null,
                'section_unit_id' => $row->section_unit_id ? (int) $row->section_unit_id : null,
            ];
        }

        return $map;
    }

    private function deactivatePrimaryAssignments(int $employeeId, int $exceptUnitId): void
    {
        EmployeeOrganizationAssignment::query()
            ->active()
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->where('organization_unit_id', '<>', $exceptUnitId)
            ->update([
                'is_primary' => false,
                'is_active' => false,
                'effective_to' => now()->toDateString(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function applyPrimaryUserForeignKeys(User $user, array $context, string $legacyType): void
    {
        $updates = [
            'company_id' => $context['company_id'],
            'branch_id' => $context['branch_id'],
            'division_id' => $context['division_id'],
            'department_id' => $context['department_id'],
            'section_unit_id' => $context['section_unit_id'],
        ];

        if ($legacyType === 'department' && $context['department_name']) {
            $updates['department'] = $context['department_name'];
        }

        if ($legacyType !== 'department' && $legacyType !== 'section_unit') {
            $updates['department_id'] = null;
            $updates['department'] = null;
            $updates['section_unit_id'] = null;
        }

        if ($legacyType === 'division') {
            $updates['department_id'] = null;
            $updates['department'] = null;
            $updates['section_unit_id'] = null;
        }

        if ($legacyType === 'company') {
            $updates['branch_id'] = null;
            $updates['division_id'] = null;
            $updates['department_id'] = null;
            $updates['department'] = null;
            $updates['section_unit_id'] = null;
        }

        if ($legacyType === 'branch') {
            $updates['division_id'] = null;
            $updates['department_id'] = null;
            $updates['department'] = null;
            $updates['section_unit_id'] = null;
        }

        User::query()->whereKey((int) $user->id)->update($updates);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function clearLegacyForeignKeysIfMatched(User $user, string $legacyType, int $legacyId, array $context): void
    {
        $updates = [];

        match ($legacyType) {
            'department' => $user->department_id === $legacyId ? $updates = ['department_id' => null, 'department' => null, 'section_unit_id' => null] : null,
            'division' => $user->division_id === $legacyId ? $updates = ['division_id' => null, 'department_id' => null, 'department' => null, 'section_unit_id' => null] : null,
            'section_unit' => $user->section_unit_id === $legacyId ? $updates = ['section_unit_id' => null] : null,
            'branch' => $user->branch_id === $legacyId ? $updates = ['branch_id' => null, 'division_id' => null, 'department_id' => null, 'department' => null, 'section_unit_id' => null] : null,
            'company' => $user->company_id === $legacyId ? $updates = ['company_id' => null, 'branch_id' => null, 'division_id' => null, 'department_id' => null, 'department' => null, 'section_unit_id' => null] : null,
            default => null,
        };

        if ($updates !== []) {
            User::query()->whereKey((int) $user->id)->update($updates);
            $this->legacyMirror->syncUserAssignment($user->fresh());
        }

        $remainingPrimary = EmployeeOrganizationAssignment::query()
            ->active()
            ->where('employee_id', (int) $user->id)
            ->where('is_primary', true)
            ->first();

        if (! $remainingPrimary && $user->isRosterEligible()) {
            $this->legacyMirror->syncUserAssignment($user->fresh());
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillCompanyContext(array &$context, Company $company): void
    {
        $context['company_id'] = (int) $company->id;
        $context['company_name'] = $company->name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillBranchContext(array &$context, Branch $branch): void
    {
        $context['company_id'] = $branch->company_id ? (int) $branch->company_id : null;
        $context['branch_id'] = (int) $branch->id;
        $context['company_name'] = $branch->company?->name;
        $context['branch_name'] = $branch->name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillDivisionContext(array &$context, Division $division): void
    {
        $context['company_id'] = $division->company_id ? (int) $division->company_id : ($division->branch?->company_id ? (int) $division->branch->company_id : null);
        $context['branch_id'] = $division->branch_id ? (int) $division->branch_id : null;
        $context['division_id'] = (int) $division->id;
        $context['company_name'] = $division->company?->name ?? $division->branch?->company?->name;
        $context['branch_name'] = $division->branch?->name;
        $context['division_name'] = $division->name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillDepartmentContext(array &$context, Department $department): void
    {
        $context['company_id'] = $department->company_id ? (int) $department->company_id : ($department->branch?->company_id ? (int) $department->branch->company_id : null);
        $context['branch_id'] = $department->branch_id ? (int) $department->branch_id : null;
        $context['division_id'] = $department->division_id ? (int) $department->division_id : null;
        $context['department_id'] = (int) $department->id;
        $context['company_name'] = $department->company?->name ?? $department->branch?->company?->name;
        $context['branch_name'] = $department->branch?->name;
        $context['division_name'] = $department->division?->name;
        $context['department_name'] = $department->name;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fillSectionContext(array &$context, SectionUnit $section): void
    {
        $context['company_id'] = $section->company_id ? (int) $section->company_id : ($section->branch?->company_id ? (int) $section->branch->company_id : null);
        $context['branch_id'] = $section->branch_id ? (int) $section->branch_id : null;
        $context['division_id'] = $section->division_id ? (int) $section->division_id : null;
        $context['department_id'] = $section->department_id ? (int) $section->department_id : null;
        $context['section_unit_id'] = (int) $section->id;
        $context['company_name'] = $section->company?->name ?? $section->branch?->company?->name;
        $context['branch_name'] = $section->branch?->name;
        $context['division_name'] = $section->division?->name;
        $context['department_name'] = $section->department?->name;
        $context['section_unit_name'] = $section->name;
    }

    private function assignmentPayload(EmployeeOrganizationAssignment $row): array
    {
        $unit = $row->organizationUnit;

        return [
            'id' => (int) $row->id,
            'assignment_type' => $row->assignment_type,
            'is_primary' => (bool) $row->is_primary,
            'is_active' => (bool) $row->is_active,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'remarks' => $row->remarks,
            'organization_unit_id' => (int) $row->organization_unit_id,
            'organization_unit_name' => $unit?->name,
            'organization_unit_type' => $unit?->legacy_source_type,
            'company_id' => $row->company_id ? (int) $row->company_id : null,
            'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
            'division_id' => $row->division_id ? (int) $row->division_id : null,
            'department_id' => $row->department_id ? (int) $row->department_id : null,
            'section_unit_id' => $row->section_unit_id ? (int) $row->section_unit_id : null,
            'org_path' => $this->orgPathFromAssignment($row),
        ];
    }

    private function orgPathFromAssignment(EmployeeOrganizationAssignment $row): string
    {
        $parts = [];
        if ($row->company_id) {
            $parts[] = Company::query()->whereKey((int) $row->company_id)->value('name') ?: 'Company';
        }
        if ($row->branch_id) {
            $parts[] = Branch::query()->whereKey((int) $row->branch_id)->value('name') ?: 'Branch';
        }
        if ($row->division_id) {
            $parts[] = Division::query()->whereKey((int) $row->division_id)->value('name') ?: 'Division';
        }
        if ($row->department_id) {
            $parts[] = Department::query()->whereKey((int) $row->department_id)->value('name') ?: 'Department';
        }
        if ($row->section_unit_id) {
            $parts[] = SectionUnit::query()->whereKey((int) $row->section_unit_id)->value('name') ?: 'Section/Unit';
        }

        if ($parts === [] && $row->relationLoaded('organizationUnit') && $row->organizationUnit) {
            return (string) $row->organizationUnit->name;
        }

        return implode(' > ', $parts);
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('employee_organization_assignments')
            && Schema::hasTable('organization_units');
    }
}
