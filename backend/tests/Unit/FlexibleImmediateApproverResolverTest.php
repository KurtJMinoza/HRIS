<?php

namespace Tests\Unit;

use App\Models\ApprovalWorkflowSetting;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationPositionType;
use App\Models\OrganizationType;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\User;
use App\Services\FlexibleImmediateApproverResolver;
use App\Services\HrApprovalChainResolver;
use App\Services\OrganizationLeadershipAssignmentScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlexibleImmediateApproverResolverTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->tablesExist()) {
            $this->markTestSkipped('Flexible organization tables are not available.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
            $this->transactionStarted = false;
        }

        parent::tearDown();
    }

    public function test_employee_specific_immediate_leader_wins(): void
    {
        $employee = $this->user();
        $specificLeader = $this->user();
        $unitLeader = $this->user();
        $unit = $this->unit('Department');
        $this->leader($unit, $unitLeader, 'Department Leader');
        $this->assign($employee, $unit, $specificLeader);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertSame($specificLeader->id, $resolved['approver_id']);
        $this->assertSame('Immediate Leader approval', $resolved['approval_label']);
    }

    public function test_missing_section_leader_falls_back_to_nearest_parent_leader_when_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('overtime', true);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'overtime');

        $this->assertSame($departmentLeader->id, $resolved['approver_id']);
        $this->assertSame('Department Leader approval', $resolved['approval_label']);
    }

    public function test_missing_section_leader_routes_to_hr_only_when_fallback_disabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNull($resolved);
    }

    public function test_leave_uses_section_unit_head_before_department_head(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $sectionLeader = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($section, $sectionLeader, 'Section Leader');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNotNull($resolved);
        $this->assertSame($sectionLeader->id, $resolved['approver_id']);
        $this->assertSame('Section Leader approval', $resolved['approval_label']);
    }

    public function test_overtime_uses_section_unit_head_before_department_head(): void
    {
        $this->setWorkflowFallbackToParent('overtime', false);

        $employee = $this->user();
        $sectionLeader = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($section, $sectionLeader, 'Section Leader');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'overtime');

        $this->assertNotNull($resolved);
        $this->assertSame($sectionLeader->id, $resolved['approver_id']);
    }

    public function test_section_unit_team_lead_leave_routes_to_parent_department_head_before_hr(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $teamLead = $this->user();
        $departmentHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = $this->unit('Department', null, [
            'legacy_source_type' => 'department',
            'legacy_source_id' => 910001,
        ], 'department');
        $section = $this->unit('Section', $department, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 910002,
        ], 'section');
        $this->leader($department, $departmentHead, 'Department Head');
        $this->leader($section, $teamLead, 'Team Leader');
        $this->assign($teamLead, $section);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'leave', $teamLead);

        $this->assertCount(2, $chain);
        $this->assertSame($departmentHead->id, $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_section_unit_team_lead_overtime_routes_to_parent_department_head_before_hr(): void
    {
        $this->setWorkflowFallbackToParent('overtime', false);

        $teamLead = $this->user();
        $departmentHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = $this->unit('Department', null, [
            'legacy_source_type' => 'department',
            'legacy_source_id' => 910011,
        ], 'department');
        $section = $this->unit('Section', $department, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 910012,
        ], 'section');
        $this->leader($department, $departmentHead, 'Department Head');
        $this->leader($section, $teamLead, 'Team Leader');
        $this->assign($teamLead, $section);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'overtime', $teamLead);

        $this->assertCount(2, $chain);
        $this->assertSame($departmentHead->id, $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_section_unit_team_lead_leave_routes_to_department_head_when_leader_is_not_section_member(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $teamLead = $this->user();
        $departmentHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = Department::query()->create([
            'name' => 'Leave Parent Department '.Str::random(6),
            'department_head_id' => (int) $departmentHead->id,
        ]);
        $section = SectionUnit::query()->create([
            'name' => 'Leave Led Section '.Str::random(6),
            'department_id' => (int) $department->id,
            'status' => 'active',
        ]);
        DB::table('section_unit_team_leaders')->insert([
            'section_unit_id' => (int) $section->id,
            'employee_id' => (int) $teamLead->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'leave', $teamLead);

        $this->assertCount(2, $chain);
        $this->assertSame((int) $departmentHead->id, (int) $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_section_unit_team_lead_overtime_routes_to_department_head_when_leader_is_not_section_member(): void
    {
        $this->setWorkflowFallbackToParent('overtime', false);

        $teamLead = $this->user();
        $departmentHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = Department::query()->create([
            'name' => 'Overtime Parent Department '.Str::random(6),
            'department_head_id' => (int) $departmentHead->id,
        ]);
        $section = SectionUnit::query()->create([
            'name' => 'Overtime Led Section '.Str::random(6),
            'department_id' => (int) $department->id,
            'status' => 'active',
        ]);
        DB::table('section_unit_team_leaders')->insert([
            'section_unit_id' => (int) $section->id,
            'employee_id' => (int) $teamLead->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'overtime', $teamLead);

        $this->assertCount(2, $chain);
        $this->assertSame((int) $departmentHead->id, (int) $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_section_unit_team_lead_leave_routes_to_hr_when_parent_department_head_missing(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $teamLead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = $this->unit('Department', null, [
            'legacy_source_type' => 'department',
            'legacy_source_id' => 910021,
        ], 'department');
        $section = $this->unit('Section', $department, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 910022,
        ], 'section');
        $this->leader($section, $teamLead, 'Team Leader');
        $this->assign($teamLead, $section);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'leave', $teamLead);

        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_section_unit_team_lead_attendance_correction_still_routes_to_hr_only(): void
    {
        $this->setWorkflowFallbackToParent('attendance_correction', false);

        $teamLead = $this->user();
        $departmentHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);

        $department = $this->unit('Department', null, [
            'legacy_source_type' => 'department',
            'legacy_source_id' => 910031,
        ], 'department');
        $section = $this->unit('Section', $department, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 910032,
        ], 'section');
        $this->leader($department, $departmentHead, 'Department Head');
        $this->leader($section, $teamLead, 'Team Leader');
        $this->assign($teamLead, $section);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($teamLead, 'attendance_correction', $teamLead);

        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_assigned_team_leader_wins_over_section_and_department_heads_for_leave(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $assignedTeamLeader = $this->user();
        $sectionLeader = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($section, $sectionLeader, 'Section Leader');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);
        $employee->forceFill(['assigned_team_leader_id' => $assignedTeamLeader->id])->save();

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNotNull($resolved);
        $this->assertSame($assignedTeamLeader->id, $resolved['approver_id']);
        $this->assertSame('Team Leader approval', $resolved['approval_label']);
        $this->assertSame('assigned_team_leader', $resolved['selected_approver_source']);
    }

    public function test_leave_chain_uses_assigned_team_leader_before_hr(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $assignedTeamLeader = $this->user();
        $departmentLeader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);
        $employee->forceFill(['assigned_team_leader_id' => $assignedTeamLeader->id])->save();

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertCount(2, $chain);
        $this->assertSame($assignedTeamLeader->id, $chain[0]['approver_id']);
        $this->assertSame('Team Leader approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_overtime_chain_uses_assigned_team_leader_before_hr(): void
    {
        $this->setWorkflowFallbackToParent('overtime', false);

        $employee = $this->user();
        $assignedTeamLeader = $this->user();
        $departmentLeader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);
        $employee->forceFill(['assigned_team_leader_id' => $assignedTeamLeader->id])->save();

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'overtime', $employee);

        $this->assertCount(2, $chain);
        $this->assertSame($assignedTeamLeader->id, $chain[0]['approver_id']);
        $this->assertSame('Team Leader approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_missing_team_and_section_leaders_use_department_head_when_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($department, $departmentLeader, 'Department Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNotNull($resolved);
        $this->assertSame($departmentLeader->id, $resolved['approver_id']);
        $this->assertSame('Department Leader approval', $resolved['approval_label']);
    }

    public function test_section_directory_head_wins_over_department_head_when_parent_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $sectionHead = $this->user();
        $departmentLeader = $this->user();
        $divisionLegacyId = 93097;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'Directory Dept');
        $section = SectionUnit::query()->create([
            'name' => 'Probe Section '.Str::random(4),
            'code' => 'PS'.random_int(1000, 9999),
            'department_id' => $department['id'],
            'division_id' => $divisionLegacyId,
            'section_unit_head_id' => $sectionHead->id,
            'status' => 'active',
        ]);
        $sectionOrgUnit = $this->unit('Section Org', $department['unit'], [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => $section->id,
        ], 'section');
        $this->leader($department['unit'], $departmentLeader, 'Department Leader');
        $employee->forceFill([
            'section_unit_id' => $section->id,
            'department_id' => $department['id'],
        ])->save();
        $this->assign($employee, $sectionOrgUnit);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNotNull($resolved);
        $this->assertSame($sectionHead->id, $resolved['approver_id']);
        $this->assertSame('Section/Unit Head approval', $resolved['approval_label']);
        $this->assertSame('section_unit_directory_head', $resolved['selected_approver_source']);
        $this->assertCount(2, $chain);
        $this->assertSame($sectionHead->id, $chain[0]['approver_id']);
        $this->assertNotSame($departmentLeader->id, $chain[0]['approver_id']);
    }

    public function test_ineligible_section_head_falls_back_to_department_head_when_parent_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $inactiveSectionHead = $this->user(['is_active' => false]);
        $departmentLeader = $this->user();
        $divisionLegacyId = 93099;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'Fallback Dept');
        $section = SectionUnit::query()->create([
            'name' => 'Inactive Head Section '.Str::random(4),
            'code' => 'IH'.random_int(1000, 9999),
            'department_id' => $department['id'],
            'division_id' => $divisionLegacyId,
            'section_unit_head_id' => $inactiveSectionHead->id,
            'status' => 'active',
        ]);
        $this->leader($department['unit'], $departmentLeader, 'Department Head');
        Department::query()->whereKey($department['id'])->update(['department_head_id' => (int) $departmentLeader->id]);
        $employee->forceFill([
            'section_unit_id' => $section->id,
            'department_id' => $department['id'],
        ])->save();

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNotNull($resolved);
        $this->assertSame($departmentLeader->id, $resolved['approver_id']);
        $this->assertSame('Department Head approval', $resolved['approval_label']);
        $this->assertCount(2, $chain);
        $this->assertSame($departmentLeader->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_employee_without_section_unit_routes_to_department_head_when_parent_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 93100;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'No Section Dept');
        Department::query()->whereKey($department['id'])->update(['department_head_id' => (int) $departmentLeader->id]);
        $employee->forceFill([
            'section_unit_id' => null,
            'department_id' => $department['id'],
        ])->save();

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNotNull($resolved);
        $this->assertSame($departmentLeader->id, $resolved['approver_id']);
        $this->assertCount(2, $chain);
        $this->assertSame($departmentLeader->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_direct_department_employee_leave_uses_department_head_when_parent_fallback_disabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 93101;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'Direct Leave Dept');
        Department::query()->whereKey($department['id'])->update(['department_head_id' => (int) $departmentLeader->id]);
        $employee->forceFill([
            'section_unit_id' => null,
            'department_id' => $department['id'],
        ])->save();

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertCount(2, $chain);
        $this->assertSame($departmentLeader->id, $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_direct_department_employee_overtime_uses_department_head_when_parent_fallback_disabled(): void
    {
        $this->setWorkflowFallbackToParent('overtime', false);

        $employee = $this->user();
        $departmentLeader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 93102;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'Direct Overtime Dept');
        Department::query()->whereKey($department['id'])->update(['department_head_id' => (int) $departmentLeader->id]);
        $employee->forceFill([
            'section_unit_id' => null,
            'department_id' => $department['id'],
        ])->save();

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'overtime', $employee);

        $this->assertCount(2, $chain);
        $this->assertSame($departmentLeader->id, $chain[0]['approver_id']);
        $this->assertSame('Department Head approval', $chain[0]['approval_label']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_legacy_section_directory_blocks_department_head_when_parent_fallback_enabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $sectionHead = $this->user();
        $departmentLeader = $this->user();
        $divisionLegacyId = 93098;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $department = $this->createLegacyDepartment($divisionLegacyId, $division, 'Legacy Directory Dept');
        $section = SectionUnit::query()->create([
            'name' => 'Legacy Section '.Str::random(4),
            'code' => 'LS'.random_int(1000, 9999),
            'department_id' => $department['id'],
            'division_id' => $divisionLegacyId,
            'section_unit_head_id' => $sectionHead->id,
            'status' => 'active',
        ]);
        $this->leader($department['unit'], $departmentLeader, 'Department Leader');
        $employee->forceFill([
            'section_unit_id' => $section->id,
            'department_id' => $department['id'],
        ])->save();

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNotNull($resolved);
        $this->assertSame($sectionHead->id, $resolved['approver_id']);
        $this->assertSame('section_unit_directory_head', $resolved['selected_approver_source'] ?? null);
        $this->assertNotSame($departmentLeader->id, $resolved['approver_id']);
    }

    public function test_section_unit_head_same_as_requester_skips_to_hr_when_fallback_disabled(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $employee = $this->user();
        $department = $this->unit('Department');
        $section = $this->unit('Section', $department, [], 'section');
        $this->leader($section, $employee, 'Section Leader');
        $this->assign($employee, $section);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertNull($resolved);
    }

    public function test_requester_leading_own_unit_skips_attendance_correction_hierarchy(): void
    {
        $employee = $this->user();
        $companyLeader = $this->user();
        $company = $this->unit('Company');
        $department = $this->unit('Department', $company);
        $this->leader($department, $employee, 'Department Leader');
        $this->leader($company, $companyLeader, 'Company Leader');
        $this->assign($employee, $department);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'attendance_correction');

        $this->assertNull($resolved);
    }

    public function test_requester_leading_own_unit_falls_back_to_parent_leader_for_leave(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $companyLeader = $this->user();
        $company = $this->unit('Company');
        $department = $this->unit('Department', $company);
        $this->leader($department, $employee, 'Department Leader');
        $this->leader($company, $companyLeader, 'Company Leader');
        $this->assign($employee, $department);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertSame($companyLeader->id, $resolved['approver_id']);
        $this->assertSame('Company Leader approval', $resolved['approval_label']);
    }

    public function test_attendance_correction_chain_routes_directly_to_hr_only(): void
    {
        $employee = $this->user();
        $leader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $department = $this->unit('Department');
        $this->leader($department, $leader, 'Department Leader');
        $this->assign($employee, $department);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain(
            $employee,
            'attendance_correction',
            $employee,
        );

        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_department_head_attendance_correction_chain_routes_directly_to_hr_only(): void
    {
        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92007;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']], 'leave');

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain(
            $deptHead,
            'attendance_correction',
            $deptHead,
        );

        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_leave_chain_still_uses_scoped_division_head(): void
    {
        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92008;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']], 'leave');

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($deptHead, 'leave', $deptHead);

        $this->assertCount(2, $chain);
        $this->assertSame($divHead->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_overtime_chain_still_uses_immediate_leader(): void
    {
        $employee = $this->user();
        $leader = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $department = $this->unit('Department');
        $this->leader($department, $leader, 'Department Leader');
        $this->assign($employee, $department, $leader);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'overtime', $employee);

        $this->assertGreaterThanOrEqual(2, count($chain));
        $this->assertSame($leader->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[count($chain) - 1]['approval_level']);
    }

    public function test_no_leader_returns_null_and_chain_goes_to_hr_only(): void
    {
        $employee = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $unit = $this->unit('Project Team');
        $this->assign($employee, $unit);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNull($resolved);
        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_same_employee_can_lead_multiple_units_and_custom_team_still_resolves(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $leader = $this->user();
        $company = $this->unit('Company');
        $department = $this->unit('Department', $company);
        $projectTeam = $this->unit('Project Team', $company, [], 'custom');

        $this->leader($department, $leader, 'Department Leader');
        $this->leader($projectTeam, $leader, 'Project Team Leader');
        $this->assign($employee, $projectTeam);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertSame($leader->id, $resolved['approver_id']);
        $this->assertSame('Project Team Leader approval', $resolved['approval_label']);
        $this->assertSame(2, OrganizationUnitLeader::query()->where('employee_id', $leader->id)->count());
    }

    public function test_company_head_and_co_company_head_can_both_exist(): void
    {
        $employee = $this->user();
        $head = $this->user();
        $coHead = $this->user();
        $company = $this->unit('Company', null, [], 'company');
        $headType = $this->positionType('company', 'Company Head', 1);
        $coHeadType = $this->positionType('company', 'Co-Company Head', 2);

        $this->positionAssignment($company, $headType, $head, true, 1);
        $this->positionAssignment($company, $coHeadType, $coHead, false, 2);
        $this->assign($employee, $company);

        $this->assertSame(2, OrganizationPositionAssignment::query()->where('organization_unit_id', $company->id)->active()->count());
    }

    public function test_department_head_and_team_leader_same_employee_is_allowed(): void
    {
        $employee = $this->user();
        $leader = $this->user();
        $department = $this->unit('Department', null, [], 'department');
        $headType = $this->positionType('department', 'Department Head', 1);
        $teamType = $this->positionType('department', 'Team Leader', 3);

        $this->positionAssignment($department, $headType, $leader, true, 1);
        $this->positionAssignment($department, $teamType, $leader, false, 3);
        $this->assign($employee, $department);

        $this->assertSame(2, OrganizationPositionAssignment::query()->where('employee_id', $leader->id)->active()->count());
    }

    public function test_multiple_leaders_use_approval_priority_and_primary(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $primary = $this->user();
        $secondary = $this->user();
        $department = $this->unit('Department', null, [], 'department');
        $headType = $this->positionType('department', 'Department Head', 1);
        $teamType = $this->positionType('department', 'Team Leader', 5);

        $this->positionAssignment($department, $teamType, $secondary, false, 5);
        $this->positionAssignment($department, $headType, $primary, true, 1);
        $this->assign($employee, $department);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave');

        $this->assertSame($primary->id, $resolved['approver_id']);
    }

    public function test_department_head_routes_to_scoped_division_head_before_hr(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92001;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']]);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);

        $this->assertNotNull($resolved);
        $this->assertSame($divHead->id, $resolved['approver_id']);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($deptHead, 'leave', $deptHead);
        $this->assertCount(2, $chain);
        $this->assertSame($divHead->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_department_head_outside_scope_routes_directly_to_hr(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92002;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');
        $hrDept = $this->createLegacyDepartment($divisionLegacyId, $division, 'HR');

        $this->assignDepartmentHead($deptHead, $hrDept['unit'], $hrDept['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']]);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($deptHead, 'leave', $deptHead);

        $this->assertNull($resolved);
        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_alternate_division_head_can_cover_different_department_scope(): void
    {
        $deptHead = $this->user();
        $lelis = $this->user();
        $otherHead = $this->user();
        $divisionLegacyId = 92003;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');
        $hrDept = $this->createLegacyDepartment($divisionLegacyId, $division, 'HR');

        $this->assignDepartmentHead($deptHead, $hrDept['unit'], $hrDept['id']);
        $this->divisionHeadScope($division, $lelis, $divisionLegacyId, 'selected', [$finance['id']], 'all', 1);
        $this->divisionHeadScope($division, $otherHead, $divisionLegacyId, 'selected', [$hrDept['id']], 'all', 2);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);

        $this->assertNotNull($resolved);
        $this->assertSame($otherHead->id, $resolved['approver_id']);
    }

    public function test_division_head_with_all_departments_scope_routes_any_department_head(): void
    {
        $deptHead = $this->user();
        $divHead = $this->user();
        $divisionLegacyId = 92004;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $operations = $this->createLegacyDepartment($divisionLegacyId, $division, 'Operations');

        $this->assignDepartmentHead($deptHead, $operations['unit'], $operations['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'all');

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);

        $this->assertNotNull($resolved);
        $this->assertSame($divHead->id, $resolved['approver_id']);
    }

    public function test_division_head_with_no_department_scope_is_not_used(): void
    {
        $this->setWorkflowFallbackToParent('leave', false);

        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92005;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'none');

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);

        $this->assertNull($resolved);
    }

    public function test_department_head_with_no_scope_and_parent_fallback_on_routes_to_hr_only(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $deptHead = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92007;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'none');
        Division::query()->whereKey($divisionLegacyId)->update(['division_head_id' => $divHead->id]);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($deptHead, 'leave', $deptHead);

        $this->assertNull($resolved);
        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_parent_fallback_skips_unscoped_division_head_when_department_scope_none(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $divHead = $this->user();
        $this->user(['role' => User::ROLE_ADMIN, 'is_super_admin' => true]);
        $divisionLegacyId = 92008;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assign($employee, $finance['unit']);
        $employee->forceFill(['department_id' => $finance['id']])->save();
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'none');
        Division::query()->whereKey($divisionLegacyId)->update(['division_head_id' => $divHead->id]);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave', $employee);
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNull($resolved);
        $this->assertCount(1, $chain);
        $this->assertSame('admin_hr', $chain[0]['approval_level']);
    }

    public function test_parent_fallback_uses_scoped_division_head_when_department_is_selected(): void
    {
        $this->setWorkflowFallbackToParent('leave', true);

        $employee = $this->user();
        $divHead = $this->user();
        $divisionLegacyId = 92009;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assign($employee, $finance['unit']);
        $employee->forceFill(['department_id' => $finance['id']])->save();
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']]);

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($employee, 'leave', $employee);
        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain($employee, 'leave', $employee);

        $this->assertNotNull($resolved);
        $this->assertSame($divHead->id, $resolved['approver_id']);
        $this->assertCount(2, $chain);
        $this->assertSame($divHead->id, $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_division_head_scope_applies_only_to_configured_request_type(): void
    {
        $deptHead = $this->user();
        $divHead = $this->user();
        $divisionLegacyId = 92006;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'selected', [$finance['id']], 'leave');

        $leaveResolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);
        $correctionResolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover(
            $deptHead,
            'attendance_correction',
            $deptHead,
        );

        $this->assertNotNull($leaveResolved);
        $this->assertSame($divHead->id, $leaveResolved['approver_id']);
        $this->assertNull($correctionResolved);
    }

    public function test_cross_company_division_head_with_scope_is_used(): void
    {
        $deptHead = $this->user();
        $divHead = $this->user();
        $divisionLegacyId = 9001;
        $branch = $this->unit('Branch', null, [], 'branch');
        $division = $this->unit('Division', $branch, [
            'legacy_source_type' => 'division',
            'legacy_source_id' => $divisionLegacyId,
        ], 'division');
        $finance = $this->createLegacyDepartment($divisionLegacyId, $division, 'Finance');

        $this->assignDepartmentHead($deptHead, $finance['unit'], $finance['id']);
        $this->divisionHeadScope($division, $divHead, $divisionLegacyId, 'all');

        $resolved = app(FlexibleImmediateApproverResolver::class)->resolveImmediateApprover($deptHead, 'leave', $deptHead);

        $this->assertNotNull($resolved);
        $this->assertSame($divHead->id, $resolved['approver_id']);
    }

    private function createLegacyDivision(int $divisionLegacyId, string $name = 'AWIC'): void
    {
        if (Division::query()->whereKey($divisionLegacyId)->exists()) {
            return;
        }

        Division::query()->insert([
            'id' => $divisionLegacyId,
            'name' => $name,
            'company_id' => null,
            'branch_id' => null,
            'division_head_id' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLegacyDepartment(int $divisionLegacyId, OrganizationUnit $divisionUnit, string $name): array
    {
        static $nextDepartmentId = 93000;
        do {
            $departmentId = $nextDepartmentId++;
        } while (Department::query()->whereKey($departmentId)->exists());

        $this->createLegacyDivision($divisionLegacyId);

        Department::query()->insert([
            'id' => $departmentId,
            'name' => $name,
            'company_id' => null,
            'branch_id' => null,
            'division_id' => $divisionLegacyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = $this->unit('Department', $divisionUnit, [
            'legacy_source_type' => 'department',
            'legacy_source_id' => $departmentId,
        ], 'department');

        return [
            'id' => $departmentId,
            'unit' => $unit,
            'name' => $name,
        ];
    }

    private function assignDepartmentHead(User $deptHead, OrganizationUnit $departmentUnit, int $departmentLegacyId): void
    {
        $this->leader($departmentUnit, $deptHead, 'Department Head');
        $this->assign($deptHead, $departmentUnit);
        Department::query()->whereKey($departmentLegacyId)->update(['department_head_id' => (int) $deptHead->id]);
        $deptHead->forceFill(['department_id' => $departmentLegacyId])->save();
    }

    private function divisionHeadScope(
        OrganizationUnit $divisionUnit,
        User $divHead,
        int $divisionLegacyId,
        string $mode,
        array $departmentIds = [],
        string $requestType = 'all',
        int $priority = 1,
    ): OrganizationPositionAssignment {
        $headType = $this->positionType('division', 'Division Head', $priority);
        $assignment = $this->positionAssignment($divisionUnit, $headType, $divHead, $priority === 1, $priority);

        app(OrganizationLeadershipAssignmentScopeService::class)->syncAssignmentScopes(
            $assignment,
            [
                'department_scope_mode' => $mode,
                'department_scope_ids' => $departmentIds,
                'scope_request_type' => $requestType,
            ],
            'division',
            $divisionLegacyId,
        );

        return $assignment;
    }

    private function positionType(string $level, string $name, int $priority): OrganizationPositionType
    {
        return OrganizationPositionType::query()->firstOrCreate(
            [
                'organization_level' => $level,
                'position_name' => $name,
            ],
            [
                'approval_priority' => $priority,
                'can_approve' => true,
                'is_final_approver' => false,
                'is_active' => true,
            ],
        );
    }

    private function positionAssignment(
        OrganizationUnit $unit,
        OrganizationPositionType $type,
        User $leader,
        bool $primary,
        int $priority,
    ): OrganizationPositionAssignment {
        return OrganizationPositionAssignment::query()->create([
            'organization_level' => $type->organization_level,
            'organization_unit_id' => $unit->id,
            'position_type_id' => $type->id,
            'employee_id' => $leader->id,
            'is_primary' => $primary,
            'approval_priority' => $priority,
            'effective_from' => null,
            'effective_to' => null,
            'is_active' => true,
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'employment_status' => 'regular',
        ], $attributes));
    }

    public function test_shared_section_assignment_context_routes_leave_to_shared_section_leader(): void
    {
        $employee = $this->user();
        $primaryLeader = $this->user();
        $sharedLeader = $this->user();

        $primarySection = $this->unit('Primary Section', null, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 900001,
        ], 'section');
        $sharedSection = $this->unit('Shared Section', null, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 900002,
        ], 'section');

        $this->leader($primarySection, $primaryLeader, 'Section Leader');
        $this->leader($sharedSection, $sharedLeader, 'Section Leader');

        $this->assign($employee, $primarySection);
        $sharedAssignment = EmployeeOrganizationAssignment::query()->create([
            'employee_id' => (int) $employee->id,
            'organization_unit_id' => (int) $sharedSection->id,
            'assignment_type' => EmployeeOrganizationAssignment::TYPE_SHARED,
            'section_unit_id' => 900002,
            'is_primary' => false,
            'effective_from' => null,
            'effective_to' => null,
            'is_active' => true,
        ]);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain(
            $employee,
            'leave',
            $employee,
            ['assignment_id' => (int) $sharedAssignment->id],
        );

        $this->assertSame((int) $sharedLeader->id, (int) $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    public function test_primary_assignment_context_routes_leave_to_primary_section_leader(): void
    {
        $employee = $this->user();
        $primaryLeader = $this->user();
        $sharedLeader = $this->user();

        $primarySection = $this->unit('Primary Section', null, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 900011,
        ], 'section');
        $sharedSection = $this->unit('Shared Section', null, [
            'legacy_source_type' => 'section_unit',
            'legacy_source_id' => 900012,
        ], 'section');

        $this->leader($primarySection, $primaryLeader, 'Section Leader');
        $this->leader($sharedSection, $sharedLeader, 'Section Leader');

        $primaryAssignment = $this->assign($employee, $primarySection);
        EmployeeOrganizationAssignment::query()->create([
            'employee_id' => (int) $employee->id,
            'organization_unit_id' => (int) $sharedSection->id,
            'assignment_type' => EmployeeOrganizationAssignment::TYPE_SHARED,
            'section_unit_id' => 900012,
            'is_primary' => false,
            'effective_from' => null,
            'effective_to' => null,
            'is_active' => true,
        ]);

        $chain = app(HrApprovalChainResolver::class)->resolveApprovalChain(
            $employee,
            'leave',
            $employee,
            ['assignment_id' => (int) $primaryAssignment->id],
        );

        $this->assertSame((int) $primaryLeader->id, (int) $chain[0]['approver_id']);
        $this->assertSame('admin_hr', $chain[1]['approval_level']);
    }

    private function type(string $name, string $code = 'custom'): OrganizationType
    {
        return OrganizationType::query()->create([
            'name' => $name,
            'code' => 'test_'.Str::slug($code, '_').'_'.Str::lower(Str::random(8)),
            'level_order' => 100,
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function unit(string $name, ?OrganizationUnit $parent = null, array $attributes = [], string $typeCode = 'custom'): OrganizationUnit
    {
        $type = $this->type($name, $typeCode);

        return OrganizationUnit::query()->create(array_merge([
            'organization_type_id' => $type->id,
            'parent_id' => $parent?->id,
            'company_id' => null,
            'name' => $name.' '.Str::random(5),
            'code' => null,
            'description' => null,
            'is_active' => true,
            'approval_routing_rule' => OrganizationUnit::ROUTING_FIRST_ASSIGNED,
            'sort_order' => 0,
        ], $attributes));
    }

    private function leader(OrganizationUnit $unit, User $leader, string $role, bool $primary = true, int $priority = 1): OrganizationUnitLeader
    {
        return OrganizationUnitLeader::query()->create([
            'organization_unit_id' => $unit->id,
            'employee_id' => $leader->id,
            'leader_role' => $role,
            'is_primary' => $primary,
            'approval_priority' => $priority,
            'is_active' => true,
        ]);
    }

    private function assign(User $employee, OrganizationUnit $unit, ?User $leader = null): EmployeeOrganizationAssignment
    {
        return EmployeeOrganizationAssignment::query()->create([
            'employee_id' => $employee->id,
            'organization_unit_id' => $unit->id,
            'is_primary' => true,
            'immediate_leader_id' => $leader?->id,
            'effective_from' => null,
            'effective_to' => null,
            'is_active' => true,
        ]);
    }

    private function tablesExist(): bool
    {
        try {
            return Schema::hasTable('organization_types')
                && Schema::hasTable('organization_units')
                && Schema::hasTable('organization_unit_leaders')
                && Schema::hasTable('organization_position_types')
                && Schema::hasTable('organization_position_assignments')
                && Schema::hasTable('employee_organization_assignments')
                && Schema::hasTable('organization_leadership_assignment_scopes')
                && Schema::hasTable('approval_workflow_settings')
                && Schema::hasTable('departments')
                && Schema::hasTable('users');
        } catch (\Throwable) {
            return false;
        }
    }

    private function setWorkflowFallbackToParent(string $requestType, bool $enabled): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        app(\App\Services\ApprovalWorkflowSettingService::class)->ensureDefaults();

        ApprovalWorkflowSetting::query()
            ->where('request_type', $requestType)
            ->update([
                'use_hierarchy_approval' => true,
                'fallback_to_parent_approver' => $enabled,
            ]);
    }
}
