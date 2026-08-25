<?php

namespace Tests\Unit;

use App\Models\RefundRequest;
use App\Services\RefundPayrollApplicationService;
use Tests\TestCase;

class RefundPayrollApplicationServiceTest extends TestCase
{
    public function test_component_lines_preserve_associative_keys_and_emit_earning_lines(): void
    {
        $refund = new RefundRequest([
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'reason' => RefundRequest::REASON_MISSING_OVERTIME,
            'refund_amount' => 750,
            'calculation' => [
                'components' => [
                    'regular_pay' => ['label' => 'Basic Pay', 'paid' => 500, 'expected' => 500, 'difference' => 0],
                    'ot_pay' => ['label' => 'Overtime', 'paid' => 0, 'expected' => 750, 'difference' => 750],
                ],
            ],
        ]);

        $lines = app(RefundPayrollApplicationService::class)->componentLines($refund);

        $this->assertCount(1, $lines);
        $this->assertSame('refund_overtime', $lines[0]['component_code']);
        $this->assertSame('earning', $lines[0]['line_type']);
        $this->assertSame('Missing Overtime', $lines[0]['label']);
        $this->assertSame(750.0, $lines[0]['amount']);
    }

    public function test_overpayment_negative_difference_emits_deduction_recovery_line(): void
    {
        $refund = new RefundRequest([
            'direction' => RefundRequest::DIRECTION_OVERPAYMENT,
            'reason' => RefundRequest::REASON_PAYROLL_COMPUTATION_ERROR,
            'refund_amount' => 200,
            'calculation' => [
                'components' => [
                    'regular_pay' => ['label' => 'Basic Pay', 'paid' => 1200, 'expected' => 1000, 'difference' => -200],
                ],
            ],
        ]);

        $lines = app(RefundPayrollApplicationService::class)->componentLines($refund);

        $this->assertCount(1, $lines);
        $this->assertSame('deduction', $lines[0]['line_type']);
        $this->assertSame(200.0, $lines[0]['amount']);
        $this->assertSame('Payroll Recovery — Payroll Computation Error', $lines[0]['label']);
    }

    public function test_manual_amount_line_uses_refund_reason_as_payslip_label(): void
    {
        $refund = new RefundRequest([
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'reason' => RefundRequest::REASON_MISSING_ATTENDANCE,
            'refund_amount' => 5000,
            'calculation' => [
                'components' => [
                    'regular_pay' => ['label' => 'Basic Pay', 'paid' => 0, 'expected' => 5000, 'difference' => 5000],
                ],
            ],
        ]);

        $lines = app(RefundPayrollApplicationService::class)->componentLines($refund);

        $this->assertCount(1, $lines);
        $this->assertSame('earning', $lines[0]['line_type']);
        $this->assertSame('Missing Attendance', $lines[0]['label']);
        $this->assertSame(5000.0, $lines[0]['amount']);
    }

    public function test_finalized_refund_applies_on_next_payroll_after_cutoff(): void
    {
        $service = app(RefundPayrollApplicationService::class);
        $refund = new RefundRequest([
            'affected_date' => '2026-01-10',
            'cutoff_end_date' => '2026-01-15',
            'original_payroll_batch_run_id' => 42,
            'calculation' => ['finalized' => true],
        ]);

        $this->assertFalse($service->isEligibleForPayWindow($refund, '2026-01-01', '2026-01-15'));
        $this->assertTrue($service->isEligibleForPayWindow($refund, '2026-01-16', '2026-01-31'));
    }

    public function test_open_period_refund_applies_when_affected_dates_overlap_window(): void
    {
        $service = app(RefundPayrollApplicationService::class);
        $refund = new RefundRequest([
            'affected_date' => '2026-01-10',
            'affected_date_to' => '2026-01-12',
            'cutoff_end_date' => '2026-01-15',
            'calculation' => ['finalized' => false],
        ]);

        $this->assertTrue($service->isEligibleForPayWindow($refund, '2026-01-01', '2026-01-15'));
        $this->assertFalse($service->isEligibleForPayWindow($refund, '2026-01-16', '2026-01-31'));
    }

    public function test_finalized_flag_in_calculation_triggers_next_payroll_rule(): void
    {
        $service = app(RefundPayrollApplicationService::class);
        $refund = new RefundRequest([
            'affected_date' => '2026-01-10',
            'cutoff_end_date' => '2026-01-15',
            'calculation' => ['finalized' => true],
        ]);

        $this->assertFalse($service->isEligibleForPayWindow($refund, '2026-01-01', '2026-01-15'));
        $this->assertTrue($service->isEligibleForPayWindow($refund, '2026-01-16', '2026-01-31'));
    }
}
