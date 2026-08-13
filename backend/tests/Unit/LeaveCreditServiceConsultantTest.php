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
            'hire_date' => '2020-01-01',
        ]);

        $probationary = new User([
            'employment_status' => 'probationary',
            'employment_status_effective_date' => '2020-01-01',
            'hire_date' => '2020-01-01',
        ]);

        $this->assertTrue($service->eligibleForPaidLeavePool($regular));
        $this->assertFalse($service->eligibleForPaidLeavePool($probationary));
    }

    public function test_recent_status_effective_date_does_not_delay_hire_date_eligibility(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $regular = new User([
            'employment_status' => 'regular',
            'hire_date' => '2024-08-04',
            'employment_status_effective_date' => '2026-08-01',
        ]);

        $this->assertSame('2024-08-04', $service->leaveCreditsServiceAnchorDate($regular)?->toDateString());
        $this->assertTrue($service->eligibleForPaidLeavePool($regular));
    }

    public function test_old_status_effective_date_does_not_bypass_hire_date_anniversary(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $regular = new User([
            'employment_status' => 'regular',
            'hire_date' => '2026-01-01',
            'employment_status_effective_date' => '2020-01-01',
        ]);

        $this->assertSame('2026-01-01', $service->leaveCreditsServiceAnchorDate($regular)?->toDateString());
        $this->assertFalse($service->eligibleForPaidLeavePool($regular));
    }

    public function test_status_effective_date_cannot_replace_a_missing_hire_date(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $regular = new User([
            'employment_status' => 'regular',
            'hire_date' => null,
            'employment_status_effective_date' => '2020-01-01',
        ]);

        $this->assertNull($service->leaveCreditsServiceAnchorDate($regular));
        $this->assertFalse($service->eligibleForPaidLeavePool($regular));
    }

    public function test_half_day_bills_half_a_credit(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $this->assertSame(0.5, $service->billableCreditDaysFromFields('half_day', '2026-08-10', '2026-08-10'));
        $this->assertSame(0.5, $service->billableCreditDaysFromFields('HALF_DAY', '2026-08-10', '2026-08-12'));
    }

    public function test_full_day_leave_bills_one_credit_per_day(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $this->assertSame(3.0, $service->billableCreditDaysFromFields('vacation', '2026-08-10', '2026-08-12'));
        $this->assertSame(1.0, $service->billableCreditDaysFromFields('sick', '2026-08-10', '2026-08-10'));
    }

    public function test_undertime_bills_no_credits(): void
    {
        $service = $this->service(allowPaidLeave: true);

        $this->assertSame(0.0, $service->billableCreditDaysFromFields('undertime', '2026-08-10', '2026-08-10'));
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
