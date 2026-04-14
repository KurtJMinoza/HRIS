<?php

namespace App\Services;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;

/**
 * Premium Pay Calculator – Phase 1 MVP.
 *
 * Computes premium-aware values for a clock-out session and stores them on the AttendanceLog.
 * Uses PolicyResolverService (policy-aware); falls back to payroll_rules / config.
 *
 * Logic (DOLE-aligned):
 * - Base multiplier: Regular holiday → 2.60 (rest) or 2.00; Special → 1.50 (rest) or 1.30; Rest day → 1.30; else 1.00
 * - OT multiplier = base × 1.30
 * - ND pay: ND on regular hours × (HWR × first_8 × 10%) + ND on OT hours × (HWR × ot_mult × 10%)
 */
class PremiumPayCalculatorService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly TimeSegmentationService $timeSegmentation,
        private readonly AttendanceStatusService $attendanceStatus,
        private readonly PolicyResolverService $policyResolver,
        private readonly ScheduleRateService $scheduleRateService,
    ) {}

    public function getTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Compute and store premium values on a clock-out log.
     * Call this after creating a clock-out AttendanceLog.
     *
     * @return array{success: bool, overtime_hours: float, night_hours: float, premium_type: string, calculated_pay_factor: array, estimated_premium_pay?: float}
     */
    public function computeAndStore(AttendanceLog $clockOutLog): array
    {
        if ($clockOutLog->type !== AttendanceLog::TYPE_CLOCK_OUT) {
            return ['success' => false, 'overtime_hours' => 0, 'night_hours' => 0, 'premium_type' => '', 'calculated_pay_factor' => []];
        }

        $user = $clockOutLog->user;
        if (! $user) {
            return ['success' => false, 'overtime_hours' => 0, 'night_hours' => 0, 'premium_type' => '', 'calculated_pay_factor' => []];
        }

        $tz = $this->getTimezone();
        $clockOutAt = $clockOutLog->created_at->copy()->timezone($tz);

        // Pair with first clock-in of the same day (session date = clock-in date for day-based logic)
        $dayStart = $clockOutAt->copy()->startOfDay();
        $dayEnd = $clockOutAt->copy()->endOfDay();
        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $clockIn = AttendanceLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$dayStartUtc, $dayEndUtc])
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->orderBy('created_at')
            ->first();

        // If clock-out is next day (night shift), check previous day for clock-in
        if (! $clockIn) {
            $prevDayStart = $dayStart->copy()->subDay();
            $prevDayEnd = $dayEnd->copy()->subDay();
            $clockIn = AttendanceLog::query()
                ->where('user_id', $user->id)
                ->whereBetween('created_at', [$prevDayStart->setTimezone('UTC'), $prevDayEnd->setTimezone('UTC')])
                ->where('type', AttendanceLog::TYPE_CLOCK_IN)
                ->orderBy('created_at')
                ->first();
        }

        if (! $clockIn) {
            return ['success' => false, 'overtime_hours' => 0, 'night_hours' => 0, 'premium_type' => '', 'calculated_pay_factor' => []];
        }

        $timeIn = $clockIn->created_at->copy()->timezone($tz);
        $timeOut = $clockOutAt;

        // Apply approved correction if present
        $dateKey = $timeIn->toDateString();
        $correction = AttendanceCorrection::query()
            ->where('user_id', $user->id)
            ->whereDate('date', $dateKey)
            ->where('approved', true)
            ->first();

        if ($correction && $correction->time_in && $correction->time_out) {
            $timeIn = $correction->time_in->copy()->timezone($tz);
            $timeOut = $correction->time_out->copy()->timezone($tz);
        }

        $effectiveSchedule = $this->rulesEngine->resolveEffectiveSchedule($user);
        $dayKey = self::DAY_KEYS[(int) Carbon::parse($dateKey, $tz)->format('w')];
        $daySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey]) ? $effectiveSchedule[$dayKey] : null;

        $workedMinutes = $daySchedule
            ? $this->attendanceStatus->getNetWorkedMinutes($timeIn, $timeOut, $daySchedule, $dateKey, $tz)
            : (int) $timeIn->diffInMinutes($timeOut);

        $companyId = $user->getEffectiveCompanyId();
        $branchId = $user->branch_id;
        $policy = $this->policyResolver->getActivePolicy($companyId, $branchId, $dateKey);
        $ndConfig = $this->policyResolver->getNdConfig($policy);
        $ndStartHour = $ndConfig['start_hour'] ?? null;
        $ndEndHour = $ndConfig['end_hour'] ?? null;

        $segmentation = $this->timeSegmentation->segment($timeIn, $timeOut, $tz, $daySchedule, $dateKey, $ndStartHour, $ndEndHour);

        $regularHours = round($segmentation['regular_hours'], 2);
        $otHours = round($segmentation['overtime_hours'], 2);
        $ndHours = round($segmentation['night_hours'], 2);

        $holidayType = $this->rulesEngine->getHolidayType($dateKey, $companyId);
        $isRestDay = $effectiveSchedule ? $this->rulesEngine->isRestDay($effectiveSchedule, Carbon::parse($dateKey, $tz)) : false;

        $ruleCode = $this->rulesEngine->resolveRuleCode($isRestDay, $holidayType);
        $multipliers = $this->policyResolver->getMultipliersForRule($policy, $ruleCode);

        $base = $multipliers['first_8'];
        $otMult = $multipliers['ot'];
        $ndPct = (float) ($multipliers['nd_addon'] ?? config('payroll.nd_premium', 0.10));
        $ndBase = $multipliers['nd_base'] ?? $base;

        $payFactor = [
            'first_8_multiplier' => $base,
            'ot_multiplier' => $otMult,
            'nd_applied_multiplier' => $ndPct,
            'rule_code' => $ruleCode,
        ];

        $hourlyRate = $this->resolveHourlyRate($user);
        $estimatedPay = null;
        if ($hourlyRate > 0) {
            $regularPay = $regularHours * ($hourlyRate * $base);
            $otPay = $otHours * ($hourlyRate * $otMult);
            $ndRegMinutes = $segmentation['nd_regular_minutes'] ?? 0;
            $ndOtMinutes = $segmentation['nd_overtime_minutes'] ?? 0;
            $ndPay = ($ndRegMinutes / 60.0) * $hourlyRate * $ndBase * $ndPct
                + ($ndOtMinutes / 60.0) * $hourlyRate * $otMult * $ndPct;
            $estimatedPay = round($regularPay + $otPay + $ndPay, 2);
        }

        $clockOutLog->update([
            'overtime_hours' => $otHours,
            'night_hours' => $ndHours,
            'premium_type' => $this->premiumTypeLabel($ruleCode),
            'calculated_pay_factor' => $payFactor,
        ]);

        $result = [
            'success' => true,
            'overtime_hours' => $otHours,
            'night_hours' => $ndHours,
            'premium_type' => $this->premiumTypeLabel($ruleCode),
            'calculated_pay_factor' => $payFactor,
        ];
        if ($estimatedPay !== null) {
            $result['estimated_premium_pay'] = $estimatedPay;
        }

        return $result;
    }

    private function premiumTypeLabel(string $ruleCode): string
    {
        $labels = [
            'ORD' => 'ordinary',
            'RD' => 'rest_day',
            'RH' => 'regular_holiday',
            'RHRD' => 'regular_holiday_rest_day',
            'SH' => 'special_holiday',
            'SHRD' => 'special_holiday_rest_day',
            'DH' => 'double_holiday',
            'DHRD' => 'double_holiday_rest_day',
        ];

        return $labels[$ruleCode] ?? 'ordinary';
    }

    private function resolveHourlyRate(User $user): float
    {
        return $this->scheduleRateService->resolveHourlyRate($user);
    }

    /**
     * Batch recompute premium values for clock-out logs in a date range.
     * Useful for backfill or when rules change.
     */
    public function recomputeForRange(Carbon $from, Carbon $to): int
    {
        $tz = $this->getTimezone();
        $fromUtc = $from->copy()->timezone($tz)->startOfDay()->setTimezone('UTC');
        $toUtc = $to->copy()->timezone($tz)->endOfDay()->setTimezone('UTC');

        $clockOuts = AttendanceLog::query()
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->with('user')
            ->get();

        $count = 0;
        foreach ($clockOuts as $log) {
            $result = $this->computeAndStore($log);
            if ($result['success']) {
                $count++;
            }
        }

        return $count;
    }
}
