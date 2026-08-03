<?php

namespace App\Services;

use App\Models\ExecomPayrollSetting;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * EXECOM attendance/report presentation overrides driven by ExecomPayrollSetting.
 */
class ExecomAttendancePresentationService
{
    public function __construct(
        private readonly EmployeeClassificationService $classification,
        private readonly LeaveCreditService $leaveCreditService,
        private readonly ExecomPayrollPolicyResolver $policyResolver,
    ) {}

    public function settingsFor(User $employee, ?CarbonInterface $asOf = null): ?ExecomPayrollSetting
    {
        $date = $asOf ?? now();
        if (! $this->classification->isExecom($employee, $date, $date)) {
            return null;
        }

        $profile = $employee->activeExecomProfileForPeriod($date, $date);
        $companyId = $profile?->company_id
            ? (int) $profile->company_id
            : ($employee->getEffectiveCompanyId() ? (int) $employee->getEffectiveCompanyId() : null);

        return $this->policyResolver->setting($companyId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function apply(User $employee, string $dateKey, array $context): array
    {
        $settings = $this->settingsFor($employee, Carbon::parse($dateKey));
        if (! $settings instanceof ExecomPayrollSetting) {
            return $context;
        }

        $policy = $settings->toPolicyArray();
        $leave = $context['leave'] ?? null;
        $hasLeave = (bool) ($context['has_leave'] ?? false)
            || $leave instanceof LeaveRequest
            || in_array(strtolower(trim((string) ($context['status'] ?? ''))), ['leave', 'halfday', 'half_day'], true);

        if (! (bool) $policy['allow_paid_leave'] && $hasLeave) {
            $context['leave_pay_status'] = 'unpaid';
            $context['leave_pay_label'] = 'Approved Leave — Unpaid under EXECOM payroll policy';
            $status = strtolower(trim((string) ($context['status'] ?? '')));
            if (in_array($status, ['leave', 'halfday', 'half_day'], true)) {
                $context['status_label'] = (string) $context['leave_pay_label'];
                $context['display_badge'] = (string) $context['leave_pay_label'];
            }
        }

        if (! (bool) $policy['auto_present_attendance_reports'] || (bool) ($context['is_future'] ?? false)) {
            return $context;
        }

        // Priority: leave/exception → holiday/rest → existing attendance/correction → auto-present → absent
        $status = strtolower(trim((string) ($context['status'] ?? '')));
        $daySchedule = $context['day_schedule'] ?? null;
        $hasSchedule = is_array($daySchedule) && ! empty($daySchedule['in']);
        $hasHoliday = (bool) ($context['has_holiday'] ?? false)
            || $status === 'holiday'
            || ! empty($context['holiday_name']);
        $hasPunch = (bool) ($context['has_punch'] ?? false)
            || ! empty($context['time_in'])
            || ! empty($context['time_out']);
        $hasCorrection = (bool) ($context['has_correction'] ?? false)
            || (bool) ($context['correction_approved'] ?? false);
        $isRest = (bool) ($context['is_rest_day'] ?? false)
            || in_array($status, ['rest', 'rest_day', 'no_schedule_rest'], true);

        if ($hasLeave || $hasHoliday || $isRest || $hasPunch || $hasCorrection || $status !== 'absent') {
            return $context;
        }

        if (! $hasSchedule) {
            return $context;
        }

        $context['status'] = 'present';
        $context['status_label'] = 'Auto Present';
        $context['display_badge'] = 'Auto Present';
        $context['presence_label'] = 'Auto Present';
        $context['presence_issue'] = 'execom_auto_present';
        $context['is_present'] = true;
        $context['is_absent'] = false;

        if (($context['payroll_impact_hours'] ?? null) === null && isset($context['scheduled_regular_hours'])) {
            $context['payroll_impact_hours'] = (float) $context['scheduled_regular_hours'];
        }
        if (($context['payroll_impact_minutes'] ?? null) === null && isset($context['scheduled_regular_hours'])) {
            $context['payroll_impact_minutes'] = (int) round(((float) $context['scheduled_regular_hours']) * 60);
        }

        return $context;
    }
}
