<?php

namespace App\Services;

use App\Models\AttendanceDailySummary;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Syncs attendance monitoring rows into the attendance_daily_summaries read model.
 *
 * Called after attendance-affecting events (clock in/out, correction approved,
 * OT approved, leave approved, schedule changed).
 */
class AttendanceSummarySyncService
{
    public function __construct(
        private readonly AttendanceCacheService $cacheService,
    ) {}

    /**
     * Sync a single employee+date from a pre-computed monitoring row array.
     */
    public function syncFromMonitoringRow(array $row): void
    {
        $employeeId = (int) ($row['employee_id'] ?? 0);
        $date = (string) ($row['date'] ?? '');

        if ($employeeId <= 0 || $date === '') {
            return;
        }

        $employee = User::query()
            ->select(['id', 'company_id', 'branch_id', 'department_id', 'profile_image'])
            ->find($employeeId);

        if (! $employee) {
            return;
        }

        $data = [
            'company_id' => $employee->company_id,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'employee_name' => $row['employee_name'] ?? null,
            'employee_code' => $row['employee_code'] ?? null,
            'position' => $row['position'] ?? null,
            'profile_image' => $row['profile_image'] ?? $employee->profile_image_url,
            'company_name' => $row['company_name'] ?? null,
            'branch_name' => $row['branch_name'] ?? null,
            'department_name' => $row['department'] ?? null,
            'day_name' => $row['day_name'] ?? null,
            'schedule_label' => $row['schedule_label'] ?? null,
            'schedule_in' => $row['schedule_in'] ?? null,
            'schedule_out' => $row['schedule_out'] ?? null,
            'time_in' => $row['time_in'] ?? null,
            'time_out' => $row['time_out'] ?? null,
            'formatted_time_in' => $row['formatted_time_in'] ?? null,
            'formatted_time_out' => $row['formatted_time_out'] ?? null,
            'time_out_next_day' => (bool) ($row['time_out_next_day'] ?? false),
            'total_hours' => $row['total_rendered_hours'] ?? $row['total_hours'] ?? null,
            'scheduled_regular_hours' => $row['scheduled_regular_hours'] ?? null,
            'late_minutes' => $row['late_minutes'] ?? null,
            'undertime_minutes' => $row['undertime_minutes'] ?? null,
            'overtime_minutes' => $row['overtime_minutes'] ?? null,
            'approved_ot_hours' => $row['approved_overtime_hours'] ?? null,
            'payable_ot_hours' => $row['payable_overtime_hours'] ?? null,
            'rendered_ot_hours' => $row['rendered_overtime_hours'] ?? $row['actual_rendered_overtime_hours'] ?? null,
            'nd_hours' => $row['night_hours'] ?? null,
            'overtime_pay' => $row['overtime_pay'] ?? null,
            'night_differential_pay' => $row['night_differential_pay'] ?? null,
            'total_premium_pay' => $row['total_premium_pay'] ?? null,
            'premium_type' => $row['premium_type'] ?? null,
            'status' => $row['status'] ?? '—',
            'presence_label' => $row['presence_label'] ?? null,
            'presence_issue' => $row['presence_issue'] ?? null,
            'overtime_status' => $row['overtime_status'] ?? null,
            'is_rest_day' => (bool) ($row['is_rest_day'] ?? false),
            'holiday_name' => $row['holiday_name'] ?? null,
            'holiday_type' => $row['holiday_type'] ?? null,
            'has_correction' => (bool) ($row['has_correction'] ?? false),
            'correction_approved' => (bool) ($row['correction_approved'] ?? false),
            'has_approved_overtime' => (bool) ($row['has_approved_overtime'] ?? false),
            'payroll_impact_hours' => $row['payroll_impact_hours'] ?? null,
            'extra' => array_filter([
                'late_label' => $row['late_label'] ?? null,
                'correction_id' => $row['correction_id'] ?? null,
                'correction_remarks' => $row['correction_remarks'] ?? null,
                'ot_payable_basis' => $row['ot_payable_basis'] ?? null,
                'overtime_reduction_reason' => $row['overtime_reduction_reason'] ?? null,
                'unapproved_overtime_hours' => $row['unapproved_overtime_hours'] ?? null,
                'approved_ot_end_time' => $row['approved_ot_end_time'] ?? null,
                'effective_expected_out' => $row['effective_expected_out'] ?? null,
                'calculated_pay_factor' => $row['calculated_pay_factor'] ?? null,
                'premium_description' => $row['premium_description'] ?? null,
                'employee_formatted_name' => $row['employee_formatted_name'] ?? null,
                'employee_level' => $row['employee_level'] ?? null,
                'employee_level_label' => $row['employee_level_label'] ?? null,
            ], fn ($v) => $v !== null),
        ];

        AttendanceDailySummary::upsertFromRow($employeeId, $date, $data);
    }

    /**
     * Sync multiple pre-computed monitoring rows (typically from the index computation).
     */
    public function syncBatch(array $rows): void
    {
        $startMs = microtime(true);
        $synced = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $this->syncFromMonitoringRow($row);
            $synced++;
        }

        $elapsedMs = (int) round((microtime(true) - $startMs) * 1000);
        if ($synced > 0) {
            Log::info('AttendanceSummarySyncService: synced batch', [
                'rows_synced' => $synced,
                'elapsed_ms' => $elapsedMs,
            ]);
        }
    }

    /**
     * Delete stale summaries for an employee outside a valid date range.
     */
    public function pruneStale(int $employeeId, string $beforeDate): void
    {
        AttendanceDailySummary::query()
            ->where('employee_id', $employeeId)
            ->where('date', '<', $beforeDate)
            ->delete();
    }
}
