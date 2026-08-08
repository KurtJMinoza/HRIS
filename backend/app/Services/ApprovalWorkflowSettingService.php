<?php

namespace App\Services;

use App\Models\ApprovalWorkflowSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflowSettingService
{
    public const HELPER_TEXT = 'Turn on hierarchy approval if this request type requires approval from the employee\'s immediate leader before HR/Admin. Turn off to route directly to HR/Admin.';

    public const CHAIN_MODES = [
        ApprovalWorkflowSetting::CHAIN_MODE_NEAREST_PLUS_ADMIN,
        ApprovalWorkflowSetting::CHAIN_MODE_FULL_HIERARCHY,
        ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
    ];

    /**
     * @var array<string, array{label: string, default_hierarchy: bool}>
     */
    public const REQUEST_TYPE_CATALOG = [
        ApprovalWorkflowSetting::REQUEST_TYPE_ATTENDANCE_CORRECTION => [
            'label' => 'Attendance Correction',
            'default_hierarchy' => false,
        ],
        ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE => [
            'label' => 'Leave',
            'default_hierarchy' => true,
        ],
        ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME => [
            'label' => 'Overtime',
            'default_hierarchy' => true,
        ],
        ApprovalWorkflowSetting::REQUEST_TYPE_CHANGE_SCHEDULE => [
            'label' => 'Change Schedule',
            'default_hierarchy' => false,
        ],
        ApprovalWorkflowSetting::REQUEST_TYPE_REPORTS_REQUEST => [
            'label' => 'Reports Request',
            'default_hierarchy' => false,
        ],
    ];

    public function normalizeRequestType(?string $requestType): ?string
    {
        $normalized = HrApprovalChainResolver::normalizeRequestType($requestType);

        if ($normalized === OrgApprovalWorkflowService::MODULE_SCHEDULE) {
            return ApprovalWorkflowSetting::REQUEST_TYPE_CHANGE_SCHEDULE;
        }

        if ($normalized !== null && array_key_exists($normalized, self::REQUEST_TYPE_CATALOG)) {
            return $normalized;
        }

        return $normalized;
    }

    public function usesHierarchyApproval(?string $requestType, array $context = []): bool
    {
        $setting = $this->resolveSetting($requestType, $context);

        return (bool) ($setting['use_hierarchy_approval'] ?? false);
    }

    public function isHrOnlyRequestType(?string $requestType, array $context = []): bool
    {
        return ! $this->usesHierarchyApproval($requestType, $context);
    }

    public function fallbackToParentApprover(?string $requestType, array $context = []): bool
    {
        $setting = $this->resolveSetting($requestType, $context);

        return (bool) ($setting['fallback_to_parent_approver'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $setting
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
    public function hierarchyStepFlags(array $setting): array
    {
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
    public function resolveSetting(?string $requestType, array $context = []): array
    {
        $normalized = $this->normalizeRequestType($requestType);
        // ponytail: filing resolves settings 3–6× per request; array store is request-scoped (Octane-safe).
        $cacheKey = 'approval_workflow_settings:payload:'.($normalized ?? '_null');
        $cached = Cache::store('array')->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fallback = $this->defaultSettingPayload($normalized);

        if (! Schema::hasTable('approval_workflow_settings')) {
            $this->logSettingLookup($normalized, $fallback, $context, 'table_missing');
            Cache::store('array')->put($cacheKey, $fallback, 3600);

            return $fallback;
        }

        $this->ensureDefaults();

        if ($normalized === null) {
            $this->logSettingLookup(null, $fallback, $context, 'missing_request_type');
            Cache::store('array')->put($cacheKey, $fallback, 3600);

            return $fallback;
        }

        $row = ApprovalWorkflowSetting::query()
            ->where('request_type', $normalized)
            ->where('is_active', true)
            ->first();

        if ($row === null) {
            $this->logSettingLookup($normalized, $fallback, $context, 'setting_not_found');
            Cache::store('array')->put($cacheKey, $fallback, 3600);

            return $fallback;
        }

        $payload = $this->payloadFromModel($row);
        $this->logSettingLookup($normalized, $payload, $context, 'setting_found');
        Cache::store('array')->put($cacheKey, $payload, 3600);

        return $payload;
    }

    /**
     * @return array{settings: list<array<string, mixed>>, helper_text: string}
     */
    public function listSettings(): array
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return [
                'settings' => collect(self::REQUEST_TYPE_CATALOG)
                    ->map(fn (array $meta, string $requestType): array => $this->defaultSettingPayload($requestType))
                    ->values()
                    ->all(),
                'helper_text' => self::HELPER_TEXT,
            ];
        }

        $this->ensureDefaults();

        $rows = ApprovalWorkflowSetting::query()
            ->with(['updatedBy:id,name,first_name,middle_name,last_name,suffix'])
            ->get()
            ->sortBy(fn (ApprovalWorkflowSetting $row): int => (int) array_search(
                $row->request_type,
                array_keys(self::REQUEST_TYPE_CATALOG),
                true,
            ))
            ->values();

        return [
            'settings' => $rows->map(fn (ApprovalWorkflowSetting $row): array => $this->payloadFromModel($row))->values()->all(),
            'helper_text' => self::HELPER_TEXT,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{settings: list<array<string, mixed>>, helper_text: string}
     */
    public function updateSettings(array $rows, User $actor): array
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            throw ValidationException::withMessages([
                'settings' => ['Approval workflow settings are not available yet. Run database migrations.'],
            ]);
        }

        $this->ensureDefaults();

        foreach ($rows as $index => $row) {
            $requestType = $this->normalizeRequestType($row['request_type'] ?? null);
            if ($requestType === null || ! array_key_exists($requestType, self::REQUEST_TYPE_CATALOG)) {
                throw ValidationException::withMessages([
                    "settings.{$index}.request_type" => ['Invalid request type.'],
                ]);
            }

            $setting = ApprovalWorkflowSetting::query()->where('request_type', $requestType)->firstOrFail();
            $setting->use_hierarchy_approval = (bool) ($row['use_hierarchy_approval'] ?? false);
            if (array_key_exists('fallback_to_parent_approver', $row)) {
                $setting->fallback_to_parent_approver = (bool) $row['fallback_to_parent_approver'];
            }
            if (array_key_exists('approval_chain_mode', $row) && Schema::hasColumn('approval_workflow_settings', 'approval_chain_mode')) {
                $setting->approval_chain_mode = $this->normalizeApprovalChainMode($row['approval_chain_mode'] ?? null);
            }
            if (array_key_exists('max_org_approval_steps', $row) && Schema::hasColumn('approval_workflow_settings', 'max_org_approval_steps')) {
                $maxSteps = $row['max_org_approval_steps'];
                $setting->max_org_approval_steps = $maxSteps === null || $maxSteps === ''
                    ? null
                    : max(0, min(6, (int) $maxSteps));
            }
            foreach ([
                'include_section_head',
                'include_department_head',
                'include_division_head',
                'include_branch_head',
                'include_area_head',
                'include_company_head',
                'include_admin_hr',
            ] as $flag) {
                if (array_key_exists($flag, $row) && Schema::hasColumn('approval_workflow_settings', $flag)) {
                    $setting->{$flag} = (bool) $row[$flag];
                }
            }
            foreach (['allow_admin_self_approval', 'allow_hr_self_approval', 'allow_super_admin_self_approval'] as $flag) {
                if (array_key_exists($flag, $row) && Schema::hasColumn('approval_workflow_settings', $flag)) {
                    $setting->{$flag} = (bool) $row[$flag];
                }
            }
            if (array_key_exists('immediate_approver_mode', $row)) {
                $mode = trim((string) $row['immediate_approver_mode']);
                if ($mode !== '') {
                    $setting->immediate_approver_mode = $mode;
                }
            }
            $setting->is_active = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;
            $setting->updated_by = $actor->id;
            if ($setting->created_by === null) {
                $setting->created_by = $actor->id;
            }
            $setting->save();
        }

        app(ApprovalChainCacheService::class)->forgetWorkflowSettings();

        return $this->listSettings();
    }

    public function ensureDefaults(): void
    {
        // ponytail: firstOrCreate×catalog on every setting lookup was a filing hotspot; once per request is enough.
        if (Cache::store('array')->get('approval_workflow_settings:defaults_ensured')) {
            return;
        }

        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        $existingTypes = ApprovalWorkflowSetting::query()->pluck('request_type')->all();
        $allPresent = count(array_diff(array_keys(self::REQUEST_TYPE_CATALOG), $existingTypes)) === 0;

        if ($allPresent) {
            Cache::store('array')->put('approval_workflow_settings:defaults_ensured', true, 3600);

            return;
        }

        foreach (self::REQUEST_TYPE_CATALOG as $requestType => $meta) {
            if (in_array($requestType, $existingTypes, true)) {
                continue;
            }

            $setting = ApprovalWorkflowSetting::query()->firstOrCreate(
                ['request_type' => $requestType],
                [
                    'use_hierarchy_approval' => $meta['default_hierarchy'],
                    'final_approver_role' => ApprovalWorkflowSetting::FINAL_APPROVER_ADMIN_HR,
                    'require_final_hr_approval' => true,
                    'immediate_approver_mode' => $this->defaultImmediateModeFor($requestType),
                    'fallback_to_hr' => true,
                    'fallback_to_parent_approver' => in_array($requestType, [
                        ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
                        ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
                    ], true),
                    ...$this->defaultChainModeFieldsForDatabase(),
                    'include_section_head' => $meta['default_hierarchy'],
                    'include_department_head' => $meta['default_hierarchy'],
                    'include_division_head' => $meta['default_hierarchy'],
                    'include_branch_head' => $meta['default_hierarchy'],
                    'include_area_head' => $meta['default_hierarchy'],
                    'include_company_head' => $meta['default_hierarchy'],
                    'include_admin_hr' => true,
                    ...$this->defaultSelfApprovalFlagsForDatabase(),
                    'is_active' => true,
                ],
            );

            $this->normalizeHierarchyStepColumnsOnModel($setting);
        }

        Cache::store('array')->put('approval_workflow_settings:defaults_ensured', true, 3600);
    }

    private function normalizeHierarchyStepColumnsOnModel(ApprovalWorkflowSetting $setting): void
    {
        if (! Schema::hasColumn('approval_workflow_settings', 'include_section_head')) {
            return;
        }

        $dirty = false;
        foreach ([
            'include_section_head',
            'include_department_head',
            'include_division_head',
            'include_branch_head',
            'include_area_head',
            'include_company_head',
        ] as $column) {
            if ($setting->{$column} === null) {
                $setting->{$column} = (bool) $setting->use_hierarchy_approval;
                $dirty = true;
            }
        }

        if ($setting->include_admin_hr === null) {
            $setting->include_admin_hr = true;
            $dirty = true;
        }

        if (Schema::hasColumn('approval_workflow_settings', 'approval_chain_mode')
            && ! in_array((string) $setting->approval_chain_mode, self::CHAIN_MODES, true)) {
            $setting->approval_chain_mode = ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS;
            $dirty = true;
        }

        if ($dirty) {
            $setting->save();
        }
    }

    /**
     * @return array<string, bool>
     */
    private function defaultSelfApprovalFlagsForDatabase(): array
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return [];
        }

        return collect([
            'allow_admin_self_approval' => true,
            'allow_hr_self_approval' => true,
            'allow_super_admin_self_approval' => true,
        ])->filter(
            fn (bool $enabled, string $column): bool => Schema::hasColumn('approval_workflow_settings', $column)
        )->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultChainModeFieldsForDatabase(): array
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return [];
        }

        return collect([
            'approval_chain_mode' => ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
            'max_org_approval_steps' => null,
        ])->filter(
            fn (mixed $value, string $column): bool => Schema::hasColumn('approval_workflow_settings', $column)
        )->all();
    }

    private function defaultImmediateModeFor(string $requestType): string
    {
        return in_array($requestType, [
            ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true)
            ? ApprovalWorkflowSetting::IMMEDIATE_MODE_SECTION_UNIT_HEAD
            : ApprovalWorkflowSetting::IMMEDIATE_MODE_NEAREST_LEADER;
    }

    private function normalizeApprovalChainMode(?string $mode): string
    {
        $normalized = trim((string) ($mode ?? ''));

        return in_array($normalized, self::CHAIN_MODES, true)
            ? $normalized
            : ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSettingPayload(?string $requestType): array
    {
        $normalized = $this->normalizeRequestType($requestType) ?? ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE;
        $meta = self::REQUEST_TYPE_CATALOG[$normalized] ?? [
            'label' => ucwords(str_replace('_', ' ', $normalized)),
            'default_hierarchy' => false,
        ];

        return [
            'id' => null,
            'request_type' => $normalized,
            'request_type_label' => $meta['label'],
            'use_hierarchy_approval' => $meta['default_hierarchy'],
            'immediate_approver_mode' => $this->defaultImmediateModeFor($normalized),
            'immediate_approver_scope_label' => $this->immediateModeLabel($this->defaultImmediateModeFor($normalized)),
            'first_approver_source_label' => $this->firstApproverSourceLabel($this->defaultImmediateModeFor($normalized)),
            'final_approver_role' => ApprovalWorkflowSetting::FINAL_APPROVER_ADMIN_HR,
            'final_approver_label' => 'HR/Admin',
            'require_final_hr_approval' => true,
            'fallback_to_hr' => true,
            'fallback_to_parent_approver' => in_array($normalized, [
                ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
                ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
            ], true),
            'approval_chain_mode' => ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
            'max_org_approval_steps' => null,
            'include_section_head' => $meta['default_hierarchy'],
            'include_department_head' => $meta['default_hierarchy'],
            'include_division_head' => $meta['default_hierarchy'],
            'include_branch_head' => $meta['default_hierarchy'],
            'include_area_head' => $meta['default_hierarchy'],
            'include_company_head' => $meta['default_hierarchy'],
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
    private function payloadFromModel(ApprovalWorkflowSetting $row): array
    {
        $meta = self::REQUEST_TYPE_CATALOG[$row->request_type] ?? [
            'label' => ucwords(str_replace('_', ' ', (string) $row->request_type)),
        ];

        return [
            'id' => (int) $row->id,
            'request_type' => (string) $row->request_type,
            'request_type_label' => $meta['label'],
            'use_hierarchy_approval' => (bool) $row->use_hierarchy_approval,
            'immediate_approver_mode' => (string) $row->immediate_approver_mode,
            'immediate_approver_scope_label' => $this->firstApproverSourceLabelFor($row->request_type, (string) $row->immediate_approver_mode),
            'first_approver_source_label' => $this->firstApproverSourceLabelFor($row->request_type, (string) $row->immediate_approver_mode),
            'final_approver_role' => (string) $row->final_approver_role,
            'final_approver_label' => 'HR/Admin',
            'require_final_hr_approval' => (bool) $row->require_final_hr_approval,
            'fallback_to_hr' => (bool) $row->fallback_to_hr,
            'fallback_to_parent_approver' => (bool) ($row->fallback_to_parent_approver ?? false),
            'approval_chain_mode' => Schema::hasColumn('approval_workflow_settings', 'approval_chain_mode')
                ? $this->normalizeApprovalChainMode($row->approval_chain_mode ?? null)
                : ApprovalWorkflowSetting::CHAIN_MODE_CUSTOM_SELECTED_STEPS,
            'max_org_approval_steps' => Schema::hasColumn('approval_workflow_settings', 'max_org_approval_steps')
                ? ($row->max_org_approval_steps === null ? null : (int) $row->max_org_approval_steps)
                : null,
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

    private function immediateModeLabel(string $mode): string
    {
        return match ($mode) {
            ApprovalWorkflowSetting::IMMEDIATE_MODE_EMPLOYEE_SPECIFIC => 'Employee-specific leader',
            ApprovalWorkflowSetting::IMMEDIATE_MODE_SCOPED_LEADER => 'Scoped leader',
            ApprovalWorkflowSetting::IMMEDIATE_MODE_SECTION_UNIT_HEAD => 'Section/Unit Head',
            default => 'Nearest leader',
        };
    }

    private function firstApproverSourceLabel(string $mode): string
    {
        return $this->immediateModeLabel($mode);
    }

    private function firstApproverSourceLabelFor(string $requestType, string $mode): string
    {
        if (in_array($requestType, [
            ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true)) {
            return 'Team Lead / Section-Unit Head';
        }

        return $this->firstApproverSourceLabel($mode);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $setting
     */
    private function logSettingLookup(?string $requestType, array $setting, array $context, string $source): void
    {
        Log::debug('approval_chain: workflow setting lookup', array_merge([
            'request_type' => $requestType,
            'workflow_setting_found' => $source === 'setting_found',
            'workflow_setting_source' => $source,
            'use_hierarchy_approval' => (bool) ($setting['use_hierarchy_approval'] ?? false),
            'fallback_to_parent_approver' => (bool) ($setting['fallback_to_parent_approver'] ?? false),
            'final_approver_role' => $setting['final_approver_role'] ?? ApprovalWorkflowSetting::FINAL_APPROVER_ADMIN_HR,
        ], array_filter([
            'request_id' => $context['request_id'] ?? null,
            'module_type' => $context['module_type'] ?? null,
        ], fn ($value) => $value !== null)));
    }
}
