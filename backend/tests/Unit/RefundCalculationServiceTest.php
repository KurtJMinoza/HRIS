<?php

namespace Tests\Unit;

use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCalculationService;
use Carbon\Carbon;
use Tests\TestCase;

class RefundCalculationServiceTest extends TestCase
{
    public function test_preview_shape_keeps_component_keys(): void
    {
        $service = app(RefundCalculationService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('sumBreakdownComponent');
        $method->setAccessible(true);

        $total = $method->invoke($service, [
            ['component' => 'paid_leave', 'amount' => 300],
            ['component' => 'paid_leave_daily_flat', 'amount' => 50],
            ['component' => 'regular_pay', 'amount' => 999],
        ], ['paid_leave', 'paid_leave_daily_flat']);

        $this->assertSame(350.0, $total);
    }

    public function test_direct_amount_preview_marks_negative_amount_as_payroll_recovery(): void
    {
        $service = app(RefundCalculationService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('previewDirectAmount');
        $method->setAccessible(true);

        $preview = $method->invoke(
            $service,
            new User(['id' => 1]),
            RefundRequest::REASON_OTHER,
            Carbon::parse('2026-08-16'),
            Carbon::parse('2026-08-31'),
            Carbon::parse('2026-08-16'),
            Carbon::parse('2026-08-31'),
            -1250.50
        );

        $this->assertSame(RefundRequest::DIRECTION_OVERPAYMENT, $preview['direction']);
        $this->assertSame(1250.50, $preview['refund_amount']);
        $this->assertSame(-1250.50, $preview['refund_signed_amount']);
        $this->assertSame('selected_payroll_cycle', $preview['application_timing']);
        $this->assertSame(-1250.50, $preview['components']['regular_pay']['difference']);
    }
}
