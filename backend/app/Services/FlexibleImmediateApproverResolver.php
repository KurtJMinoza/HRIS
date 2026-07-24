<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Area;
use App\Models\ApprovalWorkflowSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\EmployeeOrganizationAssignment;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\OrganizationUnitLeader;
use App\Models\SectionUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FlexibleImmediateApproverResolver
{
    private const HIERARCHY_ORDER = ['section_unit', 'department', 'division', 'branch', 'area', 'company'];

    public function __construct(
        private readonly LegacyOrganizationMirrorService $legacyOrganizationMirrorService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly OrganizationLeadershipAssignmentService $leadershipAssignments,
        private readonly OrganizationLeadershipAssignmentScopeService $assignmentScopeService,
        private readonly ApprovalWorkflowSettingService $workflowSettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function resolveImmediateApprover(
        User|int $employee,
        ?string $requestType = null,
        ?User $requestor = null,
        array $usedApproverIds = [],
        array $context = [],
    ): ?array {
        $hasFlexibleOrg = Schema::hasTable('organization_units');

        if ($this->workflowSettingService->isHrOnlyRequestType($requestType, $context)) {
            $this->log($context, 'hierarchy disabled by workflow setting; immediate approver skipped', [
                'request_type' => $this->workflowSettingService->normalizeRequestType($requestType),
                'skip_reason' => 'workflow_setting_hierarchy_off',
            ]);

            return null;
        }

        $subject = $employee instanceof User
            ? $employee->loadMissing(['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'])
            : User::query()
                ->with(['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'])
                ->findOrFail($employee);

        $requestorUser = $requestor ?? $subject;
        $skipIds = array_values(array_unique([
            (int) $subject->id,
            (int) $requestorUser->id,
            ...array_map('intval', $usedApproverIds),
        ]));

        $hierarchy = $this->buildLegacyHierarchy($subject, $context);
        $requesterLevel = $this->detectRequesterLevel($requestorUser, $hierarchy);
        $workflowSetting = $this->workflowSettingService->resolveSetting($requestType, $context);
        $fallbackToParent = (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false);
        $primaryAssignment = $this->selectedAssignmentFor($subject, $context);
        $employeeImmediateLeaderId = $this->employeeImmediateLeaderId($subject, $primaryAssignment);
        $sectionDirectoryProbe = $this->probeSectionDirectoryLeadership($subject, $hierarchy);
        $directlyAssignedToDepartment = $this->isDirectDepartmentAssignment($subject, $primaryAssignment, $hierarchy);

        $this->log($context, 'resolver start', [
            'request_type' => $requestType,
            'requester_employee_id' => (int) $requestorUser->id,
            'requester_name' => $requestorUser->display_name,
            'subject_employee_id' => (int) $subject->id,
            'requester_primary_company_id' => $subject->company_id ? (int) $subject->company_id : null,
            'requester_primary_department_id' => $subject->department_id ? (int) $subject->department_id : null,
            'request_assignment_id' => $context['assignment_id'] ?? null,
            'request_assignment_type' => $context['assignment_type'] ?? null,
            'request_company_id' => $context['company_id'] ?? null,
            'request_department_id' => $context['department_id'] ?? null,
            'request_section_unit_id' => $context['section_unit_id'] ?? null,
            'requester_department_id' => $hierarchy['department'] ?? null,
            'requester_division_id' => $hierarchy['division'] ?? null,
            'requester_section_unit_id' => $hierarchy['section_unit'] ?? null,
            'employee_immediate_leader_id' => $employeeImmediateLeaderId,
            'employee_team_leader_id' => $subject->assigned_team_leader_id ? (int) $subject->assigned_team_leader_id : null,
            'employee_assigned_team_leader_id' => $subject->assigned_team_leader_id ? (int) $subject->assigned_team_leader_id : null,
            'employee_supervisor_id' => $subject->supervisor_id ? (int) $subject->supervisor_id : null,
            'section_unit_head_found' => $sectionDirectoryProbe['section_unit_head_found'],
            'section_unit_team_leader_found' => $sectionDirectoryProbe['section_unit_team_leader_found'],
            'section_unit_head_id' => $sectionDirectoryProbe['section_unit_head_id'],
            'section_unit_team_leader_ids' => $sectionDirectoryProbe['section_unit_team_leader_ids'],
            'organization_ids' => $hierarchy,
            'assignment_id' => $primaryAssignment?->id ? (int) $primaryAssignment->id : null,
            'selected_assignment_id' => $primaryAssignment?->id ? (int) $primaryAssignment->id : null,
            'selected_assignment_type' => $primaryAssignment?->assignment_type,
            'selected_section_unit_id' => $primaryAssignment?->section_unit_id ? (int) $primaryAssignment->section_unit_id : null,
            'employee_directly_assigned_to_department' => $directlyAssignedToDepartment,
            'detected_requester_level' => $requesterLevel,
            'fallback_to_parent_approver' => $fallbackToParent,
            'department_head_fallback_allowed' => $fallbackToParent,
            'use_hierarchy_approval' => (bool) ($workflowSetting['use_hierarchy_approval'] ?? false),
            'flexible_organization_units_available' => $hasFlexibleOrg,
            'visibility_permissions_ignored_for_approval' => true,
        ]);

        if ($requesterLevel === 'company_head') {
            $this->log($context, 'requester is company head — no org approver before HR');

            return null;
        }

        $this->ensureLegacyUnitsForHierarchy($hierarchy, (int) $subject->id, $context, $hasFlexibleOrg);

        $requestorLeadsDeepestUnit = $hasFlexibleOrg
            ? $this->requestorLeadsDeepestUnit($requestorUser, $hierarchy)
            : $this->requestorLeadsLegacySectionUnit($requestorUser, $hierarchy);
        if (! $requestorLeadsDeepestUnit) {
            if ($employeeImmediateLeaderId !== null) {
                $leader = $this->validLeader($employeeImmediateLeaderId, $skipIds, $context, 'employee_immediate_leader');
                if ($leader) {
                    $this->log($context, 'selected immediate approver from employee-specific leader override', [
                        'approver_id' => (int) $leader->id,
                        'approver_name' => $leader->display_name,
                        'selected_first_approver' => $leader->display_name,
                        'selected_approver_source' => 'employee_immediate_leader',
                    ]);

                    return $this->formatResult(
                        $leader,
                        [$leader],
                        'Immediate Leader',
                        OrganizationUnit::ROUTING_SPECIFIC_PER_EMPLOYEE,
                        $primaryAssignment?->organizationUnit,
                        'immediate_leader',
                    );
                }

                $this->log($context, 'employee-specific immediate leader override skipped', [
                    'immediate_leader_id' => $employeeImmediateLeaderId,
                    'skip_reason' => 'employee_immediate_leader_ineligible',
                ]);
            }

            $assignedTeamLeader = $this->resolveAssignedTeamLeader($subject, $skipIds, $context);
            if ($assignedTeamLeader !== null) {
                return $assignedTeamLeader;
            }
        } else {
            $this->log($context, 'skipping employee-specific immediate leader and assigned team leader — requester leads current unit');
        }

        $unitChain = $hasFlexibleOrg
            ? $this->buildOrganizationUnitChain($subject, $hierarchy, $context)
            : collect();

        $sectionLeaderContext = $this->sectionLeaderRequestContext($requestorUser, $hierarchy, $context);
        if ($this->usesDepartmentScopedDivisionHead($requestType) && (bool) $sectionLeaderContext['is_team_leader']) {
            $departmentResolved = $this->resolveParentDepartmentHeadForSectionLeaderRequest(
                $subject,
                $requestorUser,
                $hierarchy,
                $requestType,
                $skipIds,
                $context,
                $sectionLeaderContext,
            );

            if ($departmentResolved !== null) {
                $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($departmentResolved, $fallbackToParent));

                return $departmentResolved;
            }

            return null;
        }

        if (! $this->requestorIsDepartmentHead($requestorUser, $requesterLevel, $unitChain, $hierarchy)) {
            $directoryResolved = $this->resolveSectionDirectoryLeadership($subject, $hierarchy, $skipIds, $context);
            if ($directoryResolved !== null) {
                $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($directoryResolved, $fallbackToParent));

                return $directoryResolved;
            }

            if ($hasFlexibleOrg) {
                $sectionResolved = $this->resolveSectionUnitHeadForEmployee(
                    $subject,
                    $hierarchy,
                    $requestType,
                    $skipIds,
                    $context,
                );

                if ($sectionResolved !== null) {
                    $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($sectionResolved, $fallbackToParent));

                    return $sectionResolved;
                }
            }

            if ($directlyAssignedToDepartment && $this->usesDepartmentFirstApproval($requestType)) {
                $departmentResolved = $this->resolveDepartmentHeadForRequester(
                    $subject,
                    $hierarchy,
                    $skipIds,
                    $context,
                    $requestType,
                    'direct_department_assignment',
                    $primaryAssignment,
                    true,
                );
                if ($departmentResolved !== null) {
                    $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($departmentResolved, $fallbackToParent));

                    return $departmentResolved;
                }
            }

            if (! $fallbackToParent) {
                $this->log($context, 'no team leader or section/unit head and parent fallback disabled — routing to HR/Admin only', [
                    'requester_section_unit_id' => $hierarchy['section_unit'] ?? null,
                    'requester_department_id' => $hierarchy['department'] ?? null,
                    'assignment_id' => $primaryAssignment?->id ? (int) $primaryAssignment->id : null,
                    'employee_directly_assigned_to_department' => $directlyAssignedToDepartment,
                    'employee_assigned_team_leader_id' => $subject->assigned_team_leader_id ? (int) $subject->assigned_team_leader_id : null,
                    'team_leader_found' => false,
                    'section_unit_head_found' => false,
                    'section_unit_team_leader_found' => false,
                    'department_head_found' => false,
                    'department_head_fallback_allowed' => false,
                    'skip_reason' => 'missing_team_or_section_leader_no_parent_fallback',
                ]);

                return null;
            }

            $departmentResolved = $this->resolveDepartmentHeadForRequester(
                $subject,
                $hierarchy,
                $skipIds,
                $context,
                $requestType,
                'department_head_before_parent_walk',
                $primaryAssignment,
                false,
            );
            if ($departmentResolved !== null) {
                $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($departmentResolved, true));

                return $departmentResolved;
            }

            $this->log($context, 'no section/unit head found — continuing to parent approver fallback', [
                'fallback_to_parent_approver' => true,
                'department_head_fallback_allowed' => true,
            ]);
        }

        if ($this->requestorIsDepartmentHead($requestorUser, $requesterLevel, $unitChain, $hierarchy)) {
            $divisionId = $this->resolveDivisionId($hierarchy, $unitChain);
            $departmentId = (int) ($hierarchy['department'] ?? 0);
            $scopeContext = $this->mergeScopeContext($context, $requestorUser, $hierarchy, $requestType);

            $this->log($scopeContext, 'department head request — evaluating scoped division heads', [
                'requester_employee_id' => (int) $requestorUser->id,
                'requester_department_id' => $departmentId > 0 ? $departmentId : null,
                'requester_division_id' => $divisionId > 0 ? $divisionId : null,
                'request_type' => $requestType,
            ]);

            if ($divisionId > 0) {
                $scoped = $this->assignmentScopeService->resolveScopedDivisionHead(
                    $divisionId,
                    $departmentId,
                    $requestType,
                    $skipIds,
                    $scopeContext,
                );

                if ($scoped !== null) {
                    $resolved = $this->formatResult(
                        $scoped['employee'],
                        [$scoped['employee']],
                        $scoped['leader_role'],
                        OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                        $scoped['unit'],
                        $this->approvalLevelFor($scoped['unit'], $scoped['leader_role'], 'division'),
                    );

                    $this->log($scopeContext, 'final selected first approver', [
                        'approver_id' => $resolved['approver_id'],
                        'approver_name' => $resolved['approver_name'],
                        'approval_level' => $resolved['approval_level'],
                        'leader_role' => $resolved['leader_role'],
                        'selected_approver_source' => 'scoped_division_head',
                    ]);

                    return $resolved;
                }
            }

            $this->log($scopeContext, 'no scoped division head matched — routing directly to HR/Admin', [
                'requester_department_id' => $departmentId > 0 ? $departmentId : null,
                'requester_division_id' => $divisionId > 0 ? $divisionId : null,
                'skip_reason' => 'division_head_not_in_department_scope',
            ]);

            return null;
        }

        if (! $fallbackToParent) {
            $this->log($context, 'parent approver fallback blocked — routing to HR/Admin only', [
                'fallback_to_parent_approver' => false,
                'department_head_fallback_allowed' => false,
                'skip_reason' => 'parent_fallback_disabled',
            ]);

            return null;
        }

        if (! $hasFlexibleOrg || $unitChain->isEmpty()) {
            $departmentResolved = $this->resolveDepartmentHeadForRequester(
                $subject,
                $hierarchy,
                $skipIds,
                $context,
                $requestType,
                'department_head_without_organization_units',
            );
            if ($departmentResolved !== null) {
                $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($departmentResolved, true));

                return $departmentResolved;
            }

            $this->log($context, 'parent approver fallback unavailable — flexible organization units missing', [
                'fallback_to_parent_approver' => true,
                'flexible_organization_units_available' => $hasFlexibleOrg,
                'skip_reason' => 'parent_fallback_requires_organization_units',
            ]);

            return null;
        }

        $startIndex = $this->startingParentFallbackUnitIndex($requesterLevel, $unitChain, $requestorUser, $hierarchy);
        $stopIndex = $unitChain->count() - 1;

        $this->log($context, 'starting parent leadership lookup', [
            'starting_lookup_level' => $this->startingLookupLevel($requesterLevel, $hierarchy),
            'starting_unit_index' => $startIndex,
            'unit_chain' => $unitChain->map(fn (OrganizationUnit $unit): array => [
                'organization_unit_id' => (int) $unit->id,
                'name' => $unit->name,
                'legacy_source_type' => $unit->legacy_source_type,
                'legacy_source_id' => $unit->legacy_source_id,
                'hierarchy_level' => $this->unitHierarchyLevel($unit),
            ])->all(),
        ]);

        if ($startIndex < 0 || $unitChain->isEmpty()) {
            $this->log($context, 'no organization unit chain available for leadership lookup');

            return null;
        }

        for ($i = $startIndex; $i <= $stopIndex && $i < $unitChain->count(); $i++) {
            /** @var OrganizationUnit $unit */
            $unit = $unitChain->get($i);
            if ($this->unitHierarchyLevel($unit) === 'section_unit') {
                continue;
            }

            if (
                $this->unitHierarchyLevel($unit) === 'department'
                && $this->shouldSkipDepartmentHeadFallback($requestType, $subject, $hierarchy, $skipIds, $context)
            ) {
                $this->log($context, 'skipping department head parent fallback — eligible section/unit directory approver exists', [
                    'request_type' => $requestType,
                    'requester_section_unit_id' => $hierarchy['section_unit'] ?? null,
                    'skip_reason' => 'eligible_section_directory_approver_exists',
                    'visibility_permissions_ignored_for_approval' => true,
                ]);

                continue;
            }

            $levelLog = [
                'organization_unit_id' => (int) $unit->id,
                'unit_name' => $unit->name,
                'legacy_type' => $unit->legacy_source_type,
                'legacy_id' => $unit->legacy_source_id,
                'hierarchy_level' => $this->unitHierarchyLevel($unit),
            ];

            $unitLevel = $this->unitHierarchyLevel($unit);
            $scopeContext = $this->mergeScopeContext($context, $requestorUser, $hierarchy, $requestType);
            $resolved = null;

            if ($unitLevel === 'division' && $this->usesDepartmentScopedDivisionHead($requestType)) {
                $resolved = $this->resolveScopedDivisionHeadForHierarchyUnit(
                    $unit,
                    $hierarchy,
                    $requestType,
                    $skipIds,
                    $scopeContext,
                    $levelLog,
                );

                if ($resolved === null) {
                    $this->log($scopeContext, 'skipped division head parent fallback — department scope mismatch or none', $levelLog + [
                        'requester_department_id' => $hierarchy['department'] ?? null,
                        'requester_division_id' => $hierarchy['division'] ?? null,
                        'skip_reason' => 'division_head_not_in_department_scope',
                    ]);

                    continue;
                }
            } elseif ((bool) $unit->is_active) {
                $resolved = $this->resolveFromUnitLeaders($unit, $subject, $requestType, $skipIds, $scopeContext, $levelLog);
            }

            if ($resolved === null && $unit->legacy_source_type && (int) $unit->legacy_source_id > 0) {
                if ($unitLevel === 'division' && $this->usesDepartmentScopedDivisionHead($requestType)) {
                    continue;
                }

                if (Schema::hasTable('organization_position_assignments') && $this->unitHasFlexibleApprovalAssignments($unit)) {
                    $this->log($scopeContext, 'legacy head fallback skipped — flexible approval assignments control routing', $levelLog + [
                        'skipped_reason' => 'flexible_assignment_scope_not_matched',
                    ]);

                    continue;
                }

                $resolved = $this->resolveFromLegacyHeadColumnDirect(
                    (string) $unit->legacy_source_type,
                    (int) $unit->legacy_source_id,
                    $unit,
                    $skipIds,
                    $context,
                    $levelLog,
                );
            }

            if ($resolved !== null) {
                $this->log($context, 'selected immediate approver', $levelLog + [
                    'approver_id' => $resolved['approver_id'],
                    'approver_name' => $resolved['approver_name'],
                    'approval_level' => $resolved['approval_level'],
                    'leader_role' => $resolved['leader_role'],
                    'department_head_found' => $this->unitHierarchyLevel($unit) === 'department',
                    'department_head_fallback_allowed' => $fallbackToParent && $this->unitHierarchyLevel($unit) === 'department',
                ]);

                $this->log($context, 'final selected first approver', [
                    'approver_id' => $resolved['approver_id'],
                    'approver_name' => $resolved['approver_name'],
                    'approval_level' => $resolved['approval_level'],
                    'leader_role' => $resolved['leader_role'],
                    'selected_approver_source' => 'parent_fallback',
                    'department_head_found' => $this->unitHierarchyLevel($unit) === 'department',
                ]);

                return $resolved;
            }
        }

        $departmentResolved = $this->resolveDepartmentHeadForRequester(
            $subject,
            $hierarchy,
            $skipIds,
            $context,
            $requestType,
            'department_head_after_parent_walk',
        );
        if ($departmentResolved !== null) {
            $this->log($context, 'final selected first approver', $this->finalApproverLogPayload($departmentResolved, $fallbackToParent));

            return $departmentResolved;
        }

        $this->log($context, 'no valid leadership assignment found in hierarchy walk', [
            'fallback_to_parent_approver' => $fallbackToParent,
            'department_head_found' => false,
            'department_head_selected' => false,
            'skip_reason' => 'no_eligible_department_head',
        ]);

        return null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveSectionUnitHeadForEmployee(
        User $subject,
        array $hierarchy,
        ?string $requestType,
        array $skipIds,
        array $context,
    ): ?array {
        $unit = $this->resolveSectionUnitOrganizationUnit($subject, $hierarchy, $context);
        if (! $unit) {
            $this->log($context, 'section/unit head lookup skipped — no section/unit organization context', [
                'requester_section_unit_id' => $hierarchy['section_unit'] ?? null,
                'skip_reason' => 'no_section_unit_assigned',
            ]);

            return null;
        }

        $sectionUnitId = (int) ($unit->legacy_source_id ?? 0);
        $levelLog = [
            'requester_section_unit_id' => $hierarchy['section_unit'] ?? ($sectionUnitId > 0 ? $sectionUnitId : null),
            'organization_unit_id' => (int) $unit->id,
            'unit_name' => $unit->name,
            'legacy_type' => $unit->legacy_source_type,
            'legacy_id' => $unit->legacy_source_id,
        ];

        if (Schema::hasTable('organization_position_assignments')) {
            $assignments = $unit->activePositionAssignments()
                ->with(['employee', 'positionType'])
                ->get()
                ->filter(fn (OrganizationPositionAssignment $assignment): bool => (bool) ($assignment->positionType?->can_approve ?? true));

            $this->log($context, 'section/unit leadership records found', $levelLog + [
                'position_assignment_count' => $assignments->count(),
                'position_assignments' => $assignments->map(fn (OrganizationPositionAssignment $row): array => [
                    'assignment_id' => (int) $row->id,
                    'employee_id' => (int) $row->employee_id,
                    'employee_name' => $row->employee?->display_name,
                    'position_name' => $row->positionType?->position_name,
                    'is_active' => (bool) $row->is_active,
                    'can_approve' => (bool) ($row->positionType?->can_approve ?? true),
                ])->all(),
            ]);
        }

        if ((bool) $unit->is_active) {
            $resolved = $this->resolveFromUnitLeaders($unit, $subject, $requestType, $skipIds, $context, $levelLog);
            if ($resolved !== null) {
                $resolved['selected_approver_source'] = 'section_unit_head';

                $this->log($context, 'selected section/unit head approver', $levelLog + [
                    'selected_section_unit_head_id' => $resolved['approver_id'],
                    'selected_section_unit_head_name' => $resolved['approver_name'],
                    'section_unit_head_found' => true,
                ]);

                return $resolved;
            }
        }

        if ($unit->legacy_source_type === 'section_unit' && (int) ($unit->legacy_source_id ?? 0) > 0) {
            $legacyResolved = $this->resolveFromLegacyHeadColumnDirect(
                'section_unit',
                (int) $unit->legacy_source_id,
                $unit,
                $skipIds,
                $context,
                $levelLog,
            );

            if ($legacyResolved !== null) {
                $legacyResolved['selected_approver_source'] = 'section_unit_head_legacy_column';

                $this->log($context, 'selected section/unit head approver from legacy head column', $levelLog + [
                    'selected_section_unit_head_id' => $legacyResolved['approver_id'],
                    'selected_section_unit_head_name' => $legacyResolved['approver_name'],
                    'section_unit_head_found' => true,
                ]);

                return $legacyResolved;
            }
        }

        $sectionTeamLeader = $this->resolveSectionUnitTeamLeaderFromPivot($subject, $hierarchy, $skipIds, $context, $levelLog);
        if ($sectionTeamLeader !== null) {
            return $sectionTeamLeader;
        }

        $this->log($context, 'no active section/unit head found', $levelLog + [
            'section_unit_head_found' => false,
            'skip_reason' => 'missing_or_ineligible_section_unit_head',
        ]);

        return null;
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveAssignedTeamLeader(User $subject, array $skipIds, array $context): ?array
    {
        $teamLeaderId = (int) ($subject->assigned_team_leader_id ?? 0);
        if ($teamLeaderId <= 0) {
            $this->log($context, 'assigned team leader lookup skipped', [
                'requester_assigned_team_leader_id' => null,
                'team_leader_found' => false,
                'skip_reason' => 'missing_assigned_team_leader_id',
            ]);

            return null;
        }

        $leader = $this->validLeader($teamLeaderId, $skipIds, $context, 'assigned_team_leader');
        if (! $leader) {
            $this->log($context, 'assigned team leader found but not eligible', [
                'requester_assigned_team_leader_id' => $teamLeaderId,
                'team_leader_found' => false,
                'skip_reason' => 'assigned_team_leader_ineligible',
            ]);

            return null;
        }

        $this->log($context, 'selected immediate approver from assigned team leader', [
            'requester_assigned_team_leader_id' => $teamLeaderId,
            'employee_team_leader_id' => $teamLeaderId,
            'team_leader_found' => true,
            'approver_id' => (int) $leader->id,
            'approver_name' => $leader->display_name,
            'selected_first_approver' => $leader->display_name,
            'selected_approver_source' => 'assigned_team_leader',
        ]);

        return $this->formatResult(
            $leader,
            [$leader],
            'Team Leader',
            OrganizationUnit::ROUTING_SPECIFIC_PER_EMPLOYEE,
            null,
            'team_leader',
        ) + ['selected_approver_source' => 'assigned_team_leader'];
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveSectionDirectoryLeadership(
        User $subject,
        array $hierarchy,
        array $skipIds,
        array $context,
    ): ?array {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? $subject->section_unit_id ?? 0);
        if ($sectionUnitId <= 0) {
            $this->log($context, 'section/unit directory lookup skipped — no section_unit_id on employee', [
                'skip_reason' => 'no_section_unit_assigned',
            ]);

            return null;
        }

        $section = SectionUnit::query()->find($sectionUnitId);
        if (! $section) {
            $this->log($context, 'section/unit directory lookup skipped — section record missing', [
                'requester_section_unit_id' => $sectionUnitId,
                'skip_reason' => 'section_unit_not_found',
            ]);

            return null;
        }

        $levelLog = [
            'requester_section_unit_id' => $sectionUnitId,
            'section_unit_head_id' => $section->section_unit_head_id ? (int) $section->section_unit_head_id : null,
        ];

        if (Schema::hasTable('section_unit_team_leaders')) {
            $teamLeaders = $section->teamLeaders()
                ->orderBy('section_unit_team_leaders.id')
                ->get();

            $this->log($context, 'section/unit directory team leaders scanned', $levelLog + [
                'section_unit_team_leader_ids' => $teamLeaders->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'section_unit_team_leader_found' => $teamLeaders->isNotEmpty(),
            ]);

            foreach ($teamLeaders as $teamLeader) {
                if (! $this->isValidLeaderUser($teamLeader, $skipIds)) {
                    $this->logRejectedLeader($context, $levelLog, $teamLeader, $skipIds, [
                        'source' => 'section_unit_directory_team_leader',
                    ]);

                    continue;
                }

                $this->log($context, 'selected first approver from section/unit directory team leader', $levelLog + [
                    'selected_first_approver' => $teamLeader->display_name,
                    'section_unit_team_leader_found' => true,
                    'selected_approver_source' => 'section_unit_directory_team_leader',
                ]);

                return $this->formatResult(
                    $teamLeader,
                    [$teamLeader],
                    'Team Leader',
                    OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                    null,
                    'team_leader',
                ) + ['selected_approver_source' => 'section_unit_directory_team_leader'];
            }
        }

        $headId = (int) ($section->section_unit_head_id ?? 0);
        if ($headId > 0) {
            $head = $this->validLeader($headId, $skipIds, $context, 'section_unit_directory_head');
            if ($head) {
                $this->log($context, 'selected first approver from section/unit directory head column', $levelLog + [
                    'selected_first_approver' => $head->display_name,
                    'section_unit_head_found' => true,
                    'selected_approver_source' => 'section_unit_directory_head',
                ]);

                return $this->formatResult(
                    $head,
                    [$head],
                    'Section/Unit Head',
                    OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                    null,
                    'section_unit_head',
                ) + ['selected_approver_source' => 'section_unit_directory_head'];
            }

            $this->log($context, 'section/unit directory head found but not eligible', $levelLog + [
                'section_unit_head_id' => $headId,
                'section_unit_head_found' => false,
                'skip_reason' => 'section_unit_directory_head_ineligible',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     */
    private function resolveParentDepartmentHeadForSectionLeaderRequest(
        User $subject,
        User $requestor,
        array $hierarchy,
        ?string $requestType,
        array $skipIds,
        array $context,
        array $sectionLeaderContext = [],
    ): ?array {
        $sectionUnitId = (int) ($sectionLeaderContext['section_unit_id'] ?? $hierarchy['section_unit'] ?? 0);
        $departmentId = (int) ($sectionLeaderContext['department_id'] ?? $hierarchy['department'] ?? 0);
        $sectionLeaderIds = $sectionLeaderContext['section_unit_leader_ids'] ?? $this->sectionLeaderIdsForContext($sectionUnitId);

        $baseLog = [
            'request_type' => $requestType,
            'requester_employee_id' => (int) $requestor->id,
            'requester_name' => $requestor->display_name,
            'requester_organization_context' => $hierarchy,
            'requester_is_section_unit_leader' => true,
            'section_unit_leader_ids' => $sectionLeaderIds,
            'parent_department_id' => $departmentId > 0 ? $departmentId : null,
        ];

        if ($departmentId <= 0 && $sectionUnitId > 0) {
            $departmentId = (int) (SectionUnit::query()->whereKey($sectionUnitId)->value('department_id') ?? 0);
            $baseLog['parent_department_id'] = $departmentId > 0 ? $departmentId : null;
        }

        if ($departmentId <= 0) {
            $this->log($context, 'section/unit leader request skipped department head lookup — no parent department', $baseLog + [
                'department_head_found' => false,
                'is_team_leader' => true,
                'request_section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
                'skip_reason' => 'missing_parent_department_context',
            ]);

            return null;
        }

        $legacyHeadId = $this->legacyHeadEmployeeIdFor('department', $departmentId);

        $this->log($context, 'section/unit leader request evaluating parent department head', $baseLog + [
            'is_team_leader' => true,
            'request_section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
            'department_head_id' => $legacyHeadId,
            'department_head_found' => $legacyHeadId !== null,
        ]);

        $resolved = $this->resolveLeadersForLegacyUnit(
            'department',
            $departmentId,
            $subject,
            $requestType,
            $skipIds,
            $context,
        );

        if ($resolved === null) {
            $head = $legacyHeadId ? User::query()->find($legacyHeadId) : null;
            $this->log($context, 'section/unit leader request has no eligible department head — routing to HR/Admin only', $baseLog + [
                'is_team_leader' => true,
                'request_section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
                'department_head_found' => $head !== null,
                'department_head_active' => $head?->is_active,
                'department_head_operationally_active' => $head?->isOperationallyActive(),
                'skip_reason' => 'department_head_missing_inactive_or_self',
            ]);

            return null;
        }

        $resolved['selected_approver_source'] = 'parent_department_head_for_section_leader';
        $this->log($context, 'selected parent department head for section/unit leader request', $baseLog + [
            'is_team_leader' => true,
            'request_section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
            'department_head_found' => true,
            'department_head_active' => true,
            'selected_first_approver' => $resolved['approver_name'],
            'selected_first_approver_id' => $resolved['approver_id'],
            'selected_approver_source' => 'parent_department_head_for_section_leader',
        ]);

        return $resolved;
    }

    private function employeeImmediateLeaderId(User $subject, ?EmployeeOrganizationAssignment $assignment): ?int
    {
        $assignmentLeaderId = (int) ($assignment?->immediate_leader_id ?? 0);
        if ($assignmentLeaderId > 0) {
            return $assignmentLeaderId;
        }

        $supervisorId = (int) ($subject->supervisor_id ?? 0);

        return $supervisorId > 0 ? $supervisorId : null;
    }

    private function probeSectionDirectoryLeadership(User $subject, array $hierarchy): array
    {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? $subject->section_unit_id ?? 0);
        if ($sectionUnitId <= 0) {
            return [
                'section_unit_head_id' => null,
                'section_unit_head_found' => false,
                'section_unit_team_leader_ids' => [],
                'section_unit_team_leader_found' => false,
            ];
        }

        $section = SectionUnit::query()->find($sectionUnitId);
        if (! $section) {
            return [
                'section_unit_head_id' => null,
                'section_unit_head_found' => false,
                'section_unit_team_leader_ids' => [],
                'section_unit_team_leader_found' => false,
            ];
        }

        $teamLeaderIds = Schema::hasTable('section_unit_team_leaders')
            ? $section->teamLeaders()->pluck('users.id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'section_unit_head_id' => $section->section_unit_head_id ? (int) $section->section_unit_head_id : null,
            'section_unit_head_found' => (int) ($section->section_unit_head_id ?? 0) > 0,
            'section_unit_team_leader_ids' => $teamLeaderIds,
            'section_unit_team_leader_found' => $teamLeaderIds !== [],
        ];
    }

    /**
     * Leave/overtime must not fall back to Department Head when an eligible section/unit
     * directory approver already exists. Configured-but-ineligible heads must not block
     * department head routing.
     *
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     */
    private function shouldSkipDepartmentHeadFallback(
        ?string $requestType,
        User $subject,
        array $hierarchy,
        array $skipIds,
        array $context,
    ): bool {
        $normalized = $this->workflowSettingService->normalizeRequestType($requestType);
        if (! in_array($normalized, [
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true)) {
            return false;
        }

        return $this->resolveSectionDirectoryLeadership($subject, $hierarchy, $skipIds, $context) !== null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveDepartmentHeadForRequester(
        User $subject,
        array $hierarchy,
        array $skipIds,
        array $context,
        ?string $requestType,
        string $source,
        ?EmployeeOrganizationAssignment $assignment = null,
        bool $directDepartmentAssignment = false,
    ): ?array {
        $departmentId = (int) ($hierarchy['department'] ?? $subject->department_id ?? 0);
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? $subject->section_unit_id ?? 0);

        if ($departmentId <= 0 && $sectionUnitId > 0) {
            $departmentId = (int) (SectionUnit::query()->whereKey($sectionUnitId)->value('department_id') ?? 0);
        }

        $candidates = $this->departmentHeadCandidatesForDepartment($departmentId);
        $candidateIds = array_map(fn (array $candidate): int => (int) $candidate['employee_id'], $candidates);
        $levelLog = [
            'request_type' => $requestType,
            'requester_employee_id' => (int) $subject->id,
            'requester_name' => $subject->display_name,
            'assignment_id' => $assignment?->id ? (int) $assignment->id : null,
            'department_id' => $departmentId > 0 ? $departmentId : null,
            'section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
            'requester_department_id' => $departmentId > 0 ? $departmentId : null,
            'requester_section_unit_id' => $sectionUnitId > 0 ? $sectionUnitId : null,
            'employee_directly_assigned_to_department' => $directDepartmentAssignment,
            'department_head_candidate_ids' => $candidateIds,
            'selected_approver_source' => $source,
            'visibility_permissions_ignored_for_approval' => true,
        ];

        $this->log($context, 'department head lookup for requester', $levelLog + [
            'department_head_found' => $candidateIds !== [],
        ]);

        foreach ($candidates as $candidate) {
            /** @var array{employee_id: int, source: string} $candidate */
            $headId = (int) $candidate['employee_id'];
            $headSource = (string) $candidate['source'];
            $head = User::query()->find($headId);
            if (! $head) {
                $this->log($context, 'department head candidate missing user record', $levelLog + [
                    'department_head_id' => $headId,
                    'department_head_source' => $headSource,
                    'department_head_selected' => false,
                    'skip_reason' => 'department_head_user_not_found',
                ]);

                continue;
            }

            if (! $this->isValidLeaderUser($head, $skipIds)) {
                $this->logRejectedLeader($context, $levelLog, $head, $skipIds, [
                    'source' => 'department_head_candidate',
                    'department_head_source' => $headSource,
                    'department_head_selected' => false,
                ]);

                continue;
            }

            $resolved = $this->formatResult(
                $head,
                [$head],
                'Department Head',
                OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                OrganizationUnit::query()
                    ->where('legacy_source_type', 'department')
                    ->where('legacy_source_id', $departmentId)
                    ->first(),
                $this->approvalLevelFor(null, 'Department Head', 'department'),
            ) + ['selected_approver_source' => $source];

            $this->log($context, 'selected department head approver', $levelLog + [
                'department_head_found' => true,
                'department_head_selected' => true,
                'department_head_id' => (int) $head->id,
                'department_head_name' => $head->display_name,
                'department_head_source' => $headSource,
                'selected_first_approver' => $head->display_name,
                'selected_first_approver_id' => (int) $head->id,
            ]);

            return $resolved;
        }

        if ($departmentId > 0) {
            $resolved = $this->resolveLeadersForLegacyUnit(
                'department',
                $departmentId,
                $subject,
                $requestType,
                $skipIds,
                $context,
            );

            if ($resolved !== null) {
                $resolved['selected_approver_source'] = $source;
                $this->log($context, 'selected department head approver from flexible unit leaders', $levelLog + [
                    'department_head_found' => true,
                    'department_head_selected' => true,
                    'department_head_source' => 'organization_unit_leaders',
                    'selected_first_approver' => $resolved['approver_name'],
                    'selected_first_approver_id' => $resolved['approver_id'],
                ]);

                return $resolved;
            }
        }

        $this->log($context, 'no eligible department head for requester', $levelLog + [
            'department_head_found' => false,
            'department_head_selected' => false,
            'skip_reason' => 'department_head_missing_inactive_or_self',
        ]);

        return null;
    }

    /**
     * @return list<array{employee_id: int, source: string}>
     */
    private function departmentHeadCandidatesForDepartment(int $departmentId): array
    {
        if ($departmentId <= 0) {
            return [];
        }

        $candidates = [];

        if (Schema::hasTable('organization_leadership_assignments')) {
            $query = DB::table('organization_leadership_assignments');

            if (Schema::hasColumn('organization_leadership_assignments', 'organization_type')) {
                $query->where('organization_type', 'department');
            }

            $departmentColumn = Schema::hasColumn('organization_leadership_assignments', 'department_id')
                ? 'department_id'
                : (Schema::hasColumn('organization_leadership_assignments', 'organization_id') ? 'organization_id' : null);

            if ($departmentColumn !== null) {
                $query->where($departmentColumn, $departmentId);
            }

            if (Schema::hasColumn('organization_leadership_assignments', 'can_approve')) {
                $query->where('can_approve', true);
            }

            if (Schema::hasColumn('organization_leadership_assignments', 'is_active')) {
                $query->where('is_active', true);
            }

            if (Schema::hasColumn('organization_leadership_assignments', 'expires_at')) {
                $query->where(function ($expiryQuery): void {
                    $expiryQuery->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                });
            }

            if (Schema::hasColumn('organization_leadership_assignments', 'effective_to')) {
                $query->where(function ($effectiveQuery): void {
                    $effectiveQuery->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', now()->toDateString());
                });
            }

            $candidates = $query
                ->orderBy('id')
                ->pluck('employee_id')
                ->map(fn ($id): array => [
                    'employee_id' => (int) $id,
                    'source' => 'leadership_assignment',
                ])
                ->filter(fn (array $candidate): bool => (int) $candidate['employee_id'] > 0)
                ->all();
        }

        $legacyHead = $this->legacyDepartmentHeadFor($departmentId);
        if ($legacyHead !== null) {
            $candidates[] = $legacyHead;
        }

        return collect($candidates)
            ->unique(fn (array $candidate): int => (int) $candidate['employee_id'])
            ->values()
            ->all();
    }

    private function usesDepartmentFirstApproval(?string $requestType): bool
    {
        $normalized = $this->workflowSettingService->normalizeRequestType($requestType);

        return in_array($normalized, [
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function isDirectDepartmentAssignment(
        User $subject,
        ?EmployeeOrganizationAssignment $assignment,
        array $hierarchy,
    ): bool {
        $departmentId = (int) ($hierarchy['department'] ?? $subject->department_id ?? 0);
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? $subject->section_unit_id ?? 0);

        if ($departmentId <= 0 || $sectionUnitId > 0) {
            return false;
        }

        if (! $assignment) {
            return true;
        }

        $assignmentSectionId = (int) ($assignment->section_unit_id ?? 0);
        if ($assignmentSectionId > 0) {
            return false;
        }

        $assignmentDepartmentId = (int) ($assignment->department_id ?? 0);
        if ($assignmentDepartmentId > 0) {
            return true;
        }

        $unit = $assignment->organizationUnit;
        if ($unit && (string) ($unit->legacy_source_type ?? '') === 'department') {
            return true;
        }

        return (int) ($subject->department_id ?? 0) > 0 && (int) ($subject->section_unit_id ?? 0) <= 0;
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function finalApproverLogPayload(array $resolved, bool $fallbackToParent): array
    {
        return [
            'approver_id' => $resolved['approver_id'],
            'approver_name' => $resolved['approver_name'],
            'selected_first_approver' => $resolved['approver_name'],
            'approval_level' => $resolved['approval_level'],
            'leader_role' => $resolved['leader_role'],
            'approval_label' => $resolved['approval_label'] ?? null,
            'selected_approver_source' => $resolved['selected_approver_source'] ?? null,
            'department_head_fallback_allowed' => $fallbackToParent,
        ];
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $levelLog
     * @return array<string, mixed>|null
     */
    private function resolveSectionUnitTeamLeaderFromPivot(
        User $subject,
        array $hierarchy,
        array $skipIds,
        array $context,
        array $levelLog,
    ): ?array {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? 0);
        if ($sectionUnitId <= 0 || ! Schema::hasTable('section_unit_team_leaders')) {
            return null;
        }

        $section = SectionUnit::query()->find($sectionUnitId);
        if (! $section) {
            return null;
        }

        $teamLeaders = $section->teamLeaders()
            ->orderBy('section_unit_team_leaders.id')
            ->get();

        $this->log($context, 'section/unit team leader pivot scanned', $levelLog + [
            'section_unit_team_leader_count' => $teamLeaders->count(),
            'section_unit_team_leader_ids' => $teamLeaders->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ]);

        foreach ($teamLeaders as $teamLeader) {
            if (! $this->isValidLeaderUser($teamLeader, $skipIds)) {
                $this->logRejectedLeader($context, $levelLog, $teamLeader, $skipIds, [
                    'source' => 'section_unit_team_leaders_pivot',
                ]);

                continue;
            }

            $this->log($context, 'selected section/unit head approver from team leader pivot', $levelLog + [
                'selected_section_unit_head_id' => (int) $teamLeader->id,
                'selected_section_unit_head_name' => $teamLeader->display_name,
                'section_unit_head_found' => true,
                'selected_approver_source' => 'section_unit_team_leader_pivot',
            ]);

            return $this->formatResult(
                $teamLeader,
                [$teamLeader],
                'Team Leader',
                OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                null,
                'team_leader',
            ) + ['selected_approver_source' => 'section_unit_team_leader_pivot'];
        }

        return null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  array<string, mixed>  $context
     */
    private function resolveSectionUnitOrganizationUnit(User $subject, array $hierarchy, array $context): ?OrganizationUnit
    {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? 0);
        if ($sectionUnitId > 0) {
            try {
                $this->legacyOrganizationMirrorService->syncLegacyRecord('section_unit', $sectionUnitId);
            } catch (\Throwable $exception) {
                $this->log($context, 'failed to sync section/unit legacy record before head lookup', [
                    'requester_section_unit_id' => $sectionUnitId,
                    'message' => $exception->getMessage(),
                ]);
            }

            $unit = OrganizationUnit::query()
                ->with(['type', 'parent'])
                ->where('legacy_source_type', 'section_unit')
                ->where('legacy_source_id', $sectionUnitId)
                ->first();

            if ($unit) {
                return $unit;
            }
        }

        $assignment = $this->selectedAssignmentFor($subject, $context);
        if ($assignment?->organizationUnit) {
            $unit = $assignment->organizationUnit->loadMissing(['type', 'parent']);
            if ($this->isSectionLevelUnit($unit)) {
                return $unit;
            }
        }

        return null;
    }

    private function isSectionLevelUnit(OrganizationUnit $unit): bool
    {
        if ((string) ($unit->legacy_source_type ?? '') === 'section_unit') {
            return true;
        }

        if ($this->unitHierarchyLevel($unit) === 'section_unit') {
            return true;
        }

        $parent = $unit->relationLoaded('parent') ? $unit->parent : $unit->parent()->first();
        if ($parent && $this->unitHierarchyLevel($parent) === 'department') {
            return $this->unitHierarchyLevel($unit) !== 'department';
        }

        return false;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function startingParentFallbackUnitIndex(
        string $requesterLevel,
        Collection $unitChain,
        User $requestor,
        array $hierarchy,
    ): int {
        $departmentIndex = $this->findUnitIndexByHierarchyLevel($unitChain, 'department');
        if ($departmentIndex !== null) {
            return $departmentIndex;
        }

        return $this->startingUnitIndex($requesterLevel, $unitChain, $requestor, $hierarchy);
    }

    /**
     * @return array<string, int|null>
     */
    private function buildLegacyHierarchy(User $subject, array $context = []): array
    {
        $department = $subject->departmentRelation
            ?: ($subject->department_id ? Department::query()->find($subject->department_id) : null);
        $section = $subject->sectionUnit
            ?: ($subject->section_unit_id ? SectionUnit::query()->find($subject->section_unit_id) : null);

        $departmentId = $subject->department_id ?: $department?->id ?: $section?->department_id;
        $divisionId = $subject->division_id ?: $department?->division_id ?: $section?->division_id;
        $branchId = $subject->branch_id ?: $department?->branch_id ?: $section?->branch_id;
        $areaId = null;
        $companyId = $subject->company_id ?: $department?->company_id ?: $section?->company_id;

        if ($divisionId && ! $branchId) {
            $branchId = Division::query()->whereKey($divisionId)->value('branch_id');
        }
        if ($branchId && ! $companyId) {
            $companyId = Branch::query()->whereKey($branchId)->value('company_id');
        }
        if ($branchId) {
            $branchMeta = Branch::query()->whereKey($branchId)->first(['area_id', 'company_id']);
            $areaId = $branchMeta?->area_id ? (int) $branchMeta->area_id : null;
            $companyId = $companyId ?: $branchMeta?->company_id;
        }
        if ($divisionId && ! $companyId) {
            $companyId = Division::query()->whereKey($divisionId)->value('company_id');
        }

        $sectionUnitId = $subject->section_unit_id ?: $section?->id;
        if (! $sectionUnitId && Schema::hasTable('section_unit_team_leaders')) {
            $ledSectionId = (int) (DB::table('section_unit_team_leaders')
                ->where('employee_id', (int) $subject->id)
                ->orderBy('section_unit_id')
                ->value('section_unit_id') ?? 0);
            if ($ledSectionId > 0) {
                $sectionUnitId = $ledSectionId;
                $ledSection = $section?->id === $ledSectionId
                    ? $section
                    : SectionUnit::query()->find($ledSectionId);
                $departmentId = $departmentId ?: ($ledSection?->department_id ? (int) $ledSection->department_id : null);
                $divisionId = $divisionId ?: ($ledSection?->division_id ? (int) $ledSection->division_id : null);
                $branchId = $branchId ?: ($ledSection?->branch_id ? (int) $ledSection->branch_id : null);
                $companyId = $companyId ?: ($ledSection?->company_id ? (int) $ledSection->company_id : null);
            }
        }

        $hierarchy = [
            'section_unit' => $sectionUnitId,
            'department' => $departmentId,
            'division' => $divisionId,
            'branch' => $branchId,
            'area' => $areaId,
            'company' => $companyId,
        ];

        return $this->mergeHierarchyFromAssignment($subject, $hierarchy, $context);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @return array<string, int|null>
     */
    private function mergeHierarchyFromAssignment(User $subject, array $hierarchy, array $context = []): array
    {
        $assignment = $this->selectedAssignmentFor($subject, $context);
        if (! $assignment) {
            return $hierarchy;
        }

        foreach (['company', 'area', 'branch', 'division', 'department', 'section_unit'] as $key) {
            $column = $key.'_id';
            if ($assignment->{$column}) {
                $hierarchy[$key] = (int) $assignment->{$column};
            }
        }

        if (! $assignment->organizationUnit) {
            return $hierarchy;
        }

        $unit = $assignment->organizationUnit->loadMissing(['type', 'parent']);
        while ($unit) {
            $legacyType = trim((string) ($unit->legacy_source_type ?? ''));
            $legacyId = (int) ($unit->legacy_source_id ?? 0);
            if ($legacyType !== '' && $legacyId > 0 && in_array($legacyType, self::HIERARCHY_ORDER, true)) {
                $hierarchy[$legacyType] = $hierarchy[$legacyType] ?? $legacyId;
            }
            $unit = $unit->parent;
        }

        return $hierarchy;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  array<string, mixed>  $context
     * @return Collection<int, OrganizationUnit>
     */
    private function buildOrganizationUnitChain(User $subject, array $hierarchy, array $context): Collection
    {
        $byId = [];
        $ordered = [];

        $appendUnit = function (?OrganizationUnit $unit) use (&$byId, &$ordered): void {
            while ($unit) {
                $unit->loadMissing(['type', 'parent']);
                $unitId = (int) $unit->id;
                if (! isset($byId[$unitId])) {
                    $byId[$unitId] = true;
                    $ordered[] = $unit;
                }
                $unit = $unit->parent;
            }
        };

        $assignment = $this->selectedAssignmentFor($subject, $context);
        if ($assignment?->organizationUnit) {
            $appendUnit($assignment->organizationUnit);
        }

        foreach (self::HIERARCHY_ORDER as $legacyType) {
            $legacyId = (int) ($hierarchy[$legacyType] ?? 0);
            if ($legacyId <= 0) {
                continue;
            }

            $unit = OrganizationUnit::query()
                ->with(['type', 'parent'])
                ->where('legacy_source_type', $legacyType)
                ->where('legacy_source_id', $legacyId)
                ->first();

            if ($unit) {
                $appendUnit($unit);
            }
        }

        usort($ordered, function (OrganizationUnit $left, OrganizationUnit $right): int {
            return $this->unitTreeDepth($right) <=> $this->unitTreeDepth($left);
        });

        $this->log($context, 'built organization unit lookup chain', [
            'chain_length' => count($ordered),
        ]);

        return collect($ordered);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function startingUnitIndex(
        string $requesterLevel,
        Collection $unitChain,
        User $requestor,
        array $hierarchy,
    ): int {
        if ($requesterLevel === 'company_head') {
            return -1;
        }

        if ($unitChain->isEmpty()) {
            return -1;
        }

        $skipLevels = match ($requesterLevel) {
            'area_head' => ['area', 'branch', 'division', 'department', 'section_unit'],
            'branch_head' => ['branch', 'division', 'department', 'section_unit'],
            'division_head' => ['division', 'department', 'section_unit'],
            'department_head' => ['department', 'section_unit'],
            'section_unit_head' => ['section_unit'],
            default => [],
        };

        foreach ($unitChain->values() as $index => $unit) {
            /** @var OrganizationUnit $unit */
            $level = $this->unitHierarchyLevel($unit);
            if (in_array($level, $skipLevels, true)) {
                continue;
            }

            if ($this->isHeadOfUnit($requestor, $unit)) {
                continue;
            }

            return (int) $index;
        }

        $startLegacyLevel = $this->startingLookupLevel($requesterLevel, $hierarchy);
        if ($startLegacyLevel !== null) {
            $startRank = $this->unitDepthRank($startLegacyLevel);
            foreach ($unitChain->values() as $index => $unit) {
                if ($this->unitDepthRank($this->unitHierarchyLevel($unit)) >= $startRank) {
                    return (int) $index;
                }
            }
        }

        return 0;
    }

    private function unitHierarchyLevel(OrganizationUnit $unit): string
    {
        $legacyType = trim((string) ($unit->legacy_source_type ?? ''));
        if ($legacyType !== '' && in_array($legacyType, self::HIERARCHY_ORDER, true)) {
            return $legacyType;
        }

        $code = Str::lower(trim((string) ($unit->type?->code ?? '')));
        $name = Str::lower(trim((string) ($unit->type?->name ?? '')));
        $probe = $code !== '' ? $code : $name;

        return match (true) {
            str_contains($probe, 'section') => 'section_unit',
            str_contains($probe, 'department') => 'department',
            str_contains($probe, 'division') => 'division',
            str_contains($probe, 'branch') => 'branch',
            str_contains($probe, 'area') => 'area',
            str_contains($probe, 'company') => 'company',
            default => 'custom',
        };
    }

    private function unitDepthRank(string $level): int
    {
        $index = array_search($level, self::HIERARCHY_ORDER, true);

        return $index === false ? 999 : (int) $index;
    }

    private function unitTreeDepth(OrganizationUnit $unit): int
    {
        $depth = 0;
        $current = $unit->loadMissing('parent');

        while ($current) {
            $depth++;
            $current = $current->parent;
        }

        return $depth;
    }

    private function isHeadOfUnit(User $user, OrganizationUnit $unit): bool
    {
        if ($unit->legacy_source_type && (int) $unit->legacy_source_id > 0) {
            return $this->isHeadOfLegacyUnit($user, (string) $unit->legacy_source_type, (int) $unit->legacy_source_id);
        }

        return $this->isUserLeaderOfUnit($user, $unit);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  Collection<int, OrganizationUnit>  $unitChain
     */
    private function resolveDivisionId(array $hierarchy, Collection $unitChain): int
    {
        $fromHierarchy = (int) ($hierarchy['division'] ?? 0);
        if ($fromHierarchy > 0) {
            return $fromHierarchy;
        }

        foreach ($unitChain as $unit) {
            if ($this->unitHierarchyLevel($unit) !== 'division') {
                continue;
            }

            if ($unit->legacy_source_type === 'division' && (int) $unit->legacy_source_id > 0) {
                return (int) $unit->legacy_source_id;
            }
        }

        return 0;
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $unitChain
     */
    private function findUnitIndexByHierarchyLevel(Collection $unitChain, string $level): ?int
    {
        foreach ($unitChain->values() as $index => $unit) {
            if ($this->unitHierarchyLevel($unit) === $level) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function detectRequesterLevel(User $requestor, array $hierarchy): string
    {
        $orgRole = $this->hrRoleResolver->resolveOrganizationalRole($requestor);

        $fromRole = match ($orgRole) {
            HrRole::CompanyHead => 'company_head',
            HrRole::AreaHead => 'area_head',
            HrRole::BranchHead => 'branch_head',
            HrRole::DivisionHead => 'division_head',
            HrRole::DepartmentHead => 'department_head',
            HrRole::SectionUnitHead => 'section_unit_head',
            default => null,
        };

        if ($fromRole !== null) {
            return $fromRole;
        }

        $assignment = $this->primaryAssignmentFor($requestor);
        if ($assignment?->organizationUnit) {
            $unit = $assignment->organizationUnit->loadMissing(['type', 'parent']);
            while ($unit) {
                if ($this->isUserLeaderOfUnit($requestor, $unit)) {
                    return match ($this->unitHierarchyLevel($unit)) {
                        'company' => 'company_head',
                        'area' => 'area_head',
                        'branch' => 'branch_head',
                        'division' => 'division_head',
                        'department' => 'department_head',
                        'section_unit' => 'section_unit_head',
                        default => 'employee',
                    };
                }
                $unit = $unit->parent;
            }
        }

        foreach (array_reverse(self::HIERARCHY_ORDER) as $legacyType) {
            $legacyId = (int) ($hierarchy[$legacyType] ?? 0);
            if ($legacyId > 0 && $this->isHeadOfLegacyUnit($requestor, $legacyType, $legacyId)) {
                return match ($legacyType) {
                    'company' => 'company_head',
                    'area' => 'area_head',
                    'branch' => 'branch_head',
                    'division' => 'division_head',
                    'department' => 'department_head',
                    'section_unit' => 'section_unit_head',
                    default => 'employee',
                };
            }
        }

        return 'employee';
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function requestorLeadsDeepestUnit(User $requestor, array $hierarchy): bool
    {
        foreach (self::HIERARCHY_ORDER as $legacyType) {
            $legacyId = (int) ($hierarchy[$legacyType] ?? 0);
            if ($legacyId <= 0) {
                continue;
            }

            return $this->isHeadOfLegacyUnit($requestor, $legacyType, $legacyId);
        }

        return false;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function requestorLeadsLegacySectionUnit(User $requestor, array $hierarchy): bool
    {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? 0);
        if ($sectionUnitId <= 0) {
            return false;
        }

        return $this->isHeadOfLegacyUnit($requestor, 'section_unit', $sectionUnitId);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function requestorLeadsSectionUnitContext(User $requestor, array $hierarchy): bool
    {
        return (bool) $this->sectionLeaderRequestContext($requestor, $hierarchy)['is_team_leader'];
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  array<string, mixed>  $context
     * @return array{is_team_leader: bool, section_unit_id: ?int, department_id: ?int, section_unit_leader_ids: list<int>}
     */
    private function sectionLeaderRequestContext(User $requestor, array $hierarchy, array $context = []): array
    {
        $candidateSectionIds = array_values(array_unique(array_filter(array_map('intval', [
            $context['section_unit_id'] ?? null,
            $hierarchy['section_unit'] ?? null,
        ]), fn (int $id): bool => $id > 0)));

        $ledSectionIds = $this->sectionUnitIdsLedByRequestor($requestor);
        foreach ($ledSectionIds as $sectionId) {
            if (! in_array($sectionId, $candidateSectionIds, true)) {
                $candidateSectionIds[] = $sectionId;
            }
        }

        foreach ($candidateSectionIds as $sectionUnitId) {
            $leaderIds = $this->sectionLeaderIdsForContext($sectionUnitId);
            if (! in_array((int) $requestor->id, $leaderIds, true)) {
                continue;
            }

            $departmentId = (int) ($hierarchy['department'] ?? $context['department_id'] ?? 0);
            if ($departmentId <= 0) {
                $departmentId = (int) (SectionUnit::query()->whereKey($sectionUnitId)->value('department_id') ?? 0);
            }

            return [
                'is_team_leader' => true,
                'section_unit_id' => $sectionUnitId,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'section_unit_leader_ids' => $leaderIds,
            ];
        }

        return [
            'is_team_leader' => false,
            'section_unit_id' => $candidateSectionIds[0] ?? null,
            'department_id' => (int) ($hierarchy['department'] ?? $context['department_id'] ?? 0) ?: null,
            'section_unit_leader_ids' => [],
        ];
    }

    /**
     * @return list<int>
     */
    private function sectionUnitIdsLedByRequestor(User $requestor): array
    {
        $userId = (int) $requestor->id;
        $ids = [];

        $sectionQuery = SectionUnit::query()->where('section_unit_head_id', $userId);
        if (Schema::hasColumn('sections_or_units', 'head_employee_id')) {
            $sectionQuery->orWhere('head_employee_id', $userId);
        }
        if (Schema::hasColumn('sections_or_units', 'team_leader_id')) {
            $sectionQuery->orWhere('team_leader_id', $userId);
        }
        $ids = [
            ...$ids,
            ...$sectionQuery->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];

        if (Schema::hasTable('section_unit_team_leaders')) {
            $ids = [
                ...$ids,
                ...DB::table('section_unit_team_leaders')
                    ->where('employee_id', $userId)
                    ->pluck('section_unit_id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            ];
        }

        if (Schema::hasTable('organization_units')) {
            $unitQuery = OrganizationUnit::query()
                ->where('legacy_source_type', 'section_unit')
                ->where(function ($query) use ($userId): void {
                    $query->whereHas('activeLeaders', fn ($leaderQuery) => $leaderQuery->where('employee_id', $userId));

                    if (Schema::hasTable('organization_position_assignments')) {
                        $query->orWhereHas('activePositionAssignments', function ($assignmentQuery) use ($userId): void {
                            $assignmentQuery
                                ->where('employee_id', $userId)
                                ->whereHas('positionType', fn ($typeQuery) => $typeQuery->where('can_approve', true));
                        });
                    }
                });

            $ids = [
                ...$ids,
                ...$unitQuery->pluck('legacy_source_id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        if (Schema::hasTable('organization_leadership_assignments')) {
            $leadership = DB::table('organization_leadership_assignments')->where('employee_id', $userId);
            if (Schema::hasColumn('organization_leadership_assignments', 'organization_type')) {
                $leadership->where('organization_type', 'section_unit');
            }
            if (Schema::hasColumn('organization_leadership_assignments', 'can_approve')) {
                $leadership->where('can_approve', true);
            }
            if (Schema::hasColumn('organization_leadership_assignments', 'is_active')) {
                $leadership->where('is_active', true);
            }

            $sectionColumn = Schema::hasColumn('organization_leadership_assignments', 'section_unit_id')
                ? 'section_unit_id'
                : (Schema::hasColumn('organization_leadership_assignments', 'organization_id') ? 'organization_id' : null);

            if ($sectionColumn !== null) {
                $ids = [
                    ...$ids,
                    ...$leadership->pluck($sectionColumn)->map(fn ($id) => (int) $id)->all(),
                ];
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function requestorLeadsSectionUnitContextLegacy(User $requestor, array $hierarchy): bool
    {
        $sectionUnitId = (int) ($hierarchy['section_unit'] ?? 0);
        if ($sectionUnitId <= 0) {
            return false;
        }

        return in_array((int) $requestor->id, $this->sectionLeaderIdsForContext($sectionUnitId), true);
    }

    /**
     * @return list<int>
     */
    private function sectionLeaderIdsForContext(int $sectionUnitId): array
    {
        if ($sectionUnitId <= 0) {
            return [];
        }

        $ids = [];

        $legacyHeadId = $this->legacyHeadEmployeeIdFor('section_unit', $sectionUnitId);
        if ($legacyHeadId !== null) {
            $ids[] = $legacyHeadId;
        }

        $section = SectionUnit::query()->find($sectionUnitId);
        if ($section) {
            foreach (['head_employee_id', 'team_leader_id'] as $column) {
                if (Schema::hasColumn('sections_or_units', $column) && (int) ($section->{$column} ?? 0) > 0) {
                    $ids[] = (int) $section->{$column};
                }
            }
        }

        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', 'section_unit')
            ->where('legacy_source_id', $sectionUnitId)
            ->first();

        if ($unit) {
            $ids = [
                ...$ids,
                ...$unit->activeLeaders()->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
            ];

            if (Schema::hasTable('organization_position_assignments')) {
                $ids = [
                    ...$ids,
                    ...$unit->activePositionAssignments()
                        ->whereHas('positionType', fn ($query) => $query->where('can_approve', true))
                        ->pluck('employee_id')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                ];
            }
        }

        if (Schema::hasTable('section_unit_team_leaders') && $section) {
            $ids = [
                ...$ids,
                ...$section->teamLeaders()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function startingLookupLevel(string $requesterLevel, array $hierarchy): ?string
    {
        return match ($requesterLevel) {
            'company_head' => null,
            'area_head' => $this->firstLevelWithId($hierarchy, ['company']),
            'branch_head' => $this->firstLevelWithId($hierarchy, ['area', 'company']),
            'division_head' => $this->firstLevelWithId($hierarchy, ['branch', 'area', 'company']),
            'department_head' => $this->firstLevelWithId($hierarchy, ['division', 'branch', 'area', 'company']),
            'section_unit_head' => $this->firstLevelWithId($hierarchy, ['department', 'division', 'branch', 'area', 'company']),
            default => $this->deepestLevelWithId($hierarchy),
        };
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<string>  $preferred
     */
    private function firstLevelWithId(array $hierarchy, array $preferred): ?string
    {
        foreach ($preferred as $level) {
            if ((int) ($hierarchy[$level] ?? 0) > 0) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     */
    private function deepestLevelWithId(array $hierarchy): ?string
    {
        foreach (self::HIERARCHY_ORDER as $level) {
            if ((int) ($hierarchy[$level] ?? 0) > 0) {
                return $level;
            }
        }

        return null;
    }

    private function isHeadOfLegacyUnit(User $user, string $legacyType, int $legacyId): bool
    {
        $userId = (int) $user->id;

        if ($this->legacyHeadEmployeeIdFor($legacyType, $legacyId) === $userId) {
            return true;
        }

        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        if (! $unit) {
            return false;
        }

        return $this->isUserLeaderOfUnit($user, $unit);
    }

    /**
     * @param  Collection<int, OrganizationUnit>  $unitChain
     * @param  array<string, int|null>  $hierarchy
     */
    private function requestorIsDepartmentHead(
        User $requestor,
        string $requesterLevel,
        Collection $unitChain,
        array $hierarchy,
    ): bool {
        if ($requesterLevel === 'department_head') {
            return true;
        }

        foreach ($unitChain as $unit) {
            if ($this->unitHierarchyLevel($unit) === 'department' && $this->isHeadOfUnit($requestor, $unit)) {
                return true;
            }
        }

        $departmentId = (int) ($hierarchy['department'] ?? 0);
        if ($departmentId > 0) {
            return $this->isHeadOfLegacyUnit($requestor, 'department', $departmentId);
        }

        return false;
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     */
    private function resolveLeadersForLegacyUnit(
        string $legacyType,
        int $legacyId,
        User $subject,
        ?string $requestType,
        array $skipIds,
        array $context,
    ): ?array {
        $unit = OrganizationUnit::query()
            ->with(['type', 'parent'])
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        $levelLog = [
            'legacy_type' => $legacyType,
            'legacy_id' => $legacyId,
            'organization_unit_id' => $unit?->id,
        ];

        if ($unit && (bool) $unit->is_active) {
            $resolved = $this->resolveFromUnitLeaders($unit, $subject, $requestType, $skipIds, $context, $levelLog);
            if ($resolved !== null) {
                return $resolved;
            }

            if ($this->unitHasFlexibleApprovalAssignments($unit)) {
                $this->log($context, 'legacy head fallback skipped — flexible approval assignments control routing', $levelLog + [
                    'skipped_reason' => 'flexible_assignment_scope_not_matched',
                ]);

                return null;
            }
        }

        return $this->resolveFromLegacyHeadColumnDirect($legacyType, $legacyId, $unit, $skipIds, $context, $levelLog);
    }

    /**
     * Resolve every enabled organization-head step from section/unit through company.
     *
     * @param  list<int>  $usedApproverIds
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function resolveHierarchyApprovalChain(
        User|int $employee,
        ?string $requestType = null,
        ?User $requestor = null,
        array $usedApproverIds = [],
        array $context = [],
    ): array {
        if ($this->workflowSettingService->isHrOnlyRequestType($requestType, $context)) {
            return [];
        }

        $subject = $employee instanceof User
            ? $employee->loadMissing(['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'])
            : User::query()
                ->with(['departmentRelation', 'sectionUnit', 'division', 'branch', 'company', 'assignedTeamLeader'])
                ->findOrFail($employee);

        $requestorUser = $requestor ?? $subject;
        $skipIds = array_values(array_unique([
            (int) $subject->id,
            (int) $requestorUser->id,
            ...array_map('intval', $usedApproverIds),
        ]));
        $steps = [];
        $hierarchy = $this->buildLegacyHierarchy($subject, $context);
        $scopeContext = $this->mergeScopeContext($context, $requestorUser, $hierarchy, $requestType);
        $workflowSetting = $this->workflowSettingService->resolveSetting($requestType, $context);
        $stepFlags = $this->workflowSettingService->hierarchyStepFlags($workflowSetting);
        $requesterLevel = $this->detectRequesterLevel($requestorUser, $hierarchy);
        $hasFlexibleOrg = Schema::hasTable('organization_units');
        $primaryAssignment = $this->selectedAssignmentFor($subject, $context);
        $fallbackToParent = (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false);
        $chainMode = (string) ($workflowSetting['approval_chain_mode'] ?? ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS);
        $allowsParentLookup = $fallbackToParent || in_array($chainMode, [
            ApprovalWorkflowSetting::CHAIN_MODE_NEAREST_PLUS_ADMIN,
            ApprovalWorkflowSetting::CHAIN_MODE_FULL_HIERARCHY,
        ], true);

        $this->log($scopeContext, 'hierarchy chain resolution start', [
            'request_type' => $requestType,
            'requester_employee_id' => (int) $requestorUser->id,
            'requester_name' => $requestorUser->display_name,
            'resolved_company_id' => $hierarchy['company'] ?? null,
            'resolved_area_id' => $hierarchy['area'] ?? null,
            'resolved_branch_id' => $hierarchy['branch'] ?? null,
            'resolved_division_id' => $hierarchy['division'] ?? null,
            'resolved_department_id' => $hierarchy['department'] ?? null,
            'resolved_section_unit_id' => $hierarchy['section_unit'] ?? null,
            'workflow_steps_enabled' => $stepFlags,
            'approval_chain_mode' => $chainMode,
            'max_org_approval_steps' => $workflowSetting['max_org_approval_steps'] ?? null,
            'detected_requester_level' => $requesterLevel,
            'fallback_to_parent_approver' => $fallbackToParent,
        ]);

        if ($requesterLevel === 'company_head') {
            $this->log($scopeContext, 'requester is company head — org chain empty before HR');

            return [];
        }

        $this->ensureLegacyUnitsForHierarchy($hierarchy, (int) $subject->id, $scopeContext, $hasFlexibleOrg);
        $startLookupLevel = $this->startingLookupLevel($requesterLevel, $hierarchy);
        $startLookupIndex = $startLookupLevel === null
            ? count(self::HIERARCHY_ORDER)
            : array_search($startLookupLevel, self::HIERARCHY_ORDER, true);

        $directlyAssignedToDepartment = $this->isDirectDepartmentAssignment($subject, $primaryAssignment, $hierarchy);

        if (($stepFlags['include_section_head'] ?? false) && ! $this->requestorLeadsDeepestUnit($requestorUser, $hierarchy)) {
            $sectionStep = $this->resolveFirstSectionHierarchyApprover(
                $subject,
                $requestorUser,
                $hierarchy,
                $requestType,
                $skipIds,
                $scopeContext,
                $primaryAssignment,
                $hasFlexibleOrg,
            );
            $this->appendHierarchyChainStep($steps, $sectionStep, $skipIds, $scopeContext, 'section_unit');
        }

        if ($this->usesDepartmentScopedDivisionHead($requestType)) {
            $sectionLeaderContext = $this->sectionLeaderRequestContext($requestorUser, $hierarchy, $scopeContext);
            if ((bool) ($sectionLeaderContext['is_team_leader'] ?? false)) {
                $departmentResolved = $this->resolveParentDepartmentHeadForSectionLeaderRequest(
                    $subject,
                    $requestorUser,
                    $hierarchy,
                    $requestType,
                    $skipIds,
                    $scopeContext,
                    $sectionLeaderContext,
                );
                $this->appendHierarchyChainStep($steps, $departmentResolved, $skipIds, $scopeContext, 'department');
            }
        }

        if (! $allowsParentLookup) {
            if (
                ($stepFlags['include_department_head'] ?? false)
                && $this->usesDepartmentFirstApproval($requestType)
                && $directlyAssignedToDepartment
            ) {
                $departmentResolved = $this->resolveDepartmentHeadForRequester(
                    $subject,
                    $hierarchy,
                    $skipIds,
                    $scopeContext,
                    $requestType,
                    'direct_department_assignment_chain',
                    $primaryAssignment,
                    true,
                );
                $this->appendHierarchyChainStep($steps, $departmentResolved, $skipIds, $scopeContext, 'department');
            }

            $unitChain = $hasFlexibleOrg ? $this->buildOrganizationUnitChain($subject, $hierarchy, $scopeContext) : collect();
            if ($this->requestorIsDepartmentHead($requestorUser, $requesterLevel, $unitChain, $hierarchy)) {
                $divisionId = $this->resolveDivisionId($hierarchy, $unitChain);
                if ($divisionId > 0 && $this->usesDepartmentScopedDivisionHead($requestType)) {
                    $scoped = $this->assignmentScopeService->resolveScopedDivisionHead(
                        $divisionId,
                        (int) ($hierarchy['department'] ?? 0),
                        $requestType,
                        $skipIds,
                        $scopeContext,
                    );
                    if ($scoped !== null) {
                        $divisionResolved = $this->formatResult(
                            $scoped['employee'],
                            [$scoped['employee']],
                            $scoped['leader_role'],
                            OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                            $scoped['unit'] ?? null,
                            $this->approvalLevelFor($scoped['unit'] ?? null, $scoped['leader_role'], 'division'),
                        );
                        $this->appendHierarchyChainStep($steps, $divisionResolved, $skipIds, $scopeContext, 'division');
                    }
                }
            }

            $this->log($scopeContext, 'hierarchy chain built', [
                'approval_chain_mode' => $chainMode,
                'max_org_approval_steps' => $workflowSetting['max_org_approval_steps'] ?? null,
                'final_chain' => array_map(
                    fn (array $step): array => [
                        'approver_id' => $step['approver_id'] ?? null,
                        'approver_name' => $step['approver_name'] ?? null,
                        'approval_level' => $step['approval_level'] ?? null,
                        'approval_label' => $step['approval_label'] ?? null,
                    ],
                    $steps,
                ),
            ]);

            return $steps;
        }

        $orgLevels = ['department', 'division', 'branch', 'area', 'company'];
        $flagByLevel = [
            'department' => 'include_department_head',
            'division' => 'include_division_head',
            'branch' => 'include_branch_head',
            'area' => 'include_area_head',
            'company' => 'include_company_head',
        ];

        foreach ($orgLevels as $level) {
            $flagKey = $flagByLevel[$level];
            if (! ($stepFlags[$flagKey] ?? false)) {
                $this->log($scopeContext, "{$level}_head_candidate skipped — workflow step disabled", [
                    'skipped_reason' => 'workflow_step_disabled',
                ]);

                continue;
            }

            $levelIndex = array_search($level, self::HIERARCHY_ORDER, true);
            if ($startLookupLevel !== null && $levelIndex !== false && $startLookupIndex !== false && $levelIndex < $startLookupIndex) {
                $this->log($scopeContext, "{$level}_head_candidate skipped — requester is at or above level", [
                    'skipped_reason' => 'requester_is_head_at_or_above_level',
                    'requester_level' => $requesterLevel,
                ]);

                continue;
            }

            $legacyId = (int) ($hierarchy[$level] ?? 0);
            if ($legacyId <= 0) {
                $this->log($scopeContext, "{$level}_head_candidate skipped — missing organization id", [
                    'skipped_reason' => 'missing_'.$level.'_id',
                ]);

                continue;
            }

            if ($this->isHeadOfLegacyUnit($requestorUser, $level, $legacyId)) {
                $this->log($scopeContext, "{$level}_head_candidate skipped — requester leads this unit", [
                    'skipped_reason' => 'requester_is_unit_head',
                    "{$level}_id" => $legacyId,
                ]);

                continue;
            }

            $resolved = null;
            if ($level === 'division' && $this->usesDepartmentScopedDivisionHead($requestType)) {
                $scoped = $this->assignmentScopeService->resolveScopedDivisionHead(
                    $legacyId,
                    (int) ($hierarchy['department'] ?? 0),
                    $requestType,
                    $skipIds,
                    $scopeContext,
                );
                if ($scoped !== null) {
                    $resolved = $this->formatResult(
                        $scoped['employee'],
                        [$scoped['employee']],
                        $scoped['leader_role'],
                        OrganizationUnit::ROUTING_FIRST_ASSIGNED,
                        $scoped['unit'] ?? null,
                        $this->approvalLevelFor($scoped['unit'] ?? null, $scoped['leader_role'], 'division'),
                    );
                }
            } else {
                $resolved = $this->resolveOrgHead($level, $legacyId, $subject, $requestType, $skipIds, $scopeContext);
            }

            $this->logOrgHeadCandidate($scopeContext, $level, $legacyId, $resolved);
            $this->appendHierarchyChainStep($steps, $resolved, $skipIds, $scopeContext, $level);
        }

        $this->log($scopeContext, 'hierarchy chain built', [
            'final_chain' => array_map(
                fn (array $step): array => [
                    'approver_id' => $step['approver_id'] ?? null,
                    'approver_name' => $step['approver_name'] ?? null,
                    'approval_level' => $step['approval_level'] ?? null,
                    'approval_label' => $step['approval_label'] ?? null,
                ],
                $steps,
            ),
        ]);

        return $steps;
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function resolveOrgHead(
        string $organizationType,
        int $organizationId,
        User $subject,
        ?string $requestType,
        array $skipIds,
        array $context = [],
    ): ?array {
        if ($organizationId <= 0 || ! in_array($organizationType, self::HIERARCHY_ORDER, true)) {
            return null;
        }

        return $this->resolveLeadersForLegacyUnit(
            $organizationType,
            $organizationId,
            $subject,
            $requestType,
            $skipIds,
            $context + [
                'organization_type' => $organizationType,
                'organization_id' => $organizationId,
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     */
    private function appendHierarchyChainStep(
        array &$steps,
        ?array $resolved,
        array &$skipIds,
        array $context,
        string $level,
    ): void {
        if ($resolved === null) {
            return;
        }

        $approverId = (int) ($resolved['approver_id'] ?? 0);
        if ($approverId <= 0 || in_array($approverId, $skipIds, true)) {
            $this->log($context, 'hierarchy step skipped — duplicate or self approver', [
                'level' => $level,
                'approver_id' => $approverId > 0 ? $approverId : null,
                'skipped_reason' => in_array($approverId, $skipIds, true) ? 'duplicate_or_self' : 'missing_approver',
            ]);

            return;
        }

        $skipIds[] = $approverId;
        $steps[] = $resolved;

        $this->log($context, 'hierarchy step added', [
            'level' => $level,
            'approver_id' => $approverId,
            'approver_name' => $resolved['approver_name'] ?? null,
            'approval_level' => $resolved['approval_level'] ?? null,
        ]);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function resolveFirstSectionHierarchyApprover(
        User $subject,
        User $requestorUser,
        array $hierarchy,
        ?string $requestType,
        array $skipIds,
        array $context,
        ?EmployeeOrganizationAssignment $primaryAssignment,
        bool $hasFlexibleOrg,
    ): ?array {
        $requestorLeadsDeepestUnit = $hasFlexibleOrg
            ? $this->requestorLeadsDeepestUnit($requestorUser, $hierarchy)
            : $this->requestorLeadsLegacySectionUnit($requestorUser, $hierarchy);
        if ($requestorLeadsDeepestUnit) {
            return null;
        }

        $employeeImmediateLeaderId = $this->employeeImmediateLeaderId($subject, $primaryAssignment);
        if ($employeeImmediateLeaderId !== null) {
            $leader = $this->validLeader($employeeImmediateLeaderId, $skipIds, $context, 'employee_immediate_leader');
            if ($leader) {
                return $this->formatResult(
                    $leader,
                    [$leader],
                    'Immediate Leader',
                    OrganizationUnit::ROUTING_SPECIFIC_PER_EMPLOYEE,
                    $primaryAssignment?->organizationUnit,
                    'immediate_leader',
                );
            }
        }

        $assignedTeamLeader = $this->resolveAssignedTeamLeader($subject, $skipIds, $context);
        if ($assignedTeamLeader !== null) {
            return $assignedTeamLeader;
        }

        $directoryLeader = $this->resolveSectionDirectoryLeadership($subject, $hierarchy, $skipIds, $context);
        if ($directoryLeader !== null) {
            return $directoryLeader;
        }

        if ($hasFlexibleOrg) {
            return $this->resolveSectionUnitHeadForEmployee($subject, $hierarchy, $requestType, $skipIds, $context);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logOrgHeadCandidate(array $context, string $level, int $legacyId, ?array $resolved): void
    {
        $suffix = $level === 'company' ? 'company' : $level;
        $this->log($context, "{$suffix}_head_candidate evaluated", [
            "{$suffix}_id" => $legacyId,
            "{$suffix}_head_candidate" => $resolved['approver_name'] ?? null,
            "{$suffix}_head_added_to_chain" => $resolved !== null,
            "{$suffix}_head_active" => $resolved !== null,
            "{$suffix}_head_can_approve" => $resolved !== null,
            "{$suffix}_head_scope_match" => $resolved !== null,
            "{$suffix}_head_request_type_match" => $resolved !== null,
            'skipped_reason' => $resolved === null ? 'no_matching_head' : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, int|null>  $hierarchy
     * @return array<string, mixed>
     */
    private function mergeScopeContext(array $context, User $requestor, array $hierarchy, ?string $requestType): array
    {
        return array_merge($context, [
            'request_type' => $requestType,
            'requester_employee_id' => (int) $requestor->id,
            'requester_name' => $requestor->display_name,
            'requester_company_id' => $hierarchy['company'] ?? null,
            'requester_area_id' => $hierarchy['area'] ?? null,
            'requester_branch_id' => $hierarchy['branch'] ?? null,
            'requester_department_id' => $hierarchy['department'] ?? null,
            'requester_division_id' => $hierarchy['division'] ?? null,
            'requester_section_unit_id' => $hierarchy['section_unit'] ?? null,
            'requester_hierarchy' => $hierarchy,
        ]);
    }

    private function usesDepartmentScopedDivisionHead(?string $requestType): bool
    {
        $normalized = $this->workflowSettingService->normalizeRequestType($requestType);

        return in_array($normalized, [
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            \App\Models\ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true);
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $levelLog
     * @return array<string, mixed>|null
     */
    private function resolveScopedDivisionHeadForHierarchyUnit(
        OrganizationUnit $unit,
        array $hierarchy,
        ?string $requestType,
        array $skipIds,
        array $context,
        array $levelLog,
    ): ?array {
        $divisionId = $unit->legacy_source_type === 'division'
            ? (int) ($unit->legacy_source_id ?? 0)
            : (int) ($hierarchy['division'] ?? 0);
        $departmentId = (int) ($hierarchy['department'] ?? 0);

        if ($divisionId <= 0) {
            $this->log($context, 'scoped division head lookup skipped — division id missing', $levelLog);

            return null;
        }

        $scoped = $this->assignmentScopeService->resolveScopedDivisionHead(
            $divisionId,
            $departmentId,
            $requestType,
            $skipIds,
            $context,
        );

        if ($scoped === null) {
            return null;
        }

        return $this->formatResult(
            $scoped['employee'],
            [$scoped['employee']],
            $scoped['leader_role'],
            OrganizationUnit::ROUTING_FIRST_ASSIGNED,
            $scoped['unit'] ?? $unit,
            $this->approvalLevelFor($scoped['unit'] ?? $unit, $scoped['leader_role'], 'division'),
        ) + ['selected_approver_source' => 'scoped_division_head'];
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $levelLog
     */
    private function resolveFromUnitLeaders(
        OrganizationUnit $unit,
        User $subject,
        ?string $requestType,
        array $skipIds,
        array $context = [],
        array $levelLog = [],
    ): ?array {
        if (Schema::hasTable('organization_position_assignments')) {
            $resolved = $this->resolveFromPositionAssignments($unit, $subject, $requestType, $skipIds, $context, $levelLog);
            if ($resolved !== null) {
                return $resolved;
            }

            if ($this->unitHasFlexibleApprovalAssignments($unit)) {
                $this->log($context, 'unit leader fallback skipped — flexible position assignments control approval scope', $levelLog + [
                    'skipped_reason' => 'flexible_assignment_scope_not_matched',
                ]);

                return null;
            }
        }

        $leaders = $unit->activeLeaders()->with('employee')->get();
        $candidates = $leaders
            ->map(function (OrganizationUnitLeader $leader) use ($skipIds, $context, $levelLog): ?array {
                $employee = $leader->employee;
                if (! $employee) {
                    return null;
                }

                if (! $this->isValidLeaderUser($employee, $skipIds)) {
                    $this->logRejectedLeader($context, $levelLog, $employee, $skipIds);

                    return null;
                }

                return [
                    'leader' => $leader,
                    'employee' => $employee,
                    'leader_role' => trim((string) $leader->leader_role) !== '' ? trim((string) $leader->leader_role) : 'Leader',
                ];
            })
            ->filter()
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        $this->log($context, 'leadership candidates found from unit leaders', $levelLog + [
            'candidate_ids' => $candidates->pluck('employee')->map(fn (User $user) => (int) $user->id)->all(),
        ]);

        return $this->pickCandidate($candidates, $unit, $subject, $requestType);
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $levelLog
     */
    private function resolveFromPositionAssignments(
        OrganizationUnit $unit,
        User $subject,
        ?string $requestType,
        array $skipIds,
        array $context = [],
        array $levelLog = [],
    ): ?array {
        $unitLevel = $this->unitHierarchyLevel($unit);
        $hierarchy = is_array($context['requester_hierarchy'] ?? null) ? $context['requester_hierarchy'] : [];
        $assignments = $unit->activePositionAssignments()
            ->with(['employee', 'positionType', 'activeDepartmentScopes'])
            ->get()
            ->filter(fn (OrganizationPositionAssignment $assignment): bool => (bool) ($assignment->positionType?->can_approve ?? true))
            ->sortBy([
                ['approval_priority', 'asc'],
                ['is_primary', 'desc'],
                ['id', 'asc'],
            ])
            ->values();

        $this->log($context, 'position assignments scanned', $levelLog + [
            'assignment_count' => $assignments->count(),
            'assignment_rows' => $assignments->map(fn (OrganizationPositionAssignment $row): array => [
                'assignment_id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'position_name' => $row->positionType?->position_name,
                'can_approve' => (bool) ($row->positionType?->can_approve ?? true),
                'is_active' => (bool) $row->is_active,
                'approval_priority' => (int) $row->approval_priority,
                'is_primary' => (bool) $row->is_primary,
            ])->all(),
        ]);

        $candidates = $assignments
            ->map(function (OrganizationPositionAssignment $assignment) use ($skipIds, $context, $levelLog, $unitLevel, $hierarchy, $requestType): ?array {
                $employee = $assignment->employee;
                if (! $employee) {
                    return null;
                }

                if ($this->unitUsesGenericApprovalScope($unitLevel)
                    && ! $this->assignmentScopeService->assignmentMatchesApprovalScope($assignment, $hierarchy, $requestType, $context + $levelLog)) {
                    return null;
                }

                if (! $this->isValidLeaderUser($employee, $skipIds)) {
                    $this->logRejectedLeader($context, $levelLog, $employee, $skipIds, [
                        'assignment_id' => (int) $assignment->id,
                        'position_name' => $assignment->positionType?->position_name,
                    ]);

                    return null;
                }

                $role = trim((string) ($assignment->positionType?->position_name ?? 'Leader')) ?: 'Leader';

                return [
                    'assignment' => $assignment,
                    'employee' => $employee,
                    'leader_role' => $role,
                ];
            })
            ->filter()
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $this->pickCandidate($candidates, $unit, $subject, $requestType);
    }

    private function unitUsesGenericApprovalScope(?string $unitLevel): bool
    {
        return in_array($unitLevel, ['company', 'area', 'branch'], true);
    }

    private function unitHasFlexibleApprovalAssignments(OrganizationUnit $unit): bool
    {
        return $unit->activePositionAssignments()
            ->exists();
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $levelLog
     */
    private function resolveFromLegacyHeadColumnDirect(
        string $legacyType,
        int $legacyId,
        ?OrganizationUnit $unit,
        array $skipIds,
        array $context,
        array $levelLog,
    ): ?array {
        $employeeId = $this->legacyHeadEmployeeIdFor($legacyType, $legacyId);
        if ($employeeId === null) {
            $this->log($context, 'no legacy head column value', $levelLog);

            return null;
        }

        $employee = User::query()->find($employeeId);
        if (! $employee || ! $this->isValidLeaderUser($employee, $skipIds)) {
            if ($employee) {
                $this->logRejectedLeader($context, $levelLog, $employee, $skipIds, ['source' => 'legacy_head_column']);
            }

            return null;
        }

        $role = $this->defaultLeaderRoleForLegacyType($legacyType);
        $resolvedUnit = $unit ?: OrganizationUnit::query()
            ->where('legacy_source_type', $legacyType)
            ->where('legacy_source_id', $legacyId)
            ->first();

        return $this->formatResult(
            $employee,
            [$employee],
            $role,
            OrganizationUnit::ROUTING_FIRST_ASSIGNED,
            $resolvedUnit,
            $this->approvalLevelFor($resolvedUnit, $role, $legacyType),
        );
    }

    /**
     * @param  Collection<int, array{employee: User, leader_role: string}>  $candidates
     */
    private function pickCandidate(
        Collection $candidates,
        ?OrganizationUnit $unit,
        User $subject,
        ?string $requestType,
    ): ?array {
        if ($candidates->isEmpty()) {
            return null;
        }

        $selected = $candidates->first();
        if (! $selected) {
            return null;
        }

        return $this->formatResult(
            $selected['employee'],
            [$selected['employee']],
            $selected['leader_role'],
            OrganizationUnit::ROUTING_FIRST_ASSIGNED,
            $unit,
            $this->approvalLevelFor($unit, $selected['leader_role']),
        );
    }

    /**
     * @param  list<int>  $skipIds
     */
    private function validLeader(int $leaderId, array $skipIds, array $context, string $source): ?User
    {
        $leader = User::query()->find($leaderId);
        if (! $leader || ! $this->isValidLeaderUser($leader, $skipIds)) {
            if ($leader) {
                $this->logRejectedLeader($context, ['source' => $source], $leader, $skipIds);
            }

            return null;
        }

        return $leader;
    }

    /**
     * @param  list<int>  $skipIds
     */
    private function isValidLeaderUser(User $leader, array $skipIds): bool
    {
        return $this->isEligibleApproverUser($leader, $skipIds);
    }

    /**
     * Approval routing eligibility is independent of data-visibility permissions
     * (can_view_subordinate_attendance, can_view_employee_module, etc.).
     *
     * @param  list<int>  $skipIds
     */
    private function isEligibleApproverUser(User $leader, array $skipIds): bool
    {
        return (bool) $leader->is_active
            && $leader->isOperationallyActive()
            && $this->isApproverAccountEligible($leader)
            && ! $leader->isExcludedFromApprovals()
            && ! in_array((int) $leader->id, $skipIds, true);
    }

    private function isApproverAccountEligible(User $leader): bool
    {
        return in_array($leader->role, User::ROSTER_ELIGIBLE_ROLES, true)
            && ! (bool) ($leader->is_super_admin ?? false)
            && ! (bool) ($leader->is_system_user ?? false)
            && ! (bool) ($leader->is_hidden ?? false);
    }

    /**
     * @param  array<int, User>  $eligibleApprovers
     * @return array<string, mixed>
     */
    private function formatResult(
        User $approver,
        array $eligibleApprovers,
        string $leaderRole,
        string $routingRule,
        ?OrganizationUnit $unit,
        string $approvalLevel,
    ): array {
        $eligibleApprovers = collect($eligibleApprovers)
            ->filter(fn (User $user): bool => $this->isValidLeaderUser($user, []))
            ->unique(fn (User $user): int => (int) $user->id)
            ->values()
            ->all();

        return [
            'approver' => $approver,
            'approver_id' => (int) $approver->id,
            'approver_name' => $approver->display_name,
            'eligible_approvers' => $eligibleApprovers,
            'eligible_approver_ids' => array_map(fn (User $user): int => (int) $user->id, $eligibleApprovers),
            'approval_level' => $approvalLevel,
            'approval_label' => $this->approvalLabel($leaderRole),
            'leader_role' => $leaderRole,
            'routing_rule' => $routingRule,
            'source_unit' => $unit,
        ];
    }

    private function approvalLevelFor(?OrganizationUnit $unit, string $leaderRole, ?string $legacyType = null): string
    {
        if ($unit?->type?->code) {
            return Str::snake($unit->type->code.'_'.$leaderRole);
        }

        $prefix = $legacyType ?: 'organization_unit';

        return Str::snake($prefix.'_'.$leaderRole);
    }

    private function approvalLabel(string $leaderRole): string
    {
        $role = trim($leaderRole) !== '' ? trim($leaderRole) : 'Immediate Leader';
        $lower = Str::lower($role);

        if (Str::contains($lower, 'area head') && Str::contains($lower, 'area manager')) {
            $role = 'Area Head';
            $lower = Str::lower($role);
        }

        return Str::contains($lower, ['approval', 'approver']) ? $role : $role.' approval';
    }

    /**
     * @param  array<string, int|null>  $hierarchy
     * @param  array<string, mixed>  $context
     */
    private function ensureLegacyUnitsForHierarchy(array $hierarchy, int $employeeId, array $context, bool $hasFlexibleOrg = true): void
    {
        if (! $hasFlexibleOrg) {
            return;
        }

        foreach (self::HIERARCHY_ORDER as $legacyType) {
            $legacyId = (int) ($hierarchy[$legacyType] ?? 0);
            if ($legacyId <= 0) {
                continue;
            }

            try {
                $this->legacyOrganizationMirrorService->syncLegacyRecord($legacyType, $legacyId);
            } catch (\Throwable $exception) {
                $this->log($context, 'failed to sync legacy organization unit', [
                    'legacy_type' => $legacyType,
                    'legacy_id' => $legacyId,
                    'employee_id' => $employeeId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function isUserLeaderOfUnit(User $user, OrganizationUnit $unit): bool
    {
        $userId = (int) $user->id;

        if (Schema::hasTable('organization_position_assignments')) {
            $hasAssignment = $unit->activePositionAssignments()
                ->where('employee_id', $userId)
                ->whereHas('positionType', fn ($query) => $query->where('can_approve', true))
                ->exists();

            if ($hasAssignment) {
                return true;
            }
        }

        if ($unit->activeLeaders()->where('employee_id', $userId)->exists()) {
            return true;
        }

        return $this->legacyHeadEmployeeIdForUnit($unit) === $userId;
    }

    private function legacyHeadEmployeeIdForUnit(OrganizationUnit $unit): ?int
    {
        return $this->legacyHeadEmployeeIdFor(
            (string) ($unit->legacy_source_type ?? ''),
            (int) ($unit->legacy_source_id ?? 0),
        );
    }

    private function legacyHeadEmployeeIdFor(string $legacyType, int $legacyId): ?int
    {
        if ($legacyId <= 0 || $legacyType === '') {
            return null;
        }

        $headId = match ($legacyType) {
            'company' => Company::query()->whereKey($legacyId)->value('company_head_id'),
            'area' => Area::query()->whereKey($legacyId)->value('area_manager_employee_id'),
            'branch' => Branch::query()->whereKey($legacyId)->value('branch_manager_id'),
            'division' => Division::query()->whereKey($legacyId)->value('division_head_id'),
            'department' => $this->legacyDepartmentHeadId($legacyId),
            'section_unit' => $this->legacySectionUnitHeadId($legacyId),
            default => null,
        };

        if ($headId === null || (int) $headId <= 0) {
            return null;
        }

        return (int) $headId;
    }

    private function legacyDepartmentHeadId(int $departmentId): ?int
    {
        $head = $this->legacyDepartmentHeadFor($departmentId);

        return $head ? (int) $head['employee_id'] : null;
    }

    /**
     * @return array{employee_id: int, source: string}|null
     */
    private function legacyDepartmentHeadFor(int $departmentId): ?array
    {
        $department = Department::query()->find($departmentId);
        if (! $department) {
            return null;
        }

        foreach (['head_employee_id', 'department_head_id'] as $column) {
            if (Schema::hasColumn('departments', $column) && (int) ($department->{$column} ?? 0) > 0) {
                return [
                    'employee_id' => (int) $department->{$column},
                    'source' => 'departments.'.$column,
                ];
            }
        }

        return null;
    }

    private function legacySectionUnitHeadId(int $sectionUnitId): ?int
    {
        $section = SectionUnit::query()->find($sectionUnitId);
        if (! $section) {
            return null;
        }

        foreach (['section_unit_head_id', 'head_employee_id', 'team_leader_id'] as $column) {
            if (Schema::hasColumn('sections_or_units', $column) && (int) ($section->{$column} ?? 0) > 0) {
                return (int) $section->{$column};
            }
        }

        return null;
    }

    private function defaultLeaderRoleForLegacyType(string $legacyType): string
    {
        return match ($legacyType) {
            'company' => 'Company Head',
            'branch' => 'Branch Head',
            'division' => 'Division Head',
            'department' => 'Department Head',
            'section_unit' => 'Section/Unit Head',
            default => 'Leader',
        };
    }

    private function primaryAssignmentFor(User $subject): ?EmployeeOrganizationAssignment
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return null;
        }

        return EmployeeOrganizationAssignment::query()
            ->with(['organizationUnit.type', 'organizationUnit.parent'])
            ->active()
            ->where('employee_id', (int) $subject->id)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first()
            ?: EmployeeOrganizationAssignment::query()
                ->with(['organizationUnit.type', 'organizationUnit.parent'])
                ->active()
                ->where('employee_id', (int) $subject->id)
                ->orderByDesc('is_primary')
                ->orderByDesc('id')
                ->first();
    }

    private function selectedAssignmentFor(User $subject, array $context = []): ?EmployeeOrganizationAssignment
    {
        if (! Schema::hasTable('employee_organization_assignments')) {
            return null;
        }

        $assignmentId = isset($context['assignment_id']) ? (int) $context['assignment_id'] : 0;
        if ($assignmentId > 0) {
            $assignment = EmployeeOrganizationAssignment::query()
                ->with(['organizationUnit.type', 'organizationUnit.parent'])
                ->active()
                ->whereKey($assignmentId)
                ->where('employee_id', (int) $subject->id)
                ->first();

            if ($assignment) {
                return $assignment;
            }

            $this->log($context, 'selected assignment unavailable for approval routing', [
                'request_employee_id' => (int) $subject->id,
                'selected_assignment_id' => $assignmentId,
                'skip_reason' => 'assignment_missing_inactive_or_not_owned_by_employee',
            ]);
        }

        return $this->primaryAssignmentFor($subject);
    }

    /**
     * @param  list<int>  $skipIds
     * @param  array<string, mixed>  $extra
     */
    private function logRejectedLeader(array $context, array $levelLog, User $leader, array $skipIds, array $extra = []): void
    {
        $reason = 'unknown';
        if (in_array((int) $leader->id, $skipIds, true)) {
            $reason = 'self_or_duplicate';
        } elseif (! $leader->is_active) {
            $reason = 'inactive';
        } elseif (! $leader->isOperationallyActive()) {
            $reason = 'not_operationally_active';
        } elseif (! $this->isApproverAccountEligible($leader)) {
            $reason = 'not_approver_account_eligible';
        } elseif ($leader->isExcludedFromApprovals()) {
            $reason = 'excluded_from_approvals';
        }

        $this->log($context, 'rejected leadership candidate', $levelLog + $extra + [
            'candidate_id' => (int) $leader->id,
            'candidate_name' => $leader->display_name,
            'reject_reason' => $reason,
            'visibility_permissions_ignored_for_approval' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    private function log(array $context, string $message, array $payload = []): void
    {
        Log::info('approval_chain: '.$message, array_merge([
            'request_id' => $context['request_id'] ?? null,
            'module_type' => $context['module_type'] ?? null,
        ], $payload));
    }
}
