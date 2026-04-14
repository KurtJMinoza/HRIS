<?php

namespace App\Jobs;

use App\Models\PayrollDailyRecord;
use App\Models\User;
use App\Services\PayrollComputationService;
use App\Services\ScheduleRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * GAP 5: Daily Payroll Pipeline.
 *
 * Flow:
 * FOR each employee with attendance on target_date:
 *   → TimeSegmentationService (segment hours)
 *   → PayrollRulesEngine (resolve rule)
 *   → PayrollComputationService (compute pay)
 *   → Store in payroll_daily_records
 *
 * Schedule: Run daily at 11:59 PM OR after shift cutoff.
 */
class ProcessDailyPayrollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $targetDate
    ) {}

    public function handle(PayrollComputationService $payroll, ScheduleRateService $scheduleRates): void
    {
        $dateKey = $this->targetDate;

        $employees = User::query()
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('is_active', true)
            ->get();

        foreach ($employees as $user) {
            $tz = $payroll->getTimezone();
            [$timeIn, $timeOut] = $payroll->getTimesForDate($user, $dateKey, $tz);

            $effectiveSchedule = $payroll->resolveEffectiveSchedule($user);
            if (! $effectiveSchedule) {
                continue;
            }

            $dailyRate = (float) ($user->daily_rate ?? 0);
            if ($dailyRate <= 0) {
                $dailyRate = $scheduleRates->resolveDailyRate($user);
            }
            if ($dailyRate <= 0) {
                continue;
            }

            $dayResult = $payroll->computeDayPayroll(
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
                continue;
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
    }
}
