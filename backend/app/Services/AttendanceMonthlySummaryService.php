<?php

namespace App\Services;

/**
 * Builds dashboard month metrics exclusively from shared computed daily rows.
 */
class AttendanceMonthlySummaryService
{
    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    public function summarize(array $days): array
    {
        $scheduledWorkdays = 0;
        $presentDays = 0;
        $absentDays = 0;
        $lateDays = 0;
        $undertimeDays = 0;
        $scheduledPaidHours = 0.0;
        $actualWorkedHours = 0.0;
        $payrollImpactHours = 0.0;
        $dailyBreakdown = [];

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $status = strtolower((string) ($day['status'] ?? ''));
            $isRestDay = (bool) ($day['is_rest_day'] ?? false)
                || in_array($status, ['rest', 'rest_day', 'no_schedule_rest'], true);
            $dayScheduledHours = max(0.0, (float) ($day['scheduled_regular_hours'] ?? 0));
            $dayWorkedHours = max(0.0, (float) ($day['worked_hours'] ?? $day['total_hours'] ?? 0));
            $dayPayrollImpactHours = max(0.0, (float) ($day['payroll_impact_hours'] ?? 0));
            $isPaidNonWorkingDay = in_array($status, ['leave', 'holiday'], true)
                && $dayPayrollImpactHours > 0;
            $isScheduledWorkday = ! $isRestDay
                && $dayScheduledHours > 0
                && ($status !== 'holiday' || $isPaidNonWorkingDay);

            if ($isScheduledWorkday) {
                $scheduledWorkdays++;
                $scheduledPaidHours += $dayScheduledHours;

                if ($status === 'absent') {
                    $absentDays++;
                }
                if ((int) ($day['late_minutes'] ?? 0) > 0) {
                    $lateDays++;
                }
                if ((int) ($day['undertime_minutes'] ?? 0) > 0) {
                    $undertimeDays++;
                }

                $isComputedPresent = (bool) ($day['is_present'] ?? false)
                    || in_array($status, ['present', 'present_with_ot', 'late', 'halfday', 'half_day', 'undertime', 'incomplete', 'clocked_in'], true);
                if ($isComputedPresent || $isPaidNonWorkingDay) {
                    $presentDays++;
                }
            }

            // Rest days never inflate scheduled denominators, but actual work/pay still
            // contributes to the month totals exactly as computed by payroll.
            if (! $isRestDay || (bool) ($day['is_rest_day_worked'] ?? false)) {
                $actualWorkedHours += $dayWorkedHours;
                $payrollImpactHours += $dayPayrollImpactHours;
            }

            $contribution = $dayScheduledHours > 0
                ? ($dayPayrollImpactHours / $dayScheduledHours) * 100
                : null;

            $dailyBreakdown[] = [
                'date' => $day['date'] ?? null,
                'schedule' => $this->scheduleLabel($day, $isRestDay),
                'schedule_in' => $day['schedule_in'] ?? null,
                'schedule_out' => $day['schedule_out'] ?? null,
                'time_in' => $day['time_in'] ?? null,
                'time_out' => $day['time_out'] ?? null,
                'formatted_time_in' => $day['formatted_time_in'] ?? null,
                'formatted_time_out' => $day['formatted_time_out'] ?? null,
                'status' => $day['status'] ?? null,
                'status_label' => $day['status_label'] ?? $day['display_badge'] ?? null,
                'late_minutes' => (int) ($day['late_minutes'] ?? 0),
                'undertime_minutes' => (int) ($day['undertime_minutes'] ?? 0),
                'scheduled_paid_hours' => round($dayScheduledHours, 2),
                'actual_worked_hours' => round($dayWorkedHours, 2),
                'payroll_impact_hours' => round($dayPayrollImpactHours, 2),
                'efficiency_contribution' => $contribution !== null ? round($contribution, 2) : null,
                'is_scheduled_workday' => $isScheduledWorkday,
                'is_rest_day' => $isRestDay,
                'is_rest_day_worked' => (bool) ($day['is_rest_day_worked'] ?? false),
            ];
        }

        $efficiency = $scheduledPaidHours > 0
            ? ($payrollImpactHours / $scheduledPaidHours) * 100
            : 0.0;

        return [
            'scheduled_workdays' => $scheduledWorkdays,
            'present_days' => $presentDays,
            'present_percentage' => $this->percentage($presentDays, $scheduledWorkdays),
            'absent_days' => $absentDays,
            'absent_percentage' => $this->percentage($absentDays, $scheduledWorkdays),
            'late_days' => $lateDays,
            'late_percentage' => $this->percentage($lateDays, $scheduledWorkdays),
            'undertime_days' => $undertimeDays,
            'undertime_percentage' => $this->percentage($undertimeDays, $scheduledWorkdays),
            'scheduled_paid_hours' => round($scheduledPaidHours, 2),
            'actual_worked_hours' => round($actualWorkedHours, 2),
            'payroll_impact_hours' => round($payrollImpactHours, 2),
            'efficiency_percentage' => round($efficiency, 2),
            'daily_breakdown' => $dailyBreakdown,
        ];
    }

    private function percentage(int $count, int $denominator): float
    {
        return $denominator > 0 ? round(($count / $denominator) * 100, 2) : 0.0;
    }

    /** @param array<string, mixed> $day */
    private function scheduleLabel(array $day, bool $isRestDay): string
    {
        if (is_string($day['schedule_label'] ?? null) && trim((string) $day['schedule_label']) !== '') {
            return (string) $day['schedule_label'];
        }

        if ($isRestDay) {
            return AttendanceStatusResolver::REST_DAY_LABEL;
        }

        $start = trim((string) ($day['schedule_in'] ?? ''));
        $end = trim((string) ($day['schedule_out'] ?? ''));

        return $start !== '' && $end !== '' ? "{$start} - {$end}" : '—';
    }
}
