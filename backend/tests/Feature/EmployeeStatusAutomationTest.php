<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Jobs\ProcessEmployeeStatusTransitionsJob;
use App\Models\EmployeeStatusHistory;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeStatusAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_month_automatic_regularization()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);

        $this->assertDatabaseHas('employee_status_histories', [
            'user_id' => $employee->id,
            'new_status' => EmploymentStatus::Regular->value,
            'trigger_type' => 'system_automation',
        ]);
    }

    public function test_three_month_early_regularization_with_approved_recommendation()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => User::factory()->create()->id,
            'hr_reviewed_at' => now(),
            'recommended_at' => now(),
            'processed' => false,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);

        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'processed' => true,
        ]);
    }

    public function test_three_month_not_regularized_without_recommendation()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee->employment_status);
    }

    public function test_separated_employee_not_regularized()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Separated->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => false,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Separated->value, $employee->employment_status);
    }

    public function test_inactive_employee_not_regularized()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => false,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee->employment_status);
    }

    public function test_already_regular_employee_not_processed()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Regular->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);

        // Should not create new history
        $this->assertEquals(0, EmployeeStatusHistory::where('user_id', $employee->id)->count());
    }

    public function test_idempotency_no_duplicate_regularization()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        // Run job twice
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);

        // Should only have one history record
        $this->assertEquals(1, EmployeeStatusHistory::where('user_id', $employee->id)->count());
    }

    public function test_employee_without_hire_date_skipped()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => null,
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee->employment_status);
    }

    public function test_contractual_employee_not_auto_regularized()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Contractual->value, $employee->employment_status);
    }

    public function test_three_month_takes_precedence_over_six_month()
    {
        // Employee at 6 months with approved 3-month recommendation
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => User::factory()->create()->id,
            'hr_reviewed_at' => now(),
            'recommended_at' => now(),
            'processed' => false,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);

        // Should mark recommendation as processed
        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'processed' => true,
        ]);
    }

    public function test_multiple_employees_processed_in_batch()
    {
        $employees = User::factory()->count(5)->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        foreach ($employees as $employee) {
            $employee->refresh();
            $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);
        }

        $this->assertEquals(5, EmployeeStatusHistory::where('trigger_type', 'system_automation')->count());
    }
}
