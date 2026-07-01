<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Services\AttendanceStatusResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of attendance status tags usable in Holiday Pay rule builder.
 * ponytail: extend when Attendance Status admin config exists; until then resolver + leave types.
 */
class HolidayPayAttendanceStatusRegistry
{
    /** @return list<array{value: string, label: string, group: string}> */
    public function qualifyingOptions(): array
    {
        return [
            ['value' => 'present', 'label' => 'Present', 'group' => 'attendance'],
            ['value' => 'late', 'label' => 'Late', 'group' => 'attendance'],
            ['value' => 'halfday', 'label' => 'Half Day', 'group' => 'attendance'],
            ['value' => 'undertime', 'label' => 'Undertime (with attendance)', 'group' => 'attendance'],
            ['value' => 'present_with_ot', 'label' => 'Present with OT', 'group' => 'attendance'],
            ['value' => 'approved_paid_leave', 'label' => 'Approved Paid Leave', 'group' => 'leave'],
            ['value' => 'official_business', 'label' => 'Official Business', 'group' => 'filing'],
            ['value' => 'field_work', 'label' => 'Field Work', 'group' => 'filing'],
            ['value' => 'work_from_home', 'label' => 'Work From Home', 'group' => 'filing'],
            ['value' => 'training', 'label' => 'Training / Seminar', 'group' => 'filing'],
            ['value' => 'paid_suspension', 'label' => 'Paid Suspension', 'group' => 'leave'],
            ['value' => 'approved_offset', 'label' => 'Approved Offset', 'group' => 'filing'],
        ];
    }

    /** @return list<array{value: string, label: string, group: string}> */
    public function disqualifyingOptions(): array
    {
        return [
            ['value' => 'absent', 'label' => 'Absent Without Pay', 'group' => 'attendance'],
            ['value' => 'awol', 'label' => 'AWOL', 'group' => 'attendance'],
            ['value' => 'leave_without_pay', 'label' => 'Leave Without Pay', 'group' => 'leave'],
            ['value' => 'disapproved_leave', 'label' => 'Disapproved Leave', 'group' => 'leave'],
            ['value' => 'incomplete', 'label' => 'Incomplete Attendance', 'group' => 'attendance'],
            ['value' => 'unpaid_absence', 'label' => 'Unpaid Absence', 'group' => 'attendance'],
        ];
    }

    /** @return list<string> */
    public function defaultDoleQualifying(): array
    {
        return ['present', 'late', 'approved_paid_leave'];
    }

    /** @return list<string> */
    public function defaultDoleDisqualifying(): array
    {
        return ['absent', 'leave_without_pay', 'incomplete', 'unpaid_absence'];
    }

    /** @return list<array{value: string, label: string}> */
    public function minimumConditionOptions(): array
    {
        return [
            ['value' => 'previous_working_day_only', 'label' => 'Previous Working Day Only (DOLE Default)'],
            ['value' => 'previous_and_next', 'label' => 'Previous AND Next Working Day'],
            ['value' => 'previous_or_next', 'label' => 'Previous OR Next Working Day'],
            ['value' => 'next_working_day_only', 'label' => 'Next Working Day Only'],
            ['value' => 'none', 'label' => 'No Attendance Requirement'],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public function successiveQualificationOptions(): array
    {
        return [
            ['value' => 'previous_working_day', 'label' => 'Previous Working Day'],
            ['value' => 'previous_and_first_holiday_worked', 'label' => 'Previous AND First Holiday Worked'],
        ];
    }
}
