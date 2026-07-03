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
     * All active holidays in a pay period, sorted by date ASC.
     * Resolves each calendar date independently — never first()/limit(1).
     *
     * @return list<array<string, mixed>>
     */
    public function holidaysForPayrollPeriod(User $user, string $fromDate, string $toDate): array
    {
        $from = Carbon::parse($fromDate)->startOfDay();
        $to = Carbon::parse($toDate)->startOfDay();
        if ($to->lessThan($from)) {
            return [];
        }

        $policyResolver = app(PolicyResolverService::class);
        $holidayPayPolicy = app(HolidayPayPolicyService::class);
        $holidays = [];
        $seen = [];

        $dbRows = Holiday::query()
            ->where('status', 'active')
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($dbRows as $model) {
            $row = $this->serializeForCoverage($model);
            $dateKey = (string) ($row['date'] ?? '');
            if ($dateKey === '') {
                continue;
            }

            $identity = ((string) ($row['id'] ?? '')).'|'.$dateKey;
            if (isset($seen[$identity])) {
                continue;
            }

            $scopeMatch = $this->holidayCoversEmployee($row, $user);
            $policy = $policyResolver->getActivePolicy(
                $user->getEffectiveCompanyId(),
                $user->branch_id,
                $dateKey
            );
            $resolved = $holidayPayPolicy->resolveEffectivePolicy($policy, $row, $user->getEffectiveCompanyId());
            $normalizedType = $holidayPayPolicy->normalizeHolidayType($row['type'] ?? null);
            $kind = in_array($normalizedType, ['regular', 'double'], true) ? 'regular' : 'special';
            $gate = $holidayPayPolicy->eligibleForHolidayPayEvaluation($user, $row, $resolved, $kind, false);

            if (! $gate['eligible_for_holiday_evaluation']) {
                continue;
            }

            $seen[$identity] = true;
            $holidays[] = array_merge($row, [
                'date' => $dateKey,
                'calendar_scope_match' => $scopeMatch,
            ]);
        }

        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $dateKey = $cursor->toDateString();
            $swap = $this->isSwapHolidayForEmployee($user, $dateKey);
            if ($swap !== null) {
                $identity = ((string) ($swap['id'] ?? '')).'|'.$dateKey;
                if (! isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $holidays[] = array_merge($swap, [
                        'date' => $dateKey,
                        'calendar_scope_match' => true,
                    ]);
                }
            }
            $cursor->addDay();
        }

        usort($holidays, fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return $holidays;
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
        $keys = [
            'holiday_scope:*',
            'holiday_pay_policy:*',
            'payroll_preview:*',
            'payroll_run:*',
            'employee_dashboard:calendar:*',
            'attendance_summary:*',
            'admin_dashboard:holidays:*',
        ];
        foreach ($keys as $pattern) {
            Cache::forget($pattern);
        }
        Cache::flush();
        $this->holidayCalendar->flushMergedYearCaches();
    }

    /**
     * Flush cache for a specific date only.
     */
    public function flushCoverageForDate(string $dateKey): void
    {
        Cache::forget(self::COVERAGE_CACHE_PREFIX.'swap_date:'.$dateKey);
        Cache::forget(self::COVERAGE_CACHE_PREFIX.'moved_from:'.$dateKey);
        Cache::forget('holiday_scope:date:'.$dateKey);
        Cache::forget('payroll_preview:date:'.$dateKey);
        Cache::forget('employee_dashboard:calendar:'.$dateKey);
        Cache::forget('admin_dashboard:holidays:'.$dateKey);
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

        if ($this->isMovedHolidayOriginalDateForEmployee($user, $dateKey)) {
            return $this->resolvedHolidayCache[$cacheKey] = null;
        }

        $swap = $this->isSwapHolidayForEmployee($user, $dateKey);
        if ($swap) {
            return $this->resolvedHolidayCache[$cacheKey] = $swap;
        }

        $holiday = $this->holidayCalendar->holidayForUserDate($user, $dateKey);
        if ($holiday !== null && $this->isRelocatedHolidaySourceDate($user, $dateKey, $holiday)) {
            return $this->resolvedHolidayCache[$cacheKey] = null;
        }

        return $this->resolvedHolidayCache[$cacheKey] = $holiday;
    }

    /**
     * Payroll earnings resolution: calendar scope first, then any active holiday on the date.
     *
     * @return array{holiday: ?array, calendar_scope_match: bool}
     */
    public function resolveHolidayForPayrollEarnings(User $user, string $dateKey): array
    {
        $calendar = $this->resolveHolidayForPayroll($user, $dateKey);
        if ($calendar !== null) {
            return ['holiday' => $calendar, 'calendar_scope_match' => true];
        }

        foreach ($this->activeHolidayRowsOnDate($dateKey) as $row) {
            if ($this->holidayCoversEmployee($row, $user)) {
                return ['holiday' => $row, 'calendar_scope_match' => true];
            }
        }

        if ($this->isMovedHolidayOriginalDateForEmployee($user, $dateKey)) {
            return ['holiday' => null, 'calendar_scope_match' => false];
        }

        return [
            'holiday' => $this->holidayCalendar->activeHolidayOnDate($dateKey, $user),
            'calendar_scope_match' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeHolidayRowsOnDate(string $dateKey): array
    {
        return Holiday::query()
            ->where('status', 'active')
            ->whereDate('date', $dateKey)
            ->orderBy('id')
            ->get()
            ->map(fn (Holiday $holiday) => $this->serializeForCoverage($holiday))
            ->all();
    }

    /**
     * Suppress the original (or duplicate) calendar date when the same holiday is active elsewhere.
     */
    private function isRelocatedHolidaySourceDate(User $user, string $dateKey, array $calendarHoliday): bool
    {
        $name = trim((string) ($calendarHoliday['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $year = (int) substr($dateKey, 0, 4);

        return Holiday::query()
            ->where('status', 'active')
            ->whereYear('date', $year)
            ->where('name', $name)
            ->whereDate('date', '!=', $dateKey)
            ->get()
            ->contains(fn (Holiday $holiday) => $this->holidayCoversEmployee(
                $this->serializeForCoverage($holiday),
                $user
            ));
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
     * True when $dateKey is the original calendar date of a moved holiday that now applies on another day.
     */
    public function isMovedHolidayOriginalDateForEmployee(User $user, string $dateKey): bool
    {
        foreach ($this->getMovedHolidaysFromOriginalDate($dateKey) as $holiday) {
            if ($this->holidayCoversEmployee($holiday, $user)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function getMovedHolidaysFromOriginalDate(string $originalDateKey): array
    {
        $cacheKey = self::COVERAGE_CACHE_PREFIX.'moved_from:'.$originalDateKey;

        return Cache::remember($cacheKey, self::COVERAGE_CACHE_TTL, function () use ($originalDateKey) {
            return Holiday::query()
                ->where('status', 'active')
                ->whereNotNull('original_date')
                ->whereDate('original_date', $originalDateKey)
                ->whereDate('date', '!=', $originalDateKey)
                ->get()
                ->map(fn (Holiday $h) => $this->serializeForCoverage($h))
                ->all();
        });
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
            'is_swap' => (bool) ($h->is_swap ?? false),
            'original_date' => $h->original_date instanceof Carbon
                ? $h->original_date->format('Y-m-d')
                : ($h->original_date ? (string) $h->original_date : null),
            'description' => $h->description,
            'status' => $h->status ?? 'active',
        ];
    }
}
