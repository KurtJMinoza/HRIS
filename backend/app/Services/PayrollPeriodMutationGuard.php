<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Injectable wrapper around {@see PayrollPeriodLock} for constructor DI in services/controllers.
 */
final class PayrollPeriodMutationGuard
{
    /**
     * @throws \RuntimeException When the window is locked (user-facing message).
     */
    public function assertMutableForUserWindow(int $userId, Carbon $from, Carbon $to): void
    {
        PayrollPeriodLock::assertMutableForUserWindow($userId, $from, $to);
    }

    public function assertCalendarDateMutableForPayroll(
        Carbon $date,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null
    ): void
    {
        PayrollPeriodLock::assertCalendarDateMutableForPayroll($date, $companyId, $branchId, $departmentId, $employeeId);
    }
}
