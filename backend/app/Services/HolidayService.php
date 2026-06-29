<?php

namespace App\Services;

use App\Models\EmployeeOrganizationAssignment;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HolidayService
{
    private const COVERAGE_CACHE_PREFIX = 'holiday_coverage:';

    private const COVERAGE_CACHE_TTL = 3600;

    /** @var array<string, array<string, mixed>|null> */
    private array $resolvedHolidayCache = [];

    public function __construct(
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly HolidayScopeResolver $scopeResolver,
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
                    'scope_type' => $holiday['scope'] ?? 'company',
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
        $cacheKey = self::COVERAGE_CACHE_PREFIX.'swap_date:'.$dateKey;

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
        $date = isset($holiday['date']) && $holiday['date'] !== ''
            ? Carbon::parse((string) $holiday['date'])
            : now();

        return $this->scopeResolver->appliesRowToEmployee($holiday, $user, $date);
    }

    /**
     * Get all employees covered by a holiday's coverage configuration.
     */
    public function getEmployeesCoveredByHoliday(Holiday $holiday): Collection
    {
        $date = $holiday->date instanceof Carbon ? $holiday->date : Carbon::parse((string) $holiday->date);

        return User::query()
            ->where('is_active', true)
            ->visibleEmployees()
            ->get()
            ->filter(fn (User $employee): bool => $this->scopeResolver->appliesToEmployee($holiday, $employee, $date))
            ->values();
    }

    /**
     * Count employees affected by a holiday's coverage.
     */
    public function countAffectedEmployees(Holiday $holiday): int
    {
        return $this->getEmployeesCoveredByHoliday($holiday)->count();
    }

    /**
     * Flush all coverage-related caches.
     */
    public function flushCoverageCache(): void
    {
        Cache::flush();
        $this->holidayCalendar->flushMergedYearCaches();
    }

    /**
     * Flush cache for a specific date only.
     */
    public function flushCoverageForDate(string $dateKey): void
    {
        Cache::forget(self::COVERAGE_CACHE_PREFIX.'swap_date:'.$dateKey);
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
        ?int $departmentId = null,
        ?int $divisionId = null,
        ?int $sectionUnitId = null
    ): ?array {
        $companyId = $companyId ?? ($user->getEffectiveCompanyId() !== null ? (int) $user->getEffectiveCompanyId() : null);
        $branchId = $branchId ?? ($user->branch_id !== null ? (int) $user->branch_id : null);
        $divisionId = $divisionId ?? ($user->division_id !== null ? (int) $user->division_id : null);
        $departmentId = $departmentId ?? ($user->department_id !== null ? (int) $user->department_id : null);
        $sectionUnitId = $sectionUnitId ?? ($user->section_unit_id !== null ? (int) $user->section_unit_id : null);
        $sectionUnitId = $this->activeSectionContextForEmployee($user, $dateKey, $sectionUnitId);

        $cacheKey = implode('|', [
            (int) $user->id,
            $dateKey,
            (int) ($companyId ?? 0),
            (int) ($branchId ?? 0),
            (int) ($divisionId ?? 0),
            (int) ($departmentId ?? 0),
            (int) ($sectionUnitId ?? 0),
        ]);
        if (array_key_exists($cacheKey, $this->resolvedHolidayCache)) {
            return $this->resolvedHolidayCache[$cacheKey];
        }

        $swap = $this->isSwapHolidayForEmployee($user, $dateKey);
        if ($swap) {
            return $this->resolvedHolidayCache[$cacheKey] = $swap;
        }

        return $this->resolvedHolidayCache[$cacheKey] = $this->holidayCalendar->holidayForUserDate($user, $dateKey);
    }

    private function activeSectionContextForEmployee(User $user, string $dateKey, ?int $sectionUnitId): ?int
    {
        if ($sectionUnitId === null) {
            return null;
        }
        if ($user->section_unit_id !== null && (int) $user->section_unit_id === $sectionUnitId) {
            return $sectionUnitId;
        }

        $activeShared = EmployeeOrganizationAssignment::query()
            ->where('employee_id', (int) $user->id)
            ->where('section_unit_id', $sectionUnitId)
            ->where('is_active', true)
            ->where(function ($q) use ($dateKey): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $dateKey);
            })
            ->where(function ($q) use ($dateKey): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $dateKey);
            })
            ->whereIn('assignment_type', [
                EmployeeOrganizationAssignment::TYPE_SHARED,
                EmployeeOrganizationAssignment::TYPE_TEMPORARY,
                EmployeeOrganizationAssignment::TYPE_ACTING,
                EmployeeOrganizationAssignment::TYPE_PRIMARY,
            ])
            ->exists();

        return $activeShared ? $sectionUnitId : ($user->section_unit_id !== null ? (int) $user->section_unit_id : null);
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
                    'scope_type' => $holiday['scope'] ?? 'company',
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
            'scope_type' => $h->scope,
            'date' => $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date,
            'company_id' => $h->company_id,
            'branch_id' => $h->branch_id,
            'division_id' => $h->division_id,
            'department_id' => $h->department_id,
            'section_unit_id' => $h->section_unit_id,
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
