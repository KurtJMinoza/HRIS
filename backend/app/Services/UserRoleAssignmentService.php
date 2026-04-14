<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Maps HR roles to users.role + org head assignments (single managerial role per user).
 */
class UserRoleAssignmentService
{
    public function clearManagementAssignments(User $user): void
    {
        Company::query()->where('company_head_id', $user->id)->update(['company_head_id' => null]);
        Branch::query()->where('branch_manager_id', $user->id)->update(['branch_manager_id' => null]);
        Department::query()->where('department_head_id', $user->id)->update(['department_head_id' => null]);
    }

    public function applyRole(
        User $user,
        HrRole $hrRole,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
    ): void {
        DB::transaction(function () use ($user, $hrRole, $companyId, $branchId, $departmentId) {
            $this->clearManagementAssignments($user);

            if ($hrRole === HrRole::AdminHr) {
                $user->forceFill(['role' => User::ROLE_ADMIN])->save();

                return;
            }

            $user->forceFill(['role' => User::ROLE_EMPLOYEE])->save();

            match ($hrRole) {
                HrRole::Employee => null,
                HrRole::CompanyHead => $this->assignCompanyHead($user, $companyId),
                HrRole::BranchHead => $this->assignBranchHead($user, $branchId),
                HrRole::DepartmentHead => $this->assignDepartmentHead($user, $departmentId),
                default => null,
            };
        });

        $user->refresh();
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
