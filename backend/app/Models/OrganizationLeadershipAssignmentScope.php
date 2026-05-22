<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationLeadershipAssignmentScope extends Model
{
    public const SCOPE_DEPARTMENT = 'department';

    public const SCOPE_ALL_DEPARTMENTS = 'all_departments';

    public const REQUESTER_DEPARTMENT_HEAD = 'department_head';

    public const REQUEST_TYPE_ALL = 'all';

    public const REQUEST_TYPE_LEAVE = 'leave';

    public const REQUEST_TYPE_OVERTIME = 'overtime';

    public const REQUEST_TYPE_ATTENDANCE_CORRECTION = 'attendance_correction';

    public const REQUEST_TYPE_SCHEDULE = 'schedule';

    protected $fillable = [
        'leadership_assignment_id',
        'scope_type',
        'scope_id',
        'request_type',
        'requester_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'leadership_assignment_id' => 'integer',
            'scope_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function leadershipAssignment(): BelongsTo
    {
        return $this->belongsTo(OrganizationPositionAssignment::class, 'leadership_assignment_id');
    }
}
