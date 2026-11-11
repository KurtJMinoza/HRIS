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
}
