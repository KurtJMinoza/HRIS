<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\EmploymentPayrollPolicyResolver;
use App\Services\HolidayService;
use App\Services\LeaveCreditService;
use Carbon\Carbon;
use Tests\TestCase;

class LeaveCreditServiceConsultantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-04', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_consultant_with_paid_leave_policy_and_one_year_is_eligible(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $consultant = new User([
            'employment_status' => 'consultant',
            'employment_type' => 'full_time',
            'employment_status_effective_date' => '2024-01-01',
            'hire_date' => '2024-01-01',
            'leave_credits' => 0,
        ]);

        $this->assertTrue($service->hasLeaveCreditPoolEmployment($consultant));
        $this->assertTrue($service->eligibleForPaidLeavePool($consultant));
    }

    public function test_consultant_without_paid_leave_policy_is_not_eligible(): void
    {
        $service = $this->service(allowPaidLeave: false);

        $consultant = new User([
            'employment_status' => 'consultant',
            'employment_status_effective_date' => '2020-01-01',
            'hire_date' => '2020-01-01',
        ]);

        $this->assertFalse($service->hasLeaveCreditPoolEmployment($consultant));
        $this->assertFalse($service->eligibleForPaidLeavePool($consultant));
    }

    public function test_consultant_under_one_year_is_not_eligible_even_with_policy(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $consultant = new User([
            'employment_status' => 'consultant',
            'employment_status_effective_date' => '2026-01-01',
            'hire_date' => '2026-01-01',
        ]);

        $this->assertTrue($service->hasLeaveCreditPoolEmployment($consultant));
        $this->assertFalse($service->eligibleForPaidLeavePool($consultant));
    }

    public function test_regular_employee_still_requires_regular_status(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $regular = new User([
            'employment_status' => 'regular',
            'employment_status_effective_date' => '2020-01-01',
        ]);

        $probationary = new User([
            'employment_status' => 'probationary',
            'employment_status_effective_date' => '2020-01-01',
        ]);

        $this->assertTrue($service->eligibleForPaidLeavePool($regular));
        $this->assertFalse($service->eligibleForPaidLeavePool($probationary));
    }

    private function service(bool $allowPaidLeave): LeaveCreditService
    {
        $resolver = $this->createMock(EmploymentPayrollPolicyResolver::class);
        $resolver->method('resolveForEmployee')->willReturn([
            'employment_type' => 'consultant',
            'allow_paid_leave' => $allowPaidLeave,
            'apply_custom_deductions' => true,
            'apply_allowances' => true,
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
        ]);

        return new LeaveCreditService(
            $this->createMock(HolidayService::class),
            $resolver,
        );
    }
}
