<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Services\AttendanceCacheService;
use App\Services\AttendanceRollupService;
use App\Services\AttendanceStatusResolver;
use App\Services\AttendanceStatusService;
use App\Services\EmployeeDashboardCacheService;
use App\Services\OtDetectionService;
use App\Services\OvertimePayrollService;
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
        if (is_array($cached) && ($cached['meta']['schema_version'] ?? null) === 4) {
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
            ->whereBetween('verified_at', [
                $todayNow->copy()->startOfDay()->setTimezone('UTC'),
                $todayNow->copy()->endOfDay()->setTimezone('UTC'),
            ])
            ->orderBy('verified_at')
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
                'schema_version' => 4,
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

        $from = Carbon::parse("{$year}-{$month}-01")->startOfDay();
        $to = $from->copy()->endOfMonth();
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        $cacheKey = EmployeeDashboardCacheService::calendarKey($employeeId, $yearMonth);
        $cached = EmployeeDashboardCacheService::get($cacheKey);
        if (is_array($cached) && ($cached['meta']['schema_version'] ?? null) === 4) {
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
            ->whereBetween('verified_at', [$from->copy()->startOfDay()->setTimezone('UTC'), $to->copy()->endOfDay()->setTimezone('UTC')])
            ->orderBy('verified_at')
            ->get()
            ->groupBy(fn ($l) => $l->verified_at->copy()->timezone($attendanceTz)->toDateString());

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

        // Build days array for month
        $days = [];
        $metrics = $this->zeroMetrics();
        $extraMetrics = [
            'late_minutes' => 0,
            'undertime_count' => 0,
            'total_worked_minutes' => 0,
        ];
        $undertimeThresholdMinutes = (int) config('attendance.undertime_threshold_minutes', 60);
        $cursor = $from->copy();
        $holidayMap = [];
        foreach ($holidays as $h) {
            if ($h['date'] ?? null) {
                $holidayMap[$h['date']] = $h;
            }
        }

        $absentCounts = ['leave' => 0, 'rest' => 0, 'holiday' => 0, 'absent' => 0];

        while ($cursor->lessThanOrEqualTo($to)) {
            $dateKey = $cursor->toDateString();
            $dayKey = self::DAY_KEYS[(int) $cursor->format('w')];
            $isToday = $dateKey === $todayDate;
            $isFuture = $cursor->greaterThan($todayNow->copy()->startOfDay());
            $daySchedule = $scheduleAssigned && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;
            $isRestDay = $this->attendanceRollup->isScheduledRestDay($effectiveSchedule, $daySchedule);
            $holidayOnDate = $holidayMap[$dateKey] ?? null;
            $leaveOnDate = $leaveByDate[$dateKey] ?? null;

            $dayLogs = isset($logs[$dateKey]) ? $logs[$dateKey]->all() : null;

            $correctionCollection = $corrections->get($dateKey);
            $correction = $correctionCollection?->first();

            $resolved = $this->statusResolver->resolve(
                dateKey: $dateKey,
                todayDate: $todayDate,
                nowTz: $todayNow,
                effectiveSchedule: $effectiveSchedule,
                daySchedule: $daySchedule,
                dayLogs: $dayLogs,
                correction: $correction,
                holiday: $holidayOnDate,
                leave: $leaveOnDate,
                isRestDay: $isRestDay,
                isFuture: $isFuture,
            );

            $status = $resolved['status'];
            [$effectiveTimeIn, $effectiveTimeOut, $hasTimeIn, $hasTimeOut] = $this->resolveDisplayClockTimes($dayLogs, $correction);
            $effectiveWorkedMinutes = $resolved['effective_worked_minutes'];
            if ($hasTimeIn && $hasTimeOut && $effectiveTimeIn && $effectiveTimeOut && is_array($daySchedule)) {
                $in = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                $out = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                if ($out->greaterThan($in)) {
                    $effectiveWorkedMinutes = AttendanceStatusService::getNetWorkedMinutes(
                        $in,
                        $out,
                        $daySchedule,
                        $dateKey,
                        $attendanceTz
                    );
                }
            }
            $dayLateMinutes = 0;
            $dayLateLabel = null;
            $dayUndertimeMinutes = 0;
            $rawOtMinutes = 0;
            $rawPreOtSegment = null;
            $rawPostOtSegment = null;
            $hasIncompletePunchPair = ($hasTimeIn xor $hasTimeOut);

            // Track extra metrics for monthly overview using the legacy /attendance/summary rules.
            if (
                $effectiveTimeIn instanceof Carbon
                && is_array($daySchedule)
                && ! empty($daySchedule['in'])
                && ! in_array($status, ['holiday', 'leave', 'rest', 'upcoming', 'absent'], true)
                && ! $isRestDay
            ) {
                $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $dateKey, $effectiveTimeIn);
                if ($clockInResult['status'] === 'late') {
                    $dayLateMinutes = (int) $clockInResult['late_minutes'];
                    $dayLateLabel = $clockInResult['late_label'] ?? 'Late';
                    $extraMetrics['late_minutes'] += $dayLateMinutes;
                } elseif ($clockInResult['status'] === 'half_day') {
                    $dayLateLabel = $clockInResult['late_label'] ?? 'Half Day';
                } else {
                    $dayLateLabel = $clockInResult['late_label'] ?? null;
                }
            }

            if (
                $status !== 'halfday'
                && $effectiveTimeIn instanceof Carbon
                && $effectiveTimeOut instanceof Carbon
                && is_array($daySchedule)
                && ! empty($daySchedule['in'])
                && ! empty($daySchedule['out'])
                && ! in_array($status, ['holiday', 'leave', 'rest', 'upcoming', 'absent'], true)
                && ! $isRestDay
            ) {
                $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $attendanceTz);
                if ($scheduledEnd) {
                    $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($dateKey, $daySchedule, $attendanceTz);
                    $earlyTimeout = isset($daySchedule['early_timeout_minutes']) ? (int) $daySchedule['early_timeout_minutes'] : null;
                    $undertimeMinutes = AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                        $dateKey,
                        $daySchedule,
                        $effectiveTimeIn,
                        $effectiveTimeOut,
                        $attendanceTz,
                        $earlyTimeout
                    );
                    $dayUndertimeMinutes = $undertimeMinutes > 0 ? $undertimeMinutes : 0;
                    if ($undertimeMinutes > 0 || ($effectiveWorkedMinutes !== null && $effectiveWorkedMinutes < $requiredMinutes - $undertimeThresholdMinutes)) {
                        $extraMetrics['undertime_count']++;
                        $status = 'undertime';
                    }
                }
            }

            if (
                $status === 'present'
                && $hasIncompletePunchPair
                && ! in_array($status, ['holiday', 'leave', 'rest', 'upcoming', 'absent'], true)
                && ! $isRestDay
            ) {
                $status = 'undertime';
                $dayUndertimeMinutes = $dayUndertimeMinutes > 0 ? $dayUndertimeMinutes : null;
                $extraMetrics['undertime_count']++;
            }

            if (in_array($status, ['present', 'late', 'halfday', 'undertime', 'incomplete', 'clocked_in'], true) && $effectiveWorkedMinutes !== null) {
                $extraMetrics['total_worked_minutes'] += $effectiveWorkedMinutes;
            }

            if ($effectiveTimeIn instanceof Carbon && $effectiveTimeOut instanceof Carbon && is_array($daySchedule) && ! empty($daySchedule['in']) && ! empty($daySchedule['out']) && ! $isRestDay) {
                $scheduledStartForOt = AttendanceStatusService::getScheduledStartForDate($dateKey, $daySchedule, $attendanceTz);
                $scheduledEndForOt = AttendanceStatusService::getScheduledEndForDate($dateKey, $daySchedule, $attendanceTz);

                if ($scheduledStartForOt && $effectiveTimeIn->lessThan($scheduledStartForOt)) {
                    $preMinutes = (int) $effectiveTimeIn->diffInMinutes($scheduledStartForOt);
                    if ($preMinutes > 0) {
                        $rawOtMinutes += $preMinutes;
                        $rawPreOtSegment = [
                            'kind' => 'pre_shift',
                            'start' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                            'end' => $this->formatTimeInAttendanceTz($scheduledStartForOt),
                            'minutes' => $preMinutes,
                            'hours' => round($preMinutes / 60, 2),
                        ];
                    }
                }

                if ($scheduledEndForOt) {
                    $overtimeBuffer = isset($daySchedule['overtime_buffer_minutes'])
                        ? (int) $daySchedule['overtime_buffer_minutes']
                        : (int) config('attendance.overtime_buffer_minutes', 15);
                    $postShiftOtStart = $scheduledEndForOt->copy()->addMinutes($overtimeBuffer);
                    if ($effectiveTimeOut->greaterThan($postShiftOtStart)) {
                        $postMinutes = (int) $postShiftOtStart->diffInMinutes($effectiveTimeOut);
                        if ($postMinutes > 0) {
                            $rawOtMinutes += $postMinutes;
                            $rawPostOtSegment = [
                                'kind' => 'post_shift',
                                'start' => $this->formatTimeInAttendanceTz($postShiftOtStart),
                                'end' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                                'minutes' => $postMinutes,
                                'hours' => round($postMinutes / 60, 2),
                            ];
                        }
                    }
                }
            }

            // Count for absent categories (rest days never count as absent)
            if ($status === 'absent' && ! $isRestDay) {
                if ($holidayOnDate) {
                    $absentCounts['holiday']++;
                } elseif ($leaveOnDate) {
                    $absentCounts['leave']++;
                } else {
                    $absentCounts['absent']++;
                }
            }

            // OT processing
            $otRecords = $overtimesByDate->get($dateKey)?->all() ?? [];
            $approvedOtRecords = array_values(array_filter($otRecords, fn ($o) => $o->status === Overtime::STATUS_APPROVED));
            $approvedOtHours = $this->sumOvertimeHours($approvedOtRecords);
            $actualRenderedOtHours = $rawOtMinutes > 0 ? round($rawOtMinutes / 60, 2) : 0.0;
            $payableOtMinutes = $approvedOtHours > 0
                ? $this->overtimePayroll->resolvePayableOtMinutes($rawOtMinutes, (int) round($approvedOtHours * 60))
                : 0;
            $payableOtHours = round($payableOtMinutes / 60, 2);
            $unapprovedOtHours = ($approvedOtHours > 0.0001 || $actualRenderedOtHours > 0.0001)
                ? abs(round($actualRenderedOtHours - $approvedOtHours, 2))
                : 0.0;
            if ($actualRenderedOtHours <= 0.0001 && $approvedOtHours > 0) {
                $unapprovedOtHours = 0.0;
            }

            if ($status === 'present' || $status === 'late') {
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

            $days[] = [
                'date' => $dateKey,
                'status' => in_array($status, ['rest', 'rest_day', 'no_schedule_rest'], true) ? 'rest' : $status,
                'status_label' => AttendanceStatusResolver::statusLabel($status),
                'is_rest_day' => $isRestDay || $status === 'rest',
                'schedule_label' => ($isRestDay || $status === 'rest') ? AttendanceStatusResolver::REST_DAY_LABEL : null,
                'holiday_name' => $holidayOnDate['name'] ?? null,
                'holiday_type' => $holidayOnDate['type'] ?? null,
                'schedule_in' => is_array($daySchedule) ? ($daySchedule['in'] ?? null) : null,
                'schedule_out' => is_array($daySchedule) ? ($daySchedule['out'] ?? null) : null,
                'time_in' => $this->formatTimeInAttendanceTz($effectiveTimeIn),
                'time_out' => $this->formatTimeInAttendanceTz($effectiveTimeOut),
                'formatted_time_in' => $this->formatTimeForDisplay($effectiveTimeIn),
                'formatted_time_out' => $this->formatTimeForDisplay($effectiveTimeOut),
                'total_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
                'total_rendered_hours' => $effectiveWorkedMinutes !== null ? round($effectiveWorkedMinutes / 60, 2) : null,
                'late_minutes' => $dayLateMinutes,
                'late_label' => $dayLateLabel,
                'undertime_minutes' => $dayUndertimeMinutes,
                'overtime_minutes' => $rawOtMinutes > 0 ? $rawOtMinutes : null,
                'raw_overtime_minutes' => $rawOtMinutes > 0 ? $rawOtMinutes : null,
                'raw_overtime_hours' => $rawOtMinutes > 0 ? round($rawOtMinutes / 60, 2) : null,
                'raw_pre_ot' => $rawPreOtSegment,
                'raw_post_ot' => $rawPostOtSegment,
                'presence_label' => $resolved['presence_label'],
                'presence_issue' => $resolved['presence_issue'],
                'approved_overtime_hours' => $approvedOtHours > 0 ? round($approvedOtHours, 2) : null,
                'actual_rendered_overtime_hours' => ($rawOtMinutes > 0 || $approvedOtHours > 0) ? $actualRenderedOtHours : null,
                'rendered_overtime_hours' => ($rawOtMinutes > 0 || $approvedOtHours > 0) ? $actualRenderedOtHours : null,
                'payable_overtime_hours' => $payableOtHours > 0 ? $payableOtHours : null,
                'unapproved_overtime_hours' => $unapprovedOtHours > 0.0001 ? round($unapprovedOtHours, 2) : null,
            ];

            $cursor->addDay();
        }

        $monthSummary = $this->attendanceRollup->summarizeEmployeeDays($days);

        // Merge extra metrics from the AttendanceController-style summary.
        $monthSummary['late_minutes'] = $extraMetrics['late_minutes'];
        $monthSummary['undertime_count'] = $extraMetrics['undertime_count'];
        $monthSummary['total_hours'] = $extraMetrics['total_worked_minutes'] > 0
            ? round($extraMetrics['total_worked_minutes'] / 60, 2)
            : 0;

        $payload = [
            'year' => $year,
            'month' => $month,
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
                'schema_version' => 4,
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
                'schema_version' => 4,
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

        $status = '—';
        $lateMinutes = null;
        $lateLabel = null;
        $undertimeMinutes = null;

        if ($todayLeave) {
            $status = 'leave';
        } elseif ($isRestDay) {
            $status = 'rest';
            $effectiveTimeIn = null;
            $effectiveTimeOut = null;
            $hasTimeIn = false;
            $hasTimeOut = false;
        } elseif ($scheduleAssigned && $daySchedule && ! empty($daySchedule['in'])) {
            if (! $hasTimeIn && ! $hasTimeOut) {
                $pastCutoff = AttendanceStatusService::isPastAbsentCutoff($todayDate, $todayNow);
                if ($pastCutoff) {
                    $status = 'absent';
                }
            } elseif ($hasTimeIn) {
                $timeInCarbon = $effectiveTimeIn instanceof Carbon ? $effectiveTimeIn : Carbon::parse($effectiveTimeIn);
                $clockInResult = AttendanceStatusService::getClockInStatus($daySchedule, $todayDate, $timeInCarbon);

                if ($clockInResult['status'] === 'half_day') {
                    $status = 'halfday';
                    $lateLabel = 'Half Day';
                } elseif ($clockInResult['status'] === 'late') {
                    $status = 'late';
                    $lateMinutes = $clockInResult['late_minutes'];
                    $lateLabel = $clockInResult['late_label'];
                } else {
                    $status = 'present';
                    $lateLabel = 'Present';
                }

                // Undertime check
                if ($hasTimeIn && $hasTimeOut && ! empty($daySchedule['out'])) {
                    $outCarbon = $effectiveTimeOut instanceof Carbon ? $effectiveTimeOut : Carbon::parse($effectiveTimeOut);
                    $inCarbon = $timeInCarbon;
                    $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($todayDate, $daySchedule, $attendanceTz);
                    $undertimeThreshold = (int) config('attendance.undertime_threshold_minutes', 60);

                    if ($scheduledEnd && $outCarbon->lessThan($scheduledEnd)) {
                        $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes($todayDate, $daySchedule, $attendanceTz);
                        $netWorked = AttendanceStatusService::getScheduleClippedNetWorkedMinutes(
                            $inCarbon, $outCarbon, $daySchedule, $todayDate, $attendanceTz
                        );
                        $undertimeMinutes = max(0, $requiredMinutes - $netWorked);
                        if ($undertimeMinutes > 0) {
                            $status = 'undertime';
                        }
                    }
                }

                // Clocked in without time-out
                if ($hasTimeIn && ! $hasTimeOut && ! empty($daySchedule['out'])) {
                    $scheduledEnd = AttendanceStatusService::getScheduledEndForDate($todayDate, $daySchedule, $attendanceTz);
                    if ($scheduledEnd && $todayNow->greaterThan($scheduledEnd)) {
                        $status = 'incomplete';
                    } else {
                        $status = 'clocked_in';
                    }
                }
            }
        }

        // Status label
        $labelMap = [
            'leave' => 'On leave',
            'rest' => AttendanceStatusResolver::REST_DAY_LABEL,
            'absent' => 'Missed clock-in',
            'present' => $hasTimeIn && ! $hasTimeOut ? 'Working' : 'Present',
            'late' => $hasTimeIn && ! $hasTimeOut ? 'Working' : ($lateLabel ?: 'Late'),
            'halfday' => $hasTimeIn && ! $hasTimeOut ? 'Working' : 'Half Day',
            'clocked_in' => 'Working',
            'undertime' => 'Undertime',
            'incomplete' => 'Incomplete',
        ];

        return [
            'date' => $todayDate,
            'status' => $status,
            'status_label' => $labelMap[$status] ?? ($status === '—' && $isRestDay ? AttendanceStatusResolver::REST_DAY_LABEL : $status),
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
            'has_time_in' => $hasTimeIn,
            'has_time_out' => $hasTimeOut,
        ];
    }

    private function loadMonthHolidays($user, int $year, int $month): array
    {
        try {
            $holidayModel = new \App\Models\Holiday;
            $monthStart = sprintf('%04d-%02d-01', $year, $month);
            $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();

            $query = $holidayModel->whereBetween('date', [$monthStart, $monthEnd])
                ->where('status', 'active');

            if ($user->company_id) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('company_id')
                        ->orWhere('company_id', $user->company_id);
                    if ($user->branch_id) {
                        $q->orWhereNull('branch_id')
                            ->orWhere('branch_id', $user->branch_id);
                    }
                    if ($user->department_id) {
                        $q->orWhereNull('department_id')
                            ->orWhere('department_id', $user->department_id);
                    }
                });
            }

            return $query->get(['id', 'date', 'name', 'type'])
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'date' => $h->date instanceof Carbon ? $h->date->toDateString() : (is_string($h->date) ? substr($h->date, 0, 10) : ''),
                    'name' => $h->name,
                    'type' => $h->type,
                ])
                ->toArray();
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
     * Resolve clock-in / clock-out for calendar and today widgets from device logs + approved corrections.
     * Uses verified_at (not created_at) and keeps the latest clock-out when multiple exist.
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
                $stamp = $log->verified_at ?? $log->created_at;
                if ($stamp === null) {
                    continue;
                }
                $stamp = $stamp instanceof Carbon
                    ? $stamp->copy()->timezone($tz)
                    : Carbon::parse($stamp)->timezone($tz);

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

        $restDays = $schedule->rest_days ?? [];
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
                'grace_period_minutes' => $schedule->grace_period_minutes,
                'early_timein_minutes' => $schedule->early_timein_minutes ?? 60,
                'late_allowance_minutes' => $schedule->late_allowance_minutes,
                'early_timeout_minutes' => $schedule->early_timeout_minutes,
                'overtime_buffer_minutes' => $schedule->overtime_buffer_minutes ?? 15,
            ];
        }

        return $baseDayConfig;
    }
}
