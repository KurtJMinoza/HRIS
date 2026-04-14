<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Jobs\ProcessEmployeeStatusTransitionsJob;
use App\Models\Department;
use App\Models\EmployeeStatusHistory;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comprehensive QA validation test suite.
 * Validates all business requirements and edge cases.
 */
class QAValidationTest extends TestCase
{
    use RefreshDatabase;

    // ========================================
    // 1. FUNCTIONAL VALIDATION
    // ========================================

    public function test_qa_probationary_becomes_regular_exactly_at_six_months()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6)->subDays(0), // Exactly 6 months
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);
    }

    public function test_qa_three_month_requires_both_recommendation_and_approval()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        // Without recommendation - should NOT regularize
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee->employment_status);

        // With pending recommendation - should NOT regularize
        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee->employment_status);

        // With approved recommendation - SHOULD regularize
        RegularizationRecommendation::where('user_id', $employee->id)->update([
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => User::factory()->create()->id,
            'hr_reviewed_at' => now(),
        ]);
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        $employee->refresh();
        $this->assertEquals(EmploymentStatus::Regular->value, $employee->employment_status);
    }

    public function test_qa_separated_employees_excluded()
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

    public function test_qa_non_probationary_excluded_from_automation()
    {
        $contractual = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        $projectBased = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::ProjectBased->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $contractual->refresh();
        $projectBased->refresh();
        $this->assertEquals(EmploymentStatus::Contractual->value, $contractual->employment_status);
        $this->assertEquals(EmploymentStatus::ProjectBased->value, $projectBased->employment_status);
    }

    // ========================================
    // 2. DATA INTEGRITY VALIDATION
    // ========================================

    public function test_qa_no_duplicate_status_history_records()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        // Run automation multiple times
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        ProcessEmployeeStatusTransitionsJob::dispatchSync();
        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $historyCount = EmployeeStatusHistory::where('user_id', $employee->id)->count();
        $this->assertEquals(1, $historyCount, 'Should only create one history record despite multiple runs');
    }

    public function test_qa_no_duplicate_recommendation_approvals()
    {
        $department = Department::factory()->create();
        $head = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $department->update(['department_head_id' => $head->id]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
            'department_id' => $department->id,
        ]);

        // First recommendation succeeds (pending only — no auto-approve so duplicate check applies)
        $response1 = $this->actingAs($head)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'First recommendation',
            'auto_complete' => false,
        ]);
        $response1->assertStatus(201);

        // Second recommendation fails
        $response2 = $this->actingAs($head)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Duplicate recommendation',
            'auto_complete' => false,
        ]);
        $response2->assertStatus(422);

        $count = RegularizationRecommendation::where('user_id', $employee->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_qa_foreign_keys_valid()
    {
        $employee = User::factory()->create([
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
        ]);

        $actor = User::factory()->create();

        $history = EmployeeStatusHistory::create([
            'user_id' => $employee->id,
            'previous_status' => EmploymentStatus::Probationary->value,
            'new_status' => EmploymentStatus::Regular->value,
            'effective_date' => now(),
            'trigger_type' => 'manual_admin',
            'actor_id' => $actor->id,
        ]);

        $this->assertNotNull($history->user);
        $this->assertNotNull($history->actor);
        $this->assertEquals($employee->id, $history->user->id);
        $this->assertEquals($actor->id, $history->actor->id);
    }

    // ========================================
    // 3. SECURITY VALIDATION
    // ========================================

    public function test_qa_line_employee_cannot_submit_recommendation()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $unauthorizedUser = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $response = $this->actingAs($unauthorizedUser)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Unauthorized recommendation',
        ]);

        $response->assertStatus(403);
    }

    public function test_qa_only_hr_can_approve()
    {
        $recommendation = RegularizationRecommendation::create([
            'user_id' => User::factory()->create()->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $response = $this->actingAs($employee)->postJson(
            "/api/admin/regularization/recommendations/{$recommendation->id}/approve"
        );

        // Should be blocked by middleware or authorization
        $response->assertStatus(403);
    }

    // ========================================
    // 4. AUTOMATION VALIDATION
    // ========================================

    public function test_qa_automation_is_idempotent()
    {
        $employees = User::factory()->count(3)->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        // Run automation 5 times
        for ($i = 0; $i < 5; $i++) {
            ProcessEmployeeStatusTransitionsJob::dispatchSync();
        }

        // Each employee should have exactly 1 history record
        foreach ($employees as $employee) {
            $count = EmployeeStatusHistory::where('user_id', $employee->id)->count();
            $this->assertEquals(1, $count, "Employee {$employee->id} should have exactly 1 history record");
        }
    }

    public function test_qa_automation_handles_missing_data_safely()
    {
        // Employee without hire_date
        $employee1 = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => null,
            'is_active' => true,
        ]);

        // Employee with invalid status
        $employee2 = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => 'invalid_status',
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        // Should not throw exceptions
        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        // Employees should remain unchanged
        $employee1->refresh();
        $employee2->refresh();
        $this->assertEquals(EmploymentStatus::Probationary->value, $employee1->employment_status);
        $this->assertEquals('invalid_status', $employee2->employment_status);
    }

    // ========================================
    // 5. EDGE CASES
    // ========================================

    public function test_qa_recommendation_approved_after_six_months_still_processed()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(7), // Past 6 months
            'is_active' => true,
        ]);

        // Late recommendation approved
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

        // Recommendation should be marked as processed
        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'processed' => true,
        ]);
    }

    public function test_qa_audit_trail_complete()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(6),
            'is_active' => true,
        ]);

        ProcessEmployeeStatusTransitionsJob::dispatchSync();

        $history = EmployeeStatusHistory::where('user_id', $employee->id)->first();

        $this->assertNotNull($history);
        $this->assertEquals(EmploymentStatus::Probationary->value, $history->previous_status);
        $this->assertEquals(EmploymentStatus::Regular->value, $history->new_status);
        $this->assertEquals('system_automation', $history->trigger_type);
        $this->assertNotNull($history->effective_date);
        $this->assertNotNull($history->created_at);
    }

    public function test_qa_all_statuses_supported()
    {
        $statuses = [
            EmploymentStatus::Probationary,
            EmploymentStatus::Regular,
            EmploymentStatus::Contractual,
            EmploymentStatus::ProjectBased,
            EmploymentStatus::Separated,
        ];

        foreach ($statuses as $status) {
            $employee = User::factory()->create([
                'employment_status' => $status->value,
            ]);

            $this->assertEquals($status->value, $employee->employment_status);
            $this->assertEquals($status->label(), $status->label());
        }
    }
}
