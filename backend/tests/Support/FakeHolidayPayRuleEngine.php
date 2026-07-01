<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\HolidayPayAttendanceStatusRegistry;
use App\Services\HolidayPayRuleEngine;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;

class FakeHolidayPayRuleEngine extends HolidayPayRuleEngine
{
    public function __construct(
        AttendanceSessionService $attendanceSession,
        HolidayService $holidayService,
        LeaveCreditService $leaveCreditService,
        HolidayPayAttendanceStatusRegistry $statusRegistry,
        private readonly array $workedDates,
        private readonly array $paidLeaveDates,
    ) {
        parent::__construct($attendanceSession, $holidayService, $leaveCreditService, $statusRegistry);
    }

    protected function workedOn(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->workedDates, true);
    }

    protected function hasApprovedPaidLeave(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->paidLeaveDates, true);
    }
}
