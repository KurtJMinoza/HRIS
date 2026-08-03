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

        return $context;
    }

    /**
     * Legacy rows written before auto-present was removed must not keep faking presence.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function stripStaleAutoPresent(array $context): array
    {
        if (($context['presence_issue'] ?? '') !== 'execom_auto_present') {
            return $context;
        }

        $hasPunch = trim((string) ($context['time_in'] ?? '')) !== ''
            || trim((string) ($context['time_out'] ?? '')) !== ''
            || trim((string) ($context['formatted_time_in'] ?? '')) !== ''
            || trim((string) ($context['formatted_time_out'] ?? '')) !== '';

        if ($hasPunch) {
            return $context;
        }

        $context['status'] = 'absent';
        $context['status_label'] = 'Absent';
        $context['display_badge'] = 'Absent';
        $context['presence_label'] = null;
        $context['presence_issue'] = null;
        $context['payroll_impact_hours'] = 0.0;
        $context['payroll_impact_minutes'] = 0;

        return $context;
    }
}
