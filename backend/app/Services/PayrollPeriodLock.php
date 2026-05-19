<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use App\Models\Payslip;
use Carbon\Carbon;

/**
 * Stateless payroll-period lock check (static) so {@see PayslipService} and workers
 * never depend on an injected guard for this assertion.
 */
final class PayrollPeriodLock
{
    /**
     * @throws \RuntimeException When the window is locked (user-facing message).
     */
    public static function assertMutableForUserWindow(int $userId, Carbon $from, Carbon $to): void
    {
        static::assertMutableForUserIds([$userId], $from, $to);
    }

    /**
     * Bulk payroll-period lock check for draft generation (two queries instead of per employee).
     *
     * @param  list<int>  $userIds
     * @throws \RuntimeException When the window is locked (user-facing message).
     */
    public static function assertMutableForUserIds(array $userIds, Carbon $from, Carbon $to): void
    {
        $userIds = array_values(array_unique(array_map(static fn ($id) => (int) $id, $userIds)));
        if ($userIds === []) {
            return;
        }

        if (PayrollPeriodOrphanLockService::isAutoReconcileEnabled()) {
            foreach ($userIds as $userId) {
                PayrollPeriodOrphanLockService::reconcileForUserWindow($userId, $from, $to);
            }
        }

        $fs = $from->toDateString();
        $te = $to->toDateString();

        $lockedUserId = Payslip::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $te)
            ->whereDate('pay_period_end', '>=', $fs)
            ->value('user_id');

        if ($lockedUserId !== null) {
            throw new \RuntimeException('This payroll period has already been finalized and is locked.');
        }

        $lockedPeriodUserId = PayrollPeriod::query()
            ->whereIn('user_id', $userIds)
            ->where('status', PayrollPeriod::STATUS_LOCKED)
            ->whereDate('from_date', '<=', $te)
            ->whereDate('to_date', '>=', $fs)
            ->value('user_id');

        if ($lockedPeriodUserId !== null) {
            throw new \RuntimeException('This payroll period has already been finalized and is locked.');
        }
    }

    /**
     * Block edits to calendar master data (e.g. custom holidays) when any finalized payslip’s pay window covers this date.
     */
    public static function assertCalendarDateMutableForPayroll(
        Carbon $date,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null
    ): void
    {
        PayrollPeriodOrphanLockService::reconcileForCalendarDate($date);

        $d = $date->toDateString();
        $query = Payslip::query()
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $d)
            ->whereDate('pay_period_end', '>=', $d);

        if ($employeeId !== null) {
            $query->where('user_id', $employeeId);
        }
        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        $hasFinalized = $query->exists();

        if ($hasFinalized) {
            throw new \RuntimeException('This payroll period has already been finalized and is locked.');
        }
    }
}
