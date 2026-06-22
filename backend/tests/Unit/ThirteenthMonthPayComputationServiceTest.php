<?php

namespace Tests\Unit;

use App\Models\ThirteenthMonthSetting;
use App\Models\User;
use App\Services\ThirteenthMonthPayComputationService;
use Carbon\Carbon;
use Tests\TestCase;

class ThirteenthMonthPayComputationServiceTest extends TestCase
{
    private function serviceMock(array $methods): ThirteenthMonthPayComputationService
    {
        return $this->getMockBuilder(ThirteenthMonthPayComputationService::class)
            ->onlyMethods($methods)->getMock();
    }

    private function setting(string $basis = 'basic'): ThirteenthMonthSetting
    {
        return (new ThirteenthMonthSetting)->forceFill([
            'company_scope_type' => 'all', 'basis_type' => $basis, 'coverage_type' => 'calendar_year',
            'coverage_start_month' => 1, 'coverage_start_year' => 2026,
            'coverage_end_month' => 12, 'coverage_end_year' => 2026, 'is_active' => true,
        ]);
    }

    public function test_basic_basis_is_finalized_basic_pay_divided_by_twelve(): void
    {
        $service = $this->serviceMock(['basicPay']);
        $service->method('basicPay')->willReturn(240000.0);
        $employee = (new User)->forceFill(['id' => 1]);
        $this->assertSame(20000.0, $service->computedAmount($employee, 1, $this->setting('basic')));
    }

    public function test_gross_basis_is_finalized_gross_pay_divided_by_twelve(): void
    {
        $service = $this->serviceMock(['grossPay']);
        $service->method('grossPay')->willReturn(120000.0);
        $employee = (new User)->forceFill(['id' => 2]);
        $this->assertSame(10000.0, $service->computedAmount($employee, 1, $this->setting('gross')));
    }

    public function test_acceptance_total_is_sum_of_all_cutoffs_divided_once_by_twelve(): void
    {
        $service = $this->serviceMock(['basicPay']);
        $service->method('basicPay')->willReturn((float) array_sum([6299, 7444, 6872, 4008]));
        $employee = (new User)->forceFill(['id' => 1681]);

        $this->assertSame(2051.92, $service->computedAmount($employee, 1, $this->setting('basic')));
    }

    public function test_apply_adds_one_auditable_earning_line(): void
    {
        $service = $this->serviceMock(['activeSettingForCompany', 'computedAmount']);
        $service->method('activeSettingForCompany')->willReturn($this->setting());
        $service->method('computedAmount')->willReturn(20000.0);
        $employee = (new User)->forceFill(['id' => 3]);
        $snapshot = ['summary' => ['payslip_earning_lines' => [
            ['component_code' => '13TH_MONTH_PAY', 'amount' => 1],
        ]]];
        $result = $service->applyToPayslipSnapshot($snapshot, $employee, 1, Carbon::parse('2026-12-15'), 99);
        $lines = array_values(array_filter($result['summary']['payslip_earning_lines'], fn ($l) => $l['component_code'] === '13TH_MONTH_PAY'));
        $this->assertCount(1, $lines);
        $this->assertSame(20000.0, $lines[0]['amount']);
        $this->assertSame('earning', $lines[0]['component_type']);
        $this->assertSame('13th Month Pay (Basic Pay Method)', $lines[0]['label']);
        $this->assertTrue($lines[0]['metadata']['computed_from_finalized_payroll']);
    }

    public function test_apply_recovers_missing_cutoffs_into_the_basis_once(): void
    {
        $service = $this->serviceMock(['activeSettingForCompany', 'basicPay']);
        $service->method('activeSettingForCompany')->willReturn($this->setting());
        $service->method('basicPay')->willReturn(6299.0);
        $employee = (new User)->forceFill(['id' => 1681]);
        $recovered = collect([7444, 6872, 4008])->map(fn ($amount) => [
            'period_start' => '2026-05-11',
            'period_end' => '2026-05-25',
            'pay_date' => '2026-05-30',
            'component_code' => 'BASIC_PAY',
            'amount' => $amount,
        ])->all();

        $result = $service->applyToPayslipSnapshot(
            ['summary' => ['payslip_earning_lines' => []]],
            $employee,
            1,
            Carbon::parse('2026-06-30'),
            99,
            'standard',
            $recovered
        );

        $this->assertSame(2051.92, $result['summary']['thirteenth_month_pay_this_period']);
        $this->assertSame(24623.0, $result['summary']['thirteenth_month_metadata']['basis_total']);
        $this->assertSame(3, $result['summary']['thirteenth_month_metadata']['recovered_missing_cutoffs']);
    }

    public function test_refresh_preserves_exact_draft_amount(): void
    {
        $service = $this->serviceMock([]);
        $stored = ['summary' => ['payslip_earning_lines' => [[
            'component_code' => '13TH_MONTH_PAY', 'amount' => 20000.0, 'metadata' => ['basis_type' => 'basic'],
        ]]]];
        $fresh = ['summary' => ['payslip_earning_lines' => [['component_code' => 'ALLOWANCE', 'amount' => 1000.0]]]];
        $result = $service->preserveDraftLine($fresh, $stored);
        $this->assertSame(20000.0, $result['summary']['thirteenth_month_pay_this_period']);
        $this->assertSame(2, count($result['summary']['payslip_earning_lines']));
    }
}
