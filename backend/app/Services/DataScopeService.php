<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces org scope for managerial employees (branch / department / company).
 * Aligns with least-privilege: lower roles cannot read or mutate data outside their scope.
 */
class DataScopeService
{
    public function __construct(
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
    ) {}

    /**
     * Departments where this user is the assigned head (single source for RBAC scoping).
     *
     * @return EloquentCollection<int, Department>
     */
    private function departmentsForDepartmentHead(User $actor): EloquentCollection
    {
        $ids = $this->leadershipAssignments->departmentIdsLedBy($actor);
        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return Department::query()
            ->whereIn('id', $ids->all())
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id']);
    }

    /**
     * @return EloquentCollection<int, Division>
     */
    private function divisionsForDivisionHead(User $actor): EloquentCollection
    {
        $ids = $this->leadershipAssignments->divisionIdsLedBy($actor);
        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return Division::query()
            ->whereIn('id', $ids->all())
            ->orderBy('name')
            ->get(['id', 'name', 'company_id', 'branch_id']);
    }

    /**
     * @return EloquentCollection<int, SectionUnit>
     */
    private function sectionUnitsForSectionUnitHead(User $actor): EloquentCollection
    {
        $headIds = $this->leadershipAssignments->sectionUnitIdsLedBy($actor);

        $fromHead = $headIds->isNotEmpty()
            ? SectionUnit::query()
                ->whereIn('id', $headIds->all())
                ->orderBy('name')
                ->get(['id', 'name', 'company_id', 'branch_id', 'department_id', 'division_id'])
            : new EloquentCollection;

        $fromPivot = SectionUnit::query()
            ->whereHas('teamLeaders', fn ($query) => $query->where('users.id', $actor->id))
            ->orderBy('name')
            ->get(['id', 'name', 'company_id', 'branch_id', 'department_id', 'division_id']);

        return $fromHead->merge($fromPivot)->unique('id')->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function departmentIdsForTeamLeaderScope(User $actor): \Illuminate\Support\Collection
    {
        return Department::query()
            ->whereHas('teamLeaders', fn ($query) => $query->where('users.id', $actor->id))
            ->pluck('id');
    }

    /**
     * Company ids visible to a Company Head.
     *
     * Primary source is org assignment (companies.company_head_id). Fallback to the actor's
     * effective company so HR role + company membership still works when assignment data is stale.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function companyIdsForCompanyHead(User $actor): \Illuminate\Support\Collection
    {
        $ids = $this->leadershipAssignments->companyIdsLedBy($actor)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($ids->isNotEmpty()) {
            return $ids;
        }

        $effectiveCompanyId = $actor->getEffectiveCompanyId();
        if ($effectiveCompanyId !== null && $effectiveCompanyId > 0) {
            return collect([(int) $effectiveCompanyId]);
        }

        return collect();
    }

    /**
     * Organizational scope for data queries.
     * Admin (HR) is a super-role and bypasses org scoping (returns null).
     */
    private function effectiveOrgScopeRole(User $actor): ?HrRole
    {
        if ($actor->isAdmin()) {
            return null;
        }

        $role = $this->hrRoleResolver->resolveForApprovalSubject($actor);
        if ($role === HrRole::AdminHr) {
            return null;
        }

        return $role;
    }

    /**
     * Hints for attendance filter UI (department / branch / company heads).
     *
     * @return array<string, mixed>|null
     */
    public function getAttendanceScopeMeta(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        $role = $this->hrRoleResolver->resolveForApprovalSubject($user);

        if ($role === HrRole::DepartmentHead) {
            $depts = $this->departmentsForDepartmentHead($user);

            return [
                'kind' => 'department',
                'department_ids' => $depts->pluck('id')->all(),
                'department_names' => $depts->pluck('name')->filter()->values()->all(),
            ];
        }

        if ($role === HrRole::DivisionHead) {
            $divisions = $this->divisionsForDivisionHead($user);
            $divisionIds = $divisions->pluck('id');
            $sections = $divisionIds->isNotEmpty()
                ? SectionUnit::query()->whereIn('division_id', $divisionIds)->orderBy('name')->get(['id', 'name', 'division_id'])
                : new EloquentCollection;

            return [
                'kind' => 'division',
                'division_ids' => $divisionIds->all(),
                'division_names' => $divisions->pluck('name')->filter()->values()->all(),
                'section_unit_ids' => $sections->pluck('id')->all(),
                'section_unit_names' => $sections->pluck('name')->filter()->values()->all(),
            ];
        }

        if ($role === HrRole::SectionUnitHead) {
            $sections = $this->sectionUnitsForSectionUnitHead($user);

            return [
                'kind' => 'section_unit',
                'section_unit_ids' => $sections->pluck('id')->all(),
                'section_unit_names' => $sections->pluck('name')->filter()->values()->all(),
                'division_ids' => $sections->pluck('division_id')->filter()->unique()->values()->all(),
            ];
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($user);
            if ($branchIds->isEmpty()) {
                return [
                    'kind' => 'branch',
                    'branch_id' => null,
                    'branch_name' => null,
                    'department_ids' => [],
                    'department_names' => [],
                ];
            }

            $branches = Branch::query()
                ->whereIn('id', $branchIds->all())
                ->orderBy('name')
                ->get(['id', 'name']);
            $primaryBranch = $branches->first();
            $depts = Department::query()
                ->whereIn('branch_id', $branchIds->all())
                ->orderBy('name')
                ->get(['id', 'name']);

            return [
                'kind' => 'branch',
                'branch_id' => $primaryBranch ? (int) $primaryBranch->id : null,
                'branch_name' => $primaryBranch?->name,
                'branch_ids' => $branches->pluck('id')->all(),
                'branch_names' => $branches->pluck('name')->filter()->values()->all(),
                'department_ids' => $depts->pluck('id')->all(),
                'department_names' => $depts->pluck('name')->filter()->values()->all(),
            ];
        }

        if ($role === HrRole::CompanyHead) {
            $companyIds = $this->companyIdsForCompanyHead($user);
            $companies = Company::query()
                ->whereIn('id', $companyIds->all())
                ->orderBy('name')
                ->get(['id', 'name']);

            return [
                'kind' => 'company',
                'company_ids' => $companies->pluck('id')->all(),
                'company_names' => $companies->pluck('name')->filter()->values()->all(),
            ];
        }

        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function branchIdsForBranchScope(User $actor): \Illuminate\Support\Collection
    {
        return $this->leadershipAssignments->branchIdsLedBy($actor);
    }

    /**
     * Branch row for branch-level data scope (first assigned branch).
     */
    private function branchForBranchScope(User $actor): ?Branch
    {
        $branchIds = $this->branchIdsForBranchScope($actor);
        if ($branchIds->isEmpty()) {
            return null;
        }

        return Branch::query()->whereKey($branchIds->first())->first();
    }

    /**
     * Department ids for department-level data scope.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function departmentIdsForDepartmentScope(User $actor): \Illuminate\Support\Collection
    {
        return $this->departmentsForDepartmentHead($actor)->pluck('id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function divisionIdsForDivisionScope(User $actor): \Illuminate\Support\Collection
    {
        return $this->divisionsForDivisionHead($actor)->pluck('id');
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function sectionUnitIdsForSectionScope(User $actor): \Illuminate\Support\Collection
    {
        return $this->sectionUnitsForSectionUnitHead($actor)->pluck('id');
    }

    /**
     * Scope regularization recommendation rows to employees the actor may see (org / admin).
     * Uses {@see restrictEmployeeQuery()} on the subject employee via whereHas — avoids ad-hoc subqueries
     * and enforces company / branch / department boundaries.
     *
     * @param  Builder<\App\Models\RegularizationRecommendation>  $query
     */
    public function restrictRegularizationRecommendationQuery(User $actor, Builder $query): void
    {
        $query->whereHas('user', function (Builder $q) use ($actor) {
            $q->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
            $this->restrictEmployeeQuery($actor, $q);
        });
    }

    /**
     * Employee IDs the actor may view in attendance, reports, employee lists, and dashboards.
     *
     * @return list<int>|null null = unrestricted (Admin HR)
     */
    public function getScopedEmployeeIdsForUser(User $actor): ?array
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return null;
        }

        if ($role === HrRole::Employee) {
            return [];
        }

        if ($role === HrRole::SectionUnitHead) {
            return $this->resolveSectionUnitHeadScope($actor)['final_scoped_employee_ids'];
        }

        $scopedQuery = User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
        $this->restrictEmployeeQuery($actor, $scopedQuery);

        return $scopedQuery->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Restrict a query of employee users to those visible to the actor.
     *
     * @param  array{branch_id_for_department_assignment?: int}  $options
     *                                                                     When {@see branch_id_for_department_assignment} is set and the actor is a Department Head,
     *                                                                     scope widens to that branch (same as Branch Head) so Admin → Assign Employees can list
     *                                                                     unassigned and other-department staff in the branch. The branch must belong to a department
     *                                                                     this user heads.
     */
    public function restrictEmployeeQuery(User $actor, Builder $query, array $options = []): void
    {
        $branchIdForDepartmentAssignment = isset($options['branch_id_for_department_assignment'])
            ? (int) $options['branch_id_for_department_assignment']
            : null;

        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $ids = $this->companyIdsForCompanyHead($actor);
            if ($ids->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $idList = $ids->all();
            $query->where(function ($q) use ($ids) {
                $q->whereIn('company_id', $ids)
                    ->orWhereHas('branch', fn ($b) => $b->whereIn('company_id', $ids))
                    ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->whereIn('company_id', $ids)));
            });
            // Multi-company: do not show employees who are the head of another company.
            $query->whereNotExists(function ($q) use ($idList) {
                $q->select(DB::raw(1))
                    ->from('companies')
                    ->whereColumn('companies.company_head_id', 'users.id')
                    ->whereNotIn('companies.id', $idList);
            });

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->where(function ($q) use ($branchIds) {
                $q->whereIn('branch_id', $branchIds->all())
                    ->orWhereHas('departmentRelation', fn ($d) => $d->whereIn('branch_id', $branchIds->all()));
            });
            $this->excludeUsersWhoAreCompanyHeads($query);

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $deptIds = $this->departmentIdsForDepartmentScope($actor);
            $departments = $deptIds->isNotEmpty()
                ? Department::query()->whereIn('id', $deptIds)->orderBy('name')->get(['id', 'name', 'branch_id'])
                : new EloquentCollection;
            if ($departments->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            if ($branchIdForDepartmentAssignment !== null) {
                $allowedBranchIds = $departments->pluck('branch_id')->unique()->filter()->values();
                if (! $allowedBranchIds->contains($branchIdForDepartmentAssignment)) {
                    $query->whereRaw('1 = 0');

                    return;
                }
                $bid = $branchIdForDepartmentAssignment;
                $query->where(function ($q) use ($bid) {
                    $q->where('branch_id', $bid)
                        ->orWhereHas('departmentRelation', fn ($d) => $d->where('branch_id', $bid))
                        ->orWhere(function ($q2) {
                            $q2->whereNull('company_id')
                                ->whereDoesntHave('companyHeadships');
                        });
                });
                $this->excludeUsersWhoAreCompanyHeads($query);
                $this->excludeUsersWhoAreBranchManagers($query);

                return;
            }

            // Primary: FK on users.department_id. Legacy: same label + same branch as the headed
            // department row only (name-only matching leaked users from other branches/companies).
            $query->where(function ($q) use ($departments) {
                $q->whereIn('department_id', $departments->pluck('id'));
                foreach ($departments as $dept) {
                    $name = $dept->name;
                    if (! is_string($name) || trim($name) === '') {
                        continue;
                    }
                    if ($dept->branch_id === null) {
                        continue;
                    }
                    $branchId = (int) $dept->branch_id;
                    $q->orWhere(function ($q2) use ($name, $branchId) {
                        $q2->whereNull('department_id')
                            ->where('department', $name)
                            ->where(function ($q3) use ($branchId) {
                                $q3->where('branch_id', $branchId)
                                    ->orWhereHas('departmentRelation', fn ($d) => $d->where('branch_id', $branchId));
                            });
                    });
                }
            });
            $this->excludeUsersWhoAreCompanyHeads($query);
            $this->excludeUsersWhoAreBranchManagers($query);

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $divisionIds = $this->divisionIdsForDivisionScope($actor);
            if ($divisionIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            $sectionIds = SectionUnit::query()
                ->whereIn('division_id', $divisionIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            $query->where(function ($q) use ($divisionIds, $sectionIds) {
                $q->whereIn('division_id', $divisionIds);
                if ($sectionIds->isNotEmpty()) {
                    $q->orWhereIn('section_unit_id', $sectionIds);
                }
            });
            $this->excludeUsersWhoAreCompanyHeads($query);
            $this->excludeUsersWhoAreBranchManagers($query);
            $this->excludeUsersWhoAreDepartmentHeads($query);

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $scope = $this->resolveSectionUnitHeadScope($actor);
            $teamMemberIds = $scope['team_member_employee_ids'];

            $query->where(function (Builder $outer) use ($actor, $teamMemberIds): void {
                $outer->where('users.id', $actor->id);

                if ($teamMemberIds !== []) {
                    $outer->orWhere(function (Builder $team) use ($teamMemberIds): void {
                        $team->whereIn('users.id', $teamMemberIds);
                        $this->excludeUsersWhoAreCompanyHeads($team);
                        $this->excludeUsersWhoAreBranchManagers($team);
                        $this->excludeUsersWhoAreDepartmentHeads($team);
                        $this->excludeUsersWhoAreDivisionHeads($team);
                    });
                }
            });

            return;
        }
    }

    /**
     * @return array{
     *   leader_section_unit_ids: list<int>,
     *   primary_employee_ids: list<int>,
     *   shared_employee_ids: list<int>,
     *   department_team_leader_employee_ids: list<int>,
     *   team_member_employee_ids: list<int>,
     *   final_scoped_employee_ids: list<int>,
     *   own_employee_id_added: bool
     * }
     */
    private function resolveSectionUnitHeadScope(User $actor): array
    {
        $actorId = (int) $actor->id;
        $sectionIds = $this->sectionUnitIdsForSectionScope($actor)->all();
        $departmentTeamLeaderIds = $this->departmentIdsForTeamLeaderScope($actor);

        if ($sectionIds === [] && $departmentTeamLeaderIds->isEmpty()) {
            Log::info('employee_scope: section/team leader scoped employee ids', [
                'current_user_id' => $actorId,
                'current_employee_id' => $actorId,
                'role' => $this->hrRoleResolver->resolveForApprovalSubject($actor)->value,
                'leader_section_unit_ids' => [],
                'own_employee_id_added' => true,
                'primary_scoped_employees' => [],
                'shared_scoped_employees' => [],
                'department_team_leader_employee_ids' => [],
                'team_member_employee_ids' => [],
                'final_scoped_employee_ids' => [$actorId],
                'attendance_query_employee_ids' => [$actorId],
                'report_query_employee_ids' => [$actorId],
            ]);

            return [
                'leader_section_unit_ids' => [],
                'primary_employee_ids' => [],
                'shared_employee_ids' => [],
                'department_team_leader_employee_ids' => [],
                'team_member_employee_ids' => [],
                'final_scoped_employee_ids' => [$actorId],
                'own_employee_id_added' => true,
            ];
        }

        $sharedEmployeeIds = $this->sharedEmployeeIdsForSectionScope($sectionIds);
        $primaryEmployeeIds = $sectionIds !== []
            ? User::query()
                ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereIn('section_unit_id', $sectionIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        $departmentTeamLeaderEmployeeIds = $departmentTeamLeaderIds->isNotEmpty()
            ? User::query()
                ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
                ->whereIn('department_id', $departmentTeamLeaderIds->all())
                ->where('supervisor_id', $actor->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];
        $teamMemberEmployeeIds = array_values(array_unique([
            ...$primaryEmployeeIds,
            ...$sharedEmployeeIds,
            ...$departmentTeamLeaderEmployeeIds,
        ]));
        $finalScopedEmployeeIds = array_values(array_unique([
            $actorId,
            ...$teamMemberEmployeeIds,
        ]));

        Log::info('employee_scope: section/team leader scoped employee ids', [
            'current_user_id' => $actorId,
            'current_employee_id' => $actorId,
            'role' => $this->hrRoleResolver->resolveForApprovalSubject($actor)->value,
            'leader_section_unit_ids' => $sectionIds,
            'own_employee_id_added' => in_array($actorId, $finalScopedEmployeeIds, true),
            'primary_scoped_employees' => $primaryEmployeeIds,
            'shared_scoped_employees' => $sharedEmployeeIds,
            'department_team_leader_employee_ids' => $departmentTeamLeaderEmployeeIds,
            'team_member_employee_ids' => $teamMemberEmployeeIds,
            'final_scoped_employee_ids' => $finalScopedEmployeeIds,
            'attendance_query_employee_ids' => $finalScopedEmployeeIds,
            'report_query_employee_ids' => $finalScopedEmployeeIds,
        ]);

        return [
            'leader_section_unit_ids' => $sectionIds,
            'primary_employee_ids' => $primaryEmployeeIds,
            'shared_employee_ids' => $sharedEmployeeIds,
            'department_team_leader_employee_ids' => $departmentTeamLeaderEmployeeIds,
            'team_member_employee_ids' => $teamMemberEmployeeIds,
            'final_scoped_employee_ids' => $finalScopedEmployeeIds,
            'own_employee_id_added' => true,
        ];
    }

    /**
     * @param  array<int, int>  $sectionIds
     * @return list<int>
     */
    private function sharedEmployeeIdsForSectionScope(array $sectionIds): array
    {
        $sectionIds = array_values(array_filter(array_map('intval', $sectionIds), fn (int $id): bool => $id > 0));
        if ($sectionIds === []) {
            return [];
        }

        if (! Schema::hasTable('employee_organization_assignments')) {
            return [];
        }

        return EmployeeOrganizationAssignment::query()
            ->active()
            ->whereIn('section_unit_id', $sectionIds)
            ->whereIn('assignment_type', [
                EmployeeOrganizationAssignment::TYPE_SHARED,
                EmployeeOrganizationAssignment::TYPE_TEMPORARY,
                EmployeeOrganizationAssignment::TYPE_ACTING,
            ])
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Subordinate managers (department / branch heads) must not see company-level heads in rosters.
     */
    private function excludeUsersWhoAreCompanyHeads(Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('companies')
                ->whereColumn('companies.company_head_id', 'users.id');
        });
    }

    /**
     * Department heads must not see branch managers (other branches or their own org hat).
     */
    private function excludeUsersWhoAreBranchManagers(Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('branches')
                ->whereColumn('branches.branch_manager_id', 'users.id');
        });
    }

    private function excludeUsersWhoAreDepartmentHeads(Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('departments')
                ->whereColumn('departments.department_head_id', 'users.id');
        });
    }

    private function excludeUsersWhoAreDivisionHeads(Builder $query): void
    {
        $query->whereNotExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('divisions')
                ->whereColumn('divisions.division_head_id', 'users.id');
        });
    }

    /**
     * Ensure a new employee record may be created under the given org fields (managers only).
     */
    public function assertCanCreateEmployeeInOrg(
        User $actor,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $divisionId = null,
        ?int $sectionUnitId = null
    ): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $role = $this->hrRoleResolver->resolve($actor);
        if ($role === HrRole::Employee) {
            abort(403, 'Forbidden.');
        }

        if ($role === HrRole::CompanyHead) {
            $companyIds = $this->companyIdsForCompanyHead($actor);
            if ($companyIds->isEmpty()) {
                abort(403, 'Forbidden.');
            }
            if ($companyId !== null && ! $companyIds->contains($companyId)) {
                abort(403, 'Forbidden.');
            }
            if ($branchId !== null) {
                $b = Branch::query()->find($branchId);
                if (! $b || ! $companyIds->contains((int) $b->company_id)) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($departmentId !== null) {
                $d = Department::query()->with('branch')->find($departmentId);
                if (! $d || ! $d->branch || ! $companyIds->contains((int) $d->branch->company_id)) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($divisionId !== null) {
                $division = Division::query()->find($divisionId);
                if (! $division || ($division->company_id !== null && ! $companyIds->contains((int) $division->company_id))) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($sectionUnitId !== null) {
                $section = SectionUnit::query()->find($sectionUnitId);
                if (! $section || ($section->company_id !== null && ! $companyIds->contains((int) $section->company_id))) {
                    abort(403, 'Forbidden.');
                }
            }

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            if ($branchIds->isEmpty()) {
                abort(403, 'Forbidden.');
            }
            $branches = Branch::query()->whereIn('id', $branchIds->all())->get(['id', 'company_id']);
            $companyIds = $branches->pluck('company_id')->filter()->unique()->values();
            if ($companyId !== null && ! $companyIds->contains((int) $companyId)) {
                abort(403, 'Forbidden.');
            }
            if ($branchId !== null && ! $branchIds->contains((int) $branchId)) {
                abort(403, 'Forbidden.');
            }
            if ($departmentId !== null) {
                $d = Department::query()->find($departmentId);
                if (! $d || ! $branchIds->contains((int) $d->branch_id)) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($divisionId !== null) {
                $division = Division::query()->find($divisionId);
                if (! $division || ! $branchIds->contains((int) $division->branch_id)) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($sectionUnitId !== null) {
                $section = SectionUnit::query()->find($sectionUnitId);
                if (! $section || ! $branchIds->contains((int) $section->branch_id)) {
                    abort(403, 'Forbidden.');
                }
            }

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $deptIds = $this->departmentIdsForDepartmentScope($actor);
            if ($departmentId === null || ! $deptIds->contains($departmentId)) {
                abort(403, 'Forbidden.');
            }
            if ($divisionId !== null) {
                $division = Division::query()->find($divisionId);
                $department = $departmentId !== null ? Department::query()->find($departmentId) : null;
                if (! $division || ! $deptIds->contains($departmentId)
                    || ($department !== null && (int) $department->division_id !== (int) $division->id)) {
                    abort(403, 'Forbidden.');
                }
            }
            if ($sectionUnitId !== null) {
                $section = SectionUnit::query()->find($sectionUnitId);
                if (! $section || ! $deptIds->contains((int) $section->department_id)) {
                    abort(403, 'Forbidden.');
                }
            }

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $divisionIds = $this->divisionIdsForDivisionScope($actor);
            if ($divisionId === null || ! $divisionIds->contains($divisionId)) {
                abort(403, 'Forbidden.');
            }
            if ($sectionUnitId !== null) {
                $section = SectionUnit::query()->find($sectionUnitId);
                if (! $section || ! $divisionIds->contains((int) $section->division_id)) {
                    abort(403, 'Forbidden.');
                }
            }

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $sectionIds = $this->sectionUnitIdsForSectionScope($actor);
            if ($sectionUnitId === null || ! $sectionIds->contains($sectionUnitId)) {
                abort(403, 'Forbidden.');
            }
        }
    }

    public function ensureEmployeeAccessible(User $actor, User $targetEmployee): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ((int) $actor->id === (int) $targetEmployee->id) {
            return;
        }

        if (! $targetEmployee->isRosterEligible()) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN));
        }

        $q = User::query()->whereKey($targetEmployee->id);
        $this->restrictEmployeeQuery($actor, $q);
        if (! $q->exists()) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN));
        }
    }

    /**
     * Presence-filing / correction subject: employee in scope, own record, or admin (HR) actor.
     */
    public function ensureCorrectionSubjectAccessible(User $actor, User $subject): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ((int) $actor->id === (int) $subject->id) {
            return;
        }

        if (! $subject->isRosterEligible()) {
            throw new HttpResponseException(response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN));
        }

        $this->ensureEmployeeAccessible($actor, $subject);
    }

    /**
     * Limit attendance correction rows to the actor’s org scope plus their own filings.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\AttendanceCorrection>  $query
     */
    public function restrictAttendanceCorrectionsQuery(User $actor, Builder $query): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $ids = $this->getScopedEmployeeIdsForUser($actor) ?? [];

        $query->where(function ($q) use ($actor, $ids) {
            $q->where('user_id', $actor->id);
            if ($ids !== []) {
                $q->orWhereIn('user_id', $ids);
            }
        });
    }

    /**
     * Restrict a companies query to those visible to the actor (org managers only).
     */
    public function restrictCompanyQuery(User $actor, Builder $query): void
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $ids = $this->companyIdsForCompanyHead($actor);
            if ($ids->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $ids);

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $companyIds = Branch::query()
                ->whereIn('id', $branchIds->all())
                ->pluck('company_id')
                ->filter()
                ->unique()
                ->values();
            if ($companyIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $companyIds->all());

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $deptIds = $this->departmentIdsForDepartmentScope($actor);
            if ($deptIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $companyIds = Department::query()
                ->whereIn('id', $deptIds)
                ->with('branch')
                ->get()
                ->map(fn (Department $d) => $d->branch?->company_id)
                ->filter()
                ->unique()
                ->values();
            if ($companyIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $companyIds->all());

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $divisionIds = $this->divisionIdsForDivisionScope($actor);
            $companyIds = $divisionIds->isNotEmpty()
                ? Division::query()->whereIn('id', $divisionIds)->pluck('company_id')->filter()->unique()->values()
                : collect();
            if ($companyIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $companyIds->all());

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $sectionIds = $this->sectionUnitIdsForSectionScope($actor);
            $companyIds = $sectionIds->isNotEmpty()
                ? SectionUnit::query()->whereIn('id', $sectionIds)->pluck('company_id')->filter()->unique()->values()
                : collect();
            if ($companyIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $companyIds->all());
        }
    }

    /**
     * Restrict a branches query to those visible to the actor.
     */
    public function restrictBranchQuery(User $actor, Builder $query): void
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $ids = $this->companyIdsForCompanyHead($actor);
            if ($ids->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('company_id', $ids);

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $branchIds->all());

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $deptIds = $this->departmentIdsForDepartmentScope($actor);
            if ($deptIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $branchIds = Department::query()->whereIn('id', $deptIds)->pluck('branch_id')->unique();
            $query->whereIn('id', $branchIds->all());

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $divisionIds = $this->divisionIdsForDivisionScope($actor);
            $branchIds = $divisionIds->isNotEmpty()
                ? Division::query()->whereIn('id', $divisionIds)->pluck('branch_id')->filter()->unique()->values()
                : collect();
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $branchIds->all());

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $sectionIds = $this->sectionUnitIdsForSectionScope($actor);
            $branchIds = $sectionIds->isNotEmpty()
                ? SectionUnit::query()->whereIn('id', $sectionIds)->pluck('branch_id')->filter()->unique()->values()
                : collect();
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $branchIds->all());
        }
    }

    /**
     * Restrict a departments query to those visible to the actor.
     */
    public function restrictDepartmentQuery(User $actor, Builder $query): void
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $cids = $this->companyIdsForCompanyHead($actor);
            if ($cids->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereHas('branch', fn ($b) => $b->whereIn('company_id', $cids));

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            if ($branchIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('branch_id', $branchIds->all());

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $deptIds = $this->departmentIdsForDepartmentScope($actor);
            if ($deptIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $deptIds->all());

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $divisionIds = $this->divisionIdsForDivisionScope($actor);
            $departmentIds = $divisionIds->isNotEmpty()
                ? Department::query()->whereIn('division_id', $divisionIds)->pluck('id')->filter()->unique()->values()
                : collect();
            if ($departmentIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $departmentIds->all());

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $sectionIds = $this->sectionUnitIdsForSectionScope($actor);
            $departmentIds = $sectionIds->isNotEmpty()
                ? SectionUnit::query()->whereIn('id', $sectionIds)->pluck('department_id')->filter()->unique()->values()
                : collect();
            if ($departmentIds->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('id', $departmentIds->all());
        }
    }

    public function restrictDivisionQuery(User $actor, Builder $query): void
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $companyIds = $this->companyIdsForCompanyHead($actor);
            $query->whereIn('company_id', $companyIds->all());

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            $query->whereIn('branch_id', $branchIds->isEmpty() ? [0] : $branchIds->all());

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $query->whereIn('department_id', $this->departmentIdsForDepartmentScope($actor)->all());

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $query->whereIn('id', $this->divisionIdsForDivisionScope($actor)->all());

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $divisionIds = $this->sectionUnitsForSectionUnitHead($actor)->pluck('division_id')->filter()->unique()->values();
            $query->whereIn('id', $divisionIds->all());
        }
    }

    public function restrictSectionUnitQuery(User $actor, Builder $query): void
    {
        $role = $this->effectiveOrgScopeRole($actor);
        if ($role === null) {
            return;
        }

        if ($role === HrRole::Employee) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($role === HrRole::CompanyHead) {
            $companyIds = $this->companyIdsForCompanyHead($actor);
            $query->whereIn('company_id', $companyIds->all());

            return;
        }

        if ($role === HrRole::BranchHead) {
            $branchIds = $this->branchIdsForBranchScope($actor);
            $query->whereIn('branch_id', $branchIds->isEmpty() ? [0] : $branchIds->all());

            return;
        }

        if ($role === HrRole::DepartmentHead) {
            $query->whereIn('department_id', $this->departmentIdsForDepartmentScope($actor)->all());

            return;
        }

        if ($role === HrRole::DivisionHead) {
            $query->whereIn('division_id', $this->divisionIdsForDivisionScope($actor)->all());

            return;
        }

        if ($role === HrRole::SectionUnitHead) {
            $query->whereIn('id', $this->sectionUnitIdsForSectionScope($actor)->all());
        }
    }
}
