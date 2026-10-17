<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\PayrollReportService;
use App\Services\PayslipService;
use ReflectionClass;
use Tests\TestCase;

class AttendanceRefundReportColumnTest extends TestCase
{
    private function rowForPayslip(Payslip $payslip): array
    {
        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);

        return $method->invoke($service, $payslip);
    }

    public function test_scenario_a_attendance_refund_included_in_basic_pay_not_gross_twice(): void
    {
        $refund = 999.97;
        $regular = 10000.0;
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => round($regular + $refund, 2),
            'total_deductions' => 0,
            'net_pay' => round($regular + $refund, 2),
            'snapshot' => [
                'summary' => [
                    'payroll_report_v2' => true,
                    'display_gross_pay' => round($regular + $refund, 2),
                    'display_net_pay' => round($regular + $refund, 2),
                    'daily_computation_earning_lines' => [[
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => $regular,
                    ]],
                    'payslip_earning_lines' => [[
                        'key' => 'refund_basic_pay',
                        'label' => 'Attendance Refund — Missing Attendance',
                        'amount' => $refund,
                        'component_code' => 'refund_basic_pay',
                        'source' => 'basic_pay',
                        'metadata' => ['reason' => 'missing_attendance'],
                    ]],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta($refund, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta($regular + $refund, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta($regular + $refund, (float) $row['gross_earnings'], 0.02);
    }

    public function test_scenario_b_no_attendance_refund(): void
    {
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 10000.0,
            'total_deductions' => 0,
            'net_pay' => 10000.0,
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => 10000.0,
                    'display_net_pay' => 10000.0,
                    'daily_computation_earning_lines' => [[
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 10000.0,
                    ]],
                    'payslip_earning_lines' => [],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta(0.0, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $row['gross_earnings'], 0.02);
    }

    public function test_scenario_c_multiple_attendance_refunds_summed(): void
    {
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 10750.0,
            'total_deductions' => 0,
            'net_pay' => 10750.0,
            'snapshot' => [
                'summary' => [
                    'payroll_report_v2' => true,
                    'display_gross_pay' => 10750.0,
                    'display_net_pay' => 10750.0,
                    'daily_computation_earning_lines' => [[
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 10000.0,
                    ]],
                    'payslip_earning_lines' => [
                        [
                            'key' => 'refund_basic_pay',
                            'label' => 'Attendance Refund — Missing Time In',
                            'amount' => 500.0,
                            'component_code' => 'refund_basic_pay',
                            'source' => 'basic_pay',
                            'metadata' => ['reason' => 'missing_time_in'],
                        ],
                        [
                            'key' => 'refund_basic_pay',
                            'label' => 'Attendance Refund — Missing Time Out',
                            'amount' => 250.0,
                            'component_code' => 'refund_basic_pay',
                            'source' => 'basic_pay',
                            'metadata' => ['reason' => 'missing_time_out'],
                        ],
                    ],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta(750.0, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta(10750.0, (float) $row['regular_basic_pay'], 0.02);
    }

    public function test_scenario_d_attendance_refund_with_overtime(): void
    {
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 11499.97,
            'total_deductions' => 0,
            'net_pay' => 11499.97,
            'snapshot' => [
                'summary' => [
                    'payroll_report_v2' => true,
                    'display_gross_pay' => 11499.97,
                    'display_net_pay' => 11499.97,
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 10000.0],
                        ['key' => 'daily:ot_pay', 'label' => 'Overtime', 'amount' => 500.0],
                    ],
                    'payslip_earning_lines' => [[
                        'key' => 'refund_basic_pay',
                        'label' => 'Attendance Refund — Missing Attendance',
                        'amount' => 999.97,
                        'component_code' => 'refund_basic_pay',
                        'source' => 'basic_pay',
                        'metadata' => ['reason' => 'missing_attendance'],
                    ]],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta(999.97, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta(10999.97, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(500.0, (float) $row['overtime_pay'], 0.02);
        $this->assertEqualsWithDelta(11499.97, (float) $row['gross_earnings'], 0.02);
    }

    public function test_late_deduction_refund_not_counted_as_attendance_refund_breakdown(): void
    {
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 10500.0,
            'total_deductions' => 0,
            'net_pay' => 10500.0,
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => 10500.0,
                    'display_net_pay' => 10500.0,
                    'daily_computation_earning_lines' => [[
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 10000.0,
                    ]],
                    'payslip_earning_lines' => [[
                        'key' => 'refund_basic_pay',
                        'label' => 'Attendance Refund — Incorrect Late Deduction',
                        'amount' => 500.0,
                        'component_code' => 'refund_basic_pay',
                        'source' => 'basic_pay',
                        'metadata' => ['reason' => 'incorrect_late_deduction'],
                    ]],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta(0.0, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(500.0, (float) $row['other_earnings'], 0.02);
    }

    public function test_leave_with_pay_breakdown_included_in_basic_not_gross_twice(): void
    {
        $leave = 750.0;
        $regular = 9000.0;
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => round($regular + $leave, 2),
            'total_deductions' => 0,
            'net_pay' => round($regular + $leave, 2),
            'snapshot' => [
                'summary' => [
                    'payroll_report_v2' => true,
                    'display_gross_pay' => round($regular + $leave, 2),
                    'display_net_pay' => round($regular + $leave, 2),
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => $regular],
                        ['key' => 'daily:paid_leave', 'label' => 'Leave adjustments', 'amount' => $leave],
                    ],
                    'payslip_earning_lines' => [],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta($leave, (float) $row['leave_with_pay'], 0.02);
        $this->assertEqualsWithDelta($regular + $leave, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta($regular + $leave, (float) $row['gross_earnings'], 0.02);
    }

    public function test_legacy_finalized_report_keeps_basic_pay_without_rollups(): void
    {
        $refund = 500.0;
        $regular = 10000.0;
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => round($regular + $refund, 2),
            'total_deductions' => 0,
            'net_pay' => round($regular + $refund, 2),
            'snapshot' => [
                'summary' => [
                    'display_gross_pay' => round($regular + $refund, 2),
                    'display_net_pay' => round($regular + $refund, 2),
                    'daily_computation_earning_lines' => [[
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => $regular,
                    ]],
                    'payslip_earning_lines' => [[
                        'key' => 'refund_basic_pay',
                        'label' => 'Attendance Refund — Missing Attendance',
                        'amount' => $refund,
                        'component_code' => 'refund_basic_pay',
                        'source' => 'basic_pay',
                        'metadata' => ['reason' => 'missing_attendance'],
                    ]],
                ],
            ],
        ]);

        $row = $this->rowForPayslip($payslip);

        $this->assertEqualsWithDelta($refund, (float) $row['attendance_refund'], 0.02);
        $this->assertEqualsWithDelta($regular, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(round($regular + $refund, 2), (float) $row['gross_earnings'], 0.02);
    }

    public function test_payslip_summary_exposes_attendance_refund_total(): void
    {
        $summary = [
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => 10000.0,
            ]],
            'payslip_earning_lines' => [[
                'key' => 'refund_basic_pay',
                'label' => 'Attendance Refund — Missing Attendance',
                'amount' => 999.97,
                'component_code' => 'refund_basic_pay',
                'source' => 'basic_pay',
                'metadata' => ['reason' => 'missing_attendance'],
            ]],
        ];

        $amount = app(PayslipService::class)->attendanceRefundIncludedInBasicPayAmount($summary);

        $this->assertEqualsWithDelta(999.97, $amount, 0.02);
    }
}
