<?php

namespace App\Services;

/**
 * Rollup counts for attendance summary cards — aligned with frontend
 * {@see resolveEmployeeStatusLabel} / {@see resolveAdminStatusLabel} in attendanceRecordUtils.js.
 */
class AttendanceRollupService
{
    /**
     * Scheduled rest day from assigned shift template (empty `in` for weekday).
     *
     * @param  array<string, mixed>|null  $effectiveSchedule
     * @param  array<string, mixed>|null  $daySchedule
     */
    public function isScheduledRestDay(?array $effectiveSchedule, ?array $daySchedule): bool
    {
        if (! is_array($effectiveSchedule) || $effectiveSchedule === []) {
            return false;
        }

        return ! (is_array($daySchedule) && trim((string) ($daySchedule['in'] ?? '')) !== '');
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array{present_count:int,late_count:int,absent_count:int,leave_count:int,halfday_count:int,rest_day_count:int,holiday_count:int}
     */
    public function summarizeEmployeeDays(array $days): array
    {
        $present = 0;
        $late = 0;
        $absent = 0;
        $leave = 0;
        $halfday = 0;
        $restDay = 0;
        $holiday = 0;

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            if (($day['status'] ?? '') === 'upcoming') {
                continue;
            }

            $status = (string) ($day['status'] ?? '');
            if ($status === 'halfday') {
                $halfday++;
            }

            $label = $this->employeeDisplayLabel($day);
            if ($this->labelCountsAsHoliday($label, $day)) {
                $holiday++;
            } elseif ($this->labelCountsAsRestDay($label, $day)) {
                $restDay++;
            } elseif ($this->labelCountsAsPresent($label, $day)) {
                $present++;
            } elseif ($this->labelCountsAsAbsent($label, $day)) {
                $absent++;
            } elseif ($this->labelCountsAsLate($label, $day)) {
                $late++;
            } elseif ($this->labelCountsAsLeave($label, $day)) {
                $leave++;
            }
        }

        return [
            'present_count' => $present,
            'late_count' => $late,
            'absent_count' => $absent,
            'leave_count' => $leave,
            'halfday_count' => $halfday,
            'rest_day_count' => $restDay,
            'holiday_count' => $holiday,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{present_count:int,late_count:int,absent_count:int,leave_count:int,halfday_count:int,rest_day_count:int,holiday_count:int}
     */
    public function summarizeAdminRows(array $rows): array
    {
        $present = 0;
        $late = 0;
        $absent = 0;
        $leave = 0;
        $halfday = 0;
        $restDay = 0;
        $holiday = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            if ($status === 'halfday') {
                $halfday++;
            }

            $label = $this->adminDisplayLabel($row);
            if ($this->labelCountsAsHoliday($label, $row)) {
                $holiday++;
            } elseif ($this->labelCountsAsRestDay($label, $row)) {
                $restDay++;
            } elseif ($this->labelCountsAsPresent($label, $row)) {
                $present++;
            } elseif ($this->labelCountsAsAbsent($label, $row)) {
                $absent++;
            } elseif ($this->labelCountsAsLate($label, $row)) {
                $late++;
            } elseif ($this->labelCountsAsLeave($label, $row)) {
                $leave++;
            }
        }

        return [
            'present_count' => $present,
            'late_count' => $late,
            'absent_count' => $absent,
            'leave_count' => $leave,
            'halfday_count' => $halfday,
            'rest_day_count' => $restDay,
            'holiday_count' => $holiday,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function employeeDisplayLabel(array $row): string
    {
        $status = (string) ($row['status'] ?? '');

        if ($status === 'leave') {
            $pay = (string) ($row['leave_pay_status'] ?? '');
            if ($pay === 'paid') {
                return 'Paid leave';
            }
            if ($pay === 'unpaid') {
                return 'Unpaid leave';
            }

            return 'Leave';
        }

        if ($status === 'holiday') {
            $name = trim((string) ($row['holiday_name'] ?? ''));

            return $name !== '' ? $name : 'Holiday';
        }

        if ($status === 'rest' || ! empty($row['is_rest_day'])) {
            return 'Rest Day';
        }

        $presenceLabel = trim((string) ($row['presence_label'] ?? ''));
        if ($presenceLabel !== '') {
            return $presenceLabel;
        }

        if ($status === '—' || $status === '') {
            return '—';
        }

        if ($status === 'upcoming') {
            return 'Upcoming';
        }

        if ($status === 'late') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Late';
        }

        if ($status === 'halfday') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Half Day';
        }

        if ($status === 'absent') {
            return 'Absent';
        }

        if ($status === 'incomplete') {
            return 'Present (Incomplete)';
        }

        if ($status === 'present') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Present';
        }

        if ($status === 'undertime') {
            return 'Undertime';
        }

        if ($status === 'clocked_in') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Clocked in';
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function adminDisplayLabel(array $row): string
    {
        $status = (string) ($row['status'] ?? '');

        if ($status === 'late') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Late';
        }

        if ($status === 'undertime') {
            return ! empty($row['is_approved_undertime']) ? 'Undertime (Approved)' : 'Undertime (Unfiled)';
        }

        if ($status === 'absent') {
            return 'Absent';
        }

        if ($status === 'present') {
            return ! empty($row['has_approved_overtime']) ? 'Present + OT' : 'Present';
        }

        if ($status === 'halfday') {
            $lateLabel = trim((string) ($row['late_label'] ?? ''));

            return $lateLabel !== '' ? $lateLabel : 'Half Day';
        }

        if ($status === 'incomplete') {
            return 'Present (Incomplete)';
        }

        if ($status === 'leave') {
            return 'On Leave';
        }

        if ($status === 'holiday') {
            $name = trim((string) ($row['holiday_name'] ?? ''));

            return $name !== '' ? $name : 'Holiday';
        }

        if ($status === 'rest' || ! empty($row['is_rest_day'])) {
            return 'Rest Day';
        }

        $presenceLabel = trim((string) ($row['presence_label'] ?? ''));
        if ($presenceLabel !== '') {
            return $presenceLabel;
        }

        return $status !== '' ? $status : '—';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsPresent(string $label, array $row = []): bool
    {
        if ($this->labelCountsAsRestDay('', $row) || $this->labelCountsAsHoliday('', $row)) {
            return false;
        }

        $normalized = strtolower(trim($label));
        if ($normalized === '' || $normalized === '—' || $normalized === 'upcoming') {
            return false;
        }

        if ($this->isAbsentLabel($normalized)) {
            return false;
        }

        if ($this->isLeaveLabel($normalized)) {
            return false;
        }

        if (str_contains($normalized, 'undertime') || str_contains($normalized, 'half day') || $normalized === 'halfday') {
            return false;
        }

        if (str_contains($normalized, 'clocked in')) {
            return false;
        }

        return str_starts_with($normalized, 'present');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsAbsent(string $label, array $row = []): bool
    {
        if ($this->labelCountsAsRestDay($label, $row) || $this->labelCountsAsHoliday($label, $row)) {
            return false;
        }

        return strtolower(trim($label)) === 'absent';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsRestDay(string $label, array $row = []): bool
    {
        if (($row['status'] ?? '') === 'rest' || ! empty($row['is_rest_day'])) {
            return true;
        }

        $normalized = strtolower(trim($label));

        return $normalized === 'rest day' || $normalized === 'restday';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsHoliday(string $label, array $row = []): bool
    {
        if (($row['status'] ?? '') === 'holiday') {
            return true;
        }

        $normalized = strtolower(trim($label));

        return $normalized === 'holiday' || str_contains($normalized, 'holiday');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsLate(string $label, array $row = []): bool
    {
        if (($row['status'] ?? '') === 'late') {
            return ! $this->labelCountsAsPresent($label, $row);
        }

        $normalized = strtolower(trim($label));

        return $normalized === 'late' || str_starts_with($normalized, 'late ');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function labelCountsAsLeave(string $label, array $row = []): bool
    {
        if (($row['status'] ?? '') === 'halfday') {
            return false;
        }

        if (($row['status'] ?? '') === 'leave') {
            return true;
        }

        return $this->isLeaveLabel(strtolower(trim($label)));
    }

    private function isAbsentLabel(string $normalized): bool
    {
        return $normalized === 'absent';
    }

    private function isLeaveLabel(string $normalized): bool
    {
        return $normalized === 'leave'
            || $normalized === 'on leave'
            || str_starts_with($normalized, 'paid leave')
            || str_starts_with($normalized, 'unpaid leave');
    }
}
