<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\SectionUnit;
use App\Models\User;
use App\Support\HrApprovalStages;
use Illuminate\Support\Facades\Log;

/**
 * Centralized two-step approval chain for organization requests.
 *
 * Approver 1 = immediate superior based on requester level; Final = Admin HR (always).
 * Company → Branch → Division → Department → Section/Unit → Employee.
 */
class HrApprovalChainResolver
{
    public function __construct(
        private readonly HrRoleResolver $roleResolver,
    ) {}

    /**
     * @return array<int, HrRole>|null
     */
    public function getApprovalChain(User $employee, bool $employeeSubmitted = true): ?array
    {
        $steps = $this->resolveApprovalChain($employee, null, $employeeSubmitted ? $employee : null);

        return $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
    }

    /**
     * Centralized two-step approval resolver.
     *
     * @return array<int, array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }>
     */
    public function resolveTwoStepApprovalChain(User|int $employee, ?string $requestType = null, ?User $requestor = null): array
    {
        $subject = $employee instanceof User
            ? $employee
            : User::query()->findOrFail($employee);

        return $this->resolveApprovalChain($subject, $requestType, $requestor ?? $subject);
    }

    /**
     * @return array<int, array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }>
     */
    public function resolveApprovalChain(User|int $employee, ?string $moduleType = null, ?User $requestor = null): array
    {
        $subject = $employee instanceof User
            ? $employee
            : User::query()->findOrFail($employee);

        $subject = $subject->loadMissing([
            'company',
            'branch.company',
            'departmentRelation.division.branch.company',
            'departmentRelation.branch.company',
            'division.branch.company',
            'division.company',
            'sectionUnit.department.division.branch.company',
            'sectionUnit.division.branch.company',
            'sectionUnit.department.branch.company',
            'sectionUnit.branch.company',
            'sectionUnit.company',
        ]);

        $subjectRole = $this->roleResolver->resolveForApprovalSubject($subject);
        $firstApproverRole = $this->firstApproverRoleForSubject($subjectRole);
        $requestorId = $requestor ? (int) $requestor->id : (int) $subject->id;
        $subjectId = (int) $subject->id;
        $usedApproverIds = [];
        $steps = [];

        Log::info('approval_chain: resolving two-step chain', [
            'module_type' => $moduleType,
            'request_type' => $moduleType,
            'employee_id' => $subjectId,
            'requestor_id' => $requestorId,
            'subject_role' => $subjectRole->value,
            'first_approver_role' => $firstApproverRole?->value,
            'company_id' => $subject->company_id,
            'branch_id' => $subject->branch_id,
            'division_id' => $subject->division_id,
            'department_id' => $subject->department_id,
            'section_unit_id' => $subject->section_unit_id,
        ]);

        if ($firstApproverRole !== null) {
            $approver = $this->resolveApproverForRole($subject, $firstApproverRole);
            if ($approver) {
                $approverId = (int) $approver->id;
                $skipReason = null;

                if ($approverId === $subjectId || $approverId === $requestorId) {
                    $skipReason = 'self-approval';
                } elseif (! $approver->is_active) {
                    $skipReason = 'inactive';
                }

                if ($skipReason === null) {
                    $usedApproverIds[] = $approverId;
                    $steps[] = $this->formatStep($firstApproverRole, $approver, 1);

                    Log::info('approval_chain: approver 1 added', [
                        'employee_id' => $subjectId,
                        'level' => $firstApproverRole->value,
                        'approver_id' => $approverId,
                        'approver_name' => $approver->display_name,
                    ]);
                } else {
                    Log::info('approval_chain: skipped approver 1', [
                        'employee_id' => $subjectId,
                        'level' => $firstApproverRole->value,
                        'approver_id' => $approverId,
                        'reason' => $skipReason,
                    ]);
                }
            } else {
                Log::info('approval_chain: skipped approver 1 — no head configured', [
                    'employee_id' => $subjectId,
                    'level' => $firstApproverRole->value,
                ]);
            }
        }

        $this->appendAdminHrFinalStep($steps, $subjectId, $usedApproverIds);

        Log::info('approval_chain: final order', [
            'employee_id' => $subjectId,
            'chain' => array_map(
                fn (array $step): array => [
                    'sequence_order' => $step['sequence_order'],
                    'level' => $step['approval_level'],
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                ],
                $steps,
            ),
        ]);

        return $steps;
    }

    /**
     * @return array{
     *   chain: array<int, HrRole>|null,
     *   fallback_to_admin: bool,
     *   fallback_reasons: array<int, string>,
     *   first_level_approver: ?User,
     *   hr_approver: ?User
     * }
     */
    public function resolveRoutingDecision(User $employee, bool $employeeSubmitted = true): array
    {
        $steps = $this->resolveApprovalChain($employee, null, $employeeSubmitted ? $employee : null);
        $chain = $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return [
            'chain' => $chain,
            'fallback_to_admin' => false,
            'fallback_reasons' => $chain === null ? ['no_active_admin_hr'] : [],
            'first_level_approver' => $firstLine['approver'] ?? null,
            'hr_approver' => $this->resolveHrApprover(),
        ];
    }

    public function initialApprovalStage(User $employee, bool $employeeSubmitted = true): string
    {
        $chain = $this->getApprovalChain($employee, $employeeSubmitted);
        if ($chain === null) {
            return HrApprovalStages::REJECTED;
        }

        if (count($chain) === 1 && $chain[0] === HrRole::AdminHr) {
            return HrApprovalStages::PENDING_SECOND;
        }

        return HrApprovalStages::PENDING_FIRST;
    }

    public function isFirstLevelApprover(User $actor, User $subjectEmployee): bool
    {
        $steps = $this->resolveApprovalChain($subjectEmployee, null, $subjectEmployee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine !== null && (int) $firstLine['approver_id'] === (int) $actor->id;
    }

    public function resolveFirstLevelApprover(User $employee): ?User
    {
        $steps = $this->resolveApprovalChain($employee, null, $employee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine['approver'] ?? null;
    }

    public function resolveHrApprover(): ?User
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->active()
            ->orderByDesc('is_super_admin')
            ->orderBy('id')
            ->first();
    }

    private function firstApproverRoleForSubject(HrRole $subjectRole): ?HrRole
    {
        return match ($subjectRole) {
            HrRole::Employee => HrRole::SectionUnitHead,
            HrRole::SectionUnitHead => HrRole::DepartmentHead,
            HrRole::DepartmentHead => HrRole::DivisionHead,
            HrRole::DivisionHead => HrRole::BranchHead,
            HrRole::BranchHead => HrRole::CompanyHead,
            HrRole::CompanyHead, HrRole::AdminHr => null,
        };
    }

    private function resolveApproverForRole(User $employee, HrRole $role): ?User
    {
        return match ($role) {
            HrRole::SectionUnitHead => $this->resolveSectionUnitHeadFor($employee),
            HrRole::DivisionHead => $this->resolveDivisionHeadFor($employee),
            HrRole::DepartmentHead => $this->resolveDepartmentHeadFor($employee),
            HrRole::BranchHead => $this->resolveBranchHeadFor($employee),
            HrRole::CompanyHead => $this->resolveCompanyHeadFor($employee),
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  list<int>  $usedApproverIds
     */
    private function appendAdminHrFinalStep(array &$steps, int $subjectId, array $usedApproverIds): void
    {
        $hrApprover = $this->resolveHrApprover();
        if (! $hrApprover) {
            Log::warning('approval_chain: Admin HR final step not appended — no active Admin HR account', [
                'employee_id' => $subjectId,
            ]);

            return;
        }

        $hrId = (int) $hrApprover->id;
        if (in_array($hrId, $usedApproverIds, true)) {
            Log::info('approval_chain: Admin HR approver already appears in org chain; still appending final HR step', [
                'employee_id' => $subjectId,
                'approver_id' => $hrId,
            ]);
        }

        $steps[] = $this->formatStep(HrRole::AdminHr, $hrApprover, count($steps) + 1);

        Log::info('approval_chain: Admin HR final step appended', [
            'employee_id' => $subjectId,
            'approver_id' => $hrId,
            'approver_name' => $hrApprover->display_name,
            'sequence_order' => count($steps),
        ]);
    }

    /**
     * @return array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }
     */
    private function formatStep(HrRole $role, User $approver, int $sequenceOrder): array
    {
        return [
            'approval_level' => $role->value,
            'approver_role' => $role,
            'approver_id' => (int) $approver->id,
            'approver_name' => $approver->display_name,
            'approver' => $approver,
            'sequence_order' => $sequenceOrder,
        ];
    }

    private function resolveSectionUnitHeadFor(User $employee): ?User
    {
        if ($employee->supervisor_id) {
            $supervisor = User::query()->activeRoster()->find($employee->supervisor_id);
            if ($supervisor && $this->isConfiguredTeamLeaderFor($employee, (int) $supervisor->id)) {
                return $supervisor;
            }
        }

        $section = $this->sectionUnitFor($employee);
        if ($section) {
            $sectionLeader = $this->firstActiveTeamLeader(
                $section->teamLeaders()->orderBy('section_unit_team_leaders.id')->get()
            );
            if ($sectionLeader) {
                return $sectionLeader;
            }
        }

        $department = $this->departmentFor($employee);
        if ($department) {
            $departmentLeader = $this->firstActiveTeamLeader(
                $department->teamLeaders()->orderBy('department_team_leaders.id')->get()
            );
            if ($departmentLeader) {
                return $departmentLeader;
            }
        }

        if ($section?->section_unit_head_id) {
            $legacySectionHead = User::query()->activeRoster()->find($section->section_unit_head_id);
            if ($legacySectionHead) {
                return $legacySectionHead;
            }
        }

        if ($department?->department_head_id) {
            return User::query()->activeRoster()->find($department->department_head_id);
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>|list<User>  $leaders
     */
    private function firstActiveTeamLeader($leaders): ?User
    {
        foreach ($leaders as $leader) {
            if ($leader instanceof User && $leader->is_active) {
                return $leader;
            }
        }

        return null;
    }

    private function isConfiguredTeamLeaderFor(User $employee, int $candidateId): bool
    {
        $section = $this->sectionUnitFor($employee);
        if ($section) {
            if ((int) $section->section_unit_head_id === $candidateId) {
                return true;
            }
            if ($section->teamLeaders()->where('users.id', $candidateId)->exists()) {
                return true;
            }
        }

        $department = $this->departmentFor($employee);
        if ($department) {
            if ((int) $department->department_head_id === $candidateId) {
                return true;
            }
            if ($department->teamLeaders()->where('users.id', $candidateId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function resolveDivisionHeadFor(User $employee): ?User
    {
        $division = $this->divisionFor($employee);
        if (! $division?->division_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($division->division_head_id);
    }

    private function resolveDepartmentHeadFor(User $employee): ?User
    {
        $department = $this->departmentFor($employee);
        if (! $department?->department_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($department->department_head_id);
    }

    private function resolveBranchHeadFor(User $employee): ?User
    {
        $branch = $this->branchFor($employee);
        if (! $branch?->branch_manager_id) {
            return null;
        }

        return User::query()->activeRoster()->find($branch->branch_manager_id);
    }

    private function resolveCompanyHeadFor(User $employee): ?User
    {
        $company = $this->companyFor($employee);
        if (! $company?->company_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($company->company_head_id);
    }

    private function sectionUnitFor(User $employee): ?SectionUnit
    {
        if ($employee->relationLoaded('sectionUnit') && $employee->sectionUnit) {
            return $employee->sectionUnit;
        }

        return $employee->section_unit_id ? SectionUnit::query()->find($employee->section_unit_id) : null;
    }

    private function divisionFor(User $employee): ?Division
    {
        if ($employee->relationLoaded('division') && $employee->division) {
            return $employee->division;
        }

        if ($employee->division_id) {
            return Division::query()->find($employee->division_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->division_id) {
            return Division::query()->find($department->division_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->division_id) {
            return Division::query()->find($section->division_id);
        }

        return null;
    }

    private function departmentFor(User $employee): ?Department
    {
        if ($employee->relationLoaded('departmentRelation') && $employee->departmentRelation) {
            return $employee->departmentRelation;
        }
        if ($employee->department_id) {
            return Department::query()->find($employee->department_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->department_id) {
            return Department::query()->find($section->department_id);
        }

        $deptName = trim((string) ($employee->department ?? ''));

        return $deptName !== '' ? Department::query()->where('name', $deptName)->first() : null;
    }

    private function branchFor(User $employee): ?Branch
    {
        if ($employee->relationLoaded('branch') && $employee->branch) {
            return $employee->branch;
        }
        if ($employee->branch_id) {
            return Branch::query()->find($employee->branch_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->branch_id) {
            return Branch::query()->find($section->branch_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->branch_id) {
            return Branch::query()->find($department->branch_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->branch_id) {
            return Branch::query()->find($division->branch_id);
        }

        return null;
    }

    private function companyFor(User $employee): ?Company
    {
        if ($employee->relationLoaded('company') && $employee->company) {
            return $employee->company;
        }
        if ($employee->company_id) {
            return Company::query()->find($employee->company_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->company_id) {
            return Company::query()->find($section->company_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->company_id) {
            return Company::query()->find($department->company_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->company_id) {
            return Company::query()->find($division->company_id);
        }

        $branch = $this->branchFor($employee);
        if ($branch?->company_id) {
            return Company::query()->find($branch->company_id);
        }

        return null;
    }
}
