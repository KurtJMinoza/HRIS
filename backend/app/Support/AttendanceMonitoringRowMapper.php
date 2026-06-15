<?php

namespace App\Support;

use App\Services\AdminAttendanceCacheService;

class AttendanceMonitoringRowMapper
{
    /**
     * Lightweight list row for admin attendance table.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function toListRow(array $row): array
    {
        $employeeId = (int) ($row['employee_id'] ?? 0);
        $date = (string) ($row['date'] ?? '');

        return [
            'attendance_id' => AdminAttendanceCacheService::attendanceId($employeeId, $date),
            'employee_id' => $employeeId,
            'employee_name' => $row['employee_name'] ?? null,
            'employee_formatted_name' => $row['employee_formatted_name'] ?? null,
            'employee_sort_key' => $row['employee_sort_key'] ?? null,
            'profile_image' => $row['profile_image'] ?? null,
            'company_name' => $row['company_name'] ?? null,
            'department' => $row['department'] ?? null,
            'date' => $date,
            'day_name' => $row['day_name'] ?? null,
            'schedule_in' => $row['schedule_in'] ?? null,
            'schedule_out' => $row['schedule_out'] ?? null,
            'time_in' => $row['time_in'] ?? null,
            'time_out' => $row['time_out'] ?? null,
            'formatted_time_in' => $row['formatted_time_in'] ?? null,
            'formatted_time_out' => $row['formatted_time_out'] ?? null,
            'time_out_next_day' => $row['time_out_next_day'] ?? false,
            'total_hours' => $row['total_hours'] ?? $row['total_rendered_hours'] ?? null,
            'total_rendered_hours' => $row['total_rendered_hours'] ?? $row['total_hours'] ?? null,
            'status' => $row['status'] ?? null,
            'presence_label' => $row['presence_label'] ?? null,
            'presence_issue' => $row['presence_issue'] ?? null,
            'late_minutes' => $row['late_minutes'] ?? null,
            'late_label' => $row['late_label'] ?? null,
            'undertime_minutes' => $row['undertime_minutes'] ?? null,
            'overtime_minutes' => $row['overtime_minutes'] ?? null,
            'has_correction' => $row['has_correction'] ?? false,
            'correction_approved' => $row['correction_approved'] ?? false,
            'correction_id' => $row['correction_id'] ?? null,
        ];
    }

    /**
     * Detail-lite payload for attendance modal.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function toDetailLite(array $row): array
    {
        return [
            'attendance_id' => AdminAttendanceCacheService::attendanceId(
                (int) ($row['employee_id'] ?? 0),
                (string) ($row['date'] ?? '')
            ),
            'employee_id' => $row['employee_id'] ?? null,
            'employee_name' => $row['employee_name'] ?? null,
            'date' => $row['date'] ?? null,
            'day_name' => $row['day_name'] ?? null,
            'schedule_in' => $row['schedule_in'] ?? null,
            'schedule_out' => $row['schedule_out'] ?? null,
            'time_in' => $row['time_in'] ?? null,
            'time_out' => $row['time_out'] ?? null,
            'formatted_time_in' => $row['formatted_time_in'] ?? null,
            'formatted_time_out' => $row['formatted_time_out'] ?? null,
            'time_out_next_day' => $row['time_out_next_day'] ?? false,
            'total_hours' => $row['total_hours'] ?? $row['total_rendered_hours'] ?? null,
            'status' => $row['status'] ?? null,
            'presence_label' => $row['presence_label'] ?? null,
            'presence_issue' => $row['presence_issue'] ?? null,
            'overtime_summary' => [
                'approved_overtime_hours' => $row['approved_overtime_hours'] ?? null,
                'actual_rendered_overtime_hours' => $row['actual_rendered_overtime_hours'] ?? null,
                'payable_overtime_hours' => $row['payable_overtime_hours'] ?? null,
                'unapproved_overtime_hours' => $row['unapproved_overtime_hours'] ?? null,
                'overtime_status' => $row['overtime_status'] ?? null,
                'overtime_reduction_reason' => $row['overtime_reduction_reason'] ?? null,
            ],
            'correction_status' => [
                'has_correction' => $row['has_correction'] ?? false,
                'correction_id' => $row['correction_id'] ?? null,
                'correction_approved' => $row['correction_approved'] ?? false,
                'correction_remarks' => $row['correction_remarks'] ?? null,
            ],
            'payroll_impact' => [
                'payroll_impact_hours' => $row['payroll_impact_hours'] ?? null,
                'payroll_impact_minutes' => $row['payroll_impact_minutes'] ?? null,
            ],
            'nd_summary' => [
                'night_hours' => $row['night_hours'] ?? null,
                'night_differential_pay' => $row['night_differential_pay'] ?? null,
                'overtime_pay' => $row['overtime_pay'] ?? null,
                'total_premium_pay' => $row['total_premium_pay'] ?? null,
                'premium_type' => $row['premium_type'] ?? null,
                'premium_description' => $row['premium_description'] ?? null,
            ],
            'late_minutes' => $row['late_minutes'] ?? null,
            'undertime_minutes' => $row['undertime_minutes'] ?? null,
            'overtime_minutes' => $row['overtime_minutes'] ?? null,
            'scheduled_regular_hours' => $row['scheduled_regular_hours'] ?? null,
            'holiday_name' => $row['holiday_name'] ?? null,
            'holiday_type' => $row['holiday_type'] ?? null,
            'is_rest_day' => $row['is_rest_day'] ?? false,
        ];
    }
}
