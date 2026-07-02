<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\AttendanceSessionService;
use App\Services\HolidayPayPolicyService;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;
use App\Services\PayrollRulesEngineService;
use App\Services\PolicyResolverService;

class FakeHolidayPayPolicyService extends HolidayPayPolicyService
{
    public function __construct(
        AttendanceSessionService $attendanceSession,
        HolidayService $holidayService,
        LeaveCreditService $leaveCreditService,
        PolicyResolverService $policyResolver,
        PayrollRulesEngineService $rulesEngine,
        private readonly array $workedDates,
        private readonly array $paidLeaveDates,
    ) {
        parent::__construct($attendanceSession, $holidayService, $leaveCreditService, $policyResolver, $rulesEngine);
    }

    protected function workedOn(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->workedDates, true);
    }

    protected function presentOn(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->workedDates, true);
    }

    protected function hasApprovedPaidLeave(User $employee, string $dateKey): bool
    {
        return in_array($dateKey, $this->paidLeaveDates, true);
    }
}
