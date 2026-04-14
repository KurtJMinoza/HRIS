<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use App\Services\EmployeeStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmployeeStatusService::class);
    }

    public function test_employee_eligible_for_six_month_regularization()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        $this->assertTrue($this->service->isEligibleForSixMonthRegularization($employee));
    }

    public function test_employee_not_eligible_before_six_months()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(5)->subDays(29),
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->isEligibleForSixMonthRegularization($employee));
    }

    public function test_separated_employee_not_eligible_for_regularization()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Separated->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => false,
        ]);

        $this->assertFalse($this->service->isEligibleForSixMonthRegularization($employee));
    }

    public function test_regular_employee_not_eligible_for_regularization()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Regular->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->isEligibleForSixMonthRegularization($employee));
    }

    public function test_three_month_regularization_requires_approved_recommendation()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        // Without recommendation
        $this->assertFalse($this->service->isEligibleForThreeMonthRegularization($employee));

        // With approved recommendation
        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'recommended_at' => now(),
            'processed' => false,
        ]);

        $this->assertTrue($this->service->isEligibleForThreeMonthRegularization($employee));
    }

    public function test_three_month_regularization_not_eligible_before_three_months()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(2)->subDays(29),
            'is_active' => true,
        ]);

        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'recommended_at' => now(),
            'processed' => false,
        ]);

        $this->assertFalse($this->service->isEligibleForThreeMonthRegularization($employee));
    }

    public function test_regularize_employee_creates_history()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        $history = $this->service->regularizeEmployee(
            $employee,
            'system_automation',
            null,
            'Test regularization'
        );

        $this->assertDatabaseHas('employee_status_histories', [
            'user_id' => $employee->id,
            'previous_status' => EmploymentStatus::Probationary->value,
            'new_status' => EmploymentStatus::Regular->value,
            'trigger_type' => 'system_automation',
        ]);

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);
    }

    public function test_regularization_marks_recommendation_as_processed()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $recommendation = RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'recommended_at' => now(),
            'processed' => false,
        ]);

        $this->service->regularizeEmployee($employee, 'system_automation');

        $recommendation->refresh();
        $this->assertTrue($recommendation->processed);
        $this->assertNotNull($recommendation->processed_at);
    }

    public function test_get_milestone_dates()
    {
        $hireDate = Carbon::parse('2024-01-15');
        $employee = User::factory()->create([
            'hire_date' => $hireDate,
        ]);

        $milestones = $this->service->getMilestoneDates($employee);

        $this->assertEquals('2024-01-15', $milestones['hire_date']);
        $this->assertEquals('2024-04-15', $milestones['three_months']);
        $this->assertEquals('2024-07-15', $milestones['six_months']);
    }

    public function test_is_approaching_milestone()
    {
        // Employee 5 days before 3-month milestone
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3)->addDays(5),
            'is_active' => true,
        ]);

        $approaching = $this->service->isApproachingMilestone($employee, 7);
        $this->assertEquals('3_months', $approaching);
    }

    public function test_employee_without_hire_date_not_eligible()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => null,
            'is_active' => true,
        ]);

        $this->assertFalse($this->service->isEligibleForSixMonthRegularization($employee));
        $this->assertFalse($this->service->isEligibleForThreeMonthRegularization($employee));
    }
}
