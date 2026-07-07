<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Services\AttendanceDailySummaryService;
use App\Services\AttendanceRollupService;
use App\Services\AttendanceStatusResolver;
use App\Services\AttendanceStatusService;
use App\Services\EmployeeDashboardCacheService;
use App\Services\HolidayService;
use App\Services\HolidayEligibilityService;
use App\Services\OtDetectionService;
use App\Services\OvertimePayrollService;
use App\Services\PayrollComputationService;
use App\Services\PolicyResolverService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Split employee dashboard endpoints for faster initial shell and lazy-loaded widgets.
 *
 * Performance targets:
 * - /summary (shell): <500ms uncached, <200ms cached
 * - /attendance-calendar: <1s uncached, <300ms cached
 * - /recent-requests: <500ms uncached, <200ms cached
 */
class EmployeeDashboardController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly AttendanceRollupService $attendanceRollup,
        private readonly AttendanceStatusResolver $statusResolver,
        private readonly OtDetectionService $otDetectionService,
        private readonly OvertimePayrollService $overtimePayroll,
        private readonly PayrollComputationService $payrollComputation,
        private readonly AttendanceDailySummaryService $dailySummary,
        private readonly HolidayService $holidayService,
        private readonly HolidayEligibilityService $holidayEligibility,
        private readonly PolicyResolverService $policyResolver,
    ) {}

    /**
     * Lightweight dashboard shell: today's status + pending request counts.
     * Returns only essential data for above-the-fold display.
     */
    public function summary(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $user = $request->user();
        $employeeId = (int) $user->id;
        $attendanceTz = $this->attendanceTimezone();
        $todayNow = now($attendanceTz);
        $todayDate = $todayNow->toDateString();

        $cacheKey = EmployeeDashboardCacheService::summaryKey($employeeId, $todayDate);
        $cached = EmployeeDashboardCacheService::get($cacheKey);
        if (is_array($cached) && ($cached['meta']['schema_version'] ?? null) === 7) {
            $cached['meta']['performance']['cache_hit'] = true;
            $cached['meta']['performance']['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            Log::debug('[EmployeeDashboard] summary cache HIT', [
                'endpoint' => 'summary',
                'employee_id' => $employeeId,
                'query_count' => 0,
                'db_ms' => 0,
                'cache_hit' => true,
                'total_ms' => $cached['meta']['performance']['total_ms'],
            ]);

            return response()->json($cached);
        }

        // Preload user schedule
        $this->hydrateUserSchedule($user);
        $effectiveSchedule = $user->schedule;
        if ((! is_array($effectiveSchedule) || $effectiveSchedule === []) && $user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $effectiveSchedule = $derived;
            }
        }

        $scheduleAssigned = is_array($effectiveSchedule) && $effectiveSchedule !== [];

        $startedDb = microtime(true);

        // Today's attendance
        $dayKey = self::DAY_KEYS[(int) $todayNow->format('w')];
        $daySchedule = $scheduleAssigned && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;

        $todayLogs = AttendanceLog::query()
            ->select(['id', 'user_id', 'type', 'verified_at', 'created_at', 'night_hours', 'premium_type', 'calculated_pay_factor'])
            ->where('user_id', $employeeId)
            ->where(function ($q) use ($todayNow) {
                $startUtc = $todayNow->copy()->startOfDay()->setTimezone('UTC');
                $endUtc = $todayNow->copy()->endOfDay()->setTimezone('UTC');
                $q->whereBetween('verified_at', [$startUtc, $endUtc])
                    ->orWhere(function ($q) use ($startUtc, $endUtc) {
                        $q->whereNull('verified_at')->whereBetween('created_at', [$startUtc, $endUtc]);
                    });
            })
            ->orderByRaw('COALESCE(verified_at, created_at)')
            ->get();

        $todayCorrection = AttendanceCorrection::query()
            ->select(['id', 'user_id', 'date', 'time_in', 'time_out', 'approved', 'pending_approval', 'reason_code', 'filed_at', 'is_incomplete_record'])
            ->where('user_id', $employeeId)
            ->where('date', $todayDate)
            ->first();

        $todayLeave = LeaveRequest::query()
            ->select(['id', 'user_id', 'type', 'half_type', 'start_date', 'end_date', 'status'])
            ->where('user_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $todayDate)
            ->where('end_date', '>=', $todayDate)
            ->first();

        $isRestDay = $this->attendanceRollup->isScheduledRestDay($effectiveSchedule, $daySchedule);

        // Today's next pay period info
        $upcomingBatch = null;
        try {
            $upcomingBatch = $this->resolveUpcomingPayrollBatch($user);
        } catch (\Throwable $e) {
            Log::warning('EmployeeDashboard summary: payroll batch resolution failed', [
                'user_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);
        }

        // Pending request counts (lightweight)
        $pendingLeaveCount = LeaveRequest::query()
            ->where('user_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->count();

        $pendingOtCount = Overtime::query()
            ->where('user_id', $employeeId)
            ->where('status', Overtime::STATUS_PENDING)
            ->count();

        $pendingCorrectionCount = AttendanceCorrection::query()
            ->where('user_id', $employeeId)
            ->where('pending_approval', true)
            ->count();

        $totalPending = $pendingLeaveCount + $pendingOtCount + $pendingCorrectionCount;
        $latestPayslipSummary = $this->latestPayslipSummary($employeeId);

        $todayPayload = $this->buildTodayPayload(
            todayDate: $todayDate,
            todayNow: $todayNow,
            daySchedule: $daySchedule,
            effectiveSchedule: $effectiveSchedule,
            scheduleAssigned: $scheduleAssigned,
            todayLogs: $todayLogs,
            todayCorrection: $todayCorrection,
            todayLeave: $todayLeave,
            isRestDay: $isRestDay,
            user: $user,
        );

        // Detect OT for today
        $otDetection = $this->otDetectionService->detectForToday($user, $attendanceTz);

        $payload = [
            'schedule_assigned' => $scheduleAssigned,
            'today' => $todayPayload,
            'pending_requests' => [
                'total' => $totalPending,
                'leave' => $pendingLeaveCount,
                'overtime' => $pendingOtCount,
                'correction' => $pendingCorrectionCount,
            ],
            'upcoming_payroll' => $upcomingBatch,
            'latest_payslip' => $latestPayslipSummary,
            'meta' => [
                'schema_version' => 7,
                'performance' => [
                    'cache_hit' => false,
                    'total_ms' => null,
                ],
            ],
        ];

        $payload['today']['ot_detection'] = $otDetection;

        $summaryDbMs = (int) round((microtime(true) - $startedDb) * 1000);

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload['meta']['performance']['total_ms'] = $totalMs;

        Log::debug('[EmployeeDashboard] summary computed', [
            'endpoint' => 'summary',
            'employee_id' => $employeeId,
            'query_count' => 8,
            'db_ms' => $summaryDbMs,
            'cache_hit' => false,
            'total_ms' => $totalMs,
        ]);

        EmployeeDashboardCacheService::put($cacheKey, $payload, EmployeeDashboardCacheService::SUMMARY_TTL, $employeeId);

        return response()->json($payload);
    }

    /**
     * Month-scoped attendance calendar: days, statuses, holidays, leave for one month.
     * Cached per employee + year-month for 120s.
     */
    public function attendanceCalendar(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $user = $request->user();
        $employeeId = (int) $user->id;
        $attendanceTz = $this->attendanceTimezone();
        $todayNow = now($attendanceTz);
        $todayDate = $todayNow->toDateString();

        $validated = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $yearMonth = $validated['month'] ?? $todayNow->format('Y-m');
        [$year, $month] = explode('-', $yearMonth);
        $year = (int) $year;
        $month = (int) $month;

        $from = Carbon::parse("{$year}-{$month}-01", $attendanceTz)->startOfDay();
        $to = $from->copy()->endOfMonth();
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $cacheKey = EmployeeDashboardCacheService::calendarKey($employeeId, $yearMonth);
        $cached = EmployeeDashboardCacheService::get($cacheKey);
        if (is_array($cached) && ($cached['meta']['schema_version'] ?? null) === 14) {
            $cached['meta']['performance']['cache_hit'] = true;
            $cached['meta']['performance']['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $cachedDays = is_array($cached['days'] ?? null) ? $cached['days'] : [];
            Log::debug('[EmployeeDashboard] calendar cache HIT', [
                'endpoint' => 'attendance-calendar',
                'employee_id' => $employeeId,
                'month' => $yearMonth,
                'query_count' => 0,
                'db_ms' => 0,
                'cache_hit' => true,
                'total_ms' => $cached['meta']['performance']['total_ms'],
                'days_generated' => count($cachedDays),
                'rest_day_count' => count(array_filter($cachedDays, fn ($d) => ! empty($d['is_rest_day']))),
            ]);

            return response()->json($cached);
        }

        // Preload user schedule
        $this->hydrateUserSchedule($user);
        $effectiveSchedule = $user->schedule;
        if ((! is_array($effectiveSchedule) || $effectiveSchedule === []) && $user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
            $derived = $this->buildScheduleFromWorkingSchedule($user->workingSchedule);
            if ($derived !== null) {
                $effectiveSchedule = $derived;
            }
        }
        $scheduleAssigned = is_array($effectiveSchedule) && $effectiveSchedule !== [];

        $bulkFetchStart = microtime(true);

        // Month-scoped queries: only the requested month
        $logs = AttendanceLog::query()
            ->select(['id', 'user_id', 'type', 'verified_at', 'created_at', 'night_hours', 'premium_type', 'calculated_pay_factor'])
            ->where('user_id', $employeeId)
            ->where(function ($q) use ($from, $to) {
                $startUtc = $from->copy()->setTimezone('UTC');
                $endUtc = $to->copy()->setTimezone('UTC');
                $q->whereBetween('verified_at', [$startUtc, $endUtc])
                    ->orWhere(function ($q) use ($startUtc, $endUtc) {
                        $q->whereNull('verified_at')->whereBetween('created_at', [$startUtc, $endUtc]);
                    });
            })
            ->orderByRaw('COALESCE(verified_at, created_at)')
            ->get()
            ->groupBy(fn ($l) => $this->attendanceLogPunchInstant($l)->timezone($attendanceTz)->toDateString());

        $corrections = AttendanceCorrection::query()
            ->select(['id', 'user_id', 'date', 'time_in', 'time_out', 'approved', 'pending_approval', 'reason_code', 'filed_at', 'is_incomplete_record'])
            ->where('user_id', $employeeId)
            ->whereBetween('date', [$fromStr, $toStr])
            ->get()
            ->groupBy(fn ($c) => $c->date->toDateString());

        $approvedLeaves = LeaveRequest::query()
            ->select(['id', 'user_id', 'type', 'half_type', 'start_date', 'end_date', 'status'])
            ->where('user_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $toStr)
            ->where('end_date', '>=', $fromStr)
            ->get();

        $leaveByDate = [];
        foreach ($approvedLeaves as $leave) {
            $leaveStart = $leave->start_date->copy()->max($from);
            $leaveEnd = $leave->end_date->copy()->min($to);
            $cursor = $leaveStart->copy();
            while ($cursor->lessThanOrEqualTo($leaveEnd)) {
                $ds = $cursor->toDateString();
                $existing = $leaveByDate[$ds] ?? null;
                if ($existing === null || $leave->id > $existing->id) {
                    $leaveByDate[$ds] = $leave;
                }
                $cursor->addDay();
            }
        }

        // Month-scoped holidays
        $holidays = $this->loadMonthHolidays($user, $year, $month);

        $overtimes = Overtime::query()
            ->select(['id', 'user_id', 'date', 'computed_hours', 'approved_ot_hours', 'actual_rendered_ot_hours', 'payable_ot_hours', 'unapproved_ot_hours', 'overtime_reduction_reason', 'expected_end_time', 'time_out', 'schedule_end', 'status', 'ph_ot_rule'])
            ->where('user_id', $employeeId)
            ->whereBetween('date', [$fromStr, $toStr]);
        if (Schema::hasColumn('overtimes', 'voided_at')) {
            $overtimes->whereNull('voided_at');
        }
        if (Schema::hasColumn('overtimes', 'deleted_at')) {
            $overtimes->whereNull('deleted_at');
        }
        $overtimesByDate = $overtimes->get()->groupBy(fn ($o) => $o->date->toDateString());

        $bulkFetchMs = (int) round((microtime(true) - $bulkFetchStart) * 1000);

        // Build days array for month via AttendanceDailySummaryService (single source of truth)
        $days = [];
        $metrics = $this->zeroMetrics();
        $extraMetrics = [
            'late_minutes' => 0,
            'undertime_count' => 0,
            'total_worked_minutes' => 0,
        ];
        $cursor = $from->copy();
        $holidayMap = [];
        foreach ($holidays as $h) {
            if ($h['date'] ?? null) {
                $holidayMap[$h['date']] = $h;
            }
        }

        $absentCounts = ['leave' => 0, 'rest' => 0, 'holiday' => 0, 'absent' => 0];

        $totalExpectedMinutes = 0;
        $totalAbsentExpectedMinutes = 0;

        while ($cursor->lessThanOrEqualTo($to)) {
            $dateKey = $cursor->toDateString();
            $holidayOnDate = $holidayMap[$dateKey] ?? null;
            $leaveOnDate = $leaveByDate[$dateKey] ?? null;
            $dayLogs = isset($logs[$dateKey]) ? $logs[$dateKey]->all() : null;
            $correctionCollection = $corrections->get($dateKey);
            $correction = $correctionCollection?->first();
            $otRecords = $overtimesByDate->get($dateKey)?->all() ?? [];

            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $daySchedule = $scheduleAssigned && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;
            $isRestDay = $this->attendanceRollup->isScheduledRestDay($effectiveSchedule, $daySchedule);
            $isLeave = $leaveOnDate !== null;
            $isHoliday = $holidayOnDate !== null;
            $expectedDayMinutes = 0;
            $isFutureDay = $dateKey > $todayDate;
            if (! $isFutureDay && ! $isRestDay && ! $isLeave && ! $isHoliday && is_array($daySchedule)) {
                $expectedDayMinutes = max(0, (int) ($daySchedule['expected_paid_minutes'] ?? 480));
                $totalExpectedMinutes += $expectedDayMinutes;
            }

            $summary = $this->dailySummary->computeForDate(
                user: $user,
                dateKey: $dateKey,
                todayDate: $todayDate,
                nowTz: $todayNow,
                effectiveSchedule: $effectiveSchedule,
                preloadedLogs: $dayLogs,
                correction: $correction,
                leave: $leaveOnDate,
                holiday: $holidayOnDate,
                otRecords: $otRecords,
            );

            $status = $summary['status'];
            $dayLateMinutes = (int) ($summary['late_minutes'] ?? 0);
            $dayUndertimeMinutes = (int) ($summary['undertime_minutes'] ?? 0);
            $effectiveWorkedMinutes = $summary['worked_minutes'];
            $holidayPayPolicy = null;
            if ($holidayOnDate !== null) {
                $activePolicy = $this->policyResolver->getActivePolicy(
                    $user->getEffectiveCompanyId(),
                    $user->branch_id !== null ? (int) $user->branch_id : null,
                    $dateKey
                );
                $settings = $this->holidayEligibility->resolveEffectivePolicy($activePolicy, $holidayOnDate, $user->getEffectiveCompanyId());
                $worked = (int) ($summary['worked_minutes'] ?? 0) > 0;
                $qualification = $cursor->lessThanOrEqualTo($todayNow)
                    ? $this->holidayEligibility->determineEligibility($user, $holidayOnDate, $dateKey, $worked, $activePolicy)
                    : null;
                $holidayPayPolicy = [
                    'source' => 'payroll_policy_settings',
                    'policy_id' => $activePolicy?->id,
                    'policy_name' => $activePolicy?->name ?? 'DOLE Global Default',
                    'scope' => $activePolicy?->company_id !== null ? 'company_override' : 'global_default',
                    'eligible' => $qualification['eligible'] ?? null,
                    'employment_type' => $qualification['employment_type'] ?? null,
                    'attendance_rule_applied' => $qualification['rule'] ?? 'pending',
                    'reason' => $qualification['reason'] ?? 'Eligibility is evaluated when the holiday date is reached.',
                    'unworked_multiplier' => $this->holidayEligibility->unworkedMultiplier($holidayOnDate, $settings),
                ];
                if ($holidayOnDate !== null && ($qualification['eligible'] ?? false) && ! $worked) {
                    $summary['status'] = 'holiday';
                    $summary['status_label'] = 'Unworked holiday pay';
                    $summary['presence_label'] = 'Eligible for holiday pay';
                    $summary['display_badge'] = 'Regular Holiday';
                }
            }

            if (
                $dayLateMinutes > 0
                && ! in_array($status, ['holiday', 'leave', 'rest', 'upcoming', 'absent'], true)
                && ! ($summary['is_rest_day'] ?? false)
            ) {
                $extraMetrics['late_minutes'] += $dayLateMinutes;
            }

            if (
                $dayUndertimeMinutes > 0
                && $status === AttendanceStatusResolver::STATUS_UNDERTIME
                && ! in_array($status, ['holiday', 'leave', 'rest', 'upcoming', 'absent'], true)
                && ! ($summary['is_rest_day'] ?? false)
            ) {
                $extraMetrics['undertime_count']++;
            }

            if (in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'undertime', 'incomplete', 'clocked_in'], true) && $effectiveWorkedMinutes !== null) {
                $extraMetrics['total_worked_minutes'] += $effectiveWorkedMinutes;
            }

            if ($status === 'absent' && ! ($summary['is_rest_day'] ?? false)) {
                if ($holidayOnDate) {
                    $absentCounts['holiday']++;
                } elseif ($leaveOnDate) {
                    $absentCounts['leave']++;
                } else {
                    $absentCounts['absent']++;
                    $totalAbsentExpectedMinutes += $expectedDayMinutes;
                }
            }

            if (in_array($status, ['present', 'present_with_ot', 'late'], true)) {
                $metrics['present_count']++;
                if ($status === 'late') {
                    $metrics['late_count']++;
                }
            } elseif ($status === 'absent') {
                $metrics['absent_count']++;
            } elseif ($status === 'leave') {
                $metrics['leave_count']++;
            } elseif ($status === 'halfday') {
                $metrics['halfday_count']++;
            } elseif ($status === 'holiday') {
                $absentCounts['holiday']++;
            }

            $days[] = array_merge($summary, [
                'schedule_label' => ($summary['is_rest_day'] ?? false) ? AttendanceStatusResolver::REST_DAY_LABEL : null,
                'holiday_name' => $holidayOnDate['name'] ?? null,
                'holiday_type' => $holidayOnDate['type'] ?? null,
                'holiday_pay_policy' => $holidayPayPolicy,
            ]);

            $cursor->addDay();
        }

        $monthSummary = $this->attendanceRollup->summarizeEmployeeDays($days);

        // Merge extra metrics from the AttendanceController-style summary.
        $monthSummary['late_minutes'] = $extraMetrics['late_minutes'];
        $monthSummary['undertime_count'] = $extraMetrics['undertime_count'];
        $monthSummary['total_hours'] = $extraMetrics['total_worked_minutes'] > 0
            ? round($extraMetrics['total_worked_minutes'] / 60, 2)
            : 0;

        // Override expected_scheduled_hours and absent_hours with controller-computed values from schedule config
        $monthSummary['expected_scheduled_hours'] = round($totalExpectedMinutes / 60, 2);
        $monthSummary['absent_hours'] = round($totalAbsentExpectedMinutes / 60, 2);

        // Ensure efficiency is computed from the latest values
        $effExpected = (float) ($monthSummary['expected_scheduled_hours'] ?? 0);
        $effPayroll = (float) ($monthSummary['payroll_impact_hours'] ?? 0);
        $monthSummary['attendance_efficiency_percentage'] = $effExpected > 0
            ? round(($effPayroll / $effExpected) * 100, 2)
            : 0.0;

        $payload = [
            'year' => $year,
            'month' => $month,
            'date_range' => [
                'from' => $fromStr,
                'to' => $toStr,
            ],
            'schedule_assigned' => $scheduleAssigned,
            'days' => $days,
            'summary' => $monthSummary,
            'absent_counts' => $absentCounts,
            'holidays' => $holidays,
            'overtime_requests' => $overtimesByDate
                ->flatten(1)
                ->values()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'date' => $o->date?->toDateString(),
                    'computed_hours' => (float) ($o->computed_hours ?? 0),
                    'approved_ot_hours' => $o->approved_ot_hours !== null ? (float) $o->approved_ot_hours : null,
                    'actual_rendered_ot_hours' => (float) ($o->actual_rendered_ot_hours ?? 0),
                    'payable_ot_hours' => (float) ($o->payable_ot_hours ?? 0),
                    'unapproved_ot_hours' => (float) ($o->unapproved_ot_hours ?? 0),
                    'overtime_reduction_reason' => $o->overtime_reduction_reason,
                    'status' => $o->status,
                    'expected_end_time' => $o->expected_end_time,
                    'time_out' => $o->time_out,
                    'schedule_end' => $o->schedule_end,
                    'ph_ot_rule' => $o->ph_ot_rule,
                ])
                ->all(),
            'meta' => [
                'schema_version' => 14,
                'performance' => [
                    'cache_hit' => false,
                    'bulk_fetch_ms' => $bulkFetchMs,
                    'total_ms' => null,
                ],
            ],
        ];

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload['meta']['performance']['total_ms'] = $totalMs;

        $restDayCount = count(array_filter($days, fn ($d) => ! empty($d['is_rest_day'])));

        Log::debug('[EmployeeDashboard] calendar computed', [
            'endpoint' => 'attendance-calendar',
            'employee_id' => $employeeId,
            'month' => $yearMonth,
            'query_count' => 5,
            'db_ms' => $bulkFetchMs,
            'cache_hit' => false,
            'total_ms' => $totalMs,
            'days_generated' => count($days),
            'rest_day_count' => $restDayCount,
        ]);

        EmployeeDashboardCacheService::put($cacheKey, $payload, EmployeeDashboardCacheService::CALENDAR_TTL, $employeeId);

        return response()->json($payload);
    }

    /**
     * Recent pending/approved requests for the employee (leave, overtime, corrections).
     */
    public function recentRequests(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $user = $request->user();
        $employeeId = (int) $user->id;

        $cacheKey = EmployeeDashboardCacheService::recentRequestsKey($employeeId);
        $cached = EmployeeDashboardCacheService::get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['performance']['cache_hit'] = true;
            $cached['meta']['performance']['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            return response()->json($cached);
        }

        $recentLeaves = LeaveRequest::query()
            ->select(['id', 'type', 'half_type', 'start_date', 'end_date', 'status', 'created_at'])
            ->where('user_id', $employeeId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentOvertimes = Overtime::query()
            ->select(['id', 'date', 'computed_hours', 'approved_ot_hours', 'actual_rendered_ot_hours', 'payable_ot_hours', 'unapproved_ot_hours', 'overtime_reduction_reason', 'status', 'created_at'])
            ->where('user_id', $employeeId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentCorrections = AttendanceCorrection::query()
            ->select(['id', 'date', 'time_in', 'time_out', 'approved', 'pending_approval', 'created_at'])
            ->where('user_id', $employeeId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $leaveSummary = [
            'pending' => LeaveRequest::query()->where('user_id', $employeeId)->where('status', LeaveRequest::STATUS_PENDING)->count(),
            'approved' => LeaveRequest::query()->where('user_id', $employeeId)->where('status', LeaveRequest::STATUS_APPROVED)->count(),
            'rejected' => LeaveRequest::query()->where('user_id', $employeeId)->where('status', LeaveRequest::STATUS_REJECTED)->count(),
            'upcoming' => LeaveRequest::query()
                ->where('user_id', $employeeId)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->where('start_date', '>=', now($this->attendanceTimezone())->toDateString())
                ->count(),
        ];

        $payload = [
            'leave_requests' => $recentLeaves,
            'overtime_requests' => $recentOvertimes,
            'corrections' => $recentCorrections,
            'leave_summary' => $leaveSummary,
            'meta' => [
                'schema_version' => 5,
                'performance' => [
                    'cache_hit' => false,
                    'total_ms' => null,
                ],
            ],
        ];

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload['meta']['performance']['total_ms'] = $totalMs;

        Log::debug('[EmployeeDashboard] recentRequests computed', [
            'endpoint' => 'recent-requests',
            'employee_id' => $employeeId,
            'cache_hit' => false,
            'total_ms' => $totalMs,
        ]);

        EmployeeDashboardCacheService::put($cacheKey, $payload, EmployeeDashboardCacheService::RECENT_TTL, $employeeId);

        return response()->json($payload);
    }

    /**
     * Latest payslip summary for the current employee.
     */
    public function payslipSummary(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $user = $request->user();
        $employeeId = (int) $user->id;

        try {
            $latestPayslip = \App\Models\Payslip::query()
                ->select(['id', 'payroll_period_id', 'gross_pay', 'net_pay', 'total_deductions', 'created_at'])
                ->where('user_id', $employeeId)
                ->orderByDesc('created_at')
                ->first();

            if (! $latestPayslip) {
                return response()->json([
                    'has_payslip' => false,
                    'meta' => ['performance' => ['total_ms' => (int) round((microtime(true) - $startedAt) * 1000)]],
                ]);
            }

            $latestPayslip->loadMissing('payrollPeriod');

            return response()->json([
                'has_payslip' => true,
                'payslip' => [
                    'id' => $latestPayslip->id,
                    'period_label' => $latestPayslip->payrollPeriod?->name ?? 'N/A',
                    'gross_pay' => (float) $latestPayslip->gross_pay,
                    'net_pay' => (float) $latestPayslip->net_pay,
                    'total_deductions' => (float) $latestPayslip->total_deductions,
                    'created_at' => $latestPayslip->created_at?->toDateString(),
                ],
                'meta' => ['performance' => ['total_ms' => (int) round((microtime(true) - $startedAt) * 1000)]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[EmployeeDashboard] payslipSummary failed', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'has_payslip' => false,
                'error' => 'Unable to load payslip summary.',
                'meta' => ['performance' => ['total_ms' => (int) round((microtime(true) - $startedAt) * 1000)]],
            ]);
        }
    }

    private function latestPayslipSummary(int $employeeId): array
    {
        try {
            $latestPayslip = \App\Models\Payslip::query()
                ->select(['id', 'payroll_period_id', 'gross_pay', 'net_pay', 'total_deductions', 'created_at'])
                ->where('user_id', $employeeId)
                ->orderByDesc('created_at')
                ->first();

            if (! $latestPayslip) {
                return ['has_payslip' => false];
            }

            $latestPayslip->loadMissing('payrollPeriod');

            return [
                'has_payslip' => true,
                'id' => $latestPayslip->id,
                'period_label' => $latestPayslip->payrollPeriod?->name ?? 'N/A',
                'gross_pay' => (float) $latestPayslip->gross_pay,
                'net_pay' => (float) $latestPayslip->net_pay,
                'total_deductions' => (float) $latestPayslip->total_deductions,
                'created_at' => $latestPayslip->created_at?->toDateString(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[EmployeeDashboard] latestPayslipSummary failed', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return ['has_payslip' => false];
        }
    }

    private function buildTodayPayload(
        string $todayDate,
        Carbon $todayNow,
        ?array $daySchedule,
        ?array $effectiveSchedule,
        bool $scheduleAssigned,
        iterable $todayLogs,
        ?AttendanceCorrection $todayCorrection,
        ?LeaveRequest $todayLeave,
        bool $isRestDay,
        $user,
    ): array {
        $attendanceTz = $this->attendanceTimezone();

        $todayLogList = $todayLogs instanceof \Illuminate\Support\Collection
            ? $todayLogs->all()
            : (is_array($todayLogs) ? $todayLogs : []);
        [$effectiveTimeIn, $effectiveTimeOut, $hasTimeIn, $hasTimeOut] = $this->resolveDisplayClockTimes($todayLogList, $todayCorrection);

        $approvedOtHours = 0.0;
        $payableOtHours = 0.0;
        if ($user) {
            $todayOtQuery = Overtime::query()
                ->where('user_id', $user->id)
                ->whereDate('date', $todayDate);
            if (Schema::hasColumn('overtimes', 'voided_at')) {
                $todayOtQuery->whereNull('voided_at');
            }
            $todayOtRecords = $todayOtQuery->get()->all();
            $approvedTodayOt = array_values(array_filter(
                $todayOtRecords,
                fn ($o) => $o->status === Overtime::STATUS_APPROVED
            ));
            $approvedOtHours = $this->sumOvertimeHours($approvedTodayOt);
            $rawOtMinutesToday = 0;
            if (
                $effectiveTimeIn instanceof Carbon
                && $effectiveTimeOut instanceof Carbon
                && is_array($daySchedule)
                && ! empty($daySchedule['in'])
                && ! empty($daySchedule['out'])
                && ! $isRestDay
            ) {
                $rawOtMinutesToday = AttendanceStatusService::computeRawOvertimeBreakdown(
                    $todayDate,
                    $daySchedule,
                    $effectiveTimeIn,
                    $effectiveTimeOut,
                    $attendanceTz
                )['total_minutes'];
            }
            $payableOtMinutesToday = $approvedOtHours > 0
                ? $this->overtimePayroll->resolvePayableOtMinutes($rawOtMinutesToday, (int) round($approvedOtHours * 60))
                : 0;
            $payableOtHours = round($payableOtMinutesToday / 60, 2);
        }

        $holiday = $user ? $this->holidayService->getEffectiveHolidayForEmployee($user, $todayDate) : null;

        $resolved = $this->statusResolver->resolve(
            dateKey: $todayDate,
            todayDate: $todayDate,
            nowTz: $todayNow,
            effectiveSchedule: $effectiveSchedule,
            daySchedule: $daySchedule,
            dayLogs: $todayLogList,
            correction: $todayCorrection,
            holiday: $holiday,
            leave: $todayLeave,
            isRestDay: $isRestDay,
            isFuture: false,
            overtimeContext: [
                'approved_ot_hours' => $approvedOtHours,
                'payable_ot_hours' => $payableOtHours,
            ],
        );

        $status = $resolved['status'];
        $lateMinutes = ($resolved['late_minutes'] ?? 0) > 0 ? (int) $resolved['late_minutes'] : null;
        $lateLabel = $resolved['late_label'] ?? null;
        $undertimeMinutes = ($resolved['undertime_minutes'] ?? 0) > 0 ? (int) $resolved['undertime_minutes'] : null;

        if ($isRestDay && ! $hasTimeIn && ! $hasTimeOut) {
            $effectiveTimeIn = null;
            $effectiveTimeOut = null;
            $hasTimeIn = false;
            $hasTimeOut = false;
        }

        // Status label
        $isRestDayWorked = (bool) ($resolved['is_rest_day_worked'] ?? false);
        $labelMap = [
            'leave' => 'On leave',
            'rest' => $isRestDayWorked ? 'Rest Day Worked' : AttendanceStatusResolver::REST_DAY_LABEL,
            'absent' => 'Missed clock-in',
            'present' => $hasTimeIn && ! $hasTimeOut ? 'Working' : 'Present',
            'present_with_ot' => $hasTimeIn && ! $hasTimeOut ? 'Working' : 'Present with OT',
            'late' => $isRestDayWorked ? 'Rest Day Worked Late' : ($hasTimeIn && ! $hasTimeOut ? 'Working' : ($lateLabel ?: 'Late')),
            'halfday' => $isRestDayWorked ? 'Rest Day Worked Half Day' : ($hasTimeIn && ! $hasTimeOut ? 'Working' : 'Half Day'),
            'clocked_in' => 'Working',
            'undertime' => $isRestDayWorked ? 'Rest Day Worked Undertime' : 'Undertime',
            'incomplete' => 'Incomplete',
        ];

        return [
            'date' => $todayDate,
            'status' => $status,
            'status_label' => $resolved['presence_label']
                ?? ($labelMap[$status] ?? ($status === '—' && $isRestDay ? AttendanceStatusResolver::REST_DAY_LABEL : $status)),
            'presence_label' => $resolved['presence_label'] ?? null,
            'presence_issue' => $resolved['presence_issue'] ?? null,
            'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
            'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
            'formatted_time_in' => $this->formatTimeForDisplay($effectiveTimeIn),
            'formatted_time_out' => $this->formatTimeForDisplay($effectiveTimeOut),
            'late_minutes' => $lateMinutes,
            'late_label' => $lateLabel,
            'undertime_minutes' => $undertimeMinutes,
            'schedule_in' => is_array($daySchedule) ? ($daySchedule['in'] ?? null) : null,
            'schedule_out' => is_array($daySchedule) ? ($daySchedule['out'] ?? null) : null,
            'is_rest_day' => $isRestDay,
            'is_rest_day_worked' => $isRestDayWorked,
            'has_time_in' => $hasTimeIn,
            'has_time_out' => $hasTimeOut,
        ];
    }

    private function loadMonthHolidays($user, int $year, int $month): array
    {
        try {
            $monthStart = sprintf('%04d-%02d-01', $year, $month);
            $cursor = Carbon::parse($monthStart)->startOfMonth();
            $end = $cursor->copy()->endOfMonth();
            $holidays = [];

            while ($cursor->lessThanOrEqualTo($end)) {
                $dateKey = $cursor->toDateString();
                $holiday = $this->holidayService->getEffectiveHolidayForEmployee($user, $dateKey);
                if ($holiday !== null) {
                    $holidays[] = [
                        'id' => $holiday['id'] ?? null,
                        'date' => $dateKey,
                        'name' => $holiday['name'],
                        'type' => $holiday['type'],
                    ];
                }
                $cursor->addDay();
            }

            return $holidays;
        } catch (\Throwable $e) {
            Log::warning('Failed to load month holidays', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function resolveUpcomingPayrollBatch($user): ?array
    {
        try {
            $payCycle = $user->resolveEffectivePayCycle();
            if (! $payCycle) {
                return null;
            }

            $now = now();
            $cutoff = $payCycle->current_cutoff_date ?? null;
            $payDate = $payCycle->current_pay_date ?? null;

            if (! $cutoff || ! $payDate) {
                return null;
            }

            return [
                'cutoff_start' => $payCycle->current_cutoff_start?->toDateString(),
                'cutoff_end' => $cutoff instanceof Carbon ? $cutoff->toDateString() : (is_string($cutoff) ? $cutoff : null),
                'pay_date' => $payDate instanceof Carbon ? $payDate->toDateString() : (is_string($payDate) ? $payDate : null),
                'period_label' => $payCycle->name ?? 'Current Period',
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function zeroMetrics(): array
    {
        return [
            'present_count' => 0,
            'late_count' => 0,
            'absent_count' => 0,
            'leave_count' => 0,
            'halfday_count' => 0,
        ];
    }

    private function sumOvertimeHours(array $otRecords): float
    {
        $total = 0.0;
        foreach ($otRecords as $ot) {
            $total += (float) ($ot->approved_ot_hours ?? $ot->computed_hours ?? 0);
        }

        return $total;
    }

    private function attendanceTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'UTC'));
    }

    /**
     * Prefer the actual punch timestamp and fall back to insertion time for legacy rows.
     */
    private function attendanceLogPunchInstant(AttendanceLog $log): Carbon
    {
        $stamp = $log->verified_at ?? $log->created_at;

        return $stamp instanceof Carbon ? $stamp->copy() : Carbon::parse($stamp);
    }

    /**
     * Resolve clock-in / clock-out for calendar and today widgets from device logs + approved corrections.
     * Uses verified_at when available and keeps the latest clock-out when multiple exist.
     *
     * @param  list<AttendanceLog>|null  $dayLogs
     * @return array{0: ?Carbon, 1: ?Carbon, 2: bool, 3: bool}
     */
    private function resolveDisplayClockTimes(?array $dayLogs, ?AttendanceCorrection $correction): array
    {
        $timeIn = null;
        $timeOut = null;
        $tz = $this->attendanceTimezone();

        if ($dayLogs !== null) {
            foreach ($dayLogs as $log) {
                if (! $log instanceof AttendanceLog) {
                    continue;
                }
                if (($log->verified_at ?? $log->created_at) === null) {
                    continue;
                }
                $stamp = $this->attendanceLogPunchInstant($log)->timezone($tz);

                if ($log->type === AttendanceLog::TYPE_CLOCK_IN) {
                    if ($timeIn === null) {
                        $timeIn = $stamp;
                    }
                } elseif ($log->type === AttendanceLog::TYPE_CLOCK_OUT) {
                    $timeOut = $stamp;
                }
            }
        }

        if ($correction && $correction->approved && $correction->pending_approval !== true) {
            if ($correction->time_in) {
                $timeIn = $correction->time_in instanceof Carbon
                    ? $correction->time_in->copy()->timezone($tz)
                    : Carbon::parse($correction->time_in)->timezone($tz);
            }
            if ($correction->time_out) {
                $timeOut = $correction->time_out instanceof Carbon
                    ? $correction->time_out->copy()->timezone($tz)
                    : Carbon::parse($correction->time_out)->timezone($tz);
            }
        }

        return [$timeIn, $timeOut, $timeIn !== null, $timeOut !== null];
    }

    private function formatTimeInAttendanceTz(mixed $carbon): ?string
    {
        if ($carbon === null) {
            return null;
        }
        $c = $carbon instanceof Carbon ? $carbon : Carbon::parse($carbon);

        return $c->timezone($this->attendanceTimezone())->format('H:i:s');
    }

    private function formatTimeForDisplay(mixed $carbon): ?string
    {
        if ($carbon === null) {
            return null;
        }
        $c = $carbon instanceof Carbon ? $carbon : Carbon::parse($carbon);

        return $c->timezone($this->attendanceTimezone())->format('g:i A');
    }

    private function hydrateUserSchedule($user): void
    {
        $schedule = $user->schedule;
        if ((! is_array($schedule) || $schedule === [] || $schedule === null) && $user->working_schedule_id !== null) {
            $user->loadMissing('workingSchedule');
        }
    }

    private function buildScheduleFromWorkingSchedule(?\App\Models\WorkingSchedule $schedule): ?array
    {
        if (! $schedule) {
            return null;
        }

        $restDays = is_array($schedule->rest_days) ? $schedule->rest_days : [];

        $breaks = [];
        foreach ($schedule->getAllBreaks() as $b) {
            $breaks[] = [
                'start' => $b['start'],
                'end' => $b['end'],
                'is_paid' => $b['is_paid'] ?? false,
            ];
        }

        $baseDayConfig = [];

        foreach (self::DAY_KEYS as $dayKey) {
            if (in_array($dayKey, $restDays, true)) {
                $baseDayConfig[$dayKey] = null;

                continue;
            }

            $baseDayConfig[$dayKey] = [
                'in' => $schedule->time_in,
                'out' => $schedule->time_out,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'breaks' => $breaks,
                'work_blocks' => $schedule->getWorkBlocks(),
                'shift_type' => $schedule->shift_type ?? 'fixed',
                'crosses_midnight' => (bool) ($schedule->crosses_midnight ?? false),
                'expected_paid_minutes' => $schedule->expected_paid_minutes,
                'half_day_threshold_minutes' => $schedule->effective_half_day_threshold,
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
                'rest_days' => $restDays,
                'flexible_required_minutes' => $schedule->flexible_required_minutes,
            ];
        }

        return $baseDayConfig;
    }
}
