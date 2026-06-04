<?php

namespace Tests\Feature;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\AttendanceCorrectionStatusService;
use App\Services\OrgApprovalWorkflowService;
use App\Support\AttendanceCorrectionModuleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AttendanceCorrectionStatusCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_use_status_column_after_hr_approval(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $employee = User::factory()->create(['is_active' => true]);

        $pending = AttendanceCorrection::query()->create([
            'user_id' => $employee->id,
            'date' => now()->toDateString(),
            'pending_approval' => true,
            'approved' => false,
            'status' => AttendanceCorrectionStatusService::STATUS_PENDING,
        ]);

        $approved = AttendanceCorrection::query()->create([
            'user_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'pending_approval' => false,
            'approved' => true,
            'approved_at' => now(),
            'approval_stage' => 'approved',
            'status' => AttendanceCorrectionStatusService::STATUS_APPROVED,
            'second_approved_at' => now(),
            'second_approver_id' => $admin->id,
            'final_approved_by' => $admin->id,
        ]);

        OrgApprovalRecord::query()->create([
            'module_type' => OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            'request_id' => $approved->id,
            'approver_role' => HrRole::AdminHr->value,
            'approval_status' => OrgApprovalRecord::STATUS_APPROVED,
            'sequence_order' => 2,
            'approval_label' => 'HR approval',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/attendance-corrections/counts')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('approved', 1)
            ->assertJsonPath('rejected', 0)
            ->assertJsonPath('cancelled', 0);

        app(AttendanceCorrectionStatusService::class)->markHrFinalApproved($pending->fresh(), $admin);
        AttendanceCorrectionModuleCache::flush();
        Cache::flush();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/attendance-corrections/counts')
            ->assertOk()
            ->assertJsonPath('pending', 0)
            ->assertJsonPath('approved', 2);
    }
}
