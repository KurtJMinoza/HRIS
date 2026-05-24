<?php

namespace App\Services;

use App\Models\ApprovalWorkflowSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflowSettingService
{
    public const HELPER_TEXT = 'Turn on hierarchy approval if this request type requires approval from the employee\'s immediate leader before HR/Admin. Turn off to route directly to HR/Admin.';

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
     * @return array<string, mixed>
     */
    public function resolveSetting(?string $requestType, array $context = []): array
    {
        $normalized = $this->normalizeRequestType($requestType);
        $fallback = $this->defaultSettingPayload($normalized);

        if (! Schema::hasTable('approval_workflow_settings')) {
            $this->logSettingLookup($normalized, $fallback, $context, 'table_missing');

            return $fallback;
        }

        $this->ensureDefaults();

        if ($normalized === null) {
            $this->logSettingLookup(null, $fallback, $context, 'missing_request_type');

            return $fallback;
        }

        $row = ApprovalWorkflowSetting::query()
            ->where('request_type', $normalized)
            ->where('is_active', true)
            ->first();

        if ($row === null) {
            $this->logSettingLookup($normalized, $fallback, $context, 'setting_not_found');

            return $fallback;
        }

        $payload = $this->payloadFromModel($row);
        $this->logSettingLookup($normalized, $payload, $context, 'setting_found');

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

        return $this->listSettings();
    }

    public function ensureDefaults(): void
    {
        if (! Schema::hasTable('approval_workflow_settings')) {
            return;
        }

        foreach (self::REQUEST_TYPE_CATALOG as $requestType => $meta) {
            ApprovalWorkflowSetting::query()->firstOrCreate(
                ['request_type' => $requestType],
                [
                    'use_hierarchy_approval' => $meta['default_hierarchy'],
                    'final_approver_role' => ApprovalWorkflowSetting::FINAL_APPROVER_ADMIN_HR,
                    'require_final_hr_approval' => true,
                    'immediate_approver_mode' => $this->defaultImmediateModeFor($requestType),
                    'fallback_to_hr' => true,
                    'fallback_to_parent_approver' => false,
                    ...$this->defaultSelfApprovalFlagsForDatabase(),
                    'is_active' => true,
                ],
            );
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

    private function defaultImmediateModeFor(string $requestType): string
    {
        return in_array($requestType, [
            ApprovalWorkflowSetting::REQUEST_TYPE_LEAVE,
            ApprovalWorkflowSetting::REQUEST_TYPE_OVERTIME,
        ], true)
            ? ApprovalWorkflowSetting::IMMEDIATE_MODE_SECTION_UNIT_HEAD
            : ApprovalWorkflowSetting::IMMEDIATE_MODE_NEAREST_LEADER;
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
            'fallback_to_parent_approver' => false,
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
        Log::info('approval_chain: workflow setting lookup', array_merge([
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
