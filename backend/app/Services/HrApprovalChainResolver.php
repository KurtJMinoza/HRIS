<?php

namespace App\Services;

use App\Enums\HrRole;
use App\Models\Area;
use App\Models\ApprovalWorkflowSetting;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Division;
use App\Models\OrganizationPositionAssignment;
use App\Models\OrganizationUnit;
use App\Models\SectionUnit;
use App\Models\User;
use App\Support\HrApprovalStages;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized two-step approval chain for organization requests.
 *
 * Hierarchy vs HR-only routing is driven by {@see ApprovalWorkflowSettingService}.
 */
class HrApprovalChainResolver
{
    public const REQUEST_TYPE_LEAVE = 'leave';

    public const REQUEST_TYPE_OVERTIME = 'overtime';

    public const REQUEST_TYPE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const REQUEST_TYPE_CHANGE_SCHEDULE = 'change_schedule';

    public const REQUEST_TYPE_REPORTS_REQUEST = 'reports_request';

    public function __construct(
        private readonly HrRoleResolver $roleResolver,
        private readonly FlexibleImmediateApproverResolver $flexibleImmediateApproverResolver,
        private readonly ApprovalWorkflowSettingService $workflowSettingService,
    ) {}

    public static function normalizeRequestType(?string $requestType): ?string
    {
        if ($requestType === null) {
            return null;
        }

        $normalized = strtolower(trim($requestType));

        return $normalized === '' ? null : $normalized;
    }

    public static function usesHierarchyApproval(?string $requestType): bool
    {
        return app(ApprovalWorkflowSettingService::class)->usesHierarchyApproval($requestType);
    }

    public static function isHrOnlyRequestType(?string $requestType): bool
    {
        return app(ApprovalWorkflowSettingService::class)->isHrOnlyRequestType($requestType);
    }

    /**
     * @return array<int, HrRole>|null
     */
    public function getApprovalChain(User $employee, bool $employeeSubmitted = true, ?string $requestType = null): ?array
    {
        $steps = $this->resolveApprovalChain(
            $employee,
            $requestType,
            $employeeSubmitted ? $employee : null,
        );

        return $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
    }

    /**
     * Centralized two-step approval resolver.
     *
     * @return array<int, array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }>
     */
    public function resolveTwoStepApprovalChain(User|int $employee, ?string $requestType = null, ?User $requestor = null): array
    {
        $subject = $employee instanceof User
            ? $employee
            : User::query()->findOrFail($employee);

        return $this->resolveApprovalChain($subject, $requestType, $requestor ?? $subject);
    }

    /**
     * @return array<int, array{
     *   approval_level: string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   sequence_order: int
     * }>
     */
    public function resolveApprovalChain(
        User|int $employee,
        ?string $moduleType = null,
        ?User $requestor = null,
        array $context = [],
    ): array {
        $subject = $employee instanceof User
            ? $employee
            : User::query()->findOrFail($employee);

        $subjectId = (int) $subject->id;
        $requestorId = $requestor ? (int) $requestor->id : $subjectId;
        $usedApproverIds = [];
        $steps = [];
        $logContext = array_merge($context, [
            'module_type' => $moduleType,
            'request_type' => $moduleType,
        ]);

        $workflowSetting = $this->workflowSettingService->resolveSetting($moduleType, $logContext);
        $approvalChainMode = (string) ($workflowSetting['approval_chain_mode'] ?? ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS);
        $maxOrgApprovalSteps = $this->maxOrgApprovalStepsForMode($approvalChainMode, $workflowSetting);

        Log::info('approval_chain: resolving flexible two-step chain', [
            'request_id' => $logContext['request_id'] ?? null,
            'module_type' => $moduleType,
            'request_type' => $workflowSetting['request_type'] ?? $moduleType,
            'employee_id' => $subjectId,
            'requestor_id' => $requestorId,
            'requester_name' => $requestor?->display_name ?? $subject->display_name,
            'employee_immediate_leader_id' => $subject->supervisor_id ? (int) $subject->supervisor_id : null,
            'request_assignment_id' => $logContext['assignment_id'] ?? null,
            'request_assignment_type' => $logContext['assignment_type'] ?? null,
            'request_company_id' => $logContext['company_id'] ?? null,
            'request_department_id' => $logContext['department_id'] ?? null,
            'request_section_unit_id' => $logContext['section_unit_id'] ?? null,
            'employee_assigned_team_leader_id' => $subject->assigned_team_leader_id ? (int) $subject->assigned_team_leader_id : null,
            'employee_section_unit_id' => $subject->section_unit_id ? (int) $subject->section_unit_id : null,
            'employee_department_id' => $subject->department_id ? (int) $subject->department_id : null,
            'employee_company_id' => $subject->company_id ? (int) $subject->company_id : null,
            'uses_hierarchy' => (bool) ($workflowSetting['use_hierarchy_approval'] ?? false),
            'fallback_to_parent_approver' => (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false),
            'approval_chain_mode' => $approvalChainMode,
            'max_org_approval_steps' => $maxOrgApprovalSteps,
            'department_head_fallback_allowed' => (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false),
            'final_approver_role' => $workflowSetting['final_approver_role'] ?? ApprovalWorkflowSetting::FINAL_APPROVER_ADMIN_HR,
        ]);

        if (! ($workflowSetting['use_hierarchy_approval'] ?? false)) {
            Log::info('approval_chain: hierarchy disabled by workflow setting; routing directly to Admin HR', [
                'request_id' => $logContext['request_id'] ?? null,
                'employee_id' => $subjectId,
                'requestor_id' => $requestorId,
                'request_type' => $workflowSetting['request_type'] ?? $moduleType,
                'skip_reason' => 'workflow_setting_hierarchy_off',
            ]);
            $this->appendAdminHrFinalStep($steps, $subject, $requestor, $usedApproverIds);

            return $steps;
        }

        $hierarchySteps = $this->flexibleImmediateApproverResolver->resolveHierarchyApprovalChain(
            $subject,
            $moduleType,
            $requestor,
            $usedApproverIds,
            $logContext,
        );

        if ($hierarchySteps === []) {
            $legacyImmediate = $this->flexibleImmediateApproverResolver->resolveImmediateApprover(
                $subject,
                $moduleType,
                $requestor,
                $usedApproverIds,
                $logContext,
            );
            if ($legacyImmediate !== null) {
                $hierarchySteps = [$legacyImmediate];
                Log::info('approval_chain: using legacy immediate approver fallback', [
                    'request_id' => $logContext['request_id'] ?? null,
                    'employee_id' => $subjectId,
                    'approver_id' => $legacyImmediate['approver_id'] ?? null,
                    'approval_level' => $legacyImmediate['approval_level'] ?? null,
                ]);
            }
        }

        foreach ($hierarchySteps as $flexible) {
            $approverId = (int) ($flexible['approver_id'] ?? 0);
            if ($approverId <= 0 || $approverId === $subjectId || $approverId === $requestorId) {
                continue;
            }
            if (in_array($approverId, $usedApproverIds, true)) {
                continue;
            }
            if ($maxOrgApprovalSteps !== null && count($steps) >= $maxOrgApprovalSteps) {
                Log::info('approval_chain: hierarchy approver skipped by max org approval steps', [
                    'request_id' => $logContext['request_id'] ?? null,
                    'employee_id' => $subjectId,
                    'approval_chain_mode' => $approvalChainMode,
                    'max_org_approval_steps' => $maxOrgApprovalSteps,
                    'level' => $flexible['approval_level'] ?? null,
                    'approver_id' => $approverId,
                    'approver_name' => $flexible['approver_name'] ?? null,
                    'skipped_reason' => 'max_org_approval_steps_reached',
                ]);

                continue;
            }
            $usedApproverIds[] = $approverId;
            $steps[] = $this->formatFlexibleStep($flexible, count($steps) + 1);
            Log::info('approval_chain: hierarchy approver added', [
                'request_id' => $logContext['request_id'] ?? null,
                'employee_id' => $subjectId,
                'level' => $flexible['approval_level'] ?? null,
                'approver_id' => $approverId,
                'approver_name' => $flexible['approver_name'] ?? null,
                'approval_label' => $flexible['approval_label'] ?? null,
            ]);
        }

        if ($hierarchySteps === []) {
            Log::info('approval_chain: no hierarchy approvers resolved', [
                'request_id' => $logContext['request_id'] ?? null,
                'employee_id' => $subjectId,
                'request_type' => $moduleType,
                'skip_reason' => 'no_eligible_hierarchy_approvers',
            ]);
        }

        $stepFlags = $this->workflowSettingService->hierarchyStepFlags($workflowSetting);
        if ($stepFlags['include_admin_hr'] ?? true) {
            $this->appendAdminHrFinalStep($steps, $subject, $requestor, $usedApproverIds);
        }

        Log::info('approval_chain: final order', [
            'request_id' => $logContext['request_id'] ?? null,
            'employee_id' => $subjectId,
            'fallback_to_parent_approver' => (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false),
            'approval_chain_mode' => $approvalChainMode,
            'max_org_approval_steps' => $maxOrgApprovalSteps,
            'department_head_fallback_allowed' => (bool) ($workflowSetting['fallback_to_parent_approver'] ?? false),
            'chain' => array_map(
                fn (array $step): array => [
                    'sequence_order' => $step['sequence_order'],
                    'level' => $step['approval_level'],
                    'approver_id' => $step['approver_id'],
                    'approver_name' => $step['approver_name'],
                    'approval_label' => $step['approval_label'] ?? null,
                ],
                $steps,
            ),
        ]);

        return $steps;
    }

    /**
     * @return array{
     *   chain: array<int, HrRole>|null,
     *   fallback_to_admin: bool,
     *   fallback_reasons: array<int, string>,
     *   first_level_approver: ?User,
     *   hr_approver: ?User
     * }
     */
    public function resolveRoutingDecision(User $employee, bool $employeeSubmitted = true, ?string $requestType = null, array $context = []): array
    {
        $steps = $this->resolveApprovalChain(
            $employee,
            $requestType,
            $employeeSubmitted ? $employee : null,
            $context,
        );
        $chain = $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return [
            'chain' => $chain,
            'fallback_to_admin' => false,
            'fallback_reasons' => $chain === null ? ['no_active_admin_hr'] : [],
            'first_level_approver' => $firstLine['approver'] ?? null,
            'hr_approver' => $this->resolveHrApprover(),
        ];
    }

    public function initialApprovalStage(User $employee, bool $employeeSubmitted = true, ?string $requestType = null, array $context = []): string
    {
        $steps = $this->resolveApprovalChain(
            $employee,
            $requestType,
            $employeeSubmitted ? $employee : null,
            $context,
        );
        $chain = $steps === [] ? null : array_map(fn (array $step): HrRole => $step['approver_role'], $steps);
        if ($chain === null) {
            return HrApprovalStages::REJECTED;
        }

        if (count($chain) === 1 && $chain[0] === HrRole::AdminHr) {
            return HrApprovalStages::PENDING_SECOND;
        }

        return HrApprovalStages::PENDING_FIRST;
    }

    public function isFirstLevelApprover(User $actor, User $subjectEmployee, ?string $requestType = null): bool
    {
        if ($this->workflowSettingService->isHrOnlyRequestType($requestType)) {
            return false;
        }

        $steps = $this->resolveApprovalChain($subjectEmployee, $requestType, $subjectEmployee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine !== null && (int) $firstLine['approver_id'] === (int) $actor->id;
    }

    public function resolveFirstLevelApprover(User $employee, ?string $requestType = null): ?User
    {
        if ($this->workflowSettingService->isHrOnlyRequestType($requestType)) {
            return null;
        }

        $steps = $this->resolveApprovalChain($employee, $requestType, $employee);
        $firstLine = collect($steps)->first(fn (array $step): bool => $step['approver_role'] !== HrRole::AdminHr);

        return $firstLine['approver'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $workflowSetting
     */
    private function maxOrgApprovalStepsForMode(string $approvalChainMode, array $workflowSetting): ?int
    {
        if ($approvalChainMode === ApprovalWorkflowSetting::CHAIN_MODE_NEAREST_PLUS_ADMIN) {
            return 1;
        }

        if ($approvalChainMode === ApprovalWorkflowSetting::CHAIN_MODE_FULL_HIERARCHY) {
            return null;
        }

        if (! array_key_exists('max_org_approval_steps', $workflowSetting)
            || $workflowSetting['max_org_approval_steps'] === null
            || $workflowSetting['max_org_approval_steps'] === '') {
            return null;
        }

        return max(0, min(6, (int) $workflowSetting['max_org_approval_steps']));
    }

    public function resolveHrApprover(): ?User
    {
        return User::query()
            ->approvableEmployees()
            ->where('role', User::ROLE_ADMIN)
            ->active()
            ->orderBy('id')
            ->first();
    }

    private function firstApproverRoleForSubject(HrRole $subjectRole): ?HrRole
    {
        return match ($subjectRole) {
            HrRole::Employee => HrRole::SectionUnitHead,
            HrRole::SectionUnitHead => HrRole::DepartmentHead,
            HrRole::DepartmentHead => HrRole::DivisionHead,
            HrRole::DivisionHead => HrRole::BranchHead,
            HrRole::BranchHead => HrRole::AreaHead,
            HrRole::AreaHead => HrRole::OfficerInCharge,
            HrRole::OfficerInCharge => HrRole::CompanyHead,
            HrRole::CompanyHead, HrRole::AdminHr => null,
        };
    }

    private function resolveApproverForRole(User $employee, HrRole $role): ?User
    {
        return match ($role) {
            HrRole::SectionUnitHead => $this->resolveSectionUnitHeadFor($employee),
            HrRole::DivisionHead => $this->resolveDivisionHeadFor($employee),
            HrRole::DepartmentHead => $this->resolveDepartmentHeadFor($employee),
            HrRole::BranchHead => $this->resolveBranchHeadFor($employee),
            HrRole::AreaHead => null,
            HrRole::OfficerInCharge => $this->resolveOfficerInChargeFor($employee),
            HrRole::CompanyHead => $this->resolveCompanyHeadFor($employee),
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  list<int>  $usedApproverIds
     */
    private function appendAdminHrFinalStep(array &$steps, User $subject, ?User $requestor, array $usedApproverIds): void
    {
        $subjectId = (int) $subject->id;
        $hrApprover = $this->resolveHrApproverForFinalStep($subject, $requestor);
        if (! $hrApprover) {
            Log::error('approval_chain: Admin HR final step not appended — no visible approvable Admin HR account', [
                'employee_id' => $subjectId,
                'configuration_error' => 'no_visible_admin_hr_approver',
            ]);

            return;
        }

        $hrId = (int) $hrApprover->id;
        if (in_array($hrId, $usedApproverIds, true)) {
            Log::info('approval_chain: Admin HR approver already appears in org chain; still appending final HR step', [
                'employee_id' => $subjectId,
                'approver_id' => $hrId,
            ]);
        }

        $steps[] = $this->formatStep(HrRole::AdminHr, $hrApprover, count($steps) + 1);

        Log::info('approval_chain: Admin HR final step appended', [
            'employee_id' => $subjectId,
            'approver_id' => $hrId,
            'approver_name' => $hrApprover->display_name,
            'sequence_order' => count($steps),
        ]);
    }

    private function resolveHrApproverForFinalStep(User $subject, ?User $requestor): ?User
    {
        if ($requestor
            && (int) $requestor->id === (int) $subject->id
            && $subject->isAdmin()) {
            return $subject;
        }

        return $this->resolveHrApprover();
    }

    /**
     * @param  array<string, mixed>  $flexible
     * @return array<string, mixed>
     */
    private function formatFlexibleStep(array $flexible, int $sequenceOrder): array
    {
        $approvalLevel = (string) ($flexible['approval_level'] ?? 'immediate_leader');
        $approver = $flexible['approver'];
        $approverRole = $this->inferHrRoleFromApprovalLevel($approvalLevel);

        return [
            'approval_level' => $approvalLevel,
            'approval_label' => $flexible['approval_label'] ?? null,
            'approver_role' => $approverRole,
            'approver_id' => (int) $flexible['approver_id'],
            'approver_name' => (string) $flexible['approver_name'],
            'approver' => $approver,
            'eligible_approver_ids' => $flexible['eligible_approver_ids'] ?? null,
            'routing_rule' => $flexible['routing_rule'] ?? null,
            'sequence_order' => $sequenceOrder,
        ];
    }

    private function inferHrRoleFromApprovalLevel(string $approvalLevel): HrRole
    {
        $level = strtolower($approvalLevel);

        return match (true) {
            str_contains($level, 'officer_in_charge') || str_contains($level, 'officer in charge') => HrRole::OfficerInCharge,
            str_contains($level, 'company') => HrRole::CompanyHead,
            str_contains($level, 'area') => HrRole::AreaHead,
            str_contains($level, 'branch') => HrRole::BranchHead,
            str_contains($level, 'division') => HrRole::DivisionHead,
            str_contains($level, 'department') => HrRole::DepartmentHead,
            str_contains($level, 'team_leader') => HrRole::SectionUnitHead,
            str_contains($level, 'section') => HrRole::SectionUnitHead,
            str_contains($level, 'immediate') => HrRole::SectionUnitHead,
            default => HrRole::SectionUnitHead,
        };
    }

    /**
     * @return array{
     *   approval_level: string,
     *   approval_label: ?string,
     *   approver_role: HrRole,
     *   approver_id: int,
     *   approver_name: string,
     *   approver: User,
     *   eligible_approver_ids: null,
     *   routing_rule: null,
     *   sequence_order: int
     * }
     */
    private function formatStep(HrRole $role, User $approver, int $sequenceOrder): array
    {
        return [
            'approval_level' => $role->value,
            'approval_label' => $role->badgeLabel().' approval',
            'approver_role' => $role,
            'approver_id' => (int) $approver->id,
            'approver_name' => $approver->display_name,
            'approver' => $approver,
            'eligible_approver_ids' => null,
            'routing_rule' => null,
            'sequence_order' => $sequenceOrder,
        ];
    }

    /**
     * @param  list<int>  $usedApproverIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveLegacyImmediateApprover(
        User $subject,
        int $requestorId,
        array $usedApproverIds,
        array $context,
    ): ?array {
        $subjectRole = $this->roleResolver->resolveForApprovalSubject($subject);
        $targetRole = $this->firstApproverRoleForSubject($subjectRole);
        if ($targetRole === null) {
            return null;
        }

        $scoped = $this->resolveScopedLegacyFallbackApprover($subject, $targetRole, $requestorId, $usedApproverIds, $context);
        if ($scoped !== null) {
            return $this->formatFlexibleStep($scoped, 1);
        }

        if (in_array($targetRole, [HrRole::BranchHead, HrRole::AreaHead, HrRole::OfficerInCharge, HrRole::CompanyHead], true)) {
            Log::info('approval_chain: legacy scoped fallback found no approver for role', array_merge($context, [
                'target_role' => $targetRole->value,
                'subject_role' => $subjectRole->value,
                'skip_reason' => 'no_scope_matched_scoped_org_head',
            ]));

            return null;
        }

        $approver = $this->resolveApproverForRole($subject, $targetRole);
        if (! $approver) {
            Log::info('approval_chain: legacy fallback found no approver for role', array_merge($context, [
                'target_role' => $targetRole->value,
                'subject_role' => $subjectRole->value,
            ]));

            return null;
        }

        $approverId = (int) $approver->id;
        if ($approverId === (int) $subject->id || $approverId === $requestorId || in_array($approverId, $usedApproverIds, true)) {
            Log::info('approval_chain: legacy fallback approver rejected', array_merge($context, [
                'target_role' => $targetRole->value,
                'approver_id' => $approverId,
                'reason' => 'self_or_duplicate',
            ]));

            return null;
        }

        return $this->formatStep($targetRole, $approver, 1);
    }

    /**
     * @param  list<int>  $usedApproverIds
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveScopedLegacyFallbackApprover(
        User $subject,
        HrRole $targetRole,
        int $requestorId,
        array $usedApproverIds,
        array $context,
    ): ?array {
        $organizationType = match ($targetRole) {
            HrRole::BranchHead => 'branch',
            HrRole::AreaHead => 'area',
            HrRole::OfficerInCharge, HrRole::CompanyHead => 'company',
            default => null,
        };

        if ($organizationType === null) {
            return null;
        }

        $organizationId = $this->organizationIdForScopedFallback($subject, $organizationType, $context);
        if ($organizationId <= 0) {
            return null;
        }

        $subjectRole = $this->roleResolver->resolveForApprovalSubject($subject);

        return $this->flexibleImmediateApproverResolver->resolveOrgHead(
            $organizationType,
            $organizationId,
            $subject,
            $context['request_type'] ?? $context['module_type'] ?? null,
            array_values(array_unique([
                (int) $subject->id,
                $requestorId,
                ...array_map('intval', $usedApproverIds),
            ])),
            $context + [
                'organization_type' => $organizationType,
                'organization_id' => $organizationId,
                'requester_hierarchy' => $this->hierarchyForScopedFallback($subject, $context),
                'detected_requester_level' => match ($subjectRole) {
                    HrRole::AreaHead => 'area_head',
                    HrRole::OfficerInCharge => 'officer_in_charge',
                    HrRole::CompanyHead => 'company_head',
                    default => 'employee',
                },
                'company_approver_preference' => $targetRole === HrRole::OfficerInCharge
                    ? 'officer_in_charge'
                    : ($targetRole === HrRole::CompanyHead ? 'company_head_only' : ''),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function organizationIdForScopedFallback(User $subject, string $organizationType, array $context): int
    {
        $contextKey = $organizationType.'_id';
        $contextId = (int) ($context[$contextKey] ?? 0);
        if ($contextId > 0) {
            return $contextId;
        }

        return match ($organizationType) {
            'branch' => (int) ($this->branchFor($subject)?->id ?? 0),
            'area' => (int) ($this->areaFor($subject)?->id ?? 0),
            'company' => (int) ($this->companyFor($subject)?->id ?? 0),
            default => 0,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, int|null>
     */
    private function hierarchyForScopedFallback(User $subject, array $context): array
    {
        return [
            'company' => (int) ($context['company_id'] ?? 0) ?: ($this->companyFor($subject)?->id ? (int) $this->companyFor($subject)->id : null),
            'area' => (int) ($context['area_id'] ?? 0) ?: ($this->areaFor($subject)?->id ? (int) $this->areaFor($subject)->id : null),
            'branch' => (int) ($context['branch_id'] ?? 0) ?: ($this->branchFor($subject)?->id ? (int) $this->branchFor($subject)->id : null),
            'division' => (int) ($context['division_id'] ?? 0) ?: ($this->divisionFor($subject)?->id ? (int) $this->divisionFor($subject)->id : null),
            'department' => (int) ($context['department_id'] ?? 0) ?: ($this->departmentFor($subject)?->id ? (int) $this->departmentFor($subject)->id : null),
            'section_unit' => (int) ($context['section_unit_id'] ?? 0) ?: ($this->sectionUnitFor($subject)?->id ? (int) $this->sectionUnitFor($subject)->id : null),
        ];
    }

    private function resolveSectionUnitHeadFor(User $employee): ?User
    {
        if ($employee->supervisor_id) {
            $supervisor = User::query()->activeRoster()->find($employee->supervisor_id);
            if ($supervisor && $this->isConfiguredTeamLeaderFor($employee, (int) $supervisor->id)) {
                return $supervisor;
            }
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->section_unit_head_id) {
            $legacySectionHead = User::query()->activeRoster()->find($section->section_unit_head_id);
            if ($legacySectionHead) {
                return $legacySectionHead;
            }
        }

        if ($section) {
            $sectionLeader = $this->firstActiveTeamLeader(
                $section->teamLeaders()->orderBy('section_unit_team_leaders.id')->get()
            );
            if ($sectionLeader) {
                return $sectionLeader;
            }
        }

        $department = $this->departmentFor($employee);
        if ($department) {
            $departmentLeader = $this->firstActiveTeamLeader(
                $department->teamLeaders()->orderBy('department_team_leaders.id')->get()
            );
            if ($departmentLeader) {
                return $departmentLeader;
            }
        }

        if ($department?->department_head_id) {
            return User::query()->activeRoster()->find($department->department_head_id);
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>|list<User>  $leaders
     */
    private function firstActiveTeamLeader($leaders): ?User
    {
        foreach ($leaders as $leader) {
            if ($leader instanceof User && $leader->is_active) {
                return $leader;
            }
        }

        return null;
    }

    private function isConfiguredTeamLeaderFor(User $employee, int $candidateId): bool
    {
        $section = $this->sectionUnitFor($employee);
        if ($section) {
            if ((int) $section->section_unit_head_id === $candidateId) {
                return true;
            }
            if ($section->teamLeaders()->where('users.id', $candidateId)->exists()) {
                return true;
            }
        }

        $department = $this->departmentFor($employee);
        if ($department) {
            if ((int) $department->department_head_id === $candidateId) {
                return true;
            }
            if ($department->teamLeaders()->where('users.id', $candidateId)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function resolveDivisionHeadFor(User $employee): ?User
    {
        $division = $this->divisionFor($employee);
        if (! $division?->division_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($division->division_head_id);
    }

    private function resolveDepartmentHeadFor(User $employee): ?User
    {
        $department = $this->departmentFor($employee);
        if (! $department?->department_head_id) {
            return null;
        }

        return User::query()->activeRoster()->find($department->department_head_id);
    }

    private function resolveBranchHeadFor(User $employee): ?User
    {
        $branch = $this->branchFor($employee);
        if (! $branch?->branch_manager_id) {
            return null;
        }

        return User::query()->activeRoster()->find($branch->branch_manager_id);
    }

    private function resolveCompanyHeadFor(User $employee): ?User
    {
        $company = $this->companyFor($employee);
        if (! $company) {
            return null;
        }

        $companyId = (int) $company->id;
        if ($companyId > 0 && Schema::hasTable('organization_units')) {
            $resolved = $this->flexibleImmediateApproverResolver->resolveOrgHead(
                'company',
                $companyId,
                $employee,
                null,
                [(int) $employee->id],
                [
                    'company_approver_preference' => 'company_head_only',
                    'detected_requester_level' => 'officer_in_charge',
                ],
            );
            $approver = $resolved['approver'] ?? null;
            if ($approver instanceof User) {
                return $approver;
            }
        }

        if (! $company->company_head_id) {
            return null;
        }

        $head = User::query()->activeRoster()->find((int) $company->company_head_id);
        if (! $head) {
            return null;
        }

        /** @var OrganizationLeadershipAssignmentService $assignments */
        $assignments = app(OrganizationLeadershipAssignmentService::class);
        if ($assignments->companyIdsWhereOfficerInCharge($head)->contains($companyId)
            && ! $assignments->companyIdsLedBy($head)->contains($companyId)) {
            return null;
        }

        return $head;
    }

    private function resolveOfficerInChargeFor(User $employee): ?User
    {
        $company = $this->companyFor($employee);
        if (! $company) {
            return null;
        }

        if (! Schema::hasTable('organization_units') || ! Schema::hasTable('organization_position_assignments')) {
            return null;
        }

        $unit = OrganizationUnit::query()
            ->where('legacy_source_type', 'company')
            ->where('legacy_source_id', (int) $company->id)
            ->first();

        if (! $unit) {
            return null;
        }

        $assignment = OrganizationPositionAssignment::query()
            ->active()
            ->where('organization_unit_id', (int) $unit->id)
            ->whereHas('positionType', function ($query): void {
                $query->where('can_approve', true)
                    ->where(function ($nameQuery): void {
                        $nameQuery->where('position_name', 'Officer-in-Charge')
                            ->orWhere('position_name', 'Officer in Charge');
                    });
            })
            ->orderBy('approval_priority')
            ->orderByDesc('is_primary')
            ->first();

        if (! $assignment?->employee_id) {
            return null;
        }

        return User::query()->activeRoster()->find((int) $assignment->employee_id);
    }

    private function sectionUnitFor(User $employee): ?SectionUnit
    {
        if ($employee->relationLoaded('sectionUnit') && $employee->sectionUnit) {
            return $employee->sectionUnit;
        }

        return $employee->section_unit_id ? SectionUnit::query()->find($employee->section_unit_id) : null;
    }

    private function divisionFor(User $employee): ?Division
    {
        if ($employee->relationLoaded('division') && $employee->division) {
            return $employee->division;
        }

        if ($employee->division_id) {
            return Division::query()->find($employee->division_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->division_id) {
            return Division::query()->find($department->division_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->division_id) {
            return Division::query()->find($section->division_id);
        }

        return null;
    }

    private function departmentFor(User $employee): ?Department
    {
        if ($employee->relationLoaded('departmentRelation') && $employee->departmentRelation) {
            return $employee->departmentRelation;
        }
        if ($employee->department_id) {
            return Department::query()->find($employee->department_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->department_id) {
            return Department::query()->find($section->department_id);
        }

        $deptName = trim((string) ($employee->department ?? ''));

        return $deptName !== '' ? Department::query()->where('name', $deptName)->first() : null;
    }

    private function branchFor(User $employee): ?Branch
    {
        if ($employee->relationLoaded('branch') && $employee->branch) {
            return $employee->branch;
        }
        if ($employee->branch_id) {
            return Branch::query()->find($employee->branch_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->branch_id) {
            return Branch::query()->find($section->branch_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->branch_id) {
            return Branch::query()->find($department->branch_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->branch_id) {
            return Branch::query()->find($division->branch_id);
        }

        return null;
    }

    private function areaFor(User $employee): ?Area
    {
        $branch = $this->branchFor($employee);
        if ($branch?->area_id) {
            return Area::query()->find($branch->area_id);
        }

        return null;
    }

    private function companyFor(User $employee): ?Company
    {
        if ($employee->relationLoaded('company') && $employee->company) {
            return $employee->company;
        }
        if ($employee->company_id) {
            return Company::query()->find($employee->company_id);
        }

        $section = $this->sectionUnitFor($employee);
        if ($section?->company_id) {
            return Company::query()->find($section->company_id);
        }

        $department = $this->departmentFor($employee);
        if ($department?->company_id) {
            return Company::query()->find($department->company_id);
        }

        $division = $this->divisionFor($employee);
        if ($division?->company_id) {
            return Company::query()->find($division->company_id);
        }

        $branch = $this->branchFor($employee);
        if ($branch?->company_id) {
            return Company::query()->find($branch->company_id);
        }

        return null;
    }
}
