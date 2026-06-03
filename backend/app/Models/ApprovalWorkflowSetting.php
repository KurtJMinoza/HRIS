<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalWorkflowSetting extends Model
{
    public const REQUEST_TYPE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const REQUEST_TYPE_LEAVE = 'leave';

    public const REQUEST_TYPE_OVERTIME = 'overtime';

    public const REQUEST_TYPE_CHANGE_SCHEDULE = 'change_schedule';

    public const REQUEST_TYPE_REPORTS_REQUEST = 'reports_request';

    public const FINAL_APPROVER_ADMIN_HR = 'admin_hr';

    public const IMMEDIATE_MODE_NEAREST_LEADER = 'nearest_leader';

    public const IMMEDIATE_MODE_EMPLOYEE_SPECIFIC = 'employee_specific_leader';

    public const IMMEDIATE_MODE_SCOPED_LEADER = 'scoped_leader';

    public const IMMEDIATE_MODE_SECTION_UNIT_HEAD = 'section_unit_head';

    public const CHAIN_MODE_NEAREST_PLUS_ADMIN = 'nearest_plus_admin';

    public const CHAIN_MODE_FULL_HIERARCHY = 'full_hierarchy';

    public const CHAIN_MODE_CUSTOM_SELECTED_STEPS = 'custom_selected_steps';

    protected $fillable = [
        'request_type',
        'use_hierarchy_approval',
        'final_approver_role',
        'require_final_hr_approval',
        'immediate_approver_mode',
        'fallback_to_hr',
        'fallback_to_parent_approver',
        'approval_chain_mode',
        'max_org_approval_steps',
        'allow_admin_self_approval',
        'allow_hr_self_approval',
        'allow_super_admin_self_approval',
        'include_section_head',
        'include_department_head',
        'include_division_head',
        'include_branch_head',
        'include_area_head',
        'include_company_head',
        'include_admin_hr',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'use_hierarchy_approval' => 'boolean',
            'require_final_hr_approval' => 'boolean',
            'fallback_to_hr' => 'boolean',
            'fallback_to_parent_approver' => 'boolean',
            'max_org_approval_steps' => 'integer',
            'allow_admin_self_approval' => 'boolean',
            'allow_hr_self_approval' => 'boolean',
            'allow_super_admin_self_approval' => 'boolean',
            'include_section_head' => 'boolean',
            'include_department_head' => 'boolean',
            'include_division_head' => 'boolean',
            'include_branch_head' => 'boolean',
            'include_area_head' => 'boolean',
            'include_company_head' => 'boolean',
            'include_admin_hr' => 'boolean',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
