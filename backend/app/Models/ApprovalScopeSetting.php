<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ApprovalScopeSetting extends Model
{
    public const ORG_DIVISION = 'division';

    public const ORG_BRANCH = 'branch';

    public const ORG_COMPANY = 'company';

    public const ORG_DEPARTMENT = 'department';

    public const ORG_SECTION_UNIT = 'section_unit';

    public const REQUESTER_DEPARTMENT_HEAD = 'department_head';

    public const REQUESTER_DIVISION_HEAD = 'division_head';

    public const REQUESTER_BRANCH_HEAD = 'branch_head';

    public const REQUESTER_SECTION_UNIT_HEAD = 'section_unit_head';

    public const REQUESTER_COMPANY_HEAD = 'company_head';

    public const REQUESTER_EMPLOYEE = 'employee';

    public const APPROVER_DIVISION_HEAD = 'division_head';

    public const APPROVER_BRANCH_HEAD = 'branch_head';

    public const APPROVER_COMPANY_HEAD = 'company_head';

    public const APPROVER_DEPARTMENT_HEAD = 'department_head';

    public const APPROVER_SECTION_UNIT_HEAD = 'section_unit_head';

    public const REQUEST_TYPE_ALL = 'all';

    public const REQUEST_TYPE_LEAVE = 'leave';

    public const REQUEST_TYPE_OVERTIME = 'overtime';

    public const REQUEST_TYPE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const REQUEST_TYPE_SCHEDULE = 'schedule';

    protected $fillable = [
        'organization_type',
        'organization_id',
        'requester_level',
        'approver_level',
        'request_type',
        'is_required',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
