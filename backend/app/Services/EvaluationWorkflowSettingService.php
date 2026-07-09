<?php

namespace App\Services;

use App\Models\EvaluationWorkflowSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EvaluationWorkflowSettingService
{
    public const HELPER_TEXT = 'Turn on hierarchy approval if evaluations require approval from the employee\'s organizational leaders before HR/Admin finalizes. Turn off to route directly to HR/Admin.';

    public const CHAIN_MODES = [
        EvaluationWorkflowSetting::CHAIN_MODE_NEAREST_PLUS_ADMIN,
        EvaluationWorkflowSetting::CHAIN_MODE_FULL_HIERARCHY,
        EvaluationWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
    ];

    /**
     * @var array<string, array{label: string, default_hierarchy: bool}>
     */
    public const REQUEST_TYPE_CATALOG = [
        EvaluationWorkflowSetting::REQUEST_TYPE_EVALUATION => [
            'label' => 'Performance Evaluation',
            'default_hierarchy' => true,
        ],
    ];

    public function usesHierarchyApproval(): bool
    {
        $setting = $this->resolveSetting();

        return (bool) ($setting['use_hierarchy_approval'] ?? false);
    }

    public function fallbackToParentApprover(): bool
    {
        $setting = $this->resolveSetting();

        return (bool) ($setting['fallback_to_parent_approver'] ?? false);
    }

    /**
     * @return array{
     *   include_section_head: bool,
     *   include_department_head: bool,
     *   include_division_head: bool,
     *   include_branch_head: bool,
     *   include_area_head: bool,
     *   include_company_head: bool,
     *   include_admin_hr: bool
     * }
     */
    public function hierarchyStepFlags(): array
    {
        $setting = $this->resolveSetting();
        $hierarchyEnabled = (bool) ($setting['use_hierarchy_approval'] ?? false);
        $default = $hierarchyEnabled;
        $flag = static fn (string $key): bool => $hierarchyEnabled && (
            ! array_key_exists($key, $setting) || $setting[$key] === null
                ? $default
                : (bool) $setting[$key]
        );

        return [
            'include_section_head' => $flag('include_section_head'),
            'include_department_head' => $flag('include_department_head'),
            'include_division_head' => $flag('include_division_head'),
            'include_branch_head' => $flag('include_branch_head'),
            'include_area_head' => $flag('include_area_head'),
            'include_company_head' => $flag('include_company_head'),
            'include_admin_hr' => array_key_exists('include_admin_hr', $setting) && $setting['include_admin_hr'] !== null
                ? (bool) $setting['include_admin_hr']
                : true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveSetting(): array
    {
        $fallback = $this->defaultSettingPayload();

        if (! Schema::hasTable('evaluation_workflow_settings')) {
            return $fallback;
        }

        $this->ensureDefaults();

        $row = EvaluationWorkflowSetting::query()
            ->where('request_type', EvaluationWorkflowSetting::REQUEST_TYPE_EVALUATION)
            ->where('is_active', true)
            ->first();

        if ($row === null) {
            return $fallback;
        }

        return $this->payloadFromModel($row);
    }

    /**
     * @return array{settings: list<array<string, mixed>>, helper_text: string}
     */
    public function listSettings(): array
    {
        if (! Schema::hasTable('evaluation_workflow_settings')) {
            return [
                'settings' => collect(self::REQUEST_TYPE_CATALOG)
                    ->map(fn (array $meta, string $requestType): array => $this->defaultSettingPayload())
                    ->values()
                    ->all(),
                'helper_text' => self::HELPER_TEXT,
            ];
        }

        $this->ensureDefaults();

        $rows = EvaluationWorkflowSetting::query()
            ->with(['updatedBy:id,name,first_name,middle_name,last_name,suffix'])
            ->get()
            ->values();

        return [
            'settings' => $rows->map(fn (EvaluationWorkflowSetting $row): array => $this->payloadFromModel($row))->values()->all(),
            'helper_text' => self::HELPER_TEXT,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{settings: list<array<string, mixed>>, helper_text: string}
     */
    public function updateSettings(array $rows, User $actor): array
    {
        if (! Schema::hasTable('evaluation_workflow_settings')) {
            throw ValidationException::withMessages([
                'settings' => ['Evaluation workflow settings are not available yet. Run database migrations.'],
            ]);
        }

        $this->ensureDefaults();

        foreach ($rows as $index => $row) {
            $setting = EvaluationWorkflowSetting::query()
                ->where('request_type', EvaluationWorkflowSetting::REQUEST_TYPE_EVALUATION)
                ->firstOrFail();

            $setting->use_hierarchy_approval = (bool) ($row['use_hierarchy_approval'] ?? false);
            if (array_key_exists('fallback_to_parent_approver', $row)) {
                $setting->fallback_to_parent_approver = (bool) $row['fallback_to_parent_approver'];
            }
            if (array_key_exists('approval_chain_mode', $row)) {
                $setting->approval_chain_mode = $this->normalizeApprovalChainMode($row['approval_chain_mode'] ?? null);
            }
            if (array_key_exists('max_org_approval_steps', $row)) {
                $maxSteps = $row['max_org_approval_steps'];
                $setting->max_org_approval_steps = $maxSteps === null || $maxSteps === ''
                    ? null
                    : max(0, min(6, (int) $maxSteps));
            }
            foreach ([
                'include_section_head', 'include_department_head', 'include_division_head',
                'include_branch_head', 'include_area_head', 'include_company_head', 'include_admin_hr',
            ] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $setting->{$flag} = (bool) $row[$flag];
                }
            }
            foreach (['allow_admin_self_approval', 'allow_hr_self_approval', 'allow_super_admin_self_approval'] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $setting->{$flag} = (bool) $row[$flag];
                }
            }
            $setting->is_active = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;
            $setting->updated_by = $actor->id;
            if ($setting->created_by === null) {
                $setting->created_by = $actor->id;
            }
            $setting->save();
        }

        return $this->listSettings();
    }

    public function ensureDefaults(): void
    {
        if (! Schema::hasTable('evaluation_workflow_settings')) {
            return;
        }

        EvaluationWorkflowSetting::query()->firstOrCreate(
            ['request_type' => EvaluationWorkflowSetting::REQUEST_TYPE_EVALUATION],
            [
                'use_hierarchy_approval' => true,
                'final_approver_role' => 'admin_hr',
                'require_final_hr_approval' => true,
                'immediate_approver_mode' => 'nearest_leader',
                'fallback_to_hr' => true,
                'fallback_to_parent_approver' => false,
                'approval_chain_mode' => EvaluationWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
                'max_org_approval_steps' => null,
                'include_section_head' => true,
                'include_department_head' => true,
                'include_division_head' => true,
                'include_branch_head' => true,
                'include_area_head' => true,
                'include_company_head' => true,
                'include_admin_hr' => true,
                'allow_admin_self_approval' => true,
                'allow_hr_self_approval' => true,
                'allow_super_admin_self_approval' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettingPayload(): array
    {
        return [
            'id' => null,
            'request_type' => EvaluationWorkflowSetting::REQUEST_TYPE_EVALUATION,
            'request_type_label' => 'Performance Evaluation',
            'use_hierarchy_approval' => true,
            'immediate_approver_mode' => 'nearest_leader',
            'first_approver_source_label' => 'Nearest leader',
            'final_approver_role' => 'admin_hr',
            'final_approver_label' => 'HR/Admin',
            'require_final_hr_approval' => true,
            'fallback_to_hr' => true,
            'fallback_to_parent_approver' => false,
            'approval_chain_mode' => EvaluationWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
            'max_org_approval_steps' => null,
            'include_section_head' => true,
            'include_department_head' => true,
            'include_division_head' => true,
            'include_branch_head' => true,
            'include_area_head' => true,
            'include_company_head' => true,
            'include_admin_hr' => true,
            'allow_admin_self_approval' => true,
            'allow_hr_self_approval' => true,
            'allow_super_admin_self_approval' => true,
            'is_active' => true,
            'updated_at' => null,
            'updated_by_name' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromModel(EvaluationWorkflowSetting $row): array
    {
        return [
            'id' => (int) $row->id,
            'request_type' => (string) $row->request_type,
            'request_type_label' => 'Performance Evaluation',
            'use_hierarchy_approval' => (bool) $row->use_hierarchy_approval,
            'immediate_approver_mode' => (string) $row->immediate_approver_mode,
            'first_approver_source_label' => 'Nearest leader',
            'final_approver_role' => (string) $row->final_approver_role,
            'final_approver_label' => 'HR/Admin',
            'require_final_hr_approval' => (bool) $row->require_final_hr_approval,
            'fallback_to_hr' => (bool) $row->fallback_to_hr,
            'fallback_to_parent_approver' => (bool) ($row->fallback_to_parent_approver ?? false),
            'approval_chain_mode' => $this->normalizeApprovalChainMode($row->approval_chain_mode ?? null),
            'max_org_approval_steps' => $row->max_org_approval_steps === null ? null : (int) $row->max_org_approval_steps,
            'include_section_head' => (bool) ($row->include_section_head ?? (bool) $row->use_hierarchy_approval),
            'include_department_head' => (bool) ($row->include_department_head ?? (bool) $row->use_hierarchy_approval),
            'include_division_head' => (bool) ($row->include_division_head ?? (bool) $row->use_hierarchy_approval),
            'include_branch_head' => (bool) ($row->include_branch_head ?? (bool) $row->use_hierarchy_approval),
            'include_area_head' => (bool) ($row->include_area_head ?? (bool) $row->use_hierarchy_approval),
            'include_company_head' => (bool) ($row->include_company_head ?? (bool) $row->use_hierarchy_approval),
            'include_admin_hr' => (bool) ($row->include_admin_hr ?? true),
            'allow_admin_self_approval' => (bool) ($row->allow_admin_self_approval ?? true),
            'allow_hr_self_approval' => (bool) ($row->allow_hr_self_approval ?? true),
            'allow_super_admin_self_approval' => (bool) ($row->allow_super_admin_self_approval ?? true),
            'is_active' => (bool) $row->is_active,
            'updated_at' => $row->updated_at?->toIso8601String(),
            'updated_by_name' => $row->updatedBy?->display_name,
        ];
    }

    private function normalizeApprovalChainMode(?string $mode): string
    {
        $normalized = trim((string) ($mode ?? ''));

        return in_array($normalized, self::CHAIN_MODES, true)
            ? $normalized
            : EvaluationWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS;
    }
}
