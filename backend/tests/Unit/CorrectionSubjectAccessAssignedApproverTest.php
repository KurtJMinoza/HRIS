<?php

namespace Tests\Unit;

use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectionSubjectAccessAssignedApproverTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_pending_approver_can_access_subject_outside_org_scope(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('org_approval_records')) {
            $this->markTestSkipped('org_approval_records missing');
        }

        $subject = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);
        $actor = User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
        ]);

        $correction = AttendanceCorrection::query()->create([
            'user_id' => $subject->id,
            'date' => now()->toDateString(),
            'pending_approval' => true,
            'approved' => false,
            'filed_by' => $subject->id,
            'filed_at' => now(),
            'approval_stage' => 'pending_first',
            'status' => 'pending',
        ]);

        OrgApprovalRecord::query()->create([
            'request_id' => $correction->id,
            'module_type' => OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            'approval_level' => 'first',
            'approver_role' => 'department_head',
            'approver_id' => $actor->id,
            'approver_name' => $actor->display_name,
            'eligible_approver_ids' => [$actor->id],
            'approval_status' => OrgApprovalRecord::STATUS_PENDING,
            'sequence_order' => 1,
        ]);

        $scope = app(DataScopeService::class);

        $this->assertTrue($scope->actorIsPendingCorrectionApproverForSubject($actor, $subject));

        // Must not throw Forbidden when actor is the assigned pending approver.
        $scope->ensureCorrectionSubjectAccessible($actor, $subject);
        $this->assertTrue(true);
    }
}
