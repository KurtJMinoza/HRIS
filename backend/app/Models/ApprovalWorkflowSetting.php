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

    protected $fillable = [
        'request_type',
        'use_hierarchy_approval',
        'final_approver_role',
        'require_final_hr_approval',
        'immediate_approver_mode',
        'fallback_to_hr',
        'fallback_to_parent_approver',
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
