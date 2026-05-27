<?php

namespace Tests\Unit;

use App\Enums\EmploymentStatus;
use App\Models\LeaveCreditTransaction;
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
            'hire_date' => Carbon::now()->subMonths(2),
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
            'hire_date' => Carbon::now()->subMonths(3)->addDays(2),
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

    public function test_auto_status_resolver_marks_july_2023_hire_as_regular(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-27'));

        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => '2023-07-03',
            'is_active' => true,
            'status_override' => false,
        ]);

        $resolved = $this->service->syncAutomaticEmploymentStatus($employee);

        $this->assertSame(EmploymentStatus::Regular->value, $resolved->employment_status);
        $this->assertSame('2024-01-03', $resolved->regularization_date?->toDateString());
        $this->assertSame('2024-01-03', $resolved->employment_status_effective_date?->toDateString());

        Carbon::setTestNow();
    }

    public function test_auto_status_resolver_keeps_recent_hire_probationary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-27'));

        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Regular->value,
            'hire_date' => '2026-02-01',
            'is_active' => true,
            'status_override' => false,
        ]);

        $resolved = $this->service->syncAutomaticEmploymentStatus($employee);

        $this->assertSame(EmploymentStatus::Probationary->value, $resolved->employment_status);
        $this->assertSame('2026-08-01', $resolved->regularization_date?->toDateString());

        Carbon::setTestNow();
    }

    public function test_auto_status_resolver_respects_status_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-27'));

        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => '2023-07-03',
            'is_active' => true,
            'status_override' => true,
        ]);

        $resolved = $this->service->syncAutomaticEmploymentStatus($employee);

        $this->assertSame(EmploymentStatus::Probationary->value, $resolved->employment_status);
        $this->assertNull($resolved->regularization_date);

        Carbon::setTestNow();
    }

    public function test_imported_one_year_employee_gets_regular_status_and_leave_credits_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-27'));

        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => '2025-05-01',
            'is_active' => true,
            'leave_credits' => 0,
            'leave_credits_reset_date' => null,
            'leave_credits_initialized_at' => null,
            'status_override' => false,
        ]);

        $resolved = $this->service->syncAutomaticEmploymentStatus($employee, initializeLeaveCredits: true);
        $resolvedAgain = $this->service->syncAutomaticEmploymentStatus($resolved->fresh(), initializeLeaveCredits: true);

        $this->assertSame(EmploymentStatus::Regular->value, $resolvedAgain->employment_status);
        $this->assertSame(7, (int) $resolvedAgain->leave_credits);
        $this->assertNotNull($resolvedAgain->leave_credits_initialized_at);
        $this->assertSame(1, LeaveCreditTransaction::query()
            ->where('user_id', $employee->id)
            ->where('leave_type_context', 'auto_regularization_import')
            ->count());

        Carbon::setTestNow();
    }
}
