<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Shared schedule-aware attendance minute computation.
 *
 * This service owns the ordinary-day Payroll Impact calculation used by
 * attendance, dashboards, reports, payroll previews, and payslips.
 */
class AttendanceComputationService
{
    public function __construct(
        private readonly ScheduleComputationService $scheduleComputation,
    ) {}

    /**
     * @return array{
     *   schedule_start: ?Carbon,
     *   schedule_end: ?Carbon,
     *   scheduled_paid_minutes: int,
     *   break_minutes: int,
     *   time_in: Carbon,
     *   time_out: Carbon,
     *   raw_minutes: int,
     *   break_overlap_minutes: int,
     *   actual_payable_minutes: int,
     *   late_minutes: int,
     *   undertime_minutes: int,
     *   deduct_late_from_pay: bool,
     *   deduct_undertime_from_pay: bool,
     *   payroll_impact_minutes: int,
     *   payroll_impact_hours: float
     * }
     */
    public function computePayrollImpact(
        string $dateKey,
        array $daySchedule,
        Carbon $timeIn,
        Carbon $timeOut,
        ?string $tz = null,
        ?array $policy = null,
    ): array {
        $tz = $tz ?? config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $in = $timeIn->copy()->timezone($tz)->second(0);
        $out = $timeOut->copy()->timezone($tz)->second(0);
        if ($out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        $daySchedule = $this->scheduleComputation
            ->resolveFlexibleShiftForAttendance($dateKey, $daySchedule, $in, $out, $tz)['schedule'];

        $summary = $this->scheduleComputation->summarize($dateKey, $daySchedule, $tz);
        $scheduleStart = $summary['start'];
        $scheduleEnd = $summary['end'];
        $scheduledPaidMinutes = (int) $summary['required_minutes'];

        $rawMinutes = (int) $in->diffInMinutes($out);
        $rawBreakOverlap = $this->scheduleComputation->totalUnpaidBreakOverlapMinutes(
            $dateKey,
            $daySchedule,
            $in,
            $out,
            $tz
        );

        // Payroll Impact represents payable regular work. Early arrivals and
        // unapproved post-shift time do not inflate an ordinary scheduled day.
        $payableIn = $scheduleStart && $in->lessThan($scheduleStart)
            ? $scheduleStart->copy()
            : $in->copy();
        $payableOut = $scheduleEnd && $out->greaterThan($scheduleEnd)
            ? $scheduleEnd->copy()
            : $out->copy();

        $actualPayableMinutes = 0;
        $payableBreakOverlap = 0;
        if ($payableOut->greaterThan($payableIn)) {
            $payableBreakOverlap = $this->scheduleComputation->totalUnpaidBreakOverlapMinutes(
                $dateKey,
                $daySchedule,
                $payableIn,
                $payableOut,
                $tz
            );
            $actualPayableMinutes = max(
                0,
                (int) $payableIn->diffInMinutes($payableOut) - $payableBreakOverlap
            );
        }

        $graceMinutes = AttendanceStatusService::getGraceMinutes($daySchedule);
        $arrivalShortfallMinutes = $scheduleStart && $in->greaterThan($scheduleStart)
            ? (int) $scheduleStart->diffInMinutes($in)
            : 0;
        $lateMinutes = $arrivalShortfallMinutes > 0
            ? max(0, $arrivalShortfallMinutes - $graceMinutes)
            : 0;
        $undertimeMinutes = $scheduleEnd && $out->lessThan($scheduleEnd)
            ? AttendanceStatusService::getScheduleAwareUndertimeMinutes(
                $dateKey,
                $daySchedule,
                $in,
                $out,
                $tz,
                isset($daySchedule['early_timeout_minutes'])
                    ? (int) $daySchedule['early_timeout_minutes']
                    : null
            )
            : 0;

        $deductLate = filter_var(
            $policy['deduct_late_from_pay'] ?? config('attendance.deduct_late_from_pay', true),
            FILTER_VALIDATE_BOOL
        );
        $deductUndertime = filter_var(
            $policy['deduct_undertime_from_pay'] ?? config('attendance.deduct_undertime_from_pay', true),
            FILTER_VALIDATE_BOOL
        );

        $payrollImpactMinutes = $actualPayableMinutes;
        if ($scheduledPaidMinutes > 0) {
            if (! $deductLate && $arrivalShortfallMinutes > 0) {
                $payrollImpactMinutes += $arrivalShortfallMinutes;
            }
            if (! $deductUndertime && $undertimeMinutes > 0) {
                $payrollImpactMinutes += $undertimeMinutes;
            }

            $payrollImpactMinutes = min($scheduledPaidMinutes, $payrollImpactMinutes);
        }

        return [
            'schedule_start' => $scheduleStart,
            'schedule_end' => $scheduleEnd,
            'scheduled_paid_minutes' => $scheduledPaidMinutes,
            'break_minutes' => (int) $summary['break_minutes'],
            'time_in' => $in,
            'time_out' => $out,
            'raw_minutes' => $rawMinutes,
            'break_overlap_minutes' => $rawBreakOverlap,
            'actual_payable_minutes' => $actualPayableMinutes,
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'deduct_late_from_pay' => $deductLate,
            'deduct_undertime_from_pay' => $deductUndertime,
            'payroll_impact_minutes' => max(0, $payrollImpactMinutes),
            'payroll_impact_hours' => round(max(0, $payrollImpactMinutes) / 60, 2),
        ];
    }
}
