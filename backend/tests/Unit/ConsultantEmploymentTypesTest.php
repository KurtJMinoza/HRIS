<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use App\Models\User;
use App\Services\EmploymentTypeResolver;
use App\Services\LeaveCreditService;
use App\Services\PayrollComputationService;
use Tests\TestCase;

class ConsultantEmploymentTypesTest extends TestCase
{
    public function test_consultant_types_parse_and_mirror_regular_and_probationary(): void
    {
        $this->assertSame(EmploymentStatus::ConsultantRegular, EmploymentStatus::tryFromStored('Consultant – Regular'));
        $this->assertSame(EmploymentStatus::ConsultantProbationary, EmploymentStatus::tryFromStored('consultant-probationary'));

        $this->assertSame(EmploymentStatus::Regular, EmploymentStatus::ConsultantRegular->payrollBasis());
        $this->assertSame(EmploymentStatus::Probationary, EmploymentStatus::ConsultantProbationary->payrollBasis());
        $this->assertSame(EmploymentStatus::Consultant, EmploymentStatus::Consultant->payrollBasis());

        $this->assertTrue(EmploymentStatus::ConsultantProbationary->canBeRegularized());
        $this->assertSame(EmploymentStatus::ConsultantRegular, EmploymentStatus::ConsultantProbationary->regularizedStatus());
        $this->assertSame(EmploymentStatus::Regular, EmploymentStatus::Probationary->regularizedStatus());

        foreach ([EmploymentStatus::Consultant, EmploymentStatus::ConsultantRegular, EmploymentStatus::ConsultantProbationary] as $status) {
            $this->assertTrue($status->isConsultantPayrollModule());
        }
        $this->assertFalse(EmploymentStatus::Regular->isConsultantPayrollModule());
    }

    public function test_payroll_and_leave_follow_the_mirrored_status(): void
    {
        $payroll = app(PayrollComputationService::class);
        $fixedSemiMonthly = new \ReflectionMethod($payroll, 'isFixedSemiMonthlySalaryEmployee');
        $isConsultant = new \ReflectionMethod($payroll, 'isConsultantEmployee');
        $leave = app(LeaveCreditService::class);
        $resolver = app(EmploymentTypeResolver::class);

        $consultantRegular = new User(['employment_status' => 'consultant_regular']);
        $consultantProbationary = new User(['employment_status' => 'consultant_probationary']);
        $plainConsultant = new User(['employment_status' => 'consultant']);

        // Consultant – Regular: fixed semi-monthly like Regular, not the fixed Auto-Present consultant path.
        $this->assertFalse($isConsultant->invoke($payroll, $consultantRegular));
        $this->assertTrue($fixedSemiMonthly->invoke($payroll, $consultantRegular, false));
        $this->assertTrue($leave->isRegularEmployment($consultantRegular));
        $this->assertSame('regular', $resolver->resolveLaborEmploymentType($consultantRegular));

        // Consultant – Probationary: attendance-based daily pay like Probationary.
        $this->assertFalse($isConsultant->invoke($payroll, $consultantProbationary));
        $this->assertFalse($fixedSemiMonthly->invoke($payroll, $consultantProbationary, false));
        $this->assertTrue($payroll->usesSeparateRestDayWorkedPayDisplayForEmploymentStatus('consultant_probationary'));
        $this->assertTrue($leave->isProbationaryEmployment($consultantProbationary));
        $this->assertSame('probationary', $resolver->resolveLaborEmploymentType($consultantProbationary));

        // Plain Consultant keeps the existing fixed consultant payroll.
        $this->assertTrue($isConsultant->invoke($payroll, $plainConsultant));
        $this->assertSame('consultant', $resolver->resolveLaborEmploymentType($plainConsultant));
    }

    public function test_consultant_regular_deducts_absences_from_fixed_gross_like_regular(): void
    {
        $payroll = app(PayrollComputationService::class);
        $fixedSemiMonthly = new \ReflectionMethod($payroll, 'isFixedSemiMonthlySalaryEmployee');
        $fixedSemiMonthly->setAccessible(true);

        $consultantRegular = new User([
            'employment_status' => 'consultant_regular',
            'employment_type' => 'full_time',
        ]);

        $this->assertTrue($fixedSemiMonthly->invoke($payroll, $consultantRegular, false));

        $breakdown = [
            'scheduled_days_count' => 14,
            'rows' => [
                ['key' => 'late', 'deduction_amount' => 485.42],
                ['key' => 'absence', 'count' => 2.0, 'deduction_amount' => 1194.92],
            ],
            'total_deduction' => 1680.34,
        ];

        $this->assertTrue($payroll->shouldPayFixedRegularFullSemiMonthlyGross(12.0, 14, $breakdown, false, true));
        $this->assertSame(6086.66, round(7767.0 - 485.42 - 1194.92, 2));
    }

    public function test_consultant_regular_unpaid_leave_reduces_fixed_regular_display_totals(): void
    {
        $payslipService = app(\App\Services\PayslipService::class);

        $summary = [
            'employment_status' => 'consultant_regular',
            'employment_type' => 'full_time',
            'regular_fixed_semi_monthly_payroll' => true,
            'fixed_semi_monthly_basic_gross' => 9815.0,
            'semi_monthly_basic_salary' => 9815.0,
            'regular_pay_present_day_units' => 5.0,
            'regular_fixed_present_day_base_pay' => 3775.0,
            'daily_rate' => 755.0,
            'basic_pay_this_period' => 3775.0,
            'attendance_deduction' => 6040.0,
            'daily_computation_earning_lines' => [[
                'key' => 'daily:regular_pay',
                'label' => 'Regular pay',
                'amount' => 3775.0,
                'display_amount' => 9815.0,
                'units' => '5 days',
                'metadata' => ['regular_fixed_semi_monthly_payroll' => true],
            ]],
        ];
        $breakdown = [
            'scheduled_days_count' => 11,
            'rows' => [
                ['key' => 'absence', 'count' => 2.0, 'deduction_amount' => 1510.0],
                ['key' => 'unpaid_leave', 'count' => 6.0, 'deduction_amount' => 4530.0],
            ],
            'total_deduction' => 1510.0,
        ];

        $this->assertFalse($payslipService->usesConsultantFixedPayslipLayout($summary, $summary));

        $method = new \ReflectionMethod($payslipService, 'attachFixedSemiMonthlyRegularPayAfterReductionsDisplay');
        $method->setAccessible(true);
        $updated = $method->invoke($payslipService, $summary, $breakdown, $summary['daily_computation_earning_lines'][0]);

        $this->assertSame(9815.0, (float) ($updated['daily_computation_earning_lines'][0]['display_amount'] ?? 0));
        $this->assertSame(6040.0, (float) ($updated['attendance_pay_breakdown']['total_deduction'] ?? 0));
        $this->assertSame(3775.0, (float) ($updated['attendance_pay_breakdown']['regular_pay_after_reductions'] ?? 0));
    }
}
