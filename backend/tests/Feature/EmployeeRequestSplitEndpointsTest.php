<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\Overtime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRequestSplitEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lightweight_employee_lists_return_table_fields_without_histories(): void
    {
        $employee = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);

        $leave = LeaveRequest::query()->create([
            'user_id' => $employee->id,
            'type' => 'vacation',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => LeaveRequest::STATUS_PENDING,
            'pending_approval' => true,
            'filed_by' => $employee->id,
        ]);
        $overtime = Overtime::query()->create([
            'user_id' => $employee->id,
            'date' => now()->toDateString(),
            'schedule_end' => '17:00:00',
            'expected_end_time' => '19:00:00',
            'computed_minutes' => 120,
            'computed_hours' => 2,
            'ot_type' => 'regular',
            'reason' => 'Release work',
            'status' => Overtime::STATUS_PENDING,
            'pending_approval' => true,
            'filed_by' => $employee->id,
        ]);
        $correction = AttendanceCorrection::query()->create([
            'user_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'issue_kind' => 'missing_out',
            'status' => 'pending',
            'pending_approval' => true,
            'approved' => false,
            'filed_at' => now(),
            'filed_by' => $employee->id,
        ]);

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/employee/leave/list')
            ->assertOk()
            ->assertJsonPath('leave_requests.0.id', $leave->id)
            ->assertJsonMissingPath('leave_requests.0.approval_history');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/employee/overtime/list')
            ->assertOk()
            ->assertJsonPath('overtimes.0.id', $overtime->id)
            ->assertJsonMissingPath('overtimes.0.approval_history');

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/employee/attendance-corrections/list')
            ->assertOk()
            ->assertJsonPath('presence_filings.0.id', $correction->id)
            ->assertJsonMissingPath('presence_filings.0.approval_history');
    }
}
