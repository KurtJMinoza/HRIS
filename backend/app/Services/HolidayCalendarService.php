<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\User;
use App\Support\TextSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Canonical holiday calendar for payroll and admin using local data only:
 * `holidays` table + seeded fallback map (no external API call at runtime).
 *
 * Recurring rows with `is_recurring = true` apply to every calendar year on the same month/day
 * without inserting duplicate DB rows per year.
 */
class HolidayCalendarService
{
    private const CACHE_KEY_PREFIX = 'holiday_calendar:merged_year:';

    private const CACHE_TTL_SECONDS = 86400;

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $mergedByYear = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $holidaysListByYear = [];

    public function __construct(
        private readonly HolidayScopeResolver $scopeResolver,
    ) {}

    public function flushMergedYearCaches(): void
    {
        $this->mergedByYear = [];
        $this->holidaysListByYear = [];
        foreach (range(2020, 2035) as $year) {
            Cache::forget(self::CACHE_KEY_PREFIX.$year);
        }
    }

    /**
     * Warm {@see holidaysForYear} for every year touched by a pay window (bulk payroll draft).
     */
    public function preloadYearsForDateRange(string $fromDate, string $toDate): void
    {
        $fromYear = (int) substr($fromDate, 0, 4);
        $toYear = (int) substr($toDate, 0, 4);
        if ($fromYear < 2000 || $toYear < 2000) {
            return;
        }
        for ($year = $fromYear; $year <= $toYear; $year++) {
            $this->holidaysForYear($year);
        }
    }

    /**
     * Backward-compatible active holiday map keyed by date. If multiple scoped rows share a
     * date, the most specific scope is selected for display-only callers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mergedHolidaysForYear(int $year): array
    {
        $year = max(2020, min(2035, $year));
        if (isset($this->mergedByYear[$year])) {
            return $this->mergedByYear[$year];
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$year;
        $this->mergedByYear[$year] = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($year) {
            $map = [];
            foreach ($this->holidaysForYear($year) as $row) {
                $date = (string) ($row['date'] ?? '');
                $status = strtolower((string) ($row['status'] ?? 'active'));
                if ($date === '' || $status !== 'active') {
                    continue;
                }
                if (! isset($map[$date]) || $this->scopePrecedence($row) >= $this->scopePrecedence($map[$date])) {
                    $map[$date] = $row;
                }
            }

            return $map;
        });

        return $this->mergedByYear[$year];
    }

    /**
     * Admin-facing yearly list. Keeps multiple scoped rows on the same date so scoped
     * holidays can coexist.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function holidaysForYear(int $year): Collection
    {
        $year = max(2020, min(2035, $year));
        if (isset($this->holidaysListByYear[$year])) {
            return collect($this->holidaysListByYear[$year]);
        }

        $rows = [];
        $explicitKeys = [];

        $holidays = Holiday::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'branch.company:id,name,logo',
                'division:id,name,company_id,branch_id',
                'division.branch:id,name,company_id',
                'division.company:id,name,logo',
                'department:id,name,branch_id,division_id',
                'department.division:id,name,company_id,branch_id',
                'department.branch:id,name,company_id',
                'department.branch.company:id,name,logo',
                'sectionUnit:id,name,company_id,branch_id,division_id,department_id',
                'sectionUnit.company:id,name,logo',
                'sectionUnit.branch:id,name,company_id',
                'sectionUnit.division:id,name,company_id,branch_id',
                'sectionUnit.department:id,name,branch_id,division_id',
                'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id,division_id,department_id,section_unit_id',
                'employee.companyHeadships:id,name,logo,company_head_id',
                'employee.company:id,name,logo',
                'employee.branch:id,name,company_id',
                'employee.branch.company:id,name,logo',
                'employee.departmentRelation:id,name,branch_id',
                'employee.departmentRelation.branch:id,name,company_id',
                'employee.departmentRelation.branch.company:id,name,logo',
            ])
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $customRows = [];
        $activeDatesByName = $this->activeHolidayDatesByNameForYear($year);
        foreach ($holidays as $holiday) {
            $row = $this->serializeHolidayRow($holiday);
            if ($this->shouldSuppressRelocatedHolidayRow($row, $activeDatesByName)) {
                continue;
            }
            $explicitKeys[] = $this->overrideKey($row);
            if (strtolower((string) ($row['status'] ?? 'active')) === 'inactive') {
                continue;
            }
            $customRows[] = $row;
        }

        foreach (array_values($this->seededFallbackForYear($year)) as $row) {
            if (! in_array($this->overrideKey($row), $explicitKeys, true)) {
                $rows[] = $row;
            }
        }

        foreach ($customRows as $row) {
            $rows[] = $row;
        }

        foreach ($this->recurringHolidayRowsForYear($year, $explicitKeys) as $row) {
            $rows[] = $row;
        }

        $rows = array_values(array_filter(
            $rows,
            fn (array $row) => $this->shouldIncludeInCalendarDisplay($row, $activeDatesByName)
        ));

        usort($rows, function (array $a, array $b) {
            $dateCompare = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $this->scopePrecedence($b) <=> $this->scopePrecedence($a);
        });

        $this->holidaysListByYear[$year] = $rows;

        return collect($rows);
    }

    /**
     * Hard-deleting a nationwide baseline holiday would resurrect the PH seeded calendar row.
     */
    public function wouldSeededHolidayResurfaceOnDelete(Holiday $holiday): bool
    {
        $date = $holiday->date instanceof Carbon
            ? $holiday->date->format('Y-m-d')
            : (string) $holiday->date;
        $year = (int) substr($date, 0, 4);
        $scope = strtolower((string) ($holiday->scope ?? 'nationwide'));
        if ($scope !== 'nationwide') {
            return false;
        }

        $baseline = $this->seededFallbackForYear($year)[$date] ?? null;
        if ($baseline === null) {
            return false;
        }

        return strcasecmp(trim((string) $holiday->name), (string) ($baseline['name'] ?? '')) === 0;
    }

    /**
     * Local seeded fallback holidays (PH nationwide baseline).
     *
     * @return array<string, array<string, mixed>>
     */
    private function seededFallbackForYear(int $year): array
    {
        $rows = [
            ['md' => '01-01', 'name' => "New Year's Day", 'type' => 'regular'],
            ['md' => '04-09', 'name' => 'Araw ng Kagitingan', 'type' => 'regular'],
            ['md' => '05-01', 'name' => 'Labor Day', 'type' => 'regular'],
            ['md' => '06-12', 'name' => 'Independence Day', 'type' => 'regular'],
            ['md' => '08-21', 'name' => 'Ninoy Aquino Day', 'type' => 'special'],
            ['md' => '08-25', 'name' => 'National Heroes Day', 'type' => 'regular'],
            ['md' => '11-01', 'name' => "All Saints' Day", 'type' => 'special'],
            ['md' => '11-30', 'name' => 'Bonifacio Day', 'type' => 'regular'],
            ['md' => '12-08', 'name' => 'Feast of the Immaculate Conception', 'type' => 'special'],
            ['md' => '12-24', 'name' => 'Christmas Eve', 'type' => 'special'],
            ['md' => '12-25', 'name' => 'Christmas Day', 'type' => 'regular'],
            ['md' => '12-30', 'name' => 'Rizal Day', 'type' => 'regular'],
            ['md' => '12-31', 'name' => "New Year's Eve", 'type' => 'special'],
        ];

        $out = [];
        $activeDatesByName = $this->activeHolidayDatesByNameForYear($year);

        foreach ($rows as $r) {
            $date = sprintf('%04d-%s', $year, $r['md']);
            $datesForName = $activeDatesByName[$r['name']] ?? [];
            if ($datesForName !== [] && ! in_array($date, $datesForName, true)) {
                continue;
            }

            $out[$date] = [
                'date' => $date,
                'name' => $r['name'],
                'type' => $r['type'],
                'scope' => 'nationwide',
                'scope_type' => 'nationwide',
                'scope_label' => 'Nationwide',
                'scope_target' => 'All employees',
                'scope_path' => 'Nationwide',
                'company_id' => null,
                'branch_id' => null,
                'division_id' => null,
                'department_id' => null,
                'section_unit_id' => null,
                'employee_id' => null,
                'description' => null,
                'regions' => null,
                'is_recurring' => true,
                'status' => 'active',
                'source' => 'seeded',
            ];
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function activeHolidayDatesByNameForYear(int $year): array
    {
        $map = [];
        foreach (Holiday::query()
            ->where('status', 'active')
            ->whereYear('date', $year)
            ->get(['name', 'date']) as $holiday) {
            $name = trim((string) $holiday->name);
            if ($name === '') {
                continue;
            }
            $dateKey = $holiday->date instanceof Carbon
                ? $holiday->date->format('Y-m-d')
                : (string) $holiday->date;
            $map[$name][] = $dateKey;
        }

        return $map;
    }

    /** @param  array<string, list<string>>  $activeDatesByName */
    private function shouldSuppressRelocatedHolidayRow(array $row, array $activeDatesByName): bool
    {
        if (strtolower((string) ($row['status'] ?? 'active')) !== 'active') {
            return false;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $date = (string) ($row['date'] ?? '');
        if ($name === '' || $date === '') {
            return false;
        }

        $dates = array_values(array_unique($activeDatesByName[$name] ?? []));
        if (count($dates) < 2 || ! in_array($date, $dates, true)) {
            return false;
        }

        $others = array_values(array_filter($dates, fn (string $candidate) => $candidate !== $date));
        if ($others === []) {
            return false;
        }

        $originalDate = (string) ($row['original_date'] ?? '');
        if ($originalDate !== '' && $originalDate === $date) {
            return true;
        }

        // ponytail: bad swap can leave two active rows — keep the later moved date only
        return $date < max($others);
    }

    /**
     * Calendar + list should only show active holidays on their effective observation date.
     *
     * @param  array<string, list<string>>  $activeDatesByName
     */
    private function shouldIncludeInCalendarDisplay(array $row, array $activeDatesByName): bool
    {
        if (strtolower((string) ($row['status'] ?? 'active')) !== 'active') {
            return false;
        }

        if ($this->shouldSuppressRelocatedHolidayRow($row, $activeDatesByName)) {
            return false;
        }

        $name = trim((string) ($row['name'] ?? ''));
        $date = (string) ($row['date'] ?? '');
        if ($name === '' || $date === '') {
            return true;
        }

        $activeDates = array_values(array_unique($activeDatesByName[$name] ?? []));
        if ($activeDates === [] || in_array($date, $activeDates, true)) {
            return true;
        }

        $originalDate = (string) ($row['original_date'] ?? '');
        if ($originalDate !== '' && $originalDate === $date) {
            return false;
        }

        // PH baseline / ghost row while the same holiday is observed on another date.
        return false;
    }

    /**
     * @param  list<string>  $explicitKeys
     * @return list<array<string, mixed>>
     */
    private function recurringHolidayRowsForYear(int $year, array $explicitKeys): array
    {
        $out = [];
        $templates = Holiday::query()
            ->with([
                'company:id,name,logo',
                'branch:id,name,company_id',
                'branch.company:id,name,logo',
                'division:id,name,company_id,branch_id',
                'division.branch:id,name,company_id',
                'division.company:id,name,logo',
                'department:id,name,branch_id,division_id',
                'department.division:id,name,company_id,branch_id',
                'department.branch:id,name,company_id',
                'department.branch.company:id,name,logo',
                'sectionUnit:id,name,company_id,branch_id,division_id,department_id',
                'sectionUnit.company:id,name,logo',
                'sectionUnit.branch:id,name,company_id',
                'sectionUnit.division:id,name,company_id,branch_id',
                'sectionUnit.department:id,name,branch_id,division_id',
                'employee:id,name,first_name,middle_name,last_name,suffix,employee_code,company_id,branch_id,division_id,department_id,section_unit_id',
                'employee.companyHeadships:id,name,logo,company_head_id',
                'employee.company:id,name,logo',
                'employee.branch:id,name,company_id',
                'employee.branch.company:id,name,logo',
                'employee.departmentRelation:id,name,branch_id',
                'employee.departmentRelation.branch:id,name,company_id',
                'employee.departmentRelation.branch.company:id,name,logo',
            ])
            ->where('is_recurring', true)
            ->orderBy('date')
            ->get();

        foreach ($templates as $h) {
            if (strtolower((string) ($h->status ?? 'active')) === 'inactive') {
                continue;
            }

            $anchor = $h->date instanceof Carbon ? $h->date->copy() : Carbon::parse((string) $h->date);
            if ((int) $anchor->year === $year) {
                continue;
            }

            try {
                $effective = Carbon::createFromDate($year, (int) $anchor->format('n'), (int) $anchor->format('j'))->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            $row = $this->serializeHolidayRow($h);
            $row['date'] = $effective->format('Y-m-d');
            $row['is_recurring'] = true;
            $row['source'] = 'recurring';
            if (in_array($this->overrideKey($row), $explicitKeys, true)) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHolidayRow(Holiday $h): array
    {
        $d = $h->date instanceof Carbon ? $h->date->format('Y-m-d') : (string) $h->date;
        $company = $h->company
            ?? $h->branch?->company
            ?? $h->division?->company
            ?? $h->division?->branch?->company
            ?? $h->department?->branch?->company
            ?? $h->sectionUnit?->company
            ?? $h->sectionUnit?->branch?->company
            ?? $h->employee?->companyHeadships?->first()
            ?? $h->employee?->company
            ?? $h->employee?->branch?->company
            ?? $h->employee?->departmentRelation?->branch?->company;

        $row = [
            'id' => $h->id,
            'date' => $d,
            'name' => TextSanitizer::clean($h->name, $h->name) ?? $h->name,
            'type' => $h->type,
            'scope' => $h->scope,
            'scope_type' => $h->scope,
            'company_id' => $h->company_id,
            'branch_id' => $h->branch_id,
            'division_id' => $h->division_id,
            'department_id' => $h->department_id,
            'section_unit_id' => $h->section_unit_id,
            'employee_id' => $h->employee_id,
            'coverage_type' => $h->coverage_type,
            'coverage_ids' => is_array($h->coverage_ids) ? $h->coverage_ids : [],
            'is_swap' => (bool) ($h->is_swap ?? false),
            'original_date' => $h->original_date instanceof Carbon
                ? $h->original_date->format('Y-m-d')
                : ($h->original_date ? (string) $h->original_date : null),
            'company_name' => $company?->name,
            'company_logo_url' => $this->publicMediaUrl($company?->logo),
            'branch_name' => $h->branch?->name ?? $h->employee?->branch?->name,
            'division_name' => $h->division?->name ?? $h->sectionUnit?->division?->name,
            'department_name' => $h->department?->name ?? $h->employee?->departmentRelation?->name,
            'section_unit_name' => $h->sectionUnit?->name ?? $h->employee?->sectionUnit?->name,
            'employee_name' => $h->employee?->display_name,
            'employee_formatted_name' => $h->employee?->formatted_name,
            'employee_code' => $h->employee?->employee_code,
            'description' => $h->description ?? null,
            'regions' => $h->regions,
            'is_recurring' => (bool) ($h->is_recurring ?? false),
            'status' => $h->status ?? 'active',
            'source' => 'custom',
        ];

        return array_merge($row, [
            'scope_label' => $this->scopeLabel((string) ($row['scope'] ?? 'nationwide')),
            'scope_target' => $this->scopeTargetLabel($row),
            'scope_path' => $this->scopePathLabel($row),
        ]);
    }

    /**
     * Holiday row for payroll / rules engine.
     *
     * @return array{name: string, type: string, scope: string, description: ?string}|null
     */
    public function holidayForDate(
        string $dateKey,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null,
        ?int $divisionId = null,
        ?int $sectionUnitId = null
    ): ?array {
        $year = (int) substr($dateKey, 0, 4);
        if ($year < 2000) {
            return null;
        }

        $matches = $this->holidaysForYear($year)
            ->filter(fn (array $row) => ($row['date'] ?? null) === $dateKey
                && $this->rowAppliesToTarget($row, $companyId, $branchId, $departmentId, $employeeId, $divisionId, $sectionUnitId))
            ->values()
            ->all();

        return $this->bestActiveMatch($matches);
    }

    /**
     * Best active holiday on a calendar date, without employee scope filtering.
     * Used by payroll when Holiday Pay Policy ignores organizational coverage.
     *
     * @return array<string, mixed>|null
     */
    public function activeHolidayOnDate(string $dateKey, ?User $user = null): ?array
    {
        $year = (int) substr($dateKey, 0, 4);
        if ($year < 2000) {
            return null;
        }

        $matches = $this->holidaysForYear($year)
            ->filter(fn (array $row): bool => ($row['date'] ?? null) === $dateKey)
            ->values()
            ->all();

        return $this->bestActiveMatch($matches, $user);
    }

    /**
     * @return array{name: string, type: string, scope: string, description: ?string}|null
     */
    public function holidayForUserDate(User $user, string $dateKey): ?array
    {
        $year = (int) substr($dateKey, 0, 4);
        if ($year < 2000) {
            return null;
        }

        $date = Carbon::parse($dateKey);
        $matches = $this->holidaysForYear($year)
            ->filter(fn (array $row): bool => ($row['date'] ?? null) === $dateKey
                && $this->scopeResolver->appliesRowToEmployee($row, $user, $date))
            ->values()
            ->all();

        return $this->bestActiveMatch($matches, $user);
    }

    public function rowAppliesToTarget(
        array $row,
        ?int $companyId,
        ?int $branchId,
        ?int $departmentId,
        ?int $employeeId,
        ?int $divisionId = null,
        ?int $sectionUnitId = null
    ): bool {
        return $this->scopeResolver->appliesToContext($row, [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'employee_id' => $employeeId,
            'division_id' => $divisionId,
            'section_unit_id' => $sectionUnitId,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>|null
     */
    private function bestActiveMatch(array $matches, ?User $user = null): ?array
    {
        usort($matches, function (array $a, array $b) use ($user) {
            $type = $this->holidayTypePrecedence($b) <=> $this->holidayTypePrecedence($a);
            if ($type !== 0) {
                return $type;
            }

            if ($user !== null) {
                $company = $this->payrollCompanyAffinity($b, $user) <=> $this->payrollCompanyAffinity($a, $user);
                if ($company !== 0) {
                    return $company;
                }
            }

            $scope = $this->scopePrecedence($b) <=> $this->scopePrecedence($a);
            if ($scope !== 0) {
                return $scope;
            }

            return $this->sourcePrecedence($b) <=> $this->sourcePrecedence($a);
        });

        foreach ($matches as $row) {
            $status = strtolower((string) ($row['status'] ?? 'active'));
            if ($status === 'active' || $status === '') {
                return [
                    'id' => $row['id'] ?? null,
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'scope' => (string) ($row['scope'] ?? 'nationwide'),
                    'scope_type' => (string) ($row['scope'] ?? 'nationwide'),
                    'scope_label' => $this->scopeLabel((string) ($row['scope'] ?? 'nationwide')),
                    'scope_target' => $this->scopeTargetLabel($row),
                    'company_id' => $row['company_id'] ?? null,
                    'branch_id' => $row['branch_id'] ?? null,
                    'division_id' => $row['division_id'] ?? null,
                    'department_id' => $row['department_id'] ?? null,
                    'section_unit_id' => $row['section_unit_id'] ?? null,
                    'employee_id' => $row['employee_id'] ?? null,
                    'coverage_type' => $row['coverage_type'] ?? null,
                    'coverage_ids' => $row['coverage_ids'] ?? [],
                    'description' => $row['description'] ?? null,
                ];
            }
            if ($status === 'inactive') {
                return null;
            }
        }

        return null;
    }

    private function scopePrecedence(array $row): int
    {
        return match (strtolower((string) ($row['scope'] ?? 'nationwide'))) {
            'employee' => 60,
            'section_unit' => 55,
            'department' => 50,
            'division' => 45,
            'branch' => 40,
            'company' => 30,
            'regional' => 20,
            default => 10,
        };
    }

    /** Payroll/statutory weight: regular holidays beat special on the same date. */
    private function holidayTypePrecedence(array $row): int
    {
        return match (strtolower(trim((string) ($row['type'] ?? '')))) {
            'double', 'double_holiday' => 50,
            'regular', 'regular_holiday' => 40,
            'special', 'special_non_working', 'special_non_working_holiday' => 20,
            'special_working', 'special_working_day', 'company', 'company_event' => 10,
            default => 0,
        };
    }

    private function payrollCompanyAffinity(array $row, User $user): int
    {
        $employeeCompanyId = (int) $user->getEffectiveCompanyId();
        $holidayCompanyId = (int) ($row['company_id'] ?? 0);
        if ($employeeCompanyId > 0 && $holidayCompanyId === $employeeCompanyId) {
            return 30;
        }
        if ($holidayCompanyId === 0 && strtolower((string) ($row['scope'] ?? '')) === 'nationwide') {
            return 20;
        }

        return 0;
    }

    private function sourcePrecedence(array $row): int
    {
        return match ((string) ($row['source'] ?? 'custom')) {
            'custom' => 30,
            'recurring' => 20,
            default => 10,
        };
    }

    private function overrideKey(array $row): string
    {
        return implode('|', [
            (string) ($row['date'] ?? ''),
            strtolower((string) ($row['scope'] ?? 'nationwide')),
            (string) ((int) ($row['company_id'] ?? 0)),
            (string) ((int) ($row['branch_id'] ?? 0)),
            (string) ((int) ($row['division_id'] ?? 0)),
            (string) ((int) ($row['department_id'] ?? 0)),
            (string) ((int) ($row['section_unit_id'] ?? 0)),
            (string) ((int) ($row['employee_id'] ?? 0)),
        ]);
    }

    private function scopeLabel(string $scope): string
    {
        return match (strtolower($scope)) {
            'employee' => 'Specific Employee',
            'section_unit' => 'Section / Unit',
            'department' => 'Department',
            'division' => 'Division',
            'branch' => 'Branch',
            'company' => 'Company',
            default => 'Nationwide',
        };
    }

    private function scopeTargetLabel(array $row): string
    {
        return match (strtolower((string) ($row['scope'] ?? 'nationwide'))) {
            'employee' => (string) ($row['employee_name'] ?? 'Specific employee'),
            'section_unit' => (string) ($row['section_unit_name'] ?? 'Section / Unit'),
            'department' => (string) ($row['department_name'] ?? 'Department'),
            'division' => (string) ($row['division_name'] ?? 'Division'),
            'branch' => (string) ($row['branch_name'] ?? 'Branch'),
            'company' => (string) ($row['company_name'] ?? 'Company'),
            default => 'Nationwide',
        };
    }

    private function scopePathLabel(array $row): string
    {
        $scope = strtolower((string) ($row['scope'] ?? 'nationwide'));
        $parts = array_values(array_filter([
            $row['company_name'] ?? null,
            $row['branch_name'] ?? null,
            $row['division_name'] ?? null,
            $row['department_name'] ?? null,
            $row['section_unit_name'] ?? null,
        ], fn ($part) => is_string($part) && trim($part) !== ''));

        if ($scope === 'nationwide' || $scope === 'regional') {
            return 'Nationwide';
        }

        return $parts !== [] ? implode(' / ', $parts) : $this->scopeTargetLabel($row);
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    private function publicMediaUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }
}
