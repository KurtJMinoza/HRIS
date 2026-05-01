<?php

namespace App\Support;

use App\Models\Payslip;
use App\Models\User;
use App\Services\PayslipService;

/**
 * JSON shape for full payslip preview (stored snapshot) — shared by admin and employee self-service.
 */
final class PayslipStoredSnapshotViewPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromStoredPayslip(
        Payslip $payslip,
        User $employee,
        PayslipService $payslipService,
        ?string $companyLogoPublicUrl
    ): array {
        try {
            $live = $payslipService->previewDataForEmployee($employee, $payslipService->periodInputFromPayslip($payslip));
            if (is_array($live) && is_array($live['summary'] ?? null)) {
                $live['payslip_id'] = (int) $payslip->id;
                $live['batch_scope'] = [
                    'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
                    'branch_id' => $payslip->branch_id !== null ? (int) $payslip->branch_id : null,
                    'department_id' => $payslip->department_id !== null ? (int) $payslip->department_id : null,
                ];
                if (isset($live['company']) && is_array($live['company'])) {
                    $live['company']['logo_url'] = $companyLogoPublicUrl;
                }
                if (isset($live['employee']) && is_array($live['employee'])) {
                    $live['employee']['profile_image_url'] = $employee->profile_image_url;
                }
                if (! isset($live['payroll']) || ! is_array($live['payroll'])) {
                    $live['payroll'] = [];
                }
                $live['payroll']['status'] = (string) $payslip->status;
                $live['payroll']['cycle_label'] = $payslip->cycle_label ?: ($live['payroll']['cycle_label'] ?? null);

                return $live;
            }
        } catch (\Throwable) {
            // Fall back to the stored snapshot below if current attendance recomputation is unavailable.
        }

        $snapshotRaw = is_array($payslip->snapshot ?? null) ? $payslip->snapshot : [];
        $snapshot = $payslipService->normalizeSnapshotForPayslipView($snapshotRaw);
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $dailyEarningLines = is_array($summary['daily_computation_earning_lines'] ?? null)
            ? array_values($summary['daily_computation_earning_lines'])
            : [];
        if (count($dailyEarningLines) === 0) {
            $regularPay = (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0));
            $attendancePremium = (float) ($summary['attendance_premium_pay_this_period'] ?? 0);
            if ($regularPay > 0) {
                $dailyEarningLines[] = [
                    'key' => 'fallback:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => round($regularPay, 2),
                ];
            }
            if ($attendancePremium > 0) {
                $dailyEarningLines[] = [
                    'key' => 'fallback:attendance_premium',
                    'label' => 'Attendance premiums (OT/ND/Holiday)',
                    'amount' => round($attendancePremium, 2),
                ];
            }
        }
        $company = $payslip->company ?? $employee->company;

        $gov = $payslipService->governmentIdFieldsForPayslip($employee);
        $employmentLabel = $payslipService->employmentStatusLabelForPayslip($employee);

        return [
            'payslip_id' => (int) $payslip->id,
            'company' => [
                'id' => $company?->id !== null ? (int) $company->id : null,
                'name' => $company?->name,
                'tin' => $company?->tin,
                'address' => $company?->address,
                'email' => $company?->email,
                'phone' => $company?->phone,
                'logo_url' => $companyLogoPublicUrl,
            ],
            'batch_scope' => [
                'company_id' => $payslip->company_id !== null ? (int) $payslip->company_id : null,
                'branch_id' => $payslip->branch_id !== null ? (int) $payslip->branch_id : null,
                'department_id' => $payslip->department_id !== null ? (int) $payslip->department_id : null,
            ],
            'employee' => [
                'id' => (int) $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->departmentRelation?->name ?? $employee->department,
                'position' => $employee->position,
                'employment_status' => $employee->employment_status,
                'employment_status_label' => $employmentLabel,
                'profile_image_url' => $employee->profile_image_url,
                'tin_number' => $gov['tin_number'],
                'sss_number' => $gov['sss_number'],
                'philhealth_number' => $gov['philhealth_number'],
                'pagibig_number' => $gov['pagibig_number'],
            ],
            'payroll' => [
                'pay_period_start' => $payslip->pay_period_start?->toDateString(),
                'pay_period_end' => $payslip->pay_period_end?->toDateString(),
                'pay_date' => $payslip->pay_date?->toDateString(),
                'pay_cycle_id' => $payslip->pay_cycle_id !== null ? (int) $payslip->pay_cycle_id : null,
                'payroll_period_id' => $payslip->payroll_period_id !== null ? (int) $payslip->payroll_period_id : null,
                'cycle_label' => $payslip->cycle_label,
                'is_final_pay' => (bool) $payslip->is_final_pay,
                'status' => (string) $payslip->status,
                'daily_rate' => (float) ($summary['daily_rate'] ?? data_get($snapshot, 'daily_rate', 0)),
                'daily_rate_divisor_days' => (int) ($summary['daily_rate_divisor_days'] ?? data_get($snapshot, 'daily_rate_divisor_days', 0)),
            ],
            'amounts' => [
                'gross_pay' => (float) ($payslip->gross_pay ?? 0),
                'total_deductions' => (float) ($payslip->total_deductions ?? 0),
                'net_pay' => (float) ($payslip->net_pay ?? 0),
                'taxable_total_this_period' => (float) ($payslip->taxable_total_this_period ?? 0),
                'non_taxable_total_this_period' => (float) ($payslip->non_taxable_total_this_period ?? 0),
                'ytd_gross' => (float) ($payslip->ytd_gross ?? 0),
                'ytd_deductions' => (float) ($payslip->ytd_deductions ?? 0),
                'ytd_tax' => (float) ($payslip->ytd_tax ?? 0),
            ],
            'summary' => [
                'basic_pay' => (float) ($summary['basic_pay_this_period'] ?? ($summary['total_pay'] ?? 0)),
                'attendance_premium_pay_this_period' => (float) ($summary['attendance_premium_pay_this_period'] ?? 0),
                'non_basic_earnings_this_period' => (float) ($summary['non_basic_earnings_this_period'] ?? 0),
                'withholding_tax_this_period_estimate' => (float) ($summary['withholding_tax_this_period_estimate'] ?? 0),
                'employee_statutory_this_period' => (float) ($summary['employee_statutory_this_period'] ?? 0),
                'custom_deductions_this_period' => (float) ($summary['custom_deductions_this_period'] ?? 0),
                'actual_days_worked' => (float) ($summary['actual_days_worked'] ?? 0),
                'daily_rate' => (float) ($summary['daily_rate'] ?? data_get($snapshot, 'daily_rate', 0)),
                'basic_salary_schedule_type' => (string) ($summary['basic_salary_schedule_type'] ?? ''),
                'basic_salary_schedule_factor' => (float) ($summary['basic_salary_schedule_factor'] ?? 0),
                'payslip_earning_lines' => is_array($summary['payslip_earning_lines'] ?? null) ? $summary['payslip_earning_lines'] : [],
                'daily_computation_earning_lines' => $dailyEarningLines,
                'attendance_display_summary' => is_array($summary['attendance_display_summary'] ?? null)
                    ? $summary['attendance_display_summary']
                    : [
                        'working_days_count' => 0,
                        'presence_days_count' => 0,
                        'lines' => [],
                        'total_regular_hours' => 0.0,
                        'total_presence_regular_hours' => 0.0,
                    ],
                'holiday_premium_breakdown' => is_array($summary['holiday_premium_breakdown'] ?? null)
                    ? array_values($summary['holiday_premium_breakdown'])
                    : [],
                'payslip_deduction_lines' => is_array($summary['payslip_deduction_lines'] ?? null) ? $summary['payslip_deduction_lines'] : [],
                'payslip_custom_deduction_lines' => is_array($summary['payslip_custom_deduction_lines'] ?? null) ? $summary['payslip_custom_deduction_lines'] : [],
                'statutory_breakdown' => is_array($summary['statutory_breakdown'] ?? null) ? $summary['statutory_breakdown'] : [],
            ],
            'snapshot' => $snapshot,
        ];
    }
}
