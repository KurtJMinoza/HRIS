<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const KIND_TEMPLATE = 'template';

    public const KIND_CUSTOM = 'custom';

    protected $fillable = [
        'user_id',
        'request_kind',
        'working_schedule_id',
        'custom_schedule_payload',
        'effective_from',
        'remarks',
        'status',
        'approval_stage',
        'pending_approval',
        'filed_at',
        'filed_by',
        'first_approver_id',
        'first_approved_at',
        'second_approver_id',
        'second_approved_at',
        'rejected_at',
        'rejected_by',
        'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'pending_approval' => 'boolean',
            'effective_from' => 'date',
            'custom_schedule_payload' => 'array',
            'filed_at' => 'datetime',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workingSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class, 'working_schedule_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function approvalAudits(): HasMany
    {
        return $this->hasMany(ScheduleRequestApprovalAudit::class)->orderBy('created_at');
    }
}
