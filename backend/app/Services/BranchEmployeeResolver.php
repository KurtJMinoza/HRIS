<?php

namespace App\Services;

use App\Models\EmployeeOrganizationAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class BranchEmployeeResolver
{
    public const CACHE_TTL_SECONDS = 600;

    public static function cacheKey(int $branchId): string
    {
        return "geofence:branch_employee_count:{$branchId}";
    }

    public function forgetBranch(int $branchId): void
    {
        if ($branchId > 0) {
            Cache::forget(self::cacheKey($branchId));
        }
    }

    public function forgetForAssignment(EmployeeOrganizationAssignment $assignment): void
    {
        foreach (array_filter([
            $assignment->branch_id,
            $assignment->getOriginal('branch_id'),
        ]) as $branchId) {
            $this->forgetBranch((int) $branchId);
        }
    }

    public function forgetForUser(User $user): void
    {
        foreach (array_filter([
            $user->branch_id,
            $user->getOriginal('branch_id'),
            $user->departmentRelation?->branch_id,
        ]) as $branchId) {
            $this->forgetBranch((int) $branchId);
        }
    }

    public function countEmployeesByBranch(int $branchId, CarbonInterface|string|null $date = null): int
    {
        if ($date !== null && $this->dateKey($date) !== now()->toDateString()) {
            return $this->getEmployeesByBranch($branchId, $date)->count();
        }

        return Cache::remember(
            self::cacheKey($branchId),
            self::CACHE_TTL_SECONDS,
            fn (): int => $this->getEmployeesByBranch($branchId)->count(),
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function getEmployeesByBranch(int $branchId, CarbonInterface|string|null $date = null): Collection
    {
        $dateKey = $this->dateKey($date);

        return User::query()
            ->activeRoster()
            ->with([
                'branch:id,name,company_id',
                'departmentRelation:id,name,branch_id',
                'division:id,name,branch_id,company_id',
                'sectionUnit:id,name,branch_id,department_id,division_id,company_id',
                'organizationAssignments' => fn ($query) => $this->activeAssignmentScope($query, $dateKey)
                    ->where('branch_id', $branchId)
                    ->orderByDesc('is_primary')
                    ->orderByDesc('id'),
            ])
            ->where(fn (Builder $query) => $this->branchMembershipScope($query, $branchId, $dateKey))
            ->orderByLastName()
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function previewEmployeesByBranch(int $branchId, int $limit = 3): array
    {
        return $this->getEmployeesByBranch($branchId)
            ->take($limit)
            ->map(fn (User $employee): array => $this->employeePayload($employee, $branchId))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function employeePayload(User $employee, int $branchId): array
    {
        $assignments = $employee->organizationAssignments ?? collect();
        $assignment = $assignments->first(fn (EmployeeOrganizationAssignment $row): bool => (int) $row->branch_id === $branchId);

        $assignmentType = $assignment?->assignment_type
            ?? ($assignment?->is_primary ? EmployeeOrganizationAssignment::TYPE_PRIMARY : null);
        $assignmentType ??= (int) ($employee->branch_id ?? 0) === $branchId ? 'direct_branch' : null;
        $assignmentType ??= (int) ($employee->departmentRelation?->branch_id ?? 0) === $branchId ? 'department_branch' : 'branch_assignment';

        return [
            'id' => (int) $employee->id,
            'name' => $employee->display_name,
            'employee_number' => $employee->employee_code ?: sprintf('EMP-%05d', (int) $employee->id),
            'department' => $employee->departmentRelation?->name,
            'section_unit' => $employee->sectionUnit?->name,
            'division' => $employee->division?->name,
            'assignment_type' => $assignmentType,
            'active' => $employee->isOperationallyActive(),
        ];
    }

    private function branchMembershipScope(Builder $query, int $branchId, string $dateKey): void
    {
        if (Schema::hasTable('employee_organization_assignments')) {
            $query->whereHas('organizationAssignments', fn (Builder $q) => $this->activeAssignmentScope($q, $dateKey)
                ->where('branch_id', $branchId));
        }

        $query->orWhere('branch_id', $branchId)
            ->orWhereHas('departmentRelation', fn (Builder $q) => $q->where('branch_id', $branchId));
    }

    private function activeAssignmentScope($query, string $dateKey)
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($dateKey): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $dateKey);
            })
            ->where(function (Builder $q) use ($dateKey): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateKey);
            });
    }

    private function dateKey(CarbonInterface|string|null $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->toDateString();
        }

        return $date ? (string) $date : now()->toDateString();
    }
}
