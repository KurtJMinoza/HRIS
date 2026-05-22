<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationLeadershipAssignmentScope;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
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
     * @param  array<string, mixed>  $row
     */
    public function syncAssignmentScopes(
        OrganizationPositionAssignment $assignment,
        array $row,
        string $legacyType,
        int $legacyId,
    ): void {
        if ($legacyType !== 'division' || ! Schema::hasTable('organization_leadership_assignment_scopes')) {
            return;
        }

        if (! (bool) ($assignment->positionType?->can_approve ?? true)) {
            $assignment->departmentScopes()->delete();

            return;
        }

        $mode = $this->normalizeScopeMode($row['department_scope_mode'] ?? null);
        $requestType = $this->normalizeRequestType($row['scope_request_type'] ?? null);
        $departmentIds = collect($row['department_scope_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($mode === 'selected') {
            if ($departmentIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'assignments' => ['Select at least one department when using selected department approval scope.'],
                ]);
            }

            $this->assertDepartmentsBelongToDivision($departmentIds->all(), $legacyId);
        }

        $assignment->departmentScopes()->delete();

        if ($mode === 'none') {
            return;
        }

        if ($mode === 'all') {
            OrganizationLeadershipAssignmentScope::query()->create([
                'leadership_assignment_id' => (int) $assignment->id,
                'scope_type' => OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS,
                'scope_id' => null,
                'request_type' => $requestType,
                'requester_level' => OrganizationLeadershipAssignmentScope::REQUESTER_DEPARTMENT_HEAD,
                'is_active' => true,
            ]);

            return;
        }

        foreach ($departmentIds as $departmentId) {
            OrganizationLeadershipAssignmentScope::query()->create([
                'leadership_assignment_id' => (int) $assignment->id,
                'scope_type' => OrganizationLeadershipAssignmentScope::SCOPE_DEPARTMENT,
                'scope_id' => $departmentId,
                'request_type' => $requestType,
                'requester_level' => OrganizationLeadershipAssignmentScope::REQUESTER_DEPARTMENT_HEAD,
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
                'department_ids' => [],
                'request_type' => OrganizationLeadershipAssignmentScope::REQUEST_TYPE_ALL,
                'department_labels' => [],
            ];
        }

        $requestType = $this->normalizeRequestType($scopes->first()?->request_type);

        if ($scopes->contains(fn (OrganizationLeadershipAssignmentScope $scope): bool => $scope->scope_type === OrganizationLeadershipAssignmentScope::SCOPE_ALL_DEPARTMENTS)) {
            return [
                'mode' => 'all',
                'department_ids' => [],
                'request_type' => $requestType,
                'department_labels' => ['All departments'],
            ];
        }

        $departmentIds = $scopes
            ->where('scope_type', OrganizationLeadershipAssignmentScope::SCOPE_DEPARTMENT)
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $labels = Department::query()
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return [
            'mode' => $departmentIds === [] ? 'none' : 'selected',
            'department_ids' => $departmentIds,
            'request_type' => $requestType,
            'department_labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scopePayloadForAssignment(OrganizationPositionAssignment $assignment): array
    {
        $summary = $this->summarizeScopes($assignment);

        return [
            'department_scope_mode' => $summary['mode'],
            'department_scope_ids' => $summary['department_ids'],
            'department_scope_labels' => $summary['department_labels'],
            'scope_request_type' => $summary['request_type'],
        ];
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
            return 'division_head_scope_is_none';
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
            OrganizationLeadershipAssignmentScope::REQUEST_TYPE_OVERTIME => $normalized,
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
