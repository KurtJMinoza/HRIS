<?php

namespace App\Services;

use App\Models\EmployeeOrganizationAssignment;
use App\Models\ExecomEmployeeProfile;
use App\Models\PayrollBatchRun;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PayrollEmployeeEligibilityService
{
    public const EXCLUSION_PAYROLL_EFFECTIVE_AFTER_PERIOD = 'Not payroll-effective for this pay cycle';

    public const EXCLUSION_CREATED_AFTER_PERIOD = 'Created after payroll period';

    public const EXCLUSION_ASSIGNMENT_NOT_ACTIVE = 'Assignment not active in period';

    public function query(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
    ): Builder {
        if ($payrollModule === PayrollBatchRun::MODULE_EXECOM) {
            return $this->execomPayrollQuery(
                $companyId,
                $branchId,
                $departmentId,
                $periodStart,
                $periodEnd,
                $actor,
                $dataScopeService
            );
        }

        $query = User::query()->payrollEmployees()->active();
        $this->applyPayrollStartDateScope($query, $periodEnd);
        if ($actor instanceof User && $dataScopeService instanceof DataScopeService) {
            $dataScopeService->restrictEmployeeQuery($actor, $query);
        }

        $this->excludeExecomEmployeesFromRegularQuery($query, $periodStart, $periodEnd);

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
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
    ): int {
        return (int) $this->query($companyId, $branchId, $departmentId, $periodStart, $periodEnd, $actor, $dataScopeService, $payrollModule)
            ->distinct('users.id')
            ->count('users.id');
    }

    /**
     * @return list<int>
     */
    public function getExecomPayrollEligibleEmployeeIds(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
    ): array {
        return $this->execomPayrollQuery($companyId, $branchId, $departmentId, $periodStart, $periodEnd, $actor, $dataScopeService)
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getExecomPayrollEligibleEmployees(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
    ) {
        return $this->execomPayrollQuery($companyId, $branchId, $departmentId, $periodStart, $periodEnd, $actor, $dataScopeService)
            ->orderByLastName()
            ->get();
    }

    /**
     * @return list<int>
     */
    public function getPayrollEligibleEmployeeIds(
        ?int $companyId,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?User $actor = null,
        ?DataScopeService $dataScopeService = null,
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
    ): array {
        return $this->query($companyId, $branchId, $departmentId, $periodStart, $periodEnd, $actor, $dataScopeService, $payrollModule)
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    public function findIneligibleDraftEmployeeIds(
        array $employeeIds,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
    ): array {
        if ($employeeIds === []) {
            return [];
        }

        $eligibleIds = $this->getPayrollEligibleEmployeeIds(
            $companyId,
            $branchId,
            $departmentId,
            $periodStart,
            $periodEnd,
            null,
            null,
            $payrollModule
        );

        return collect($employeeIds)
            ->map(fn ($id): int => (int) $id)
            ->diff($eligibleIds)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateEmployeeEligibility(
        User $employee,
        ?int $companyId,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?int $branchId = null,
        ?int $departmentId = null,
        string $payrollModule = PayrollBatchRun::MODULE_STANDARD,
        ?int $payrollRunId = null,
    ): array {
        $end = Carbon::parse($periodEnd)->startOfDay();
        $payrollStart = $this->payrollStartDate($employee);
        $assignment = $payrollModule === PayrollBatchRun::MODULE_EXECOM
            ? $this->activeExecomProfileForEmployee($employee, $companyId, $branchId, $departmentId, $periodStart, $periodEnd)
            : $this->activePayrollAssignmentForEvaluation($employee, $companyId, $branchId, $departmentId, $periodStart, $periodEnd);

        $reason = null;
        if (! $payrollStart instanceof Carbon || $payrollStart->gt($end)) {
            $createdAt = $employee->created_at ? Carbon::parse($employee->created_at)->startOfDay() : null;
            $payrollEffective = $this->employeePayrollEffectiveDate($employee);
            $reason = $createdAt instanceof Carbon && $createdAt->gt($end)
                ? self::EXCLUSION_CREATED_AFTER_PERIOD
                : self::EXCLUSION_PAYROLL_EFFECTIVE_AFTER_PERIOD;
            if ($payrollEffective instanceof Carbon && $payrollEffective->gt($end)) {
                $reason = self::EXCLUSION_PAYROLL_EFFECTIVE_AFTER_PERIOD;
            }
        } elseif ($companyId !== null && $companyId > 0 && ! $assignment) {
            $reason = self::EXCLUSION_ASSIGNMENT_NOT_ACTIVE;
        }

        $included = $reason === null;
        $payload = [
            'payroll_run_id' => $payrollRunId,
            'payroll_period_start' => $periodStart->toDateString(),
            'payroll_period_end' => $periodEnd->toDateString(),
            'employee_id' => (int) $employee->id,
            'employee_name' => (string) $employee->display_name,
            'hire_date' => $employee->hire_date?->toDateString(),
            'created_at' => $employee->created_at?->toDateString(),
            'payroll_effective_date' => $this->employeePayrollEffectiveDate($employee)?->toDateString(),
            'payroll_start_date' => $payrollStart?->toDateString(),
            'assignment_effective_from' => $assignment?->effective_from?->toDateString(),
            'assignment_effective_to' => $assignment?->effective_to?->toDateString(),
            'included_or_excluded' => $included ? 'included' : 'excluded',
            'exclusion_reason' => $reason,
        ];

        if (! $included) {
            Log::info('Payroll employee excluded by eligibility', $payload);
        }

        return $payload + [
            'included' => $included,
        ];
    }

    public function clampComputationStart(User $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): Carbon
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->startOfDay();
        $payrollStart = $this->payrollStartDate($employee);

        if ($payrollStart instanceof Carbon && $payrollStart->betweenIncluded($start, $end)) {
            return $payrollStart->copy();
        }

        return $start;
    }

    private function execomPayrollQuery(
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
        ?User $actor,
        ?DataScopeService $dataScopeService,
    ): Builder {
        $query = User::query()
            ->payrollEmployees()
            ->active();
        $this->applyPayrollStartDateScope($query, $periodEnd);
        $this->applyActiveExecomProfileScope($query, $companyId, $branchId, $departmentId, $periodStart, $periodEnd, true);

        if ($actor instanceof User && $dataScopeService instanceof DataScopeService) {
            $dataScopeService->restrictEmployeeQuery($actor, $query);
        }

        return $query;
    }

    private function excludeExecomEmployeesFromRegularQuery(Builder $query, ?CarbonInterface $periodStart, ?CarbonInterface $periodEnd): void
    {
        if (Schema::hasColumn('users', 'is_execom')) {
            $query->where(function (Builder $scope): void {
                $scope->where('users.is_execom', false)->orWhereNull('users.is_execom');
            });
        }

        if (Schema::hasTable('execom_employee_profiles')) {
            $this->applyActiveExecomProfileScope($query, null, null, null, $periodStart, $periodEnd, false);
        }
    }

    private function applyActiveExecomProfileScope(
        Builder $query,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
        bool $mustHaveProfile,
    ): void {
        if (! Schema::hasTable('execom_employee_profiles')) {
            if ($mustHaveProfile) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        $start = $periodStart?->toDateString() ?? now()->toDateString();
        $end = $periodEnd?->toDateString() ?? $start;
        $existsMethod = $mustHaveProfile ? 'whereExists' : 'whereNotExists';

        $query->{$existsMethod}(function ($sub) use ($companyId, $branchId, $departmentId, $start, $end): void {
            $sub->selectRaw('1')
                ->from('execom_employee_profiles')
                ->whereColumn('execom_employee_profiles.employee_id', 'users.id')
                ->where('execom_employee_profiles.is_active', true)
                ->where(function ($dateQuery) use ($end): void {
                    $dateQuery->whereNull('execom_employee_profiles.effective_from')
                        ->orWhereDate('execom_employee_profiles.effective_from', '<=', $end);
                })
                ->where(function ($dateQuery) use ($start): void {
                    $dateQuery->whereNull('execom_employee_profiles.effective_to')
                        ->orWhereDate('execom_employee_profiles.effective_to', '>=', $start);
                });

            if ($companyId !== null && $companyId > 0) {
                $sub->where('execom_employee_profiles.company_id', $companyId);
            }
            if ($branchId !== null && $branchId > 0) {
                $sub->where('execom_employee_profiles.branch_id', $branchId);
            }
            if ($departmentId !== null && $departmentId > 0) {
                $sub->where('execom_employee_profiles.department_id', $departmentId);
            }
        });
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
            })->orWhere(function (Builder $legacyProfile) use ($companyId, $branchId, $departmentId): void {
                $legacyProfile
                    ->where('company_id', $companyId)
                    ->whereDoesntHave('organizationAssignments', function (Builder $assignment) use ($companyId): void {
                        $assignment
                            ->where('company_id', $companyId)
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

    private function applyPayrollStartDateScope(Builder $query, ?CarbonInterface $periodEnd): void
    {
        if (! $periodEnd instanceof CarbonInterface) {
            return;
        }

        $end = $periodEnd->toDateString();
        $payrollStartSql = $this->payrollStartDateSql();
        $query
            ->whereDate('users.created_at', '<=', $end)
            ->whereRaw($payrollStartSql.' <= ?', [$end]);
    }

    private function payrollStartDateSql(): string
    {
        $parts = [
            "COALESCE(users.hire_date, '1000-01-01')",
            'DATE(users.created_at)',
        ];
        if (Schema::hasColumn('users', 'payroll_effective_date')) {
            $parts[] = "COALESCE(users.payroll_effective_date, DATE(users.created_at))";
        }

        return 'GREATEST('.implode(', ', $parts).')';
    }

    private function payrollStartDate(User $employee): ?Carbon
    {
        $dates = [];
        if ($employee->hire_date) {
            $dates[] = Carbon::parse($employee->hire_date)->startOfDay();
        }
        if ($employee->created_at) {
            $dates[] = Carbon::parse($employee->created_at)->startOfDay();
        }
        $payrollEffective = $this->employeePayrollEffectiveDate($employee);
        if ($payrollEffective instanceof Carbon) {
            $dates[] = $payrollEffective;
        }

        if ($dates === []) {
            return null;
        }

        return collect($dates)->max();
    }

    private function employeePayrollEffectiveDate(User $employee): ?Carbon
    {
        if (Schema::hasColumn('users', 'payroll_effective_date') && $employee->payroll_effective_date) {
            return Carbon::parse($employee->payroll_effective_date)->startOfDay();
        }
        if ($employee->created_at) {
            return Carbon::parse($employee->created_at)->startOfDay();
        }

        return null;
    }

    private function activePayrollAssignmentForEvaluation(User $employee, ?int $companyId, ?int $branchId, ?int $departmentId, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?object
    {
        if ($companyId === null || $companyId <= 0) {
            return (object) [
                'effective_from' => null,
                'effective_to' => null,
            ];
        }

        $hasPrimaryAssignmentForCompany = $employee->organizationAssignments()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where('is_primary', true)
            ->where('assignment_type', EmployeeOrganizationAssignment::TYPE_PRIMARY)
            ->exists();

        return $this->primaryPayrollAssignmentForEmployee($employee, $companyId, $branchId, $departmentId, $periodStart, $periodEnd)
            ?: $employee->organizationAssignments()
                ->where(function (Builder $query) use ($companyId, $branchId, $departmentId, $periodStart, $periodEnd): void {
                    $this->applySharedPayrollEligibility($query, $companyId, $branchId, $departmentId, $periodStart, $periodEnd);
                })
                ->first()
            ?: (! $hasPrimaryAssignmentForCompany && (int) ($employee->company_id ?? 0) === $companyId ? (object) [
                'effective_from' => null,
                'effective_to' => null,
            ] : null);
    }

    private function activeExecomProfileForEmployee(User $employee, ?int $companyId, ?int $branchId, ?int $departmentId, CarbonInterface $periodStart, CarbonInterface $periodEnd): ?ExecomEmployeeProfile
    {
        if (! Schema::hasTable('execom_employee_profiles')) {
            return null;
        }

        $query = ExecomEmployeeProfile::query()
            ->where('employee_id', (int) $employee->id)
            ->where('is_active', true)
            ->where(function (Builder $date) use ($periodEnd): void {
                $date->whereNull('effective_from')->orWhereDate('effective_from', '<=', $periodEnd->toDateString());
            })
            ->where(function (Builder $date) use ($periodStart): void {
                $date->whereNull('effective_to')->orWhereDate('effective_to', '>=', $periodStart->toDateString());
            });

        if ($companyId !== null && $companyId > 0) {
            $query->where('company_id', $companyId);
        }
        if ($branchId !== null && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }
        if ($departmentId !== null && $departmentId > 0) {
            $query->where('department_id', $departmentId);
        }

        return $query->first();
    }
}
