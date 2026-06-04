<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationLeadershipAssignmentScope;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\SectionUnit;
use App\Models\User;
use App\Support\OrganizationLeadershipScopeOptionsCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OrganizationLeadershipAssignmentScopeService
{
    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function resolveScopedDivisionHeadForDepartmentHead(
        int $divisionId,
        int $departmentId,
        ?string $requestType,
        array $skipIds,
        array $context = [],
    ): ?array {
        return $this->resolveScopedDivisionHead($divisionId, $departmentId, $requestType, $skipIds, $context);
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function resolveScopedDivisionHead(
        int $divisionId,
        int $departmentId,
        ?string $requestType,
        array $skipIds,
        array $context = [],
    ): ?array {
        if ($divisionId <= 0 || ! Schema::hasTable('organization_leadership_assignment_scopes')) {
            return null;
        }

        if (! $this->isDepartmentScopedRequestType($requestType)) {
            $this->log($context, 'scoped division head lookup skipped — request type does not use department scope', [
                'request_type' => $requestType,
                'requester_department_id' => $departmentId > 0 ? $departmentId : null,
                'requester_division_id' => $divisionId,
            ]);

            return null;
        }

        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', 'division')
            ->where('legacy_source_id', $divisionId)
            ->first();

        if (! $unit) {
            $this->log($context, 'scoped division head lookup skipped — division organization unit missing', [
                'division_id' => $divisionId,
                'department_id' => $departmentId > 0 ? $departmentId : null,
            ]);

            return null;
        }

        $assignments = OrganizationPositionAssignment::query()
            ->with(['employee', 'positionType', 'activeDepartmentScopes'])
            ->where('organization_unit_id', (int) $unit->id)
            ->active()
            ->whereHas('positionType', fn ($query) => $query->where('can_approve', true))
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $this->log($context, 'scoped division head lookup start', [
            'request_type' => $requestType,
            'requester_department_id' => $departmentId > 0 ? $departmentId : null,
            'requester_division_id' => $divisionId,
            'division_head_assignments_found' => $assignments->count(),
        ]);

        foreach ($assignments as $assignment) {
            $scopeSummary = $this->summarizeScopes($assignment);
            $scopeMatch = $this->assignmentMatchesRequest($assignment, $departmentId, $requestType);
            $skipReason = $this->scopeSkipReason($scopeSummary, $departmentId, $scopeMatch);

            $this->log($context, 'evaluated division head assignment scope', [
                'division_head_candidate_id' => (int) $assignment->employee_id,
                'division_head_candidate_name' => $assignment->employee?->display_name,
                'assignment_id' => (int) $assignment->id,
                'department_scope_mode' => $scopeSummary['mode'],
                'selected_department_scope_ids' => $scopeSummary['department_ids'],
                'scope_request_type' => $scopeSummary['request_type'],
                'scope_match' => $scopeMatch,
                'reason_skipped' => $scopeMatch ? null : $skipReason,
            ]);

            if (! $scopeMatch) {
                $this->log($context, 'skipped division head — department scope mismatch', [
                    'assignment_id' => (int) $assignment->id,
                    'employee_id' => (int) $assignment->employee_id,
                    'skipped_reason' => $skipReason,
                ]);

                continue;
            }

            $employee = $assignment->employee;
            if (! $employee || ! $this->isValidApprover($employee, $skipIds)) {
                $this->log($context, 'skipped division head — invalid approver employee', [
                    'assignment_id' => (int) $assignment->id,
                    'employee_id' => (int) ($employee?->id ?? 0),
                    'skipped_reason' => 'inactive_or_self',
                ]);

                continue;
            }

            $role = trim((string) ($assignment->positionType?->position_name ?? 'Division Head')) ?: 'Division Head';

            $this->log($context, 'selected scoped division head approver', [
                'assignment_id' => (int) $assignment->id,
                'selected_first_approver' => $employee->display_name,
                'approver_id' => (int) $employee->id,
                'approver_name' => $employee->display_name,
                'scope_match' => true,
            ]);

            return [
                'assignment' => $assignment,
                'employee' => $employee,
                'leader_role' => $role,
                'unit' => $unit,
            ];
        }

        $this->log($context, 'no scoped division head matched request', [
            'division_id' => $divisionId,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'request_type' => $requestType,
        ]);

        return null;
    }

    public function assignmentMatchesRequest(
        OrganizationPositionAssignment $assignment,
        int $departmentId,
        ?string $requestType,
    ): bool {
        return $this->assignmentMatchesDepartmentHeadRequest($assignment, $departmentId, $requestType);
    }

    /**
     * @return array<int, array{id: int, name: string, status: string|null}>
     */
    public function departmentsForDivision(int $divisionId): array
    {
        if ($divisionId <= 0) {
            return [];
        }

        $division = Division::query()->find($divisionId);
        if (! $division) {
            return [];
        }

        return Department::query()
            ->where(function ($query) use ($divisionId, $division): void {
                $query->where('division_id', $divisionId);

                if ($division->company_id) {
                    $query->orWhere(function ($inner) use ($divisionId, $division): void {
                        $inner->where('company_id', (int) $division->company_id)
                            ->where(function ($branchScope) use ($division): void {
                                if ($division->branch_id) {
                                    $branchScope->where('branch_id', (int) $division->branch_id);
                                } else {
                                    $branchScope->whereNull('branch_id');
                                }
                            })
                            ->where(function ($divisionScope) use ($divisionId): void {
                                $divisionScope->whereNull('division_id')
                                    ->orWhere('division_id', $divisionId);
                            });
                    });
                }
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->unique('id')
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
                'status' => 'active',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<array{id: int, name: string, status: string|null}>>
     */
    public function approvalScopeOptions(string $legacyType, int $legacyId): array
    {
        if ($legacyId <= 0) {
            return [];
        }

        return OrganizationLeadershipScopeOptionsCache::remember(
            $legacyType,
            $legacyId,
            fn (): array => match ($legacyType) {
                'company' => $this->companyScopeOptions($legacyId),
                'area' => $this->areaScopeOptions($legacyId),
                'branch' => $this->branchScopeOptions($legacyId),
                'division' => [
                    'department' => $this->departmentsForDivision($legacyId),
                ],
                default => [],
            },
        );
    }

    /**
     * @return array<string, list<array{id: int, name: string, status: string|null}>>
     */
    private function companyScopeOptions(int $companyId): array
    {
        $branchIds = Branch::query()->where('company_id', $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $divisionIds = Division::query()
            ->where(function ($query) use ($companyId, $branchIds): void {
                $query->where('company_id', $companyId);
                if ($branchIds !== []) {
                    $query->orWhereIn('branch_id', $branchIds);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'company' => $this->optionRows(Company::query()->whereKey($companyId)->get(['id', 'name'])),
            'area' => $this->optionRows(Area::query()->where('company_id', $companyId)->orderBy('area_name')->get(['id', 'area_name as name', 'status'])),
            'branch' => $this->optionRows(Branch::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])),
            'division' => $this->optionRows(Division::query()->whereIn('id', $divisionIds)->orderBy('name')->get(['id', 'name'])),
            'department' => $this->optionRows(Department::query()->where(function ($query) use ($companyId, $branchIds, $divisionIds): void {
                $query->where('company_id', $companyId);
                if ($branchIds !== []) {
                    $query->orWhereIn('branch_id', $branchIds);
                }
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
            'section_unit' => $this->optionRows(SectionUnit::query()->where(function ($query) use ($companyId, $branchIds, $divisionIds): void {
                $query->where('company_id', $companyId);
                if ($branchIds !== []) {
                    $query->orWhereIn('branch_id', $branchIds);
                }
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
        ];
    }

    /**
     * @return array<string, list<array{id: int, name: string, status: string|null}>>
     */
    private function areaScopeOptions(int $areaId): array
    {
        $area = Area::query()->find($areaId);
        if (! $area) {
            return [];
        }

        $branchIds = Branch::query()->where('area_id', $areaId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $divisionIds = Division::query()
            ->whereIn('branch_id', $branchIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'area' => $this->optionRows(collect([(object) ['id' => $area->id, 'name' => $area->area_name, 'status' => $area->status]])),
            'branch' => $this->optionRows(Branch::query()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name'])),
            'division' => $this->optionRows(Division::query()->whereIn('id', $divisionIds)->orderBy('name')->get(['id', 'name'])),
            'department' => $this->optionRows(Department::query()->where(function ($query) use ($branchIds, $divisionIds): void {
                $query->whereIn('branch_id', $branchIds);
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
            'section_unit' => $this->optionRows(SectionUnit::query()->where(function ($query) use ($branchIds, $divisionIds): void {
                $query->whereIn('branch_id', $branchIds);
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
        ];
    }

    /**
     * @return array<string, list<array{id: int, name: string, status: string|null}>>
     */
    private function branchScopeOptions(int $branchId): array
    {
        $divisionIds = Division::query()
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $departmentDivisionIds = Department::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('division_id')
            ->pluck('division_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $divisionIds = collect([...$divisionIds, ...$departmentDivisionIds])
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return [
            'branch' => $this->optionRows(Branch::query()->whereKey($branchId)->get(['id', 'name'])),
            'division' => $this->optionRows(Division::query()->whereIn('id', $divisionIds)->orderBy('name')->get(['id', 'name'])),
            'department' => $this->optionRows(Department::query()->where(function ($query) use ($branchId, $divisionIds): void {
                $query->where('branch_id', $branchId);
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
            'section_unit' => $this->optionRows(SectionUnit::query()->where(function ($query) use ($branchId, $divisionIds): void {
                $query->where('branch_id', $branchId);
                if ($divisionIds !== []) {
                    $query->orWhereIn('division_id', $divisionIds);
                }
            })->orderBy('name')->get(['id', 'name'])),
        ];
    }

    /**
     * @param  iterable<int, object>  $rows
     * @return list<array{id: int, name: string, status: string|null}>
     */
    private function optionRows(iterable $rows): array
    {
        return collect($rows)
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'status' => property_exists($row, 'status') ? $row->status : 'active',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function syncAssignmentScopes(
        OrganizationPositionAssignment $assignment,
        array $row,
        string $legacyType,
        int $legacyId,
    ): void {
        if (! in_array($legacyType, ['company', 'area', 'branch', 'division'], true)
            || ! Schema::hasTable('organization_leadership_assignment_scopes')) {
            return;
        }

        if (! (bool) ($assignment->positionType?->can_approve ?? true)) {
            $assignment->departmentScopes()->delete();

            return;
        }

        $rawScopeType = trim((string) ($row['approval_scope_type'] ?? ''));
        $mode = $rawScopeType === 'none'
            ? 'none'
            : $this->normalizeScopeMode($row['approval_scope_mode'] ?? $row['department_scope_mode'] ?? null);
        $scopeType = $this->normalizeScopeType($rawScopeType !== '' ? $rawScopeType : ($legacyType === 'division' ? 'department' : $legacyType), $legacyType);
        $requestType = $this->normalizeRequestType($row['scope_request_type'] ?? null);
        $scopeIds = collect($row['approval_scope_ids'] ?? $row['department_scope_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($mode === 'selected') {
            if ($scopeIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'assignments' => ['Select at least one item when using selected approval scope.'],
                ]);
            }

            $this->assertScopeIdsBelongToLegacyUnit($scopeType, $scopeIds->all(), $legacyType, $legacyId);
        }

        $assignment->departmentScopes()->delete();

        if ($mode === 'none') {
            return;
        }

        if ($mode === 'all') {
            OrganizationLeadershipAssignmentScope::query()->create([
                'leadership_assignment_id' => (int) $assignment->id,
                'scope_type' => $this->allScopeType($scopeType),
                'scope_id' => null,
                'request_type' => $requestType,
                'requester_level' => $this->requesterLevelForLegacyType($legacyType),
                'is_active' => true,
            ]);

            return;
        }

        foreach ($scopeIds as $scopeId) {
            OrganizationLeadershipAssignmentScope::query()->create([
                'leadership_assignment_id' => (int) $assignment->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'request_type' => $requestType,
                'requester_level' => $this->requesterLevelForLegacyType($legacyType),
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return array{
     *   mode: string,
     *   department_ids: list<int>,
     *   request_type: string,
     *   department_labels: list<string>
     * }
     */
    public function summarizeScopes(OrganizationPositionAssignment $assignment): array
    {
        $scopes = $assignment->relationLoaded('activeDepartmentScopes')
            ? $assignment->activeDepartmentScopes
            : $assignment->activeDepartmentScopes()->get();

        if ($scopes->isEmpty()) {
            return [
                'mode' => 'none',
                'scope_type' => null,
                'scope_ids' => [],
                'department_ids' => [],
                'request_type' => OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL,
                'scope_labels' => [],
                'department_labels' => [],
            ];
        }

        $requestType = $this->normalizeRequestType($scopes->first()?->request_type);
        $allScope = $scopes->first(fn (OrganizationLeadershipAssignmentScope $scope): bool => str_starts_with((string) $scope->scope_type, 'all_'));
        if ($allScope) {
            $scopeType = $this->scopeTypeFromAllScope((string) $allScope->scope_type);
            $label = 'All '.$this->pluralScopeLabel($scopeType);

            return [
                'mode' => 'all',
                'scope_type' => $scopeType,
                'scope_ids' => [],
                'department_ids' => [],
                'request_type' => $requestType,
                'scope_labels' => [$label],
                'department_labels' => $scopeType === 'department' ? [$label] : [],
            ];
        }

        if ($scopes->contains(fn (OrganizationLeadershipAssignmentScope $scope): bool => $scope->scope_type === OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS)) {
            return [
                'mode' => 'all',
                'scope_type' => 'department',
                'scope_ids' => [],
                'department_ids' => [],
                'request_type' => $requestType,
                'scope_labels' => ['All departments'],
                'department_labels' => ['All departments'],
            ];
        }

        $scopeType = (string) ($scopes->first()?->scope_type ?? OrganizationLeadershipAssignmentScope::SCOPE_DEPARTMENT);
        $scopeIds = $scopes
            ->where('scope_type', $scopeType)
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $labels = $this->labelsForScopeIds($scopeType, $scopeIds);
        $departmentIds = $scopeType === 'department' ? $scopeIds : [];

        return [
            'mode' => $scopeIds === [] ? 'none' : 'selected',
            'scope_type' => $scopeType,
            'scope_ids' => $scopeIds,
            'department_ids' => $departmentIds,
            'request_type' => $requestType,
            'scope_labels' => $labels,
            'department_labels' => $scopeType === 'department' ? $labels : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scopePayloadForAssignment(OrganizationPositionAssignment $assignment): array
    {
        $summary = $this->summarizeScopes($assignment);
        $isDepartmentScope = ($summary['scope_type'] ?? null) === 'department';

        return [
            'approval_scope_type' => ($summary['mode'] ?? 'none') === 'none' ? 'none' : $summary['scope_type'],
            'approval_scope_mode' => $summary['mode'],
            'approval_scope_ids' => $summary['scope_ids'],
            'approval_scope_labels' => $summary['scope_labels'],
            'department_scope_mode' => $isDepartmentScope ? $summary['mode'] : 'none',
            'department_scope_ids' => $isDepartmentScope ? $summary['department_ids'] : [],
            'department_scope_labels' => $isDepartmentScope ? $summary['department_labels'] : [],
            'scope_request_type' => $summary['request_type'],
        ];
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  array<string, mixed>  $context
     */
    public function assignmentMatchesApprovalScope(
        OrganizationPositionAssignment $assignment,
        array $hierarchy,
        ?string $requestType,
        array $context = [],
    ): bool {
        if (! Schema::hasTable('organization_leadership_assignment_scopes')) {
            return true;
        }

        $scopes = $assignment->relationLoaded('activeDepartmentScopes')
            ? $assignment->activeDepartmentScopes
            : $assignment->activeDepartmentScopes()->get();

        if ($scopes->isEmpty()) {
            $this->log($context, 'leadership assignment skipped — approval scope is none', [
                'assignment_id' => (int) $assignment->id,
                'employee_id' => (int) $assignment->employee_id,
                'head_type' => $context['organization_type'] ?? $context['legacy_type'] ?? null,
                'approval_scope_type' => 'none',
                'scope_match' => false,
                'request_type_match' => false,
                'can_approve' => (bool) ($assignment->positionType?->can_approve ?? true),
                'is_active' => (bool) $assignment->is_active,
                'skipped_reason' => 'approval_scope_none',
            ]);

            return false;
        }

        $normalizedRequestType = $this->normalizeRequestType($requestType);
        $matchingRequestScopes = $scopes->filter(function (OrganizationLeadershipAssignmentScope $scope) use ($normalizedRequestType): bool {
            return $scope->request_type === OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL
                || $scope->request_type === $normalizedRequestType;
        });

        if ($matchingRequestScopes->isEmpty()) {
            $this->log($context, 'leadership assignment skipped — request type outside approval scope', [
                'assignment_id' => (int) $assignment->id,
                'employee_id' => (int) $assignment->employee_id,
                'head_type' => $context['organization_type'] ?? $context['legacy_type'] ?? null,
                'approval_scope_type' => $this->summarizeScopes($assignment)['scope_type'] ?? null,
                'scope_match' => false,
                'request_type_match' => false,
                'can_approve' => (bool) ($assignment->positionType?->can_approve ?? true),
                'is_active' => (bool) $assignment->is_active,
                'request_type' => $normalizedRequestType,
                'skipped_reason' => 'request_type_not_in_scope',
            ]);

            return false;
        }

        foreach ($matchingRequestScopes as $scope) {
            $scopeType = (string) $scope->scope_type;
            if (str_starts_with($scopeType, 'all_')) {
                if ($this->scopeTypeExistsInHierarchy($this->scopeTypeFromAllScope($scopeType), $hierarchy)) {
                    return true;
                }

                continue;
            }

            if ($this->scopeRowMatchesRequesterPath($scopeType, (int) $scope->scope_id, $hierarchy)) {
                return true;
            }
        }

        $this->log($context, 'leadership assignment skipped — requester outside approval scope', [
            'assignment_id' => (int) $assignment->id,
            'employee_id' => (int) $assignment->employee_id,
            'head_type' => $context['organization_type'] ?? $context['legacy_type'] ?? null,
            'approval_scope_type' => $this->summarizeScopes($assignment)['scope_type'] ?? null,
            'scope_match' => false,
            'request_type_match' => true,
            'can_approve' => (bool) ($assignment->positionType?->can_approve ?? true),
            'is_active' => (bool) $assignment->is_active,
            'request_type' => $normalizedRequestType,
            'requester_hierarchy' => $hierarchy,
            'skipped_reason' => 'requester_outside_scope',
        ]);

        return false;
    }

    private function assignmentMatchesDepartmentHeadRequest(
        OrganizationPositionAssignment $assignment,
        int $departmentId,
        ?string $requestType,
    ): bool {
        if (! $this->isDepartmentScopedRequestType($requestType)) {
            return false;
        }

        $scopes = $assignment->relationLoaded('activeDepartmentScopes')
            ? $assignment->activeDepartmentScopes
            : $assignment->activeDepartmentScopes()->get();

        if ($scopes->isEmpty()) {
            return false;
        }

        $normalizedRequestType = $this->normalizeRequestType($requestType);
        $matchingRequestScopes = $scopes->filter(function (OrganizationLeadershipAssignmentScope $scope) use ($normalizedRequestType): bool {
            if ($scope->request_type !== OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL
                && $scope->request_type !== $normalizedRequestType) {
                return false;
            }

            return true;
        });

        if ($matchingRequestScopes->isEmpty()) {
            return false;
        }

        if ($matchingRequestScopes->contains(fn (OrganizationLeadershipAssignmentScope $scope): bool => $scope->scope_type === OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS)) {
            return true;
        }

        if ($departmentId <= 0) {
            return false;
        }

        return $matchingRequestScopes
            ->where('scope_type', OrganizationLeadershipAssignmentScope::SCOPE_DEPARTMENT)
            ->contains(fn (OrganizationLeadershipAssignmentScope $scope): bool => (int) $scope->scope_id === $departmentId);
    }

    /**
     * @param  array{mode: string, department_ids: list<int>, request_type: string, department_labels: list<string>}  $scopeSummary
     */
    private function scopeSkipReason(array $scopeSummary, int $departmentId, bool $scopeMatch): ?string
    {
        if ($scopeMatch) {
            return null;
        }

        if (($scopeSummary['mode'] ?? 'none') === 'none') {
            return 'approval_scope_none';
        }

        if ($departmentId <= 0) {
            return 'requester_department_missing';
        }

        return 'requester_department_not_in_division_head_scope';
    }

    private function isDepartmentScopedRequestType(?string $requestType): bool
    {
        $normalized = $this->normalizeRequestType($requestType);

        return in_array($normalized, [
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_LEAVE,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_OVERTIME,
        ], true);
    }

    /**
     * @param  list<int>  $departmentIds
     */
    private function assertDepartmentsBelongToDivision(array $departmentIds, int $divisionId): void
    {
        if ($departmentIds === []) {
            return;
        }

        $division = Division::query()->find($divisionId);
        if (! $division) {
            throw ValidationException::withMessages([
                'assignments' => ['Division could not be resolved for department approval scope.'],
            ]);
        }

        foreach ($departmentIds as $departmentId) {
            $department = Department::query()->find((int) $departmentId);
            if (! $department) {
                throw ValidationException::withMessages([
                    'assignments' => ['One or more selected departments could not be found.'],
                ]);
            }

            if ((int) ($department->division_id ?? 0) === $divisionId) {
                continue;
            }

            if ($this->departmentMatchesDivisionOrg($department, $division)) {
                $department->forceFill(['division_id' => $divisionId])->save();

                continue;
            }

            throw ValidationException::withMessages([
                'assignments' => ["Department \"{$department->name}\" does not belong to this division."],
            ]);
        }
    }

    private function departmentMatchesDivisionOrg(Department $department, Division $division): bool
    {
        if ((int) ($department->company_id ?? 0) !== (int) ($division->company_id ?? 0)) {
            return false;
        }

        if ($division->branch_id !== null) {
            return (int) ($department->branch_id ?? 0) === (int) $division->branch_id;
        }

        return $department->branch_id === null;
    }

    /**
     * @param  list<int>  $skipIds
     */
    private function isValidApprover(User $leader, array $skipIds): bool
    {
        return (bool) $leader->is_active
            && $leader->isRosterEligible()
            && $leader->isOperationallyActive()
            && ! in_array((int) $leader->id, $skipIds, true);
    }

    /**
     * @param  list<int>  $scopeIds
     */
    private function assertScopeIdsBelongToLegacyUnit(string $scopeType, array $scopeIds, string $legacyType, int $legacyId): void
    {
        $availableIds = collect($this->approvalScopeOptions($legacyType, $legacyId)[$scopeType] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missing = array_values(array_diff($scopeIds, $availableIds));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'assignments' => ['One or more selected approval scope items do not belong to this organization unit.'],
            ]);
        }
    }

    private function normalizeScopeType(?string $scopeType, string $legacyType): string
    {
        $allowed = match ($legacyType) {
            'company' => ['company', 'area', 'branch', 'division', 'department', 'section_unit'],
            'area' => ['area', 'branch', 'division', 'department', 'section_unit'],
            'branch' => ['branch', 'division', 'department', 'section_unit'],
            'division' => ['department'],
            default => ['department'],
        };

        $normalized = trim((string) ($scopeType ?? ''));

        return in_array($normalized, $allowed, true) ? $normalized : $allowed[0];
    }

    private function allScopeType(string $scopeType): string
    {
        return $scopeType === 'department'
            ? OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS
            : 'all_'.$scopeType.'s';
    }

    private function scopeTypeFromAllScope(string $scopeType): string
    {
        if ($scopeType === OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS) {
            return 'department';
        }

        $normalized = preg_replace('/^all_/', '', $scopeType) ?? $scopeType;
        $normalized = preg_replace('/s$/', '', $normalized) ?? $normalized;

        return $normalized === 'section_unit' ? 'section_unit' : $normalized;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function scopeTypeExistsInHierarchy(string $scopeType, array $hierarchy): bool
    {
        return (int) ($hierarchy[$scopeType] ?? 0) > 0;
    }

    /**
     * Match a scoped leader to the requester's resolved organization path.
     *
     * @param  array<string, int|null>  $hierarchy
     */
    private function scopeRowMatchesRequesterPath(string $scopeType, int $scopeId, array $hierarchy): bool
    {
        if ($scopeId <= 0) {
            return false;
        }

        $requesterId = (int) ($hierarchy[$scopeType] ?? 0);
        if ($requesterId > 0 && $requesterId === $scopeId) {
            return true;
        }

        return match ($scopeType) {
            'section_unit' => (int) ($hierarchy['section_unit'] ?? 0) === $scopeId,
            'department' => (int) ($hierarchy['department'] ?? 0) === $scopeId,
            'division' => (int) ($hierarchy['division'] ?? 0) === $scopeId,
            'branch' => (int) ($hierarchy['branch'] ?? 0) === $scopeId,
            'area' => (int) ($hierarchy['area'] ?? 0) === $scopeId,
            'company' => (int) ($hierarchy['company'] ?? 0) === $scopeId,
            default => false,
        };
    }

    private function pluralScopeLabel(?string $scopeType): string
    {
        return match ($scopeType) {
            'company' => 'companies',
            'area' => 'areas',
            'branch' => 'branches',
            'division' => 'divisions',
            'section_unit' => 'sections',
            default => 'departments',
        };
    }

    /**
     * @param  list<int>  $scopeIds
     * @return list<string>
     */
    private function labelsForScopeIds(string $scopeType, array $scopeIds): array
    {
        if ($scopeIds === []) {
            return [];
        }

        $query = match ($scopeType) {
            'company' => Company::query(),
            'area' => Area::query()->select('id', 'area_name as name'),
            'branch' => Branch::query(),
            'division' => Division::query(),
            'section_unit' => SectionUnit::query(),
            default => Department::query(),
        };

        return $query
            ->whereIn('id', $scopeIds)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();
    }

    private function requesterLevelForLegacyType(string $legacyType): string
    {
        return match ($legacyType) {
            'company' => 'company_head',
            'area' => 'area_head',
            'branch' => 'branch_head',
            'division' => OrganizationLeadershipAssignmentScope::REQUESTER_DEPARTMENT_HEAD,
            default => $legacyType.'_head',
        };
    }

    private function normalizeScopeMode(?string $mode): string
    {
        return match (trim((string) ($mode ?? ''))) {
            'all' => 'all',
            'selected' => 'selected',
            default => 'none',
        };
    }

    private function normalizeRequestType(?string $requestType): string
    {
        $normalized = trim((string) ($requestType ?? ''));

        return match ($normalized) {
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_LEAVE,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_OVERTIME,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ATTENDANCE_CORRECTION,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_OFFICIAL_BUSINESS,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_CHANGE_SCHEDULE,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_PAYROLL_APPROVAL,
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_SCHEDULE => $normalized,
            default => OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function log(array $context, string $message, array $payload = []): void
    {
        Log::info('approval_chain: '.$message, array_merge([
            'request_type' => $context['request_type'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'module_type' => $context['module_type'] ?? null,
            'requester_employee_id' => $context['requester_employee_id'] ?? null,
            'requester_name' => $context['requester_name'] ?? null,
        ], $payload));
    }
}
