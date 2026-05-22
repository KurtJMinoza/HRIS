<?php

namespace Tests\Unit;

use App\Models\ApprovalWorkflowSetting;
use App\Services\ApprovalWorkflowSettingService;
use App\Services\HrApprovalChainResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApprovalWorkflowSettingServiceTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('approval_workflow_settings')) {
            $this->markTestSkipped('approval_workflow_settings table is not available.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
        app(ApprovalWorkflowSettingService::class)->ensureDefaults();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
            $this->transactionStarted = false;
        }

        parent::tearDown();
    }

    public function test_default_attendance_correction_is_hr_only(): void
    {
        $service = app(ApprovalWorkflowSettingService::class);

        $this->assertFalse($service->usesHierarchyApproval('attendance_correction'));
        $this->assertTrue($service->isHrOnlyRequestType('attendance_correction'));
    }

    public function test_default_leave_and_overtime_use_hierarchy(): void
    {
        $service = app(ApprovalWorkflowSettingService::class);

        $this->assertTrue($service->usesHierarchyApproval('leave'));
        $this->assertTrue($service->usesHierarchyApproval('overtime'));
    }

    public function test_default_change_schedule_is_hr_only(): void
    {
        $service = app(ApprovalWorkflowSettingService::class);

        $this->assertFalse($service->usesHierarchyApproval('change_schedule'));
        $this->assertFalse($service->usesHierarchyApproval('schedule'));
    }

    public function test_attendance_correction_can_be_switched_to_hierarchy_on(): void
    {
        ApprovalWorkflowSetting::query()
            ->where('request_type', ApprovalWorkflowSetting::REQUEST_TYPE_ATTENDANCE_CORRECTION)
            ->update(['use_hierarchy_approval' => true]);

        $service = app(ApprovalWorkflowSettingService::class);

        $this->assertTrue($service->usesHierarchyApproval('attendance_correction'));
        $this->assertFalse(HrApprovalChainResolver::isHrOnlyRequestType('attendance_correction'));
    }

    public function test_leave_can_be_switched_to_hr_only(): void
    {
        ApprovalWorkflowSetting::query()
            ->where('request_type', ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE)
            ->update(['use_hierarchy_approval' => false]);

        $service = app(ApprovalWorkflowSettingService::class);

        $this->assertFalse($service->usesHierarchyApproval('leave'));
    }

    public function test_list_settings_returns_all_modules(): void
    {
        $payload = app(ApprovalWorkflowSettingService::class)->listSettings();

        $this->assertCount(5, $payload['settings']);
        $this->assertSame(
            [
                'attendance_correction',
                'leave',
                'overtime',
                'change_schedule',
                'reports_request',
            ],
            collect($payload['settings'])->pluck('request_type')->all(),
        );
    }
}
