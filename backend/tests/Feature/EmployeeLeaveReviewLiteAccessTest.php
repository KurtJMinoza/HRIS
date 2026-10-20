<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard "Recent filings" deep-links open the leave review modal on the employee self-service
 * page. Plain employees are outside the HR panel surface, so the own-request endpoint must serve
 * them (the hr.panel routes answer 403 for them).
 */
class EmployeeLeaveReviewLiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeLeave(User $employee): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'user_id' => $employee->id,
            'type' => 'vacation',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => LeaveRequest::STATUS_PENDING,
            'pending_approval' => true,
            'approval_stage' => 'pending_second',
            'filed_by' => $employee->id,
            'filed_at' => now(),
        ]);
    }

    public function test_employee_can_read_own_leave_review_payload_without_hr_panel_access(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $leave = $this->makeLeave($employee);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/leave/my/'.$leave->id.'/review-lite')
            ->assertOk()
            ->assertJsonPath('leave_request.id', $leave->id)
            ->assertJsonPath('leave_request.type', 'vacation')
            ->assertJsonPath('leave_request.status', LeaveRequest::STATUS_PENDING)
            ->assertJsonPath('leave_request.actor_can_delete', true)
            ->assertJsonPath('leave_request.can_approve', false)
            ->assertJsonPath('leave_request.actor_can_approve', false)
            ->assertJsonStructure([
                'leave_request' => [
                    'approval_progress',
                    'approval_history',
                    'document_urls',
                ],
            ]);
    }

    public function test_employee_cannot_read_another_employees_leave_review_payload(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $other = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $leave = $this->makeLeave($other);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/leave/my/'.$leave->id.'/review-lite')
            ->assertNotFound();
    }

    public function test_employee_review_endpoint_does_not_open_the_hr_panel_surface(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $leave = $this->makeLeave($employee);

        // The hr.panel route stays closed to plain employees.
        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/leave-requests/'.$leave->id.'/review-lite')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden. HR panel access required.');
    }
}
