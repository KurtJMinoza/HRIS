<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\AttendanceStatusService;
use App\Services\DataScopeService;
use App\Services\EmployeeStatusService;
use App\Services\HrRoleResolver;
use App\Services\PresenceFilingCorrectionFormatter;
use App\Services\PresenceFilingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $attendanceCorrectionApprovalService,
        private readonly EmployeeStatusService $employeeStatusService,
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
            $q->where('is_active', true);
        }
        $this->dataScopeService->restrictEmployeeQuery($actor, $q);

        return $q->pluck('id')->all();
    }

    /**
     * Return UTC range [start, end] for a calendar day in the attendance timezone.
     * Use for whereBetween('created_at', ...) so logs stored in UTC match "today" in business tz.
     */
    private function dateRangeUtcForDay(Carbon $date, string $tz): array
    {
        $start = $date->copy()->startOfDay()->setTimezone('UTC');
        $end = $date->copy()->endOfDay()->setTimezone('UTC');

        return [$start, $end];
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
        $monthlyLateStats = $this->monthlyLateStatistics($now, $allScopeIds);
        $departmentDistribution = $this->departmentAttendanceDistribution($today, $actor);
        $companyDistribution = $this->companyAttendanceDistribution($today, null, $actor);
        $todayLogs = $this->todayAttendanceLogs($today, $todayDayKey, $activeScopeIds);
        $halfDaySummary = $this->halfDaySummary($today, $activeScopeIds);
        $todayLeaves = $this->todayLeaves($today, $activeScopeIds);
        $upcomingRegularizations = $this->upcomingRegularizations($actor, 5);
        $expiringContracts = $this->expiringContracts($actor, 5);
        $employmentSettings = $this->employeeStatusService->getAutomationSettings();

        $pendingCorrectionsCollection = $this->attendanceCorrectionApprovalService->getPendingForApprover($actor);
        $pendingAttendanceCorrections = $pendingCorrectionsCollection->count();
        $correctionDisplayTz = $this->presenceFilingService->attendanceTimezone();
        $pendingAttendanceCorrectionPreview = null;
        if ($pendingCorrectionsCollection->isNotEmpty()) {
            $pendingAttendanceCorrectionPreview = $this->correctionFormatter->format(
                $pendingCorrectionsCollection->first(),
                $correctionDisplayTz,
                includeEmployee: true,
                actor: $actor,
                includeDisplayFields: true
            );
        }

        $response = [
            'stats' => $statsToday,
            'stats_prev' => $statsYesterday,
            'weekly_overview' => $weeklyOverview,
            'monthly_late' => $monthlyLateStats,
            'department_distribution' => $departmentDistribution,
            'company_distribution' => $companyDistribution,
            'today_logs' => $todayLogs,
            'half_day_summary' => $halfDaySummary,
            'today_leaves' => $todayLeaves,
            'upcoming_regularizations' => $upcomingRegularizations,
            'expiring_contracts' => $expiringContracts,
            'employment_settings' => $employmentSettings,
            'pending_attendance_corrections' => $pendingAttendanceCorrections,
            'pending_attendance_correction_preview' => $pendingAttendanceCorrectionPreview,
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
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->whereNotNull('hire_date')
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);
        $employees = $query->get();
        if ($employees->isEmpty()) {
            return [];
        }

        $recommendationsByUser = RegularizationRecommendation::query()
            ->with(['recommendedBy:id,name'])
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
                'name' => $employee->name,
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
                    'recommended_by_name' => $recommendation->recommendedBy?->name,
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
            ];
        })->filter()->sortBy([
            ['is_within_30_days', 'desc'],
            ['days_remaining', 'asc'],
            ['name', 'asc'],
        ])->values();

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
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', $from->toDateString())
            ->whereDate('contract_end_date', '<=', $until->toDateString())
            ->where(function ($q) {
                $q->whereRaw("LOWER(COALESCE(employment_type, '')) IN ('contractual','project-based','project_based','project based')")
                    ->orWhereRaw("LOWER(COALESCE(employment_status, '')) IN ('contractual','project-based','project_based','project based')");
            })
            ->with(['departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name', 'branch:id,name']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);

        $rows = $query->get()->map(function (User $employee) use ($today, $role) {
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
                'name' => $employee->name,
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
            ];
        })->filter()->sortBy([
            ['days_remaining', 'asc'],
            ['name', 'asc'],
        ])->values();

        return $rows->take($limit)->all();
    }

    private function workedMinutesFromLogs($logs): ?int
    {
        $total = 0;
        $clockIn = null;
        foreach ($logs as $log) {
            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                $clockIn = $log->created_at;
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT && $clockIn) {
                $total += $clockIn->diffInMinutes($log->created_at);
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
        $firstClockIn = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->whereIn('user_id', $activeEmployeeIds)
            ->select('user_id', DB::raw('MIN(created_at) as first_at'))
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
                // $firstAt comes from MIN(created_at) (stored in UTC). Parse as UTC then convert to business timezone.
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

        $logsByUserForDay = AttendanceLog::query()
            ->whereIn('user_id', $activeEmployeeIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->orderBy('created_at')
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

            $firstAt = $firstClockIn->get($user->id)?->first_at;
            if ($firstAt) {
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
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd]);
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

    /**
     * Monthly late statistics: last 12 months, total late count per month (grace + deduction rules).
     */
    /**
     * @param  array<int, int>  $scopedUserIds
     */
    private function monthlyLateStatistics(Carbon $now, array $scopedUserIds): array
    {
        $usersCache = [];
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = $now->copy()->subMonths($i)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $firstClockInsQuery = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereBetween('created_at', [$start, $end]);
            if ($scopedUserIds !== []) {
                $firstClockInsQuery->whereIn('user_id', $scopedUserIds);
            } else {
                $firstClockInsQuery->whereRaw('1 = 0');
            }
            $firstClockIns = $firstClockInsQuery
                ->select('user_id', DB::raw('DATE(created_at) as d'), DB::raw('MIN(created_at) as first_at'))
                ->groupBy('user_id', DB::raw('DATE(created_at)'))
                ->get();
            $monthUserIds = $firstClockIns->pluck('user_id')->unique()->map(fn ($id) => (int) $id)->all();
            $idsToLoad = array_values(array_diff($monthUserIds, array_keys($usersCache)));
            if ($idsToLoad !== []) {
                $loaded = User::query()
                    ->whereIn('id', $idsToLoad)
                    ->with('workingSchedule')
                    ->get();
                foreach ($loaded as $loadedUser) {
                    $usersCache[(int) $loadedUser->id] = $loadedUser;
                }
            }
            $clockInSamples = $firstClockIns->count();
            $lateCount = 0;
            foreach ($firstClockIns as $row) {
                $date = Carbon::parse($row->d);
                $dayKey = self::DAY_KEYS[(int) $date->format('w')];
                $user = $usersCache[(int) $row->user_id] ?? null;
                $effectiveSched = $user ? $this->resolveEffectiveSchedule($user) : null;
                if (! $effectiveSched || ! isset($effectiveSched[$dayKey]['in'])) {
                    continue;
                }
                $todaySchedule = $effectiveSched[$dayKey];
                $dateKey = $date->format('Y-m-d');
                // first_at is MIN(created_at) stored in UTC.
                $firstAtCarbon = Carbon::parse($row->first_at, 'UTC');
                $result = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $firstAtCarbon);
                if ($result['status'] === 'late') {
                    $lateCount++;
                }
            }
            $months[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                // Distinguish "no records" from a true zero-late month so charts don't look bugged.
                // If there were no clock-ins recorded, return null and mark has_data=false.
                'late_count' => $clockInSamples === 0 ? null : $lateCount,
                'has_data' => $clockInSamples > 0,
                'clock_in_samples' => $clockInSamples,
            ];
        }

        return $months;
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
            $firstClockIns = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->select('user_id', DB::raw('MIN(created_at) as first_at'))
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
                // first_at is MIN(created_at) stored in UTC.
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
                $q->select('id', 'name', 'department', 'department_id', 'position', 'profile_image')
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
                'employee_name' => $user->name ?? '—',
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

        usort($items, fn (array $a, array $b) => strcmp((string) $a['employee_name'], (string) $b['employee_name']));

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
                $q->select('id', 'name', 'department', 'department_id', 'company_id', 'branch_id', 'profile_image')
                    ->with(['company:id,name', 'branch:id,name,company_id', 'branch.company:id,name', 'departmentRelation:id,name,branch_id', 'departmentRelation.branch:id,name']);
            }])
            ->orderBy('half_type')
            ->orderBy('user_id')
            ->get();

        $userIds = $leaveRequests->pluck('user_id')->unique()->all();
        $firstClockIns = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->select('user_id', DB::raw('MIN(created_at) as first_at'))
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
                'employee_name' => $user->name ?? '—',
                'branch' => $branchName,
                'time_in' => $timeIn ? $timeIn->format('H:i:s') : null,
                'half_type' => $lr->half_type ?? '—',
                'notes' => $lr->notes ? trim($lr->notes) : null,
            ];
        }

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
        $activeQuery = User::whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->with(['departmentRelation:id,name,branch_id']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $activeQuery);
        $activeEmployees = $activeQuery->get();

        // Set of users who actually clocked in today (present).
        $tz = config('attendance.timezone', config('app.timezone', 'UTC'));
        [$rangeStart, $rangeEnd] = $this->dateRangeUtcForDay($today, $tz);
        $presentQuery = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd]);
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
        $activeEmployeesQuery = User::whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true)
            ->with(['workingSchedule', 'companyHeadships:id,company_head_id', 'company:id,name', 'branch:id,company_id', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id']);
        $this->dataScopeService->restrictEmployeeQuery($actor, $activeEmployeesQuery);
        $activeEmployees = $activeEmployeesQuery->get();
        
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

        $firstClockIn = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->whereIn('user_id', $activeEmployeeIds)
            ->select('user_id', DB::raw('MIN(created_at) as first_at'))
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
            'employee_name' => $user->name ?? '-',
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
                $q->select('id', 'name', 'schedule', 'working_schedule_id', 'profile_image', 'department', 'department_id', 'company_id', 'branch_id')
                    ->with(['workingSchedule', 'companyHeadships:id,name,logo,company_head_id', 'company:id,name,logo', 'branch:id,company_id', 'branch.company:id,name,logo', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id', 'departmentRelation.branch.company:id,name,logo']);
            }])
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->orderBy('created_at');
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
                    'employee_name' => $user->name ?? '—',
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

            if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                if ($grouped[$userId]['time_in'] === null || $log->created_at->lessThan($grouped[$userId]['time_in'])) {
                    $grouped[$userId]['time_in'] = $log->created_at;
                }
            } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                if ($grouped[$userId]['time_out'] === null || $log->created_at->greaterThan($grouped[$userId]['time_out'])) {
                    $grouped[$userId]['time_out'] = $log->created_at;
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
                $q->select('id', 'name', 'schedule', 'working_schedule_id', 'profile_image', 'department', 'department_id', 'company_id', 'branch_id')
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
                    'employee_name' => $user->name ?? '—',
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
            ->where('is_active', true)
            ->with(['workingSchedule', 'companyHeadships:id,name,logo,company_head_id', 'company:id,name,logo', 'branch:id,company_id', 'branch.company:id,name,logo', 'departmentRelation:id,branch_id', 'departmentRelation.branch:id,company_id', 'departmentRelation.branch.company:id,name,logo'])
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

            return strcmp($a['employee_name'], $b['employee_name']);
        });

        return array_values($grouped);
    }
}
