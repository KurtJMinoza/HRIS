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

    public function assertCalendarDateMutableForPayroll(Carbon $date): void
    {
        PayrollPeriodLock::assertCalendarDateMutableForPayroll($date);
    }
}
