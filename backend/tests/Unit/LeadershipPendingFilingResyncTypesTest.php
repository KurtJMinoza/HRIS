<?php

namespace Tests\Unit;

use App\Jobs\LeadershipPendingChainResyncJob;
use App\Services\OrgApprovalWorkflowService;
use App\Services\OrganizationLeadershipService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class LeadershipPendingFilingResyncTypesTest extends TestCase
{
    public function test_pending_filing_resync_covers_all_hierarchy_filing_modules(): void
    {
        $types = OrganizationLeadershipService::pendingFilingResyncTypes();

        $this->assertSame([
            OrgApprovalWorkflowService::MODULE_LEAVE,
            OrgApprovalWorkflowService::MODULE_OVERTIME,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            OrgApprovalWorkflowService::MODULE_CHANGE_SCHEDULE,
        ], $types);
    }

    public function test_leadership_resync_job_defaults_match_pending_filing_types(): void
    {
        $job = new LeadershipPendingChainResyncJob;
        $ref = new ReflectionClass($job);
        $prop = $ref->getProperty('requestTypes');
        $prop->setAccessible(true);

        $this->assertSame(
            OrganizationLeadershipService::pendingFilingResyncTypes(),
            $prop->getValue($job),
        );
    }

    public function test_workflow_service_can_resync_correction_and_schedule_chains(): void
    {
        $ref = new ReflectionClass(OrgApprovalWorkflowService::class);

        $this->assertTrue($ref->hasMethod('resyncPendingAttendanceCorrectionRequests'));
        $this->assertTrue($ref->hasMethod('resyncPendingScheduleRequests'));

        $public = new ReflectionMethod(OrgApprovalWorkflowService::class, 'resyncPendingRequestChains');
        $this->assertTrue($public->isPublic());
    }
}
