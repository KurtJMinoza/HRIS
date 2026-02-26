<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AttendanceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Dashboard stats, chart data, and today's attendance logs.
     */
    public function index(): JsonResponse
    {
        $today = today();
        $dayKey = self::DAY_KEYS[(int) $today->format('w')];
        $dateKey = $today->toDateString();
        $undertimeThresholdMinutes = config('attendance.undertime_threshold_minutes', 60);

        $activeEmployees = User::where('role', User::ROLE_EMPLOYEE)->where('is_active', true)->get();
        $activeEmployeeIds = $activeEmployees->pluck('id')->all();
        $totalEmployees = $activeEmployees->count();

        // Employees on approved leave today (distinct users with approved leave covering today)
        $leaveTodayUserIds = LeaveRequest::query()
            ->whereIn('user_id', $activeEmployeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->pluck('user_id')
            ->unique();
        $leaveTodaySet = array_fill_keys($leaveTodayUserIds->all(), true);

        // First clock-in per user today (for present + late + half day)
        $firstClockInToday = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereDate('created_at', $today)
            ->select('user_id', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $presentUserIds = $firstClockInToday->keys()->all();
        $presentToday = count($presentUserIds);

        $lateToday = 0;
        $halfDay = 0;
        $underTimeToday = 0;
        foreach ($activeEmployees as $user) {
            $firstAt = $firstClockInToday->get($user->id)?->first_at;
            if (! $firstAt) {
                continue;
            }
            $schedule = $user->schedule;
            $todaySchedule = is_array($schedule) && isset($schedule[$dayKey]) ? $schedule[$dayKey] : null;
            if ($todaySchedule && ! empty($todaySchedule['in'])) {
                $firstAtCarbon = $firstAt instanceof Carbon ? $firstAt : Carbon::parse($firstAt);
                $clockInResult = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $firstAtCarbon);
                if ($clockInResult['status'] === 'late') {
                    $lateToday++;
                }
                if ($clockInResult['status'] === 'half_day') {
                    $halfDay++;
                }
            }
            // Under time: required hours from schedule vs actual (clock_out - clock_in). If no clock_out yet, skip.
            if ($todaySchedule && ! empty($todaySchedule['in']) && ! empty($todaySchedule['out'])) {
                $requiredMinutes = AttendanceStatusService::getRequiredWorkingMinutes(
                    $today->format('Y-m-d'),
                    $todaySchedule,
                    config('attendance.timezone', config('app.timezone', 'UTC'))
                );
                $logsToday = AttendanceLog::where('user_id', $user->id)
                    ->whereDate('created_at', $today)
                    ->orderBy('created_at')
                    ->get();
                $workedMinutes = $this->workedMinutesFromLogs($logsToday);
                if ($workedMinutes !== null && $workedMinutes < $requiredMinutes - $undertimeThresholdMinutes) {
                    $underTimeToday++;
                }
            }
        }

        $expectedToday = 0;
        $absentToday = 0;
        foreach ($activeEmployees as $user) {
            $s = $user->schedule;
            $todaySched = is_array($s) && isset($s[$dayKey]) ? $s[$dayKey] : null;
            if (! $todaySched || empty($todaySched['in'])) {
                continue;
            }
            $expectedToday++;

            // Skip employees on approved leave today from absence calculation.
            if (isset($leaveTodaySet[$user->id])) {
                continue;
            }

            $firstAt = $firstClockInToday->get($user->id)?->first_at;
            if ($firstAt) {
                continue;
            }
            // No clock-in: mark absent only after cutoff (e.g. 5:00 PM)
            if (AttendanceStatusService::isPastAbsentCutoff($dateKey, now())) {
                $absentToday++;
            }
        }

        $onLeave = $leaveTodayUserIds->count();

        $stats = [
            'total_employees' => $totalEmployees,
            'present_today' => $presentToday,
            'late_today' => $lateToday,
            'absent_today' => $absentToday,
            'on_leave' => $onLeave,
            'half_day' => $halfDay,
            'under_time' => $underTimeToday,
        ];

        $weeklyOverview = $this->weeklyAttendanceOverview();
        $monthlyLateStats = $this->monthlyLateStatistics();
        $departmentDistribution = $this->departmentAttendanceDistribution($today);
        $todayLogs = $this->todayAttendanceLogs($today, $dayKey);

        return response()->json([
            'stats' => $stats,
            'weekly_overview' => $weeklyOverview,
            'monthly_late' => $monthlyLateStats,
            'department_distribution' => $departmentDistribution,
            'today_logs' => $todayLogs,
        ]);
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
     * Weekly attendance: last 7 days, count of distinct users who clocked in each day.
     */
    private function weeklyAttendanceOverview(): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $presentCount = (int) AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereDate('created_at', $date)
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
    private function monthlyLateStatistics(): array
    {
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = now()->subMonths($i)->copy()->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $firstClockIns = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereBetween('created_at', [$start, $end])
                ->select('user_id', DB::raw('DATE(created_at) as d'), DB::raw('MIN(created_at) as first_at'))
                ->groupBy('user_id', DB::raw('DATE(created_at)'))
                ->get();
            $lateCount = 0;
            foreach ($firstClockIns as $row) {
                $date = Carbon::parse($row->d);
                $dayKey = self::DAY_KEYS[(int) $date->format('w')];
                $user = User::find($row->user_id);
                if (! $user || ! is_array($user->schedule) || ! isset($user->schedule[$dayKey]['in'])) {
                    continue;
                }
                $todaySchedule = $user->schedule[$dayKey];
                $dateKey = $date->format('Y-m-d');
                $firstAtCarbon = Carbon::parse($row->first_at);
                $result = AttendanceStatusService::getClockInStatus($todaySchedule, $dateKey, $firstAtCarbon);
                if ($result['status'] === 'late') {
                    $lateCount++;
                }
            }
            $months[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'late_count' => $lateCount,
            ];
        }
        return $months;
    }

    private function lateFrequencyChart(): array
    {
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $dayKey = self::DAY_KEYS[(int) $date->format('w')];
            $firstClockIns = AttendanceLog::query()
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->whereDate('created_at', $date)
                ->select('user_id', DB::raw('MIN(created_at) as first_at'))
                ->groupBy('user_id')
                ->get();
            $lateCount = 0;
            foreach ($firstClockIns as $row) {
                $user = User::find($row->user_id);
                if (! $user || ! $user->schedule || ! isset($user->schedule[$dayKey]['in'])) {
                    continue;
                }
                $todaySchedule = $user->schedule[$dayKey];
                $dateKey = $date->format('Y-m-d');
                $firstAtCarbon = Carbon::parse($row->first_at);
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

    private function departmentAttendanceDistribution($today): array
    {
        $presentUserIds = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->whereDate('created_at', $today)
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');
        $users = User::whereIn('id', $presentUserIds)->get();
        $byDept = $users->groupBy(function (User $u) {
            $d = $u->department ?? 'Unassigned';
            return is_string($d) ? $d : 'Unassigned';
        })->map->count();
        return $byDept->map(function ($count, $name) {
            return ['department' => $name, 'count' => $count];
        })->values()->all();
    }

    private function todayAttendanceLogs($today, string $dayKey): array
    {
        $logs = AttendanceLog::query()
            ->with('user:id,name,schedule,profile_image,department')
            ->whereDate('created_at', $today)
            ->orderBy('created_at')
            ->get();

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
                $profileImageUrl = $user->profile_image
                    ? asset('storage/' . $user->profile_image)
                    : null;

                $grouped[$userId] = [
                    'id' => $userId,
                    'employee_name' => $user->name ?? '—',
                    'profile_image' => $profileImageUrl,
                    'department' => $user->department ?? '—',
                    'time_in' => null,
                    'time_out' => null,
                    'is_late' => false,
                    'late_label' => null,
                    'is_half_day' => false,
                ];
                $schedules[$userId] = $user->schedule;
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
