<?php

namespace Tests\Unit;

use App\Models\Payslip;
use App\Services\PayslipService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PayslipFrozenLineMetricsTest extends TestCase
{
    public function test_frozen_metrics_preserve_required_payroll_line_categories(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 0,
            'total_deductions' => 0,
            'net_pay' => 0,
            'snapshot' => [
                'summary' => [
                    'daily_computation_earning_lines' => [
                        ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 1000],
                        ['key' => 'daily:holiday_premium', 'label' => 'Holiday Pay', 'amount' => 300],
                        ['key' => 'daily:ot_pay', 'label' => 'Overtime', 'amount' => 200],
                        ['key' => 'daily:nd_pay', 'label' => 'Night Differential', 'amount' => 100],
                        ['key' => 'daily:paid_leave', 'label' => 'Paid Leave', 'amount' => 400],
                    ],
                    'payslip_earning_lines' => [
                        ['key' => 'pay_component:7', 'label' => 'Meal Allowance', 'amount' => 150],
                    ],
                    'payslip_deduction_lines' => [
                        ['key' => 'SSS', 'label' => 'SSS', 'amount' => 50],
                    ],
                    'payslip_custom_deduction_lines' => [
                        ['key' => 'deduction:9', 'label' => 'Other Deduction', 'amount' => 75],
                    ],
                ],
            ],
        ]);

        $metrics = $service->frozenPayslipLineMetrics($payslip);

        $this->assertSame(8, $metrics['line_count']);
        $this->assertSame(2150.0, $metrics['gross_pay']);
        $this->assertSame(125.0, $metrics['total_deductions']);
        $this->assertSame(2025.0, $metrics['net_pay']);
        $this->assertSame(1000.0, $metrics['regular_pay']);
        $this->assertSame(300.0, $metrics['holiday_pay']);
        $this->assertSame(200.0, $metrics['overtime_pay']);
        $this->assertSame(100.0, $metrics['night_differential']);
        $this->assertSame(400.0, $metrics['paid_leave']);
        $this->assertSame(150.0, $metrics['allowances']);
        $this->assertSame(75.0, $metrics['other_deductions']);
        $this->assertContains('government_deduction', $metrics['categories']);
    }

    public function test_finalized_sync_does_not_rewrite_snapshot(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $snapshot = [
            'summary' => [
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:holiday_premium', 'label' => 'Holiday Pay', 'amount' => 300],
                ],
                'payslip_earning_lines' => [],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [],
            ],
        ];
        $payslip = new Payslip;
        $payslip->forceFill([
            'status' => Payslip::STATUS_FINALIZED,
            'gross_pay' => 999,
            'total_deductions' => 0,
            'net_pay' => 999,
            'snapshot' => $snapshot,
        ]);

        $totals = $service->syncPayslipSummaryFromLines($payslip);

        $this->assertSame(300.0, $totals['gross_pay']);
        $this->assertFalse($totals['changed']);
        $this->assertSame($snapshot, $payslip->snapshot);
        $this->assertSame('999.00', (string) $payslip->gross_pay);
    }

    public function test_execom_sanitize_keeps_overtime_and_holiday_when_allowed(): void
    {
        $service = $this->payslipServiceWithoutConstructor();
        $method = (new ReflectionClass(PayslipService::class))->getMethod('sanitizeExecomPayslipSummary');
        $method->setAccessible(true);

        $summary = [
            'execom_settings' => [
                'apply_custom_deductions' => false,
                'apply_allowances' => false,
                'allow_paid_leave' => true,
                'allow_overtime' => true,
                'allow_holiday_pay' => true,
            ],
            'basic_pay' => 25000.0,
            'basic_pay_this_period' => 25000.0,
            'paid_leave_amount' => 0.0,
            'leave_deduction' => 0.0,
            'overtime_total_amount' => 500.0,
            'payslip_earning_lines' => [
                [
                    'key' => 'execom_basic_pay',
                    'label' => 'Basic Pay',
                    'category' => 'basic_pay',
                    'component_code' => 'BASIC_SALARY',
                    'amount' => 25000.0,
                ],
                [
                    'key' => 'daily:ot_pay',
                    'label' => 'Overtime',
                    'component' => 'ot_pay',
                    'category' => 'overtime',
                    'amount' => 500.0,
                ],
                [
                    'key' => 'daily:holiday_premium',
                    'label' => 'Holiday premium',
                    'component' => 'holiday_premium',
                    'category' => 'holiday_pay',
                    'amount' => 1000.0,
                    'metadata' => ['scope_match' => true],
                ],
                [
                    'key' => 'holiday:out-of-scope:REGULAR_HOLIDAY_UNWORKED_PAY',
                    'label' => 'Regular Holiday — Unworked Pay: OUT',
                    'category' => 'holiday_pay',
                    'amount' => 769.23,
                    'metadata' => ['scope_match' => false],
                ],
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 999.0,
                ],
            ],
            'payslip_deduction_lines' => [],
            'payslip_custom_deduction_lines' => [],
        ];

        $out = $method->invoke($service, $summary, []);
        $labels = array_map(
            static fn (array $line): string => (string) ($line['label'] ?? ''),
            $out['payslip_earning_lines']
        );

        $this->assertContains('Basic Pay', $labels);
        $this->assertContains('Overtime', $labels);
        $this->assertContains('Holiday premium', $labels);
        $this->assertNotContains('Regular Holiday — Unworked Pay: OUT', $labels);
        $this->assertNotContains('Regular pay', $labels);
        $this->assertSame(500.0, $out['overtime_total_amount']);
        $this->assertSame(1500.0, $out['attendance_premium_pay_this_period']);
        $this->assertSame(26500.0, $out['total_pay']);
    }

    public function test_regular_pay_attendance_label_uses_present_day_count(): void
    {
        $service = app(PayslipService::class);
        $label = $service->regularPayAttendanceLabel([
            'daily_rate' => 525.0,
            'attendance_display_summary' => [
                'presence_days_count' => 13,
                'working_days_count' => 13,
                'lines' => [],
            ],
            'daily_computation_earning_lines' => [
                [
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'minutes_worked' => (12 * 8 * 60) + (6 * 60) + 30,
                    'amount' => 4921.88,
                ],
            ],
        ]);

        $this->assertSame('13 days', $label);
    }

    public function test_regular_pay_display_amount_keeps_present_day_gross_and_shows_payable_total(): void
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

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;
        $totalDeduction = (float) ($normalized['summary']['attendance_pay_breakdown']['total_deduction'] ?? 0);
        $netAmount = round((float) ($line['amount'] ?? 0), 2);

        $this->assertSame('13 days', $line['units'] ?? null);
        $this->assertEqualsWithDelta(7101.53, $netAmount, 0.02);
        $this->assertSame(7575.0, round((float) ($line['display_amount'] ?? 0), 2));
        $afterReductions = round((float) ($normalized['summary']['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0), 2);
        $this->assertSame($netAmount, $afterReductions);
        $this->assertSame($afterReductions, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame($afterReductions, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));
        $this->assertGreaterThan(0.0, $totalDeduction);
    }

    public function test_worked_holiday_is_not_counted_as_a_regular_pay_day(): void
    {
        $regularDay = static fn (string $date): array => [
            'date' => $date,
            'status' => 'worked',
            'is_rest_day' => false,
            'regular_day_minutes' => 480,
            'regular_night_minutes' => 0,
            'required_minutes' => 480,
            'breakdown' => [[
                'component' => 'regular_pay',
                'minutes' => 480,
                'rate' => 76.9225,
                'amount' => 615.38,
            ]],
        ];
        $snapshot = [
            'daily_rate' => 615.38,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 615.38,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 16000,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 1846.14,
                    ],
                    [
                        'key' => 'daily:holiday_premium',
                        'label' => 'Special Holiday - Worked Pay: KADAWAYAN',
                        'amount' => 799.99,
                    ],
                ],
            ],
            'daily_computation_days' => [
                $regularDay('2026-08-12'),
                $regularDay('2026-08-13'),
                $regularDay('2026-08-14'),
                [
                    'date' => '2026-08-15',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'holiday_premium_pay' => 799.99,
                    'breakdown' => [[
                        'component' => 'holiday_premium',
                        'minutes' => 480,
                        'rate' => 99.99875,
                        'amount' => 799.99,
                    ]],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rowsByKey = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame('3 days', $line['units'] ?? null);
        $this->assertEqualsWithDelta(1846.14, (float) ($line['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1846.14, (float) ($line['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($breakdown['total_deduction'] ?? 0), 0.001);
        $this->assertFalse($rowsByKey->has('attendance_adjustment'));

        $frozen = app(PayslipService::class)->frozenSnapshotForPayslipView($snapshot);
        $frozenLine = $frozen['summary']['daily_computation_earning_lines'][0] ?? null;
        $frozenRowsByKey = collect($frozen['summary']['attendance_pay_breakdown']['rows'] ?? [])->keyBy('key');

        $this->assertSame('3 days', $frozenLine['units'] ?? null);
        $this->assertEqualsWithDelta(1846.14, (float) ($frozenLine['display_amount'] ?? 0), 0.02);
        $this->assertFalse($frozenRowsByKey->has('attendance_adjustment'));
    }

    public function test_worked_regular_holiday_keeps_its_regular_pay_day(): void
    {
        $regularDay = static fn (string $date): array => [
            'date' => $date,
            'status' => 'worked',
            'is_rest_day' => false,
            'regular_day_minutes' => 480,
            'regular_night_minutes' => 0,
            'required_minutes' => 480,
            'breakdown' => [[
                'component' => 'regular_pay',
                'minutes' => 480,
                'rate' => 76.9225,
                'amount' => 615.38,
            ]],
        ];
        $snapshot = [
            'daily_rate' => 615.38,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 615.38,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 16000,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'amount' => 2461.52,
                    ],
                    [
                        'key' => 'daily:holiday_premium',
                        'label' => 'Regular Holiday - Worked Pay: REGULAR HOLIDAY',
                        'amount' => 615.38,
                    ],
                ],
            ],
            'daily_computation_days' => [
                $regularDay('2026-08-10'),
                [
                    'date' => '2026-08-11',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'holiday_premium_pay' => 615.38,
                    'holiday' => ['name' => 'REGULAR HOLIDAY', 'type' => 'regular'],
                    'breakdown' => [
                        [
                            'component' => 'regular_pay',
                            'minutes' => 480,
                            'rate' => 76.9225,
                            'amount' => 615.38,
                        ],
                        [
                            'component' => 'holiday_premium',
                            'minutes' => 480,
                            'rate' => 76.9225,
                            'amount' => 615.38,
                        ],
                    ],
                ],
                $regularDay('2026-08-12'),
                $regularDay('2026-08-13'),
                [
                    'date' => '2026-08-14',
                    'status' => 'absent',
                    'is_rest_day' => false,
                    'required_minutes' => 480,
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rowsByKey = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame('4 days', $line['units'] ?? null);
        $this->assertEqualsWithDelta(2461.52, (float) ($line['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2461.52, (float) ($line['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(615.38, (float) ($normalized['summary']['daily_computation_earning_lines'][1]['amount'] ?? 0), 0.02);
        $this->assertFalse($rowsByKey->has('attendance_adjustment'));

        $frozen = app(PayslipService::class)->frozenSnapshotForPayslipView($snapshot);
        $frozenLine = $frozen['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame('4 days', $frozenLine['units'] ?? null);
        $this->assertEqualsWithDelta(2461.52, (float) ($frozenLine['display_amount'] ?? 0), 0.02);
    }

    public function test_late_breakdown_uses_policy_buckets_for_worked_regular_holidays(): void
    {
        $hourlyRate = 69.1825;
        $lateDay = static function (string $date, int $ledgerLateMinutes, bool $regularHoliday = false) use ($hourlyRate): array {
            $dayBreakdown = [[
                'component' => 'regular_pay',
                'minutes' => 450,
                'rate' => $hourlyRate,
                'amount' => 518.87,
            ]];
            if ($regularHoliday) {
                $dayBreakdown[] = [
                    'component' => 'holiday_premium',
                    'minutes' => 450,
                    'rate' => $hourlyRate,
                    'amount' => 518.87,
                ];
            }

            return [
                'date' => $date,
                'status' => 'worked',
                'is_rest_day' => false,
                'required_minutes' => 480,
                'regular_day_minutes' => 450,
                'regular_night_minutes' => 0,
                'regular_pay' => 518.87,
                'holiday_premium_pay' => $regularHoliday ? 518.87 : 0,
                'late_deduction_minutes' => $ledgerLateMinutes,
                'undertime_deduction_minutes' => 0,
                'tardiness_status' => 'late',
                'tardiness_label' => '30 Minutes late',
                'breakdown' => $dayBreakdown,
            ];
        };

        $snapshot = [
            'daily_rate' => 553.46,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 553.46,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 14390,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 2628.94,
                ]],
            ],
            'daily_computation_days' => [
                $lateDay('2026-08-11', 20, true),
                $lateDay('2026-08-12', 23),
                $lateDay('2026-08-13', 21),
                [
                    'date' => '2026-08-14',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'required_minutes' => 480,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'regular_pay' => 553.46,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => $hourlyRate,
                        'amount' => 553.46,
                    ]],
                ],
                $lateDay('2026-08-17', 24),
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame(120, (int) ($rows['late']['minutes'] ?? 0));
        $this->assertSame('2 hrs', $rows['late']['details'] ?? null);
        $this->assertEqualsWithDelta(138.37, (float) ($rows['late']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(138.37, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2628.94, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
        $this->assertFalse($rows->has('attendance_adjustment'));
    }

    public function test_regular_pay_undertime_keeps_full_present_days_and_breakdown_uses_payable_total(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 692.31,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 18000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'units' => '4 days',
                    'amount' => 2769.23,
                    'display_amount' => 2769.23,
                ]],
            ],
            'daily_computation_days' => array_merge([
                [
                    'date' => '2026-08-10',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'undertime_deduction_minutes' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => 86.53875,
                        'amount' => 692.31,
                    ]],
                ],
            ], array_map(
                static fn (string $date): array => [
                    'date' => $date,
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 240,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'undertime_deduction_minutes' => 240,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 240,
                        'rate' => 86.53875,
                        'amount' => 346.16,
                    ]],
                ],
                ['2026-08-12', '2026-08-17', '2026-08-20']
            )),
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame('4 days', $line['units'] ?? null);
        $this->assertSame(1200, (int) ($line['minutes_worked'] ?? 0));
        $this->assertEqualsWithDelta(1730.78, (float) ($line['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2769.23, (float) ($line['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(
            1730.78,
            (float) ($normalized['summary']['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0),
            0.02
        );
        $this->assertEqualsWithDelta(
            1730.78,
            (float) ($normalized['summary']['display_gross_pay'] ?? 0),
            0.02
        );
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');
        $this->assertEqualsWithDelta(1038.45, (float) ($rows['undertime']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1038.45, (float) ($breakdown['total_deduction'] ?? 0), 0.02);

        $frozen = app(PayslipService::class)->frozenSnapshotForPayslipView($snapshot);
        $frozenLine = $frozen['summary']['daily_computation_earning_lines'][0] ?? null;
        $this->assertSame('4 days', $frozenLine['units'] ?? null);
        $this->assertEqualsWithDelta(1730.78, (float) ($frozenLine['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(2769.23, (float) ($frozenLine['display_amount'] ?? 0), 0.02);
        $frozenBreakdown = $frozen['summary']['attendance_pay_breakdown'] ?? [];
        $this->assertEqualsWithDelta(1038.45, (float) ($frozenBreakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(
            1730.78,
            (float) ($frozen['summary']['display_gross_pay'] ?? 0),
            0.02
        );
    }

    public function test_regular_pay_half_day_is_displayed_as_fractional_day(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'summary' => [
                'daily_rate' => 692.31,
                'monthly_basic_salary' => 18000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 346.16,
                ]],
            ],
            'daily_computation_days' => [[
                'date' => '2026-08-13',
                'status' => 'worked',
                'tardiness_status' => 'half_day',
                'is_rest_day' => false,
                'regular_day_minutes' => 240,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'undertime_deduction_minutes' => 240,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 240,
                    'rate' => 86.53875,
                    'amount' => 346.16,
                ]],
            ]],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame('0.5 day', $line['units'] ?? null);
        $this->assertEqualsWithDelta(346.16, (float) ($line['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(346.16, (float) ($line['display_amount'] ?? 0), 0.02);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $this->assertEqualsWithDelta(0.0, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
    }

    public function test_half_day_breakdown_only_deducts_time_below_included_half_day(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'summary' => [
                'daily_rate' => 692.31,
                'monthly_basic_salary' => 18000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 1012.50,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-08-10',
                    'status' => 'worked',
                    'tardiness_status' => 'on_time',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => 86.53875,
                        'amount' => 692.31,
                    ]],
                ],
                [
                    'date' => '2026-08-11',
                    'status' => 'worked',
                    'tardiness_status' => 'half_day',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 222,
                    'regular_night_minutes' => 0,
                    'paid_regular_minutes' => 222,
                    'required_minutes' => 480,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 222,
                        'rate' => 86.53875,
                        'amount' => 320.19,
                    ]],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame('1.5 days', $normalized['summary']['daily_computation_earning_lines'][0]['units'] ?? null);
        $this->assertSame(18, (int) ($rows['half_day']['deduction_minutes'] ?? 0));
        $this->assertEqualsWithDelta(25.96, (float) ($rows['half_day']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(25.96, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1012.50, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
    }

    public function test_severe_undertime_does_not_create_a_half_day_or_late_deduction(): void
    {
        $hourlyRate = 769.23 / 8;
        $snapshot = [
            'daily_rate' => 769.23,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 769.23,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 20000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 991.99,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-08-11',
                    'status' => 'worked',
                    'tardiness_status' => 'late',
                    'tardiness_label' => '30 Minutes late',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 10,
                    'regular_night_minutes' => 0,
                    'paid_regular_minutes' => 10,
                    'required_minutes' => 480,
                    'late_deduction_minutes' => 0,
                    'undertime_deduction_minutes' => 470,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 10,
                        'rate' => $hourlyRate,
                        'amount' => 16.03,
                    ]],
                ],
                [
                    'date' => '2026-08-12',
                    'status' => 'worked',
                    'tardiness_status' => 'on_time',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'paid_regular_minutes' => 480,
                    'required_minutes' => 480,
                    'late_deduction_minutes' => 0,
                    'undertime_deduction_minutes' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => $hourlyRate,
                        'amount' => 769.23,
                    ]],
                ],
                [
                    'date' => '2026-08-13',
                    'status' => 'worked',
                    'tardiness_status' => 'half_day',
                    'tardiness_label' => 'Half Day',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 129,
                    'regular_night_minutes' => 0,
                    'paid_regular_minutes' => 129,
                    'required_minutes' => 480,
                    'late_deduction_minutes' => 0,
                    'undertime_deduction_minutes' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 129,
                        'rate' => $hourlyRate,
                        'amount' => 206.73,
                    ]],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame(0, (int) ($rows['late']['minutes'] ?? -1));
        $this->assertSame(470, (int) ($rows['undertime']['minutes'] ?? 0));
        $this->assertSame(111, (int) ($rows['half_day']['minutes'] ?? 0));
        $this->assertSame('1 hr 51 mins', $rows['half_day']['details'] ?? null);
        $this->assertSame(111, (int) ($rows['half_day']['deduction_minutes'] ?? 0));
        $this->assertEqualsWithDelta(177.88, (float) ($rows['half_day']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(931.09, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(991.99, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);
    }

    public function test_regular_pay_late_day_keeps_one_day_display_units(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'summary' => [
                'daily_rate' => 692.31,
                'monthly_basic_salary' => 18000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 1038.47,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-08-10',
                    'status' => 'worked',
                    'tardiness_status' => 'late',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 240,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'late_deduction_minutes' => 240,
                    'undertime_deduction_minutes' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 240,
                        'rate' => 86.53875,
                        'amount' => 346.16,
                    ]],
                ],
                [
                    'date' => '2026-08-11',
                    'status' => 'worked',
                    'tardiness_status' => 'on_time',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'late_deduction_minutes' => 0,
                    'undertime_deduction_minutes' => 0,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => 86.53875,
                        'amount' => 692.31,
                    ]],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertSame('2 days', $line['units'] ?? null);
        $this->assertEqualsWithDelta(1038.47, (float) ($line['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1384.62, (float) ($line['display_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(346.15, (float) ($rows['late']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(346.15, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
    }

    public function test_attendance_breakdown_displays_only_payable_reductions(): void
    {
        $regularRate = 692.31 / 8;
        $halfDay = static function (string $date, bool $hasPaidLeave) use ($regularRate): array {
            $breakdown = [];
            if ($hasPaidLeave || $date === '2026-08-12') {
                $breakdown[] = [
                    'component' => 'regular_pay',
                    'minutes' => 240,
                    'rate' => $regularRate,
                    'amount' => 346.16,
                ];
            }
            if ($hasPaidLeave) {
                $breakdown[] = [
                    'component' => 'paid_leave',
                    'minutes' => 240,
                    'rate' => $regularRate,
                    'amount' => 346.16,
                ];
            }

            return [
                'date' => $date,
                'status' => $hasPaidLeave ? 'halfday' : 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => $hasPaidLeave ? 480 : 240,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'tardiness_status' => $date === '2026-08-12' || $hasPaidLeave ? 'half_day' : null,
                'tardiness_label' => $date === '2026-08-12' || $hasPaidLeave ? 'Half Day' : null,
                'breakdown' => $breakdown,
            ];
        };

        $snapshot = [
            'daily_rate' => 692.31,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 692.31,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 18000,
                'daily_computation_earning_lines' => [
                    [
                        'key' => 'daily:regular_pay',
                        'label' => 'Regular pay',
                        'units' => '4 days',
                        'amount' => 2769.23,
                        'display_amount' => 2769.23,
                    ],
                    [
                        'key' => 'daily:paid_leave',
                        'label' => 'Leave adjustments',
                        'units' => '1.5 days',
                        'amount' => 1038.48,
                    ],
                ],
                // Simulate the old frozen display fields. The view normalizer must rebuild
                // attendance detail from the immutable daily rows.
                'attendance_pay_breakdown' => [
                    'available' => true,
                    'total_deduction' => 346.16,
                    'rows' => [],
                ],
            ],
            'daily_computation_days' => array_merge(
                [[
                    'date' => '2026-08-11',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'regular_day_minutes' => 480,
                    'regular_night_minutes' => 0,
                    'required_minutes' => 480,
                    'breakdown' => [[
                        'component' => 'regular_pay',
                        'minutes' => 480,
                        'rate' => $regularRate,
                        'amount' => 692.31,
                    ]],
                ]],
                [
                    $halfDay('2026-08-12', false),
                    $halfDay('2026-08-17', true),
                    $halfDay('2026-08-20', true),
                    [
                        'date' => '2026-08-18',
                        'status' => 'halfday',
                        'is_rest_day' => false,
                        'regular_day_minutes' => 240,
                        'regular_night_minutes' => 0,
                        'required_minutes' => 480,
                        'breakdown' => [[
                            'component' => 'paid_leave',
                            'minutes' => 240,
                            'rate' => $regularRate,
                            'amount' => 346.16,
                        ]],
                    ],
                ],
                array_map(
                    static fn (int $day): array => [
                        'date' => sprintf('2026-08-%02d', $day),
                        'status' => 'absent',
                        'is_rest_day' => false,
                        'required_minutes' => 480,
                    ],
                    [13, 14, 15, 19, 21, 22, 24, 25]
                )
            ),
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $rows = collect($breakdown['rows'] ?? [])->keyBy('key');

        $this->assertEqualsWithDelta(1384.62, (float) ($rows['half_day']['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($rows['half_day']['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(5538.48, (float) ($rows['absence']['amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($rows['absence']['deduction_amount'] ?? -1), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($breakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1730.78, (float) ($breakdown['regular_pay_after_reductions'] ?? 0), 0.02);

        $frozen = app(PayslipService::class)->frozenSnapshotForPayslipView($snapshot);
        $frozenBreakdown = $frozen['summary']['attendance_pay_breakdown'] ?? [];
        $this->assertEqualsWithDelta(0.0, (float) ($frozenBreakdown['total_deduction'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(0.0, (float) ($frozenBreakdown['rows'][2]['deduction_amount'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(1730.78, (float) ($frozenBreakdown['regular_pay_after_reductions'] ?? 0), 0.02);
    }

    public function test_payslip_totals_snap_daily_rate_rounding_to_semi_monthly(): void
    {
        $snapshot = [
            'daily_rate' => 692.31,
            'daily_rate_divisor_days' => 26,
            'summary' => [
                'daily_rate' => 692.31,
                'daily_rate_divisor_days' => 26,
                'monthly_basic_salary' => 18000,
                'semi_monthly_basic_salary' => 9000,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 9000.03,
                    'minutes_worked' => 13 * 8 * 60,
                    'hourly_rate' => 692.31 / 8,
                ]],
            ],
            'daily_computation_days' => array_fill(0, 13, [
                'date' => '2026-07-26',
                'status' => 'worked',
                'is_rest_day' => false,
                'regular_day_minutes' => 480,
                'regular_night_minutes' => 0,
                'required_minutes' => 480,
                'breakdown' => [[
                    'component' => 'regular_pay',
                    'minutes' => 480,
                    'rate' => 692.31 / 8,
                    'amount' => 692.31,
                ]],
            ]),
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $line = $normalized['summary']['daily_computation_earning_lines'][0] ?? null;

        $this->assertSame(9000.0, round((float) ($line['display_amount'] ?? 0), 2));
        $this->assertSame(9000.0, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame(9000.0, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));

        $displayTotals = app(PayslipService::class)->payslipDisplayTotalsFromSnapshot($snapshot);
        $this->assertSame(9000.0, $displayTotals['gross_pay']);
        $this->assertSame(9000.0, $displayTotals['net_pay']);
    }

    public function test_consultant_payslip_does_not_double_count_basic_pay_in_gross(): void
    {
        $snapshot = [
            'summary' => [
                'consultant_fixed_payroll' => true,
                'employment_status' => 'consultant',
                'basic_pay_this_period' => 23158,
                'payslip_earning_lines' => [
                    ['key' => 'consultant_basic_pay', 'label' => 'Basic Pay', 'amount' => 23158],
                    ['key' => 'daily:regular_pay', 'label' => 'Basic Pay', 'amount' => 23158],
                    ['key' => 'pay_component:allowance', 'label' => 'ALLOWANCE', 'component_code' => 'ALLOWANCE', 'amount' => 10000],
                ],
                'daily_computation_earning_lines' => [
                    ['key' => 'daily:regular_pay', 'label' => 'Regular pay', 'amount' => 23158],
                ],
                'payslip_deduction_lines' => [],
                'payslip_custom_deduction_lines' => [
                    ['key' => 'lending', 'label' => 'LENDING SALARY DEDUCTION EVERY 15 AND 30', 'amount' => 15900],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);

        $this->assertSame(33158.0, round((float) ($normalized['summary']['display_gross_pay'] ?? 0), 2));
        $this->assertSame(17258.0, round((float) ($normalized['summary']['display_net_pay'] ?? 0), 2));
    }

    public function test_payslip_absences_units_use_scheduled_workdays(): void
    {
        $snapshot = [
            'daily_rate' => 500,
            'summary' => [
                'daily_rate' => 500,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 1000,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-08-10',
                    'status' => 'absent',
                    'is_rest_day' => false,
                    'required_minutes' => 540,
                ],
                [
                    'date' => '2026-08-11',
                    'status' => 'absent',
                    'is_rest_day' => true,
                    'required_minutes' => 0,
                ],
                [
                    'date' => '2026-08-12',
                    'status' => 'absent',
                    'is_rest_day' => false,
                    'required_minutes' => 480,
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $absenceRow = null;
        foreach ($normalized['summary']['attendance_pay_breakdown']['rows'] ?? [] as $row) {
            if (is_array($row) && ($row['key'] ?? '') === 'absence') {
                $absenceRow = $row;
                break;
            }
        }

        $this->assertSame('2 days', $absenceRow['details'] ?? null);
        $this->assertSame(2.0, (float) ($absenceRow['count'] ?? 0));
    }

    public function test_payslip_scheduled_days_include_unworked_special_holiday(): void
    {
        $snapshot = [
            'daily_rate' => 500,
            'summary' => [
                'daily_rate' => 500,
                'daily_computation_earning_lines' => [[
                    'key' => 'daily:regular_pay',
                    'label' => 'Regular pay',
                    'amount' => 1000,
                ]],
            ],
            'daily_computation_days' => [
                [
                    'date' => '2026-08-10',
                    'status' => 'worked',
                    'is_rest_day' => false,
                    'required_minutes' => 480,
                    'holiday_premium_pay' => 0,
                ],
                [
                    'date' => '2026-08-11',
                    'status' => 'holiday',
                    'is_rest_day' => false,
                    'required_minutes' => 480,
                    'holiday_premium_pay' => 500,
                    'holiday' => ['name' => 'Kadayawan Festival', 'type' => 'special'],
                ],
            ],
        ];

        $normalized = app(PayslipService::class)->normalizeSnapshotForPayslipView($snapshot);
        $breakdown = $normalized['summary']['attendance_pay_breakdown'] ?? [];
        $absenceRow = null;
        foreach ($breakdown['rows'] ?? [] as $row) {
            if (is_array($row) && ($row['key'] ?? '') === 'absence') {
                $absenceRow = $row;
                break;
            }
        }

        $this->assertSame(2, (int) ($breakdown['scheduled_days_count'] ?? 0));
        $this->assertSame(0.0, (float) ($absenceRow['count'] ?? -1));
    }

    private function payslipServiceWithoutConstructor(): PayslipService
    {
        return (new ReflectionClass(PayslipService::class))->newInstanceWithoutConstructor();
    }
}
