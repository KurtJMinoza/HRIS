<?php

namespace App\Services;

use App\Models\Payslip;
use App\Models\PayrollDailyRecord;
use App\Models\User;
use Carbon\Carbon;

/**
 * Writes {@see PayrollDailyRecord} rows from attendance + schedule + payroll rules.
 * Shared by {@see \App\Jobs\ProcessDailyPayrollJob} and schedule-change recalculation.
 */
class PayrollDailyRecordSyncService
{
    public function __construct(
        private readonly PayrollComputationService $payroll,
        private readonly ScheduleRateService $scheduleRates,
    ) {}

    /**
     * True if this calendar day falls inside a payslip window that is already locked/published.
     */
    public function dateLockedForUser(int $userId, string $dateKey): bool
    {
        return Payslip::query()
            ->where('user_id', $userId)
            ->whereIn('status', Payslip::lockingStatuses())
            ->whereDate('pay_period_start', '<=', $dateKey)
            ->whereDate('pay_period_end', '>=', $dateKey)
            ->exists();
    }

    /**
     * Recompute and upsert one user's payroll daily record for a single date (when not locked).
     */
    public function syncDayForUser(User $user, string $dateKey): void
    {
        if ($this->dateLockedForUser((int) $user->id, $dateKey)) {
            return;
        }

        $tz = $this->payroll->getTimezone();
        [$timeIn, $timeOut] = $this->payroll->getTimesForDate($user, $dateKey, $tz);

        $effectiveSchedule = $this->payroll->resolveEffectiveSchedule($user);
        if (! $effectiveSchedule) {
            return;
        }

        $dailyRate = (float) ($user->daily_rate ?? 0);
        if ($dailyRate <= 0) {
            $dailyRate = $this->scheduleRates->resolveDailyRate($user);
        }
        if ($dailyRate <= 0) {
            return;
        }

        $dayResult = $this->payroll->computeDayPayroll(
            $user,
            $dateKey,
            $timeIn,
            $timeOut,
            $effectiveSchedule,
            $dailyRate,
            $tz
        );

        // Skip non-worked days unless paid leave (no punch) produced compensable pay.
        if ($dayResult['total_pay'] <= 0 && $dayResult['worked_minutes'] <= 0) {
            return;
        }

        $holiday = $dayResult['holiday'] ?? null;

        PayrollDailyRecord::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => $dateKey,
            ],
            [
                'regular_hours' => round(($dayResult['regular_day_minutes'] + $dayResult['regular_night_minutes']) / 60, 2),
                'ot_hours' => round(($dayResult['ot_day_minutes'] + $dayResult['ot_night_minutes']) / 60, 2),
                'nd_hours' => round(($dayResult['regular_night_minutes'] + $dayResult['ot_night_minutes']) / 60, 2),
                'nd_ot_hours' => round($dayResult['ot_night_minutes'] / 60, 2),
                'rule_code' => $dayResult['conditions']['rule_code'] ?? null,
                'first8_pay' => $dayResult['regular_pay'] ?? 0,
                'ot_pay' => $dayResult['ot_pay'] ?? 0,
                'nd_pay' => $dayResult['nd_pay'] ?? 0,
                'holiday_premium_pay' => $dayResult['holiday_premium_pay'] ?? 0,
                'total_pay' => $dayResult['total_pay'] ?? 0,
                'is_ot_approved' => ($dayResult['approved_ot_hours'] ?? 0) > 0,
                'approved_ot_hours' => $dayResult['approved_ot_hours'] ?? 0,
                'unapproved_ot_hours' => $dayResult['unapproved_ot_hours'] ?? 0,
                'holiday_type' => $holiday['type'] ?? null,
                'holiday_name' => $holiday['name'] ?? null,
                'is_rest_day' => $dayResult['is_rest_day'] ?? false,
                'worked_minutes' => $dayResult['worked_minutes'] ?? 0,
                'late_deduction_minutes' => $dayResult['late_deduction_minutes'] ?? 0,
                'undertime_deduction_minutes' => $dayResult['undertime_deduction_minutes'] ?? 0,
                'conditions' => $dayResult['conditions'] ?? null,
                'breakdown' => $dayResult['breakdown'] ?? null,
                'policy_id' => $dayResult['policy_id'] ?? null,
                'policy_snapshot' => $dayResult['policy_snapshot'] ?? null,
            ]
        );
    }

    /**
     * Recalculate daily records for specific users across an inclusive date range (skips finalized payslip windows).
     *
     * @param  list<int>  $userIds
     */
    public function recalculateForUsersInRange(array $userIds, Carbon $from, Carbon $to): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->payrollEmployees()
            ->active()
            ->with('workingSchedule')
            ->get();

        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $dateKey = $cursor->toDateString();
            foreach ($users as $user) {
                $this->syncDayForUser($user, $dateKey);
            }
            $cursor->addDay();
        }
    }
}
