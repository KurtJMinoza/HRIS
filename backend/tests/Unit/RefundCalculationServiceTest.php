<?php

namespace Tests\Unit;

use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\Payslip;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RefundCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_preview_rejects_explicit_cycle_that_is_already_finalized(): void
    {
        $employee = User::factory()->create();
        $this->freezeWindow($employee, '2026-09-11', '2026-09-25');

        $service = app(RefundCalculationService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been finalized and is locked (2026-09-11 → 2026-09-25)');

        $service->preview($employee, [
            'employee_id' => $employee->id,
            'reason' => RefundRequest::REASON_OTHER,
            'affected_date' => '2026-09-15',
            'cutoff_start_date' => '2026-09-11',
            'cutoff_end_date' => '2026-09-25',
            'direct_refund_amount' => 500,
        ]);
    }

    public function test_preview_allows_open_cycle_and_marks_selected_cycle_timing(): void
    {
        $employee = User::factory()->create();

        $service = app(RefundCalculationService::class);
        $preview = $service->preview($employee, [
            'employee_id' => $employee->id,
            'reason' => RefundRequest::REASON_OTHER,
            'affected_date' => '2026-10-01',
            'cutoff_start_date' => '2026-09-26',
            'cutoff_end_date' => '2026-10-10',
            'direct_refund_amount' => 500,
        ]);

        $this->assertFalse($preview['finalized']);
        $this->assertSame('selected_payroll_cycle', $preview['application_timing']);
        $this->assertSame(500.0, $preview['refund_amount']);
    }

    private function freezeWindow(User $employee, string $start, string $end): void
    {
        $batch = PayrollBatchRun::query()->create([
            'payroll_module' => PayrollBatchRun::MODULE_STANDARD,
            'batch_key' => 'test-freeze-'.md5($employee->id.$start.$end),
            'pay_period_start' => $start,
            'pay_period_end' => $end,
            'status' => PayrollBatchRun::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);

        $payslip = Payslip::query()->create([
            'user_id' => $employee->id,
            'pay_period_start' => $start,
            'pay_period_end' => $end,
        ]);

        PayrollEmployee::query()->create([
            'payroll_batch_run_id' => $batch->id,
            'payslip_id' => $payslip->id,
            'user_id' => $employee->id,
            'pay_period_start' => $start,
            'pay_period_end' => $end,
            'status' => PayrollEmployee::STATUS_FINALIZED,
        ]);
    }
}
