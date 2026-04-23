<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maps HR roles to users.role (Laravel admin vs employee) plus org head assignments.
 * Admin (HR) can be combined with company / branch / department head: set {@see $isLaravelAdmin}
 * and pass the organizational {@see HrRole} (not {@see HrRole::AdminHr}).
 */
class UserRoleAssignmentService
{
    /**
     * Existing org-head assignments before mutation.
     *
     * @return array{company_id:?int,branch_id:?int,department_id:?int}
     */
    private function currentHeadAssignmentIds(User $user): array
    {
        $companyId = Company::query()
            ->where('company_head_id', $user->id)
            ->value('id');
        $branchId = Branch::query()
            ->where('branch_manager_id', $user->id)
            ->value('id');
        $departmentId = Department::query()
            ->where('department_head_id', $user->id)
            ->value('id');

        return [
            'company_id' => $companyId !== null ? (int) $companyId : null,
            'branch_id' => $branchId !== null ? (int) $branchId : null,
            'department_id' => $departmentId !== null ? (int) $departmentId : null,
        ];
    }

    /**
     * Preserve existing head assignment context when caller only toggles Admin (HR).
     *
     * @param  array{company_id:?int,branch_id:?int,department_id:?int}  $current
     * @return array{company_id:?int,branch_id:?int,department_id:?int}
     */
    private function normalizeRoleContext(HrRole $hrRole, ?int $companyId, ?int $branchId, ?int $departmentId, array $current): array
    {
        return match ($hrRole) {
            HrRole::CompanyHead => [
                'company_id' => $companyId ?? $current['company_id'],
                'branch_id' => null,
                'department_id' => null,
            ],
            HrRole::BranchHead => [
                'company_id' => null,
                'branch_id' => $branchId ?? $current['branch_id'],
                'department_id' => null,
            ],
            HrRole::DepartmentHead => [
                'company_id' => null,
                'branch_id' => null,
                'department_id' => $departmentId ?? $current['department_id'],
            ],
            default => [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
            ],
        };
    }

    public function clearManagementAssignments(User $user): void
    {
        Company::query()->where('company_head_id', $user->id)->update(['company_head_id' => null]);
        Branch::query()->where('branch_manager_id', $user->id)->update(['branch_manager_id' => null]);
        Department::query()->where('department_head_id', $user->id)->update(['department_head_id' => null]);
    }

    /**
     * @param  bool  $isLaravelAdmin  Grants Admin (HR): full HR API + RBAC admin paths ({@see User::ROLE_ADMIN}).
     */
    public function applyRole(
        User $user,
        HrRole $hrRole,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        bool $isLaravelAdmin = false,
    ): void {
        $current = $this->currentHeadAssignmentIds($user);
        $context = $this->normalizeRoleContext($hrRole, $companyId, $branchId, $departmentId, $current);

        DB::transaction(function () use ($user, $hrRole, $context, $isLaravelAdmin) {
            $this->clearManagementAssignments($user);

            $laravelAdmin = $isLaravelAdmin || $hrRole === HrRole::AdminHr;
            $user->forceFill([
                'role' => $laravelAdmin ? User::ROLE_ADMIN : User::ROLE_EMPLOYEE,
            ])->save();

            if ($hrRole === HrRole::AdminHr) {
                $this->applyAdminHrOrganizationScope($user, $context['company_id'], $context['branch_id'], $context['department_id']);

                return;
            }

            match ($hrRole) {
                HrRole::Employee => $this->syncPlainEmployeeOrgFks($user, $laravelAdmin, $context),
                HrRole::CompanyHead => $this->assignCompanyHead($user, $context['company_id']),
                HrRole::BranchHead => $this->assignBranchHead($user, $context['branch_id']),
                HrRole::DepartmentHead => $this->assignDepartmentHead($user, $context['department_id']),
                default => null,
            };
        });

        $user->refresh();
    }

    /**
     * Employee hat: sync users.company_id / branch_id / department_id for roster, payroll, and attendance.
     *
     * Admin (HR) + Employee: do not blanket-clear org FKs. Legacy behavior called
     * {@see applyAdminHrOrganizationScope()} with all nulls, which removed the user's employment
     * placement and excluded them from company/branch/department-scoped employee lists and runs.
     * When the assignment payload includes org ids, apply them; otherwise keep existing placement.
     */
    private function syncPlainEmployeeOrgFks(User $user, bool $laravelAdmin, array $context): void
    {
        $companyId = $context['company_id'] ?? null;
        $branchId = $context['branch_id'] ?? null;
        $departmentId = $context['department_id'] ?? null;
        $hasIncomingOrg = $companyId !== null || $branchId !== null || $departmentId !== null;

        if ($laravelAdmin) {
            if ($hasIncomingOrg) {
                $user->forceFill([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'department_id' => $departmentId,
                ])->save();
            }

            return;
        }

        if ($hasIncomingOrg) {
            $user->forceFill([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
            ])->save();

            return;
        }

        $user->forceFill([
            'company_id' => null,
            'branch_id' => null,
            'department_id' => null,
        ])->save();
    }

    /**
     * Optional org scope for Admin (HR): narrow data visibility to one company, branch, or department.
     * Priority: department (narrowest) → branch → company. All null = global HR (no org filter).
     */
    public function applyAdminHrOrganizationScope(User $user, ?int $companyId, ?int $branchId, ?int $departmentId): void
    {
        if (! $user->isAdmin()) {
            return;
        }

        if ($departmentId !== null) {
            $dept = Department::query()->with('branch')->findOrFail($departmentId);
            $branch = $dept->branch;
            $user->forceFill([
                'company_id' => $branch?->company_id,
                'branch_id' => $dept->branch_id,
                'department_id' => $dept->id,
            ])->save();

            return;
        }

        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            $user->forceFill([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'department_id' => null,
            ])->save();

            return;
        }

        if ($companyId !== null) {
            Company::query()->findOrFail($companyId);
            $user->forceFill([
                'company_id' => $companyId,
                'branch_id' => null,
                'department_id' => null,
            ])->save();

            return;
        }

        $user->forceFill([
            'company_id' => null,
            'branch_id' => null,
            'department_id' => null,
        ])->save();
    }

    private function assignCompanyHead(User $user, ?int $companyId): void
    {
        if ($companyId === null) {
            abort(422, 'company_id is required for COMPANY HEAD.');
        }
        $company = Company::query()->findOrFail($companyId);
        $company->update(['company_head_id' => $user->id]);
        $user->forceFill([
            'company_id' => $company->id,
            'branch_id' => null,
            'department_id' => null,
        ])->save();
    }

    private function assignBranchHead(User $user, ?int $branchId): void
    {
        if ($branchId === null) {
            abort(422, 'branch_id is required for BRANCH HEAD.');
        }
        $branch = Branch::query()->findOrFail($branchId);
        $branch->update(['branch_manager_id' => $user->id]);
        $user->forceFill([
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'department_id' => null,
        ])->save();
    }

    private function assignDepartmentHead(User $user, ?int $departmentId): void
    {
        if ($departmentId === null) {
            abort(422, 'department_id is required for DEPARTMENT HEAD.');
        }
        $dept = Department::query()->with('branch')->findOrFail($departmentId);
        $dept->update(['department_head_id' => $user->id]);
        $branch = $dept->branch;
        $user->forceFill([
            'company_id' => $branch?->company_id,
            'branch_id' => $dept->branch_id,
            'department_id' => $dept->id,
        ])->save();
    }
}
