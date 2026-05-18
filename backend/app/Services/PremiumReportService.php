<?php

namespace App\Services;

use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Premium Report Service — same rules engine as payroll (policy §§6–9).
 *
 * Uses {@see PayrollRulesEngineService} (rule codes), {@see TimeSegmentationService} (regular/OT/ND),
 * {@see AttendanceSessionService} (sessions). Multipliers: `config('payroll.rules')` / `payroll_rules` table.
 * Read-only for reporting. See docs/PAYROLL_RULES_ENGINE.md.
 */
class PremiumReportService
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** Rule code → [work_condition, pay_rule, multiplier] for report columns. */
    private const RULE_LABELS = [
        'ORD' => ['Ordinary working day (first 8 hrs)', '100% of basic hourly rate', '1.00'],
        'RD' => ['Rest day (first 8 hrs)', '130% of daily/hourly rate', '1.30'],
        'RH' => ['Regular holiday worked (first 8 hrs)', '200%', '2.00'],
        'RHRD' => ['Regular holiday + rest day (first 8 hrs)', '260%', '2.60'],
        'SH' => ['Special holiday worked (first 8 hrs)', '130%', '1.30'],
        'SHRD' => ['Special holiday + rest day (first 8 hrs)', '150%', '1.50'],
        'DH' => ['Double holiday worked (first 8 hrs)', '300%', '3.00'],
        'DHRD' => ['Double holiday + rest day (first 8 hrs)', '300%', '3.00'],
    ];

    public function __construct(
        private readonly PayrollRulesEngineService $rulesEngine,
        private readonly TimeSegmentationService $timeSegmentation,
        private readonly AttendanceSessionService $attendanceSession,
        private readonly ScheduleRateService $scheduleRateService,
    ) {}

    public function getTimezone(): string
    {
        return config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
    }

    /**
     * Compute premium breakdown for a single employee over a date range.
     *
     * @return array{
     *   employee_id: int,
     *   employee_name: string,
     *   from_date: string,
     *   to_date: string,
     *   hourly_rate: float,
     *   daily_rate: float|null,
     *   has_rate: bool,
     *   days: array,
     *   summary: array
     * }
     */
    public function computeForEmployee(User $user, Carbon $from, Carbon $to): array
    {
        $tz = $this->getTimezone();
        $fromDate = $from->copy()->timezone($tz)->startOfDay();
        $toDate = $to->copy()->timezone($tz)->endOfDay();

        $hourlyRate = $this->scheduleRateService->resolveHourlyRate($user);
        $dailyRate = $this->scheduleRateService->resolveDailyRate($user);

        $effectiveSchedule = $this->rulesEngine->resolveEffectiveSchedule($user);
        $companyId = $user->getEffectiveCompanyId();

        // Preload approved OT for date range
        $approvedOtByDate = Overtime::query()
            ->where('user_id', $user->id)
            ->where('status', Overtime::STATUS_APPROVED)
            ->whereBetween('date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->get()
            ->keyBy(fn (Overtime $ot) => $ot->date->toDateString());

        $days = [];
        $cursor = $fromDate->copy();

        while ($cursor->lessThanOrEqualTo($toDate)) {
            $dateKey = $cursor->toDateString();
            $dayBreakdown = $this->computeDailyBreakdown(
                $user,
                $dateKey,
                $effectiveSchedule,
                $companyId,
                $hourlyRate,
                $approvedOtByDate->get($dateKey),
                $tz
            );
            if ($dayBreakdown !== null) {
                $days[] = $dayBreakdown;
            }
            $cursor->addDay();
        }

        $summary = $this->summarizeDays($days);

        return [
            'employee_id' => $user->id,
            'employee_name' => $user->name,
            'profile_image' => $user->profile_image_url,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'hourly_rate' => round($hourlyRate, 2),
            'daily_rate' => $dailyRate > 0 ? round($dailyRate, 2) : null,
            'has_rate' => $hourlyRate > 0,
            'days' => $days,
            'summary' => $summary,
        ];
    }

    /**
     * Compute premium breakdown for multiple employees (admin report).
     *
     * @param  Collection<int, User>  $employees
     * @return array<int, array>
     */
    public function computeForEmployees(Collection $employees, Carbon $from, Carbon $to): array
    {
        $ordered = $employees->sortBy(fn (User $u) => $u->employeeListingSortKey())->values();

        $results = [];
        foreach ($ordered as $user) {
            $results[] = $this->computeForEmployee($user, $from, $to);
        }

        return $results;
    }

    /**
     * Reusable premium context for one employee (avoid re-resolving rates/schedules per day).
     *
     * @return array{effective_schedule: ?array, company_id: ?int, hourly_rate: float}
     */
    public function premiumContextForEmployee(User $user): array
    {
        return [
            'effective_schedule' => $this->rulesEngine->resolveEffectiveSchedule($user),
            'company_id' => $user->getEffectiveCompanyId(),
            'hourly_rate' => (float) $this->scheduleRateService->resolveHourlyRate($user),
        ];
    }

    /**
     * Premium row for detailed reports using already-resolved clock times (matches session service order).
     * Avoids {@see AttendanceSessionService::getTimesForDate()} N+1 queries when the caller has times.
     *
     * @param  array{effective_schedule: ?array, company_id: ?int, hourly_rate: float}  $context
     * @return array|null null if no paired attendance times
     */
    public function computeDayPremiumFromResolvedTimes(
        array $context,
        string $dateKey,
        $timeIn,
        $timeOut,
        ?Overtime $approvedOt,
        string $tz
    ): ?array {
        if (! $timeIn || ! $timeOut) {
            return null;
        }

        $effectiveSchedule = $context['effective_schedule'] ?? null;
        $companyId = $context['company_id'] ?? null;
        $hourlyRate = (float) ($context['hourly_rate'] ?? 0.0);

        return $this->buildDailyBreakdownFromTimes(
            $dateKey,
            $timeIn instanceof Carbon ? $timeIn : Carbon::parse($timeIn),
            $timeOut instanceof Carbon ? $timeOut : Carbon::parse($timeOut),
            $effectiveSchedule,
            $companyId,
            $hourlyRate,
            $approvedOt,
            $tz
        );
    }

    /**
     * Compute daily premium breakdown for one date.
     *
     * @return array|null null if no attendance that day
     */
    private function computeDailyBreakdown(
        User $user,
        string $dateKey,
        ?array $effectiveSchedule,
        ?int $companyId,
        float $hourlyRate,
        ?Overtime $approvedOt,
        string $tz
    ): ?array {
        [$timeIn, $timeOut] = $this->attendanceSession->getTimesForDate($user, $dateKey, $tz);

        if (! $timeIn || ! $timeOut) {
            return null;
        }

        return $this->buildDailyBreakdownFromTimes(
            $dateKey,
            $timeIn,
            $timeOut,
            $effectiveSchedule,
            $companyId,
            $hourlyRate,
            $approvedOt,
            $tz
        );
    }

    /**
     * Core premium math given resolved in/out instants (attendance timezone).
     */
    private function buildDailyBreakdownFromTimes(
        string $dateKey,
        Carbon $timeIn,
        Carbon $timeOut,
        ?array $effectiveSchedule,
        ?int $companyId,
        float $hourlyRate,
        ?Overtime $approvedOt,
        string $tz
    ): ?array {
        $date = Carbon::parse($dateKey, $tz);
        $dayKey = self::DAY_KEYS[(int) $date->format('w')];
        $daySchedule = is_array($effectiveSchedule) && isset($effectiveSchedule[$dayKey])
            ? $effectiveSchedule[$dayKey]
            : null;

        $holidayType = $this->rulesEngine->getHolidayType($dateKey, $companyId);
        $isRestDay = $effectiveSchedule ? $this->rulesEngine->isRestDay($effectiveSchedule, $date) : false;
        $ruleCode = $this->rulesEngine->resolveRuleCode($isRestDay, $holidayType);
        $multipliers = $this->rulesEngine->getMultipliersForRule($ruleCode);
        $base = (float) ($multipliers['first_8'] ?? 1.0);
        $otMult = (float) ($multipliers['ot'] ?? 1.25);
        $ndBase = (float) ($multipliers['nd_base'] ?? $base);
        $ndPct = (float) config('payroll.nd_premium', 0.10);

        $segmentation = $this->timeSegmentation->segment($timeIn, $timeOut, $tz, $daySchedule, $dateKey);
        $regularHours = round($segmentation['regular_hours'], 2);
        $otHoursFromLogs = round($segmentation['overtime_hours'], 2);
        $ndHours = round($segmentation['night_hours'], 2);

        // Payroll/report: OT is always measured from actual time-in / time-out (segmentation).
        // Approved OT is eligibility/policy only — never substitute request "computed_hours" for real work.
        $otHours = $otHoursFromLogs;

        $ndPay = 0.0;
        $otPay = 0.0;
        $regularPay = 0.0;

        $regularHolidayPay = 0.0;
        $specialHolidayPay = 0.0;
        $restDayPremiumPay = 0.0;
        $combinedPremiumsPay = 0.0;

        if ($hourlyRate > 0) {
            $ndRegMinutes = $segmentation['nd_regular_minutes'] ?? 0;
            $ndOtMinutes = $segmentation['nd_overtime_minutes'] ?? 0;
            $ndPay = round(
                ($ndRegMinutes / 60.0) * $hourlyRate * $ndBase * $ndPct
                + ($ndOtMinutes / 60.0) * $hourlyRate * $otMult * $ndPct,
                2
            );
            $otPay = round($otHours * ($hourlyRate * $otMult), 2);
            $regularPay = round($regularHours * ($hourlyRate * $base), 2);

            $regularPremium = $regularHours * $hourlyRate * max(0, $base - 1.0);
            if (in_array($ruleCode, ['RH', 'DH'], true)) {
                $regularHolidayPay = round($regularPremium, 2);
            } elseif (in_array($ruleCode, ['RD'], true)) {
                $restDayPremiumPay = round($regularPremium, 2);
            } elseif (in_array($ruleCode, ['SH'], true)) {
                $specialHolidayPay = round($regularPremium, 2);
            } elseif (in_array($ruleCode, ['RHRD', 'SHRD', 'DHRD'], true)) {
                $combinedPremiumsPay = round($regularPremium, 2);
            }
        }

        $totalPay = round($regularPay + $otPay + $ndPay, 2);

        $ruleLabels = self::RULE_LABELS[$ruleCode] ?? [null, null, null];

        return [
            'date' => $dateKey,
            'rule_code' => $ruleCode,
            'work_condition' => $ruleLabels[0],
            'pay_rule' => $ruleLabels[1],
            'multiplier' => $ruleLabels[2],
            'is_rest_day' => $isRestDay,
            'holiday_type' => $holidayType,
            'regular_hours' => $regularHours,
            'overtime_hours' => $otHours,
            'night_hours' => $ndHours,
            'regular_pay' => $regularPay,
            'overtime_pay' => $otPay,
            'night_differential_pay' => $ndPay,
            'regular_holiday_pay' => $regularHolidayPay,
            'special_holiday_pay' => $specialHolidayPay,
            'rest_day_premium_pay' => $restDayPremiumPay,
            'combined_premiums_pay' => $combinedPremiumsPay,
            'total_pay' => $totalPay,
            'ot_source' => $approvedOt ? 'from_logs_approved_eligible' : 'from_logs',
        ];
    }

    private function summarizeDays(array $days): array
    {
        $totalRegularHours = 0.0;
        $totalOvertimeHours = 0.0;
        $totalNightHours = 0.0;
        $totalRegularPay = 0.0;
        $totalOvertimePay = 0.0;
        $totalNightDifferentialPay = 0.0;
        $totalRegularHolidayPay = 0.0;
        $totalSpecialHolidayPay = 0.0;
        $totalRestDayPremiumPay = 0.0;
        $totalCombinedPremiumsPay = 0.0;
        $totalPay = 0.0;

        foreach ($days as $d) {
            $totalRegularHours += $d['regular_hours'];
            $totalOvertimeHours += $d['overtime_hours'];
            $totalNightHours += $d['night_hours'];
            $totalRegularPay += $d['regular_pay'];
            $totalOvertimePay += $d['overtime_pay'];
            $totalNightDifferentialPay += $d['night_differential_pay'];
            $totalRegularHolidayPay += $d['regular_holiday_pay'] ?? 0;
            $totalSpecialHolidayPay += $d['special_holiday_pay'] ?? 0;
            $totalRestDayPremiumPay += $d['rest_day_premium_pay'] ?? 0;
            $totalCombinedPremiumsPay += $d['combined_premiums_pay'] ?? 0;
            $totalPay += $d['total_pay'];
        }

        $allPremiums = $totalOvertimePay + $totalNightDifferentialPay
            + $totalRegularHolidayPay + $totalSpecialHolidayPay
            + $totalRestDayPremiumPay + $totalCombinedPremiumsPay;

        return [
            'total_regular_hours' => round($totalRegularHours, 2),
            'total_overtime_hours' => round($totalOvertimeHours, 2),
            'total_night_hours' => round($totalNightHours, 2),
            'total_regular_pay' => round($totalRegularPay, 2),
            'total_overtime_pay' => round($totalOvertimePay, 2),
            'total_night_differential_pay' => round($totalNightDifferentialPay, 2),
            'total_regular_holiday_pay' => round($totalRegularHolidayPay, 2),
            'total_special_holiday_pay' => round($totalSpecialHolidayPay, 2),
            'total_rest_day_premium_pay' => round($totalRestDayPremiumPay, 2),
            'total_combined_premiums_pay' => round($totalCombinedPremiumsPay, 2),
            'total_premium_pay' => round($allPremiums, 2),
            'total_pay' => round($totalPay, 2),
            'days_with_attendance' => count($days),
        ];
    }
}
