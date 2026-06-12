<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use App\Models\Payslip;
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

    /**
     * @param  array<int, array{key: int|string, user_id: int, from: Carbon, to: Carbon}>  $windows
     * @return array<int|string, string>
     */
    public function lockedWindowErrors(array $windows, bool $reconcileOrphans = true): array
    {
        $windows = array_values(array_filter($windows, static fn (array $window): bool => (int) ($window['user_id'] ?? 0) > 0));
        if ($windows === []) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(static fn (array $window): int => (int) $window['user_id'], $windows)));
        $from = collect($windows)->min(fn (array $window): int => $window['from']->timestamp);
        $to = collect($windows)->max(fn (array $window): int => $window['to']->timestamp);
        $rangeFrom = Carbon::createFromTimestamp((int) $from)->startOfDay();
        $rangeTo = Carbon::createFromTimestamp((int) $to)->endOfDay();

        if ($reconcileOrphans && PayrollPeriodOrphanLockService::isAutoReconcileEnabled()) {
            foreach ($userIds as $userId) {
                PayrollPeriodOrphanLockService::reconcileForUserWindow($userId, $rangeFrom, $rangeTo);
            }
        }

        $payslips = Payslip::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $rangeTo->toDateString())
            ->whereDate('pay_period_end', '>=', $rangeFrom->toDateString())
            ->get(['user_id', 'pay_period_start', 'pay_period_end'])
            ->groupBy('user_id');
        $periods = PayrollPeriod::query()
            ->whereIn('user_id', $userIds)
            ->where('status', PayrollPeriod::STATUS_LOCKED)
            ->whereDate('from_date', '<=', $rangeTo->toDateString())
            ->whereDate('to_date', '>=', $rangeFrom->toDateString())
            ->get(['user_id', 'from_date', 'to_date'])
            ->groupBy('user_id');

        $errors = [];
        foreach ($windows as $window) {
            $userId = (int) $window['user_id'];
            $windowFrom = $window['from']->toDateString();
            $windowTo = $window['to']->toDateString();
            $hasPayslipLock = ($payslips->get($userId) ?? collect())->contains(
                fn (Payslip $payslip): bool => $payslip->pay_period_start->toDateString() <= $windowTo
                    && $payslip->pay_period_end->toDateString() >= $windowFrom,
            );
            $hasPeriodLock = ($periods->get($userId) ?? collect())->contains(
                fn (PayrollPeriod $period): bool => $period->from_date->toDateString() <= $windowTo
                    && $period->to_date->toDateString() >= $windowFrom,
            );
            if ($hasPayslipLock || $hasPeriodLock) {
                $errors[$window['key']] = 'This payroll period has already been finalized and is locked.';
            }
        }

        return $errors;
    }

    public function assertCalendarDateMutableForPayroll(
        Carbon $date,
        ?int $companyId = null,
        ?int $branchId = null,
        ?int $departmentId = null,
        ?int $employeeId = null
    ): void {
        PayrollPeriodLock::assertCalendarDateMutableForPayroll($date, $companyId, $branchId, $departmentId, $employeeId);
    }
}
