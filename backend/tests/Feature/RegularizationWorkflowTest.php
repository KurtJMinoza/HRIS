<?php

namespace Tests\Feature;

use App\Enums\EmploymentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Permission;
use App\Models\RegularizationRecommendation;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegularizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_head_can_submit_recommendation_for_department_employee()
    {
        $department = Department::factory()->create();
        $head = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $department->update(['department_head_id' => $head->id]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs($head)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Dept head recommendation.',
            'auto_complete' => false,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'recommended_by' => $head->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
        ]);
    }

    public function test_upcoming_regularizations_includes_employee_with_missing_employment_status_as_probationary_default()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employeeA = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(7),
            'is_active' => true,
        ]);

        // Legacy/missing status: should still appear in upcoming queue.
        $employeeB = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => null,
            'hire_date' => Carbon::now()->subMonths(7),
            'is_active' => true,
        ]);

        $res = $this->actingAs($hr)->getJson('/api/admin/regularization/upcoming?days_ahead=30');
        $res->assertStatus(200);

        $ids = collect($res->json('employees'))->pluck('id')->all();
        $this->assertContains($employeeA->id, $ids);
        $this->assertContains($employeeB->id, $ids);
    }

    public function test_upcoming_regularizations_includes_contract_expired_today()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'employment_type' => 'contractual',
            'is_active' => true,
            'hire_date' => Carbon::now()->subMonths(7),
            'contract_start_date' => Carbon::now()->subMonths(7)->startOfDay(),
            'contract_end_date' => Carbon::now()->startOfDay(),
        ]);

        $res = $this->actingAs($hr)->getJson('/api/admin/regularization/upcoming?days_ahead=30');
        $res->assertStatus(200);

        $rows = collect($res->json('employees'));
        $row = $rows->firstWhere('id', $employee->id);
        $this->assertNotNull($row);
        $this->assertSame('Expired today', $row['days_remaining_label'] ?? null);
        $this->assertSame('Expired today', $row['status_label'] ?? null);
        $this->assertSame('Contract end', $row['next_milestone'] ?? null);
    }

    public function test_contract_renewal_submission_does_not_use_regularization_eligibility()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        // Contractual employee has no hire_date (would fail regularization eligibility),
        // but contract renewal should still be allowed if renewal rules pass.
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'hire_date' => null,
            'is_active' => true,
            'contract_start_date' => Carbon::parse('2026-01-01'),
            'contract_end_date' => Carbon::parse('2026-03-31'),
        ]);

        $response = $this->actingAs($hr)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
            'recommendation_notes' => 'Renew contract.',
            'effective_date' => '2026-04-01',
            'expiration_date' => '2026-09-30',
            'auto_complete' => false,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'recommendation_type' => RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
            'status' => RegularizationRecommendation::STATUS_PENDING,
        ]);
    }

    public function test_regularization_ineligible_employee_fails_with_regularization_message()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'hire_date' => Carbon::now()->subMonths(8),
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => RegularizationRecommendation::TYPE_PROBATION_TO_REGULAR,
            'recommendation_notes' => 'Try to regularize.',
            'auto_complete' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Employee is not eligible for regularization.',
        ]);
    }

    public function test_contract_renewal_missing_expiration_date_returns_renewal_specific_error()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Contractual->value,
            'hire_date' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => RegularizationRecommendation::TYPE_CONTRACT_RENEWAL,
            'recommendation_notes' => 'Renew contract.',
            'effective_date' => '2026-04-01',
            // missing expiration_date
            'auto_complete' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Contract end date is required for contract renewal.',
        ]);
    }

    public function test_hr_admin_can_submit_recommendation_before_three_months_auto_complete()
    {
        $hr = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(2),
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Early HR recommendation.',
            'auto_complete' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('regularization_recommendations', [
            'user_id' => $employee->id,
            'recommended_by' => $hr->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => $hr->id,
        ]);
        $employee->refresh();
        $this->assertSame(EmploymentStatus::Probationary->value, $employee->employment_status);
    }

    public function test_cannot_submit_duplicate_recommendation()
    {
        $hr = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => $hr->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);

        $response = $this->actingAs($hr)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Duplicate.',
            'auto_complete' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_hr_can_approve_recommendation()
    {
        $hrUser = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $recommendation = RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);

        $response = $this->actingAs($hrUser)->postJson("/api/admin/regularization/recommendations/{$recommendation->id}/approve", [
            'notes' => 'Approved by HR.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('regularization_recommendations', [
            'id' => $recommendation->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => $hrUser->id,
        ]);
        $employee->refresh();
        $this->assertSame(EmploymentStatus::Regular->value, $employee->employment_status);
    }

    public function test_hr_can_reject_recommendation()
    {
        $hrUser = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $recommendation = RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);

        $response = $this->actingAs($hrUser)->postJson("/api/admin/regularization/recommendations/{$recommendation->id}/reject", [
            'reason' => 'Performance issues.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('regularization_recommendations', [
            'id' => $recommendation->id,
            'status' => RegularizationRecommendation::STATUS_REJECTED,
            'hr_reviewed_by' => $hrUser->id,
        ]);
    }

    public function test_cannot_review_already_reviewed_recommendation()
    {
        $hrUser = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $recommendation = RegularizationRecommendation::create([
            'user_id' => User::factory()->create()->id,
            'recommended_by' => User::factory()->create()->id,
            'status' => RegularizationRecommendation::STATUS_APPROVED,
            'hr_reviewed_by' => $hrUser->id,
            'hr_reviewed_at' => now(),
            'recommended_at' => now(),
        ]);

        $response = $this->actingAs($hrUser)->postJson("/api/admin/regularization/recommendations/{$recommendation->id}/approve");

        $response->assertStatus(422);
    }

    public function test_unauthorized_user_cannot_submit_recommendation()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $unauthorizedUser = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $response = $this->actingAs($unauthorizedUser)->postJson('/api/regularization/recommend', [
            'user_id' => $employee->id,
            'recommendation_type' => 'probation_to_regular',
            'recommendation_notes' => 'Unauthorized.',
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_can_view_own_regularization_status()
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $response = $this->actingAs($employee)->getJson('/api/regularization/my-status');

        $response->assertStatus(200);
        $response->assertJsonStructure(['recommendations']);
    }

    public function test_branch_head_cannot_approve_regularization_even_with_edit_permission()
    {
        $this->seed(RbacSeeder::class);
        $perm = Permission::query()->where('slug', 'employees.edit')->firstOrFail();
        DB::table('role_permissions')->insert([
            'role_key' => 'branch_head',
            'permission_id' => $perm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();

        $company = Company::query()->create(['name' => 'QA Regularization Co '.uniqid('', true)]);
        $branchHead = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $branch = Branch::query()->create([
            'name' => 'QA Branch',
            'company_id' => $company->id,
            'branch_manager_id' => $branchHead->id,
        ]);

        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $recommender = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $rec = RegularizationRecommendation::create([
            'user_id' => $employee->id,
            'recommended_by' => $recommender->id,
            'status' => RegularizationRecommendation::STATUS_PENDING,
            'recommended_at' => now(),
        ]);

        $response = $this->actingAs($branchHead)->postJson("/api/admin/regularization/recommendations/{$rec->id}/approve", [
            'notes' => 'Should be forbidden',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'Only HR administrators may approve regularization recommendations.',
        ]);
    }

    public function test_hr_admin_can_list_eligible_employees()
    {
        $hr = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $eligibleEmployee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
        ]);

        $response = $this->actingAs($hr)->getJson('/api/regularization/eligible-employees');

        $response->assertStatus(200);
        $data = $response->json('employees');
        $eligibleIds = collect($data)->pluck('id')->toArray();
        $this->assertContains($eligibleEmployee->id, $eligibleIds);
    }

    public function test_department_head_can_list_eligible_employees_in_scope()
    {
        $department = Department::factory()->create();
        $head = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $department->update(['department_head_id' => $head->id]);

        User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'employment_status' => EmploymentStatus::Probationary->value,
            'hire_date' => Carbon::now()->subMonths(3),
            'is_active' => true,
            'department_id' => $department->id,
        ]);

        $response = $this->actingAs($head)->getJson('/api/regularization/eligible-employees');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('employees'));
    }
}
