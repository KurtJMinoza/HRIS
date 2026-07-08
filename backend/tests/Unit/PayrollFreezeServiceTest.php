<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\PayrollEmployee;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollFreezeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollFreezeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_execom_finalized_run_freezes_only_included_employee_and_covered_dates(): void
    {
        [$employee, $run] = $this->finalizedPayroll(PayrollBatchRun::MODULE_EXECOM);
        $other = User::factory()->create();
        $service = app(PayrollFreezeService::class);

        $inside = $service->isDateFrozenForEmployee((int) $employee->id, '2026-06-10');

        $this->assertTrue($inside['frozen']);
        $this->assertSame('execom', $inside['payroll_type']);
        $this->assertSame((int) $run->id, $inside['payroll_run_id']);
        $this->assertSame('2026-06-01', $inside['period_start']);
        $this->assertSame('2026-06-15', $inside['period_end']);
        $this->assertFalse($service->isDateFrozenForEmployee((int) $employee->id, '2026-06-16')['frozen']);
        $this->assertFalse($service->isDateFrozenForEmployee((int) $other->id, '2026-06-10')['frozen']);
    }

    public function test_range_reports_each_frozen_date_and_regular_uses_same_service(): void
    {
        [$employee] = $this->finalizedPayroll(PayrollBatchRun::MODULE_STANDARD);
        $service = app(PayrollFreezeService::class);

        $this->assertSame(
            ['2026-06-14', '2026-06-15'],
            $service->frozenDatesForEmployee((int) $employee->id, '2026-06-14', '2026-06-16'),
        );
        $this->assertSame('regular', $service->isDateFrozenForEmployee((int) $employee->id, '2026-06-14')['payroll_type']);
    }

    public function test_draft_or_voided_run_does_not_freeze_employee(): void
    {
        [$employee, $run] = $this->finalizedPayroll(PayrollBatchRun::MODULE_EXECOM);
        $run->update(['status' => PayrollBatchRun::STATUS_VOIDED, 'voided_at' => now()]);

        $this->assertFalse(app(PayrollFreezeService::class)->isDateFrozenForEmployee((int) $employee->id, '2026-06-10')['frozen']);
    }

    /** @return array{User, PayrollBatchRun} */
    private function finalizedPayroll(string $module): array
    {
        $company = Company::query()->create(['name' => 'Freeze Test Co '.uniqid()]);
        $employee = User::factory()->create(['company_id' => $company->id]);
        $run = PayrollBatchRun::query()->create([
            'payroll_module' => $module,
            'batch_key' => uniqid($module.'-', true),
            'company_id' => $company->id,
            'pay_period_start' => '2026-06-01',
            'pay_period_end' => '2026-06-15',
            'status' => PayrollBatchRun::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);
        $payslip = Payslip::query()->create([
            'payroll_module' => $module,
            'user_id' => $employee->id,
            'company_id' => $company->id,
            'payroll_batch_run_id' => $run->id,
            'pay_period_start' => '2026-06-01',
            'pay_period_end' => '2026-06-15',
            'period_slot' => 0,
            'gross_pay' => 1000,
            'total_deductions' => 0,
            'net_pay' => 1000,
            'status' => Payslip::STATUS_FINALIZED,
            'finalized_at' => now(),
        ]);
        PayrollEmployee::query()->create([
            'payroll_module' => $module,
            'payslip_id' => $payslip->id,
            'payroll_batch_run_id' => $run->id,
            'user_id' => $employee->id,
            'company_id' => $company->id,
            'pay_period_start' => '2026-06-01',
            'pay_period_end' => '2026-06-15',
            'status' => PayrollEmployee::STATUS_FINALIZED,
            'gross_pay' => 1000,
            'total_deductions' => 0,
            'net_pay' => 1000,
        ]);

        return [$employee, $run];
    }
}
