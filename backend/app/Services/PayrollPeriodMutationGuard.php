<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Injectable wrapper around {@see PayrollPeriodLock} for constructor DI in services/controllers.
 */
final class PayrollPeriodMutationGuard
{
    public function __construct(
        private readonly PayrollFreezeService $payrollFreezeService,
    ) {}

    /**
     * @throws \RuntimeException When the window is locked (user-facing message).
     */
    public function assertMutableForUserWindow(int $userId, Carbon $from, Carbon $to): void
    {
        $this->payrollFreezeService->assertMutableForUserWindow($userId, $from, $to);
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

        if ($reconcileOrphans && PayrollPeriodOrphanLockService::isAutoReconcileEnabled()) {
            $userIds = array_values(array_unique(array_map(static fn (array $window): int => (int) $window['user_id'], $windows)));
            $from = collect($windows)->min(fn (array $window): int => $window['from']->timestamp);
            $to = collect($windows)->max(fn (array $window): int => $window['to']->timestamp);
            $rangeFrom = Carbon::createFromTimestamp((int) $from)->startOfDay();
            $rangeTo = Carbon::createFromTimestamp((int) $to)->endOfDay();
            foreach ($userIds as $userId) {
                PayrollPeriodOrphanLockService::reconcileForUserWindow($userId, $rangeFrom, $rangeTo);
            }
        }

        $errors = [];
        foreach ($windows as $window) {
            $userId = (int) $window['user_id'];
            $frozen = $this->payrollFreezeService->isWindowFrozenForEmployee($userId, $window['from'], $window['to']);
            if ($frozen['frozen']) {
                $errors[$window['key']] = PayrollFreezeService::LOCK_MESSAGE;
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
