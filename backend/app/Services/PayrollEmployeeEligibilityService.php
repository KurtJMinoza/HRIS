<?php

namespace App\Services;

use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PayrollEmployeeEligibilityService
{
    public function query(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
    ): Builder {
        $query = User::query()->payrollEmployees()->active();
        if ($actor instanceof User && $dataScopeService instanceof DataScopeService) {
            $dataScopeService->restrictEmployeeQuery($actor, $query);
        }

        if ($companyId !== null && $companyId > 0) {
            $query->where(function (Builder $scope) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                $this->applyPrimaryEligibility($scope, $companyId, $branchId, $departmentId, $periodStart, $periodEnd);
                $scope->orWhereHas('organizationAssignments', function (Builder $assignment) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                    $this->applySharedPayrollEligibility($assignment, $companyId, $branchId, $departmentId, $periodStart, $periodEnd);
                });
            });
        } else {
            if ($branchId !== null && $branchId > 0) {
                $query->where('branch_id', $branchId);
            }
            if ($departmentId !== null && $departmentId > 0) {
                $query->where('department_id', $departmentId);
            }
        }

        return $query;
    }

    public function count(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
    ): int {
        return (int) $this->query($companyId, $branchId, $departmentId, $periodStart, $periodEnd, $actor, $dataScopeService)
            ->distinct('users.id')
            ->count('users.id');
    }

    /**
     * @return array<string, mixed>
     */
    public function contextForEmployee(User $employee, ?int $companyId, ?int $branchId = null, ?int $departmentId = null, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null): array
    {
        $primaryAssignment = $companyId !== null && $companyId > 0
            ? $this->primaryPayrollAssignmentForEmployee($employee, $companyId, $branchId, $departmentId, $periodStart, $periodEnd)
            : null;

        $hasActivePrimaryAssignment = $companyId !== null && $companyId > 0
            ? $employee->organizationAssignments()
                ->where(function (Builder $query) use ($periodStart, $periodEnd): void {
                    $this->applyAssignmentDateScope($query, $periodStart, $periodEnd);
                    $query
                        ->where('is_active', true)
                        ->where('is_primary', true)
                        ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_PRIMARY);
                })
                ->exists()
            : false;

        $canUseLegacyProfilePrimary = $companyId === null
            || $companyId <= 0
            || (! $hasActivePrimaryAssignment && (int) ($employee->company_id ?? 0) === $companyId);

        if ($primaryAssignment instanceof EmployeeOrganizationAssignment || $canUseLegacyProfilePrimary) {
            return [
                'company_id' => $companyId ?? ($employee->getEffectiveCompanyId() ?: null),
                'branch_id' => $branchId ?? ($primaryAssignment?->branch_id ? (int) $primaryAssignment->branch_id : ($employee->branch_id ? (int) $employee->branch_id : null)),
                'division_id' => $primaryAssignment?->division_id ? (int) $primaryAssignment->division_id : ($employee->division_id ? (int) $employee->division_id : null),
                'department_id' => $departmentId ?? ($primaryAssignment?->department_id ? (int) $primaryAssignment->department_id : ($employee->department_id ? (int) $employee->department_id : null)),
                'section_unit_id' => $primaryAssignment?->section_unit_id ? (int) $primaryAssignment->section_unit_id : ($employee->section_unit_id ? (int) $employee->section_unit_id : null),
                'assignment_id' => $primaryAssignment?->id ? (int) $primaryAssignment->id : null,
                'assignment_type' => EmployeeOrganizationAssignment::TYPE_PRIMARY,
                'is_primary' => true,
                'include_in_payroll' => true,
                'assignment_source' => $primaryAssignment instanceof EmployeeOrganizationAssignment ? 'primary_assignment' : 'legacy_profile_primary',
                'cross_company_payroll_assignment' => false,
            ];
        }

        $assignment = $employee->organizationAssignments()
            ->where(function (Builder $query) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                $this->applySharedPayrollEligibility($query, $companyId, $branchId, $departmentId, $periodStart, $periodEnd);
            })
            ->orderByDesc('id')
            ->first();

        return [
            'company_id' => $companyId,
            'branch_id' => $branchId ?? ($assignment?->branch_id ? (int) $assignment->branch_id : null),
            'division_id' => $assignment?->division_id ? (int) $assignment->division_id : null,
            'department_id' => $departmentId ?? ($assignment?->department_id ? (int) $assignment->department_id : null),
            'section_unit_id' => $assignment?->section_unit_id ? (int) $assignment->section_unit_id : null,
            'assignment_id' => $assignment?->id ? (int) $assignment->id : null,
            'assignment_type' => $assignment?->assignment_type ?: EmployeeOrganizationAssignment::TYPE_SHARED,
            'is_primary' => false,
            'include_in_payroll' => (bool) ($assignment?->include_in_payroll ?? false),
            'assignment_source' => 'shared_payroll_eligible',
            'cross_company_payroll_assignment' => true,
        ];
    }

    public function logScope(
        string $event,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
        Builder $eligibleQuery,
    ): void {
        $ids = (clone $eligibleQuery)->pluck('users.id')->map(fn ($id): int => (int) $id)->values()->all();
        $primaryIds = (clone $eligibleQuery)
            ->where('users.company_id', $companyId)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $sharedIds = Schema::hasColumn('employee_organization_assignments', 'include_in_payroll')
            ? EmployeeOrganizationAssignment::query()
                ->where('company_id', $companyId)
                ->where('include_in_payroll', true)
                ->whereIn('employee_id', $ids)
                ->pluck('employee_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        $excludedSharedIds = Schema::hasColumn('employee_organization_assignments', 'include_in_payroll')
            ? EmployeeOrganizationAssignment::query()
                ->where('company_id', $companyId)
                ->where('include_in_payroll', false)
                ->pluck('employee_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        $includedContexts = [];
        if ($ids !== [] && $companyId !== null && $companyId > 0) {
            $usersById = User::query()
                ->whereIn('id', $ids)
                ->get(['id', 'company_id'])
                ->keyBy('id');
            $hasIncludeInPayrollColumn = Schema::hasColumn('employee_organization_assignments', 'include_in_payroll');
            $assignmentColumns = ['id', 'employee_id', 'company_id', 'assignment_type', 'is_primary'];
            if ($hasIncludeInPayrollColumn) {
                $assignmentColumns[] = 'include_in_payroll';
            }
            $assignmentsByEmployee = EmployeeOrganizationAssignment::query()
                ->whereIn('employee_id', $ids)
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where(function (Builder $assignment) use ($hasIncludeInPayrollColumn): void {
                    $assignment->where('is_primary', true);
                    if ($hasIncludeInPayrollColumn) {
                        $assignment->orWhere('include_in_payroll', true);
                    }
                })
                ->get($assignmentColumns)
                ->groupBy('employee_id');

            foreach ($ids as $employeeId) {
                $assignment = ($assignmentsByEmployee->get($employeeId) ?? collect())
                    ->sortByDesc(fn (EmployeeOrganizationAssignment $row): array => [
                        (bool) $row->is_primary ? 1 : 0,
                        (bool) $row->include_in_payroll ? 1 : 0,
                        (int) $row->id,
                    ])
                    ->first();
                $isPrimaryAssignment = $assignment instanceof EmployeeOrganizationAssignment && (bool) $assignment->is_primary;
                $isSharedPayrollEligible = $assignment instanceof EmployeeOrganizationAssignment
                    && ! $isPrimaryAssignment
                    && (bool) $assignment->include_in_payroll;
                $includedContexts[] = [
                    'employee_id' => $employeeId,
                    'employee_primary_company_id' => (int) ($usersById->get($employeeId)?->company_id ?? 0) ?: null,
                    'payroll_employee_company_id' => $companyId,
                    'assignment_id' => $assignment?->id ? (int) $assignment->id : null,
                    'assignment_type' => $assignment?->assignment_type,
                    'include_in_payroll' => $assignment instanceof EmployeeOrganizationAssignment ? (bool) $assignment->include_in_payroll : null,
                    'inclusion_reason' => $isPrimaryAssignment
                        ? 'active_primary_assignment_company_match'
                        : ($isSharedPayrollEligible ? 'payroll_eligible_shared_assignment' : 'legacy_profile_primary_without_active_primary_assignment'),
                ];
            }
        }

        Log::info($event, [
            'selected_company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'period_start' => $periodStart?->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'eligible_primary_employee_ids' => $primaryIds,
            'eligible_shared_payroll_employee_ids' => $sharedIds,
            'excluded_shared_employee_ids_due_to_include_in_payroll_false' => $excludedSharedIds,
            'included_employee_context' => $includedContexts,
            'final_employee_count' => count($ids),
        ]);
    }

    private function applyPrimaryEligibility(Builder $scope, int $companyId, ?int $branchId, ?int $departmentId, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): void
    {
        $scope->where(function (Builder $primary) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
            $primary->whereHas('organizationAssignments', function (Builder $assignment) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                $this->applyAssignmentDateScope($assignment, $periodStart, $periodEnd);
                $assignment
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_PRIMARY);
                if ($branchId !== null && $branchId > 0) {
                    $assignment->where('branch_id', $branchId);
                }
                if ($departmentId !== null && $departmentId > 0) {
                    $assignment->where('department_id', $departmentId);
                }
            })->orWhere(function (Builder $legacyProfile) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                $legacyProfile
                    ->where('company_id', $companyId)
                    ->whereDoesntHave('organizationAssignments', function (Builder $assignment) use ($periodStart, $periodEnd): void {
                        $this->applyAssignmentDateScope($assignment, $periodStart, $periodEnd);
                        $assignment
                            ->where('is_active', true)
                            ->where('is_primary', true)
                            ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_PRIMARY);
                    });
                if ($branchId !== null && $branchId > 0) {
                    $legacyProfile->where('branch_id', $branchId);
                }
                if ($departmentId !== null && $departmentId > 0) {
                    $legacyProfile->where('department_id', $departmentId);
                }
            });
        });
    }

    private function primaryPayrollAssignmentForEmployee(User $employee, int $companyId, ?int $branchId, ?int $departmentId, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): ?EmployeeOrganizationAssignment
    {
        return $employee->organizationAssignments()
            ->where(function (Builder $query) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                $this->applyAssignmentDateScope($query, $periodStart, $periodEnd);
                $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_PRIMARY);
                if ($branchId !== null && $branchId > 0) {
                    $query->where('branch_id', $branchId);
                }
                if ($departmentId !== null && $departmentId > 0) {
                    $query->where('department_id', $departmentId);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function applySharedPayrollEligibility(Builder $assignment, int $companyId, ?int $branchId, ?int $departmentId, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): void
    {
        $this->applyAssignmentDateScope($assignment, $periodStart, $periodEnd);
        $assignment
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('assignment_type', [
                EmployeeOrganizationAssignment::TYPE_SHARED,
                EmployeeOrganizationAssignment::TYPE_TEMPORARY,
                EmployeeOrganizationAssignment::TYPE_ACTING,
            ]);
        if (Schema::hasColumn('employee_organization_assignments', 'include_in_payroll')) {
            $assignment->where('include_in_payroll', true);
        } else {
            $assignment->whereRaw('1 = 0');
        }
        if ($branchId !== null && $branchId > 0) {
            $assignment->where('branch_id', $branchId);
        }
        if ($departmentId !== null && $departmentId > 0) {
            $assignment->where('department_id', $departmentId);
        }
    }

    private function applyAssignmentDateScope(Builder $assignment, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): void
    {
        $start = $periodStart?->toDateString() ?? now()->toDateString();
        $end = $periodEnd?->toDateString() ?? $start;
        $assignment
            ->where(function (Builder $query) use ($end): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $end);
            })
            ->where(function (Builder $query) use ($start): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start);
            });
    }
}
