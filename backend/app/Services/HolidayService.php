<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HolidayService
{
    private const COVERAGE_CACHE_PREFIX = 'holiday_coverage:';
    private const COVERAGE_CACHE_TTL = 3600;

    /** @var array<string, array<string, mixed>|null> */
    private array $resolvedHolidayCache = [];

    public function __construct(
        private readonly HolidayCalendarService $holidayCalendar,
    ) {}

    public function flushRuntimeCaches(): void
    {
        $this->resolvedHolidayCache = [];
    }

    /**
     * Check if a date is a swap holiday for a specific employee.
     * This is the primary method all modules should call.
     */
    public function isSwapHolidayForEmployee(User $user, string $dateKey): ?array
    {
        $holidays = $this->getSwapHolidaysForDate($dateKey);

        foreach ($holidays as $holiday) {
            if ($this->holidayCoversEmployee($holiday, $user)) {
                return [
                    'id' => $holiday['id'],
                    'name' => $holiday['name'],
                    'type' => $holiday['type'],
                    'scope' => $holiday['scope'] ?? 'company',
                    'is_swap' => true,
                    'original_date' => $holiday['original_date'] ?? null,
                    'coverage_type' => $holiday['coverage_type'],
                    'description' => $holiday['description'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Check if a date is any kind of holiday (regular or swap) for an employee.
     * Merges swap holiday logic with HolidayCalendarService.
     */
    public function getEffectiveHolidayForEmployee(User $user, string $dateKey): ?array
    {
        $swapHoliday = $this->isSwapHolidayForEmployee($user, $dateKey);
        if ($swapHoliday) {
            return $swapHoliday;
        }

        return $this->holidayCalendar->holidayForUserDate($user, $dateKey);
    }

    /**
     * Get all swap holidays for a given date.
     */
    public function getSwapHolidaysForDate(string $dateKey): array
    {
        $year = (int) substr($dateKey, 0, 4);
        $cacheKey = self::COVERAGE_CACHE_PREFIX . 'swap_date:' . $dateKey;

        return Cache::remember($cacheKey, self::COVERAGE_CACHE_TTL, function () use ($dateKey) {
            return Holiday::query()
                ->where('is_swap', true)
                ->where('status', 'active')
                ->whereDate('date', $dateKey)
                ->get()
                ->map(fn (Holiday $h) => $this->serializeForCoverage($h))
                ->all();
        });
    }

    /**
     * Determine if a holiday covers a specific employee based on coverage_type and coverage_ids.
     */
    public function holidayCoversEmployee(array $holiday, User $user): bool
    {
        $coverageType = $holiday['coverage_type'] ?? null;
        $coverageIds = $holiday['coverage_ids'] ?? [];

        if (! is_array($coverageIds)) {
            $coverageIds = [];
        }

        if ($coverageType === null || empty($coverageIds)) {
            return $this->fallbackScopeCheck($holiday, $user);
        }

        $coverageIds = array_map('intval', $coverageIds);

        return match ($coverageType) {
            'company' => $this->employeeBelongsToCompanies($user, $coverageIds),
            'branches' => $this->employeeBelongsToBranches($user, $coverageIds),
            'departments' => $this->employeeBelongsToDepartments($user, $coverageIds),
            'employees' => in_array((int) $user->id, $coverageIds, true),
            default => false,
        };
    }

    /**
     * Fallback to the existing scope-based check when coverage_type/coverage_ids are not set.
     */
    private function fallbackScopeCheck(array $holiday, User $user): bool
    {
        $scope = strtolower($holiday['scope'] ?? 'nationwide');
        $companyId = $user->getEffectiveCompanyId();
        $branchId = $user->branch_id ? (int) $user->branch_id : null;
        $departmentId = $user->department_id ? (int) $user->department_id : null;

        return match ($scope) {
            'employee' => isset($holiday['employee_id']) && (int) $holiday['employee_id'] === (int) $user->id,
            'department' => isset($holiday['department_id']) && $departmentId !== null && (int) $holiday['department_id'] === $departmentId,
            'branch' => isset($holiday['branch_id']) && $branchId !== null && (int) $holiday['branch_id'] === $branchId,
            'company' => ! isset($holiday['company_id']) || ($companyId !== null && (int) $holiday['company_id'] === $companyId),
            'nationwide', 'regional' => true,
            default => true,
        };
    }

    private function employeeBelongsToCompanies(User $user, array $companyIds): bool
    {
        $effectiveCompanyId = $user->getEffectiveCompanyId();
        if ($effectiveCompanyId === null) {
            return false;
        }

        return in_array((int) $effectiveCompanyId, $companyIds, true);
    }

    private function employeeBelongsToBranches(User $user, array $branchIds): bool
    {
        if ($user->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)) {
            return true;
        }

        if ($user->department_id !== null) {
            $dept = Department::query()->where('id', $user->department_id)->first(['branch_id']);
            if ($dept && in_array((int) $dept->branch_id, $branchIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function employeeBelongsToDepartments(User $user, array $departmentIds): bool
    {
        if ($user->department_id !== null) {
            return in_array((int) $user->department_id, $departmentIds, true);
        }

        return false;
    }

    /**
     * Get all employees covered by a holiday's coverage configuration.
     */
    public function getEmployeesCoveredByHoliday(Holiday $holiday): Collection
    {
        $coverageType = $holiday->coverage_type;
        $coverageIds = $holiday->getCoverageIds();

        if (empty($coverageIds) || $coverageType === null) {
            return collect();
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);

        return match ($coverageType) {
            'company' => $query->where(function ($q) use ($coverageIds) {
                $q->whereIn('company_id', $coverageIds)
                    ->orWhereHas('companyHeadships', fn ($sub) => $sub->whereIn('id', $coverageIds))
                    ->orWhereHas('branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds))
                    ->orWhereHas('departmentRelation.branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds));
            })->get(),
            'branches' => $query->where(function ($q) use ($coverageIds) {
                $q->whereIn('branch_id', $coverageIds)
                    ->orWhereHas('departmentRelation', fn ($sub) => $sub->whereIn('branch_id', $coverageIds));
            })->get(),
            'departments' => $query->whereIn('department_id', $coverageIds)->get(),
            'employees' => $query->whereIn('id', $coverageIds)->get(),
            default => collect(),
        };
    }

    /**
     * Count employees affected by a holiday's coverage.
     */
    public function countAffectedEmployees(Holiday $holiday): int
    {
        $coverageType = $holiday->coverage_type;
        $coverageIds = $holiday->getCoverageIds();

        if (empty($coverageIds) || $coverageType === null) {
            return 0;
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);

        return match ($coverageType) {
            'company' => (clone $query)->where(function ($q) use ($coverageIds) {
                $q->whereIn('company_id', $coverageIds)
                    ->orWhereHas('companyHeadships', fn ($sub) => $sub->whereIn('id', $coverageIds))
                    ->orWhereHas('branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds))
                    ->orWhereHas('departmentRelation.branch', fn ($sub) => $sub->whereIn('company_id', $coverageIds));
            })->count(),
            'branches' => (clone $query)->where(function ($q) use ($coverageIds) {
                $q->whereIn('branch_id', $coverageIds)
                    ->orWhereHas('departmentRelation', fn ($sub) => $sub->whereIn('branch_id', $coverageIds));
            })->count(),
            'departments' => (clone $query)->whereIn('department_id', $coverageIds)->count(),
            'employees' => (clone $query)->whereIn('id', $coverageIds)->count(),
            default => 0,
        };
    }

    /**
     * Flush all coverage-related caches.
     */
    public function flushCoverageCache(): void
    {
        $pattern = self::COVERAGE_CACHE_PREFIX . '*';
        Cache::flush();
        $this->holidayCalendar->flushMergedYearCaches();
    }

    /**
     * Flush cache for a specific date only.
     */
    public function flushCoverageForDate(string $dateKey): void
    {
        Cache::forget(self::COVERAGE_CACHE_PREFIX . 'swap_date:' . $dateKey);
    }

    /**
     * Batch check: is date a holiday for employee? (optimized for payroll loops)
     * Returns the holiday info or null.
     */
    public function resolveHolidayForPayroll(
        User $user,
        string $dateKey,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null
    ): ?array {
        $cacheKey = (int) $user->id.'|'.$dateKey;
        if (array_key_exists($cacheKey, $this->resolvedHolidayCache)) {
            return $this->resolvedHolidayCache[$cacheKey];
        }

        $swap = $this->isSwapHolidayForEmployee($user, $dateKey);
        if ($swap) {
            return $this->resolvedHolidayCache[$cacheKey] = $swap;
        }

        return $this->resolvedHolidayCache[$cacheKey] = $this->holidayCalendar->holidayForDate(
            $dateKey,
            $companyId ?? ($user->getEffectiveCompanyId() !== null ? (int) $user->getEffectiveCompanyId() : null),
            $branchId ?? ($user->branch_id !== null ? (int) $user->branch_id : null),
            $departmentId ?? ($user->department_id !== null ? (int) $user->department_id : null),
            (int) $user->id
        );
    }

    /**
     * Pre-load swap holidays for a date range (avoids N+1 in payroll batch processing).
     *
     * @return array<string, list<array>> Indexed by date key
     */
    public function preloadSwapHolidaysForRange(string $fromDate, string $toDate): array
    {
        $holidays = Holiday::query()
            ->where('is_swap', true)
            ->where('status', 'active')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $byDate = [];
        foreach ($holidays as $holiday) {
            $dateKey = $holiday->date instanceof Carbon
                ? $holiday->date->format('Y-m-d')
                : (string) $holiday->date;
            $byDate[$dateKey][] = $this->serializeForCoverage($holiday);
        }

        return $byDate;
    }

    /**
     * Check if employee is covered by any swap holiday in a pre-loaded set.
     */
    public function checkPreloadedSwapHoliday(array $swapHolidaysForDate, User $user): ?array
    {
        foreach ($swapHolidaysForDate as $holiday) {
            if ($this->holidayCoversEmployee($holiday, $user)) {
                return [
                    'id' => $holiday['id'],
                    'name' => $holiday['name'],
                    'type' => $holiday['type'],
                    'scope' => $holiday['scope'] ?? 'company',
                    'is_swap' => true,
                    'original_date' => $holiday['original_date'] ?? null,
                    'coverage_type' => $holiday['coverage_type'],
                    'description' => $holiday['description'] ?? null,
                ];
            }
        }

        return null;
    }

    private function serializeForCoverage(Holiday $h): array
    {
        return [
            'id' => $h->id,
            'name' => $h->name,
            'type' => $h->type,
            'scope' => $h->scope,
            'date' => $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date,
            'company_id' => $h->company_id,
            'branch_id' => $h->branch_id,
            'department_id' => $h->department_id,
            'employee_id' => $h->employee_id,
            'coverage_type' => $h->coverage_type,
            'coverage_ids' => $h->getCoverageIds(),
            'is_swap' => true,
            'original_date' => $h->original_date instanceof Carbon
                ? $h->original_date->format('Y-m-d')
                : ($h->original_date ? (string) $h->original_date : null),
            'description' => $h->description,
            'status' => $h->status ?? 'active',
        ];
    }
}
