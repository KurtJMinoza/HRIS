<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\HolidayCalendarService;
use App\Services\HrRoleResolver;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Services\PresenceFilingService;
use App\Support\TextSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $attendanceCorrectionApprovalService,
        private readonly EmployeeStatusService $employeeStatusService,
        private readonly HolidayCalendarService $holidayCalendar,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PresenceFilingCorrectionFormatter $correctionFormatter,
        private readonly PresenceFilingService $presenceFilingService,
    ) {}

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Employee user IDs in scope for the actor (optionally active only).
     *
     * @return array<int, int>
     */
    private function scopedEmployeeIds(User $actor, bool $onlyActive): array
    {
        $q = User::query()->whereIn('role', User::ROSTER_ELIGIBLE_ROLES);
        if ($onlyActive) {
            $q->active();
        }
        $this->dataScopeService->restrictEmployeeQuery($actor, $q);

        return $q->pluck('id')->all();
    }

    /**
     * Return UTC range [start, end] for a calendar day in the attendance timezone.
     * Use with {@see attendanceLogEffectivePunchWhereBetween()} so punch instants (`verified_at`, else `created_at`)
     * stored in UTC line up with the business calendar date.
     */
    private function dateRangeUtcForDay(Carbon $date, string $tz): array
    {
        $start = $date->copy()->startOfDay()->setTimezone('UTC');
        $end = $date->copy()->endOfDay()->setTimezone('UTC');

        return [$start, $end];
    }

    /**
     * True punch instant for dashboards: prefer `verified_at` (seeded kiosk / corrections), fallback `created_at`.
     */
    private function attendanceLogPunchInstant(AttendanceLog $log): ?Carbon
    {
        $t = $log->verified_at ?? $log->created_at;
        if ($t === null) {
            return null;
        }

        return $t instanceof Carbon ? $t : Carbon::parse($t);
    }

    private function attendanceLogEffectivePunchColumnSql(): string
    {
        return 'COALESCE(verified_at, created_at)';
    }

    /**
     * @param  Builder<AttendanceLog>  $query
     * @return Builder<AttendanceLog>
     */
    private function attendanceLogEffectivePunchWhereBetween(Builder $query, Carbon $rangeStartUtc, Carbon $rangeEndUtc): Builder
    {
        return $query->whereRaw(
            $this->attendanceLogEffectivePunchColumnSql().' between ? and ?',
            [$rangeStartUtc->format('Y-m-d H:i:s'), $rangeEndUtc->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Dashboard stats, chart data, and today's attendance logs.
     */
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $actor = $request->user();
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();

        $todayDayKey = self::DAY_KEYS[(int) $today->format('w')];
        $yesterdayDayKey = self::DAY_KEYS[(int) $yesterday->format('w')];
        $undertimeThresholdMinutes = config('attendance.undertime_threshold_minutes', 60);

        $activeScopeIds = $this->scopedEmployeeIds($actor, true);
        $allScopeIds = $this->scopedEmployeeIds($actor, false);
        $activeEmployees = $activeScopeIds === []
            ? collect()
            : User::whereIn('id', $activeScopeIds)->with('workingSchedule')->get();
        $activeEmployeeIds = $activeEmployees->pluck('id')->all();
        // Total employees in scope (active + inactive).
        $totalEmployees = count($allScopeIds);

        // Today + yesterday daily stats (per-date, reset automatically when the date changes).
        $statsToday = $this->computeDailyStats(
            $today,
            $todayDayKey,
            $activeEmployees,
            $activeEmployeeIds,
            $undertimeThresholdMinutes,
            $tz,
            $now,
            false
        );
        $statsYesterday = $this->computeDailyStats(
            $yesterday,
            $yesterdayDayKey,
            $activeEmployees,
            $activeEmployeeIds,
            $undertimeThresholdMinutes,
            $tz,
            // For yesterday we always treat as past cutoff.
            $yesterday->copy()->endOfDay(),
            false
        );

        // Attach total employees (same for both days).
        $statsToday['total_employees'] = $totalEmployees;
        $statsYesterday['total_employees'] = $totalEmployees;

        $weeklyOverview = $this->weeklyAttendanceOverview($today, $activeScopeIds);
        $upcomingHolidays = $this->upcomingHolidays($actor, $now);
        $departmentDistribution = $this->departmentAttendanceDistribution($today, $actor);
        $companyDistribution = $this->companyAttendanceDistribution($today, null, $actor);
        $todayLogs = $this->todayAttendanceLogs($today, $todayDayKey, $activeScopeIds);
        $halfDaySummary = $this->halfDaySummary($today, $activeScopeIds);
        $todayLeaves = $this->todayLeaves($today, $activeScopeIds);
        $upcomingRegularizations = $this->upcomingRegularizations($actor, 5);
        $expiringContracts = $this->expiringContracts($actor, 5);
        $birthdays = $this->birthdays($actor);
        $employmentSettings = $this->employeeStatusService->getAutomationSettings();

        $pendingCorrectionsCollection = $this->attendanceCorrectionApprovalService->getPendingForApprover($actor);
        $pendingAttendanceCorrections = $pendingCorrectionsCollection->count();
        $correctionDisplayTz = $this->presenceFilingService->attendanceTimezone();
        $pendingAttendanceCorrectionPreview = null;
        $pendingAttendanceCorrectionPreviews = [];
        if ($pendingCorrectionsCollection->isNotEmpty()) {
            $pendingAttendanceCorrectionPreview = $this->correctionFormatter->format(
                $pendingCorrectionsCollection->first(),
                $correctionDisplayTz,
                includeEmployee: true,
                actor: $actor,
                includeDisplayFields: true
            );
            $pendingAttendanceCorrectionPreviews = $pendingCorrectionsCollection
                ->take(5)
                ->map(fn ($correction) => $this->correctionFormatter->format(
                    $correction,
                    $correctionDisplayTz,
                    includeEmployee: true,
                    actor: $actor,
                    includeDisplayFields: true
                ))
                ->values()
                ->all();
        }
        $pendingAttendanceCorrectionRequests = collect($pendingAttendanceCorrectionPreviews)
            ->map(fn (array $row) => $row + [
                'correction_request_id' => $row['id'] ?? null,
                'employee_id' => $row['user_id'] ?? null,
                'attendance_date' => $row['date'] ?? null,
                'requested_time_start' => $row['requested_time_in'] ?? $row['time_in'] ?? null,
                'requested_time_end' => $row['requested_time_out'] ?? $row['time_out'] ?? null,
                'current_step' => $row['approval_stage'] ?? null,
                'can_review' => (bool) ($row['actor_can_approve'] ?? false),
            ])
            ->values()
            ->all();

        $response = [
            'stats' => $statsToday,
            'stats_prev' => $statsYesterday,
            'weekly_overview' => $weeklyOverview,
            'upcoming_holidays' => $upcomingHolidays,
            'department_distribution' => $departmentDistribution,
            'company_distribution' => $companyDistribution,
            'today_logs' => $todayLogs,
            'half_day_summary' => $halfDaySummary,
            'today_leaves' => $todayLeaves,
            'today_birthdays' => $birthdays['today_birthdays'],
            'current_month_birthdays' => $birthdays['current_month_birthdays'],
            'upcoming_30_days' => $birthdays['upcoming_30_days'],
            'upcoming_90_days' => $birthdays['upcoming_90_days'],
            // Backward-compatible aliases for any existing dashboard consumers.
            'upcoming_birthdays' => $birthdays['upcoming_birthdays'],
            'upcoming_birthdays_90' => $birthdays['upcoming_birthdays_90'],
            'birthday_month_label' => $birthdays['birthday_month_label'],
            'birthday_month_range_label' => $birthdays['birthday_month_range_label'],
            'upcoming_regularizations' => $upcomingRegularizations,
            'expiring_contracts' => $expiringContracts,
            'employment_settings' => $employmentSettings,
            'pending_attendance_corrections' => $pendingAttendanceCorrections,
            'pending_attendance_correction_preview' => $pendingAttendanceCorrectionPreview,
            'pending_attendance_correction_previews' => $pendingAttendanceCorrectionPreviews,
            'pending_count' => $pendingAttendanceCorrections,
            'pending_requests' => $pendingAttendanceCorrectionRequests,
        ];

        Log::info('Admin dashboard payload prepared', [
            'actor_user_id' => (int) $actor->id,
            'active_scope_count' => count($activeScopeIds),
            'all_scope_count' => count($allScopeIds),
            'today_logs_count' => is_array($todayLogs) ? count($todayLogs) : 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json($response);
    }

    /** How many calendar months back admins may browse birthday history. */
    private const BIRTHDAY_HISTORY_MONTHS = 24;

    /** How many calendar months ahead admins may browse upcoming birthdays. */
    private const BIRTHDAY_FUTURE_MONTHS = 12;

    /**
     * Birthdays for a specific calendar month (past, current, or future). Used by the dashboard month browser.
     */
    public function birthdaysByMonth(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $tz = 'Asia/Manila';
        $today = Carbon::now($tz)->startOfDay();
        $year = (int) $request->integer('year', $today->year);
        $month = (int) $request->integer('month', $today->month);

        if ($month < 1 || $month > 12) {
            abort(422, 'Invalid month.');
        }

        $selectedStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $currentMonthStart = $today->copy()->startOfMonth();
        $earliestMonthStart = $currentMonthStart->copy()->subMonths(self::BIRTHDAY_HISTORY_MONTHS - 1);
        $latestMonthStart = $currentMonthStart->copy()->addMonths(self::BIRTHDAY_FUTURE_MONTHS - 1);

        if ($selectedStart->greaterThan($latestMonthStart)) {
            abort(422, 'Birthday calendar is limited to the next '.self::BIRTHDAY_FUTURE_MONTHS.' months.');
        }
        if ($selectedStart->lessThan($earliestMonthStart)) {
            abort(422, 'Birthday history is limited to the last '.self::BIRTHDAY_HISTORY_MONTHS.' months.');
        }

        return response()->json($this->birthdaysForCalendarMonth($actor, $year, $month));
    }

    /**
     * Active employee birthdays for the admin dashboard.
     *
     * @return array{today_birthdays: array<int, array<string, mixed>>, current_month_birthdays: array<int, array<string, mixed>>, upcoming_30_days: array<int, array<string, mixed>>, upcoming_90_days: array<int, array<string, mixed>>, upcoming_birthdays: array<int, array<string, mixed>>, upcoming_birthdays_90: array<int, array<string, mixed>>, birthday_month_label: string, birthday_month_range_label: string}
     */
    private function birthdays(User $actor, int $daysAhead = 30, int $extendedDaysAhead = 90): array
    {
        $tz = 'Asia/Manila';
        $today = Carbon::now($tz)->startOfDay();
        $window = max(1, $daysAhead);
        $extendedWindow = max($window, $extendedDaysAhead);
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $baseQuery = $this->scopedBirthdayEmployeeQuery($actor);

        $fingerprintQuery = clone $baseQuery;
        $fingerprint = $fingerprintQuery
            ->select([
                DB::raw('COUNT(*) as aggregate_count'),
                DB::raw('MAX(updated_at) as aggregate_updated_at'),
            ])
            ->first();
        $cacheKey = implode(':', [
            'admin_dashboard_birthdays',
            (int) $actor->id,
            $today->toDateString(),
            (int) ($fingerprint?->aggregate_count ?? 0),
            (string) ($fingerprint?->aggregate_updated_at ?? 'none'),
            $window,
            $extendedWindow,
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($baseQuery, $today, $window, $extendedWindow, $monthStart, $monthEnd): array {
            $rows = $this->buildBirthdayEmployeeRows($baseQuery, $today);

            $clean = function (array $row): array {
                unset($row['__sort_name']);

                return $row;
            };

            $upcoming30 = $rows
                ->filter(fn (array $row) => (int) $row['days_until_birthday'] >= 0 && (int) $row['days_until_birthday'] <= $window)
                ->map($clean)
                ->values()
                ->all();
            $upcoming90 = $rows
                ->filter(fn (array $row) => (int) $row['days_until_birthday'] >= 0 && (int) $row['days_until_birthday'] <= $extendedWindow)
                ->map($clean)
                ->values()
                ->all();

            $currentMonthPayload = $this->filterBirthdaysForCalendarMonth($rows, $today->year, $today->month, $today);

            return [
                'today_birthdays' => $rows
                    ->filter(fn (array $row) => (int) $row['days_until_birthday'] === 0)
                    ->map($clean)
                    ->values()
                    ->all(),
                'current_month_birthdays' => $currentMonthPayload['birthdays'],
                'upcoming_30_days' => $upcoming30,
                'upcoming_90_days' => $upcoming90,
                'upcoming_birthdays' => $upcoming30,
                'upcoming_birthdays_90' => $upcoming90,
                'birthday_month_label' => $currentMonthPayload['birthday_month_label'],
                'birthday_month_range_label' => $currentMonthPayload['birthday_month_range_label'],
            ];
        });
    }

    /**
     * @return array{
     *     birthdays: array<int, array<string, mixed>>,
     *     birthday_month_label: string,
     *     birthday_month_range_label: string,
     *     year: int,
     *     month: int,
     *     is_current_month: bool,
     *     is_past_month: bool,
     *     is_future_month: bool,
     *     can_go_previous: bool,
     *     can_go_next: bool
     * }
     */
    private function birthdaysForCalendarMonth(User $actor, int $year, int $month): array
    {
        $tz = 'Asia/Manila';
        $today = Carbon::now($tz)->startOfDay();
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $currentMonthStart = $today->copy()->startOfMonth();
        $earliestMonthStart = $currentMonthStart->copy()->subMonths(self::BIRTHDAY_HISTORY_MONTHS - 1);
        $latestMonthStart = $currentMonthStart->copy()->addMonths(self::BIRTHDAY_FUTURE_MONTHS - 1);

        $baseQuery = $this->scopedBirthdayEmployeeQuery($actor);
        $fingerprintQuery = clone $baseQuery;
        $fingerprint = $fingerprintQuery
            ->select([
                DB::raw('COUNT(*) as aggregate_count'),
                DB::raw('MAX(updated_at) as aggregate_updated_at'),
            ])
            ->first();

        $cacheKey = implode(':', [
            'admin_dashboard_birthdays_month',
            (int) $actor->id,
            $monthStart->format('Y-m'),
            $today->toDateString(),
            (int) ($fingerprint?->aggregate_count ?? 0),
            (string) ($fingerprint?->aggregate_updated_at ?? 'none'),
        ]);

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($baseQuery, $today, $year, $month, $monthStart, $currentMonthStart, $earliestMonthStart, $latestMonthStart): array {
            $rows = $this->buildBirthdayEmployeeRows($baseQuery, $today);
            $payload = $this->filterBirthdaysForCalendarMonth($rows, $year, $month, $today);

            return $payload + [
                'year' => $year,
                'month' => $month,
                'is_current_month' => $monthStart->isSameMonth($today),
                'is_past_month' => $monthStart->lessThan($currentMonthStart),
                'is_future_month' => $monthStart->greaterThan($currentMonthStart),
                'can_go_previous' => $monthStart->greaterThan($earliestMonthStart),
                'can_go_next' => $monthStart->lessThan($latestMonthStart),
            ];
        });
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function scopedBirthdayEmployeeQuery(User $actor)
    {
        $baseQuery = User::query()
            ->activeRoster()
            ->whereNotNull('date_of_birth');
        $this->dataScopeService->restrictEmployeeQuery($actor, $baseQuery);

        return $baseQuery;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $baseQuery
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildBirthdayEmployeeRows($baseQuery, Carbon $today)
    {
        $query = (clone $baseQuery)
            ->select([
                'users.id',
                'users.name',
                'users.first_name',
                'users.middle_name',
                'users.last_name',
                'users.suffix',
                'users.profile_image',
                'users.department',
                'users.department_id',
                'users.position',
                'users.date_of_birth',
            ])
            ->selectSub(
                DB::table('departments')
                    ->select('name')
                    ->whereColumn('departments.id', 'users.department_id')
                    ->limit(1),
                'department_name'
            );

        return $query->get()
            ->map(function (User $employee) use ($today): ?array {
                $birthDate = $employee->date_of_birth?->copy()->timezone($today->timezone);
                if (! $birthDate) {
                    return null;
                }

                $nextBirthday = Carbon::create(
                    (int) $today->year,
                    (int) $birthDate->month,
                    (int) $birthDate->day,
                    0,
                    0,
                    0,
                    $today->timezone
                )->startOfDay();

                if ($nextBirthday->lessThan($today)) {
                    $nextBirthday->addYear();
                }

                $daysUntilBirthday = (int) $today->diffInDays($nextBirthday, false);
                $isToday = $daysUntilBirthday === 0;
                $isTomorrow = $daysUntilBirthday === 1;
                $fullName = trim((string) $employee->display_name);

                $ageFields = $this->computeBirthdayAgeFields($birthDate, $nextBirthday, $today);

                return [
                    'employee_id' => (int) $employee->id,
                    'full_name' => $fullName !== '' ? $fullName : 'Employee #'.$employee->id,
                    'formatted_name' => $fullName !== '' ? $fullName : 'Employee #'.$employee->id,
                    'profile_image' => $employee->profile_image,
                    'profile_image_url' => $employee->profile_image_url,
                    'profile_picture_url' => $employee->profile_image_url,
                    'department' => $employee->department_name ?? $employee->department ?? 'Unassigned',
                    'position' => $employee->position ?: 'Unassigned',
                    'birth_date' => $birthDate->toDateString(),
                    'birth_date_formatted' => $birthDate->format('M j'),
                    'next_birthday_date' => $nextBirthday->toDateString(),
                    'next_birthday_formatted' => $nextBirthday->format('M j, Y'),
                    'birthday_month_day' => $birthDate->format('m-d'),
                    'day_name' => $nextBirthday->format('l'),
                    'birth_month' => (int) $birthDate->month,
                    'birth_day' => (int) $birthDate->day,
                    'days_until_birthday' => $daysUntilBirthday,
                    'current_age' => $ageFields['current_age'],
                    'next_age' => $ageFields['next_age'],
                    'birthday_status' => $ageFields['birthday_status'],
                    'is_today' => $isToday,
                    'is_tomorrow' => $isTomorrow,
                    'birthday_passed_in_view' => false,
                    '__sort_name' => $employee->employeeListingSortKey(),
                ];
            })
            ->filter()
            ->sortBy([
                ['days_until_birthday', 'asc'],
                ['__sort_name', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array{birthdays: array<int, array<string, mixed>>, birthday_month_label: string, birthday_month_range_label: string}
     */
    private function filterBirthdaysForCalendarMonth($rows, int $year, int $month, Carbon $today): array
    {
        $tz = $today->timezone;
        $monthStart = Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $currentMonthStart = $today->copy()->startOfMonth();
        $isPastMonth = $monthStart->lessThan($currentMonthStart);
        $isCurrentMonth = $monthStart->isSameMonth($today);
        $isFutureMonth = $monthStart->greaterThan($currentMonthStart);

        $clean = function (array $row): array {
            unset($row['__sort_name']);

            return $row;
        };

        $birthdays = $rows
            ->filter(fn (array $row) => (int) $row['birth_month'] === $month)
            ->map(function (array $row) use ($year, $month, $today, $isPastMonth, $isCurrentMonth, $isFutureMonth, $tz): array {
                $occurrence = Carbon::create($year, $month, (int) $row['birth_day'], 0, 0, 0, $tz)->startOfDay();
                $daysUntilOccurrence = (int) $today->diffInDays($occurrence, false);
                $birthDate = Carbon::parse((string) $row['birth_date'], $tz)->startOfDay();
                $passedInView = $isPastMonth || ($isCurrentMonth && $occurrence->lessThan($today));
                $ageFields = $this->computeBirthdayAgeFields($birthDate, $occurrence, $today, $passedInView);
                $row['day_name'] = $occurrence->format('l');
                $row['next_birthday_date'] = $occurrence->toDateString();
                $row['next_birthday_formatted'] = $occurrence->format('M j, Y');
                $row['days_until_birthday'] = $daysUntilOccurrence;
                $row['current_age'] = $ageFields['current_age'];
                $row['next_age'] = $ageFields['next_age'];
                $row['birthday_status'] = $ageFields['birthday_status'];
                $row['is_today'] = $daysUntilOccurrence === 0;
                $row['is_tomorrow'] = $daysUntilOccurrence === 1;
                $row['birthday_passed_in_view'] = $passedInView;
                $row['birthday_upcoming_in_view'] = $isFutureMonth || ($isCurrentMonth && $occurrence->greaterThan($today));

                return $row;
            })
            ->sortBy([
                ['birth_day', 'asc'],
                ['__sort_name', 'asc'],
            ])
            ->map($clean)
            ->values()
            ->all();

        return [
            'birthdays' => $birthdays,
            'birthday_month_label' => $monthStart->format('F Y'),
            'birthday_month_range_label' => $monthStart->format('M j').' to '.$monthEnd->format('M j'),
        ];
    }

    /**
     * @return array{current_age: int, next_age: int, birthday_status: string}
     */
    private function computeBirthdayAgeFields(
        Carbon $birthDate,
        Carbon $occurrence,
        Carbon $today,
        bool $passedInView = false
    ): array {
        $birthDate = $birthDate->copy()->startOfDay();
        $occurrence = $occurrence->copy()->startOfDay();
        $today = $today->copy()->startOfDay();

        $currentAge = (int) $birthDate->diff($today)->y;
        $nextAge = (int) $occurrence->year - (int) $birthDate->year;
        $daysUntil = (int) $today->diffInDays($occurrence, false);

        $birthdayStatus = match (true) {
            $passedInView || $daysUntil < 0 => 'passed',
            $daysUntil === 0 => 'today',
            $daysUntil === 1 => 'tomorrow',
            default => 'upcoming',
        };

        return [
            'current_age' => $currentAge,
            'next_age' => $nextAge,
            'birthday_status' => $birthdayStatus,
        ];
    }

    private function upcomingRegularizations(User $actor, int $limit = 8): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = Carbon::now($tz)->startOfDay();
        $settings = $this->employeeStatusService->getAutomationSettings();
        $earlyMonths = $settings['early_regularization_months'];
        $autoMonths = $settings['auto_regularization_months'];
        $greenWindowDays = (int) config('employment.regularization.dashboard_green_window_days', 30);
        $role = $this->hrRoleResolver->resolveForApprovalSubject($actor)->value;
        $isHrAdmin = $this->hrRoleResolver->isAdminHrAccount($actor);

        $query = User::query()
            ->activeRoster()
            ->whereNotNull('hire_date')
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);
        $employees = $query->orderByLastName()->get();
        if ($employees->isEmpty()) {
            return [];
        }

        $recommendationsByUser = RegularizationRecommendation::query()
            ->with(['recommendedBy:id,name,first_name,middle_name,last_name,suffix'])
            ->whereIn('user_id', $employees->pluck('id')->all())
            ->where('recommendation_type', RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR)
            ->orderByDesc('recommended_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first());

        $rows = $employees->map(function (User $employee) use ($today, $recommendationsByUser, $isHrAdmin, $earlyMonths, $autoMonths, $greenWindowDays) {
            $hireDate = $employee->hire_date?->copy()->startOfDay();
            if (! $hireDate) {
                return null;
            }

            // Do not rely on exact DB spellings; normalize to canonical label.
            $canonicalEmploymentLabel = EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);
            // Backward-compatible: treat missing status as probationary (default) for legacy rows.
            if ($canonicalEmploymentLabel === null) {
                $canonicalEmploymentLabel = EmploymentStatus::Probationary->label();
            }
            if ($canonicalEmploymentLabel !== EmploymentStatus::Probationary->label()) {
                return null;
            }

            $threeMonth = $hireDate->copy()->addMonths($earlyMonths);
            $fiveMonth = $hireDate->copy()->addMonths(max(0, $autoMonths - 1));
            $sixMonth = $hireDate->copy()->addMonths($autoMonths);

            // Use service calculation to avoid signed diffInDays quirks.
            $monthsSinceHire = $this->employeeStatusService->getMonthsSinceHire($employee, $today);
            if ($monthsSinceHire === null) {
                return null;
            }

            $serviceDiff = $hireDate->diff($today);
            $serviceMonths = ($serviceDiff->y * 12) + $serviceDiff->m;
            $serviceLengthLabel = ($serviceMonths ?? 0).' months '.$serviceDiff->d.' days';

            $passedThree = $today->greaterThanOrEqualTo($threeMonth);
            $passedFive = $today->greaterThanOrEqualTo($fiveMonth);
            $passedSix = $today->greaterThanOrEqualTo($sixMonth);

            $approachingThree = $today->lessThan($threeMonth) && $today->diffInDays($threeMonth) <= $greenWindowDays;
            $approachingFive = $today->lessThan($fiveMonth) && $today->diffInDays($fiveMonth) <= $greenWindowDays;
            $approachingSix = $today->lessThan($sixMonth) && $today->diffInDays($sixMonth) <= $greenWindowDays;

            $hasApproachingOrPassedMilestone = $passedThree || $passedFive || $passedSix || $approachingThree || $approachingFive || $approachingSix;
            $inMonitoringWindow = $monthsSinceHire >= 4.0 || $hasApproachingOrPassedMilestone;

            if (! $inMonitoringWindow) {
                return null;
            }

            $isOverdueSix = $today->greaterThan($sixMonth);
            $withinThirtyDayWindow = $today->diffInDays($sixMonth) <= $greenWindowDays;
            $earlyEligible = $monthsSinceHire >= $earlyMonths && ! $isOverdueSix;

            // Signed “days remaining” relative to the 6-month milestone.
            $daysToSixMonth = $isOverdueSix ? -$sixMonth->diffInDays($today) : $today->diffInDays($sixMonth);

            $recommendation = $recommendationsByUser->get($employee->id);
            $requiredActions = $this->employeeStatusService->getRequiredActions($employee);
            $actionsComplete = (bool) ($requiredActions['all_completed'] ?? false);
            $departmentName = $employee->departmentRelation?->name ?? $employee->department ?? 'Unassigned';
            $branchName = $employee->branch?->name ?? $employee->departmentRelation?->branch?->name ?? null;
            $daysRemainingLabel = $daysToSixMonth > 0
                ? $daysToSixMonth.' days left'
                : ($daysToSixMonth === 0 ? 'Due today' : abs($daysToSixMonth).' days overdue');

            // Status tone: red = overdue (past 6-month), green = within the configured green window, orange = early eligible / due soon.
            $statusTone = $isOverdueSix
                ? 'red'
                : ($withinThirtyDayWindow ? 'green' : 'orange');
            $statusLabel = $isOverdueSix
                ? 'Overdue'
                : ($withinThirtyDayWindow ? 'On Track' : 'Due Soon');
            if ($statusTone === 'orange' && $earlyEligible) {
                $statusLabel = 'Early eligible';
            }

            // Next milestone: earliest upcoming (<= today => none) else latest passed.
            $candidates = collect([
                ['milestone' => '3-month', 'date' => $threeMonth],
                ['milestone' => '5-month', 'date' => $fiveMonth],
                ['milestone' => '6-month', 'date' => $sixMonth],
            ])->sortBy(fn ($x) => $x['date']->getTimestamp());

            $upcoming = $candidates->first(fn ($x) => $x['date']->greaterThanOrEqualTo($today));
            $picked = $upcoming ?? $candidates->last();
            $nextMilestone = $picked['milestone'];
            $nextMilestoneDate = $picked['date']->toDateString();

            $recommendedAction = match ($nextMilestone) {
                '6-month' => 'HR decision: confirm Regular or extended probation',
                '5-month' => 'Complete 5-month review; schedule 6-month HR decision',
                '3-month' => 'Early confirmation after 3 months (head recommendation + HR approval)',
                default => '—',
            };

            return [
                'id' => $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'profile_image_url' => $employee->profile_image_url,
                'employee_code' => $employee->employee_code,
                'employment_type' => $employee->employment_type,
                'employment_status' => $employee->employment_status,
                'department' => $departmentName,
                'branch' => $branchName,
                'hire_date' => $hireDate->toDateString(),
                'probation_end_date' => $sixMonth->toDateString(),
                'early_eligibility_date' => $threeMonth->toDateString(),
                'service_length_label' => $serviceLengthLabel,
                'months_since_hire' => round($monthsSinceHire, 1),
                'days_remaining' => $daysToSixMonth,
                'days_remaining_label' => $daysRemainingLabel,
                'next_milestone' => $nextMilestone,
                'next_milestone_date' => $nextMilestoneDate,
                'recommended_action' => $recommendedAction,
                'status_label' => $statusLabel,
                'is_early_eligible' => $earlyEligible,
                'is_within_30_days' => $withinThirtyDayWindow,
                'indicator' => $statusTone,
                'indicator_label' => $statusLabel,
                'recommendation' => $recommendation ? [
                    'id' => $recommendation->id,
                    'status' => $recommendation->status,
                    'recommended_by_name' => $recommendation->recommendedBy?->display_name,
                    'recommended_at' => $recommendation->recommended_at?->toIso8601String(),
                ] : null,
                'required_actions' => $requiredActions,
                'auto_regularization_months' => $autoMonths,
                'early_regularization_months' => $earlyMonths,
                'actions' => [
                    'can_recommend_early' => ! $isHrAdmin
                        && $earlyEligible
                        && $actionsComplete
                        && ! $isOverdueSix
                        && ! $withinThirtyDayWindow
                        && ($recommendation === null || $recommendation->status === RegularizationRecommendation::STATUS_REJECTED),
                    'can_review_approve' => $isHrAdmin && $recommendation !== null && $recommendation->status === RegularizationRecommendation::STATUS_PENDING,
                ],
                '__sort' => $employee->employeeListingSortKey(),
            ];
        })->filter()->sortBy([
            ['is_within_30_days', 'desc'],
            ['days_remaining', 'asc'],
            ['__sort', 'asc'],
        ])->map(function (array $row) {
            unset($row['__sort']);

            return $row;
        })->values();

        return $rows->take($limit)->all();
    }

    private function expiringContracts(User $actor, int $limit = 5, int $daysAhead = 90): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = Carbon::now($tz)->startOfDay();
        $until = $today->copy()->addDays(max(1, $daysAhead));
        // Include recently-ended contracts so the widget can show “Overdue” tasks.
        $from = $today->copy()->subDays(30);
        $role = $this->hrRoleResolver->resolveForApprovalSubject($actor)->value;

        $query = User::query()
            ->activeRoster()
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', $from->toDateString())
            ->whereDate('contract_end_date', '<=', $until->toDateString())
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(employment_type, '')) IN ('contractual','project-based','project_based','project based')")
                    ->orWhereRaw("LOWER(COALESCE(employment_status, '')) IN ('contractual','project-based','project_based','project based')");
            })
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);

        $rows = $query->orderByLastName()->get()->map(function (User $employee) use ($today, $role) {
            $endDate = $employee->contract_end_date?->copy()->startOfDay();
            if (! $endDate) {
                return null;
            }
            $startDate = $employee->contract_start_date?->copy()->startOfDay();
            $daysRemaining = (int) $today->diffInDays($endDate, false);
            $departmentName = $employee->departmentRelation?->name ?? $employee->department ?? 'Unassigned';
            $branchName = $employee->branch?->name ?? $employee->departmentRelation?->branch?->name ?? null;
            $contractTypeLabel = null;
            $statusLabel = \App\Enums\EmploymentStatus::normalizeToCanonicalLabel($employee->employment_status);
            if (in_array($statusLabel, ['Contractual', 'Project-based'], true)) {
                $contractTypeLabel = $statusLabel;
            }
            if ($contractTypeLabel === null && $employee->employment_type) {
                $t = strtolower(trim((string) $employee->employment_type));
                $t = str_replace(['-', ' '], '_', $t);
                if ($t === 'contractual') {
                    $contractTypeLabel = 'Contractual';
                } elseif ($t === 'project_based' || $t === 'projectbased') {
                    $contractTypeLabel = 'Project-based';
                }
            }
            if ($contractTypeLabel === null) {
                // Fallback for legacy data.
                $contractTypeLabel = $employee->employment_type ? ucwords(str_replace(['_', '-'], ' ', (string) $employee->employment_type)) : 'Contractual';
            }

            $daysTone = 'neutral';
            if ($daysRemaining < 0) {
                $daysTone = 'red';
            } elseif ($daysRemaining < 30) {
                $daysTone = 'red';
            } elseif ($daysRemaining <= 60) {
                $daysTone = 'orange';
            }

            $daysRemainingLabel = $daysRemaining < 0
                ? abs($daysRemaining).' days overdue'
                : ($daysRemaining === 0 ? 'Expired today' : $daysRemaining.' days left');

            $recommendedAction = match (true) {
                $daysRemaining < 0 => 'Prepare Final Pay',
                $daysRemaining < 30 => 'Prepare Final Pay',
                $daysRemaining <= 60 => 'Review Extension',
                default => 'Renew Contract',
            };

            $buttonLabel = match ($role) {
                'admin_hr' => 'Renew Contract',
                default => 'Review Extension',
            };

            return [
                'id' => $employee->id,
                'name' => $employee->display_name,
                'formatted_name' => $employee->formatted_name,
                'profile_image_url' => $employee->profile_image_url,
                'contract_type' => $contractTypeLabel,
                'department' => $departmentName,
                'branch' => $branchName,
                'contract_start_date' => $startDate?->toDateString(),
                'contract_end_date' => $endDate->toDateString(),
                'days_remaining' => $daysRemaining,
                'days_remaining_label' => $daysRemainingLabel,
                'days_tone' => $daysTone,
                'recommended_action' => $recommendedAction,
                'actions' => [
                    'can_review_contract' => in_array($role, ['admin_hr', 'company_head', 'branch_head', 'department_head'], true),
                    'review_button_label' => $buttonLabel,
                ],
                '__sort' => $employee->employeeListingSortKey(),
            ];
        })->filter()->sortBy([
            ['days_remaining', 'asc'],
            ['__sort', 'asc'],
        ])->map(function (array $row) {
            unset($row['__sort']);

            return $row;
        })->values();

        return $rows->take($limit)->all();
    }

    private function workedMinutesFromLogs($logs): ?int
    {
        $total = 0;
        $clockIn = null;
        $sorted = collect($logs)
            ->sortBy(fn (AttendanceLog $log) => $this->attendanceLogPunchInstant($log)?->getTimestamp() ?? 0)
            ->values();
        foreach ($sorted as $log) {
            $punch = $this->attendanceLogPunchInstant($log);
            if ($punch === null) {
                continue;
            }
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $clockIn = $punch;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT && $clockIn) {
                $total += $clockIn->diffInMinutes($punch);
                $clockIn = null;
            }
        }

        return $clockIn === null ? $total : null;
    }

    /**
     * Build a per-day schedule array from a WorkingSchedule model.
     * Mirrors the same helper in AttendanceController so schedule resolution is consistent.
     */
    private function buildScheduleFromWorkingSchedule(?WorkingSchedule $workingSchedule): ?array
    {
        if (! $workingSchedule) {
            return null;
        }

        $restDays = $workingSchedule->rest_days ?? [];
        $dayConfig = [];

        foreach (self::DAY_KEYS as $key) {
            if (in_array($key, $restDays, true)) {
                $dayConfig[$key] = null;

                continue;
            }

            $dayConfig[$key] = [
                'in' => $workingSchedule->time_in,
                'out' => $workingSchedule->time_out,
                'break_start' => $workingSchedule->break_start,
                'break_end' => $workingSchedule->break_end,
                'grace_period_minutes' => $workingSchedule->grace_period_minutes,
                'early_timein_minutes' => $workingSchedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $workingSchedule->late_allowance_minutes,
                'early_timeout_minutes' => $workingSchedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $workingSchedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $dayConfig;
    }

    /**
     * Resolve the effective per-day schedule for a user.
     * Prefers the JSON `schedule` column; falls back to the `working_schedule_id` relationship
     * so that employees assigned via Admin → Schedule module are treated identically to
     * those with a manually-set JSON schedule.
     */
    private function resolveEffectiveSchedule(User $user): ?array
    {
        $schedule = $user->schedule;
        if (is_array($schedule) && $schedule !== []) {
            return $schedule;
        }

        if ($user->working_schedule_id !== null) {
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                return $derived;
            }
        }

        return null;
    }

    /**
     * Compute per-day attendance summary for dashboard cards.
     */
    private function computeDailyStats(
        Carbon $date,
        string $dayKey,
        $activeEmployees,
        array $activeEmployeeIds,
        int $undertimeThresholdMinutes,
        string $tz,
        Carbon $nowForCutoff,
        bool $respectAbsentCutoff = true
    ): array {
        $dateKey = $date->toDateString();

        // Employees on approved leave for this date (distinct users with approved leave covering the date).
        $leaveUserIds = LeaveRequest::query()
            ->whereIn('user_id', $activeEmployeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $dateKey)
            ->where('end_date', '>=', $dateKey)
            ->pluck('user_id')
            ->unique();
        $leaveSet = array_fill_keys($leaveUserIds->all(), true);

        // First clock-in per user for this date (for present + late + half day).
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($date, $tz);
        $firstClockInQuery = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN);
        $firstClockInQuery = $this->attendanceLogEffectivePunchWhereBetween($firstClockInQuery, $rangeStart, $rangeEnd);
        $firstClockIn = $firstClockInQuery
            ->whereIn('user_id', $activeEmployeeIds)
            ->select('user_id', DB::raw('MIN('.$this->attendanceLogEffectivePunchColumnSql().') as first_at'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Overlay approved corrections: employees with manual attendance are treated as present
        // even if they have no actual AttendanceLog record for this date.
        $approvedCorrections = AttendanceCorrection::query()
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->whereNotNull('time_in')
            ->whereIn('user_id', $activeEmployeeIds)
            ->get()
            ->keyBy('user_id');
        foreach ($approvedCorrections as $userId => $correction) {
            if (! $firstClockIn->has($userId)) {
                // Synthetic entry matching the shape used by the query above.
                $firstClockIn->put($userId, (object) ['first_at' => $correction->time_in]);
            }
        }

        $presentUserIds = $firstClockIn->keys()->all();
        $presentCount = count($presentUserIds);

        // Late / half-day: count everyone who clocked in today (from firstClockIn), using their schedule,
        // so Overview "Late Today" matches the actual late count in today's logs regardless of is_active.
        $lateCount = 0;
        $halfDay = 0;
        $clockedInUserIds = $firstClockIn->keys()->unique()->all();
        $usersWhoClockedIn = $clockedInUserIds !== []
            ? User::whereIn('id', $clockedInUserIds)->with('workingSchedule')->get(['id', 'schedule', 'working_schedule_id'])
            : collect();
        foreach ($usersWhoClockedIn as $user) {
            $firstAt = $firstClockIn->get($user->id)?->first_at;
            $schedule = $this->resolveEffectiveSchedule($user);
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            if ($firstAt && $daySchedule && ! empty($daySchedule['in'])) {
                // $first_at is MIN(COALESCE(verified_at, created_at)) stored in UTC.
                $firstAtCarbon = $firstAt instanceof Carbon ? $firstAt : Carbon::parse($firstAt, 'UTC')->timezone($tz);
                $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $firstAtCarbon);
                if ($clockInResult['status'] === 'late') {
                    $lateCount++;
                }
                if ($clockInResult['status'] === 'half_day') {
                    $halfDay++;
                }
            }
        }

        $logsByDayQuery = AttendanceLog::query()
            ->whereIn('user_id', $activeEmployeeIds);
        $logsByDayQuery = $this->attendanceLogEffectivePunchWhereBetween($logsByDayQuery, $rangeStart, $rangeEnd);
        $logsByUserForDay = $logsByDayQuery
            ->orderByRaw($this->attendanceLogEffectivePunchColumnSql())
            ->get()
            ->groupBy('user_id');

        $underTimeCount = 0;
        foreach ($activeEmployees as $user) {
            $firstAt = $firstClockIn->get($user->id)?->first_at;
            $schedule = $this->resolveEffectiveSchedule($user);
            $daySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            // Under time: required hours from schedule vs actual (clock_out - clock_in). If no clock_out yet, skip.
            if ($daySchedule && ! empty($daySchedule['in']) && ! empty($daySchedule['out'])) {
                $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes(
                    $dateKey,
                    $daySchedule,
                    $tz
                );
                $logsForDate = $logsByUserForDay->get($user->id, collect());
                $workedMinutes = $this->workedMinutesFromLogs($logsForDate);
                if ($workedMinutes !== null && $workedMinutes < $requiredMinutes - $undertimeThresholdMinutes) {
                    $underTimeCount++;
                }
            }
        }

        $expectedCount = 0;
        $absentCount = 0;

        foreach ($activeEmployees as $user) {
            $s = $this->resolveEffectiveSchedule($user);
            $daySched = is_array($s) && isset($s[$dayKey]) ? $s[$dayKey] : null;
            if (! $daySched || empty($daySched['in'])) {
                continue;
            }
            $expectedCount++;

            // Skip employees on approved leave from absence calculation.
            if (isset($leaveSet[$user->id])) {
                continue;
            }

            if ($logsByUserForDay->has($user->id) || $firstClockIn->has($user->id)) {
                continue;
            }

            // For "today", mark absent only after cutoff; for past days, always treat as past cutoff.
            if (! $respectAbsentCutoff || AttendanceStatusService::isPastAbsentCutoff($dateKey, $nowForCutoff)) {
                $absentCount++;
            }
        }

        return [
            'present_today' => $presentCount,
            'late_today' => $lateCount,
            'absent_today' => $absentCount,
            'on_leave' => $leaveUserIds->count(),
            'half_day' => $halfDay,
            'under_time' => $underTimeCount,
        ];
    }

    /**
     * Weekly attendance: last 7 days, count of distinct users who clocked in each day.
     */
    /**
     * @param  array<int, int>  $scopedUserIds
     */
    private function weeklyAttendanceOverview(Carbon $today, array $scopedUserIds): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($date, $tz);
            $presentQuery = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN);
            $presentQuery = $this->attendanceLogEffectivePunchWhereBetween($presentQuery, $rangeStart, $rangeEnd);
            if ($scopedUserIds !== []) {
                $presentQuery->whereIn('user_id', $scopedUserIds);
            } else {
                $presentQuery->whereRaw('1 = 0');
            }
            $presentCount = (int) $presentQuery
                ->selectRaw('COUNT(DISTINCT user_id) as c')
                ->value('c');
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('D d'),
                'present_count' => $presentCount,
            ];
        }

        return $days;
    }

    /** Years before/after the current year loaded for the dashboard holiday widget. */
    private const UPCOMING_HOLIDAYS_YEAR_SPAN = 1;

    /** Max rows returned in the dashboard payload (UI filters by month/year and shows fewer). */
    private const UPCOMING_HOLIDAYS_LIMIT = 500;

    /**
     * Active holidays from the Holiday Module for the dashboard widget.
     * Returns all active holidays in a multi-year window (past and future dates).
     * The UI filters by selected month/year; do not exclude past dates here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upcomingHolidays(User $actor, Carbon $now, int $limit = self::UPCOMING_HOLIDAYS_LIMIT): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = $now->copy()->timezone($tz)->startOfDay();
        $currentYear = (int) $today->format('Y');
        $context = $this->resolveHolidayScopeContext($actor);

        $years = [];
        for ($y = $currentYear - self::UPCOMING_HOLIDAYS_YEAR_SPAN; $y <= $currentYear + self::UPCOMING_HOLIDAYS_YEAR_SPAN; $y++) {
            $years[] = $y;
        }

        $rawRows = [];
        foreach ($years as $year) {
            foreach ($this->holidayCalendar->holidaysForYear($year) as $row) {
                $rawRows[] = $row;
            }
        }

        $dedupeResult = $this->dedupeUpcomingHolidayCandidates($rawRows);
        $candidates = $dedupeResult['rows'];
        $duplicateKeysDetected = $dedupeResult['duplicate_keys'];
        $duplicateIdsRemoved = $dedupeResult['removed_ids'];

        $candidateIds = array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $candidates)));
        $debugExcluded = [];
        $scopeExcludedIds = [];
        $rows = [];
        foreach ($candidates as $row) {
            $dateStr = (string) ($row['date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) !== 1) {
                $debugExcluded[] = $this->holidayDebugExclude($row, 'invalid_date');

                continue;
            }
            $date = Carbon::parse($dateStr, $tz)->startOfDay();
            $status = strtolower((string) ($row['status'] ?? 'active'));
            if ($status !== 'active') {
                $debugExcluded[] = $this->holidayDebugExclude($row, 'status_'.$status);

                continue;
            }
            if (! $this->holidayAppliesToActorScope($row, $context)) {
                $scopeExcludedIds[] = $row['id'] ?? null;
                $debugExcluded[] = $this->holidayDebugExclude($row, 'scope_not_applicable');

                continue;
            }

            $daysRemaining = (int) $today->diffInDays($date, false);
            $isToday = $date->isSameDay($today);
            $rows[] = $this->formatUpcomingHolidayRow($row, $date, $daysRemaining, $isToday);
        }

        usort($rows, function (array $a, array $b) {
            $dateCompare = strcmp((string) ($a['holiday_date'] ?? $a['date'] ?? ''), (string) ($b['holiday_date'] ?? $b['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $result = array_slice($rows, 0, max(1, $limit));

        Log::info('Admin dashboard upcoming holidays', [
            'logged_in_user_id' => (int) $actor->id,
            'user_role' => (string) ($actor->role ?? ''),
            'user_company_id' => $actor->getEffectiveCompanyId() ?? $actor->company_id,
            'user_branch_id' => $actor->branch_id,
            'user_department_id' => $actor->department_id,
            'actor_is_admin' => $actor->isAdmin(),
            'actor_hr_scoped' => $actor->hasScopedHrAdminAssignment(),
            'today' => $today->toDateString(),
            'years_loaded' => $years,
            'date_range_note' => 'full calendar years; UI filters by selected month',
            'scope_context' => $context,
            'raw_holiday_count' => count($rawRows),
            'raw_holiday_ids' => array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $rawRows))),
            'duplicate_keys_detected' => $duplicateKeysDetected,
            'duplicate_holiday_ids_removed' => $duplicateIdsRemoved,
            'candidate_count' => count($candidates),
            'holiday_ids_before_scope_filter' => $candidateIds,
            'holiday_ids_removed_by_scope' => array_values(array_filter($scopeExcludedIds)),
            'included_count' => count($rows),
            'returned_count' => count($result),
            'included_holiday_ids' => array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $rows))),
            'returned_holiday_ids' => array_values(array_filter(array_map(fn (array $r) => $r['id'] ?? null, $result))),
            'final_holiday_list' => array_map(fn (array $r) => [
                'id' => $r['id'] ?? null,
                'unique_key' => $r['unique_key'] ?? null,
                'holiday_name' => $r['holiday_name'] ?? $r['name'] ?? null,
                'holiday_date' => $r['holiday_date'] ?? $r['date'] ?? null,
                'multiplier_label' => $r['multiplier_label'] ?? null,
                'multiplier_source' => $r['multiplier_source'] ?? null,
            ], $result),
            'excluded_sample' => array_slice($debugExcluded, 0, 50),
        ]);

        return $result;
    }

    /**
     * Org scope for holiday visibility. Null = unrestricted (global HR admin).
     *
     * @return array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}|null
     */
    private function resolveHolidayScopeContext(User $actor): ?array
    {
        if ($actor->isAdmin() && ! $actor->hasScopedHrAdminAssignment()) {
            return null;
        }

        if ($actor->isAdmin() && $actor->hasScopedHrAdminAssignment()) {
            return $this->holidayScopeContextFromUserAssignment($actor);
        }

        $meta = $this->dataScopeService->getAttendanceScopeMeta($actor);
        if ($meta === null) {
            return null;
        }

        return match ($meta['kind'] ?? null) {
            'company' => $this->holidayScopeContextForCompanyIds(
                array_map('intval', $meta['company_ids'] ?? [])
            ),
            'branch' => $this->holidayScopeContextForBranchId(
                isset($meta['branch_id']) ? (int) $meta['branch_id'] : null
            ),
            'department' => $this->holidayScopeContextForDepartmentIds(
                array_map('intval', $meta['department_ids'] ?? [])
            ),
            default => [
                'company_ids' => [],
                'branch_ids' => [],
                'department_ids' => [],
            ],
        };
    }

    /**
     * @return array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}
     */
    private function holidayScopeContextFromUserAssignment(User $actor): array
    {
        if ($actor->department_id !== null) {
            return $this->holidayScopeContextForDepartmentIds([(int) $actor->department_id]);
        }
        if ($actor->branch_id !== null) {
            return $this->holidayScopeContextForBranchId((int) $actor->branch_id);
        }
        $effectiveCompanyId = $actor->getEffectiveCompanyId();
        if ($effectiveCompanyId !== null) {
            return $this->holidayScopeContextForCompanyIds([(int) $effectiveCompanyId]);
        }
        if ($actor->company_id !== null) {
            return $this->holidayScopeContextForCompanyIds([(int) $actor->company_id]);
        }

        return [
            'company_ids' => [],
            'branch_ids' => [],
            'department_ids' => [],
        ];
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}
     */
    private function holidayScopeContextForCompanyIds(array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_filter($companyIds, fn ($id) => $id > 0)));
        if ($companyIds === []) {
            return [
                'company_ids' => [],
                'branch_ids' => [],
                'department_ids' => [],
            ];
        }

        $branchIds = Branch::query()->whereIn('company_id', $companyIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $departmentIds = Department::query()
            ->whereHas('branch', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'company_ids' => $companyIds,
            'branch_ids' => $branchIds,
            'department_ids' => $departmentIds,
        ];
    }

    /**
     * @return array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}
     */
    private function holidayScopeContextForBranchId(?int $branchId): array
    {
        if ($branchId === null || $branchId <= 0) {
            return [
                'company_ids' => [],
                'branch_ids' => [],
                'department_ids' => [],
            ];
        }

        $branch = Branch::query()->where('id', $branchId)->first(['id', 'company_id']);
        $companyIds = $branch && $branch->company_id ? [(int) $branch->company_id] : [];
        $departmentIds = Department::query()
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'company_ids' => $companyIds,
            'branch_ids' => [$branchId],
            'department_ids' => $departmentIds,
        ];
    }

    /**
     * @param  array<int, int>  $departmentIds
     * @return array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}
     */
    private function holidayScopeContextForDepartmentIds(array $departmentIds): array
    {
        $departmentIds = array_values(array_unique(array_filter($departmentIds, fn ($id) => $id > 0)));
        if ($departmentIds === []) {
            return [
                'company_ids' => [],
                'branch_ids' => [],
                'department_ids' => [],
            ];
        }

        $departments = Department::query()
            ->whereIn('id', $departmentIds)
            ->get(['id', 'branch_id']);

        $branchIds = $departments->pluck('branch_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $companyIds = $branchIds === []
            ? []
            : Branch::query()->whereIn('id', $branchIds)->pluck('company_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return [
            'company_ids' => $companyIds,
            'branch_ids' => $branchIds,
            'department_ids' => $departmentIds,
        ];
    }

    /**
     * Whether a holiday row from the Holiday Module applies to the dashboard actor's org scope.
     * Uses the same targeting rules as payroll ({@see HolidayCalendarService::rowAppliesToTarget}).
     *
     * @param  array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}|null  $context
     */
    private function holidayAppliesToActorScope(array $row, ?array $context): bool
    {
        if ($context === null) {
            return true;
        }

        $scope = strtolower((string) ($row['scope'] ?? 'nationwide'));
        if (in_array($scope, ['nationwide', 'regional'], true)) {
            return true;
        }

        $companyIds = array_values(array_unique(array_map('intval', $context['company_ids'] ?? [])));
        $branchIds = array_values(array_unique(array_map('intval', $context['branch_ids'] ?? [])));
        $departmentIds = array_values(array_unique(array_map('intval', $context['department_ids'] ?? [])));

        foreach ($companyIds as $companyId) {
            if ($companyId > 0 && $this->holidayCalendar->rowAppliesToTarget($row, $companyId, null, null, null)) {
                return true;
            }
        }

        foreach ($branchIds as $branchId) {
            if ($branchId > 0 && $this->holidayCalendar->rowAppliesToTarget($row, null, $branchId, null, null)) {
                return true;
            }
        }

        foreach ($departmentIds as $departmentId) {
            if ($departmentId > 0 && $this->holidayCalendar->rowAppliesToTarget($row, null, null, $departmentId, null)) {
                return true;
            }
        }

        $rowCompanyId = isset($row['company_id']) ? (int) $row['company_id'] : 0;
        $rowBranchId = isset($row['branch_id']) ? (int) $row['branch_id'] : 0;
        $rowDepartmentId = isset($row['department_id']) ? (int) $row['department_id'] : 0;
        if ($rowCompanyId > 0 && in_array($rowCompanyId, $companyIds, true)) {
            return true;
        }
        if ($rowBranchId > 0 && in_array($rowBranchId, $branchIds, true)) {
            return true;
        }
        if ($rowDepartmentId > 0 && in_array($rowDepartmentId, $departmentIds, true)) {
            return true;
        }

        $coverageType = $row['coverage_type'] ?? null;
        $coverageIds = is_array($row['coverage_ids'] ?? null) ? $row['coverage_ids'] : [];
        if (is_string($coverageType) && $coverageType !== '' && $coverageIds !== []) {
            $coverageIds = array_map('intval', $coverageIds);

            return match ($coverageType) {
                'company' => (bool) array_intersect($coverageIds, $companyIds),
                'branches' => (bool) array_intersect($coverageIds, $branchIds),
                'departments' => (bool) array_intersect($coverageIds, $departmentIds),
                'employees' => $this->holidayCoverageIncludesScopedEmployee($coverageIds, $context),
                default => false,
            };
        }

        return false;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @param  array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}  $context
     */
    private function holidayCoverageIncludesScopedEmployee(array $employeeIds, array $context): bool
    {
        if ($employeeIds === []) {
            return false;
        }

        $query = User::query()->activeRoster()->whereIn('id', $employeeIds);
        $this->restrictUserQueryToHolidayContext($query, $context);

        return $query->exists();
    }

    /**
     * @param  Builder<User>  $query
     * @param  array{company_ids: array<int, int>, branch_ids: array<int, int>, department_ids: array<int, int>}  $context
     */
    private function restrictUserQueryToHolidayContext(Builder $query, array $context): void
    {
        $companyIds = $context['company_ids'] ?? [];
        $branchIds = $context['branch_ids'] ?? [];
        $departmentIds = $context['department_ids'] ?? [];

        $query->where(function (Builder $q) use ($companyIds, $branchIds, $departmentIds) {
            $applied = false;
            if ($departmentIds !== []) {
                $q->whereIn('department_id', $departmentIds);
                $applied = true;
            }
            if ($branchIds !== []) {
                $method = $applied ? 'orWhere' : 'where';
                $q->{$method}(function (Builder $sub) use ($branchIds) {
                    $sub->whereIn('branch_id', $branchIds)
                        ->orWhereHas('departmentRelation', fn (Builder $d) => $d->whereIn('branch_id', $branchIds));
                });
                $applied = true;
            }
            if ($companyIds !== []) {
                $method = $applied ? 'orWhere' : 'where';
                $q->{$method}(function (Builder $sub) use ($companyIds) {
                    $sub->whereIn('company_id', $companyIds)
                        ->orWhereHas('branch', fn (Builder $b) => $b->whereIn('company_id', $companyIds))
                        ->orWhereHas('departmentRelation.branch', fn (Builder $b) => $b->whereIn('company_id', $companyIds));
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function holidayDebugExclude(array $row, string $reason): array
    {
        return [
            'id' => $row['id'] ?? null,
            'name' => $row['name'] ?? null,
            'date' => $row['date'] ?? null,
            'status' => $row['status'] ?? null,
            'scope' => $row['scope'] ?? null,
            'scope_type' => $row['coverage_type'] ?? null,
            'company_id' => $row['company_id'] ?? null,
            'branch_id' => $row['branch_id'] ?? null,
            'department_id' => $row['department_id'] ?? null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatUpcomingHolidayRow(array $row, Carbon $date, int $daysRemaining, bool $isToday): array
    {
        $type = strtolower((string) ($row['type'] ?? 'special'));
        $scope = strtolower((string) ($row['scope'] ?? 'nationwide'));
        $coverageType = $row['coverage_type'] ?? null;
        $name = TextSanitizer::clean((string) ($row['name'] ?? ''), 'Holiday') ?? 'Holiday';
        $companyName = TextSanitizer::clean($row['company_name'] ?? null);
        $branchName = TextSanitizer::clean($row['branch_name'] ?? null);
        $departmentName = TextSanitizer::clean($row['department_name'] ?? null);
        $scopeLabel = TextSanitizer::clean($this->holidayScopeTargetLabel($row), 'All employees') ?? 'All employees';
        $scopeTypeLabel = $this->holidayScopeTypeLabel($row);
        $dateKey = $date->format('Y-m-d');
        $multiplier = $this->resolveUpcomingHolidayMultiplier($row);

        return [
            'id' => $row['id'] ?? null,
            'unique_key' => $this->upcomingHolidayUniqueKey($row),
            'name' => $name,
            'holiday_name' => $name,
            'date' => $dateKey,
            'holiday_date' => $dateKey,
            'day_name' => $date->format('l'),
            'type' => $type,
            'holiday_type' => $type,
            'type_label' => $this->holidayTypeLabel($type),
            'multiplier' => $multiplier['multiplier'],
            'pay_rate_multiplier' => $multiplier['pay_rate_multiplier'],
            'multiplier_label' => $multiplier['multiplier_label'],
            'multiplier_source' => $multiplier['multiplier_source'],
            'scope' => $scope,
            'scope_type' => $scopeTypeLabel,
            'scope_label' => $scopeLabel,
            'company_id' => isset($row['company_id']) ? (int) $row['company_id'] : null,
            'company_name' => $companyName,
            'branch_id' => isset($row['branch_id']) ? (int) $row['branch_id'] : null,
            'branch_name' => $branchName,
            'branch' => $branchName,
            'location_id' => isset($row['branch_id']) ? (int) $row['branch_id'] : null,
            'location_name' => $branchName,
            'location' => $branchName,
            'department_id' => isset($row['department_id']) ? (int) $row['department_id'] : null,
            'department_name' => $departmentName,
            'department' => $departmentName,
            'company' => $companyName,
            'days_remaining' => $daysRemaining,
            'days_remaining_label' => $this->holidayDaysRemainingLabel($daysRemaining, $isToday),
            'is_today' => $isToday,
            'status' => strtolower((string) ($row['status'] ?? 'active')),
            'description' => TextSanitizer::clean($row['description'] ?? null),
            'is_recurring' => (bool) ($row['is_recurring'] ?? false),
            'is_swap' => (bool) ($row['is_swap'] ?? false),
            'source' => (string) ($row['source'] ?? 'custom'),
            'coverage_type' => $coverageType,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, duplicate_keys: list<string>, removed_ids: list<int|string|null>}
     */
    private function dedupeUpcomingHolidayCandidates(array $rows): array
    {
        $byKey = [];
        $duplicateKeys = [];
        $removedIds = [];

        foreach ($rows as $row) {
            $key = $this->upcomingHolidayUniqueKey($row);
            if (! isset($byKey[$key])) {
                $byKey[$key] = $row;

                continue;
            }

            $duplicateKeys[] = $key;
            $existing = $byKey[$key];
            if ($this->upcomingHolidaySourceRank($row) > $this->upcomingHolidaySourceRank($existing)) {
                if (isset($existing['id'])) {
                    $removedIds[] = $existing['id'];
                }
                $byKey[$key] = $row;
            } elseif (isset($row['id'])) {
                $removedIds[] = $row['id'];
            }
        }

        $deduped = array_values($byKey);
        usort($deduped, function (array $a, array $b) {
            $dateCompare = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return [
            'rows' => $deduped,
            'duplicate_keys' => array_values(array_unique($duplicateKeys)),
            'removed_ids' => $removedIds,
        ];
    }

    /**
     * Stable identity for dashboard holiday rows (ignores DB id so seeded + module rows collapse).
     */
    private function upcomingHolidayUniqueKey(array $row): string
    {
        $name = mb_strtolower(trim((string) ($row['name'] ?? '')));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return implode('|', [
            (string) ($row['date'] ?? ''),
            $name,
            strtolower((string) ($row['type'] ?? '')),
            strtolower((string) ($row['scope'] ?? 'nationwide')),
            (string) ($row['coverage_type'] ?? ''),
            json_encode($row['coverage_ids'] ?? [], JSON_UNESCAPED_UNICODE),
            (string) ((int) ($row['company_id'] ?? 0)),
            (string) ((int) ($row['branch_id'] ?? 0)),
            (string) ((int) ($row['department_id'] ?? 0)),
            (string) ((int) ($row['employee_id'] ?? 0)),
        ]);
    }

    private function upcomingHolidaySourceRank(array $row): int
    {
        return match (strtolower((string) ($row['source'] ?? 'custom'))) {
            'custom' => 30,
            'recurring' => 20,
            'seeded' => 10,
            default => 0,
        };
    }

    /**
     * @return array{multiplier: ?float, pay_rate_multiplier: ?float, multiplier_label: string, multiplier_source: string}
     */
    private function resolveUpcomingHolidayMultiplier(array $row): array
    {
        foreach (['pay_rate_multiplier', 'multiplier', 'premium_multiplier'] as $field) {
            if (isset($row[$field]) && is_numeric($row[$field])) {
                $value = (float) $row[$field];
                if ($value > 0) {
                    return [
                        'multiplier' => $value,
                        'pay_rate_multiplier' => $value,
                        'multiplier_label' => $this->formatHolidayMultiplierLabel($value),
                        'multiplier_source' => 'holiday_module',
                    ];
                }
            }
        }

        $type = strtolower((string) ($row['type'] ?? ''));
        $fallback = match ($type) {
            'regular' => 2.0,
            'special', 'special_non_working' => 1.3,
            default => null,
        };

        if ($fallback !== null) {
            return [
                'multiplier' => $fallback,
                'pay_rate_multiplier' => $fallback,
                'multiplier_label' => $this->formatHolidayMultiplierLabel($fallback),
                'multiplier_source' => 'fallback',
            ];
        }

        return [
            'multiplier' => null,
            'pay_rate_multiplier' => null,
            'multiplier_label' => '-',
            'multiplier_source' => 'none',
        ];
    }

    private function formatHolidayMultiplierLabel(float $multiplier): string
    {
        if ($multiplier <= 0) {
            return '-';
        }

        $pct = $multiplier <= 3
            ? (int) round($multiplier * 100)
            : (int) round($multiplier);

        return $pct.'%';
    }

    private function holidayDaysRemainingLabel(int $daysRemaining, bool $isToday): string
    {
        if ($isToday) {
            return 'Today';
        }
        if ($daysRemaining < 0) {
            $ago = abs($daysRemaining);

            return $ago === 1 ? '1 day ago' : $ago.' days ago';
        }
        if ($daysRemaining === 1) {
            return 'In 1 day';
        }

        return 'In '.$daysRemaining.' days';
    }

    private function holidayTypeLabel(string $type): string
    {
        return match ($type) {
            'regular' => 'Regular Holiday',
            'special', 'special_non_working' => 'Special Non-Working Holiday',
            'special_working' => 'Special Working Day',
            'company' => 'Company Event',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function holidayScopeTypeLabel(array $row): string
    {
        $coverageType = $row['coverage_type'] ?? null;
        if (is_string($coverageType) && $coverageType !== '') {
            return match ($coverageType) {
                'company' => 'Company',
                'branches' => 'Branch',
                'departments' => 'Department',
                'employees' => 'Employee',
                default => 'Scoped',
            };
        }

        return match (strtolower((string) ($row['scope'] ?? 'nationwide'))) {
            'company' => 'Company',
            'branch' => 'Branch-specific',
            'department' => 'Department-specific',
            'employee' => 'Employee-specific',
            'regional' => 'Regional',
            'nationwide' => 'Nationwide',
            default => 'Nationwide',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function holidayScopeTargetLabel(array $row): string
    {
        $coverageType = $row['coverage_type'] ?? null;
        if (is_string($coverageType) && $coverageType !== '' && ! empty($row['coverage_ids'])) {
            return match ($coverageType) {
                'company' => $row['company_name'] ?? 'Selected companies',
                'branches' => $row['branch_name'] ?? 'Selected branches',
                'departments' => $row['department_name'] ?? 'Selected departments',
                'employees' => $row['employee_name'] ?? $row['employee_code'] ?? 'Selected employees',
                default => $this->holidayScopeTypeLabel($row),
            };
        }

        $scope = strtolower((string) ($row['scope'] ?? 'nationwide'));
        if ($scope === 'company') {
            $name = TextSanitizer::clean($row['company_name'] ?? null);

            return $name !== null && $name !== ''
                ? $name
                : 'Company';
        }
        if ($scope === 'branch') {
            return (string) ($row['branch_name'] ?? 'Branch');
        }
        if ($scope === 'department') {
            return (string) ($row['department_name'] ?? 'Department');
        }
        if ($scope === 'employee') {
            return (string) ($row['employee_name'] ?? $row['employee_code'] ?? 'Employee');
        }
        if ($scope === 'regional') {
            return 'Selected regions';
        }

        return 'All employees';
    }

    private function lateFrequencyChart(): array
    {
        $usersCache = [];
        $days = [];
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        for ($i = 29; $i >= 0; $i--) {
            $date = today($tz)->subDays($i);
            $dayKey = self::DAY_KEYS[(int) $date->format('w')];
            [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($date, $tz);
            $fq = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN);
            $fq = $this->attendanceLogEffectivePunchWhereBetween($fq, $rangeStart, $rangeEnd);
            $firstClockIns = $fq
                ->select('user_id', DB::raw('MIN('.$this->attendanceLogEffectivePunchColumnSql().') as first_at'))
                ->groupBy('user_id')
                ->get();
            $dayUserIds = $firstClockIns->pluck('user_id')->unique()->map(fn ($id) => (int) $id)->all();
            $idsToLoad = array_values(array_diff($dayUserIds, array_keys($usersCache)));
            if ($idsToLoad !== []) {
                $loaded = User::query()
                    ->whereIn('id', $idsToLoad)
                    ->with('workingSchedule')
                    ->get();
                foreach ($loaded as $loadedUser) {
                    $usersCache[(int) $loadedUser->id] = $loadedUser;
                }
            }
            $lateCount = 0;
            foreach ($firstClockIns as $row) {
                $user = $usersCache[(int) $row->user_id] ?? null;
                $effectiveSched = $user ? $this->resolveEffectiveSchedule($user) : null;
                if (! $effectiveSched || ! isset($effectiveSched[$dayKey]['in'])) {
                    continue;
                }
                $todaySchedule = $effectiveSched[$dayKey];
                $dateKey = $date->format('Y-m-d');
                // first_at is MIN(COALESCE(verified_at, created_at)) stored in UTC.
                $firstAtCarbon = Carbon::parse($row->first_at, 'UTC');
                $result = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $firstAtCarbon);
                if ($result['status'] === 'late') {
                    $lateCount++;
                }
            }
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $date->format('M d'),
                'late_count' => $lateCount,
            ];
        }

        return $days;
    }

    /**
     * Half-day leave summary for today: AM and PM approved half-day leaves.
     */
    /**
     * @param  array<int, int>  $scopedActiveUserIds
     */
    private function halfDaySummary(Carbon $today, array $scopedActiveUserIds): array
    {
        $dateKey = $today->toDateString();
        $amQuery = LeaveRequest::query()
            ->where('type', 'half_day')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('half_type', 'am')
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey);
        $pmQuery = LeaveRequest::query()
            ->where('type', 'half_day')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('half_type', 'pm')
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey);
        if ($scopedActiveUserIds !== []) {
            $amQuery->whereIn('user_id', $scopedActiveUserIds);
            $pmQuery->whereIn('user_id', $scopedActiveUserIds);
        } else {
            $amQuery->whereRaw('1 = 0');
            $pmQuery->whereRaw('1 = 0');
        }
        $amCount = $amQuery->count();
        $pmCount = $pmQuery->count();
        $totalActive = count($scopedActiveUserIds);

        return [
            'am_today' => $amCount,
            'pm_today' => $pmCount,
            'total_today' => $amCount + $pmCount,
            'total_workforce' => $totalActive,
        ];
    }

    /**
     * Approved leave requests that overlap today's date.
     *
     * @param  array<int, int>  $scopedActiveUserIds
     * @return array<int, array<string, mixed>>
     */
    private function todayLeaves(Carbon $today, array $scopedActiveUserIds): array
    {
        $dateKey = $today->toDateString();
        $query = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey);
        if ($scopedActiveUserIds !== []) {
            $query->whereIn('user_id', $scopedActiveUserIds);
        } else {
            $query->whereRaw('1 = 0');
        }
        $rows = $query
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'department', 'department_id', 'position', 'profile_image')
                    ->with(['departmentRelation:id,name']);
            }])
            ->orderBy('start_date')
            ->orderBy('user_id')
            ->get();

        $items = [];
        foreach ($rows as $leave) {
            $user = $leave->user;
            if (! $user) {
                continue;
            }

            $rawType = is_string($leave->type) ? trim($leave->type) : '';
            $leaveType = $rawType !== '' ? ucwords(str_replace('_', ' ', $rawType)) : 'Leave';
            $start = $leave->start_date;
            $end = $leave->end_date;
            $days = ($start && $end) ? ((int) $start->diffInDays($end) + 1) : 1;
            $durationLabel = $rawType === 'half_day'
                ? 'Half day'
                : ($days <= 1 ? 'Full day' : $days.' days');

            $items[] = [
                'leave_request_id' => $leave->id,
                'user_id' => $user->id,
                'employee_name' => $user->display_name ?: '—',
                'employee_sort_key' => $user->employeeListingSortKey(),
                'leave_type' => $leaveType,
                'duration_label' => $durationLabel,
                'department' => $user->departmentRelation?->name ?? $user->department ?? null,
                'position' => $user->position ?? null,
                'profile_image' => $user->profile_image,
                'profile_image_url' => $user->profile_image_url,
                'start_date' => $start?->toDateString(),
                'end_date' => $end?->toDateString(),
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp((string) ($a['employee_sort_key'] ?? ''), (string) ($b['employee_sort_key'] ?? '')));

        return $items;
    }

    /**
     * Detailed list of employees on half-day leave for a given date.
     * Used by the Half-Day Summary card drill-down modal.
     */
    public function halfDayList(Request $request): JsonResponse
    {
        $actor = $request->user();
        $scopedIds = $this->scopedEmployeeIds($actor, false);
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'), $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();
        $dateKey = $date->toDateString();
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($date, $tz);

        $leaveRequestsQuery = LeaveRequest::query()
            ->where('type', 'half_day')
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey);
        if ($scopedIds !== []) {
            $leaveRequestsQuery->whereIn('user_id', $scopedIds);
        } else {
            $leaveRequestsQuery->whereRaw('1 = 0');
        }
        $leaveRequests = $leaveRequestsQuery
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'department', 'department_id', 'company_id', 'branch_id', 'profile_image')
                    ->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name']);
            }])
            ->orderBy('half_type')
            ->orderBy('user_id')
            ->get();

        $userIds = $leaveRequests->pluck('user_id')->unique()->all();
        $fdc = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereIn('user_id', $userIds);
        $fdc = $this->attendanceLogEffectivePunchWhereBetween($fdc, $rangeStart, $rangeEnd);
        $firstClockIns = $fdc
            ->select('user_id', DB::raw('MIN('.$this->attendanceLogEffectivePunchColumnSql().') as first_at'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $approvedCorrections = AttendanceCorrection::query()
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->whereNotNull('time_in')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $rows = [];
        foreach ($leaveRequests as $lr) {
            $user = $lr->user;
            if (! $user) {
                continue;
            }
            $correction = $approvedCorrections->get($user->id);
            $firstLog = $firstClockIns->get($user->id);
            $timeIn = $correction?->time_in ?? ($firstLog?->first_at ? Carbon::parse($firstLog->first_at, 'UTC')->timezone($tz) : null);
            $branchName = $user->branch?->name ?? $user->departmentRelation?->branch?->name ?? $user->department ?? '—';

            $rows[] = [
                'user_id' => $user->id,
                'employee_name' => $user->display_name ?: '—',
                'employee_sort_key' => $user->employeeListingSortKey(),
                'branch' => $branchName,
                'time_in' => $timeIn ? $timeIn->format('H:i:s') : null,
                'half_type' => $lr->half_type ?? '—',
                'notes' => $lr->notes ? trim($lr->notes) : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) ($a['employee_sort_key'] ?? ''), (string) ($b['employee_sort_key'] ?? '')));

        return response()->json([
            'date' => $dateKey,
            'am_count' => $leaveRequests->where('half_type', 'am')->count(),
            'pm_count' => $leaveRequests->where('half_type', 'pm')->count(),
            'total' => count($rows),
            'employees' => $rows,
        ]);
    }

    private function departmentAttendanceDistribution(Carbon $today, User $actor): array
    {
        // Active employees in scope, grouped by department for accurate headcount.
        $activeQuery = User::activeRoster()
            ->with(['departmentRelation:id,name,branch_id']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $activeQuery);
        $activeEmployees = $activeQuery->orderByLastName()->get();

        // Set of users who actually clocked in today (present).
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($today, $tz);
        $presentQuery = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN);
        $presentQuery = $this->attendanceLogEffectivePunchWhereBetween($presentQuery, $rangeStart, $rangeEnd);
        if ($activeEmployees->isNotEmpty()) {
            $presentQuery->whereIn('user_id', $activeEmployees->pluck('id')->all());
        } else {
            $presentQuery->whereRaw('1 = 0');
        }
        $presentUserIds = $presentQuery
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();
        $presentSet = array_fill_keys($presentUserIds, true);

        $byDept = $activeEmployees->groupBy(function (User $u) {
            $name = $u->departmentRelation?->name ?? $u->department ?? 'Unassigned';

            return is_string($name) && $name !== '' ? $name : 'Unassigned';
        });

        $rows = [];
        foreach ($byDept as $name => $users) {
            $headcount = $users->count();
            $presentCount = $users->filter(function (User $u) use ($presentSet) {
                return isset($presentSet[$u->id]);
            })->count();

            $rows[] = [
                'department' => $name,
                'present' => $presentCount,
                'headcount' => $headcount,
                // Backward-compat: keep "count" equal to present employees.
                'count' => $presentCount,
            ];
        }

        // Sort by present employees (desc) so top-performing departments are highlighted first.
        usort($rows, function (array $a, array $b): int {
            return $b['present'] <=> $a['present'];
        });

        return $rows;
    }

    /**
     * Company-level attendance rows for a given date. Used by dashboard index and companyAttendance endpoint.
     *
     * @param  array<int>|null  $companyIds  Filter to these company IDs, or null for all.
     * @return array{array{company_id: int|null, company: string, present: int, late: int, absent: int, on_leave: int, headcount: int, present_pct: float, absent_pct: float, late_pct: float, on_leave_pct: float}}
     */
    private function companyAttendanceDistribution(Carbon $date, ?array $companyIds, User $actor): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $companies = Company::orderBy('name')->get(['id', 'name', 'logo'])->keyBy('id');
        $activeEmployeesQuery = User::activeRoster()
            ->with(['workingSchedule', 'companyHeadships:id,company_head_id', 'company:id,name', 'branch:id,company_id', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $activeEmployeesQuery);
        $activeEmployees = $activeEmployeesQuery->orderByLastName()->get();

        // Ensure company filter respects the actor's scope
        if ($companyIds !== null && $companyIds !== []) {
            $scopedCompanyIds = $activeEmployees->map(fn (User $u) => $u->getEffectiveCompanyId())->filter()->unique()->values()->all();
            $companyIds = array_intersect($companyIds, $scopedCompanyIds);
        }

        // Filter to selected companies (already validated against scope above)
        if ($companyIds !== null && $companyIds !== []) {
            $activeEmployees = $activeEmployees->filter(function (User $u) use ($companyIds) {
                $cid = $u->getEffectiveCompanyId();

                return $cid !== null && in_array($cid, $companyIds, true);
            });
        }

        $byCompany = $activeEmployees->groupBy(function (User $u) {
            $cid = $u->getEffectiveCompanyId();

            return $cid ?? 0;
        });

        $dayKey = self::DAY_KEYS[(int) $date->format('w')];
        $dateKey = $date->toDateString();
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($date, $tz);
        $nowForCutoff = $date->isToday() ? Carbon::now($tz) : $date->copy()->endOfDay();
        $activeEmployeeIds = $activeEmployees->pluck('id')->all();

        $leaveUserIds = LeaveRequest::query()
            ->whereIn('user_id', $activeEmployeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->pluck('user_id')
            ->unique();
        $leaveSet = array_fill_keys($leaveUserIds->all(), true);

        $fcd = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereIn('user_id', $activeEmployeeIds);
        $fcd = $this->attendanceLogEffectivePunchWhereBetween($fcd, $rangeStart, $rangeEnd);
        $firstClockIn = $fcd
            ->select('user_id', DB::raw('MIN('.$this->attendanceLogEffectivePunchColumnSql().') as first_at'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $approvedCorrections = AttendanceCorrection::query()
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->whereNotNull('time_in')
            ->whereIn('user_id', $activeEmployeeIds)
            ->get()
            ->keyBy('user_id');
        foreach ($approvedCorrections as $userId => $correction) {
            if (! $firstClockIn->has($userId)) {
                $firstClockIn->put($userId, (object) ['first_at' => $correction->time_in]);
            }
        }

        $rows = [];
        foreach ($byCompany as $companyId => $users) {
            $headcount = $users->count();
            if ($headcount === 0) {
                continue;
            }
            $presentCount = 0;
            $lateCount = 0;
            $absentCount = 0;
            $onLeaveCount = 0;

            foreach ($users as $user) {
                if (isset($leaveSet[$user->id])) {
                    $onLeaveCount++;

                    continue;
                }
                $firstAt = $firstClockIn->get($user->id)?->first_at;
                if ($firstAt) {
                    $presentCount++;
                    $schedule = $this->resolveEffectiveSchedule($user);
                    $daySched = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
                    if ($daySched && ! empty($daySched['in'])) {
                        $firstAtCarbon = $firstAt instanceof Carbon ? $firstAt : Carbon::parse($firstAt, 'UTC')->timezone($tz);
                        $result = AttendanceStatusService::getClockInStatus($daySched, $dateKey, $firstAtCarbon);
                        if ($result['status'] === 'late') {
                            $lateCount++;
                        }
                    }
                } else {
                    $schedule = $this->resolveEffectiveSchedule($user);
                    $daySched = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
                    if ($daySched && ! empty($daySched['in'])) {
                        if (AttendanceStatusService::isPastAbsentCutoff($dateKey, $nowForCutoff)) {
                            $absentCount++;
                        }
                    }
                }
            }

            $companyModel = $companyId > 0 && $companies->has($companyId) ? $companies->get($companyId) : null;
            $companyName = $companyModel?->name ?? 'Unassigned';
            $companyLogo = $companyModel?->logo ?? null;
            $companyLogoUrl = $companyLogo ? $this->companyLogoUrl($companyLogo) : null;

            $rows[] = [
                'company_id' => $companyId > 0 ? $companyId : null,
                'company' => $companyName,
                'logo_url' => $companyLogoUrl,
                'present' => $presentCount,
                'late' => $lateCount,
                'absent' => $absentCount,
                'on_leave' => $onLeaveCount,
                'headcount' => $headcount,
                'present_pct' => $headcount > 0 ? round(100 * $presentCount / $headcount, 1) : 0,
                'absent_pct' => $headcount > 0 ? round(100 * $absentCount / $headcount, 1) : 0,
                'late_pct' => $headcount > 0 ? round(100 * $lateCount / $headcount, 1) : 0,
                'on_leave_pct' => $headcount > 0 ? round(100 * $onLeaveCount / $headcount, 1) : 0,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return $b['present'] <=> $a['present'];
        });

        return $rows;
    }

    /**
     * Company-level attendance comparison for the Company Attendance Comparison chart.
     * Supports date range and company filter. When from_date = to_date, single-day stats.
     * When range spans multiple days, aggregates present/late/absent/on_leave across all days.
     */
    public function companyAttendance(Request $request): JsonResponse
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        $today = Carbon::now($tz)->startOfDay();
        $fromDate = $request->filled('from_date')
            ? Carbon::parse($request->input('from_date'), $tz)->startOfDay()
            : $today->copy();
        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->input('to_date'), $tz)->startOfDay()
            : $fromDate->copy();
        if ($toDate->lessThan($fromDate)) {
            $toDate = $fromDate->copy();
        }
        $maxDays = 366;
        if ($fromDate->diffInDays($toDate) > $maxDays) {
            $toDate = $fromDate->copy()->addDays($maxDays);
        }
        $companyIdsParam = $request->input('company_ids');
        $companyIds = null;
        if ($companyIdsParam !== null && $companyIdsParam !== '') {
            $arr = is_array($companyIdsParam) ? $companyIdsParam : [$companyIdsParam];
            $companyIds = array_values(array_filter(array_map('intval', $arr)));
        }

        $companies = Company::orderBy('name')->get(['id', 'name']);
        $actor = $request->user();
        $rows = $fromDate->equalTo($toDate)
            ? $this->companyAttendanceDistribution($fromDate, $companyIds, $actor)
            : $this->companyAttendanceDistributionForRange($fromDate, $toDate, $companyIds, $actor);

        return response()->json([
            'date' => $fromDate->toDateString(),
            'date_to' => $toDate->toDateString(),
            'companies' => $rows,
            'companies_list' => $companies->values()->all(),
        ]);
    }

    /**
     * Aggregate company attendance across a date range. Sums present/late/absent/on_leave per company.
     * Percentages use expected work-days (headcount * num_days) for range.
     */
    private function companyAttendanceDistributionForRange(Carbon $fromDate, Carbon $toDate, ?array $companyIds, User $actor): array
    {
        $dayRows = [];
        $date = $fromDate->copy();
        while ($date->lessThanOrEqualTo($toDate)) {
            $dayRows[] = $this->companyAttendanceDistribution($date, $companyIds, $actor);
            $date->addDay();
        }
        if ($dayRows === []) {
            return [];
        }
        $numDays = (int) $fromDate->diffInDays($toDate) + 1;
        $aggregated = [];
        foreach ($dayRows as $rows) {
            foreach ($rows as $row) {
                $key = $row['company_id'] ?? 'u';
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'company_id' => $row['company_id'],
                        'company' => $row['company'],
                        'logo_url' => $row['logo_url'] ?? null,
                        'present' => 0,
                        'late' => 0,
                        'absent' => 0,
                        'on_leave' => 0,
                        'headcount' => $row['headcount'],
                    ];
                }
                $aggregated[$key]['present'] += $row['present'];
                $aggregated[$key]['late'] += $row['late'];
                $aggregated[$key]['absent'] += $row['absent'];
                $aggregated[$key]['on_leave'] += $row['on_leave'];
            }
        }
        $result = [];
        foreach ($aggregated as $row) {
            $headcount = $row['headcount'];
            $expectedDays = $headcount * $numDays;
            $result[] = [
                'company_id' => $row['company_id'],
                'company' => $row['company'],
                'logo_url' => $row['logo_url'] ?? null,
                'present' => $row['present'],
                'late' => $row['late'],
                'absent' => $row['absent'],
                'on_leave' => $row['on_leave'],
                'headcount' => $headcount,
                'present_pct' => $expectedDays > 0 ? round(100 * $row['present'] / $expectedDays, 1) : 0,
                'absent_pct' => $expectedDays > 0 ? round(100 * $row['absent'] / $expectedDays, 1) : 0,
                'late_pct' => $expectedDays > 0 ? round(100 * $row['late'] / $expectedDays, 1) : 0,
                'on_leave_pct' => $expectedDays > 0 ? round(100 * $row['on_leave'] / $expectedDays, 1) : 0,
            ];
        }
        usort($result, fn (array $a, array $b) => $b['present'] <=> $a['present']);

        return $result;
    }

    /**
     * Build public URL for a department logo path (storage path). Returns null if path empty or file missing.
     */
    private function departmentLogoUrl(?string $path): ?string
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
        if (! Storage::disk('public')->exists($normalized)) {
            return null;
        }
        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $s) => rawurlencode($s), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    /**
     * Build public URL for a company logo path.
     */
    private function companyLogoUrl(?string $path): ?string
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
        $segments = explode('/', trim($normalized, '/'));
        $encoded = array_map(static fn (string $s) => rawurlencode($s), $segments);

        return url('/api/media/public/'.implode('/', $encoded));
    }

    /**
     * @param  array<int, int>  $scopedActiveUserIds
     */
    private function dashboardAttendanceBaseRow(User $user): array
    {
        $company = $user->companyHeadships->first() ?? $user->company ?? $user->branch?->company ?? $user->departmentRelation?->branch?->company;
        $companyName = $company?->name ?? null;
        $companyLogoUrl = $company?->logo ? $this->departmentLogoUrl($company->logo) : null;

        return [
            'id' => $user->id,
            'employee_name' => $user->display_name ?: '-',
            'employee_sort_key' => $user->employeeListingSortKey(),
            'profile_image' => $user->profile_image_url,
            'department' => $user->department ?? '-',
            'company_name' => $companyName,
            'company_logo_url' => $companyLogoUrl,
            'time_in' => null,
            'time_out' => null,
            'is_late' => false,
            'late_label' => null,
            'is_half_day' => false,
            'is_absent' => false,
            'absent_label' => null,
        ];
    }

    private function todayAttendanceLogs($today, string $dayKey, array $scopedActiveUserIds): array
    {
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($today, $tz);
        $logsQuery = AttendanceLog::query()
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'schedule', 'working_schedule_id', 'profile_image', 'department', 'department_id', 'company_id', 'branch_id')
                    ->with(['workingSchedule', 'companyHeadships:id,name,logo,company_head_id', 'company:id,name,logo', 'branch:id,company_id', 'branch.company:id,name,logo', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id', 'departmentRelation.branch.company:id,name,logo']);
            }]);
        $logsQuery = $this->attendanceLogEffectivePunchWhereBetween($logsQuery, $rangeStart, $rangeEnd);
        $logsQuery->orderByRaw($this->attendanceLogEffectivePunchColumnSql());
        if ($scopedActiveUserIds !== []) {
            $logsQuery->whereIn('user_id', $scopedActiveUserIds);
        } else {
            $logsQuery->whereRaw('1 = 0');
        }
        $logs = $logsQuery->get();

        // Group by employee and aggregate into a single row per employee for today.
        $grouped = [];
        $schedules = [];

        foreach ($logs as $log) {
            $user = $log->user;
            if (! $user) {
                continue;
            }
            $userId = $user->id;

            if (! isset($grouped[$userId])) {
                $profileImageUrl = $user->profile_image_url;
                $company = $user->companyHeadships->first() ?? $user->company ?? $user->branch?->company ?? $user->departmentRelation?->branch?->company;
                $companyName = $company?->name ?? null;
                $companyLogoUrl = $company?->logo ? $this->departmentLogoUrl($company->logo) : null;
                $grouped[$userId] = [
                    'id' => $userId,
                    'employee_name' => $user->display_name ?: '—',
                    'employee_sort_key' => $user->employeeListingSortKey(),
                    'profile_image' => $profileImageUrl,
                    'department' => $user->department ?? '—',
                    'company_name' => $companyName,
                    'company_logo_url' => $companyLogoUrl,
                    'time_in' => null,
                    'time_out' => null,
                    'is_late' => false,
                    'late_label' => null,
                    'is_half_day' => false,
                    'is_absent' => false,
                    'absent_label' => null,
                ];
                $schedules[$userId] = $this->resolveEffectiveSchedule($user);
            }

            $punchAt = $this->attendanceLogPunchInstant($log);
            if ($punchAt === null) {
                continue;
            }
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $currentIn = $grouped[$userId]['time_in'];
                if ($currentIn === null || $punchAt->lessThan($currentIn instanceof Carbon ? $currentIn : Carbon::parse($currentIn))) {
                    $grouped[$userId]['time_in'] = $punchAt;
                }
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                $currentOut = $grouped[$userId]['time_out'];
                if ($currentOut === null || $punchAt->greaterThan($currentOut instanceof Carbon ? $currentOut : Carbon::parse($currentOut))) {
                    $grouped[$userId]['time_out'] = $punchAt;
                }
            }
        }

        $dateKey = $today->toDateString();

        // Overlay approved corrections for today so manually-entered attendance
        // appears in the Workforce Activity feed and Today's Attendance table.
        $todayCorrectionsQuery = AttendanceCorrection::query()
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->whereNotNull('time_in');
        if ($scopedActiveUserIds !== []) {
            $todayCorrectionsQuery->whereIn('user_id', $scopedActiveUserIds);
        } else {
            $todayCorrectionsQuery->whereRaw('1 = 0');
        }
        $todayCorrections = $todayCorrectionsQuery
            ->with(['user' => function ($q) {
                $q->select('id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'schedule', 'working_schedule_id', 'profile_image', 'department', 'department_id', 'company_id', 'branch_id')
                    ->with(['workingSchedule', 'companyHeadships:id,name,logo,company_head_id', 'company:id,name,logo', 'branch:id,company_id', 'branch.company:id,name,logo', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id', 'departmentRelation.branch.company:id,name,logo']);
            }])
            ->get();

        foreach ($todayCorrections as $correction) {
            $user = $correction->user;
            if (! $user) {
                continue;
            }
            $userId = $user->id;
            if (! isset($grouped[$userId])) {
                $company = $user->companyHeadships->first() ?? $user->company ?? $user->branch?->company ?? $user->departmentRelation?->branch?->company;
                $companyName = $company?->name ?? null;
                $companyLogoUrl = $company?->logo ? $this->departmentLogoUrl($company->logo) : null;
                $grouped[$userId] = [
                    'id' => $userId,
                    'employee_name' => $user->display_name ?: '—',
                    'employee_sort_key' => $user->employeeListingSortKey(),
                    'profile_image' => $user->profile_image_url,
                    'department' => $user->department ?? '—',
                    'company_name' => $companyName,
                    'company_logo_url' => $companyLogoUrl,
                    'time_in' => null,
                    'time_out' => null,
                    'is_late' => false,
                    'late_label' => null,
                    'is_half_day' => false,
                    'is_absent' => false,
                    'absent_label' => null,
                ];
                $schedules[$userId] = $this->resolveEffectiveSchedule($user);
            }
            // Correction overrides any log-based times.
            $grouped[$userId]['time_in'] = $correction->time_in;
            if ($correction->time_out) {
                $grouped[$userId]['time_out'] = $correction->time_out;
            }
        }

        $approvedOvertimeByUserId = Overtime::query()
            ->where('status', Overtime::STATUS_APPROVED)
            ->whereDate('date', $dateKey)
            ->when(
                $scopedActiveUserIds !== [],
                fn ($q) => $q->whereIn('user_id', $scopedActiveUserIds),
                fn ($q) => $q->whereRaw('1 = 0')
            )
            ->get()
            ->keyBy('user_id')
            ->all();

        $leaveUserIds = LeaveRequest::query()
            ->whereIn('user_id', $scopedActiveUserIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $dateKey)
            ->whereDate('end_date', '>=', $dateKey)
            ->pluck('user_id')
            ->unique()
            ->all();
        $leaveSet = array_fill_keys($leaveUserIds, true);

        $scheduledUsers = User::query()
            ->whereIn('id', $scopedActiveUserIds)
            ->active()
            ->with(['workingSchedule', 'companyHeadships:id,name,logo,company_head_id', 'company:id,name,logo', 'branch:id,company_id', 'branch.company:id,name,logo', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id', 'departmentRelation.branch.company:id,name,logo'])
            ->orderByLastName()
            ->get();

        foreach ($scheduledUsers as $user) {
            if (isset($grouped[$user->id]) || isset($leaveSet[$user->id])) {
                continue;
            }

            $schedule = $this->resolveEffectiveSchedule($user);
            $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            if (! $todaySchedule || empty($todaySchedule['in'])) {
                continue;
            }

            $grouped[$user->id] = $this->dashboardAttendanceBaseRow($user);
            $grouped[$user->id]['is_absent'] = true;
            $grouped[$user->id]['absent_label'] = 'Absent';
            $schedules[$user->id] = $schedule;
        }

        foreach ($grouped as $userId => &$row) {
            // No clock-out yet: use approved OT expected end (same as Attendance session / Reports).
            if ($row['time_in'] && $row['time_out'] === null) {
                $ot = $approvedOvertimeByUserId[$userId] ?? null;
                if ($ot) {
                    $schedule = $schedules[$userId] ?? null;
                    $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
                    $resolvedOut = AttendanceStatusService::resolveApprovedOvertimeVirtualEnd(
                        $ot,
                        $dateKey,
                        is_array($todaySchedule) ? $todaySchedule : null,
                        $tz
                    );
                    if ($resolvedOut !== null) {
                        $row['time_out'] = $resolvedOut;
                        // Same flag name as Admin Attendance detailed report for UI tooltips.
                        $row['virtual_time_out_from_ot'] = true;
                    }
                }
            }
        }
        unset($row);

        foreach ($grouped as $userId => &$row) {
            $schedule = $schedules[$userId] ?? null;
            $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;

            if ($row['time_in'] && $todaySchedule && ! empty($todaySchedule['in'])) {
                $timeInCarbon = $row['time_in'] instanceof Carbon ? $row['time_in'] : Carbon::parse($row['time_in']);
                $result = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $timeInCarbon);
                $row['is_late'] = $result['status'] === 'late';
                $row['late_label'] = $row['is_late'] ? $result['late_label'] : null;
                $row['late_minutes'] = $row['is_late'] ? ($result['late_minutes'] ?? 0) : null;
                $row['is_half_day'] = $result['status'] === 'half_day';
            }

            $row['virtual_time_out_from_ot'] = (bool) ($row['virtual_time_out_from_ot'] ?? false);

            $row['time_in'] = $row['time_in'] ? $row['time_in']->toIso8601String() : null;
            $row['time_out'] = $row['time_out'] ? $row['time_out']->toIso8601String() : null;
        }
        unset($row);

        // Sort final rows by time_in (earliest first), then by name as a fallback.
        usort($grouped, function (array $a, array $b): int {
            if ($a['time_in'] && $b['time_in']) {
                return strcmp($a['time_in'], $b['time_in']);
            }
            if ($a['time_in']) {
                return -1;
            }
            if ($b['time_in']) {
                return 1;
            }

            return strcmp((string) ($a['employee_sort_key'] ?? ''), (string) ($b['employee_sort_key'] ?? ''));
        });

        return array_values($grouped);
    }
}
