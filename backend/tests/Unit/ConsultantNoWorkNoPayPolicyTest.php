<?php

namespace Tests\Unit;

use App\Models\EmploymentPayrollSetting;
use App\Services\EmploymentPayrollPolicyApplicator;
use PHPUnit\Framework\TestCase;

class ConsultantNoWorkNoPayPolicyTest extends TestCase
{
    public function test_no_work_no_pay_is_off_by_default_for_every_employment_type(): void
    {
        foreach (EmploymentPayrollSetting::EMPLOYMENT_TYPES as $type) {
            $this->assertFalse(
                EmploymentPayrollSetting::defaults($type)['no_work_no_pay'],
                "{$type} must default to fixed (auto present) pay"
            );
        }
    }

    public function test_only_consultants_can_be_switched_to_no_work_no_pay(): void
    {
        $this->assertTrue(EmploymentPayrollSetting::supportsNoWorkNoPay('consultant'));
        $this->assertFalse(EmploymentPayrollSetting::supportsNoWorkNoPay('regular'));
        $this->assertFalse(EmploymentPayrollSetting::supportsNoWorkNoPay('probationary'));
        $this->assertFalse(EmploymentPayrollSetting::supportsNoWorkNoPay('project_based'));
    }

    public function test_policy_array_forces_no_work_no_pay_off_for_unsupported_types(): void
    {
        $regular = new EmploymentPayrollSetting([
            'employment_type' => 'regular',
            'no_work_no_pay' => true,
        ]);

        $this->assertFalse($regular->toPolicyArray()['no_work_no_pay']);

        $consultant = new EmploymentPayrollSetting([
            'employment_type' => 'consultant',
            'no_work_no_pay' => true,
        ]);

        $this->assertTrue($consultant->toPolicyArray()['no_work_no_pay']);
    }

    public function test_consultant_auto_present_payroll_is_used_by_default(): void
    {
        $this->assertTrue(EmploymentPayrollPolicyApplicator::consultantUsesAutoPresentPayroll([
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
            'no_work_no_pay' => false,
        ]));
    }

    public function test_consultant_auto_present_payroll_is_disabled_by_no_work_no_pay(): void
    {
        $this->assertFalse(EmploymentPayrollPolicyApplicator::consultantUsesAutoPresentPayroll([
            'allow_overtime' => false,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
            'no_work_no_pay' => true,
        ]));
    }

    public function test_consultant_auto_present_payroll_is_disabled_by_attendance_earnings(): void
    {
        $this->assertFalse(EmploymentPayrollPolicyApplicator::consultantUsesAutoPresentPayroll([
            'allow_overtime' => true,
            'allow_holiday_pay' => false,
            'allow_paid_leave' => false,
            'no_work_no_pay' => false,
        ]));
    }

    public function test_no_work_no_pay_flag_is_reported_separately_from_attendance_earnings(): void
    {
        $this->assertTrue(EmploymentPayrollPolicyApplicator::consultantNoWorkNoPayEnabled([
            'no_work_no_pay' => true,
        ]));
        $this->assertFalse(EmploymentPayrollPolicyApplicator::consultantAttendanceEarningsEnabled([
            'no_work_no_pay' => true,
        ]));
    }
}
