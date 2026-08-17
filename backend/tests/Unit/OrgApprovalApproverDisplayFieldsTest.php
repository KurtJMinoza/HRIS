<?php

namespace Tests\Unit;

use App\Services\OrgApprovalWorkflowService;
use PHPUnit\Framework\TestCase;

class OrgApprovalApproverDisplayFieldsTest extends TestCase
{
    public function test_prefers_current_pending_approver(): void
    {
        $fields = OrgApprovalWorkflowService::listApproverDisplayFieldsFromProgress([
            ['status' => 'completed', 'approver_name' => null, 'profile_image_url' => null],
            ['status' => 'completed', 'approver_name' => 'First Approver', 'profile_image_url' => '/a.jpg', 'approver_id' => 1],
            ['status' => 'current', 'approver_name' => 'Waiting Approver', 'profile_image_url' => '/b.jpg', 'approver_id' => 2],
            ['status' => 'pending', 'approver_name' => 'Later Approver', 'profile_image_url' => '/c.jpg', 'approver_id' => 3],
        ]);

        $this->assertSame('Waiting Approver', $fields['current_approver_name']);
        $this->assertSame('/b.jpg', $fields['current_approver_profile_image']);
        $this->assertSame(2, $fields['current_approver_id']);
    }

    public function test_falls_back_to_latest_completed_approver_when_approved(): void
    {
        $fields = OrgApprovalWorkflowService::listApproverDisplayFieldsFromProgress([
            ['status' => 'completed', 'approver_name' => null, 'profile_image_url' => null],
            ['status' => 'completed', 'approver_name' => 'Dept Head', 'profile_image_url' => '/d.jpg', 'approver_id' => 10],
            ['status' => 'completed', 'approver_name' => 'Edquila, Manilyn', 'profile_image_url' => '/e.jpg', 'approver_id' => 20],
        ]);

        $this->assertSame('Edquila, Manilyn', $fields['current_approver_name']);
        $this->assertSame('/e.jpg', $fields['current_approver_profile_image']);
        $this->assertSame(20, $fields['current_approver_id']);
    }

    public function test_nameless_current_hr_pool_falls_back_to_last_acted(): void
    {
        $fields = OrgApprovalWorkflowService::listApproverDisplayFieldsFromProgress([
            ['status' => 'completed', 'approver_name' => 'Dept Head', 'profile_image_url' => '/d.jpg', 'approver_id' => 10],
            ['status' => 'completed', 'approver_name' => 'HR Admin', 'profile_image_url' => '/h.jpg', 'approver_id' => 99],
            ['status' => 'current', 'approver_name' => null, 'profile_image_url' => null, 'approver_id' => null],
        ]);

        $this->assertSame('HR Admin', $fields['current_approver_name']);
        $this->assertSame('/h.jpg', $fields['current_approver_profile_image']);
        $this->assertSame(99, $fields['current_approver_id']);
    }

    public function test_approved_request_with_stale_pending_steps_uses_last_named_completed_actor(): void
    {
        // Mirrors buildApprovalProgress when leave.status=approved but org rows stayed pending:
        // those rows are remapped to completed so the final HR admin is shown.
        $fields = OrgApprovalWorkflowService::listApproverDisplayFieldsFromProgress([
            ['status' => 'completed', 'approver_name' => null, 'profile_image_url' => null],
            ['status' => 'completed', 'approver_name' => 'Magbanua, Edmund Narvaza', 'profile_image_url' => null, 'approver_id' => 1658],
            ['status' => 'completed', 'approver_name' => 'Edquila, Manilyn Arcala', 'profile_image_url' => null, 'approver_id' => 1648],
        ]);

        $this->assertSame('Edquila, Manilyn Arcala', $fields['current_approver_name']);
        $this->assertSame(1648, $fields['current_approver_id']);
    }

    public function test_falls_back_to_rejecting_approver(): void
    {
        $fields = OrgApprovalWorkflowService::listApproverDisplayFieldsFromProgress([
            ['status' => 'completed', 'approver_name' => 'Dept Head', 'profile_image_url' => '/d.jpg', 'approver_id' => 10],
            ['status' => 'rejected', 'approver_name' => 'Rejector', 'profile_image_url' => '/r.jpg', 'approver_id' => 30],
        ]);

        $this->assertSame('Rejector', $fields['current_approver_name']);
        $this->assertSame('/r.jpg', $fields['current_approver_profile_image']);
    }
}
