<?php

namespace Tests\Unit;

use App\Models\PayrollBatchRun;
use App\Models\Payslip;
use App\Services\PayrollReportService;
use ReflectionClass;
use Tests\TestCase;

class PayrollReportServiceTest extends TestCase
{
    public function test_report_basic_pay_uses_regular_pay_after_reductions(): void
    {
        $snapshot = [
            'daily_rate' => 582.69,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 582.69,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 15150,
                'semi_monthly_basic_salary' => 7575,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 7429.30,
                ]],
            ],
            'daily_computation_days' => array_fill(0, 13, [
                'date' => '2026-07-26',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 450,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'undertime_deduction_minutes' => 30,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 450,
                    'rate' => 72.83625,
                    'amount' => 546.27,
                ]],
            ]),
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 7101.53,
            'total_deductions' => 0,
            'net_pay' => 7101.53,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(7101.53, (float) $row['regular_basic_pay'], 0.02);
    }

    public function test_report_other_column_includes_refund_other_lines(): void
    {
        $snapshot = [
            'daily_rate' => 961.54,
            'summary' => [
                'daily_rate' => 961.54,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 10276.46,
                    'display_amount' => 10576.92,
                ]],
                'payslip_earning_lines' => [[
                    'key' => 'refund_basic_pay',
                    'label' => 'Other',
                    'amount' => 6000,
                    'category' => 'basic_pay',
                    'component_code' => 'refund_basic_pay',
                ]],
            ],
            'daily_computation_days' => array_fill(0, 11, [
                'date' => '2026-08-12',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 480,
                    'rate' => 120.1925,
                    'amount' => 961.54,
                ]],
            ]),
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 16276.46,
            'total_deductions' => 0,
            'net_pay' => 16276.46,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(6000.0, (float) $row['other_earnings'], 0.02);
        $this->assertLessThan(16276.46, (float) $row['regular_basic_pay']);
    }

    public function test_report_other_column_does_not_duplicate_holiday_pay(): void
    {
        $snapshot = [
            'daily_rate' => 961.54,
            'summary' => [
                'daily_rate' => 961.54,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 5000,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 961.54,
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                    ],
                    [
                        'key' => 'holiday:2026-08-22:244:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: NINOY EXCHANGE',
                        'amount' => 961.54,
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                    ],
                ],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 6923.08,
            'total_deductions' => 0,
            'net_pay' => 6923.08,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(6923.08, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(0.0, (float) $row['holiday_pay'], 0.02);
        $this->assertEqualsWithDelta(0.0, (float) $row['other_earnings'], 0.02);
    }

    public function test_report_holiday_column_includes_only_worked_holiday_pay(): void
    {
        $snapshot = [
            'daily_rate' => 961.54,
            'summary' => [
                'daily_rate' => 961.54,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 5000,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 961.54,
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false, 'unworked' => true],
                    ],
                    [
                        'key' => 'holiday:2026-08-20:240:SPECIAL_HOLIDAY_WORKED_PAY',
                        'label' => 'Special Holiday — Worked Pay: EXAMPLE',
                        'amount' => 1250,
                        'component_code' => 'SPECIAL_HOLIDAY_WORKED_PAY',
                        'metadata' => ['worked' => true, 'unworked' => false, 'multiplier' => 1.30],
                    ],
                ],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 7211.54,
            'total_deductions' => 0,
            'net_pay' => 7211.54,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(6923.08, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(288.46, (float) $row['holiday_pay'], 0.02);
    }

    public function test_report_consultant_basic_pay_uses_semi_monthly_not_monthly(): void
    {
        $snapshot = [
            'summary' => [
                'consultant_fixed_payroll' => true,
                'employment_status' => 'consultant',
                'consultant_fixed_salary' => 46316,
                'monthly_basic_salary' => 46316,
                'basic_pay' => 46316,
                'basic_pay_this_period' => 23158,
                'payslip_earning_lines' => [
                    ['key' => 'consultant_basic_pay', 'label' => 'Basic Pay', 'amount' => 23158, 'component_code' => 'BASIC_SALARY'],
                    ['key' => 'pay_component:allowance', 'label' => 'ALLOWANCE', 'component_code' => 'ALLOWANCE', 'amount' => 5000],
                ],
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 46316],
                ],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'payroll_module' => PayrollBatchRun::MODULE_CONSULTANT,
            'gross_pay' => 28158,
            'total_deductions' => 0,
            'net_pay' => 28158,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(23158.0, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(5000.0, (float) $row['allowance'], 0.02);
        $this->assertSame('—', $row['total_attendance']);
    }

    public function test_report_consultant_basic_pay_falls_back_to_half_of_monthly_when_period_field_missing(): void
    {
        $snapshot = [
            'summary' => [
                'consultant_fixed_payroll' => true,
                'employment_status' => 'consultant',
                'consultant_fixed_salary' => 46316,
                'basic_pay' => 46316,
                'payslip_earning_lines' => [],
                'daily_computation_earning_lines' => [],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'payroll_module' => PayrollBatchRun::MODULE_CONSULTANT,
            'gross_pay' => 23158,
            'total_deductions' => 0,
            'net_pay' => 23158,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(23158.0, (float) $row['regular_basic_pay'], 0.02);
    }

    public function test_late_deduction_does_not_include_unworked_holiday_when_line_missing_from_snapshot(): void
    {
        $snapshot = json_decode(file_get_contents(__DIR__.'/Fixtures/lupas_15488_snapshot.json'), true);
        if (! is_array($snapshot)) {
            $this->markTestSkipped('Lupas fixture snapshot unavailable.');
        }

        $normalized = app(\App\Services\PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $lateRow = collect($breakdown['rows'] ?? [])->firstWhere('key', 'late');

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_DRAFT,
            'gross_pay' => 5240.38,
            'total_deductions' => 0,
            'net_pay' => 5240.38,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(4471.15, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(144.23, (float) ($lateRow['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(4471.15, (float) $row['regular_basic_pay'], 0.02);
    }

    public function test_report_splits_special_holiday_worked_pay_into_regular_base_and_holiday_premium(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'summary' => [
                'daily_rate' => 692.31,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 4000,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_WORKED_PAY',
                        'label' => 'Special Holiday — Worked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 900,
                        'minutes_worked' => 480,
                        'hourly_rate' => 112.5,
                        'component_code' => 'SPECIAL_HOLIDAY_WORKED_PAY',
                        'metadata' => ['worked' => true, 'holiday_type' => 'special', 'multiplier' => 1.30],
                    ],
                ],
            ],
        ];

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 4900,
            'total_deductions' => 0,
            'net_pay' => 4900,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(4692.31, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(207.69, (float) $row['holiday_pay'], 0.02);
    }

    public function test_payslip_hides_unworked_holiday_lines_from_display(): void
    {
        $lines = [
            ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 1000],
            ['key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY', 'label' => 'Unworked holiday', 'amount' => 200, 'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY', 'metadata' => ['worked' => false]],
            ['key' => 'holiday:2026-08-20:240:SPECIAL_HOLIDAY_WORKED_PAY', 'label' => 'Worked holiday', 'amount' => 100, 'component_code' => 'SPECIAL_HOLIDAY_WORKED_PAY', 'metadata' => ['worked' => true]],
        ];

        $service = app(\App\Services\PayslipService::class);
        $method = (new ReflectionClass($service))->getMethod('hideUnworkedHolidayLinesFromPayslipDisplay');
        $method->setAccessible(true);
        $visible = $method->invoke($service, $lines);

        $this->assertSame(
            ['daily:regular_pay', 'holiday:2026-08-20:240:SPECIAL_HOLIDAY_WORKED_PAY'],
            array_column($visible, 'key'),
        );
    }

    public function test_regular_pay_after_reductions_includes_unworked_holiday_without_inflating_late(): void
    {
        $dailyDays = array_fill(0, 11, [
            'date' => '2026-08-12',
            'status' => 'worked',
            'is_rest_day' => false,
            'regular_day_minutes' => 450,
            'regular_night_minutes' => 0,
            'required_minutes' => 480,
            'late_deduction_minutes' => 30,
            'tardiness_status' => 'late',
            'tardiness_label' => '30 minutes late',
            'breakdown' => [[
                'component' => 'regular_pay',
                'minutes' => 450,
                'rate' => 120.1925,
                'amount' => 901.44,
            ]],
        ]);

        $snapshot = [
            'daily_rate' => 961.54,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 961.54,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 25000,
                'semi_monthly_basic_salary' => 12500,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 10276.46,
                        'minutes_worked' => 5130,
                        'hourly_rate' => 120.1925,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 961.54,
                        'units' => '1 day',
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false],
                    ],
                    [
                        'key' => 'holiday:2026-08-22:244:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: NINOY EXCHANGE',
                        'amount' => 961.54,
                        'units' => '1 day',
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false],
                    ],
                ],
            ],
            'daily_computation_days' => $dailyDays,
        ];

        $normalized = app(\App\Services\PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $lateRow = collect($breakdown['rows'] ?? [])->firstWhere('key', 'late');
        $visibleKeys = array_column($normalized['summary']['daily_computation_earning_lines'] ?? [], 'key');

        $this->assertNotContains('holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY', $visibleKeys);
        $this->assertGreaterThan(11000.0, (float) ($breakdown['regular_pay_after_reductions'] ?? 0));
        $this->assertLessThan(1500.0, (float) ($lateRow['deduction_amount'] ?? 0));
    }

    public function test_report_basic_pay_does_not_double_count_unworked_holiday_when_breakdown_present(): void
    {
        $dailyDays = array_fill(0, 11, [
            'date' => '2026-08-12',
            'status' => 'worked',
            'is_rest_day' => false,
            'regular_day_minutes' => 450,
            'regular_night_minutes' => 0,
            'required_minutes' => 480,
            'late_deduction_minutes' => 30,
            'tardiness_status' => 'late',
            'tardiness_label' => '30 minutes late',
            'breakdown' => [[
                'component' => 'regular_pay',
                'minutes' => 450,
                'rate' => 120.1925,
                'amount' => 901.44,
            ]],
        ]);

        $snapshot = [
            'daily_rate' => 961.54,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 961.54,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 25000,
                'semi_monthly_basic_salary' => 12500,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 10276.46,
                        'minutes_worked' => 5130,
                        'hourly_rate' => 120.1925,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 961.54,
                        'units' => '1 day',
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false],
                    ],
                    [
                        'key' => 'holiday:2026-08-22:244:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: NINOY EXCHANGE',
                        'amount' => 961.54,
                        'units' => '1 day',
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false],
                    ],
                ],
                'payslip_earning_lines' => [[
                    'key' => 'refund_basic_pay',
                    'label' => 'Other',
                    'amount' => 6000,
                    'category' => 'basic_pay',
                    'component_code' => 'refund_basic_pay',
                ]],
            ],
            'daily_computation_days' => $dailyDays,
        ];

        $view = app(\App\Services\PayslipService::class)->frozenSnapshotForPayslipView($snapshot);
        $expectedBasicPay = (float) ($view['summary']['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0);

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => round($expectedBasicPay + 6000, 2),
            'total_deductions' => 0,
            'net_pay' => round($expectedBasicPay + 6000, 2),
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertGreaterThan(11000.0, $expectedBasicPay);
        $this->assertEqualsWithDelta($expectedBasicPay, (float) $row['regular_basic_pay'], 0.02);
        $this->assertLessThan($expectedBasicPay + 1923.0, (float) $row['regular_basic_pay']);
        $this->assertEqualsWithDelta(6000.0, (float) $row['other_earnings'], 0.02);
        $this->assertSame('13 days', $row['total_attendance']);
    }

    public function test_regular_pay_attendance_label_includes_unworked_holiday_days(): void
    {
        $dailyDays = array_fill(0, 11, [
            'date' => '2026-08-12',
            'status' => 'worked',
            'is_rest_day' => false,
            'regular_day_minutes' => 480,
            'regular_night_minutes' => 0,
            'required_minutes' => 480,
            'breakdown' => [[
                'component' => 'regular_pay',
                'minutes' => 480,
                'rate' => 120.1925,
                'amount' => 961.54,
            ]],
        ]);

        $summary = [
            'daily_computation_days' => $dailyDays,
            'daily_computation_earning_lines' => [
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 10276.46,
                    'units' => '11 days',
                ],
                [
                    'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                    'amount' => 961.54,
                    'units' => '1 day',
                    'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'metadata' => ['worked' => false],
                ],
                [
                    'key' => 'holiday:2026-08-22:244:SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'label' => 'Special Holiday — Unworked Pay: NINOY EXCHANGE',
                    'amount' => 961.54,
                    'units' => '1 day',
                    'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'metadata' => ['worked' => false],
                ],
            ],
        ];

        $label = app(\App\Services\PayslipService::class)->regularPayAttendanceLabel($summary);

        $this->assertSame('13 days', $label);
    }

    public function test_double_normalization_preserves_unworked_holiday_regular_pay(): void
    {
        $snapshot = [
            'daily_rate' => 769.23,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 769.23,
                'daily_computation_earning_lines' => [[
                    'key' => 'holiday:2026-11-02:177:SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'label' => "Special Holiday — Unworked Pay: All Souls' Day",
                    'amount' => 769.23,
                    'units' => '1 day',
                    'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                    'metadata' => ['worked' => false, 'unworked' => true],
                ]],
                'holiday_premium_breakdown' => [[
                    'date' => '2026-11-02',
                    'holiday_name' => "All Souls' Day",
                    'amount' => 769.23,
                    'worked' => false,
                    'eligible' => true,
                    'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                ]],
            ],
            'daily_computation_days' => [[
                'date' => '2026-11-02',
                'status' => 'holiday',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'holiday_premium_pay' => 769.23,
                'regular_day_minutes' => 0,
                'regular_night_minutes' => 0,
                'worked_minutes' => 0,
                'holiday' => ['id' => 177, 'name' => "All Souls' Day"],
            ]],
        ];

        $service = app(\App\Services\PayslipService::class);
        $once = $service->normalizeSnapshotForPayslipView($snapshot);
        $twice = $service->normalizeSnapshotForPayslipView($once);

        $this->assertEqualsWithDelta(
            769.23,
            (float) ($twice['summary']['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0),
            0.02,
        );
        $this->assertEqualsWithDelta(769.23, (float) ($twice['summary']['display_gross_pay'] ?? 0), 0.02);
        $this->assertNotEmpty($twice['summary']['daily_computation_earning_lines'] ?? []);
        $this->assertSame('daily:regular_pay', ($twice['summary']['daily_computation_earning_lines'][0]['key'] ?? null));
    }

    public function test_regular_pay_after_reductions_matches_headline_when_attendance_deduction_is_zero(): void
    {
        $dailyDays = [];
        foreach (['2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14', '2026-08-16', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-23', '2026-08-25'] as $date) {
            $dailyDays[] = [
                'date' => $date,
                'status' => 'worked',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'worked_minutes' => 480,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 480,
                    'rate' => 96.15375,
                    'amount' => 769.23,
                ]],
            ];
        }
        $dailyDays[] = [
            'date' => '2026-08-15',
            'status' => 'holiday',
            'is_rest_day' => false,
            'required_minutes' => 480,
            'holiday_premium_pay' => 769.23,
            'regular_day_minutes' => 0,
            'regular_night_minutes' => 0,
            'worked_minutes' => 0,
            'holiday' => ['id' => 236, 'name' => 'KADAWAYAN EXCHANCE'],
        ];
        foreach (['2026-08-21', '2026-08-22'] as $date) {
            $dailyDays[] = [
                'date' => $date,
                'status' => 'absent',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'regular_day_minutes' => 0,
                'regular_night_minutes' => 0,
                'worked_minutes' => 0,
            ];
        }

        $snapshot = [
            'daily_rate' => 769.23,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 769.23,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 7692.30,
                        'minutes_worked' => 4800,
                        'hourly_rate' => 96.15375,
                    ],
                    [
                        'key' => 'holiday:2026-08-15:236:SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'label' => 'Special Holiday — Unworked Pay: KADAWAYAN EXCHANCE',
                        'amount' => 769.23,
                        'component_code' => 'SPECIAL_HOLIDAY_UNWORKED_PAY',
                        'metadata' => ['worked' => false],
                    ],
                ],
            ],
            'daily_computation_days' => $dailyDays,
        ];

        $normalized = app(\App\Services\PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $regularLine = collect($normalized['summary']['daily_computation_earning_lines'] ?? [])
            ->first(fn ($line) => is_array($line) && ($line['key'] ?? '') === 'daily:regular_pay');

        $this->assertEqualsWithDelta(8461.53, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(8461.53, (float) ($regularLine['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
    }

    public function test_bertoso_regular_pay_after_reductions_matches_headline_when_attendance_deduction_is_zero(): void
    {
        $snapshot = json_decode(file_get_contents(__DIR__.'/Fixtures/bertoso_15510_snapshot.json'), true);
        if (! is_array($snapshot)) {
            $this->markTestSkipped('Bertoso fixture snapshot unavailable.');
        }

        $normalized = app(\App\Services\PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $regularLine = collect($normalized['summary']['daily_computation_earning_lines'] ?? [])
            ->first(fn ($line) => is_array($line) && ($line['key'] ?? '') === 'daily:regular_pay');
        $absenceRow = collect($breakdown['rows'] ?? [])->firstWhere('key', 'absence');

        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_DRAFT,
            'gross_pay' => 4442.34,
            'total_deductions' => 416.67,
            'net_pay' => 4025.67,
            'snapshot' => $snapshot,
        ]);

        $service = app(PayrollReportService::class);
        $method = (new ReflectionClass($service))->getMethod('rowForPayslip');
        $method->setAccessible(true);
        $row = $method->invoke($service, $payslip);

        $this->assertEqualsWithDelta(5076.92, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(5076.92, (float) ($regularLine['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($absenceRow['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(5076.92, (float) $row['regular_basic_pay'], 0.02);
        $this->assertEqualsWithDelta(5076.92, (float) $row['gross_earnings'], 0.02);
        $this->assertEqualsWithDelta(416.67, (float) $row['total_deductions'], 0.02);
        $this->assertEqualsWithDelta(4660.25, (float) $row['net_pay'], 0.02);
    }
}
